<?php
/**
 * WP Agent rate-limit diagnostics checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/rate-limit-diagnostics.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This rate-limit diagnostics script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_rate_limit_user_id'] = 0;

function wp_agent_rate_limit_key( $user_id ) {
    return 'wp_agent_rl_' . wp_hash( (int) $user_id . '_' . gmdate( 'YmdH' ) );
}

function wp_agent_rate_limit_cleanup() {
    if ( $GLOBALS['wp_agent_rate_limit_user_id'] > 0 ) {
        delete_transient( wp_agent_rate_limit_key( (int) $GLOBALS['wp_agent_rate_limit_user_id'] ) );
        wp_delete_user( (int) $GLOBALS['wp_agent_rate_limit_user_id'] );
    }
    wp_set_current_user( 0 );
}

function wp_agent_rate_limit_fail( $message ) {
    wp_agent_rate_limit_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_rate_limit_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_rate_limit_fail( $message );
    }
}

register_shutdown_function( 'wp_agent_rate_limit_cleanup' );

$user_id = wp_insert_user( array(
    'user_login'   => 'wp-agent-rate-limit-' . strtolower( wp_generate_password( 8, false, false ) ),
    'user_email'   => 'wp-agent-rate-limit-' . wp_generate_uuid4() . '@example.test',
    'user_pass'    => wp_generate_password( 20, true, true ),
    'display_name' => 'WP Agent Rate Limit Fixture',
    'role'         => 'subscriber',
) );
wp_agent_rate_limit_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Temporary rate-limit user was not created.' );
$GLOBALS['wp_agent_rate_limit_user_id'] = (int) $user_id;

delete_transient( wp_agent_rate_limit_key( (int) $user_id ) );

$permissions = new WPAgent_Permissions();
$initial = $permissions->rate_limit_status( (int) $user_id );
wp_agent_rate_limit_assert( WPAgent_Permissions::RATE_LIMIT_PER_HOUR === (int) $initial['limit_per_hour'], 'Rate-limit status should expose the configured hourly limit.' );
wp_agent_rate_limit_assert( 0 === (int) $initial['used'], 'Fresh rate-limit window should start at zero.' );
wp_agent_rate_limit_assert( WPAgent_Permissions::RATE_LIMIT_PER_HOUR === (int) $initial['remaining'], 'Fresh rate-limit window should expose full remaining allowance.' );
wp_agent_rate_limit_assert( empty( $initial['limited'] ), 'Fresh rate-limit window should not be limited.' );
wp_agent_rate_limit_assert( ! empty( $initial['reset_at'] ), 'Rate-limit status should expose reset time.' );

for ( $i = 0; $i < WPAgent_Permissions::RATE_LIMIT_PER_HOUR; $i++ ) {
    wp_agent_rate_limit_assert( $permissions->check_rate_limit( (int) $user_id ), 'Rate-limit check should allow request #' . ( $i + 1 ) . '.' );
}

$exhausted = $permissions->rate_limit_status( (int) $user_id );
wp_agent_rate_limit_assert( WPAgent_Permissions::RATE_LIMIT_PER_HOUR === (int) $exhausted['used'], 'Exhausted status should report all requests used.' );
wp_agent_rate_limit_assert( 0 === (int) $exhausted['remaining'], 'Exhausted status should report zero remaining requests.' );
wp_agent_rate_limit_assert( ! empty( $exhausted['limited'] ), 'Exhausted status should report limited=true.' );
wp_agent_rate_limit_assert( ! $permissions->check_rate_limit( (int) $user_id ), 'Request after the hourly limit should be rejected.' );
wp_agent_rate_limit_assert( 0 === $permissions->get_remaining_rate( (int) $user_id ), 'Remaining helper should report zero after exhaustion.' );

wp_set_current_user( (int) $user_id );
$diagnostics = WPAgent_Diagnostics::runtime();
$diag_rate   = $diagnostics['security']['rate_limit'] ?? array();
wp_agent_rate_limit_assert( (int) $user_id === (int) ( $diag_rate['user_id'] ?? 0 ), 'Diagnostics should report the current user rate-limit subject.' );
wp_agent_rate_limit_assert( WPAgent_Permissions::RATE_LIMIT_PER_HOUR === (int) ( $diag_rate['limit_per_hour'] ?? 0 ), 'Diagnostics should expose hourly rate-limit ceiling.' );
wp_agent_rate_limit_assert( 0 === (int) ( $diag_rate['remaining'] ?? -1 ), 'Diagnostics should expose exhausted remaining allowance.' );
wp_agent_rate_limit_assert( ! empty( $diag_rate['limited'] ), 'Diagnostics should expose limited=true after exhaustion.' );

delete_transient( wp_agent_rate_limit_key( (int) $user_id ) );
$reset = $permissions->rate_limit_status( (int) $user_id );
wp_agent_rate_limit_assert( 0 === (int) $reset['used'], 'Deleting the transient should restore a fresh window.' );
wp_agent_rate_limit_assert( WPAgent_Permissions::RATE_LIMIT_PER_HOUR === (int) $reset['remaining'], 'Fresh window after cleanup should restore remaining allowance.' );

$result = array(
    'success'        => true,
    'user_id'        => (int) $user_id,
    'limit_per_hour' => (int) $initial['limit_per_hour'],
    'used_at_limit'  => (int) $exhausted['used'],
    'remaining'      => (int) $reset['remaining'],
    'diagnostics'    => $diag_rate,
);

wp_agent_rate_limit_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
