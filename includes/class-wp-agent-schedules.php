<?php
/**
 * Scheduled agent tasks.
 *
 * Stores recurring agent prompts and runs them on a cron tick. Each run
 * executes the full synchronous agent loop as the bounded wp-agent identity,
 * so in author mode the result is a pending draft.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Schedules {

    /** @var int Lock duration in seconds for schedule claim protection. */
    const LOCK_SECONDS = 300;

    /**
     * Valid recurrence intervals.
     *
     * @var string[]
     */
    private static $intervals = array( 'minutes', 'hourly', 'daily', 'weekly' );

    /**
     * Register the cron interval and the recurring check callback.
     */
    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_interval' ) );
        add_action( 'wp_agent_check_schedules', array( __CLASS__, 'check_and_run' ) );
    }

    /**
        * Add a one-minute interval to the WP cron schedules.
     *
     * @param array $schedules Existing cron schedules.
     * @return array
     */
    public static function register_cron_interval( $schedules ) {
        $schedules['wp_agent_one_min'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __( 'Every minute', 'wp-agent' ),
        );
        return $schedules;
    }

    /**
     * Schedule the recurring cron event if it is not already scheduled.
     */
    public static function schedule_cron() {
        $next = wp_next_scheduled( 'wp_agent_check_schedules' );
        if ( $next ) {
            $event = wp_get_scheduled_event( 'wp_agent_check_schedules' );
            if ( $event && 'wp_agent_one_min' !== $event->schedule ) {
                wp_clear_scheduled_hook( 'wp_agent_check_schedules' );
                $next = false;
            }
        }

        if ( ! $next ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wp_agent_one_min', 'wp_agent_check_schedules' );
        }
    }

    /**
     * Clear the recurring cron event.
     */
    public static function clear_cron() {
        wp_clear_scheduled_hook( 'wp_agent_check_schedules' );
    }

    /**
     * Create a new schedule.
     *
     * @param int         $created_by   WordPress user ID that owns the schedule.
     * @param string      $prompt       The agent prompt to run.
     * @param string      $interval     One of minutes|hourly|daily|weekly.
     * @param string|null $time_of_day  HH:MM in the site timezone (for daily/weekly).
     * @param int|null    $day_of_week  0 (Sunday) - 6 (Saturday), for weekly.
     * @param int|null    $interval_minutes Number of minutes between runs, for minutes.
     * @param string      $skill_slug       Optional saved Skill slug to bind.
     * @return int The new schedule ID, or 0 on failure.
     */
    public static function create( $created_by, $prompt, $interval, $time_of_day = null, $day_of_week = null, $interval_minutes = null, $skill_slug = '' ) {
        global $wpdb;

        $created_by = (int) $created_by;
        $interval = in_array( $interval, self::$intervals, true ) ? $interval : 'daily';
        $prompt   = (string) $prompt;
        $skill_slug = self::sanitize_skill_slug( $skill_slug );

        if ( '' === trim( $prompt ) ) {
            return 0;
        }
        if ( '' !== $skill_slug && ! self::resolve_skill( $created_by, $skill_slug ) ) {
            return 0;
        }

        $time_of_day = self::sanitize_time( $time_of_day );
        $day_of_week = ( null === $day_of_week ) ? null : max( 0, min( 6, (int) $day_of_week ) );
        $interval_minutes = ( 'minutes' === $interval ) ? self::sanitize_interval_minutes( $interval_minutes ) : null;

        $next_run = self::compute_next_run( $interval, $time_of_day, $day_of_week, null, $interval_minutes );

        $wpdb->insert(
            $wpdb->prefix . 'wp_agent_schedules',
            array(
                'created_by'        => $created_by,
                'prompt'            => $prompt,
                'skill_slug'        => '' !== $skill_slug ? $skill_slug : null,
                'schedule_interval' => $interval,
                'interval_minutes'  => $interval_minutes,
                'time_of_day'       => $time_of_day,
                'day_of_week'       => $day_of_week,
                'status'            => 'active',
                'next_run'          => $next_run,
                'created_at'        => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Parse common natural-language cadence phrases into schedule fields.
     *
     * This intentionally handles a conservative subset of English and Chinese
     * recurrence phrases. Unclear phrases return matched=false so the agent can
     * ask for clarification or pass explicit structured fields.
     *
     * @param string $text Natural schedule phrase.
     * @return array
     */
    public static function parse_natural_language( $text ) {
        $text       = trim( wp_strip_all_tags( (string) $text ) );
        $lower      = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $warnings   = array();
        $result     = array(
            'matched'          => false,
            'interval'         => null,
            'interval_minutes' => null,
            'time'             => null,
            'day_of_week'      => null,
            'warnings'         => array(),
        );

        if ( '' === $text ) {
            $result['warnings'][] = 'No natural-language schedule text was provided.';
            return $result;
        }

        $time = self::parse_natural_time( $lower );
        if ( null !== $time ) {
            $result['time'] = $time;
        }

        $day_of_week = self::parse_natural_day_of_week( $lower );
        if ( null !== $day_of_week ) {
            $result['interval']    = 'weekly';
            $result['day_of_week'] = $day_of_week;
            $result['matched']     = true;
        }

        if ( preg_match( '/\bevery\s+([0-9]{1,4})\s*(?:mins?|minutes?)\b/i', $lower, $m )
            || preg_match( '/每(?:隔)?\s*([0-9一二两三四五六七八九十百]{1,8})\s*分钟/u', $lower, $m ) ) {
            $minutes = self::parse_small_number( $m[1] );
            if ( $minutes > 0 ) {
                $result['interval']         = 'minutes';
                $result['interval_minutes'] = self::sanitize_interval_minutes( $minutes );
                $result['time']             = null;
                $result['day_of_week']      = null;
                $result['matched']          = true;
            }
        } elseif ( preg_match( '/\bevery\s+([0-9]{1,3})\s*(?:hrs?|hours?)\b/i', $lower, $m )
            || preg_match( '/每(?:隔)?\s*([0-9一二两三四五六七八九十百]{1,8})\s*(?:个)?小时/u', $lower, $m ) ) {
            $hours = self::parse_small_number( $m[1] );
            if ( 1 === $hours ) {
                $result['interval'] = 'hourly';
            } elseif ( $hours > 1 ) {
                $result['interval']         = 'minutes';
                $result['interval_minutes'] = self::sanitize_interval_minutes( $hours * 60 );
            }
            if ( null !== $result['interval'] ) {
                $result['time']        = null;
                $result['day_of_week'] = null;
                $result['matched']     = true;
            }
        } elseif ( preg_match( '/\b(hourly|every\s+hour)\b/i', $lower )
            || preg_match( '/每(?:个|一)?小时/u', $lower ) ) {
            $result['interval']    = 'hourly';
            $result['time']        = null;
            $result['day_of_week'] = null;
            $result['matched']     = true;
        } elseif ( null === $result['interval']
            && ( preg_match( '/\b(daily|every\s+day|each\s+day|every\s+morning|every\s+afternoon|every\s+evening|every\s+night)\b/i', $lower )
                || preg_match( '/每天|每日|天天|每天早上|每天上午|每天中午|每天下午|每天晚上/u', $lower ) ) ) {
            $result['interval'] = 'daily';
            $result['matched']  = true;
        }

        if ( null !== $result['time'] && null === $result['interval'] ) {
            $result['interval'] = 'daily';
            $result['matched']  = true;
        }

        if ( 'daily' === $result['interval'] || 'weekly' === $result['interval'] ) {
            $result['time'] = self::sanitize_time( $result['time'] );
        }

        if ( ! $result['matched'] ) {
            $warnings[] = 'No supported cadence phrase was detected.';
        }

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Get all schedules, newest first.
     *
     * @param int $limit Maximum rows to return.
     * @return array
     */
    public static function all( $limit = 100, $user_id = 0 ) {
        global $wpdb;

        $limit = max( 1, (int) $limit );

        if ( $user_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wp_agent_schedules WHERE created_by = %d ORDER BY id DESC LIMIT %d",
                (int) $user_id,
                $limit
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wp_agent_schedules ORDER BY id DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Get a single schedule by ID.
     *
     * @param int $id Schedule ID.
     * @return object|null
     */
    public static function get( $id, $user_id = 0 ) {
        global $wpdb;

        if ( $user_id > 0 ) {
            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wp_agent_schedules WHERE id = %d AND created_by = %d",
                (int) $id,
                (int) $user_id
            ) );
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wp_agent_schedules WHERE id = %d",
            (int) $id
        ) );
    }

    /**
     * Set a schedule's status.
     *
     * @param int    $id     Schedule ID.
     * @param string $status One of active|paused.
     */
    public static function set_status( $id, $status, $user_id = 0 ) {
        global $wpdb;

        $status = in_array( $status, array( 'active', 'paused' ), true ) ? $status : 'paused';

        $where        = array( 'id' => (int) $id );
        $where_format = array( '%d' );
        if ( $user_id > 0 ) {
            $where['created_by'] = (int) $user_id;
            $where_format[]      = '%d';
        }

        $wpdb->update( $wpdb->prefix . 'wp_agent_schedules', array( 'status' => $status ), $where, array( '%s' ), $where_format );
    }

    /**
     * Delete a schedule.
     *
     * @param int $id Schedule ID.
     * @return bool
     */
    public static function delete( $id, $user_id = 0 ) {
        global $wpdb;

        $where        = array( 'id' => (int) $id );
        $where_format = array( '%d' );
        if ( $user_id > 0 ) {
            $where['created_by'] = (int) $user_id;
            $where_format[]      = '%d';
        }

        return (bool) $wpdb->delete(
            $wpdb->prefix . 'wp_agent_schedules',
            $where,
            $where_format
        );
    }

    /**
     * Get active schedules that are due to run.
     *
     * @return array
     */
    public static function due() {
        global $wpdb;

        $now = current_time( 'mysql', true );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wp_agent_schedules
             WHERE status = %s
               AND next_run <= %s
               AND ( locked_until IS NULL OR locked_until < %s )
             ORDER BY next_run ASC",
            'active',
            $now,
            $now
        ) );
    }

    /**
     * Compute the next run time, returned as a UTC 'Y-m-d H:i:s' string.
     *
     * @param string      $interval    One of minutes|hourly|daily|weekly.
     * @param string|null $time_of_day HH:MM in the site timezone (defaults '09:00').
     * @param int|null    $day_of_week 0 (Sunday) - 6 (Saturday) for weekly.
     * @param int|null    $from_ts     Base UNIX timestamp (defaults to now).
     * @param int|null    $interval_minutes Number of minutes between runs, for minutes.
     * @return string
     */
    public static function compute_next_run( $interval, $time_of_day, $day_of_week, $from_ts = null, $interval_minutes = null ) {
        $from_ts = ( null === $from_ts ) ? time() : (int) $from_ts;

        if ( 'minutes' === $interval ) {
            $minutes = self::sanitize_interval_minutes( $interval_minutes );
            return gmdate( 'Y-m-d H:i:s', $from_ts + ( $minutes * MINUTE_IN_SECONDS ) );
        }

        // Hourly is timezone-independent: just one hour from the base time.
        if ( 'hourly' === $interval ) {
            return gmdate( 'Y-m-d H:i:s', $from_ts + HOUR_IN_SECONDS );
        }

        $tz          = wp_timezone();
        $time_of_day = self::sanitize_time( $time_of_day );
        list( $hour, $minute ) = array_map( 'intval', explode( ':', $time_of_day ) );

        // Build a DateTime "now" in the site timezone from the base timestamp.
        $now = new DateTime( '@' . $from_ts );
        $now->setTimezone( $tz );

        $candidate = new DateTime( 'now', $tz );
        $candidate->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), (int) $now->format( 'j' ) );
        $candidate->setTime( $hour, $minute, 0 );

        if ( 'weekly' === $interval ) {
            $target_dow = ( null === $day_of_week ) ? 0 : max( 0, min( 6, (int) $day_of_week ) );
            // PHP 'w': 0 (Sunday) - 6 (Saturday), matching our stored value.
            $current_dow = (int) $candidate->format( 'w' );
            $day_diff    = ( $target_dow - $current_dow + 7 ) % 7;

            if ( $day_diff > 0 ) {
                $candidate->modify( '+' . $day_diff . ' days' );
            } elseif ( $candidate <= $now ) {
                // Today is the target day but the time has already passed.
                $candidate->modify( '+7 days' );
            }
        } else {
            // Daily: today if the time is still ahead, otherwise tomorrow.
            if ( $candidate <= $now ) {
                $candidate->modify( '+1 day' );
            }
        }

        $candidate->setTimezone( new DateTimeZone( 'UTC' ) );

        return $candidate->format( 'Y-m-d H:i:s' );
    }

    /**
     * Enqueue a single schedule for background execution.
     *
     * The schedule tick must stay short. It records a user message in the
     * schedule conversation, creates a durable run, advances next_run, and lets
     * WPAgent_Worker execute the agent loop.
     *
     * @param int $id Schedule ID.
     * @return array
     */
    public static function run( $id ) {
        global $wpdb;

        $s = self::get( $id );

        if ( ! $s || 'paused' === $s->status ) {
            return array( 'ok' => false );
        }

        if ( ! self::claim( (int) $id ) ) {
            return array(
                'ok'      => false,
                'status'  => 'locked',
                'summary' => 'Schedule is already being queued by another worker.',
            );
        }

        $s = self::get( $id );
        if ( ! $s || 'paused' === $s->status ) {
            self::release_lock( (int) $id );
            return array( 'ok' => false );
        }

        $uid = (int) $s->created_by ?: WPAgent_Roles::get_user_id();
        $message = self::build_scheduled_message( $s, $uid );
        if ( is_wp_error( $message ) ) {
            $next_run = self::compute_next_run( $s->schedule_interval, $s->time_of_day, $s->day_of_week, null, $s->interval_minutes ?? null );
            $wpdb->update(
                $wpdb->prefix . 'wp_agent_schedules',
                array(
                    'last_run'     => current_time( 'mysql', true ),
                    'last_run_id'  => null,
                    'last_status'  => 'error',
                    'last_summary' => $message->get_error_message(),
                    'next_run'     => $next_run,
                    'locked_until' => null,
                ),
                array( 'id' => (int) $id ),
                array( '%s', '%d', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );

            return array(
                'ok'      => false,
                'status'  => 'error',
                'summary' => $message->get_error_message(),
            );
        }

        $conversation = new WPAgent_Conversation();
        $conversation_id = $conversation->get_or_create( $uid, 'schedule', 'schedule-' . (int) $id );
        $message_id = $conversation->add_message(
            $conversation_id,
            'user',
            $message
        );
        $run_id = WPAgent_Runs::create( $conversation_id, $uid, $message_id, 'schedule' );
        if ( $run_id <= 0 ) {
            $next_run = self::compute_next_run( $s->schedule_interval, $s->time_of_day, $s->day_of_week, null, $s->interval_minutes ?? null );
            $summary  = 'Could not queue schedule run.';
            $wpdb->update(
                $wpdb->prefix . 'wp_agent_schedules',
                array(
                    'last_run'     => current_time( 'mysql', true ),
                    'last_run_id'  => null,
                    'last_status'  => 'error',
                    'last_summary' => $summary,
                    'next_run'     => $next_run,
                    'locked_until' => null,
                ),
                array( 'id' => (int) $id ),
                array( '%s', '%d', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );

            return array(
                'ok'      => false,
                'status'  => 'error',
                'summary' => $summary,
            );
        }

        $status  = 'queued';
        $summary = 'Queued run #' . $run_id . ' for background execution.';

        $next_run = self::compute_next_run( $s->schedule_interval, $s->time_of_day, $s->day_of_week, null, $s->interval_minutes ?? null );

        $wpdb->update(
            $wpdb->prefix . 'wp_agent_schedules',
            array(
                'last_run'    => current_time( 'mysql', true ),
                'last_run_id' => $run_id,
                'last_status' => $status,
                'last_summary' => $summary,
                'next_run'    => $next_run,
                'locked_until' => null,
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%d', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return array(
            'ok'      => true,
            'status'  => $status,
            'summary' => $summary,
            'run_id'  => $run_id,
        );
    }

    /**
     * Atomically claim a schedule before it queues a durable run.
     *
     * @param int $id Schedule ID.
     * @return bool True when this caller owns the claim.
     */
    public static function claim( $id ) {
        global $wpdb;

        $now    = current_time( 'mysql', true );
        $locked = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_SECONDS );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}wp_agent_schedules
             SET locked_until = %s
             WHERE id = %d
               AND status = 'active'
               AND ( locked_until IS NULL OR locked_until < %s )",
            $locked,
            (int) $id,
            $now
        ) );

        return $wpdb->rows_affected > 0;
    }

    /**
     * Release a schedule claim.
     *
     * @param int $id Schedule ID.
     * @return void
     */
    public static function release_lock( $id ) {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'wp_agent_schedules',
            array( 'locked_until' => null ),
            array( 'id' => (int) $id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Sync the owning schedule, if any, after a durable run changes state.
     *
     * @param int $run_id Run ID.
     * @return bool True when a schedule was found and updated.
     */
    public static function sync_by_run( $run_id ) {
        global $wpdb;

        $run_id = (int) $run_id;
        if ( $run_id <= 0 ) {
            return false;
        }

        $schedule_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id
             FROM {$wpdb->prefix}wp_agent_schedules
             WHERE last_run_id = %d
             ORDER BY last_run DESC, id DESC
             LIMIT 1",
            $run_id
        ) );

        if ( $schedule_id <= 0 ) {
            return false;
        }

        return self::sync_run_status( $schedule_id, $run_id );
    }

    /**
     * Resolve the active Skill bound to the schedule that queued a run.
     *
     * @param int $run_id Durable run ID.
     * @return array|null
     */
    public static function skill_for_run( $run_id ) {
        global $wpdb;

        $run_id = (int) $run_id;
        if ( $run_id <= 0 ) {
            return null;
        }

        $schedule = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, created_by, skill_slug
             FROM {$wpdb->prefix}wp_agent_schedules
             WHERE last_run_id = %d
             ORDER BY last_run DESC, id DESC
             LIMIT 1",
            $run_id
        ) );
        if ( ! $schedule ) {
            return null;
        }

        $skill_slug = self::sanitize_skill_slug( $schedule->skill_slug ?? '' );
        if ( '' === $skill_slug ) {
            return null;
        }

        $skill = self::resolve_skill( (int) $schedule->created_by, $skill_slug );
        if ( ! $skill ) {
            return null;
        }

        return array(
            'schedule_id' => (int) $schedule->id,
            'user_id'     => (int) $schedule->created_by,
            'skill_slug'  => $skill_slug,
            'skill'       => $skill,
        );
    }

    /**
     * Return the normalized Skill permission policy attached to a durable run.
     *
     * @param int $run_id Durable run ID.
     * @return array
     */
    public static function skill_policy_for_run( $run_id ) {
        $bound = self::skill_for_run( $run_id );
        if ( ! is_array( $bound ) || empty( $bound['skill_slug'] ) || ! class_exists( 'WPAgent_Skills' ) ) {
            return array(
                'bound'      => false,
                'restricted' => false,
            );
        }

        $owner_id    = (int) ( $bound['user_id'] ?? 0 );
        $permissions = WPAgent_Skills::permissions_for_skill( $owner_id, $bound['skill_slug'] );
        $policy      = WPAgent_Skills::policy_from_permissions( $permissions );

        return array_merge( $policy, array(
            'bound'             => true,
            'schedule_id'       => (int) ( $bound['schedule_id'] ?? 0 ),
            'user_id'           => $owner_id,
            'skill_slug'        => sanitize_title( $bound['skill_slug'] ),
            'permissions_found' => ! empty( $permissions ),
        ) );
    }

    /**
     * Sync one schedule's status summary from its latest durable run.
     *
     * @param int $schedule_id Schedule ID.
     * @param int $run_id      Optional run ID. Defaults to schedule last_run_id.
     * @return bool True when updated.
     */
    public static function sync_run_status( $schedule_id, $run_id = 0 ) {
        global $wpdb;

        $schedule = self::get( $schedule_id );
        if ( ! $schedule ) {
            return false;
        }

        $run_id = (int) $run_id;
        if ( $run_id <= 0 ) {
            $run_id = (int) ( $schedule->last_run_id ?? 0 );
        }
        if ( $run_id <= 0 || ! class_exists( 'WPAgent_Runs' ) ) {
            return false;
        }

        $run = WPAgent_Runs::get( $run_id );
        if ( ! $run ) {
            return false;
        }

        $status  = self::status_from_run( $run );
        $summary = self::summary_from_run( $run, $status );

        $wpdb->update(
            $wpdb->prefix . 'wp_agent_schedules',
            array(
                'last_status'  => $status,
                'last_summary' => $summary,
            ),
            array( 'id' => (int) $schedule_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return $wpdb->rows_affected >= 0;
    }

    /**
     * Cron callback: run all due schedules (capped per tick).
     */
    public static function check_and_run() {
        $due = self::due();
        $count = 0;

        foreach ( $due as $schedule ) {
            if ( $count >= 5 ) {
                break;
            }
            self::run( (int) $schedule->id );
            $count++;
        }
    }

    /**
     * Sanitize an HH:MM time string, defaulting to 09:00.
     *
     * @param string|null $time Raw time value.
     * @return string
     */
    private static function sanitize_time( $time ) {
        if ( is_string( $time ) && preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $time ), $m ) ) {
            return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
        }
        return '09:00';
    }

    /**
     * Sanitize minute cadence, clamped to a practical range for WP-Cron.
     *
     * @param int|null $minutes Raw minute interval.
     * @return int
     */
    private static function sanitize_interval_minutes( $minutes ) {
        $minutes = ( null === $minutes ) ? 5 : (int) $minutes;
        return max( 1, min( 1440, $minutes ) );
    }

    private static function parse_natural_time( $text ) {
        if ( preg_match( '/\b(noon|midday)\b/i', $text ) ) {
            return '12:00';
        }
        if ( preg_match( '/\bmidnight\b/i', $text ) ) {
            return '00:00';
        }

        if ( preg_match( '/\b(?:at\s*)?([01]?\d|2[0-3])[:.]([0-5]\d)\s*(am|pm)?\b/i', $text, $m ) ) {
            $hour   = (int) $m[1];
            $minute = (int) $m[2];
            $period = strtolower( $m[3] ?? '' );
            return self::format_hour_minute( self::apply_period( $hour, $period ), $minute );
        }

        if ( preg_match( '/\b(?:at\s*)?([1-9]|1[0-2])\s*(am|pm)\b/i', $text, $m ) ) {
            return self::format_hour_minute( self::apply_period( (int) $m[1], strtolower( $m[2] ) ), 0 );
        }

        if ( preg_match( '/\bmorning\b/i', $text ) ) {
            return '08:00';
        }
        if ( preg_match( '/\bafternoon\b/i', $text ) ) {
            return '14:00';
        }
        if ( preg_match( '/\b(evening|night)\b/i', $text ) ) {
            return '18:00';
        }

        if ( preg_match( '/(凌晨|早上|上午|中午|下午|晚上|夜里)?\s*([0-2]?\d)\s*(?:点|时)(?:(半)|(?:[:：]\s*([0-5]?\d))|(?:(\d{1,2})\s*分))?/u', $text, $m ) ) {
            $period = $m[1] ?? '';
            $hour   = (int) $m[2];
            $minute = 0;
            if ( ! empty( $m[3] ) ) {
                $minute = 30;
            } elseif ( isset( $m[4] ) && '' !== $m[4] ) {
                $minute = (int) $m[4];
            } elseif ( isset( $m[5] ) && '' !== $m[5] ) {
                $minute = (int) $m[5];
            }

            if ( in_array( $period, array( '下午', '晚上', '夜里' ), true ) && $hour < 12 ) {
                $hour += 12;
            } elseif ( '中午' === $period && $hour > 0 && $hour < 11 ) {
                $hour += 12;
            } elseif ( '凌晨' === $period && 12 === $hour ) {
                $hour = 0;
            }

            return self::format_hour_minute( $hour, $minute );
        }

        if ( preg_match( '/早上|上午/u', $text ) ) {
            return '08:00';
        }
        if ( preg_match( '/中午/u', $text ) ) {
            return '12:00';
        }
        if ( preg_match( '/下午/u', $text ) ) {
            return '14:00';
        }
        if ( preg_match( '/晚上|夜里/u', $text ) ) {
            return '18:00';
        }

        return null;
    }

    private static function parse_natural_day_of_week( $text ) {
        $english_days = array(
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        );

        foreach ( $english_days as $name => $day ) {
            if ( preg_match( '/\b(?:every\s+)?' . preg_quote( $name, '/' ) . 's?\b/i', $text ) ) {
                return $day;
            }
        }

        if ( preg_match( '/(?:每)?(?:周|星期|礼拜)\s*([一二三四五六日天])/u', $text, $m ) ) {
            $map = array(
                '日' => 0,
                '天' => 0,
                '一' => 1,
                '二' => 2,
                '三' => 3,
                '四' => 4,
                '五' => 5,
                '六' => 6,
            );
            return $map[ $m[1] ] ?? null;
        }

        return null;
    }

    private static function parse_small_number( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^\d+$/', $value ) ) {
            return (int) $value;
        }

        $digits = array(
            '零' => 0,
            '一' => 1,
            '二' => 2,
            '两' => 2,
            '三' => 3,
            '四' => 4,
            '五' => 5,
            '六' => 6,
            '七' => 7,
            '八' => 8,
            '九' => 9,
        );

        if ( isset( $digits[ $value ] ) ) {
            return $digits[ $value ];
        }

        if ( '十' === $value ) {
            return 10;
        }

        if ( preg_match( '/^([一二两三四五六七八九])?十([一二三四五六七八九])?$/u', $value, $m ) ) {
            $tens = isset( $m[1] ) && '' !== $m[1] ? $digits[ $m[1] ] : 1;
            $ones = isset( $m[2] ) && '' !== $m[2] ? $digits[ $m[2] ] : 0;
            return ( $tens * 10 ) + $ones;
        }

        if ( preg_match( '/^([一二两三四五六七八九])百$/u', $value, $m ) ) {
            return $digits[ $m[1] ] * 100;
        }

        return 0;
    }

    private static function apply_period( $hour, $period ) {
        $hour = (int) $hour;
        if ( 'pm' === $period && $hour < 12 ) {
            return $hour + 12;
        }
        if ( 'am' === $period && 12 === $hour ) {
            return 0;
        }
        return $hour;
    }

    private static function format_hour_minute( $hour, $minute ) {
        $hour   = max( 0, min( 23, (int) $hour ) );
        $minute = max( 0, min( 59, (int) $minute ) );
        return sprintf( '%02d:%02d', $hour, $minute );
    }

    private static function sanitize_skill_slug( $slug ) {
        return sanitize_title( (string) $slug );
    }

    private static function resolve_skill( $user_id, $skill_slug ) {
        $skill_slug = self::sanitize_skill_slug( $skill_slug );
        if ( '' === $skill_slug || ! class_exists( 'WPAgent_Skills' ) ) {
            return null;
        }

        $skill = WPAgent_Skills::get_by_slug( (int) $user_id, $skill_slug );
        if ( ! $skill || 'active' !== ( $skill['status'] ?? '' ) ) {
            return null;
        }

        return $skill;
    }

    private static function build_scheduled_message( $schedule, $user_id ) {
        $message = '[Scheduled task #' . (int) $schedule->id . ']';
        $skill_slug = self::sanitize_skill_slug( $schedule->skill_slug ?? '' );
        if ( '' === $skill_slug ) {
            return $message . "\n" . (string) $schedule->prompt;
        }

        $skill = self::resolve_skill( $user_id, $skill_slug );
        if ( ! $skill ) {
            return new WP_Error( 'wp_agent_schedule_skill_missing', 'Bound skill is missing, archived, or unavailable: ' . $skill_slug );
        }

        $message .= "\nBound Skill: " . $skill['name'] . ' (`' . $skill['slug'] . '`, version ' . (int) $skill['version'] . ")\n\n";
        $message .= "## Bound Skill Playbook\n";
        $message .= trim( (string) $skill['body'] ) . "\n\n";
        $message .= "## Scheduled Instruction\n";
        $message .= (string) $schedule->prompt;

        return $message;
    }

    /**
     * Map durable run row state into a schedule-visible status.
     *
     * @param object $run Run row.
     * @return string
     */
    private static function status_from_run( $run ) {
        $status = sanitize_key( (string) ( $run->status ?? '' ) );
        if ( 'queued' === $status && ! empty( $run->next_attempt_at ) ) {
            $retry_ts = strtotime( (string) $run->next_attempt_at . ' UTC' );
            if ( $retry_ts && $retry_ts > time() ) {
                return 'retry_scheduled';
            }
        }

        return '' !== $status ? $status : 'unknown';
    }

    /**
     * Build a bounded schedule summary from a durable run.
     *
     * @param object $run    Run row.
     * @param string $status Schedule-visible status.
     * @return string
     */
    private static function summary_from_run( $run, $status ) {
        $run_id  = (int) ( $run->id ?? 0 );
        $attempt = (int) ( $run->attempt_count ?? 0 );
        $error   = trim( wp_strip_all_tags( (string) ( $run->error ?? '' ) ) );

        if ( '' !== $error && function_exists( 'mb_substr' ) ) {
            $error = mb_substr( $error, 0, 400 );
        } elseif ( '' !== $error ) {
            $error = substr( $error, 0, 400 );
        }

        switch ( $status ) {
            case 'done':
                return 'Run #' . $run_id . ' completed.';
            case 'error':
                return 'Run #' . $run_id . ' failed' . ( '' !== $error ? ': ' . $error : '.' );
            case 'retry_scheduled':
                $next = ! empty( $run->next_attempt_at ) ? (string) $run->next_attempt_at : 'the next retry window';
                return 'Run #' . $run_id . ' scheduled retry at ' . $next . ( $attempt > 0 ? ' after attempt ' . $attempt . '.' : '.' );
            case 'awaiting_confirmation':
                return 'Run #' . $run_id . ' is waiting for human confirmation.';
            case 'canceled':
                return 'Run #' . $run_id . ' was canceled' . ( '' !== $error ? ': ' . $error : '.' );
            case 'running':
                return 'Run #' . $run_id . ' is in progress.';
            case 'queued':
                return 'Run #' . $run_id . ' is queued for background execution.';
            default:
                return 'Run #' . $run_id . ' status: ' . $status . '.';
        }
    }
}
