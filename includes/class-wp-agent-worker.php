<?php
/**
 * Durable background worker for autonomous agent runs.
 *
 * The worker is the single execution path for queued runs. It can be driven by
 * WP-CLI, a bounded WP-Cron fallback, or a lightweight UI nudge.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Worker {

    /** @var int Default cron batch size. */
    const CRON_BATCH = 1;

    /** @var int Default cron time budget in seconds. */
    const CRON_SECONDS = 20;

    /**
     * Register schedules and callbacks.
     */
    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_interval' ) );
        add_action( 'wp_agent_worker_tick', array( __CLASS__, 'cron_tick' ) );
    }

    /**
     * Add a one-minute worker tick for degraded hosting environments.
     *
     * @param array $schedules
     * @return array
     */
    public static function register_cron_interval( $schedules ) {
        $schedules['wp_agent_one_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every minute', 'wp-agent' ),
        );
        return $schedules;
    }

    /**
     * Schedule fallback cron.
     */
    public static function schedule_cron() {
        if ( ! wp_next_scheduled( 'wp_agent_worker_tick' ) ) {
            wp_schedule_event( time() + 30, 'wp_agent_one_minute', 'wp_agent_worker_tick' );
        }
    }

    /**
     * Clear fallback cron.
     */
    public static function clear_cron() {
        wp_clear_scheduled_hook( 'wp_agent_worker_tick' );
    }

    /**
     * WP-Cron callback.
     */
    public static function cron_tick() {
        if ( class_exists( 'WPAgent_Daemon' ) ) {
            $watchdog = WPAgent_Daemon::watchdog( array( 'source' => 'wp_cron' ) );
            if ( ! is_wp_error( $watchdog ) && empty( $watchdog['fallback_recommended'] ) ) {
                return;
            }
        }

        self::tick( self::CRON_BATCH, self::CRON_SECONDS );
    }

    /**
     * Advance a bounded number of queued runs.
     *
     * @param int $batch       Max runs/steps to execute.
     * @param int $max_seconds Time budget.
     * @return array
     */
    public static function tick( $batch = 1, $max_seconds = 20 ) {
        $batch       = max( 1, min( (int) $batch, 20 ) );
        $max_seconds = max( 1, min( (int) $max_seconds, 300 ) );
        $deadline    = time() + $max_seconds;
        $results     = array();

        for ( $i = 0; $i < $batch && time() < $deadline; $i++ ) {
            $result = self::run_once();
            $results[] = $result;

            if ( ! empty( $result['idle'] ) ) {
                break;
            }
        }

        return array(
            'ok'      => true,
            'results' => $results,
        );
    }

    /**
     * Run the worker loop for CLI entrypoints.
     *
     * @param array         $args Optional max_seconds, sleep, batch, once.
     * @param callable|null $logger Receives each log line.
     * @return array Summary.
     */
    public static function run_loop( $args = array(), callable $logger = null ) {
        $max_seconds = isset( $args['max_seconds'] ) ? max( 1, (int) $args['max_seconds'] ) : 300;
        $sleep       = isset( $args['sleep'] ) ? max( 0, (int) $args['sleep'] ) : 2;
        $batch       = isset( $args['batch'] ) ? max( 1, (int) $args['batch'] ) : 1;
        $once        = ! empty( $args['once'] );
        $deadline    = time() + $max_seconds;
        $ticks       = 0;
        $processed   = 0;

        if ( $logger ) {
            $logger( 'WP Agent worker started.' );
        }

        do {
            $result = self::tick( $batch, min( 30, max( 1, $deadline - time() ) ) );
            $ticks++;

            foreach ( $result['results'] as $entry ) {
                if ( empty( $entry['idle'] ) ) {
                    $processed++;
                }
                if ( $logger ) {
                    $logger( ! empty( $entry['idle'] ) ? 'idle' : wp_json_encode( $entry ) );
                }
            }

            if ( $once || time() >= $deadline ) {
                break;
            }

            $all_idle = ! empty( $result['results'] )
                && count( $result['results'] ) === 1
                && ! empty( $result['results'][0]['idle'] );
            if ( $all_idle && $sleep > 0 ) {
                sleep( min( $sleep, max( 0, $deadline - time() ) ) );
            }
        } while ( true );

        $summary = array(
            'ticks'     => $ticks,
            'processed' => $processed,
        );

        if ( $logger ) {
            $logger( 'WP Agent worker stopped after ' . $ticks . ' tick(s).' );
        }

        return $summary;
    }

    /**
     * Claim and advance exactly one agent step.
     *
     * @return array
     */
    public static function run_once( $preferred_run_id = 0 ) {
        $preferred_run_id = (int) $preferred_run_id;
        $candidate = $preferred_run_id > 0 ? WPAgent_Runs::get( $preferred_run_id ) : WPAgent_Runs::next_claimable();
        if ( ! $candidate ) {
            return array( 'idle' => true );
        }

        $run_id = (int) $candidate->id;
        if ( ! in_array( $candidate->status, array( 'queued', 'running' ), true ) ) {
            return array(
                'idle'   => false,
                'run_id' => $run_id,
                'status' => $candidate->status,
            );
        }

        if ( ! WPAgent_Runs::claim( $run_id ) ) {
            return array( 'idle' => false, 'claimed' => false, 'run_id' => $run_id );
        }
        WPAgent_Run_Events::add( $run_id, (int) $candidate->user_id, 'claimed', 'Worker claimed run.' );

        $run = WPAgent_Runs::get( $run_id );
        if ( ! $run ) {
            return array( 'idle' => false, 'run_id' => $run_id, 'error' => 'Run disappeared after claim.' );
        }

        if ( 'canceled' === (string) $run->status ) {
            WPAgent_Run_Events::add( $run_id, (int) $candidate->user_id, 'canceled_observed', 'Worker observed run cancellation before executing a step.' );
            self::sync_schedule_status( $run_id );
            return array( 'idle' => false, 'run_id' => $run_id, 'status' => 'canceled' );
        }

        try {
            $iteration_cap = WPAgent_Agent::effective_max_iterations_for_run( $run );
            if ( $iteration_cap > 0 && (int) $run->loop_count >= $iteration_cap ) {
                // Reached the iteration cap: force a final summary and complete
                // the run gracefully instead of erroring out.
                $channel = ! empty( $run->channel ) ? (string) $run->channel : 'webchat';
                $summary = WPAgent::get_agent()->run_summary_step(
                    (int) $run->conversation_id,
                    (int) $run->user_id,
                    $run_id,
                    $channel
                );
                WPAgent_Runs::incr_loop( $run_id );
                WPAgent_Runs::set_done( $run_id );
                WPAgent_Run_Events::add( $run_id, (int) $run->user_id, 'iteration_limit_summary', 'Reached the iteration limit; produced a final summary.', array( 'limit' => $iteration_cap ) );
                if ( ! empty( $summary['final'] ) ) {
                    self::notify_channel_completion( $run, (string) $summary['final'] );
                }
                if ( class_exists( 'WPAgent_Journal' ) ) {
                    WPAgent_Journal::add(
                        (int) $run->user_id,
                        'action',
                        'Completed run #' . $run_id . ' at iteration limit',
                        isset( $summary['final'] ) ? mb_substr( (string) $summary['final'], 0, 1000 ) : 'The run reached the iteration limit.',
                        array( 'channel' => $channel, 'status' => 'done', 'iteration_limit' => $iteration_cap ),
                        (int) $run->conversation_id,
                        $run_id
                    );
                }
                if ( (int) $run->parent_run_id > 0 && isset( $summary['final'] ) ) {
                    WPAgent_Runs::set_result_summary( $run_id, (string) $summary['final'] );
                }
                self::sync_schedule_status( $run_id );
                self::maybe_resume_parent( $run_id );
                return array( 'idle' => false, 'run_id' => $run_id, 'status' => 'done', 'iteration_limit' => true );
            }

            $channel = ! empty( $run->channel ) ? (string) $run->channel : 'webchat';
            $step    = WPAgent::get_agent()->run_step(
                (int) $run->conversation_id,
                (int) $run->user_id,
                $run_id,
                $channel
            );

            if ( WPAgent_Runs::is_canceled( $run_id ) ) {
                WPAgent_Run_Events::add( $run_id, (int) $run->user_id, 'canceled_observed', 'Worker stopped after a cooperative cancellation request.' );
                self::sync_schedule_status( $run_id );
                self::maybe_resume_parent( $run_id );
                return array( 'idle' => false, 'run_id' => $run_id, 'status' => 'canceled' );
            }

            WPAgent_Runs::incr_loop( $run_id );

            if ( ! empty( $step['awaiting_subagents'] ) ) {
                WPAgent_Runs::set_awaiting_subagents( $run_id, (string) ( $step['subagent_group'] ?? '' ), (string) ( $step['parent_tool_call_id'] ?? '' ) );
                self::sync_schedule_status( $run_id );
                return array(
                    'idle'           => false,
                    'run_id'         => $run_id,
                    'status'         => 'awaiting_subagents',
                    'subagent_group' => (string) ( $step['subagent_group'] ?? '' ),
                );
            }

            if ( ! empty( $step['awaiting_confirmation'] ) ) {
                $confirmation_id = (int) ( $step['confirmation_id'] ?? 0 );
                WPAgent_Runs::set_awaiting_confirmation( $run_id, 'Awaiting human confirmation #' . $confirmation_id, array( 'confirmation_id' => $confirmation_id ) );
                self::sync_schedule_status( $run_id );
                return array(
                    'idle'            => false,
                    'run_id'          => $run_id,
                    'status'          => 'awaiting_confirmation',
                    'confirmation_id' => $confirmation_id,
                );
            }

            if ( ! empty( $step['done'] ) ) {
                WPAgent_Runs::set_done( $run_id );
                WPAgent_Run_Events::add( $run_id, (int) $run->user_id, 'done', 'Run completed.' );
                if ( ! empty( $step['final'] ) ) {
                    self::notify_channel_completion( $run, (string) $step['final'] );
                }
                if ( class_exists( 'WPAgent_Journal' ) ) {
                    WPAgent_Journal::add(
                        (int) $run->user_id,
                        'action',
                        'Completed run #' . $run_id,
                        isset( $step['final'] ) ? mb_substr( (string) $step['final'], 0, 1000 ) : 'The run completed.',
                        array( 'channel' => $channel, 'status' => 'done' ),
                        (int) $run->conversation_id,
                        $run_id
                    );
                }
                if ( (int) $run->parent_run_id > 0 && isset( $step['final'] ) ) {
                    WPAgent_Runs::set_result_summary( $run_id, (string) $step['final'] );
                }
                self::sync_schedule_status( $run_id );
                self::maybe_resume_parent( $run_id );
                return array( 'idle' => false, 'run_id' => $run_id, 'status' => 'done' );
            }

            WPAgent_Runs::release( $run_id );
            WPAgent_Run_Events::add( $run_id, (int) $run->user_id, 'step', 'Run step completed; more work remains.' );
            self::sync_schedule_status( $run_id );
            return array( 'idle' => false, 'run_id' => $run_id, 'status' => 'running' );
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WP Agent] Worker run error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }

            $classification = self::classify_exception( $e );
            if ( 'retryable' === $classification['type'] && WPAgent_Runs::can_retry( $run ) ) {
                $attempt_number = (int) ( $run->attempt_count ?? 0 ) + 1;
                $delay          = WPAgent_Runs::retry_delay_seconds( $attempt_number );
                /**
                 * Filter retry backoff for tests or site-specific operations policy.
                 *
                 * @param int       $delay          Delay in seconds.
                 * @param int       $run_id         Run ID.
                 * @param Exception $exception      Failure exception.
                 * @param array     $classification Retry classification.
                 */
                $delay = (int) apply_filters( 'wp_agent_run_retry_delay', $delay, $run_id, $e, $classification );
                $next_attempt_at = WPAgent_Runs::set_retry( $run_id, $e->getMessage(), $delay, $classification['code'] );
                WPAgent_Run_Events::add(
                    $run_id,
                    (int) $run->user_id,
                    'retry_scheduled',
                    'Retryable run failure; next attempt scheduled.',
                    array(
                        'attempt_count'   => $attempt_number,
                        'max_attempts'    => WPAgent_Runs::MAX_ATTEMPTS,
                        'delay_seconds'   => max( 0, $delay ),
                        'next_attempt_at' => $next_attempt_at,
                        'error_code'      => $classification['code'],
                    )
                );
                self::sync_schedule_status( $run_id );

                return array(
                    'idle'            => false,
                    'run_id'          => $run_id,
                    'status'          => 'retry_scheduled',
                    'attempt_count'   => $attempt_number,
                    'next_attempt_at' => $next_attempt_at,
                    'error'           => $e->getMessage(),
                );
            }

            WPAgent_Runs::set_error( $run_id, $e->getMessage(), $classification['code'] );
            WPAgent_Run_Events::add(
                $run_id,
                (int) $run->user_id,
                'error',
                $e->getMessage(),
                array(
                    'attempt_count' => (int) ( $run->attempt_count ?? 0 ),
                    'error_code'    => $classification['code'],
                    'retryable'     => 'retryable' === $classification['type'],
                )
            );
            if ( (int) $run->parent_run_id > 0 ) {
                WPAgent_Runs::set_result_summary( $run_id, 'Sub-agent failed: ' . mb_substr( $e->getMessage(), 0, 300 ) );
            }
            self::sync_schedule_status( $run_id );
            self::maybe_resume_parent( $run_id );
            if ( class_exists( 'WPAgent_Journal' ) ) {
                WPAgent_Journal::add(
                    (int) $run->user_id,
                    'error',
                    'Run #' . $run_id . ' failed',
                    mb_substr( $e->getMessage(), 0, 1000 ),
                    array( 'status' => 'error' ),
                    (int) $run->conversation_id,
                    $run_id
                );
            }
            return array( 'idle' => false, 'run_id' => $run_id, 'status' => 'error', 'error' => $e->getMessage() );
        }
    }

    /**
     * Advance one run for the poll-driven chat, also nudging a claimable
     * sub-agent when the polled run is parked waiting on its sub-agents. This
     * lets delegation make progress even without a resident daemon.
     *
     * @param int $run_id Polled run ID.
     * @return array
     */
    public static function poll_advance( $run_id ) {
        $result = self::run_once( (int) $run_id );

        $run = WPAgent_Runs::get( (int) $run_id );
        if ( $run && 'awaiting_subagents' === (string) $run->status ) {
            self::run_once(); // Drain a claimable sub-agent step between polls.
        }

        return $result;
    }

    /**
     * If a finished child was the last of its sub-agent batch, aggregate the
     * children's summaries into the parent's tool result and resume the parent.
     *
     * @param int $child_run_id
     * @return void
     */
    private static function maybe_resume_parent( $child_run_id ) {
        $child = WPAgent_Runs::get( (int) $child_run_id );
        if ( ! $child || (int) $child->parent_run_id <= 0 ) {
            return;
        }

        $parent_id = (int) $child->parent_run_id;
        if ( WPAgent_Runs::pending_children_count( $parent_id ) > 0 ) {
            return; // Other sub-agents are still running.
        }

        // Atomic guard: only the single winner resumes and aggregates.
        if ( ! WPAgent_Runs::resume_from_subagents( $parent_id ) ) {
            return;
        }

        $parent = WPAgent_Runs::get( $parent_id );
        if ( ! $parent ) {
            return;
        }

        $results = array();
        foreach ( WPAgent_Runs::children_of( $parent_id ) as $c ) {
            $summary = (string) $c->result_summary;
            if ( '' === $summary ) {
                $summary = 'done' === (string) $c->status
                    ? '(no summary returned)'
                    : 'Sub-agent ended with status: ' . (string) $c->status;
            }
            $results[] = array(
                'run_id'  => (int) $c->id,
                'status'  => (string) $c->status,
                'summary' => $summary,
            );
        }

        $payload = wp_json_encode( array(
            'subagent_results' => $results,
            'note'             => 'Sub-agent tasks finished. Use these summaries to continue toward the original goal.',
        ) );

        $conversation = new WPAgent_Conversation();
        $conversation->add_message( (int) $parent->conversation_id, 'tool', $payload, array(
            'tool_results' => array( 'tool_call_id' => (string) $parent->parent_tool_call_id ),
        ) );

        WPAgent_Run_Events::add( $parent_id, (int) $parent->user_id, 'subagent_group_complete', 'Sub-agents finished; parent run resumed.', array(
            'children' => count( $results ),
        ) );
    }

    /**
     * Reflect durable run state onto a schedule that queued it.
     *
     * @param int $run_id
     * @return void
     */
    private static function sync_schedule_status( $run_id ) {
        if ( class_exists( 'WPAgent_Schedules' ) ) {
            WPAgent_Schedules::sync_by_run( (int) $run_id );
        }
    }

    /**
     * Classify a worker exception for durable run retry policy.
     *
     * @param Exception $e
     * @return array{type:string,code:string}
     */
    private static function classify_exception( Exception $e ) {
        $message = strtolower( $e->getMessage() );

        $non_retryable = array(
            'budget',
            'permission denied',
            'human confirmation',
            'quality gate',
            'invalid source',
            'invalid generated image',
            'unsupported',
            'no hardened sandbox',
            'forbidden',
            'unauthorized',
        );
        foreach ( $non_retryable as $needle ) {
            if ( false !== strpos( $message, $needle ) ) {
                return array( 'type' => 'final', 'code' => 'non_retryable' );
            }
        }

        $retryable = array(
            '429',
            '503',
            '529',
            'rate limit',
            'too many requests',
            'timeout',
            'timed out',
            'temporarily',
            'temporary',
            'overloaded',
            'service unavailable',
            'connection',
            'transport',
            'curl',
        );
        foreach ( $retryable as $needle ) {
            if ( false !== strpos( $message, $needle ) ) {
                return array( 'type' => 'retryable', 'code' => 'transient' );
            }
        }

        return array( 'type' => 'final', 'code' => 'exception' );
    }

    /**
     * Send completed responses back to external channels.
     *
     * @param object $run
     * @param string $text
     * @return void
     */
    private static function notify_channel_completion( $run, $text ) {
        $channel_name = ! empty( $run->channel ) ? (string) $run->channel : 'webchat';
        if ( in_array( $channel_name, array( 'webchat', 'schedule' ), true ) ) {
            return;
        }

        $conversation = new WPAgent_Conversation();
        $row = $conversation->get_conversation( (int) $run->conversation_id );
        if ( ! $row || empty( $row['channel_chat_id'] ) ) {
            return;
        }

        $channel = self::build_channel( $channel_name );
        if ( ! $channel ) {
            return;
        }

        $channel->send_message( $row['channel_chat_id'], $text );
    }

    /**
     * Build a channel sender from configured credentials.
     *
     * @param string $channel_name
     * @return WPAgent_Channel|null
     */
    private static function build_channel( $channel_name ) {
        switch ( $channel_name ) {
            case 'telegram':
                $token = WPAgent::get_option( 'telegram_bot_token' );
                return ! empty( $token ) && class_exists( 'WPAgent_Channel_Telegram' )
                    ? new WPAgent_Channel_Telegram( WPAgent::decrypt( $token ) )
                    : null;

            case 'slack':
                $token = WPAgent::get_option( 'slack_bot_token' );
                return ! empty( $token ) && class_exists( 'WPAgent_Channel_Slack' )
                    ? new WPAgent_Channel_Slack( WPAgent::decrypt( $token ) )
                    : null;

            case 'discord':
                $token = WPAgent::get_option( 'discord_bot_token' );
                if ( empty( $token ) || ! class_exists( 'WPAgent_Channel_Discord' ) ) {
                    return null;
                }
                return new WPAgent_Channel_Discord( WPAgent::decrypt( $token ), WPAgent::get_option( 'discord_application_id' ) );

            default:
                return null;
        }
    }
}
