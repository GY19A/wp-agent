<?php
/**
 * Async run queue for poll-driven chat.
 *
 * Each run represents a queued unit of agent work tied to a single user
 * message. The /chat/poll endpoint atomically claims a run, executes one
 * iteration of the agent loop, and releases it until completion.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Runs {

    /** @var int Lock duration in seconds for a claimed run. */
    const LOCK_SECONDS = 300;

    /** @var int Maximum failed attempts before a retryable run becomes final error. */
    const MAX_ATTEMPTS = 3;

    /**
     * Table name helper.
     *
     * @return string
     */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wp_agent_runs';
    }

    /**
     * Create a new queued run.
     *
     * @param int $conversation_id
     * @param int $user_id
     * @param int    $message_id
     * @param string $channel
     * @return int Run ID.
     */
    public static function create( $conversation_id, $user_id, $message_id, $channel = 'webchat' ) {
        global $wpdb;

        $now = current_time( 'mysql', true );
        $channel = sanitize_key( $channel );
        if ( '' === $channel ) {
            $channel = 'webchat';
        }

        $wpdb->insert(
            self::table(),
            array(
                'conversation_id' => $conversation_id,
                'user_id'         => $user_id,
                'message_id'      => $message_id,
                'channel'         => $channel,
                'status'          => 'queued',
                'loop_count'      => 0,
                'attempt_count'   => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        $run_id = (int) $wpdb->insert_id;
        if ( $run_id && class_exists( 'WPAgent_Run_Events' ) ) {
            WPAgent_Run_Events::add( $run_id, $user_id, 'queued', 'Run queued.', array( 'channel' => $channel ) );
        }

        return $run_id;
    }

    /**
     * Fetch a run by ID.
     *
     * @param int $id
     * @return object|null
     */
    public static function get( $id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $id
        ) );

        return $row ? $row : null;
    }

    /**
     * Find the next queued/running run whose lease is available.
     *
     * @return object|null
     */
    public static function next_claimable() {
        global $wpdb;

        $now = current_time( 'mysql', true );
        $table = self::table();

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT r.* FROM {$table} r
             WHERE r.status IN ('queued','running')
               AND ( r.locked_until IS NULL OR r.locked_until < %s )
               AND ( r.next_attempt_at IS NULL OR r.next_attempt_at <= %s )
               AND NOT EXISTS (
                   SELECT 1 FROM {$table} prior
                   WHERE prior.conversation_id = r.conversation_id
                     AND prior.user_id = r.user_id
                     AND prior.id < r.id
                     AND prior.status IN ('queued','running','awaiting_confirmation','awaiting_subagents')
               )
             ORDER BY r.id ASC
             LIMIT 1",
            $now,
            $now
        ) );
    }

    /**
     * Count runs currently claimable by a worker or child agent.
     *
     * @return int
     */
    public static function claimable_count() {
        global $wpdb;

        $now = current_time( 'mysql', true );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM " . self::table() . "
             WHERE status IN ('queued','running')
               AND ( locked_until IS NULL OR locked_until < %s )
               AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )",
            $now,
            $now
        ) );
    }

    /**
     * Count queued/running runs delayed by retry backoff.
     *
     * @return int
     */
    public static function retry_scheduled_count() {
        global $wpdb;

        $now = current_time( 'mysql', true );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM " . self::table() . "
             WHERE status IN ('queued','running')
               AND next_attempt_at IS NOT NULL
               AND next_attempt_at > %s",
            $now
        ) );
    }

    /**
     * Next retry time for any delayed queued/running run.
     *
     * @return string|null
     */
    public static function next_retry_at() {
        global $wpdb;

        $now = current_time( 'mysql', true );

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(next_attempt_at)
             FROM " . self::table() . "
             WHERE status IN ('queued','running')
               AND next_attempt_at IS NOT NULL
               AND next_attempt_at > %s",
            $now
        ) );
    }

    /**
     * Count runs grouped by status.
     *
     * @return array
     */
    public static function status_counts() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS count
             FROM " . self::table() . "
             GROUP BY status",
            ARRAY_A
        );
        $counts = array();
        foreach ( $rows as $row ) {
            $counts[ (string) $row['status'] ] = (int) $row['count'];
        }
        return $counts;
    }

    /**
     * Get the newest unfinished run for a conversation owned by a user.
     *
     * @param int $conversation_id Conversation ID.
     * @param int $user_id         User ID.
     * @return object|null
     */
    public static function active_for_conversation( $conversation_id, $user_id ) {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE conversation_id = %d
               AND user_id = %d
               AND status IN ('queued','running','awaiting_confirmation','awaiting_subagents')
             ORDER BY id DESC
             LIMIT 1",
            (int) $conversation_id,
            (int) $user_id
        ) );
    }

    /**
     * Summarize unfinished queue state for a specific run.
     *
     * @param int $id Run ID.
     * @return array
     */
    public static function queue_summary_for_run( $id ) {
        $run = self::get( (int) $id );
        if ( ! $run ) {
            return array(
                'run_id'           => (int) $id,
                'status'           => 'missing',
                'active_total'     => 0,
                'position'         => 0,
                'blocked_by_prior' => false,
                'updated_at'       => null,
            );
        }

        $terminal = self::is_terminal_status( (string) $run->status );

        return array(
            'run_id'           => (int) $run->id,
            'status'           => (string) $run->status,
            'active_total'     => self::unfinished_count_for_conversation( (int) $run->conversation_id, (int) $run->user_id ),
            'position'         => $terminal ? 0 : self::unfinished_position_for_run( $run ),
            'blocked_by_prior' => ! $terminal && self::has_earlier_active_in_conversation( $run ),
            'updated_at'       => $run->updated_at,
        );
    }

    /**
     * Summarize unfinished queue state for a conversation.
     *
     * @param int $conversation_id Conversation ID.
     * @param int $user_id         User ID.
     * @return array
     */
    public static function queue_summary_for_conversation( $conversation_id, $user_id ) {
        return array(
            'run_id'           => 0,
            'status'           => 'idle',
            'active_total'     => self::unfinished_count_for_conversation( (int) $conversation_id, (int) $user_id ),
            'position'         => 0,
            'blocked_by_prior' => false,
            'updated_at'       => null,
        );
    }

    /**
     * Count unfinished runs in a conversation.
     *
     * @param int $conversation_id Conversation ID.
     * @param int $user_id         User ID.
     * @return int
     */
    private static function unfinished_count_for_conversation( $conversation_id, $user_id ) {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM " . self::table() . "
             WHERE conversation_id = %d
               AND user_id = %d
               AND status IN ('queued','running','awaiting_confirmation','awaiting_subagents')",
            (int) $conversation_id,
            (int) $user_id
        ) );
    }

    /**
     * Calculate this run's position among unfinished work in its conversation.
     *
     * @param object $run Run row.
     * @return int
     */
    private static function unfinished_position_for_run( $run ) {
        global $wpdb;

        if ( ! $run ) {
            return 0;
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM " . self::table() . "
             WHERE conversation_id = %d
               AND user_id = %d
               AND id <= %d
               AND status IN ('queued','running','awaiting_confirmation','awaiting_subagents')",
            (int) $run->conversation_id,
            (int) $run->user_id,
            (int) $run->id
        ) );
    }

    /**
     * Check whether a run is blocked by an older unfinished run in the same conversation.
     *
     * @param int|object $run Run ID or row object.
     * @return bool
     */
    public static function has_earlier_active_in_conversation( $run ) {
        global $wpdb;

        if ( is_numeric( $run ) ) {
            $run = self::get( (int) $run );
        }
        if ( ! $run ) {
            return false;
        }

        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM " . self::table() . "
             WHERE conversation_id = %d
               AND user_id = %d
               AND id < %d
               AND status IN ('queued','running','awaiting_confirmation','awaiting_subagents')
             LIMIT 1",
            (int) $run->conversation_id,
            (int) $run->user_id,
            (int) $run->id
        ) );
    }

    /**
     * Whether a run status is final.
     *
     * @param string $status Run status.
     * @return bool
     */
    public static function is_terminal_status( $status ) {
        return in_array( (string) $status, array( 'done', 'error', 'canceled' ), true );
    }

    /**
     * Whether a run is currently canceled.
     *
     * @param int $id Run ID.
     * @return bool
     */
    public static function is_canceled( $id ) {
        $run = self::get( (int) $id );
        return $run && 'canceled' === (string) $run->status;
    }

    /**
     * Atomically claim a run for execution.
     *
     * Sets status to running and extends the lock. Only succeeds when the
     * run is queued/running and not currently locked by another worker.
     *
     * @param int $id
     * @return bool True if this caller now holds the lock.
     */
    public static function claim( $id ) {
        global $wpdb;

        $now    = current_time( 'mysql', true );
        $locked = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::LOCK_SECONDS );
        $table  = self::table();
        $before = self::get( $id );
        if ( $before && self::has_earlier_active_in_conversation( $before ) ) {
            return false;
        }
        $stale_running = $before
            && 'running' === (string) $before->status
            && ! empty( $before->locked_until )
            && strtotime( (string) $before->locked_until ) < strtotime( $now );

        // Single atomic UPDATE — the WHERE clause guarantees only one worker
        // can transition the row at a time.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'running', locked_until = %s, updated_at = %s
             WHERE id = %d
               AND status IN ('queued','running')
               AND ( locked_until IS NULL OR locked_until < %s )
               AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )",
            $locked,
            $now,
            $id,
            $now,
            $now
        ) );

        $claimed = $wpdb->rows_affected > 0;
        if ( $claimed && $stale_running && class_exists( 'WPAgent_Run_Events' ) ) {
            WPAgent_Run_Events::add(
                (int) $id,
                (int) $before->user_id,
                'lease_reclaimed',
                'Worker reclaimed a stale run lease.',
                array(
                    'previous_locked_until' => (string) $before->locked_until,
                    'lock_seconds'          => self::LOCK_SECONDS,
                )
            );
        }

        return $claimed;
    }

    /**
     * Release a running run's lock so it can be claimed again.
     *
     * @param int $id
     * @return void
     */
    public static function release( $id ) {
        global $wpdb;

        $table = self::table();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET locked_until = NULL, updated_at = %s
             WHERE id = %d AND status = 'running'",
            current_time( 'mysql', true ),
            $id
        ) );
    }

    /**
     * Requeue a run after human confirmation.
     *
     * @param int $id
     * @return void
     */
    public static function set_queued( $id ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'       => 'queued',
                'error'        => null,
                'locked_until' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'updated_at'   => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Pause a run until a human confirms or rejects an operation.
     *
     * @param int    $id
     * @param string $msg
     * @return void
     */
    public static function set_awaiting_confirmation( $id, $msg = '', $metadata = array() ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'       => 'awaiting_confirmation',
                'error'        => $msg,
                'locked_until' => null,
                'updated_at'   => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( class_exists( 'WPAgent_Run_Events' ) ) {
            $run = self::get( $id );
            if ( $run ) {
                WPAgent_Run_Events::add(
                    (int) $id,
                    (int) $run->user_id,
                    'awaiting_confirmation',
                    '' !== $msg ? $msg : 'Run paused for human confirmation.',
                    $metadata
                );
            }
        }
    }

    /**
     * Mark a run as completed.
     *
     * @param int $id
     * @return void
     */
    public static function set_done( $id ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'       => 'done',
                'error'        => null,
                'locked_until' => null,
                'next_attempt_at' => null,
                'updated_at'   => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Mark a run as canceled.
     *
     * @param int    $id
     * @param string $msg
     * @return void
     */
    public static function set_canceled( $id, $msg = '' ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'       => 'canceled',
                'error'        => $msg,
                'locked_until' => null,
                'next_attempt_at' => null,
                'updated_at'   => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Mark an active run as canceled without changing terminal rows.
     *
     * @param int    $id  Run ID.
     * @param string $msg Cancellation message.
     * @return bool True when the row was canceled by this call.
     */
    public static function cancel_if_active( $id, $msg = '' ) {
        global $wpdb;

        $table = self::table();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'canceled',
                 error = %s,
                 locked_until = NULL,
                 next_attempt_at = NULL,
                 updated_at = %s
             WHERE id = %d
               AND status IN ('queued','running','awaiting_confirmation','awaiting_subagents')",
            $msg,
            current_time( 'mysql', true ),
            (int) $id
        ) );

        return $wpdb->rows_affected > 0;
    }

    /**
     * Mark a run as errored.
     *
     * @param int    $id
     * @param string $msg
     * @return void
     */
    public static function set_error( $id, $msg, $code = '' ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'       => 'error',
                'error'        => $msg,
                'locked_until' => null,
                'next_attempt_at' => null,
                'last_error_code' => sanitize_key( (string) $code ),
                'updated_at'   => current_time( 'mysql', true ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Requeue a retryable failed run after a backoff delay.
     *
     * @param int    $id
     * @param string $msg
     * @param int    $delay_seconds
     * @param string $code
     * @return string Next attempt UTC mysql datetime.
     */
    public static function set_retry( $id, $msg, $delay_seconds, $code = '' ) {
        global $wpdb;

        $delay_seconds = max( 0, (int) $delay_seconds );
        $next          = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );
        $table         = self::table();

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'queued',
                 error = %s,
                 locked_until = NULL,
                 attempt_count = attempt_count + 1,
                 next_attempt_at = %s,
                 last_error_code = %s,
                 updated_at = %s
             WHERE id = %d",
            $msg,
            $next,
            sanitize_key( (string) $code ),
            current_time( 'mysql', true ),
            (int) $id
        ) );

        return $next;
    }

    /**
     * Whether this run can be retried after another failure.
     *
     * @param object $run
     * @return bool
     */
    public static function can_retry( $run ) {
        return $run && (int) ( $run->attempt_count ?? 0 ) < ( self::MAX_ATTEMPTS - 1 );
    }

    /**
     * Backoff delay for the next failed attempt.
     *
     * @param int $attempt_number 1-based failed attempt count after increment.
     * @return int Seconds.
     */
    public static function retry_delay_seconds( $attempt_number ) {
        $attempt_number = max( 1, (int) $attempt_number );
        return min( 900, 60 * ( 2 ** ( $attempt_number - 1 ) ) );
    }

    /**
     * Increment the loop counter for a run.
     *
     * @param int $id
     * @return void
     */
    public static function incr_loop( $id ) {
        global $wpdb;

        $table = self::table();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET loop_count = loop_count + 1, updated_at = %s
             WHERE id = %d",
            current_time( 'mysql', true ),
            $id
        ) );
    }

    /**
     * Create a queued child (sub-agent) run linked to a parent run.
     *
     * The child lives in its OWN isolated conversation so it bypasses the
     * per-conversation FIFO claim guard and can run in parallel.
     *
     * @param int    $conversation_id Isolated child conversation ID.
     * @param int    $user_id         Owning user (inherited from the parent/requester).
     * @param int    $message_id      Seed message ID in the child conversation.
     * @param int    $parent_run_id   Parent (orchestrator) run ID.
     * @param string $subagent_group  UUID grouping the children of one delegate call.
     * @param int    $depth           Nesting depth (parent depth + 1).
     * @param array  $tool_policy     Effective tool policy for the child (stored as JSON).
     * @param string $channel         Channel name (defaults to 'agent').
     * @return int Child run ID.
     */
    public static function create_child( $conversation_id, $user_id, $message_id, $parent_run_id, $subagent_group, $depth, $tool_policy = array(), $channel = 'agent' ) {
        global $wpdb;

        $now     = current_time( 'mysql', true );
        $channel = sanitize_key( $channel );
        if ( '' === $channel ) {
            $channel = 'agent';
        }

        $wpdb->insert(
            self::table(),
            array(
                'conversation_id'  => (int) $conversation_id,
                'user_id'          => (int) $user_id,
                'message_id'       => (int) $message_id,
                'channel'          => $channel,
                'status'           => 'queued',
                'loop_count'       => 0,
                'attempt_count'    => 0,
                'parent_run_id'    => (int) $parent_run_id,
                'subagent_group'   => sanitize_text_field( $subagent_group ),
                'depth'            => max( 0, (int) $depth ),
                'role'             => 'leaf',
                'tool_policy_json' => ! empty( $tool_policy ) ? wp_json_encode( $tool_policy ) : null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
        );

        $run_id = (int) $wpdb->insert_id;
        if ( $run_id && class_exists( 'WPAgent_Run_Events' ) ) {
            WPAgent_Run_Events::add( $run_id, (int) $user_id, 'queued', 'Sub-agent run queued.', array(
                'parent_run_id'  => (int) $parent_run_id,
                'subagent_group' => (string) $subagent_group,
            ) );
        }

        return $run_id;
    }

    /**
     * Pause a parent run while its sub-agents execute.
     *
     * @param int    $id       Parent run ID.
     * @param string $group_id Sub-agent batch group UUID.
     * @return void
     */
    public static function set_awaiting_subagents( $id, $group_id = '', $tool_call_id = '' ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'              => 'awaiting_subagents',
                'subagent_group'      => sanitize_text_field( $group_id ),
                'parent_tool_call_id' => sanitize_text_field( $tool_call_id ),
                'locked_until'        => null,
                'updated_at'          => current_time( 'mysql', true ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( class_exists( 'WPAgent_Run_Events' ) ) {
            $run = self::get( $id );
            if ( $run ) {
                WPAgent_Run_Events::add( (int) $id, (int) $run->user_id, 'awaiting_subagents', 'Run paused while sub-agents run.', array( 'subagent_group' => (string) $group_id ) );
            }
        }
    }

    /**
     * Atomically transition a parent from awaiting_subagents back to queued.
     *
     * Returns true only for the single caller that wins the race (the last
     * child to finish), so aggregation/resume happens exactly once.
     *
     * @param int $id Parent run ID.
     * @return bool
     */
    public static function resume_from_subagents( $id ) {
        global $wpdb;

        $table = self::table();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'queued', locked_until = NULL, next_attempt_at = NULL, updated_at = %s
             WHERE id = %d AND status = 'awaiting_subagents'",
            current_time( 'mysql', true ),
            (int) $id
        ) );

        return $wpdb->rows_affected > 0;
    }

    /**
     * Store a run's final summary (used to feed sub-agent results to the parent).
     *
     * @param int    $id
     * @param string $summary
     * @return void
     */
    public static function set_result_summary( $id, $summary ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'result_summary' => (string) $summary,
                'updated_at'     => current_time( 'mysql', true ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * All child runs of a parent, oldest first.
     *
     * @param int $parent_run_id
     * @return array
     */
    public static function children_of( $parent_run_id ) {
        global $wpdb;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE parent_run_id = %d ORDER BY id ASC",
            (int) $parent_run_id
        ) );
    }

    /**
     * Count a parent's child runs that have not yet reached a terminal state.
     *
     * @param int $parent_run_id
     * @return int
     */
    public static function pending_children_count( $parent_run_id ) {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . "
             WHERE parent_run_id = %d AND status NOT IN ('done','error','canceled')",
            (int) $parent_run_id
        ) );
    }

    /**
     * Cancel all descendant sub-agent runs of a run.
     *
     * Depth is capped at 1 today; the recursion is defensive for future nesting.
     *
     * @param int    $parent_run_id
     * @param string $msg
     * @return void
     */
    public static function propagate_cancel( $parent_run_id, $msg = 'Parent run canceled.' ) {
        $children = self::children_of( $parent_run_id );
        foreach ( $children as $child ) {
            self::cancel_if_active( (int) $child->id, $msg );
            self::propagate_cancel( (int) $child->id, $msg );
        }
    }
}
