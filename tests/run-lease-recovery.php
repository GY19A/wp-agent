<?php
/**
 * WP Agent run lease recovery checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/run-lease-recovery.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This run lease recovery script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_run_lease_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_run_lease_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_run_lease_fail( $message );
    }
}

global $wpdb;

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( 1, 'wpcli', 'run-lease-recovery-' . wp_generate_uuid4() );
wp_agent_run_lease_assert( $conversation_id > 0, 'Conversation should be created.' );

$message_id = $conversation->add_message( $conversation_id, 'user', 'Run lease recovery acceptance fixture.' );
wp_agent_run_lease_assert( $message_id > 0, 'Message should be created.' );

$run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
wp_agent_run_lease_assert( $run_id > 0, 'Run should be queued.' );

$initial = WPAgent_Runs::get( $run_id );
wp_agent_run_lease_assert( $initial && 'queued' === $initial->status, 'Run should start queued.' );
wp_agent_run_lease_assert( WPAgent_Runs::claim( $run_id ), 'Initial claim should succeed.' );

$claimed = WPAgent_Runs::get( $run_id );
wp_agent_run_lease_assert( $claimed && 'running' === $claimed->status, 'Claimed run should be running.' );
wp_agent_run_lease_assert( ! empty( $claimed->locked_until ) && strtotime( $claimed->locked_until ) > time(), 'Claim should set a future lock.' );
wp_agent_run_lease_assert( ! WPAgent_Runs::claim( $run_id ), 'Second claim should fail while lock is active.' );

$table = $wpdb->prefix . 'wp_agent_runs';
$expired_lock = gmdate( 'Y-m-d H:i:s', time() - 60 );
$wpdb->update(
    $table,
    array(
        'locked_until' => $expired_lock,
        'updated_at'   => gmdate( 'Y-m-d H:i:s', time() - 60 ),
    ),
    array( 'id' => $run_id ),
    array( '%s', '%s' ),
    array( '%d' )
);

$next = WPAgent_Runs::next_claimable();
wp_agent_run_lease_assert( $next && (int) $next->id === (int) $run_id, 'Expired running run should be next claimable.' );
wp_agent_run_lease_assert( WPAgent_Runs::claimable_count() >= 1, 'Expired running run should increase claimable count.' );
wp_agent_run_lease_assert( WPAgent_Runs::claim( $run_id ), 'Expired running run should be reclaimable.' );

$reclaimed = WPAgent_Runs::get( $run_id );
wp_agent_run_lease_assert( $reclaimed && 'running' === $reclaimed->status, 'Reclaimed run should remain running.' );
wp_agent_run_lease_assert( strtotime( $reclaimed->locked_until ) > time(), 'Reclaim should refresh lock.' );

$events = WPAgent_Run_Events::recent( $run_id, 20 );
$event_types = array_map(
    function( $event ) {
        return $event['event_type'] ?? '';
    },
    $events
);
wp_agent_run_lease_assert( in_array( 'lease_reclaimed', $event_types, true ), 'Reclaiming a stale lease should create a lease_reclaimed event.' );

WPAgent_Runs::release( $run_id );
$released = WPAgent_Runs::get( $run_id );
wp_agent_run_lease_assert( $released && 'running' === $released->status && empty( $released->locked_until ), 'Release should clear the running lock.' );

WPAgent_Runs::set_done( $run_id );
$done = WPAgent_Runs::get( $run_id );
wp_agent_run_lease_assert( $done && 'done' === $done->status && empty( $done->locked_until ), 'Done should clear lock and mark run complete.' );
wp_agent_run_lease_assert( ! WPAgent_Runs::claim( $run_id ), 'Completed run should not be claimable.' );

$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_run_lease_assert( isset( $diagnostics['queue']['lock_seconds'] ) && WPAgent_Runs::LOCK_SECONDS === (int) $diagnostics['queue']['lock_seconds'], 'Diagnostics should expose lock seconds.' );

echo wp_json_encode( array(
    'success'      => true,
    'run_id'       => $run_id,
    'events'       => $event_types,
    'lock_seconds' => (int) $diagnostics['queue']['lock_seconds'],
) ) . "\n";
