<?php
/**
 * WP Agent background daemon and watchdog checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/daemon-background-watchdog.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This daemon background script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_daemon_bg_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_daemon_bg_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_daemon_bg_fail( $message );
    }
}

function wp_agent_daemon_bg_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_daemon_bg_wait_for( callable $predicate, $seconds, $label ) {
    $deadline = microtime( true ) + max( 1, (int) $seconds );
    $last     = null;

    do {
        $last = $predicate();
        if ( ! empty( $last['ok'] ) ) {
            return $last;
        }
        usleep( 250000 );
    } while ( microtime( true ) < $deadline );

    $debug = array(
        'last'             => $last,
        'stop_file'        => WPAgent_Daemon::stop_file(),
        'stop_file_exists' => file_exists( WPAgent_Daemon::stop_file() ),
        'runtime_dir'      => WPAgent_Daemon::runtime_dir(),
        'runtime_files'    => is_dir( WPAgent_Daemon::runtime_dir() ) ? array_values( array_diff( scandir( WPAgent_Daemon::runtime_dir() ), array( '.', '..' ) ) ) : array(),
        'stop_option'      => get_option( 'wp_agent_daemon_stop_request', null ),
        'log_tail'         => is_file( WPAgent_Daemon::log_file() ) ? array_slice( file( WPAgent_Daemon::log_file(), FILE_IGNORE_NEW_LINES ), -5 ) : array(),
    );

    wp_agent_daemon_bg_fail( $label . ': ' . wp_json_encode( $debug ) );
}

function wp_agent_daemon_bg_wait_running( $seconds, $label ) {
    return wp_agent_daemon_bg_wait_for(
        function() {
            $status = WPAgent_Daemon::status();
            return array(
                'ok'     => ! empty( $status['running'] ) && (int) ( $status['heartbeat'] ?? 0 ) > 0,
                'status' => $status,
            );
        },
        $seconds,
        $label
    )['status'];
}

function wp_agent_daemon_bg_wait_stopped( $seconds, $label ) {
    return wp_agent_daemon_bg_wait_for(
        function() {
            $status = WPAgent_Daemon::status();
            return array(
                'ok'     => empty( $status['running'] ) && 'stopped' === ( $status['status'] ?? '' ),
                'status' => $status,
            );
        },
        $seconds,
        $label
    )['status'];
}

$initial_stop = WPAgent_Daemon::request_stop();
wp_agent_daemon_bg_assert( ! is_wp_error( $initial_stop ), is_wp_error( $initial_stop ) ? $initial_stop->get_error_message() : 'Initial stop request should not fail.' );
wp_agent_daemon_bg_wait_stopped( 5, 'Initial daemon stop did not settle' );

$runtime_root = WPAgent_Sandbox::runtime_root();
$site_root    = WPAgent_Sandbox::site_runtime_root();
$runtime_area = WPAgent_Sandbox::runtime_area_dir( 'runtime' );

$wake = WPAgent_Daemon::wake( array(
    'max_children'      => 1,
    'idle_sleep'        => 1,
    'max_lifetime'      => 30,
    'max_idle_time'     => 20,
    'memory_soft_limit' => 512,
    'memory_hard_limit' => 768,
) );
wp_agent_daemon_bg_assert( ! is_wp_error( $wake ), is_wp_error( $wake ) ? $wake->get_error_message() : 'Daemon wake should not fail.' );
wp_agent_daemon_bg_assert( ! empty( $wake['started'] ), 'Daemon wake should start a background process.' );

$running = wp_agent_daemon_bg_wait_running( 8, 'Daemon did not reach running state after wake' );
wp_agent_daemon_bg_assert( (int) ( $running['pid'] ?? 0 ) > 0, 'Running daemon should report a PID.' );
wp_agent_daemon_bg_assert( (int) ( $running['heartbeat_age'] ?? 99 ) <= 5, 'Running daemon heartbeat should be fresh.' );
wp_agent_daemon_bg_assert( wp_agent_daemon_bg_path_starts_with( $running['pid_file'], $runtime_area ), 'PID file should live under the site runtime area.' );
wp_agent_daemon_bg_assert( wp_agent_daemon_bg_path_starts_with( $running['log_file'], $runtime_area ), 'Log file should live under the site runtime area.' );
wp_agent_daemon_bg_assert( wp_agent_daemon_bg_path_starts_with( $running['pid_file'], $site_root ), 'PID file should live under the site runtime root.' );
wp_agent_daemon_bg_assert( ! wp_agent_daemon_bg_path_starts_with( $running['pid_file'], WP_AGENT_PLUGIN_DIR ), 'PID file must not live under the plugin directory.' );

$healthy = WPAgent_Daemon::watchdog( array(
    'stale_seconds' => 10,
    'backoff_base'  => 1,
    'backoff_max'   => 5,
) );
wp_agent_daemon_bg_assert( ! is_wp_error( $healthy ), is_wp_error( $healthy ) ? $healthy->get_error_message() : 'Watchdog healthy check should not fail.' );
wp_agent_daemon_bg_assert( ! empty( $healthy['healthy'] ), 'Watchdog should report a freshly running daemon as healthy.' );
wp_agent_daemon_bg_assert( 'healthy' === ( $healthy['action'] ?? '' ), 'Watchdog healthy action should be reported.' );

$stop = WPAgent_Daemon::request_stop();
wp_agent_daemon_bg_assert( ! is_wp_error( $stop ), is_wp_error( $stop ) ? $stop->get_error_message() : 'Daemon stop request should not fail.' );
clearstatcache( true, WPAgent_Daemon::stop_file() );
wp_agent_daemon_bg_assert( file_exists( WPAgent_Daemon::stop_file() ) || empty( $stop['running'] ), 'Stop request should either leave a stop file or stop the daemon immediately.' );
$stopped = wp_agent_daemon_bg_wait_stopped( 10, 'Daemon did not stop after stop request' );
wp_agent_daemon_bg_assert( empty( $stopped['running'] ), 'Daemon should be stopped after stop request.' );

$recovery = WPAgent_Daemon::watchdog( array(
    'max_children'      => 1,
    'idle_sleep'        => 1,
    'max_lifetime'      => 30,
    'max_idle_time'     => 20,
    'memory_soft_limit' => 512,
    'memory_hard_limit' => 768,
    'stale_seconds'     => 10,
    'backoff_base'      => 1,
    'backoff_max'       => 5,
) );
wp_agent_daemon_bg_assert( ! is_wp_error( $recovery ), is_wp_error( $recovery ) ? $recovery->get_error_message() : 'Watchdog recovery should not fail.' );
wp_agent_daemon_bg_assert( ! empty( $recovery['started'] ), 'Watchdog should start a stopped daemon.' );
wp_agent_daemon_bg_assert( 'restart_requested' === ( $recovery['action'] ?? '' ), 'Watchdog should report restart_requested.' );

$restarted = wp_agent_daemon_bg_wait_running( 8, 'Daemon did not reach running state after watchdog recovery' );
wp_agent_daemon_bg_assert( (int) ( $restarted['watchdog_restart_count'] ?? 0 ) >= 1, 'Daemon status should retain watchdog restart count.' );

$cleanup_stop = WPAgent_Daemon::request_stop();
wp_agent_daemon_bg_assert( ! is_wp_error( $cleanup_stop ), is_wp_error( $cleanup_stop ) ? $cleanup_stop->get_error_message() : 'Cleanup stop request should not fail.' );
$final = wp_agent_daemon_bg_wait_stopped( 10, 'Recovered daemon did not stop during cleanup' );
wp_agent_daemon_bg_wait_for(
    function() {
        clearstatcache( true, WPAgent_Daemon::pid_file() );
        clearstatcache( true, WPAgent_Daemon::stop_file() );
        return array(
            'ok'              => ! file_exists( WPAgent_Daemon::pid_file() ) && ! file_exists( WPAgent_Daemon::stop_file() ),
            'pid_file_exists' => file_exists( WPAgent_Daemon::pid_file() ),
            'stop_file_exists' => file_exists( WPAgent_Daemon::stop_file() ),
        );
    },
    5,
    'Daemon runtime files were not removed after cleanup stop'
);

$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_daemon_bg_assert( 'stopped' === ( $diagnostics['daemon']['status'] ?? '' ), 'Diagnostics should report stopped daemon after cleanup.' );

echo wp_json_encode( array(
    'success'      => true,
    'runtime_root' => $runtime_root,
    'site_root'    => $site_root,
    'wake'         => array(
        'started' => ! empty( $wake['started'] ),
        'pid'     => (int) ( $running['pid'] ?? 0 ),
        'ticks'   => (int) ( $running['ticks'] ?? 0 ),
    ),
    'healthy'      => array(
        'action'  => $healthy['action'] ?? '',
        'running' => ! empty( $healthy['status']['running'] ),
    ),
    'recovery'     => array(
        'action'         => $recovery['action'] ?? '',
        'started'        => ! empty( $recovery['started'] ),
        'restart_count'  => (int) ( $restarted['watchdog_restart_count'] ?? 0 ),
        'restarted_pid'  => (int) ( $restarted['pid'] ?? 0 ),
    ),
    'final_status' => array(
        'status'               => $final['status'] ?? '',
        'running'              => ! empty( $final['running'] ),
        'last_watchdog_action' => $final['last_watchdog_action'] ?? '',
    ),
) ) . "\n";
