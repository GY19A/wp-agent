<?php
/**
 * WP Agent deterministic daemon soak checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/daemon-soak.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This daemon soak script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_daemon_soak_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_daemon_soak_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_daemon_soak_fail( $message );
    }
}

function wp_agent_daemon_soak_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

WPAgent_Daemon::request_stop();

$runtime_root = WPAgent_Sandbox::runtime_root();
$logs         = array();
$started_at   = microtime( true );
$result       = WPAgent_Daemon::run(
    array(
        'max_children'      => 1,
        'idle_sleep'        => 0,
        'max_lifetime'      => 5,
        'max_idle_time'     => 1,
        'memory_soft_limit' => 512,
        'memory_hard_limit' => 768,
    ),
    function( $line ) use ( &$logs ) {
        if ( count( $logs ) < 20 ) {
            $logs[] = (string) $line;
        }
    }
);
$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

wp_agent_daemon_soak_assert( ! empty( $result['ok'] ), 'Daemon foreground run should succeed.' );
wp_agent_daemon_soak_assert( (int) ( $result['ticks'] ?? 0 ) > 0, 'Daemon should execute at least one loop tick.' );
wp_agent_daemon_soak_assert( 'max_idle_time' === ( $result['restart_reason'] ?? '' ), 'Daemon should stop because max_idle_time was reached.' );
wp_agent_daemon_soak_assert( 0 === (int) ( $result['processed_jobs'] ?? -1 ), 'Idle soak should not process jobs.' );

$status = WPAgent_Daemon::status();
wp_agent_daemon_soak_assert( empty( $status['running'] ), 'Daemon should not be running after foreground soak exits.' );
wp_agent_daemon_soak_assert( 'stopped' === ( $status['status'] ?? '' ), 'Daemon final status should be stopped.' );
wp_agent_daemon_soak_assert( 'max_idle_time' === ( $status['restart_reason'] ?? '' ), 'Daemon status should retain restart reason.' );
wp_agent_daemon_soak_assert( (int) ( $status['ticks'] ?? 0 ) >= (int) $result['ticks'], 'Daemon status should retain tick count.' );
wp_agent_daemon_soak_assert( (int) ( $status['heartbeat'] ?? 0 ) > 0, 'Daemon status should retain heartbeat.' );
wp_agent_daemon_soak_assert( (int) ( $status['memory_baseline'] ?? 0 ) > 0, 'Daemon should report memory baseline.' );
wp_agent_daemon_soak_assert( (int) ( $status['memory_usage'] ?? 0 ) > 0, 'Daemon should report memory usage.' );
wp_agent_daemon_soak_assert( (int) ( $status['memory_peak'] ?? 0 ) >= (int) ( $status['memory_usage'] ?? 0 ), 'Daemon memory peak should be at least current usage.' );
wp_agent_daemon_soak_assert( (int) ( $status['memory_delta'] ?? -1 ) >= 0, 'Daemon should report non-negative memory delta.' );
wp_agent_daemon_soak_assert( 0 === (int) ( $status['memory_per_job_delta'] ?? -1 ), 'Idle daemon should report zero per-job memory delta.' );
wp_agent_daemon_soak_assert( 0 === (int) ( $status['processed_jobs'] ?? -1 ), 'Daemon status should report zero processed jobs.' );

foreach ( array( 'pid_file', 'log_file' ) as $field ) {
    wp_agent_daemon_soak_assert( ! empty( $status[ $field ] ), $field . ' should be reported.' );
    wp_agent_daemon_soak_assert( wp_agent_daemon_soak_path_starts_with( $status[ $field ], $runtime_root ), $field . ' should live under runtime root.' );
    wp_agent_daemon_soak_assert( ! wp_agent_daemon_soak_path_starts_with( $status[ $field ], WP_AGENT_PLUGIN_DIR ), $field . ' should not live under plugin directory.' );
}
wp_agent_daemon_soak_assert( ! file_exists( WPAgent_Daemon::pid_file() ), 'PID file should be removed after foreground daemon exits.' );
wp_agent_daemon_soak_assert( ! file_exists( WPAgent_Daemon::stop_file() ), 'Stop file should be removed after foreground daemon exits.' );

$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_daemon_soak_assert( 'stopped' === ( $diagnostics['daemon']['status'] ?? '' ), 'Diagnostics should report stopped daemon.' );
wp_agent_daemon_soak_assert( 'max_idle_time' === ( $diagnostics['daemon']['restart_reason'] ?? '' ), 'Diagnostics should expose daemon restart reason.' );
wp_agent_daemon_soak_assert( (int) ( $diagnostics['daemon']['ticks'] ?? 0 ) >= (int) $result['ticks'], 'Diagnostics should expose daemon tick count.' );
wp_agent_daemon_soak_assert( (int) ( $diagnostics['daemon']['memory_baseline'] ?? 0 ) > 0, 'Diagnostics should expose memory baseline.' );
wp_agent_daemon_soak_assert( (int) ( $diagnostics['queue']['claimable_count'] ?? -1 ) >= 0, 'Diagnostics should expose queue claimable count.' );
wp_agent_daemon_soak_assert( ! empty( $diagnostics['database']['ok'] ), 'Diagnostics database ping should pass.' );

echo wp_json_encode( array(
    'success'      => true,
    'result'       => $result,
    'elapsed_ms'   => $elapsed_ms,
    'status'       => array(
        'ticks'                => (int) $status['ticks'],
        'restart_reason'       => $status['restart_reason'],
        'memory_baseline'      => (int) $status['memory_baseline'],
        'memory_usage'         => (int) $status['memory_usage'],
        'memory_peak'          => (int) $status['memory_peak'],
        'memory_delta'         => (int) $status['memory_delta'],
        'memory_per_job_delta' => (int) $status['memory_per_job_delta'],
        'heartbeat_age'        => $status['heartbeat_age'],
        'pcntl'                => ! empty( $status['pcntl'] ),
        'can_fork'             => ! empty( $status['can_fork'] ),
    ),
    'diagnostics'  => array(
        'daemon'   => $diagnostics['daemon'],
        'queue'    => $diagnostics['queue'],
        'database' => $diagnostics['database'],
    ),
    'runtime_root' => $runtime_root,
    'logs'         => $logs,
) ) . "\n";
