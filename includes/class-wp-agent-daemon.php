<?php
/**
 * Native PHP agent daemon.
 *
 * The daemon is the long-running host process for WP Agent. WordPress remains
 * the control plane; this process owns the runtime loop and, when pcntl is
 * available, forks short-lived child agents to process queued runs in parallel.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Daemon {

    const DEFAULT_MAX_CHILDREN = 3;
    const DEFAULT_IDLE_SLEEP   = 2;
    const DEFAULT_MAX_JOBS     = 0;
    const DEFAULT_MAX_LIFETIME = 0;
    const DEFAULT_MAX_IDLE     = 0;
    const DEFAULT_MEMORY_SOFT  = 192;
    const DEFAULT_MEMORY_HARD  = 256;
    const DEFAULT_WATCHDOG_STALE = 45;
    const DEFAULT_WATCHDOG_BACKOFF = 30;
    const MAX_WATCHDOG_BACKOFF = 300;
    const DEFAULT_WATCHDOG_KILL_GRACE = 30;
    const STATE_OPTION         = 'wp_agent_daemon_state';
    const STOP_OPTION          = 'wp_agent_daemon_stop_request';
    const PID_FILE_VERSION     = 1;

    /**
     * Run the long-lived daemon loop in the current PHP CLI process.
     *
     * @param array         $args
     * @param callable|null $logger
     * @return array
     */
    public static function run( $args = array(), callable $logger = null ) {
        if ( 'cli' !== PHP_SAPI ) {
            return array( 'ok' => false, 'error' => 'agentd must run from PHP CLI.' );
        }

        @set_time_limit( 0 );

        $max_children = isset( $args['max_children'] ) ? max( 1, min( (int) $args['max_children'], 10 ) ) : self::configured_max_children();
        $idle_sleep   = isset( $args['idle_sleep'] ) ? max( 0, min( (int) $args['idle_sleep'], 30 ) ) : self::DEFAULT_IDLE_SLEEP;
        $max_seconds  = isset( $args['max_seconds'] ) ? max( 0, (int) $args['max_seconds'] ) : 0;
        $max_jobs     = isset( $args['max_jobs'] ) ? max( 0, (int) $args['max_jobs'] ) : self::DEFAULT_MAX_JOBS;
        $max_lifetime = isset( $args['max_lifetime'] ) ? max( 0, (int) $args['max_lifetime'] ) : self::DEFAULT_MAX_LIFETIME;
        $max_idle     = isset( $args['max_idle_time'] ) ? max( 0, (int) $args['max_idle_time'] ) : self::DEFAULT_MAX_IDLE;
        $memory_soft  = self::memory_limit_bytes( $args['memory_soft_limit'] ?? self::DEFAULT_MEMORY_SOFT );
        $memory_hard  = self::memory_limit_bytes( $args['memory_hard_limit'] ?? self::DEFAULT_MEMORY_HARD );
        $once         = ! empty( $args['once'] );
        $daemon_token = self::sanitize_daemon_token( $args['daemon_token'] ?? '' );
        $lifetime     = $max_lifetime > 0 ? $max_lifetime : $max_seconds;
        $deadline     = $lifetime > 0 ? time() + $lifetime : 0;
        $pcntl        = self::can_fork();
        $pid          = getmypid();
        $children     = array();
        $stop         = false;
        $ticks        = 0;
        $spawned      = 0;
        $last_schedule_check = 0;
        $processed_jobs = 0;
        $gc_runs        = 0;
        $restart_reason = '';
        $idle_since     = time();
        $memory_baseline = memory_get_usage( true );
        $limits         = array(
            'max_jobs'          => $max_jobs,
            'max_lifetime'      => $lifetime,
            'max_idle_time'     => $max_idle,
            'memory_soft_limit' => $memory_soft,
            'memory_hard_limit' => $memory_hard,
        );

        self::ensure_runtime_dir();
        @unlink( self::stop_file() );
        delete_option( self::STOP_OPTION );
        if ( '' === $daemon_token ) {
            $daemon_token = self::new_daemon_token();
        }
        self::write_pid_record( $pid, $daemon_token );

        if ( function_exists( 'pcntl_signal' ) ) {
            pcntl_signal( self::sigterm(), function() use ( &$stop ) {
                $stop = true;
            } );
            pcntl_signal( self::sigint(), function() use ( &$stop ) {
                $stop = true;
            } );
        }

        self::write_state( array(
            'status'          => 'running',
            'pid'             => $pid,
            'started_at'      => current_time( 'mysql', true ),
            'heartbeat'       => time(),
            'max_children'    => $max_children,
            'active_children' => 0,
            'pcntl'           => $pcntl,
            'daemon_token'    => $daemon_token,
            'last_event'      => 'started',
            'ticks'           => 0,
            'memory_baseline' => $memory_baseline,
        ), $processed_jobs, $restart_reason, $gc_runs, $limits );
        self::log( $logger, 'WP Agent daemon started pid=' . $pid . ' max_children=' . $max_children . ' pcntl=' . ( $pcntl ? 'yes' : 'no' ) );

        while ( ! $stop ) {
            if ( function_exists( 'pcntl_signal_dispatch' ) ) {
                pcntl_signal_dispatch();
            }

            if ( self::stop_requested( $pid, $daemon_token ) ) {
                $stop = true;
                break;
            }
            if ( $deadline && time() >= $deadline ) {
                $restart_reason = 'max_lifetime';
                break;
            }

            $ticks++;
            self::maybe_check_schedules( $last_schedule_check, $logger );

            if ( $pcntl ) {
                $processed_jobs += self::reap_children( $children, $logger );
                $claimable = WPAgent_Runs::claimable_count();
                $available_job_slots = $max_jobs > 0 ? max( 0, $max_jobs - $processed_jobs - count( $children ) ) : PHP_INT_MAX;

                while ( $claimable > 0 && count( $children ) < $max_children && $available_job_slots > 0 ) {
                    $child_pid = pcntl_fork();
                    if ( -1 === $child_pid ) {
                        self::log( $logger, 'fork failed; falling back to foreground tick' );
                        $result = WPAgent_Worker::run_once();
                        self::record_worker_error( $result );
                        if ( empty( $result['idle'] ) ) {
                            $processed_jobs++;
                        }
                        self::log( $logger, wp_json_encode( array( 'child' => 'inline', 'result' => $result ) ) );
                        break;
                    }

                    if ( 0 === $child_pid ) {
                        self::run_child_and_exit();
                    }

                    $children[ $child_pid ] = time();
                    $spawned++;
                    $claimable--;
                    $available_job_slots--;
                    self::log( $logger, 'spawned child agent pid=' . $child_pid );
                }

                $idle = empty( $children ) && 0 === $claimable;
                $idle_since = $idle ? $idle_since : time();

                self::write_state( array(
                    'status'          => $idle ? 'idle' : 'running',
                    'pid'             => $pid,
                    'heartbeat'       => time(),
                    'max_children'    => $max_children,
                    'active_children' => count( $children ),
                    'pcntl'           => true,
                    'daemon_token'    => $daemon_token,
                    'last_event'      => $spawned > 0 ? 'spawned_child_agent' : 'heartbeat',
                    'ticks'           => $ticks,
                ), $processed_jobs, $restart_reason, $gc_runs, $limits );

                if ( $once && empty( $children ) ) {
                    break;
                }
                if ( $max_jobs > 0 && $processed_jobs >= $max_jobs && empty( $children ) ) {
                    $restart_reason = 'max_jobs';
                    break;
                }
                if ( $max_idle > 0 && $idle && ( time() - $idle_since ) >= $max_idle ) {
                    $restart_reason = 'max_idle_time';
                    break;
                }
                if ( self::memory_limit_reached( $memory_soft, $memory_hard, $gc_runs, $restart_reason ) && empty( $children ) ) {
                    break;
                }
                if ( $idle && $idle_sleep > 0 ) {
                    sleep( $idle_sleep );
                } else {
                    usleep( 200000 );
                }
            } else {
                $result = WPAgent_Worker::tick( 1, 20 );
                self::record_worker_error( $result );
                if ( ! self::tick_idle( $result ) ) {
                    $processed_jobs++;
                    $idle_since = time();
                }
                $idle = self::tick_idle( $result );
                self::write_state( array(
                    'status'          => $idle ? 'idle' : 'running',
                    'pid'             => $pid,
                    'heartbeat'       => time(),
                    'max_children'    => 1,
                    'active_children' => 0,
                    'pcntl'           => false,
                    'daemon_token'    => $daemon_token,
                    'last_event'      => 'single_process_tick',
                    'ticks'           => $ticks,
                ), $processed_jobs, $restart_reason, $gc_runs, $limits );
                self::log( $logger, wp_json_encode( array( 'single_process' => true, 'result' => $result ) ) );

                if ( $once ) {
                    break;
                }
                if ( $max_jobs > 0 && $processed_jobs >= $max_jobs ) {
                    $restart_reason = 'max_jobs';
                    break;
                }
                if ( $max_idle > 0 && $idle && ( time() - $idle_since ) >= $max_idle ) {
                    $restart_reason = 'max_idle_time';
                    break;
                }
                if ( self::memory_limit_reached( $memory_soft, $memory_hard, $gc_runs, $restart_reason ) ) {
                    break;
                }
                if ( $idle && $idle_sleep > 0 ) {
                    sleep( $idle_sleep );
                }
            }
        }

        if ( $pcntl ) {
            self::terminate_children( $children, $logger );
        }

        @unlink( self::pid_file() );
        @unlink( self::stop_file() );
        delete_option( self::STOP_OPTION );
        self::write_state( array(
            'status'          => 'stopped',
            'pid'             => $pid,
            'heartbeat'       => time(),
            'max_children'    => $max_children,
            'active_children' => 0,
            'pcntl'           => $pcntl,
            'daemon_token'    => $daemon_token,
            'last_event'      => '' !== $restart_reason ? 'stopped_' . $restart_reason : 'stopped',
            'ticks'           => $ticks,
        ), $processed_jobs, $restart_reason, $gc_runs, $limits );
        self::log( $logger, 'WP Agent daemon stopped ticks=' . $ticks . ' spawned=' . $spawned );

        return array(
            'ok'              => true,
            'ticks'           => $ticks,
            'spawned'         => $spawned,
            'processed_jobs'  => $processed_jobs,
            'restart_reason'  => $restart_reason,
        );
    }

    /**
     * Start the daemon in the background if it is not already running.
     *
     * @param array $args
     * @return array|WP_Error
     */
    public static function wake( $args = array() ) {
        self::ensure_runtime_dir();
        $lock = self::acquire_wake_lock();
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        $status = self::status();
        if ( ! empty( $status['running'] ) || 'starting' === ( $status['status'] ?? '' ) ) {
            self::release_wake_lock( $lock );
            return array( 'started' => false, 'status' => $status );
        }

        @unlink( self::stop_file() );
        delete_option( self::STOP_OPTION );

        $max_children = isset( $args['max_children'] ) ? max( 1, min( (int) $args['max_children'], 10 ) ) : self::configured_max_children();
        self::set_configured_max_children( $max_children );

        $php_binary = self::php_binary();
        if ( is_wp_error( $php_binary ) ) {
            self::release_wake_lock( $lock );
            return $php_binary;
        }

        $daemon_token = self::new_daemon_token();
        $wp_load = trailingslashit( ABSPATH ) . 'wp-load.php';
        $cmd = array(
            $php_binary,
            WP_AGENT_PLUGIN_DIR . 'bin/agentd.php',
            '--wp-load=' . $wp_load,
            '--daemon-token=' . $daemon_token,
            '--max-children=' . $max_children,
            '--idle-sleep=' . ( isset( $args['idle_sleep'] ) ? max( 0, (int) $args['idle_sleep'] ) : self::DEFAULT_IDLE_SLEEP ),
        );

        if ( ! empty( $args['max_seconds'] ) ) {
            $cmd[] = '--max-seconds=' . max( 1, (int) $args['max_seconds'] );
        }
        foreach ( array( 'max_jobs', 'max_lifetime', 'max_idle_time', 'memory_soft_limit', 'memory_hard_limit' ) as $key ) {
            if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
                $cmd[] = '--' . str_replace( '_', '-', $key ) . '=' . (int) $args[ $key ];
            }
        }

        $pid = self::spawn_background( $cmd, self::log_file() );
        if ( is_wp_error( $pid ) ) {
            self::release_wake_lock( $lock );
            return $pid;
        }

        self::write_state( array(
            'status'          => 'starting',
            'pid'             => (int) $pid,
            'started_at'      => current_time( 'mysql', true ),
            'heartbeat'       => time(),
            'max_children'    => $max_children,
            'active_children' => 0,
            'pcntl'           => false,
            'daemon_token'    => $daemon_token,
            'last_event'      => 'wake_requested',
        ) );
        WPAgent::audit_log( get_current_user_id(), 'daemon_wake', array(
            'pid'          => (int) $pid,
            'max_children' => $max_children,
        ), 'admin' );

        self::release_wake_lock( $lock );
        return array( 'started' => true, 'pid' => (int) $pid, 'status' => self::status() );
    }

    /**
     * Check daemon health and recover it when safe.
     *
     * This is intentionally lightweight so WP-Cron, WP-CLI, and admin actions
     * can use the same recovery path without owning the daemon loop.
     *
     * @param array $args Optional stale/backoff/lifecycle settings.
     * @return array|WP_Error
     */
    public static function watchdog( $args = array() ) {
        self::ensure_runtime_dir();
        $lock = self::acquire_watchdog_lock();
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        $now           = time();
        $state         = self::daemon_state();
        $status        = self::status();
        $stale_seconds = self::watchdog_int_arg( $args, 'stale_seconds', self::DEFAULT_WATCHDOG_STALE, 10, 3600 );
        $kill_grace    = self::watchdog_int_arg( $args, 'kill_grace', self::DEFAULT_WATCHDOG_KILL_GRACE, 0, 3600 );
        $backoff_base  = self::watchdog_int_arg( $args, 'backoff_base', self::DEFAULT_WATCHDOG_BACKOFF, 1, 3600 );
        $backoff_max   = self::watchdog_int_arg( $args, 'backoff_max', self::MAX_WATCHDOG_BACKOFF, 1, 3600 );
        $heartbeat_age = $status['heartbeat_age'];
        $healthy       = ! empty( $status['running'] ) && null !== $heartbeat_age && (int) $heartbeat_age <= $stale_seconds;

        if ( $healthy ) {
            self::write_watchdog_state( array(
                'last_watchdog_action'          => 'healthy',
                'last_watchdog_reason'          => '',
                'watchdog_backoff_until'        => 0,
                'watchdog_consecutive_failures' => 0,
                'watchdog_fallback_recommended' => false,
                'watchdog_last_error'           => '',
            ) );
            self::release_wake_lock( $lock );
            return array(
                'ok'                   => true,
                'healthy'              => true,
                'started'              => false,
                'action'               => 'healthy',
                'reason'               => '',
                'status'               => self::status(),
                'fallback_recommended' => false,
            );
        }

        $backoff_until = max( 0, (int) ( $state['watchdog_backoff_until'] ?? 0 ) );
        if ( $backoff_until > $now ) {
            self::write_watchdog_state( array(
                'last_watchdog_action'          => 'backoff',
                'last_watchdog_reason'          => 'restart_backoff',
                'watchdog_fallback_recommended' => true,
            ) );
            self::release_wake_lock( $lock );
            return array(
                'ok'                   => true,
                'healthy'              => false,
                'started'              => false,
                'action'               => 'backoff',
                'reason'               => 'restart_backoff',
                'backoff_until'        => $backoff_until,
                'backoff_remaining'    => max( 0, $backoff_until - $now ),
                'status'               => $status,
                'fallback_recommended' => true,
            );
        }

        $reason = self::watchdog_reason( $status, $stale_seconds );

        if ( ! empty( $status['running'] ) ) {
            self::request_stale_stop( $status, $reason );
            $force_killed = false;
            if ( null !== $heartbeat_age && (int) $heartbeat_age >= ( $stale_seconds + $kill_grace ) ) {
                $force_killed = self::force_stop_pid( (int) $status['pid'] );
                $status       = self::status();
            }

            if ( ! empty( $status['running'] ) ) {
                self::write_watchdog_state( array(
                    'last_watchdog_action'          => $force_killed ? 'stale_force_stop_failed' : 'stale_stop_requested',
                    'last_watchdog_reason'          => $reason,
                    'watchdog_fallback_recommended' => true,
                ) );
                self::release_wake_lock( $lock );
                return array(
                    'ok'                   => true,
                    'healthy'              => false,
                    'started'              => false,
                    'action'               => $force_killed ? 'stale_force_stop_failed' : 'stale_stop_requested',
                    'reason'               => $reason,
                    'status'               => $status,
                    'fallback_recommended' => true,
                );
            }

            $reason = $force_killed ? 'heartbeat_stale_force_stopped' : 'heartbeat_stale_stopped';
        }

        $result = self::wake( self::watchdog_wake_args( $args ) );
        if ( is_wp_error( $result ) ) {
            $failures      = max( 0, (int) ( $state['watchdog_consecutive_failures'] ?? 0 ) ) + 1;
            $backoff_until = $now + self::watchdog_backoff_delay( $failures, $backoff_base, $backoff_max );
            self::write_watchdog_state( array(
                'last_watchdog_action'          => 'wake_failed',
                'last_watchdog_reason'          => $reason,
                'watchdog_consecutive_failures' => $failures,
                'watchdog_backoff_until'        => $backoff_until,
                'watchdog_fallback_recommended' => true,
                'watchdog_last_error'           => $result->get_error_message(),
            ) );
            self::release_wake_lock( $lock );
            return $result;
        }

        $started       = ! empty( $result['started'] );
        $failures      = max( 0, (int) ( $state['watchdog_consecutive_failures'] ?? 0 ) );
        $restart_count = max( 0, (int) ( $state['watchdog_restart_count'] ?? 0 ) );
        $backoff_until = 0;
        if ( $started ) {
            $failures++;
            $restart_count++;
            $backoff_until = $now + self::watchdog_backoff_delay( $failures, $backoff_base, $backoff_max );
        }

        self::write_watchdog_state( array(
            'last_watchdog_action'          => $started ? 'restart_requested' : 'already_running',
            'last_watchdog_reason'          => $reason,
            'watchdog_restart_count'        => $restart_count,
            'watchdog_consecutive_failures' => $failures,
            'watchdog_backoff_until'        => $backoff_until,
            'watchdog_fallback_recommended' => false,
            'watchdog_last_error'           => '',
        ) );
        self::release_wake_lock( $lock );

        return array(
            'ok'                   => true,
            'healthy'              => false,
            'started'              => $started,
            'action'               => $started ? 'restart_requested' : 'already_running',
            'reason'               => $reason,
            'status'               => self::status(),
            'fallback_recommended' => false,
        );
    }

    /**
     * Request daemon shutdown.
     *
     * @return array|WP_Error
     */
    public static function request_stop() {
        self::ensure_runtime_dir();
        $status    = self::status();
        $stop_file = self::stop_file();
        if ( empty( $status['running'] ) && 'starting' !== (string) ( $status['status'] ?? '' ) ) {
            @unlink( $stop_file );
            delete_option( self::STOP_OPTION );
            self::write_state( array(
                'status'          => 'stopped',
                'pid'             => 0,
                'active_children' => 0,
                'last_event'      => 'stopped',
            ) );
            WPAgent::audit_log( get_current_user_id(), 'daemon_stop', array(
                'pid'     => 0,
                'already' => 'stopped',
            ), 'admin' );
            return self::status();
        }

        $stop_dir  = dirname( $stop_file );
        if ( ! is_dir( $stop_dir ) && ! wp_mkdir_p( $stop_dir ) ) {
            return new WP_Error( 'wp_agent_daemon_stop_dir', 'Could not create the agent daemon stop directory.' );
        }
        @chmod( $stop_dir, 0700 );

        $written = file_put_contents( $stop_file, wp_json_encode( array(
            'time'       => time(),
            'site_scope' => self::site_scope(),
            'pid'        => (int) ( $status['pid'] ?? 0 ),
        ) ), LOCK_EX );
        if ( false === $written ) {
            return new WP_Error( 'wp_agent_daemon_stop_file', 'Could not write the agent daemon stop file.' );
        }
        @chmod( $stop_file, 0600 );
        clearstatcache( true, $stop_file );

        $state = self::daemon_state();
        update_option( self::STOP_OPTION, array(
            'time'  => time(),
            'pid'   => (int) ( $status['pid'] ?? 0 ),
            'token' => self::sanitize_daemon_token( $state['daemon_token'] ?? '' ),
        ), false );
        self::write_state( array( 'last_event' => 'stop_requested' ) );

        if ( ! empty( $status['pid'] ) && function_exists( 'posix_kill' ) ) {
            @posix_kill( (int) $status['pid'], self::sigterm() );
        }
        WPAgent::audit_log( get_current_user_id(), 'daemon_stop', array(
            'pid' => (int) ( $status['pid'] ?? 0 ),
        ), 'admin' );
        return self::status();
    }

    /**
     * Current daemon status.
     *
     * @return array
     */
    public static function status() {
        $state = self::daemon_state();

        $heartbeat = isset( $state['heartbeat'] ) ? (int) $state['heartbeat'] : 0;
        $pid_record = self::read_pid_record();
        $pid = (int) ( $pid_record['pid'] ?? 0 );
        $daemon_token = self::sanitize_daemon_token( $state['daemon_token'] ?? '' );
        $pid_token = self::sanitize_daemon_token( $pid_record['daemon_token'] ?? '' );
        if ( $pid <= 0 && ! empty( $state['pid'] ) ) {
            $pid = (int) $state['pid'];
            $pid_token = $daemon_token;
        } elseif ( '' === $pid_token && '' !== $daemon_token ) {
            $pid_token = $daemon_token;
        }
        $token_matches = '' === $daemon_token || hash_equals( $daemon_token, $pid_token );
        $heartbeat_fresh = $heartbeat > 0 && ( time() - $heartbeat ) <= self::DEFAULT_WATCHDOG_STALE;
        $state_status = (string) ( $state['status'] ?? '' );
        $state_live = in_array( $state_status, array( 'running', 'idle' ), true );
        $pid_verified = $pid > 0 && $token_matches && self::pid_running( $pid, $pid_token );
        // WP-CLI can run in a different PID namespace from the resident daemon
        // container. In that case /proc/posix checks cannot see the daemon PID,
        // but a fresh heartbeat written through WordPress storage still proves
        // the loop is alive.
        $running = $pid_verified || ( $pid > 0 && $token_matches && $heartbeat_fresh && $state_live );
        $stop_pending = self::stop_request_pending();
        $starting = ! $running
            && ! $stop_pending
            && 'stop_requested' !== ( $state['last_event'] ?? '' )
            && 'starting' === ( $state['status'] ?? '' )
            && $heartbeat > 0
            && time() - $heartbeat < 15;
        if ( ! $running && ! $starting && $stop_pending && ! is_file( self::pid_file() ) ) {
            @unlink( self::stop_file() );
            delete_option( self::STOP_OPTION );
            $stop_pending = false;
            $state['last_event'] = 'stopped';
            $state['status']     = 'stopped';
            $state['pid']        = 0;
            update_option( self::STATE_OPTION, $state, false );
        }
        if ( $pid > 0 && ! $running && ! $starting && ! $heartbeat_fresh ) {
            if ( is_file( self::pid_file() ) ) {
                @unlink( self::pid_file() );
            }
            if ( is_file( self::stop_file() ) ) {
                @unlink( self::stop_file() );
            }
            delete_option( self::STOP_OPTION );
        }
        if ( $pid_verified ) {
            $liveness_source = 'pid';
            $liveness_note   = 'Local daemon PID and token verified.';
        } elseif ( $running ) {
            $liveness_source = 'heartbeat';
            $liveness_note   = 'Fresh daemon heartbeat and matching token; PID may be hidden by a container namespace.';
        } elseif ( $starting ) {
            $liveness_source = 'starting';
            $liveness_note   = 'Daemon wake was requested recently; waiting for a live heartbeat.';
        } elseif ( $heartbeat > 0 && ! $heartbeat_fresh ) {
            $liveness_source = 'stale_heartbeat';
            $liveness_note   = 'Daemon heartbeat is stale; the daemon is treated as stopped.';
        } else {
            $liveness_source = 'none';
            $liveness_note   = 'No daemon heartbeat is available.';
        }
        $configured_max_children = self::configured_max_children();
        $runtime_max_children    = (int) ( $state['max_children'] ?? $configured_max_children );

        return array(
            'running'                 => $running,
            'pid'                     => $running ? $pid : 0,
            'pid_verified'            => $pid_verified,
            'liveness_source'         => $liveness_source,
            'liveness_note'           => $liveness_note,
            'last_pid'                => (int) ( $state['pid'] ?? 0 ),
            'status'                  => $running ? ( $state['status'] ?? 'running' ) : ( $starting ? 'starting' : 'stopped' ),
            'heartbeat'               => $heartbeat,
            'heartbeat_age'           => $heartbeat > 0 ? max( 0, time() - $heartbeat ) : null,
            'started_at'              => $state['started_at'] ?? '',
            'max_children'            => ( $running || $starting ) ? $runtime_max_children : $configured_max_children,
            'configured_max_children' => $configured_max_children,
            'active_children'         => (int) ( $state['active_children'] ?? 0 ),
            'pcntl'                   => ! empty( $state['pcntl'] ),
            'can_fork'                => ( $running || $starting ) ? ! empty( $state['pcntl'] ) : self::can_fork(),
            'last_event'              => $state['last_event'] ?? '',
            'ticks'                   => (int) ( $state['ticks'] ?? 0 ),
            'processed_jobs'          => (int) ( $state['processed_jobs'] ?? 0 ),
            'restart_reason'          => $state['restart_reason'] ?? '',
            'gc_runs'                 => (int) ( $state['gc_runs'] ?? 0 ),
            'memory_baseline'         => (int) ( $state['memory_baseline'] ?? 0 ),
            'memory_usage'            => (int) ( $state['memory_usage'] ?? 0 ),
            'memory_peak'             => (int) ( $state['memory_peak'] ?? 0 ),
            'memory_delta'            => (int) ( $state['memory_delta'] ?? 0 ),
            'memory_per_job_delta'    => (int) ( $state['memory_per_job_delta'] ?? 0 ),
            'max_jobs'                => (int) ( $state['max_jobs'] ?? 0 ),
            'max_lifetime'            => (int) ( $state['max_lifetime'] ?? 0 ),
            'max_idle_time'           => (int) ( $state['max_idle_time'] ?? 0 ),
            'memory_soft_limit'       => (int) ( $state['memory_soft_limit'] ?? 0 ),
            'memory_hard_limit'       => (int) ( $state['memory_hard_limit'] ?? 0 ),
            'last_watchdog_check'     => (int) ( $state['last_watchdog_check'] ?? 0 ),
            'last_watchdog_action'    => $state['last_watchdog_action'] ?? '',
            'last_watchdog_reason'    => $state['last_watchdog_reason'] ?? '',
            'last_error'              => $state['last_error'] ?? '',
            'last_error_at'           => $state['last_error_at'] ?? '',
            'watchdog_restart_count'  => (int) ( $state['watchdog_restart_count'] ?? 0 ),
            'watchdog_backoff_until'  => (int) ( $state['watchdog_backoff_until'] ?? 0 ),
            'watchdog_backoff_remaining' => isset( $state['watchdog_backoff_until'] ) ? max( 0, (int) $state['watchdog_backoff_until'] - time() ) : 0,
            'watchdog_consecutive_failures' => (int) ( $state['watchdog_consecutive_failures'] ?? 0 ),
            'watchdog_fallback_recommended' => ! empty( $state['watchdog_fallback_recommended'] ),
            'watchdog_last_error'     => $state['watchdog_last_error'] ?? '',
            'site_scope'              => self::site_scope(),
            'pid_file'                => self::pid_file(),
            'log_file'                => self::log_file(),
        );
    }

    public static function configured_max_children() {
        return max( 1, min( (int) get_option( 'wp_agent_daemon_max_children', self::DEFAULT_MAX_CHILDREN ), 10 ) );
    }

    public static function set_configured_max_children( $value ) {
        update_option( 'wp_agent_daemon_max_children', max( 1, min( (int) $value, 10 ) ) );
    }

    public static function runtime_dir() {
        return trailingslashit( WPAgent_Sandbox::runtime_area_dir( 'runtime' ) ) . self::site_scope();
    }

    public static function pid_file() {
        return self::runtime_dir() . '/agentd.pid';
    }

    public static function stop_file() {
        return self::runtime_dir() . '/agentd.stop';
    }

    public static function log_file() {
        return self::runtime_dir() . '/agentd.log';
    }

    public static function lock_file() {
        return self::runtime_dir() . '/agentd.lock';
    }

    public static function watchdog_lock_file() {
        return self::runtime_dir() . '/agentd.watchdog.lock';
    }

    public static function can_fork() {
        return 'cli' === PHP_SAPI && function_exists( 'pcntl_fork' ) && function_exists( 'pcntl_waitpid' );
    }

    private static function daemon_state() {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( self::STATE_OPTION, 'options' );
        }
        $state = get_option( self::STATE_OPTION, array() );
        return is_array( $state ) ? $state : array();
    }

    private static function stop_requested( $pid, $daemon_token ) {
        clearstatcache( true, self::stop_file() );
        if ( file_exists( self::stop_file() ) ) {
            return true;
        }

        $request = self::stop_request();
        if ( ! is_array( $request ) || empty( $request['time'] ) ) {
            return false;
        }

        $requested_pid   = (int) ( $request['pid'] ?? 0 );
        $requested_token = self::sanitize_daemon_token( $request['token'] ?? '' );
        $daemon_token    = self::sanitize_daemon_token( $daemon_token );

        if ( '' !== $requested_token && '' !== $daemon_token ) {
            return hash_equals( $requested_token, $daemon_token );
        }

        return $requested_pid > 0 && $requested_pid === (int) $pid;
    }

    private static function stop_request_pending() {
        clearstatcache( true, self::stop_file() );
        if ( file_exists( self::stop_file() ) ) {
            return true;
        }

        $request = self::stop_request();
        return is_array( $request ) && ! empty( $request['time'] );
    }

    private static function stop_request() {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( self::STOP_OPTION, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }

        return get_option( self::STOP_OPTION, array() );
    }

    private static function watchdog_int_arg( $args, $key, $default, $min, $max ) {
        $value = isset( $args[ $key ] ) ? (int) $args[ $key ] : (int) $default;
        return max( (int) $min, min( $value, (int) $max ) );
    }

    private static function watchdog_wake_args( $args ) {
        $wake_args = array();
        foreach ( array( 'max_children', 'idle_sleep', 'max_seconds', 'max_jobs', 'max_lifetime', 'max_idle_time', 'memory_soft_limit', 'memory_hard_limit' ) as $key ) {
            if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
                $wake_args[ $key ] = (int) $args[ $key ];
            }
        }
        return $wake_args;
    }

    private static function watchdog_reason( $status, $stale_seconds ) {
        if ( ! empty( $status['running'] ) ) {
            if ( null === $status['heartbeat_age'] ) {
                return 'heartbeat_missing';
            }
            if ( (int) $status['heartbeat_age'] > (int) $stale_seconds ) {
                return 'heartbeat_stale';
            }
            return 'unhealthy_running';
        }

        if ( 'starting' === ( $status['status'] ?? '' ) ) {
            return 'starting';
        }

        return 'stopped';
    }

    private static function watchdog_backoff_delay( $failures, $base, $max ) {
        $failures = max( 1, min( (int) $failures, 10 ) );
        $delay    = (int) $base * ( 2 ** ( $failures - 1 ) );
        return max( 1, min( $delay, (int) $max ) );
    }

    private static function write_watchdog_state( $fields ) {
        $fields['last_watchdog_check'] = time();
        self::write_state( $fields );
    }

    private static function request_stale_stop( $status, $reason ) {
        file_put_contents( self::stop_file(), wp_json_encode( array(
            'time'       => time(),
            'site_scope' => self::site_scope(),
            'pid'        => (int) ( $status['pid'] ?? 0 ),
            'reason'     => (string) $reason,
            'source'     => 'watchdog',
        ) ) );
        @chmod( self::stop_file(), 0600 );
        if ( ! empty( $status['pid'] ) && function_exists( 'posix_kill' ) ) {
            @posix_kill( (int) $status['pid'], self::sigterm() );
        }
    }

    private static function force_stop_pid( $pid ) {
        if ( $pid <= 0 || ! function_exists( 'posix_kill' ) ) {
            return false;
        }

        $pid_record   = self::read_pid_record();
        $daemon_token = self::sanitize_daemon_token( $pid_record['daemon_token'] ?? '' );
        if ( ! self::pid_running( $pid, $daemon_token ) ) {
            return true;
        }

        @posix_kill( $pid, 9 );
        usleep( 200000 );
        if ( self::pid_running( $pid, $daemon_token ) ) {
            return false;
        }

        @unlink( self::pid_file() );
        return true;
    }

    private static function php_binary() {
        $candidates = array();
        if ( defined( 'PHP_BINARY' ) && '' !== PHP_BINARY ) {
            $candidates[] = PHP_BINARY;
        }
        if ( defined( 'PHP_BINDIR' ) && '' !== PHP_BINDIR ) {
            $candidates[] = trailingslashit( PHP_BINDIR ) . 'php';
        }
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/bin/php';

        foreach ( array_unique( $candidates ) as $candidate ) {
            if ( is_file( $candidate ) && is_executable( $candidate ) && self::binary_is_cli_php( $candidate ) ) {
                return $candidate;
            }
        }

        return new WP_Error( 'wp_agent_daemon_php_binary', 'Could not locate an executable PHP CLI binary for agentd.' );
    }

    private static function binary_is_cli_php( $binary ) {
        if ( ! function_exists( 'proc_open' ) ) {
            return 'cli' === PHP_SAPI && defined( 'PHP_BINARY' ) && PHP_BINARY === $binary;
        }

        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );
        $process = @proc_open( array( $binary, '-r', 'echo PHP_SAPI;' ), $descriptors, $pipes, null, null ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Verifies the selected daemon binary is PHP CLI.
        if ( ! is_resource( $process ) ) {
            return false;
        }

        fclose( $pipes[0] );
        $out = stream_get_contents( $pipes[1] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exit = proc_close( $process );

        return 0 === (int) $exit && 'cli' === trim( (string) $out );
    }

    private static function site_scope() {
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        return substr( hash( 'sha256', home_url() . '|' . ABSPATH . '|' . $blog_id ), 0, 16 );
    }

    private static function new_daemon_token() {
        try {
            return bin2hex( random_bytes( 16 ) );
        } catch ( \Exception $e ) {
            return substr( hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) ), 0, 32 );
        }
    }

    private static function sanitize_daemon_token( $token ) {
        $token = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $token ) );
        return strlen( $token ) >= 16 && strlen( $token ) <= 64 ? $token : '';
    }

    private static function run_child_and_exit() {
        if ( function_exists( 'pcntl_signal' ) ) {
            pcntl_signal( self::sigterm(), self::sig_dfl() );
            pcntl_signal( self::sigint(), self::sig_dfl() );
        }
        self::reconnect_db();
        $result = WPAgent_Worker::run_once();
        self::record_worker_error( $result );
        if ( class_exists( 'WPAgent_Run_Events' ) && empty( $result['idle'] ) && ! empty( $result['run_id'] ) ) {
            WPAgent_Run_Events::add(
                (int) $result['run_id'],
                self::run_user_id( (int) $result['run_id'] ),
                'subagent_exit',
                'Child agent process finished.',
                array( 'pid' => getmypid(), 'result' => $result )
            );
        }
        echo wp_json_encode( array( 'child_pid' => getmypid(), 'result' => $result ) ) . "\n";
        exit( 0 );
    }

    private static function run_user_id( $run_id ) {
        $run = WPAgent_Runs::get( $run_id );
        return $run ? (int) $run->user_id : 0;
    }

    private static function reap_children( &$children, callable $logger = null, $blocking = false ) {
        if ( ! function_exists( 'pcntl_waitpid' ) ) {
            return 0;
        }

        $reaped = 0;
        do {
            $status = 0;
            $flags  = $blocking ? 0 : self::wnohang();
            $pid    = pcntl_waitpid( -1, $status, $flags );
            if ( $pid > 0 ) {
                unset( $children[ $pid ] );
                $reaped++;
                self::log( $logger, 'reaped child agent pid=' . $pid . ' status=' . $status );
            }
        } while ( $pid > 0 && ( $blocking || ! empty( $children ) ) );

        return $reaped;
    }

    private static function terminate_children( &$children, callable $logger = null ) {
        if ( empty( $children ) || ! function_exists( 'pcntl_waitpid' ) ) {
            return;
        }

        foreach ( array_keys( $children ) as $child_pid ) {
            if ( function_exists( 'posix_kill' ) ) {
                @posix_kill( $child_pid, self::sigterm() );
            }
        }

        $deadline = microtime( true ) + 5;
        while ( ! empty( $children ) && microtime( true ) < $deadline ) {
            self::reap_children( $children, $logger );
            if ( ! empty( $children ) ) {
                usleep( 100000 );
            }
        }

        if ( ! empty( $children ) && function_exists( 'posix_kill' ) ) {
            foreach ( array_keys( $children ) as $child_pid ) {
                @posix_kill( $child_pid, 9 );
            }
            self::reap_children( $children, $logger, true );
        }
    }

    private static function tick_idle( $result ) {
        return ! empty( $result['results'] )
            && count( $result['results'] ) === 1
            && ! empty( $result['results'][0]['idle'] );
    }

    /**
     * Persist the latest worker failure for lightweight daemon diagnostics.
     *
     * @param array $result Worker result or tick result.
     * @return void
     */
    private static function record_worker_error( $result ) {
        $error = self::worker_error_message( $result );
        if ( '' === $error ) {
            return;
        }

        $state = self::daemon_state();
        $state['last_error']    = self::error_summary( $error );
        $state['last_error_at'] = current_time( 'mysql', true );
        update_option( self::STATE_OPTION, $state, false );
    }

    /**
     * Find an error message in a worker/tick result payload.
     *
     * @param array $result Worker result or tick result.
     * @return string
     */
    private static function worker_error_message( $result ) {
        if ( ! is_array( $result ) ) {
            return '';
        }

        if ( ! empty( $result['error'] ) ) {
            return (string) $result['error'];
        }

        if ( ! empty( $result['results'] ) && is_array( $result['results'] ) ) {
            foreach ( $result['results'] as $entry ) {
                $error = self::worker_error_message( $entry );
                if ( '' !== $error ) {
                    return $error;
                }
            }
        }

        return '';
    }

    /**
     * Prepare a daemon error for operator-facing status output.
     *
     * @param string $message Raw error message.
     * @return string
     */
    private static function error_summary( $message ) {
        $message = wp_strip_all_tags( (string) $message );
        $message = preg_replace( '/\s+/', ' ', $message );
        $message = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $message );
        $message = preg_replace( '/\b(api[_ -]?key|apikey|token|secret|authorization|password)\b\s*[:=]\s*[^,\s;]+/i', '$1=[redacted]', $message );
        $message = preg_replace( '/\bsk-[A-Za-z0-9_-]{12,}\b/', '[redacted-key]', $message );
        $message = trim( (string) $message );

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            return mb_strlen( $message ) > 300 ? rtrim( mb_substr( $message, 0, 297 ) ) . '...' : $message;
        }

        return strlen( $message ) > 300 ? rtrim( substr( $message, 0, 297 ) ) . '...' : $message;
    }

    private static function memory_limit_bytes( $value ) {
        $value = (int) $value;
        if ( $value <= 0 ) {
            return 0;
        }
        return $value * 1024 * 1024;
    }

    private static function memory_limit_reached( $soft_limit, $hard_limit, &$gc_runs, &$restart_reason ) {
        $usage = memory_get_usage( true );
        if ( $soft_limit > 0 && $usage >= $soft_limit && function_exists( 'gc_collect_cycles' ) ) {
            gc_collect_cycles();
            $gc_runs++;
            $usage = memory_get_usage( true );
        }

        if ( $hard_limit > 0 && $usage >= $hard_limit ) {
            $restart_reason = 'memory_hard_limit';
            return true;
        }

        return false;
    }

    private static function maybe_check_schedules( &$last_schedule_check, callable $logger = null ) {
        if ( ! class_exists( 'WPAgent_Schedules' ) ) {
            return;
        }

        $now = time();
        if ( $last_schedule_check > 0 && ( $now - $last_schedule_check ) < 30 ) {
            return;
        }

        $last_schedule_check = $now;
        $due_count = count( WPAgent_Schedules::due() );
        if ( $due_count <= 0 ) {
            return;
        }

        WPAgent_Schedules::check_and_run();
        self::log( $logger, 'checked schedules due=' . $due_count );
    }

    private static function ensure_runtime_dir() {
        $dir = self::runtime_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        @chmod( WPAgent_Sandbox::runtime_root(), 0700 );
        @chmod( WPAgent_Sandbox::site_runtime_root(), 0700 );
        @chmod( WPAgent_Sandbox::runtime_area_dir( 'runtime' ), 0700 );
        @chmod( $dir, 0700 );
    }

    private static function write_pid_record( $pid, $daemon_token ) {
        $record = array(
            'version'      => self::PID_FILE_VERSION,
            'pid'          => (int) $pid,
            'daemon_token' => self::sanitize_daemon_token( $daemon_token ),
            'site_scope'   => self::site_scope(),
            'written_at'   => time(),
        );
        file_put_contents( self::pid_file(), wp_json_encode( $record ) );
        @chmod( self::pid_file(), 0600 );
    }

    private static function read_pid_record() {
        $file = self::pid_file();
        clearstatcache( true, $file );
        if ( ! is_file( $file ) ) {
            return array();
        }

        $raw = trim( (string) file_get_contents( $file ) );
        if ( '' === $raw ) {
            return array();
        }

        if ( preg_match( '/^\d+$/', $raw ) ) {
            return array(
                'pid'          => max( 0, (int) $raw ),
                'daemon_token' => '',
                'site_scope'   => '',
            );
        }

        $record = json_decode( $raw, true );
        if ( ! is_array( $record ) ) {
            return array();
        }

        $record['pid'] = max( 0, (int) ( $record['pid'] ?? 0 ) );
        $record['daemon_token'] = self::sanitize_daemon_token( $record['daemon_token'] ?? '' );
        $record['site_scope'] = sanitize_key( $record['site_scope'] ?? '' );

        if ( self::site_scope() !== $record['site_scope'] ) {
            return array();
        }

        return $record;
    }

    private static function pid_running( $pid, $daemon_token = '' ) {
        $pid = (int) $pid;
        if ( $pid <= 0 ) {
            return false;
        }
        if ( function_exists( 'posix_kill' ) ) {
            if ( ! @posix_kill( $pid, 0 ) ) {
                return false;
            }
        } else {
            return false;
        }

        return self::pid_cmdline_matches( $pid, $daemon_token );
    }

    private static function pid_cmdline_matches( $pid, $daemon_token = '' ) {
        $cmdline = '/proc/' . (int) $pid . '/cmdline';
        if ( ! is_readable( $cmdline ) ) {
            return true;
        }

        $cmd = str_replace( "\0", ' ', (string) file_get_contents( $cmdline ) );
        if ( false === strpos( $cmd, 'bin/agentd.php' ) && false === strpos( $cmd, 'agentd.php' ) ) {
            return false;
        }

        $daemon_token = self::sanitize_daemon_token( $daemon_token );
        if ( '' !== $daemon_token && false === strpos( $cmd, '--daemon-token=' . $daemon_token ) ) {
            return false;
        }

        return true;
    }

    private static function acquire_wake_lock() {
        $handle = @fopen( self::lock_file(), 'c' );
        if ( ! $handle ) {
            return new WP_Error( 'wp_agent_daemon_lock', 'Could not open the agent daemon wake lock.' );
        }
        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            return new WP_Error( 'wp_agent_daemon_lock_busy', 'Another request is already waking the agent daemon.' );
        }
        @chmod( self::lock_file(), 0600 );
        return $handle;
    }

    private static function acquire_watchdog_lock() {
        $handle = @fopen( self::watchdog_lock_file(), 'c' );
        if ( ! $handle ) {
            return new WP_Error( 'wp_agent_watchdog_lock', 'Could not open the agent daemon watchdog lock.' );
        }
        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            return new WP_Error( 'wp_agent_watchdog_lock_busy', 'Another request is already checking the agent daemon.' );
        }
        @chmod( self::watchdog_lock_file(), 0600 );
        return $handle;
    }

    private static function release_wake_lock( $handle ) {
        if ( is_resource( $handle ) ) {
            flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    private static function spawn_background( $cmd, $log_file ) {
        if ( ! function_exists( 'proc_open' ) ) {
            return new WP_Error( 'wp_agent_daemon_proc_open', 'proc_open() is required to wake the agent daemon.' );
        }

        $shell = '/bin/sh';
        if ( ! is_executable( $shell ) ) {
            return new WP_Error( 'wp_agent_daemon_shell', 'A POSIX shell is required to detach the agent daemon.' );
        }

        $escaped = array_map( 'escapeshellarg', $cmd );
        $command = 'nohup ' . implode( ' ', $escaped ) . ' >> ' . escapeshellarg( $log_file ) . ' 2>&1 < /dev/null & echo $!';
        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );
        $process = proc_open( array( $shell, '-c', $command ), $descriptors, $pipes, null, null ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Starts the native PHP daemon with the WordPress runtime environment.
        if ( ! is_resource( $process ) ) {
            return new WP_Error( 'wp_agent_daemon_start', 'Could not start the agent daemon.' );
        }

        fclose( $pipes[0] );
        $out = stream_get_contents( $pipes[1] );
        $err = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exit = proc_close( $process );
        if ( 0 !== (int) $exit ) {
            return new WP_Error( 'wp_agent_daemon_start', trim( $err ) ? trim( $err ) : 'Could not start the agent daemon.' );
        }

        $pid = (int) trim( $out );
        if ( $pid <= 0 ) {
            return new WP_Error( 'wp_agent_daemon_pid', 'The agent daemon did not report a process ID.' );
        }

        return $pid;
    }

    private static function reconnect_db() {
        global $wpdb;
        if ( $wpdb && method_exists( $wpdb, 'close' ) ) {
            $wpdb->close();
        }
        if ( $wpdb && method_exists( $wpdb, 'db_connect' ) ) {
            $wpdb->db_connect( false );
        }
    }

    private static function write_state( $state, $processed_jobs = null, $restart_reason = null, $gc_runs = null, $limits = array() ) {
        $current = self::daemon_state();
        if ( null !== $processed_jobs ) {
            $state['processed_jobs'] = (int) $processed_jobs;
        }
        if ( null !== $restart_reason ) {
            $state['restart_reason'] = (string) $restart_reason;
        }
        if ( null !== $gc_runs ) {
            $state['gc_runs'] = (int) $gc_runs;
        }
        if ( is_array( $limits ) ) {
            foreach ( array( 'max_jobs', 'max_lifetime', 'max_idle_time', 'memory_soft_limit', 'memory_hard_limit' ) as $key ) {
                if ( array_key_exists( $key, $limits ) ) {
                    $state[ $key ] = (int) $limits[ $key ];
                }
            }
        }
        $state['memory_usage'] = memory_get_usage( true );
        $state['memory_peak']  = memory_get_peak_usage( true );
        $baseline = (int) ( $state['memory_baseline'] ?? ( $current['memory_baseline'] ?? 0 ) );
        if ( $baseline <= 0 ) {
            $baseline = $state['memory_usage'];
        }
        $jobs = (int) ( $state['processed_jobs'] ?? ( $current['processed_jobs'] ?? 0 ) );
        $state['memory_baseline']      = $baseline;
        $state['memory_delta']         = max( 0, $state['memory_usage'] - $baseline );
        $state['memory_per_job_delta'] = $jobs > 0 ? (int) round( $state['memory_delta'] / $jobs ) : 0;
        $state['updated_at'] = current_time( 'mysql', true );
        update_option( self::STATE_OPTION, array_merge( $current, $state ), false );
    }

    private static function log( callable $logger = null, $line = '' ) {
        if ( $logger ) {
            $logger( $line );
        }
    }

    private static function sigterm() {
        return defined( 'SIGTERM' ) ? SIGTERM : 15;
    }

    private static function sigint() {
        return defined( 'SIGINT' ) ? SIGINT : 2;
    }

    private static function wnohang() {
        return defined( 'WNOHANG' ) ? WNOHANG : 1;
    }

    private static function sig_dfl() {
        return defined( 'SIG_DFL' ) ? SIG_DFL : 0;
    }
}
