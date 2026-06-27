<?php
/**
 * Daemon status should trust a fresh heartbeat when PID verification is unavailable.
 *
 * This covers WP-CLI running in a different PID namespace from the resident
 * daemon container: the daemon PID may be invisible, but the database-backed
 * heartbeat is still authoritative while fresh.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/daemon-status-heartbeat.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This daemon status heartbeat script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_daemon_status_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_daemon_status_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_daemon_status_fail( $message );
    }
}

$previous_state = get_option( WPAgent_Daemon::STATE_OPTION, array() );
$previous_stop  = get_option( WPAgent_Daemon::STOP_OPTION, null );

$restore = function() use ( $previous_state, $previous_stop ) {
    update_option( WPAgent_Daemon::STATE_OPTION, $previous_state, false );
    if ( null === $previous_stop ) {
        delete_option( WPAgent_Daemon::STOP_OPTION );
    } else {
        update_option( WPAgent_Daemon::STOP_OPTION, $previous_stop, false );
    }
};
register_shutdown_function( $restore );

$fake_pid = 999999;
update_option( WPAgent_Daemon::STATE_OPTION, array(
    'status'       => 'idle',
    'pid'          => $fake_pid,
    'daemon_token' => '1234567890abcdef1234567890abcdef',
    'heartbeat'    => time(),
    'started_at'   => current_time( 'mysql', true ),
    'pcntl'        => true,
    'last_event'   => 'heartbeat',
), false );
delete_option( WPAgent_Daemon::STOP_OPTION );

$fresh = WPAgent_Daemon::status();
wp_agent_daemon_status_assert( ! empty( $fresh['running'] ), 'Fresh heartbeat should report running even when PID cannot be verified.' );
wp_agent_daemon_status_assert( empty( $fresh['pid_verified'] ), 'Fake PID should not be locally verified.' );
wp_agent_daemon_status_assert( $fake_pid === (int) $fresh['pid'], 'Fresh heartbeat status should retain the daemon PID.' );
wp_agent_daemon_status_assert( 'heartbeat' === ( $fresh['liveness_source'] ?? '' ), 'Fresh heartbeat status should report heartbeat liveness.' );
wp_agent_daemon_status_assert( false !== strpos( (string) ( $fresh['liveness_note'] ?? '' ), 'heartbeat' ), 'Fresh heartbeat status should explain heartbeat liveness.' );

update_option( WPAgent_Daemon::STATE_OPTION, array(
    'status'       => 'idle',
    'pid'          => $fake_pid,
    'daemon_token' => '1234567890abcdef1234567890abcdef',
    'heartbeat'    => time() - WPAgent_Daemon::DEFAULT_WATCHDOG_STALE - 5,
    'started_at'   => current_time( 'mysql', true ),
    'pcntl'        => true,
    'last_event'   => 'heartbeat',
), false );

$stale = WPAgent_Daemon::status();
wp_agent_daemon_status_assert( empty( $stale['running'] ), 'Stale heartbeat with an unverifiable PID should not report running.' );
wp_agent_daemon_status_assert( 'stopped' === (string) $stale['status'], 'Stale heartbeat should report stopped.' );
wp_agent_daemon_status_assert( 'stale_heartbeat' === ( $stale['liveness_source'] ?? '' ), 'Stale heartbeat status should explain stale liveness.' );

$restore();

echo wp_json_encode( array(
    'success'      => true,
    'fresh_status' => array(
        'running'      => (bool) $fresh['running'],
        'pid_verified' => (bool) $fresh['pid_verified'],
        'status'       => $fresh['status'],
        'liveness'     => $fresh['liveness_source'],
    ),
    'stale_status' => array(
        'running'      => (bool) $stale['running'],
        'pid_verified' => (bool) $stale['pid_verified'],
        'status'       => $stale['status'],
        'liveness'     => $stale['liveness_source'],
    ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
