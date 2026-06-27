<?php
/**
 * WP Agent iteration-budget (Hermes-style per-request turn cap) checks.
 *
 * Verifies the configurable agent iteration cap: default 100, override,
 * 0 = unlimited, the background-unlimited toggle for scheduled runs, and the
 * per-child sub-agent budget carried in tool_policy_json.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/agent-iteration-budget.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_iter_budget_previous'] = array(
    'max'        => array( 'sentinel' => '__missing__', 'value' => get_option( 'wp_agent_max_iterations', '__missing__' ) ),
    'background' => array( 'sentinel' => '__missing__', 'value' => get_option( 'wp_agent_background_iterations_unlimited', '__missing__' ) ),
);
$GLOBALS['wp_agent_iter_budget_restored'] = false;

function wp_agent_iter_budget_cleanup() {
    if ( ! empty( $GLOBALS['wp_agent_iter_budget_restored'] ) ) {
        return;
    }
    foreach ( array(
        'max'        => 'wp_agent_max_iterations',
        'background' => 'wp_agent_background_iterations_unlimited',
    ) as $key => $option ) {
        $previous = $GLOBALS['wp_agent_iter_budget_previous'][ $key ];
        if ( $previous['sentinel'] === $previous['value'] ) {
            delete_option( $option );
        } else {
            update_option( $option, $previous['value'], false );
        }
    }
    $GLOBALS['wp_agent_iter_budget_restored'] = true;
}
register_shutdown_function( 'wp_agent_iter_budget_cleanup' );

function wp_agent_iter_budget_fail( $message ) {
    wp_agent_iter_budget_cleanup();
    fwrite( STDERR, 'FAIL: ' . $message . "\n" );
    exit( 1 );
}
function wp_agent_iter_budget_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_iter_budget_fail( $message );
    }
}

// The option-based assertions assume no constant/env override is in effect.
wp_agent_iter_budget_assert(
    ! defined( 'WP_AGENT_MAX_ITERATIONS' ) && false === getenv( 'WP_AGENT_MAX_ITERATIONS' ),
    'Test requires the WP_AGENT_MAX_ITERATIONS constant/env override to be unset.'
);

// 1. Default cap is 100 when unconfigured.
delete_option( 'wp_agent_max_iterations' );
delete_option( 'wp_agent_background_iterations_unlimited' );
wp_agent_iter_budget_assert( 100 === WPAgent_Agent::DEFAULT_MAX_ITERATIONS, 'DEFAULT_MAX_ITERATIONS should be 100.' );
wp_agent_iter_budget_assert( 100 === WPAgent_Agent::max_iterations(), 'Unconfigured max_iterations() should default to 100.' );

// 2. A configured integer is respected.
update_option( 'wp_agent_max_iterations', 5, false );
wp_agent_iter_budget_assert( 5 === WPAgent_Agent::max_iterations(), 'Configured cap of 5 should be respected.' );

// 3. Zero means unlimited.
update_option( 'wp_agent_max_iterations', 0, false );
wp_agent_iter_budget_assert( 0 === WPAgent_Agent::max_iterations(), 'Cap of 0 should mean unlimited.' );

// 4. effective_max_iterations_for_run honors channel + the background toggle.
update_option( 'wp_agent_max_iterations', 7, false );

$webchat_run  = (object) array( 'channel' => 'webchat' );
$schedule_run = (object) array( 'channel' => 'schedule' );

wp_agent_iter_budget_assert( 7 === WPAgent_Agent::effective_max_iterations_for_run( $webchat_run ), 'Interactive run should use the global cap.' );

delete_option( 'wp_agent_background_iterations_unlimited' );
wp_agent_iter_budget_assert( 7 === WPAgent_Agent::effective_max_iterations_for_run( $schedule_run ), 'Scheduled run should be bounded when background-unlimited is off.' );

update_option( 'wp_agent_background_iterations_unlimited', '1', false );
wp_agent_iter_budget_assert( 0 === WPAgent_Agent::effective_max_iterations_for_run( $schedule_run ), 'Scheduled run should be unlimited when background-unlimited is on.' );
wp_agent_iter_budget_assert( 7 === WPAgent_Agent::effective_max_iterations_for_run( $webchat_run ), 'Interactive run should stay bounded even when background-unlimited is on.' );

// 5. A child sub-agent budget in tool_policy_json overrides everything.
$child_run = (object) array( 'channel' => 'schedule', 'tool_policy_json' => wp_json_encode( array( 'max_iterations' => 50 ) ) );
wp_agent_iter_budget_assert( 50 === WPAgent_Agent::effective_max_iterations_for_run( $child_run ), 'Child run should use its explicit policy budget.' );

$child_unlimited = (object) array( 'channel' => 'webchat', 'tool_policy_json' => wp_json_encode( array( 'max_iterations' => 0 ) ) );
wp_agent_iter_budget_assert( 0 === WPAgent_Agent::effective_max_iterations_for_run( $child_unlimited ), 'Child run with a 0 policy budget should be unlimited.' );

// 6. The forced-summary entry points exist.
wp_agent_iter_budget_assert( method_exists( 'WPAgent_Agent', 'run_summary_step' ), 'run_summary_step() must exist for forced summaries.' );

wp_agent_iter_budget_cleanup();

echo wp_json_encode( array(
    'ok'                => true,
    'default'           => 100,
    'configured'        => 5,
    'unlimited'         => 0,
    'child_budget'      => 50,
    'background_toggle' => true,
) ) . "\n";
exit( 0 );
