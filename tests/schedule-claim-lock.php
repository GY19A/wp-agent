<?php
/**
 * WP Agent schedule claim/lock checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/schedule-claim-lock.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This schedule claim lock script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_schedule_lock_schedule_ids']     = array();
$GLOBALS['wp_agent_schedule_lock_run_ids']          = array();
$GLOBALS['wp_agent_schedule_lock_conversation_ids'] = array();

function wp_agent_schedule_lock_cleanup() {
    global $wpdb;

    foreach ( $GLOBALS['wp_agent_schedule_lock_schedule_ids'] as $schedule_id ) {
        WPAgent_Schedules::delete( (int) $schedule_id );
    }
    foreach ( $GLOBALS['wp_agent_schedule_lock_run_ids'] as $run_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => (int) $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $run_id ), array( '%d' ) );
    }
    foreach ( $GLOBALS['wp_agent_schedule_lock_conversation_ids'] as $conversation_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $conversation_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $conversation_id ), array( '%d' ) );
    }
}

function wp_agent_schedule_lock_fail( $message ) {
    wp_agent_schedule_lock_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_schedule_lock_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_schedule_lock_fail( $message );
    }
}

function wp_agent_schedule_lock_admin_id() {
    $admin = get_user_by( 'login', 'admin' );
    if ( $admin ) {
        return (int) $admin->ID;
    }
    return 1;
}

function wp_agent_schedule_lock_force_due( $schedule_id, $locked_until = null ) {
    global $wpdb;

    $data = array(
        'next_run' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
    );
    $formats = array( '%s' );

    if ( null !== $locked_until ) {
        $data['locked_until'] = $locked_until;
        $formats[]           = '%s';
    }

    $wpdb->update(
        $wpdb->prefix . 'wp_agent_schedules',
        $data,
        array( 'id' => (int) $schedule_id ),
        $formats,
        array( '%d' )
    );
}

function wp_agent_schedule_lock_due_ids() {
    return array_map(
        'intval',
        wp_list_pluck( WPAgent_Schedules::due(), 'id' )
    );
}

register_shutdown_function( 'wp_agent_schedule_lock_cleanup' );

$user_id = wp_agent_schedule_lock_admin_id();
wp_set_current_user( $user_id );

$schedule_id = WPAgent_Schedules::create( $user_id, 'WP Agent schedule claim lock fixture.', 'minutes', null, null, 5 );
wp_agent_schedule_lock_assert( $schedule_id > 0, 'Schedule should be created.' );
$GLOBALS['wp_agent_schedule_lock_schedule_ids'][] = $schedule_id;

$future_lock = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
wp_agent_schedule_lock_force_due( $schedule_id, $future_lock );
wp_agent_schedule_lock_assert( ! in_array( $schedule_id, wp_agent_schedule_lock_due_ids(), true ), 'Active schedule lock should hide the due schedule.' );

$locked_result = WPAgent_Schedules::run( $schedule_id );
wp_agent_schedule_lock_assert( empty( $locked_result['ok'] ) && 'locked' === ( $locked_result['status'] ?? '' ), 'Locked schedule should not queue another run.' );
$locked_schedule = WPAgent_Schedules::get( $schedule_id );
wp_agent_schedule_lock_assert( $locked_schedule && empty( $locked_schedule->last_run_id ), 'Locked schedule should not write last_run_id.' );

$past_lock = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
wp_agent_schedule_lock_force_due( $schedule_id, $past_lock );
wp_agent_schedule_lock_assert( in_array( $schedule_id, wp_agent_schedule_lock_due_ids(), true ), 'Expired schedule lock should become due again.' );

$queued = WPAgent_Schedules::run( $schedule_id );
wp_agent_schedule_lock_assert( ! empty( $queued['ok'] ) && ! empty( $queued['run_id'] ), 'Expired-lock schedule should queue a run.' );
$run_id = (int) $queued['run_id'];
$GLOBALS['wp_agent_schedule_lock_run_ids'][] = $run_id;

$run = WPAgent_Runs::get( $run_id );
wp_agent_schedule_lock_assert( $run && 'schedule' === (string) $run->channel, 'Queued run should be a schedule run.' );
$GLOBALS['wp_agent_schedule_lock_conversation_ids'][] = (int) $run->conversation_id;

$queued_schedule = WPAgent_Schedules::get( $schedule_id );
wp_agent_schedule_lock_assert( $queued_schedule && (int) $queued_schedule->last_run_id === $run_id, 'Queued schedule should store last_run_id.' );
wp_agent_schedule_lock_assert( empty( $queued_schedule->locked_until ), 'Schedule lock should be released after successful queueing.' );
wp_agent_schedule_lock_assert( 'queued' === (string) $queued_schedule->last_status, 'Schedule should show queued after successful claim.' );

$missing_skill_schedule_id = WPAgent_Schedules::create( $user_id, 'WP Agent missing Skill lock fixture.', 'minutes', null, null, 5 );
wp_agent_schedule_lock_assert( $missing_skill_schedule_id > 0, 'Missing Skill fixture schedule should be created.' );
$GLOBALS['wp_agent_schedule_lock_schedule_ids'][] = $missing_skill_schedule_id;

global $wpdb;
$wpdb->update(
    $wpdb->prefix . 'wp_agent_schedules',
    array(
        'skill_slug'    => 'missing-skill-lock-fixture',
        'next_run'      => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
        'locked_until'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
    ),
    array( 'id' => (int) $missing_skill_schedule_id ),
    array( '%s', '%s', '%s' ),
    array( '%d' )
);

$error_result = WPAgent_Schedules::run( $missing_skill_schedule_id );
wp_agent_schedule_lock_assert( empty( $error_result['ok'] ) && 'error' === ( $error_result['status'] ?? '' ), 'Missing Skill schedule should fail cleanly.' );
$error_schedule = WPAgent_Schedules::get( $missing_skill_schedule_id );
wp_agent_schedule_lock_assert( $error_schedule && empty( $error_schedule->locked_until ), 'Schedule lock should be released after schedule build error.' );
wp_agent_schedule_lock_assert( 'error' === (string) $error_schedule->last_status, 'Schedule build error should be visible.' );
wp_agent_schedule_lock_assert( false !== strpos( (string) $error_schedule->last_summary, 'missing-skill-lock-fixture' ), 'Schedule build error should mention missing Skill slug.' );

$result = array(
    'success'                    => true,
    'locked_status'              => $locked_result['status'] ?? '',
    'queued_run_id'              => $run_id,
    'success_lock_released'      => empty( $queued_schedule->locked_until ),
    'error_lock_released'        => empty( $error_schedule->locked_until ),
    'schedule_lock_seconds'      => WPAgent_Schedules::LOCK_SECONDS,
);

wp_agent_schedule_lock_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
