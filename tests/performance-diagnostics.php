<?php
/**
 * WP Agent performance diagnostics checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/performance-diagnostics.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This performance diagnostics script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_perf_diag_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_perf_diag_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_perf_diag_fail( $message );
    }
}

function wp_agent_perf_diag_assert_runtime( array $diagnostics, $label ) {
    $performance = $diagnostics['performance'] ?? array();
    $ai          = $diagnostics['ai'] ?? array();
    $rate_limit  = $diagnostics['security']['rate_limit'] ?? array();
    $storage     = $diagnostics['storage'] ?? array();
    $skills      = $diagnostics['skills'] ?? array();
    $github_store = $skills['github_store'] ?? array();
    $queue       = $diagnostics['queue'] ?? array();
    $schedules   = $diagnostics['schedules'] ?? array();
    $daemon      = $diagnostics['daemon'] ?? array();

    wp_agent_perf_diag_assert( ! empty( $diagnostics['php']['version'] ), $label . ' should include PHP version.' );
    wp_agent_perf_diag_assert( array_key_exists( 'ready', $ai ), $label . ' should include AI readiness.' );
    wp_agent_perf_diag_assert( array_key_exists( 'content_ready', $ai ), $label . ' should include AI content readiness.' );
    wp_agent_perf_diag_assert( array_key_exists( 'image_generation_ready', $ai ), $label . ' should include AI image readiness.' );
    wp_agent_perf_diag_assert( array_key_exists( 'api_key_state', $ai ), $label . ' should include AI API key state.' );
    wp_agent_perf_diag_assert( array_key_exists( 'model', $ai ), $label . ' should include configured AI model.' );
    wp_agent_perf_diag_assert( array_key_exists( 'image_model', $ai ), $label . ' should include configured image model.' );
    wp_agent_perf_diag_assert( array_key_exists( 'base_url_host', $ai ), $label . ' should include AI endpoint host.' );
    wp_agent_perf_diag_assert( array_key_exists( 'missing', $ai ) && is_array( $ai['missing'] ), $label . ' should include missing AI settings.' );
    wp_agent_perf_diag_assert( false === strpos( wp_json_encode( $ai ), 'Bearer ' ), $label . ' must not expose bearer tokens.' );
    wp_agent_perf_diag_assert( array_key_exists( 'enable_cli', $diagnostics['opcache'] ?? array() ), $label . ' should include OPcache CLI state.' );
    wp_agent_perf_diag_assert( array_key_exists( 'jit_buffer_size_bytes', $diagnostics['opcache'] ?? array() ), $label . ' should include JIT buffer size.' );
    wp_agent_perf_diag_assert( isset( $rate_limit['limit_per_hour'] ) && (int) $rate_limit['limit_per_hour'] > 0, $label . ' should include rate-limit ceiling.' );
    wp_agent_perf_diag_assert( isset( $rate_limit['remaining'] ) && (int) $rate_limit['remaining'] >= 0, $label . ' should include rate-limit remaining allowance.' );
    wp_agent_perf_diag_assert( array_key_exists( 'github_store', $skills ) && is_array( $github_store ), $label . ' should include GitHub Skills Store diagnostics.' );
    wp_agent_perf_diag_assert( array_key_exists( 'ready', $github_store ), $label . ' should include GitHub Skills Store readiness.' );
    wp_agent_perf_diag_assert( array_key_exists( 'configured', $github_store ), $label . ' should include GitHub Skills Store configured state.' );
    wp_agent_perf_diag_assert( array_key_exists( 'repository', $github_store ), $label . ' should include GitHub Skills Store repository.' );
    wp_agent_perf_diag_assert( array_key_exists( 'skill_path', $github_store ), $label . ' should include GitHub Skills Store Skill path.' );
    wp_agent_perf_diag_assert( array_key_exists( 'placeholder_reason', $github_store ), $label . ' should include GitHub Skills Store placeholder reason.' );
    wp_agent_perf_diag_assert( array_key_exists( 'activation_policy', $github_store ), $label . ' should include GitHub Skills Store review policy.' );
    wp_agent_perf_diag_assert( array_key_exists( 'token_state', $github_store ), $label . ' should include GitHub token state.' );
    wp_agent_perf_diag_assert( array_key_exists( 'missing', $github_store ) && is_array( $github_store['missing'] ), $label . ' should include missing GitHub Skills Store settings.' );
    wp_agent_perf_diag_assert( '' !== (string) ( $storage['runtime_root'] ?? '' ), $label . ' should include runtime root.' );
    wp_agent_perf_diag_assert( '' !== (string) ( $storage['active_source'] ?? '' ), $label . ' should include runtime root source.' );
    wp_agent_perf_diag_assert( '' !== (string) ( $storage['active_source_label'] ?? '' ), $label . ' should include runtime root source label.' );
    wp_agent_perf_diag_assert( array_key_exists( 'effective_configured', $storage ), $label . ' should include effective runtime root configuration state.' );
    wp_agent_perf_diag_assert( array_key_exists( 'configured_by', $storage ), $label . ' should include runtime root configured source.' );
    wp_agent_perf_diag_assert( array_key_exists( 'setting_root', $storage ), $label . ' should include runtime root setting value.' );
    wp_agent_perf_diag_assert( array_key_exists( 'constant_root', $storage ), $label . ' should include runtime root constant value.' );
    wp_agent_perf_diag_assert( array_key_exists( 'env_root', $storage ), $label . ' should include runtime root environment value.' );
    wp_agent_perf_diag_assert( array_key_exists( 'candidate_statuses', $storage ) && is_array( $storage['candidate_statuses'] ), $label . ' should include runtime root candidate statuses.' );
    $env_root = getenv( 'WP_AGENT_RUNTIME_ROOT' );
    if ( false !== $env_root && '' !== trim( (string) $env_root ) && ! defined( 'WP_AGENT_RUNTIME_ROOT' ) ) {
        wp_agent_perf_diag_assert( 'environment' === ( $storage['active_source'] ?? '' ), $label . ' should report WP_AGENT_RUNTIME_ROOT as the active runtime root source.' );
    }
    wp_agent_perf_diag_assert( ! empty( $diagnostics['database']['ok'] ), $label . ' database ping should pass.' );
    wp_agent_perf_diag_assert( isset( $diagnostics['database']['query_ms'] ) && (float) $diagnostics['database']['query_ms'] >= 0, $label . ' should include database query timing.' );
    wp_agent_perf_diag_assert( array_key_exists( 'pid_verified', $daemon ), $label . ' should include daemon PID verification state.' );
    wp_agent_perf_diag_assert( array_key_exists( 'liveness_source', $daemon ), $label . ' should include daemon liveness source.' );
    wp_agent_perf_diag_assert( array_key_exists( 'liveness_note', $daemon ), $label . ' should include daemon liveness note.' );
    wp_agent_perf_diag_assert( array_key_exists( 'recent_failures', $queue ) && is_array( $queue['recent_failures'] ), $label . ' should include recent queue failures.' );
    wp_agent_perf_diag_assert( array_key_exists( 'retry_scheduled_count', $queue ), $label . ' should include retry-scheduled count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'next_retry_at', $queue ), $label . ' should include next retry timestamp.' );
    wp_agent_perf_diag_assert( array_key_exists( 'next_retry_in', $queue ), $label . ' should include next retry delay.' );
    wp_agent_perf_diag_assert( array_key_exists( 'last_failure_at', $queue ), $label . ' should include latest queue failure timestamp.' );
    wp_agent_perf_diag_assert( array_key_exists( 'last_failure_age', $queue ), $label . ' should include latest queue failure age.' );
    wp_agent_perf_diag_assert( array_key_exists( 'last_failure_error', $queue ), $label . ' should include latest queue failure summary.' );
    wp_agent_perf_diag_assert( array_key_exists( 'counts', $schedules ) && is_array( $schedules['counts'] ), $label . ' should include schedule status counts.' );
    wp_agent_perf_diag_assert( array_key_exists( 'last_status_counts', $schedules ) && is_array( $schedules['last_status_counts'] ), $label . ' should include schedule last-status counts.' );
    wp_agent_perf_diag_assert( array_key_exists( 'due_count', $schedules ), $label . ' should include due schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'locked_count', $schedules ), $label . ' should include locked schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'due_locked_count', $schedules ), $label . ' should include due locked schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'stale_lock_count', $schedules ), $label . ' should include stale schedule lock count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'oldest_due_at', $schedules ), $label . ' should include oldest due schedule timestamp.' );
    wp_agent_perf_diag_assert( array_key_exists( 'oldest_due_age', $schedules ), $label . ' should include oldest due schedule age.' );
    wp_agent_perf_diag_assert( array_key_exists( 'next_due_at', $schedules ), $label . ' should include next schedule timestamp.' );
    wp_agent_perf_diag_assert( array_key_exists( 'next_due_in', $schedules ), $label . ' should include next schedule delay.' );
    wp_agent_perf_diag_assert( array_key_exists( 'lock_seconds', $schedules ) && (int) $schedules['lock_seconds'] > 0, $label . ' should include schedule lock duration.' );
    wp_agent_perf_diag_assert( array_key_exists( 'skill_bound_count', $schedules ), $label . ' should include Skill-bound schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'skill_bound_active_count', $schedules ), $label . ' should include active Skill-bound schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'skill_bound_recent_checked', $schedules ), $label . ' should include checked Skill-bound schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'skill_bound_recent_missing_count', $schedules ), $label . ' should include missing Skill-bound schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'skill_bound_recent_restricted_count', $schedules ), $label . ' should include restricted Skill-bound schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'skill_bound_recent_empty_permission_count', $schedules ), $label . ' should include empty-permission Skill-bound schedule count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'recent_bound_skill_runs', $schedules ) && is_array( $schedules['recent_bound_skill_runs'] ), $label . ' should include recent Skill-bound schedule policies.' );
    wp_agent_perf_diag_assert( array_key_exists( 'restart_count', $daemon ), $label . ' should include daemon restart count.' );
    wp_agent_perf_diag_assert( array_key_exists( 'last_error', $daemon ), $label . ' should include daemon last error.' );
    wp_agent_perf_diag_assert( array_key_exists( 'last_error_at', $daemon ), $label . ' should include daemon last error timestamp.' );
    wp_agent_perf_diag_assert( array_key_exists( 'watchdog_last_error', $daemon ), $label . ' should include watchdog last error.' );

    wp_agent_perf_diag_assert( isset( $performance['diagnostics_collection_ms'] ) && (float) $performance['diagnostics_collection_ms'] >= 0, $label . ' should include diagnostics collection timing.' );
    wp_agent_perf_diag_assert( isset( $performance['autoload_load_ms'] ) && (float) $performance['autoload_load_ms'] >= 0, $label . ' should include autoload timing.' );
    wp_agent_perf_diag_assert( (int) ( $performance['autoload_options_count'] ?? 0 ) > 0, $label . ' should include autoload option count.' );
    wp_agent_perf_diag_assert( (int) ( $performance['autoload_options_bytes'] ?? 0 ) > 0, $label . ' should include autoload option bytes.' );
    wp_agent_perf_diag_assert( '' !== (string) ( $performance['autoload_options_display'] ?? '' ), $label . ' should include formatted autoload size.' );
    wp_agent_perf_diag_assert( (int) ( $performance['included_files_count'] ?? 0 ) > 0, $label . ' should include included file count.' );
    wp_agent_perf_diag_assert( (int) ( $performance['wp_agent_included_files_count'] ?? 0 ) > 0, $label . ' should include WP Agent included file count.' );
    wp_agent_perf_diag_assert( (int) ( $performance['loaded_extensions_count'] ?? 0 ) > 0, $label . ' should include loaded extension count.' );
    wp_agent_perf_diag_assert( (int) ( $performance['memory_after_collection'] ?? 0 ) > 0, $label . ' should include post-collection memory.' );
}

$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_perf_diag_assert_runtime( $diagnostics, 'Collector diagnostics' );

$runtime_tool = new WPAgent_Tool_Runtime();
$runtime_tool->set_context( 1, 'wpcli', 0, 1, 0 );
$runtime_result = $runtime_tool->execute( array( 'action' => 'status' ) );
wp_agent_perf_diag_assert( ! empty( $runtime_result['success'] ), 'Runtime tool should succeed.' );
wp_agent_perf_diag_assert_runtime( $runtime_result['diagnostics'] ?? array(), 'Runtime tool diagnostics' );

$cli_output = WP_CLI::runcommand( 'wp-agent diagnostics', array(
    'return'     => true,
    'parse'      => 'json',
    'launch'     => false,
    'exit_error' => false,
) );
wp_agent_perf_diag_assert( is_array( $cli_output ), 'WP-CLI diagnostics should return parsed JSON.' );
wp_agent_perf_diag_assert_runtime( $cli_output, 'WP-CLI diagnostics' );

echo wp_json_encode( array(
    'success'     => true,
    'performance' => $diagnostics['performance'],
    'database'    => $diagnostics['database'],
) ) . "\n";
