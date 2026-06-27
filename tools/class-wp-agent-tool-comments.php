<?php
/**
 * Comments tool — moderate, reply to, and manage WordPress comments.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Comments extends WPAgent_Tool {

    public function get_name() {
        return 'manage_comments';
    }

    public function get_description() {
        return 'List, inspect, triage, approve, spam, trash, or reply to WordPress comments. Great for content moderation.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type' => 'string',
                    'enum' => array( 'list', 'get', 'triage', 'approve', 'spam', 'trash', 'reply' ),
                    'description' => 'The operation to perform.',
                ),
                'comment_id' => array(
                    'type' => 'integer',
                    'description' => 'Comment ID (required for get, triage, approve, spam, trash, reply).',
                ),
                'reply_content' => array(
                    'type' => 'string',
                    'description' => 'Reply text (for reply action).',
                ),
                'reply_tone' => array(
                    'type' => 'string',
                    'enum' => array( 'neutral', 'friendly', 'formal' ),
                    'description' => 'Tone for triage reply suggestions. Default: friendly.',
                ),
                'status_filter' => array(
                    'type' => 'string',
                    'enum' => array( 'all', 'hold', 'approve', 'spam', 'trash' ),
                    'description' => 'Filter by status (for list). Default: hold (pending).',
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Number of comments to return (default 10).',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'moderate_comments';
    }

    public function execute( array $params ) {
        switch ( $params['action'] ) {
            case 'list':
                $args = array(
                    'status' => $params['status_filter'] ?? 'hold',
                    'number' => min( (int) ( $params['limit'] ?? 10 ), 50 ),
                    'orderby' => 'comment_date_gmt',
                    'order'   => 'DESC',
                );
                $comments = get_comments( $args );
                $result = array();
                foreach ( $comments as $c ) {
                    $result[] = $this->format_comment( $c, false );
                }
                $counts = wp_count_comments();
                return array(
                    'comments' => $result,
                    'counts'   => array(
                        'pending'  => $counts->moderated,
                        'approved' => $counts->approved,
                        'spam'     => $counts->spam,
                        'trash'    => $counts->trash,
                        'total'    => $counts->total_comments,
                    ),
                );

            case 'get':
                if ( empty( $params['comment_id'] ) ) return array( 'error' => 'comment_id required.' );
                $comment = get_comment( $params['comment_id'] );
                if ( ! $comment ) return array( 'error' => 'Comment not found.' );
                return array(
                    'success' => true,
                    'comment' => $this->format_comment( $comment, true ),
                );

            case 'triage':
                if ( empty( $params['comment_id'] ) ) return array( 'error' => 'comment_id required.' );
                $comment = get_comment( $params['comment_id'] );
                if ( ! $comment ) return array( 'error' => 'Comment not found.' );
                $tone = $params['reply_tone'] ?? 'friendly';
                if ( ! in_array( $tone, array( 'neutral', 'friendly', 'formal' ), true ) ) {
                    $tone = 'friendly';
                }
                return $this->triage_comment( $comment, $tone );

            case 'approve':
                if ( empty( $params['comment_id'] ) ) return array( 'error' => 'comment_id required.' );
                wp_set_comment_status( $params['comment_id'], 'approve' );
                return array( 'success' => true, 'message' => 'Comment approved.' );

            case 'spam':
                if ( empty( $params['comment_id'] ) ) return array( 'error' => 'comment_id required.' );
                wp_spam_comment( $params['comment_id'] );
                return array( 'success' => true, 'message' => 'Comment marked as spam.' );

            case 'trash':
                if ( empty( $params['comment_id'] ) ) return array( 'error' => 'comment_id required.' );
                wp_trash_comment( $params['comment_id'] );
                return array( 'success' => true, 'message' => 'Comment trashed.' );

            case 'reply':
                if ( empty( $params['comment_id'] ) ) return array( 'error' => 'comment_id required.' );
                if ( empty( $params['reply_content'] ) ) return array( 'error' => 'reply_content required.' );
                $parent = get_comment( $params['comment_id'] );
                if ( ! $parent ) return array( 'error' => 'Parent comment not found.' );
                $reply_id = wp_insert_comment( array(
                    'comment_post_ID'  => $parent->comment_post_ID,
                    'comment_content'  => sanitize_text_field( $params['reply_content'] ),
                    'comment_parent'   => $params['comment_id'],
                    'comment_approved' => 1,
                    'user_id'          => get_current_user_id(),
                    'comment_author'   => wp_get_current_user()->display_name,
                    'comment_author_email' => wp_get_current_user()->user_email,
                ) );
                return array( 'success' => true, 'reply_id' => $reply_id, 'message' => 'Reply posted.' );

            default:
                return array( 'error' => 'Unknown action.' );
        }
    }

    /**
     * Format a comment for model/operator review.
     *
     * @param WP_Comment $comment      Comment object.
     * @param bool       $include_full Include full plain-text body.
     * @return array
     */
    private function format_comment( $comment, $include_full ) {
        $content = trim( wp_strip_all_tags( (string) $comment->comment_content ) );

        return array(
            'id'      => (int) $comment->comment_ID,
            'author'  => (string) $comment->comment_author,
            'email'   => (string) $comment->comment_author_email,
            'content' => $include_full ? $content : wp_trim_words( $content, 30 ),
            'date'    => $comment->comment_date,
            'status'  => wp_get_comment_status( $comment ),
            'post'    => get_the_title( $comment->comment_post_ID ),
            'post_id' => (int) $comment->comment_post_ID,
            'parent'  => (int) $comment->comment_parent,
        );
    }

    /**
     * Return non-mutating moderation signals and a reply suggestion.
     *
     * This is a deterministic pre-filter. The agent/model can use these signals
     * as context before choosing approve, spam, trash, or reply.
     *
     * @param WP_Comment $comment Comment object.
     * @param string     $tone    Reply tone.
     * @return array
     */
    private function triage_comment( $comment, $tone ) {
        $content = trim( wp_strip_all_tags( (string) $comment->comment_content ) );
        $lower   = strtolower( $content );
        $signals = array();
        $score   = 0;

        $url_count = preg_match_all( '~https?://[^\s<]+~i', $content, $matches );
        if ( $url_count >= 2 ) {
            $score    += 2;
            $signals[] = 'many_links';
        } elseif ( 1 === $url_count ) {
            $score    += 1;
            $signals[] = 'external_link';
        }

        if ( false !== strpos( $lower, 'http://' ) ) {
            $score    += 1;
            $signals[] = 'plain_http_link';
        }

        foreach ( array( 'viagra', 'casino', 'payday loan', 'crypto', 'forex', 'backlink', 'free money', 'work from home', 'click here' ) as $term ) {
            if ( false !== strpos( $lower, $term ) ) {
                $score    += 2;
                $signals[] = 'spam_term:' . $term;
            }
        }

        if ( strlen( $content ) < 12 ) {
            $score    += 1;
            $signals[] = 'low_information';
        }

        $email = (string) $comment->comment_author_email;
        if ( '' === $email || ! is_email( $email ) ) {
            $score    += 1;
            $signals[] = 'invalid_email';
        }

        $looks_like_question = false !== strpos( $content, '?' )
            || (bool) preg_match( '/\b(how|what|why|where|when|could|would|can|thanks|thank you)\b/i', $content );

        if ( $score >= 3 ) {
            $recommended = 'spam';
        } elseif ( $score >= 2 ) {
            $recommended = 'hold';
        } elseif ( $looks_like_question ) {
            $recommended = 'reply';
        } else {
            $recommended = 'approve';
        }

        return array(
            'success'           => true,
            'comment'           => $this->format_comment( $comment, true ),
            'signals'           => array_values( array_unique( $signals ) ),
            'spam_score'        => $score,
            'recommended_action' => $recommended,
            'requires_review'   => in_array( $recommended, array( 'hold', 'reply' ), true ),
            'reply_suggestion'  => in_array( $recommended, array( 'reply', 'approve', 'hold' ), true )
                ? $this->suggest_reply( $comment, $tone, $looks_like_question )
                : '',
        );
    }

    /**
     * Build a short deterministic reply suggestion for operator/model review.
     *
     * @param WP_Comment $comment             Comment object.
     * @param string     $tone                Reply tone.
     * @param bool       $looks_like_question Whether the comment asks a question.
     * @return string
     */
    private function suggest_reply( $comment, $tone, $looks_like_question ) {
        $name = trim( (string) $comment->comment_author );
        $name = '' !== $name ? strtok( $name, " \t\r\n" ) : 'there';

        if ( $looks_like_question ) {
            if ( 'formal' === $tone ) {
                return 'Thank you for your question, ' . $name . '. We will review the topic and add more detail where it helps readers.';
            }
            if ( 'neutral' === $tone ) {
                return 'Thanks for the question, ' . $name . '. We will review this and add clarification if needed.';
            }
            return 'Thanks for asking, ' . $name . '. We will take a closer look and add more detail where useful.';
        }

        if ( 'formal' === $tone ) {
            return 'Thank you for your comment, ' . $name . '. We appreciate your contribution to the discussion.';
        }
        if ( 'neutral' === $tone ) {
            return 'Thanks for your comment, ' . $name . '. We appreciate the feedback.';
        }
        return 'Thanks for reading and sharing your thoughts, ' . $name . '.';
    }
}
