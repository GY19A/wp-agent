<?php
/**
 * WP Agent schedule diagnostics checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/schedule-diagnostics.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This schedule diagnostics script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_schedule_diag_schedule_ids'] = array();

function wp_agent_schedule_diag_cleanup() {
    foreach ( $GLOBALS['wp_agent_schedule_diag_schedule_ids'] as $schedule_id ) {
        WPAgent_Schedules::delete( (int) $schedule_id );
    }
}

function wp_agent_schedule_diag_fail( $message ) {
    wp_agent_schedule_diag_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_schedule_diag_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_schedule_diag_fail( $message );
    }
}

function wp_agent_schedule_diag_admin_id() {
    $admin = get_user_by( 'login', 'admin' );
    if ( $admin ) {
        return (int) $admin->ID;
    }
    return 1;
}

function wp_agent_schedule_diag_snapshot() {
    $diagnostics = WPAgent_Diagnostics::runtime();
    return $diagnostics['schedules'] ?? array();
}

function wp_agent_schedule_diag_count( array $snapshot, $key, $nested = null ) {
    if ( null !== $nested ) {
        return (int) ( $snapshot[ $key ][ $nested ] ?? 0 );
    }
    return (int) ( $snapshot[ $key ] ?? 0 );
}

function wp_agent_schedule_diag_create( $user_id, $label, array $fields ) {
    global $wpdb;

    $schedule_id = WPAgent_Schedules::create(
        $user_id,
        'WP Agent schedule diagnostics fixture: ' . $label,
        'minutes',
        null,
        null,
        5
    );
    wp_agent_schedule_diag_assert( $schedule_id > 0, 'Schedule should be created for ' . $label . '.' );
    $GLOBALS['wp_agent_schedule_diag_schedule_ids'][] = $schedule_id;

    $formats = array();
    foreach ( $fields as $key => $value ) {
        $formats[] = in_array( $key, array( 'created_by', 'interval_minutes', 'last_run_id' ), true ) ? '%d' : '%s';
    }

    $wpdb->update(
        $wpdb->prefix . 'wp_agent_schedules',
        $fields,
        array( 'id' => (int) $schedule_id ),
        $formats,
        array( '%d' )
    );

    return $schedule_id;
}

register_shutdown_function( 'wp_agent_schedule_diag_cleanup' );

$user_id = wp_agent_schedule_diag_admin_id();
wp_set_current_user( $user_id );

$before = wp_agent_schedule_diag_snapshot();

wp_agent_schedule_diag_assert( array_key_exists( 'counts', $before ), 'Schedule diagnostics should expose status counts.' );
wp_agent_schedule_diag_assert( array_key_exists( 'last_status_counts', $before ), 'Schedule diagnostics should expose last-status counts.' );
wp_agent_schedule_diag_assert( array_key_exists( 'lock_seconds', $before ), 'Schedule diagnostics should expose lock window.' );
wp_agent_schedule_diag_assert( WPAgent_Schedules::LOCK_SECONDS === (int) $before['lock_seconds'], 'Schedule diagnostics should use schedule lock window.' );

$past        = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
$future      = gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS );
$stale_lock  = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );
$future_lock = gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS );

wp_agent_schedule_diag_create( $user_id, 'due', array(
    'status'        => 'active',
    'next_run'      => $past,
    'locked_until'  => null,
    'last_status'   => 'queued',
    'last_summary'  => 'Diagnostics due fixture.',
) );

wp_agent_schedule_diag_create( $user_id, 'due locked', array(
    'status'        => 'active',
    'next_run'      => $past,
    'locked_until'  => $future_lock,
    'last_status'   => 'running',
    'last_summary'  => 'Diagnostics due locked fixture.',
) );

wp_agent_schedule_diag_create( $user_id, 'stale lock', array(
    'status'        => 'active',
    'next_run'      => $past,
    'locked_until'  => $stale_lock,
    'last_status'   => 'error',
    'last_summary'  => 'Diagnostics stale lock fixture.',
) );

wp_agent_schedule_diag_create( $user_id, 'paused', array(
    'status'        => 'paused',
    'next_run'      => $future,
    'locked_until'  => null,
    'last_status'   => 'done',
    'last_summary'  => 'Diagnostics paused fixture.',
) );

$after = wp_agent_schedule_diag_snapshot();

wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'active_count' ) >= wp_agent_schedule_diag_count( $before, 'active_count' ) + 3,
    'Active schedule count should include the diagnostics fixtures.'
);
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'paused_count' ) >= wp_agent_schedule_diag_count( $before, 'paused_count' ) + 1,
    'Paused schedule count should include the diagnostics fixture.'
);
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'due_count' ) >= wp_agent_schedule_diag_count( $before, 'due_count' ) + 2,
    'Due schedule count should include unlocked and stale-lock due fixtures.'
);
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'locked_count' ) >= wp_agent_schedule_diag_count( $before, 'locked_count' ) + 1,
    'Locked schedule count should include the active lock fixture.'
);
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'due_locked_count' ) >= wp_agent_schedule_diag_count( $before, 'due_locked_count' ) + 1,
    'Due locked count should include the active lock fixture.'
);
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'stale_lock_count' ) >= wp_agent_schedule_diag_count( $before, 'stale_lock_count' ) + 1,
    'Stale lock count should include the expired lock fixture.'
);
wp_agent_schedule_diag_assert( '' !== (string) ( $after['oldest_due_at'] ?? '' ), 'Oldest due timestamp should be visible.' );
wp_agent_schedule_diag_assert( null !== ( $after['oldest_due_age'] ?? null ), 'Oldest due age should be visible.' );
wp_agent_schedule_diag_assert( '' !== (string) ( $after['next_lock_release_at'] ?? '' ), 'Next lock release timestamp should be visible.' );
wp_agent_schedule_diag_assert( null !== ( $after['next_lock_release_in'] ?? null ), 'Next lock release delay should be visible.' );
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'last_status_counts', 'queued' ) >= wp_agent_schedule_diag_count( $before, 'last_status_counts', 'queued' ) + 1,
    'Last-status queued count should include the diagnostics fixture.'
);
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'last_status_counts', 'running' ) >= wp_agent_schedule_diag_count( $before, 'last_status_counts', 'running' ) + 1,
    'Last-status running count should include the diagnostics fixture.'
);
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'last_status_counts', 'error' ) >= wp_agent_schedule_diag_count( $before, 'last_status_counts', 'error' ) + 1,
    'Last-status error count should include the diagnostics fixture.'
);
wp_agent_schedule_diag_assert(
    wp_agent_schedule_diag_count( $after, 'last_status_counts', 'done' ) >= wp_agent_schedule_diag_count( $before, 'last_status_counts', 'done' ) + 1,
    'Last-status done count should include the diagnostics fixture.'
);

$result = array(
    'success'            => true,
    'due_delta'          => wp_agent_schedule_diag_count( $after, 'due_count' ) - wp_agent_schedule_diag_count( $before, 'due_count' ),
    'locked_delta'       => wp_agent_schedule_diag_count( $after, 'locked_count' ) - wp_agent_schedule_diag_count( $before, 'locked_count' ),
    'due_locked_delta'   => wp_agent_schedule_diag_count( $after, 'due_locked_count' ) - wp_agent_schedule_diag_count( $before, 'due_locked_count' ),
    'stale_lock_delta'   => wp_agent_schedule_diag_count( $after, 'stale_lock_count' ) - wp_agent_schedule_diag_count( $before, 'stale_lock_count' ),
    'lock_seconds'       => (int) $after['lock_seconds'],
);

wp_agent_schedule_diag_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
