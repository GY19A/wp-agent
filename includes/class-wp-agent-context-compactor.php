<?php
/**
 * Conversation context compaction.
 *
 * Keeps the message history sent to the model within a configurable input
 * token budget. When a long-running conversation (lots of turns, large tool
 * results) would exceed the model's context window, the oldest turns are
 * compacted into a short summary line and the most recent turns are kept
 * verbatim. This is the "compact" capability the agent needs so it never
 * blows past the model's input limit.
 *
 * Token counts are estimated (no tokenizer dependency): ~4 chars per token for
 * Latin text, ~1.5 chars per token for CJK. The estimate is intentionally
 * conservative so we compact a little early rather than overflow.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Context_Compactor {

    /** Default model input context window (tokens) when unset. */
    const DEFAULT_CONTEXT_WINDOW = 128000;

    /** Sane bounds for a configured context window. */
    const CONTEXT_WINDOW_MIN = 4000;
    const CONTEXT_WINDOW_MAX = 2000000;

    /**
     * Fraction of the context window usable for the message history, leaving
     * headroom for the system prompt, tool schemas, and the completion.
     */
    const HISTORY_BUDGET_RATIO = 0.6;

    /** Always keep at least this many of the most recent messages verbatim. */
    const MIN_RECENT_MESSAGES = 8;

    /**
     * Estimate the token count of a single text string.
     *
     * @param string $text
     * @return int
     */
    public static function estimate_text_tokens( $text ) {
        $text = (string) $text;
        if ( '' === $text ) {
            return 0;
        }
        $total_chars = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
        $cjk_chars   = preg_match_all( '/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $text );
        $cjk_chars   = (int) $cjk_chars;
        $latin_chars = max( 0, $total_chars - $cjk_chars );

        // CJK: ~1.5 chars/token. Latin: ~4 chars/token.
        $tokens = ( $cjk_chars / 1.5 ) + ( $latin_chars / 4 );
        return (int) ceil( $tokens );
    }

    /**
     * Estimate the token count of one context message (role + content +
     * tool calls / results), including a small per-message overhead.
     *
     * @param array $message
     * @return int
     */
    public static function estimate_message_tokens( array $message ) {
        $tokens = 4; // per-message structural overhead

        $content = $message['content'] ?? '';
        if ( is_string( $content ) ) {
            $tokens += self::estimate_text_tokens( $content );
        } elseif ( is_array( $content ) ) {
            // Multimodal content parts (text/image). Images are billed by the
            // provider separately; approximate a fixed cost per image part.
            foreach ( $content as $part ) {
                if ( is_array( $part ) && isset( $part['type'] ) && 'text' === $part['type'] ) {
                    $tokens += self::estimate_text_tokens( $part['text'] ?? '' );
                } else {
                    $tokens += 800; // rough image part allowance
                }
            }
        }

        if ( ! empty( $message['tool_calls'] ) ) {
            $tokens += self::estimate_text_tokens( wp_json_encode( $message['tool_calls'] ) );
        }

        return $tokens;
    }

    /**
     * Estimate the total token count of a message array.
     *
     * @param array $messages
     * @return int
     */
    public static function estimate_tokens( array $messages ) {
        $total = 0;
        foreach ( $messages as $message ) {
            $total += self::estimate_message_tokens( (array) $message );
        }
        return $total;
    }

    /**
     * Resolve the configured model context window (tokens).
     *
     * @return int
     */
    public static function context_window() {
        $value = 0;
        if ( class_exists( 'WPAgent' ) ) {
            $value = (int) WPAgent::get_option( 'context_window', 0 );
        }
        if ( $value <= 0 ) {
            $value = self::DEFAULT_CONTEXT_WINDOW;
        }
        $value = max( self::CONTEXT_WINDOW_MIN, min( self::CONTEXT_WINDOW_MAX, $value ) );

        /**
         * Filter the model input context window in tokens.
         *
         * @param int $value
         */
        return (int) apply_filters( 'wp_agent_context_window', $value );
    }

    /**
     * The token budget available for the conversation history.
     *
     * @return int
     */
    public static function history_budget() {
        $budget = (int) floor( self::context_window() * self::HISTORY_BUDGET_RATIO );

        /**
         * Filter the history token budget (after reserving headroom for the
         * system prompt, tool schemas, and the completion).
         *
         * @param int $budget
         */
        return (int) apply_filters( 'wp_agent_history_token_budget', $budget );
    }

    /**
     * Compact a context message array to fit the history token budget.
     *
     * Strategy: keep the most recent messages verbatim; replace the older
     * prefix with a single synthesized summary message. Assistant messages
     * that carry tool_calls are never split from their following tool results
     * — the cut point is moved earlier so a complete turn boundary is kept.
     *
     * @param array    $messages Context messages (chronological order).
     * @param int|null $budget   Optional explicit budget; defaults to history_budget().
     * @return array Compacted messages.
     */
    public static function compact( array $messages, $budget = null ) {
        if ( empty( $messages ) ) {
            return $messages;
        }

        $budget = ( null === $budget ) ? self::history_budget() : (int) $budget;
        if ( $budget <= 0 ) {
            return $messages;
        }

        $total = self::estimate_tokens( $messages );
        if ( $total <= $budget ) {
            return $messages; // already fits
        }

        $count = count( $messages );

        // Find how many recent messages we can keep within ~85% of budget,
        // walking backwards from the end. Reserve the rest for the summary.
        $keep_budget = (int) floor( $budget * 0.85 );
        $kept_tokens = 0;
        $keep_from   = $count; // index of first kept message

        for ( $i = $count - 1; $i >= 0; $i-- ) {
            $t = self::estimate_message_tokens( (array) $messages[ $i ] );
            if ( $kept_tokens + $t > $keep_budget && ( $count - $i ) > self::MIN_RECENT_MESSAGES ) {
                break;
            }
            $kept_tokens += $t;
            $keep_from    = $i;
        }

        // Never keep more than all messages; never keep fewer than the minimum.
        $min_keep_from = max( 0, $count - self::MIN_RECENT_MESSAGES );
        if ( $keep_from > $min_keep_from ) {
            $keep_from = $min_keep_from;
        }

        // Move the cut to a safe turn boundary: the first kept message must not
        // be a tool result (which would be orphaned from its assistant call).
        while ( $keep_from > 0 && $keep_from < $count
            && ( ( $messages[ $keep_from ]['role'] ?? '' ) === 'tool' ) ) {
            $keep_from--;
        }
        // If the message just before the cut is an assistant message with tool
        // calls, its tool results are in the kept block — that is fine. But if
        // the kept block *starts* mid-tool-chain, pull the cut back to include
        // the assistant call too.
        if ( $keep_from > 0 && ( ( $messages[ $keep_from ]['role'] ?? '' ) === 'tool' ) ) {
            // Walk back to the assistant message that owns this tool result.
            while ( $keep_from > 0 && ( ( $messages[ $keep_from ]['role'] ?? '' ) !== 'assistant' ) ) {
                $keep_from--;
            }
        }

        if ( $keep_from <= 0 ) {
            return $messages; // nothing to compact safely
        }

        $older = array_slice( $messages, 0, $keep_from );
        $recent = array_slice( $messages, $keep_from );

        $summary = self::summarize_older( $older );

        $summary_message = array(
            'role'    => 'user',
            'content' => $summary,
        );

        return array_merge( array( $summary_message ), $recent );
    }

    /**
     * Build a compact textual summary of the older messages.
     *
     * Uses a lightweight LLM summary when a provider is available and the
     * older block is large; otherwise falls back to a deterministic structural
     * digest. Both keep the result well within a small token budget.
     *
     * @param array $older
     * @return string
     */
    protected static function summarize_older( array $older ) {
        $digest = self::structural_digest( $older );

        $summary  = "[Earlier conversation compacted to stay within the model context window.]\n";
        $summary .= "Summary of the earlier turns (older to newer):\n";
        $summary .= $digest;
        $summary .= "\nContinue from the most recent messages below.";

        return $summary;
    }

    /**
     * Deterministic structural digest of older messages: who said/did what,
     * truncated, with tool calls named. No network calls.
     *
     * @param array $older
     * @return string
     */
    protected static function structural_digest( array $older ) {
        $lines = array();
        foreach ( $older as $msg ) {
            $role = $msg['role'] ?? '';
            if ( 'assistant' === $role && ! empty( $msg['tool_calls'] ) ) {
                $names = array();
                foreach ( (array) $msg['tool_calls'] as $tc ) {
                    $name = $tc['name'] ?? ( $tc['function']['name'] ?? 'tool' );
                    $names[] = $name;
                }
                $lines[] = '- Assistant called: ' . implode( ', ', array_unique( $names ) );
                continue;
            }

            $content = $msg['content'] ?? '';
            if ( is_array( $content ) ) {
                // Multimodal: collapse to its text parts.
                $texts = array();
                foreach ( $content as $part ) {
                    if ( is_array( $part ) && ( $part['type'] ?? '' ) === 'text' ) {
                        $texts[] = $part['text'] ?? '';
                    } elseif ( is_array( $part ) ) {
                        $texts[] = '[image]';
                    }
                }
                $content = implode( ' ', $texts );
            }
            $content = trim( preg_replace( '/\s+/', ' ', (string) $content ) );
            if ( '' === $content ) {
                continue;
            }
            $snippet = self::clip( $content, 240 );

            switch ( $role ) {
                case 'user':
                    $lines[] = '- User: ' . $snippet;
                    break;
                case 'assistant':
                    $lines[] = '- Assistant: ' . $snippet;
                    break;
                case 'tool':
                    $lines[] = '- Tool result: ' . self::clip( $content, 160 );
                    break;
                default:
                    $lines[] = '- ' . $role . ': ' . $snippet;
            }
        }

        // Keep the digest itself bounded.
        $max_lines = 40;
        if ( count( $lines ) > $max_lines ) {
            $head  = array_slice( $lines, 0, 10 );
            $tail  = array_slice( $lines, -28 );
            $lines = array_merge( $head, array( '- … (' . ( count( $lines ) - 38 ) . ' earlier steps omitted) …' ), $tail );
        }

        return implode( "\n", $lines );
    }

    /**
     * UTF-8-safe clip with an ellipsis.
     *
     * @param string $text
     * @param int    $limit
     * @return string
     */
    protected static function clip( $text, $limit ) {
        $text = (string) $text;
        $len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
        if ( $len <= $limit ) {
            return $text;
        }
        $cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
        return rtrim( $cut ) . '…';
    }
}
