<?php
/**
 * WP Agent sub-agent delegation guard checks (no AI calls).
 *
 * Verifies the delegate tool's guards and child-run shape without invoking the
 * model: sync-mode rejection, concurrency cap, depth cap, isolated child
 * conversations, restricted child tool policy, and cancellation cascade.
 *
 * wp eval-file wp-content/plugins/wp-agent/tests/subagent-guards.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_sa_guard_runs']  = array();
$GLOBALS['wp_agent_sa_guard_convs'] = array();

function wp_agent_sa_guard_cleanup() {
    global $wpdb;
    foreach ( array_unique( $GLOBALS['wp_agent_sa_guard_runs'] ) as $rid ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $rid ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $rid ), array( '%d' ) );
    }
    foreach ( array_unique( $GLOBALS['wp_agent_sa_guard_convs'] ) as $cid ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $cid ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $cid ), array( '%d' ) );
    }
}
register_shutdown_function( 'wp_agent_sa_guard_cleanup' );

function wp_agent_sa_guard_fail( $m ) {
    wp_agent_sa_guard_cleanup();
    fwrite( STDERR, "FAIL: $m\n" );
    exit( 1 );
}
function wp_agent_sa_guard_assert( $c, $m ) {
    if ( ! $c ) {
        wp_agent_sa_guard_fail( $m );
    }
}

$conv     = new WPAgent_Conversation();
$delegate = ( new WPAgent_Tools() )->get_tool( 'delegate' );
wp_agent_sa_guard_assert( $delegate instanceof WPAgent_Tool, 'delegate tool should be registered.' );

// --- sync-mode guard: no run_id -> friendly error, no children ---
$delegate->set_context( 1, 'webchat', 0, 1, 0 );
$res = $delegate->execute( array( 'tasks' => array( array( 'goal' => 'do something' ) ) ) );
wp_agent_sa_guard_assert( ! empty( $res['error'] ), 'Delegation without a run should error.' );

// --- parent run (depth 0) ---
$pconv = $conv->get_or_create( 1, 'agent', 'sa-guard-parent-' . uniqid() );
$GLOBALS['wp_agent_sa_guard_convs'][] = $pconv;
$pmsg = $conv->add_message( $pconv, 'user', 'PARENT' );
$prun = WPAgent_Runs::create( $pconv, 1, $pmsg, 'agent' );
$GLOBALS['wp_agent_sa_guard_runs'][] = $prun;

// --- concurrency cap: >3 tasks rejected, no children created ---
$delegate->set_context( 1, 'agent', $pconv, 1, $prun );
$res = $delegate->execute( array( 'tasks' => array(
    array( 'goal' => 'a' ), array( 'goal' => 'b' ), array( 'goal' => 'c' ), array( 'goal' => 'd' ),
) ) );
wp_agent_sa_guard_assert( ! empty( $res['error'] ), 'More than 3 sub-tasks should be rejected.' );
wp_agent_sa_guard_assert( 0 === count( WPAgent_Runs::children_of( $prun ) ), 'Rejected delegation should create no children.' );

// --- valid delegation: 2 children, isolated convs, restricted policy ---
$res = $delegate->execute( array( 'tasks' => array(
    array( 'goal' => 'GOAL_A', 'tools' => array( 'web' ) ),
    array( 'goal' => 'GOAL_B' ),
) ) );
wp_agent_sa_guard_assert( ! empty( $res['awaiting_subagents'] ), 'Valid delegation should return the awaiting_subagents sentinel.' );
$valid_group = (string) ( $res['subagent_group'] ?? '' );
wp_agent_sa_guard_assert( '' !== $valid_group, 'Delegation should return a subagent group id.' );

$children = WPAgent_Runs::children_of( $prun );
wp_agent_sa_guard_assert( 2 === count( $children ), 'Two children should be created.' );
foreach ( $children as $c ) {
    $GLOBALS['wp_agent_sa_guard_runs'][]  = (int) $c->id;
    $GLOBALS['wp_agent_sa_guard_convs'][] = (int) $c->conversation_id;
    wp_agent_sa_guard_assert( (int) $c->conversation_id !== (int) $pconv, 'Each child must have its own conversation.' );
    wp_agent_sa_guard_assert( 1 === (int) $c->depth, 'Child depth should be 1.' );
    wp_agent_sa_guard_assert( 'leaf' === (string) $c->role, 'Child role should be leaf.' );
    wp_agent_sa_guard_assert( (int) $c->parent_run_id === (int) $prun, 'Child must link to its parent.' );
    $policy = json_decode( (string) $c->tool_policy_json, true );
    wp_agent_sa_guard_assert( ! empty( $policy['restricted'] ), 'Child policy should be restricted.' );
    wp_agent_sa_guard_assert( 50 === (int) $policy['max_iterations'], 'Child budget should default to 50.' );
    foreach ( array( 'delegate', 'execute_code', 'manage_wp_agent_settings', 'manage_users' ) as $blocked ) {
        wp_agent_sa_guard_assert( ! in_array( $blocked, $policy['allowed_tools'], true ), "Child must not be allowed to use {$blocked}." );
    }
}
$policy_a = json_decode( (string) $children[0]->tool_policy_json, true );
wp_agent_sa_guard_assert( array( 'web' ) === array_values( $policy_a['allowed_tools'] ), 'Child A toolset should be exactly [web].' );

// --- depth cap: a depth-1 run cannot delegate further ---
$delegate->set_context( 1, 'agent', (int) $children[0]->conversation_id, 1, (int) $children[0]->id );
$res = $delegate->execute( array( 'tasks' => array( array( 'goal' => 'grandchild' ) ) ) );
wp_agent_sa_guard_assert( ! empty( $res['error'] ), 'A depth-1 sub-agent must not delegate further.' );
wp_agent_sa_guard_assert( 0 === count( WPAgent_Runs::children_of( (int) $children[0]->id ) ), 'No grandchildren should be created.' );

// --- cancellation cascade: parent awaiting_subagents is cancelable, children cascade ---
WPAgent_Runs::set_awaiting_subagents( $prun, $valid_group, 'toolcall_test' );
$parent = WPAgent_Runs::get( $prun );
wp_agent_sa_guard_assert( 'awaiting_subagents' === (string) $parent->status, 'Parent should be in awaiting_subagents.' );
wp_agent_sa_guard_assert( 'toolcall_test' === (string) $parent->parent_tool_call_id, 'Parent tool_call_id should be stored.' );

$canceled = WPAgent_Runs::cancel_if_active( $prun, 'test cancel' );
wp_agent_sa_guard_assert( $canceled, 'A parent awaiting sub-agents should be cancelable.' );
WPAgent_Runs::propagate_cancel( $prun );
foreach ( WPAgent_Runs::children_of( $prun ) as $c ) {
    $fresh = WPAgent_Runs::get( (int) $c->id );
    wp_agent_sa_guard_assert( 'canceled' === (string) $fresh->status, 'Children should be canceled with the parent.' );
}

wp_agent_sa_guard_cleanup();

echo wp_json_encode( array(
    'ok'              => true,
    'sync_rejected'   => true,
    'concurrency_cap' => 3,
    'children'        => 2,
    'depth_capped'    => true,
    'cancel_cascade'  => true,
) ) . "\n";
exit( 0 );
