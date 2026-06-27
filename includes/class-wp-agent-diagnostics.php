<?php
/**
 * Lightweight runtime diagnostics for the native PHP agent loop.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Diagnostics {

    /**
     * Collect read-only runtime diagnostics.
     *
     * @param array $args Optional daemon status override.
     * @return array
     */
    public static function runtime( $args = array() ) {
        $started = microtime( true );
        $daemon = isset( $args['daemon'] ) && is_array( $args['daemon'] )
            ? $args['daemon']
            : WPAgent_Daemon::status();

        $diagnostics = array(
            'generated_at' => current_time( 'mysql', true ),
            'ai'           => self::ai_status(),
            'php'          => self::php_status(),
            'opcache'      => self::opcache_status(),
            'security'     => self::security_status(),
            'storage'      => self::storage_status(),
            'skills'       => self::skills_status(),
            'queue'        => self::queue_status(),
            'schedules'    => self::schedule_status(),
            'database'     => self::database_status(),
            'daemon'       => self::daemon_summary( $daemon ),
        );
        $diagnostics['performance'] = self::performance_status( $started );

        return $diagnostics;
    }

    /**
     * Format a byte count for UI and JSON consumers.
     *
     * @param int $bytes Byte count, or -1 for unlimited.
     * @return string
     */
    public static function format_bytes( $bytes ) {
        $bytes = (int) $bytes;
        if ( $bytes < 0 ) {
            return __( 'Unlimited', 'wp-agent' );
        }
        if ( function_exists( 'size_format' ) ) {
            return size_format( $bytes );
        }
        return number_format( $bytes ) . ' B';
    }

    /**
     * AI provider readiness diagnostics.
     *
     * @return array
     */
    private static function ai_status() {
        return class_exists( 'WPAgent' ) ? WPAgent::ai_provider_readiness() : array();
    }

    /**
     * PHP process diagnostics.
     *
     * @return array
     */
    private static function php_status() {
        $memory_limit = self::ini_bytes( ini_get( 'memory_limit' ) );

        return array(
            'version'              => PHP_VERSION,
            'sapi'                 => PHP_SAPI,
            'binary'               => defined( 'PHP_BINARY' ) ? PHP_BINARY : '',
            'memory_limit'         => ini_get( 'memory_limit' ),
            'memory_limit_bytes'   => $memory_limit,
            'memory_limit_display' => self::format_bytes( $memory_limit ),
            'memory_usage'         => memory_get_usage( true ),
            'memory_usage_display' => self::format_bytes( memory_get_usage( true ) ),
            'memory_peak'          => memory_get_peak_usage( true ),
            'memory_peak_display'  => self::format_bytes( memory_get_peak_usage( true ) ),
        );
    }

    /**
     * OPcache and JIT diagnostics.
     *
     * @return array
     */
    private static function opcache_status() {
        $status = false;
        if ( function_exists( 'opcache_get_status' ) ) {
            $status = @opcache_get_status( false );
        }

        $jit_status = is_array( $status ) && isset( $status['jit'] ) && is_array( $status['jit'] )
            ? $status['jit']
            : array();

        $jit_buffer_size = self::ini_bytes( ini_get( 'opcache.jit_buffer_size' ) );
        $jit_buffer_free = isset( $jit_status['buffer_free'] ) ? (int) $jit_status['buffer_free'] : null;

        return array(
            'extension_loaded'             => extension_loaded( 'Zend OPcache' ) || extension_loaded( 'opcache' ),
            'function_available'           => function_exists( 'opcache_get_status' ),
            'enabled'                      => is_array( $status ) && ! empty( $status['opcache_enabled'] ),
            'enable_ini'                   => ini_get( 'opcache.enable' ),
            'enable_cli_ini'               => ini_get( 'opcache.enable_cli' ),
            'enable_cli'                   => self::ini_enabled( 'opcache.enable_cli' ),
            'jit_ini'                      => ini_get( 'opcache.jit' ),
            'jit_buffer_size'              => ini_get( 'opcache.jit_buffer_size' ),
            'jit_buffer_size_bytes'        => $jit_buffer_size,
            'jit_buffer_size_display'      => self::format_bytes( $jit_buffer_size ),
            'jit_buffer_free_bytes'        => $jit_buffer_free,
            'jit_buffer_free_display'      => null === $jit_buffer_free ? '' : self::format_bytes( $jit_buffer_free ),
            'jit_enabled'                  => ! empty( $jit_status['enabled'] ),
            'jit_on'                       => ! empty( $jit_status['on'] ),
            'restart_pending'              => is_array( $status ) && ! empty( $status['restart_pending'] ),
            'memory_used_bytes'            => is_array( $status ) && isset( $status['memory_usage']['used_memory'] ) ? (int) $status['memory_usage']['used_memory'] : null,
            'memory_used_display'          => is_array( $status ) && isset( $status['memory_usage']['used_memory'] ) ? self::format_bytes( (int) $status['memory_usage']['used_memory'] ) : '',
            'interned_strings_used_bytes'  => is_array( $status ) && isset( $status['interned_strings_usage']['used_memory'] ) ? (int) $status['interned_strings_usage']['used_memory'] : null,
            'interned_strings_used_display' => is_array( $status ) && isset( $status['interned_strings_usage']['used_memory'] ) ? self::format_bytes( (int) $status['interned_strings_usage']['used_memory'] ) : '',
        );
    }

    /**
     * Security guard diagnostics.
     *
     * @return array
     */
    private static function security_status() {
        $permissions = new WPAgent_Permissions();
        $user_id     = get_current_user_id();

        return array(
            'rate_limit' => $permissions->rate_limit_status( $user_id ),
        );
    }

    /**
     * Private runtime storage diagnostics.
     *
     * @return array
     */
    private static function storage_status() {
        $configured       = get_option( 'wp_agent_runtime_root', '' );
        $configured       = is_string( $configured ) ? trim( $configured ) : '';
        $constant         = defined( 'WP_AGENT_RUNTIME_ROOT' ) ? trim( (string) WP_AGENT_RUNTIME_ROOT ) : '';
        $env              = getenv( 'WP_AGENT_RUNTIME_ROOT' );
        $env              = false !== $env ? trim( (string) $env ) : '';
        $selection        = WPAgent_Sandbox::runtime_root_selection();
        $active           = (string) ( $selection['runtime_root'] ?? WPAgent_Sandbox::runtime_root() );
        $active_source    = (string) ( $selection['source'] ?? '' );
        $configured_by    = in_array( $active_source, array( 'constant', 'environment', 'setting' ), true ) ? $active_source : '';
        $source_label     = (string) ( $selection['source_label'] ?? WPAgent_Sandbox::runtime_root_source_label( $active_source ) );

        return array(
            'runtime_root'          => $active,
            'active_source'         => $active_source,
            'active_source_label'   => $source_label,
            'configured_by'         => $configured_by,
            'configured_by_label'   => '' !== $configured_by ? WPAgent_Sandbox::runtime_root_source_label( $configured_by ) : '',
            'effective_configured'  => '' !== $configured_by,
            'configured'            => '' !== $configured,
            'configured_root'       => $configured,
            'setting_root'          => $configured,
            'constant_root'         => $constant,
            'env_root'              => $env,
            'configured_status'     => WPAgent_Sandbox::runtime_root_status( $configured, false ),
            'active_status'         => WPAgent_Sandbox::runtime_root_status( $active, false ),
            'candidate_statuses'    => $selection['candidates'] ?? array(),
        );
    }

    /**
     * Skills and Skills Store diagnostics.
     *
     * @return array
     */
    private static function skills_status() {
        return array(
            'github_store' => class_exists( 'WPAgent_Skills' ) ? WPAgent_Skills::github_store_readiness() : array(),
        );
    }

    /**
     * Queue diagnostics.
     *
     * @return array
     */
    private static function queue_status() {
        global $wpdb;

        $table = $wpdb->prefix . 'wp_agent_runs';
        $now   = current_time( 'mysql', true );

        $queued_at = $wpdb->get_var(
            "SELECT MIN(created_at) FROM {$table} WHERE status = 'queued'"
        );
        $running_at = $wpdb->get_var(
            "SELECT MIN(created_at) FROM {$table} WHERE status = 'running'"
        );
        $claimable_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(created_at)
             FROM {$table}
             WHERE status IN ('queued','running')
               AND ( locked_until IS NULL OR locked_until < %s )
               AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )",
            $now,
            $now
        ) );

        $counts = array(
            'queued'                => 0,
            'running'               => 0,
            'done'                  => 0,
            'error'                 => 0,
            'canceled'              => 0,
            'awaiting_confirmation' => 0,
        );
        $counts = array_merge( $counts, WPAgent_Runs::status_counts() );
        $recent_failures = self::recent_queue_failures( 5 );
        $last_failure    = ! empty( $recent_failures ) ? $recent_failures[0] : array();
        $next_retry_at   = WPAgent_Runs::next_retry_at();

        return array(
            'counts'                 => $counts,
            'claimable_count'        => WPAgent_Runs::claimable_count(),
            'retry_scheduled_count'  => WPAgent_Runs::retry_scheduled_count(),
            'next_retry_at'          => $next_retry_at ? (string) $next_retry_at : '',
            'next_retry_in'          => self::mysql_until( $next_retry_at ),
            'oldest_queued_at'       => $queued_at ? (string) $queued_at : '',
            'oldest_queued_age'      => self::mysql_age( $queued_at ),
            'oldest_running_at'      => $running_at ? (string) $running_at : '',
            'oldest_running_age'     => self::mysql_age( $running_at ),
            'oldest_claimable_at'    => $claimable_at ? (string) $claimable_at : '',
            'oldest_claimable_age'   => self::mysql_age( $claimable_at ),
            'lock_seconds'           => WPAgent_Runs::LOCK_SECONDS,
            'last_failure_at'        => ! empty( $last_failure['updated_at'] ) ? (string) $last_failure['updated_at'] : '',
            'last_failure_age'       => ! empty( $last_failure['updated_at'] ) ? self::mysql_age( $last_failure['updated_at'] ) : null,
            'last_failure_error'     => ! empty( $last_failure['error'] ) ? (string) $last_failure['error'] : '',
            'recent_failures'        => $recent_failures,
        );
    }

    /**
     * Scheduled workflow diagnostics.
     *
     * @return array
     */
    private static function schedule_status() {
        global $wpdb;

        $table = $wpdb->prefix . 'wp_agent_schedules';
        $now   = current_time( 'mysql', true );

        $status_counts = array(
            'active' => 0,
            'paused' => 0,
        );
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS count
             FROM {$table}
             GROUP BY status",
            ARRAY_A
        );
        foreach ( $rows as $row ) {
            $status = sanitize_key( (string) $row['status'] );
            if ( '' === $status ) {
                continue;
            }
            $status_counts[ $status ] = (int) $row['count'];
        }

        $last_status_counts = array();
        $last_rows = $wpdb->get_results(
            "SELECT COALESCE(NULLIF(last_status, ''), 'none') AS last_status, COUNT(*) AS count
             FROM {$table}
             GROUP BY COALESCE(NULLIF(last_status, ''), 'none')",
            ARRAY_A
        );
        foreach ( $last_rows as $row ) {
            $status = sanitize_key( (string) $row['last_status'] );
            $last_status_counts[ $status ?: 'none' ] = (int) $row['count'];
        }

        $oldest_due_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(next_run)
             FROM {$table}
             WHERE status = 'active'
               AND next_run <= %s
               AND ( locked_until IS NULL OR locked_until < %s )",
            $now,
            $now
        ) );
        $next_due_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(next_run)
             FROM {$table}
             WHERE status = 'active'
               AND next_run > %s",
            $now
        ) );
        $next_lock_release_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(locked_until)
             FROM {$table}
             WHERE status = 'active'
               AND locked_until >= %s",
            $now
        ) );

        $due_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE status = 'active'
               AND next_run <= %s
               AND ( locked_until IS NULL OR locked_until < %s )",
            $now,
            $now
        ) );
        $locked_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE status = 'active'
               AND locked_until >= %s",
            $now
        ) );
        $due_locked_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE status = 'active'
               AND next_run <= %s
               AND locked_until >= %s",
            $now,
            $now
        ) );
        $stale_lock_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE status = 'active'
               AND locked_until IS NOT NULL
               AND locked_until < %s",
            $now
        ) );
        $skill_bound_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE skill_slug IS NOT NULL
               AND skill_slug <> ''"
        );
        $skill_bound_active_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE status = 'active'
               AND skill_slug IS NOT NULL
               AND skill_slug <> ''"
        );
        $skill_policy = self::schedule_skill_policy_status( $table );

        return array(
            'counts'               => $status_counts,
            'last_status_counts'   => $last_status_counts,
            'active_count'         => (int) ( $status_counts['active'] ?? 0 ),
            'paused_count'         => (int) ( $status_counts['paused'] ?? 0 ),
            'due_count'            => $due_count,
            'locked_count'         => $locked_count,
            'due_locked_count'     => $due_locked_count,
            'stale_lock_count'     => $stale_lock_count,
            'oldest_due_at'        => $oldest_due_at ? (string) $oldest_due_at : '',
            'oldest_due_age'       => self::mysql_age( $oldest_due_at ),
            'next_due_at'          => $next_due_at ? (string) $next_due_at : '',
            'next_due_in'          => self::mysql_until( $next_due_at ),
            'next_lock_release_at' => $next_lock_release_at ? (string) $next_lock_release_at : '',
            'next_lock_release_in' => self::mysql_until( $next_lock_release_at ),
            'lock_seconds'         => WPAgent_Schedules::LOCK_SECONDS,
            'skill_bound_count'    => $skill_bound_count,
            'skill_bound_active_count' => $skill_bound_active_count,
            'skill_bound_recent_checked' => (int) $skill_policy['checked'],
            'skill_bound_recent_missing_count' => (int) $skill_policy['missing_count'],
            'skill_bound_recent_restricted_count' => (int) $skill_policy['restricted_count'],
            'skill_bound_recent_empty_permission_count' => (int) $skill_policy['empty_permission_count'],
            'recent_bound_skill_runs' => $skill_policy['recent_runs'],
        );
    }

    /**
     * Recent Skill-bound schedule/run policy diagnostics.
     *
     * @param string $table Schedule table name.
     * @return array
     */
    private static function schedule_skill_policy_status( $table ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, created_by, skill_slug, last_run_id, last_status, last_run
             FROM {$table}
             WHERE skill_slug IS NOT NULL
               AND skill_slug <> ''
             ORDER BY COALESCE(last_run, created_at) DESC, id DESC
             LIMIT 5",
            ARRAY_A
        );

        $recent_runs            = array();
        $missing_count          = 0;
        $restricted_count       = 0;
        $empty_permission_count = 0;

        foreach ( $rows as $row ) {
            $schedule_id = (int) ( $row['id'] ?? 0 );
            $run_id      = (int) ( $row['last_run_id'] ?? 0 );
            $user_id     = (int) ( $row['created_by'] ?? 0 );
            $skill_slug  = sanitize_title( (string) ( $row['skill_slug'] ?? '' ) );
            $policy      = array(
                'bound'             => false,
                'restricted'        => false,
                'allowed_tools'     => array(),
                'network'           => null,
                'code_execution'    => null,
                'permissions_found' => false,
            );
            $skill_found = false;

            if ( $run_id > 0 && class_exists( 'WPAgent_Schedules' ) ) {
                $policy      = WPAgent_Schedules::skill_policy_for_run( $run_id );
                $skill_found = ! empty( $policy['bound'] );
            } elseif ( class_exists( 'WPAgent_Skills' ) && $user_id > 0 && '' !== $skill_slug ) {
                $skill       = WPAgent_Skills::get_by_slug( $user_id, $skill_slug );
                $skill_found = is_array( $skill ) && 'active' === ( $skill['status'] ?? '' );
                if ( $skill_found ) {
                    $permissions = WPAgent_Skills::permissions_for_skill( $user_id, $skill_slug );
                    $policy      = array_merge(
                        WPAgent_Skills::policy_from_permissions( $permissions ),
                        array(
                            'bound'             => true,
                            'permissions_found' => ! empty( $permissions ),
                        )
                    );
                }
            }

            if ( ! $skill_found ) {
                $missing_count++;
            }
            if ( ! empty( $policy['restricted'] ) ) {
                $restricted_count++;
            }
            if ( $skill_found && empty( $policy['permissions_found'] ) ) {
                $empty_permission_count++;
            }

            $allowed_tools = is_array( $policy['allowed_tools'] ?? null ) ? $policy['allowed_tools'] : array();
            $recent_runs[] = array(
                'schedule_id'       => $schedule_id,
                'run_id'            => $run_id,
                'user_id'           => $user_id,
                'skill_slug'        => $skill_slug,
                'skill_found'       => $skill_found,
                'permissions_found' => ! empty( $policy['permissions_found'] ),
                'restricted'        => ! empty( $policy['restricted'] ),
                'allowed_tools'     => array_slice( array_values( $allowed_tools ), 0, 20 ),
                'allowed_tool_count' => count( $allowed_tools ),
                'network'           => array_key_exists( 'network', $policy ) ? $policy['network'] : null,
                'code_execution'    => array_key_exists( 'code_execution', $policy ) ? $policy['code_execution'] : null,
                'last_status'       => sanitize_key( (string) ( $row['last_status'] ?? '' ) ),
                'last_run'          => ! empty( $row['last_run'] ) ? (string) $row['last_run'] : '',
            );
        }

        return array(
            'checked'                => count( $rows ),
            'missing_count'          => $missing_count,
            'restricted_count'       => $restricted_count,
            'empty_permission_count' => $empty_permission_count,
            'recent_runs'            => $recent_runs,
        );
    }

    /**
     * Recent failed runs for queue health pages and machine diagnostics.
     *
     * @param int $limit Maximum rows to return.
     * @return array
     */
    private static function recent_queue_failures( $limit = 5 ) {
        global $wpdb;

        $limit = max( 1, min( (int) $limit, 20 ) );
        $table = $wpdb->prefix . 'wp_agent_runs';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, conversation_id, user_id, channel, error, loop_count, created_at, updated_at
             FROM {$table}
             WHERE status = 'error'
             ORDER BY updated_at DESC, id DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        $failures = array();
        foreach ( $rows as $row ) {
            $updated_at = ! empty( $row['updated_at'] ) ? (string) $row['updated_at'] : '';
            $failures[] = array(
                'id'              => (int) $row['id'],
                'conversation_id' => (int) $row['conversation_id'],
                'user_id'         => (int) $row['user_id'],
                'channel'         => sanitize_key( (string) $row['channel'] ),
                'error'           => self::queue_error_summary( (string) $row['error'] ),
                'loop_count'      => (int) $row['loop_count'],
                'created_at'      => ! empty( $row['created_at'] ) ? (string) $row['created_at'] : '',
                'updated_at'      => $updated_at,
                'age'             => self::mysql_age( $updated_at ),
            );
        }

        return $failures;
    }

    /**
     * Prepare a failed-run error message for public diagnostics.
     *
     * @param string $message Raw run error.
     * @return string
     */
    private static function queue_error_summary( $message ) {
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

    /**
     * Database ping diagnostics.
     *
     * @return array
     */
    private static function database_status() {
        global $wpdb;

        $started = microtime( true );
        $result  = $wpdb->get_var( 'SELECT 1' );
        $elapsed = ( microtime( true ) - $started ) * 1000;

        return array(
            'ok'         => '1' === (string) $result,
            'query_ms'   => round( $elapsed, 2 ),
            'last_error' => (string) $wpdb->last_error,
        );
    }

    /**
     * Lightweight process and WordPress loading diagnostics.
     *
     * @param float $collection_started Unix timestamp with microseconds.
     * @return array
     */
    private static function performance_status( $collection_started ) {
        $autoload_started = microtime( true );
        $alloptions       = wp_load_alloptions();
        $autoload_ms      = ( microtime( true ) - $autoload_started ) * 1000;

        $autoload_bytes = 0;
        if ( is_array( $alloptions ) ) {
            foreach ( $alloptions as $value ) {
                $autoload_bytes += strlen( is_scalar( $value ) || null === $value ? (string) $value : maybe_serialize( $value ) );
            }
        }

        $included_files = get_included_files();
        $plugin_root    = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
        $wp_agent_root  = trailingslashit( wp_normalize_path( WP_AGENT_PLUGIN_DIR ) );
        $plugin_files   = 0;
        $wp_agent_files = 0;

        foreach ( $included_files as $file ) {
            $file = wp_normalize_path( (string) $file );
            if ( 0 === strpos( $file, $plugin_root ) ) {
                $plugin_files++;
            }
            if ( 0 === strpos( $file, $wp_agent_root ) ) {
                $wp_agent_files++;
            }
        }

        return array(
            'diagnostics_collection_ms'  => round( ( microtime( true ) - (float) $collection_started ) * 1000, 2 ),
            'autoload_load_ms'           => round( $autoload_ms, 2 ),
            'autoload_options_count'     => is_array( $alloptions ) ? count( $alloptions ) : 0,
            'autoload_options_bytes'     => $autoload_bytes,
            'autoload_options_display'   => self::format_bytes( $autoload_bytes ),
            'included_files_count'       => count( $included_files ),
            'plugin_included_files_count' => $plugin_files,
            'wp_agent_included_files_count' => $wp_agent_files,
            'loaded_extensions_count'    => count( get_loaded_extensions() ),
            'memory_after_collection'    => memory_get_usage( true ),
            'memory_after_collection_display' => self::format_bytes( memory_get_usage( true ) ),
        );
    }

    /**
     * Summarize daemon fields most relevant to runtime health.
     *
     * @param array $daemon Raw daemon status.
     * @return array
     */
    private static function daemon_summary( $daemon ) {
        $memory_usage = (int) ( $daemon['memory_usage'] ?? 0 );
        $memory_peak  = (int) ( $daemon['memory_peak'] ?? 0 );
        $soft_limit   = (int) ( $daemon['memory_soft_limit'] ?? 0 );
        $hard_limit   = (int) ( $daemon['memory_hard_limit'] ?? 0 );

        return array(
            'status'                    => (string) ( $daemon['status'] ?? 'stopped' ),
            'running'                   => ! empty( $daemon['running'] ),
            'pid_verified'              => ! empty( $daemon['pid_verified'] ),
            'liveness_source'           => (string) ( $daemon['liveness_source'] ?? 'none' ),
            'liveness_note'             => (string) ( $daemon['liveness_note'] ?? '' ),
            'heartbeat_age'             => isset( $daemon['heartbeat_age'] ) ? $daemon['heartbeat_age'] : null,
            'ticks'                     => (int) ( $daemon['ticks'] ?? 0 ),
            'active_children'           => (int) ( $daemon['active_children'] ?? 0 ),
            'max_children'              => (int) ( $daemon['max_children'] ?? 0 ),
            'processed_jobs'            => (int) ( $daemon['processed_jobs'] ?? 0 ),
            'gc_runs'                   => (int) ( $daemon['gc_runs'] ?? 0 ),
            'memory_baseline'           => (int) ( $daemon['memory_baseline'] ?? 0 ),
            'memory_usage'              => $memory_usage,
            'memory_usage_display'      => self::format_bytes( $memory_usage ),
            'memory_peak'               => $memory_peak,
            'memory_peak_display'       => self::format_bytes( $memory_peak ),
            'memory_delta'              => (int) ( $daemon['memory_delta'] ?? 0 ),
            'memory_delta_display'      => self::format_bytes( (int) ( $daemon['memory_delta'] ?? 0 ) ),
            'memory_per_job_delta'      => (int) ( $daemon['memory_per_job_delta'] ?? 0 ),
            'memory_per_job_delta_display' => self::format_bytes( (int) ( $daemon['memory_per_job_delta'] ?? 0 ) ),
            'memory_soft_limit'         => $soft_limit,
            'memory_soft_limit_display' => self::format_bytes( $soft_limit ),
            'memory_hard_limit'         => $hard_limit,
            'memory_hard_limit_display' => self::format_bytes( $hard_limit ),
            'restart_reason'            => (string) ( $daemon['restart_reason'] ?? '' ),
            'restart_count'             => (int) ( $daemon['watchdog_restart_count'] ?? 0 ),
            'last_error'                => (string) ( $daemon['last_error'] ?? '' ),
            'last_error_at'             => (string) ( $daemon['last_error_at'] ?? '' ),
            'watchdog_action'           => (string) ( $daemon['last_watchdog_action'] ?? '' ),
            'watchdog_reason'           => (string) ( $daemon['last_watchdog_reason'] ?? '' ),
            'watchdog_restart_count'    => (int) ( $daemon['watchdog_restart_count'] ?? 0 ),
            'watchdog_consecutive_failures' => (int) ( $daemon['watchdog_consecutive_failures'] ?? 0 ),
            'watchdog_backoff_remaining' => (int) ( $daemon['watchdog_backoff_remaining'] ?? 0 ),
            'watchdog_last_error'       => (string) ( $daemon['watchdog_last_error'] ?? '' ),
        );
    }

    /**
     * Convert a WordPress UTC mysql datetime to an age in seconds.
     *
     * @param string|null $mysql Datetime.
     * @return int|null
     */
    private static function mysql_age( $mysql ) {
        if ( empty( $mysql ) ) {
            return null;
        }

        $timestamp = strtotime( $mysql . ' UTC' );
        if ( false === $timestamp ) {
            return null;
        }

        return max( 0, time() - $timestamp );
    }

    /**
     * Seconds until a WordPress UTC mysql datetime.
     *
     * @param string|null $mysql Datetime.
     * @return int|null
     */
    private static function mysql_until( $mysql ) {
        if ( empty( $mysql ) ) {
            return null;
        }

        $timestamp = strtotime( $mysql . ' UTC' );
        if ( false === $timestamp ) {
            return null;
        }

        return max( 0, $timestamp - time() );
    }

    /**
     * Parse PHP shorthand sizes.
     *
     * @param string|false $value INI value.
     * @return int
     */
    private static function ini_bytes( $value ) {
        if ( false === $value || '' === trim( (string) $value ) ) {
            return 0;
        }

        $value = trim( (string) $value );
        if ( '-1' === $value ) {
            return -1;
        }

        $unit   = strtolower( substr( $value, -1 ) );
        $number = (float) $value;

        switch ( $unit ) {
            case 'g':
                $number *= 1024;
                // Fall through.
            case 'm':
                $number *= 1024;
                // Fall through.
            case 'k':
                $number *= 1024;
                break;
        }

        return (int) $number;
    }

    /**
     * Read a boolean-ish INI flag.
     *
     * @param string $key INI key.
     * @return bool
     */
    private static function ini_enabled( $key ) {
        $value = strtolower( trim( (string) ini_get( $key ) ) );
        return in_array( $value, array( '1', 'on', 'yes', 'true' ), true );
    }
}
