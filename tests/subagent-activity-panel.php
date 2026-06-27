<?php
/**
 * WP Agent Agent-workspace activity payload (Codex panel) check.
 *
 * Drives a delegation with a stubbed model and asserts the webhook's
 * run_activity_payload() surfaces the run-event timeline and the sub-agent tree
 * that the Agent workspace renders.
 *
 * wp eval-file wp-content/plugins/wp-agent/tests/subagent-activity-panel.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_act_runs']  = array();
$GLOBALS['wp_agent_act_convs'] = array();
$GLOBALS['wp_agent_act_previous'] = array(
    'mode'          => get_option( 'wp_agent_mode', 'author' ),
    'api_key'       => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'         => WPAgent::get_option( 'meowl_model', '' ),
    'budget'        => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists' => false !== get_option( 'wp_agent_monthly_budget', false ),
);

function wp_agent_act_cleanup() {
    global $wpdb;
    foreach ( array_unique( $GLOBALS['wp_agent_act_runs'] ) as $rid ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $rid ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => (int) $rid ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $rid ), array( '%d' ) );
    }
    foreach ( array_unique( $GLOBALS['wp_agent_act_convs'] ) as $cid ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $cid ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $cid ), array( '%d' ) );
    }
    $previous = $GLOBALS['wp_agent_act_previous'];
    update_option( 'wp_agent_mode', $previous['mode'] );
    WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
    WPAgent::update_option( 'meowl_model', $previous['model'] );
    if ( $previous['budget_exists'] ) {
        update_option( 'wp_agent_monthly_budget', $previous['budget'] );
    } else {
        delete_option( 'wp_agent_monthly_budget' );
    }
    WPAgent_Roles::ensure();
}
register_shutdown_function( 'wp_agent_act_cleanup' );

function wp_agent_act_fail( $m ) {
    wp_agent_act_cleanup();
    fwrite( STDERR, "FAIL: $m\n" );
    exit( 1 );
}
function wp_agent_act_assert( $c, $m ) {
    if ( ! $c ) {
        wp_agent_act_fail( $m );
    }
}

function wp_agent_act_response( $message, $finish = 'stop' ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'id'      => 'chatcmpl-act',
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => 'wp-agent-act-model',
            'choices' => array( array( 'index' => 0, 'message' => $message, 'finish_reason' => $finish ) ),
            'usage'   => array( 'prompt_tokens' => 12, 'completion_tokens' => 5, 'total_tokens' => 17 ),
        ) ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
    );
}
function wp_agent_act_tool_call( $id, $name, $arguments ) {
    return array( 'id' => $id, 'type' => 'function', 'function' => array( 'name' => $name, 'arguments' => wp_json_encode( $arguments ) ) );
}

update_option( 'wp_agent_mode', 'administrator' );
WPAgent_Roles::ensure();
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-act-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-act-model' );
update_option( 'wp_agent_monthly_budget', 0 );

add_filter( 'pre_http_request', function( $preempt, $args, $url ) {
    if ( false === strpos( (string) $url, '/chat/completions' ) ) {
        return $preempt;
    }
    $body = isset( $args['body'] ) ? (string) $args['body'] : '';
    if ( false !== strpos( $body, 'subagent_results' ) ) {
        return wp_agent_act_response( array( 'role' => 'assistant', 'content' => 'PARENT_FINAL combined.' ) );
    }
    if ( false !== strpos( $body, 'SUBTASK_ALPHA' ) && false === strpos( $body, 'PARENT_GOAL' ) ) {
        return wp_agent_act_response( array( 'role' => 'assistant', 'content' => 'ALPHA_SUMMARY done.' ) );
    }
    if ( false !== strpos( $body, 'SUBTASK_BETA' ) && false === strpos( $body, 'PARENT_GOAL' ) ) {
        return wp_agent_act_response( array( 'role' => 'assistant', 'content' => 'BETA_SUMMARY done.' ) );
    }
    return wp_agent_act_response(
        array(
            'role'       => 'assistant',
            'content'    => 'Delegating.',
            'tool_calls' => array(
                wp_agent_act_tool_call( 'call_d', 'delegate', array( 'tasks' => array(
                    array( 'goal' => 'SUBTASK_ALPHA: research', 'label' => 'Research', 'tools' => array( 'web' ) ),
                    array( 'goal' => 'SUBTASK_BETA: draft', 'label' => 'Draft' ),
                ) ) ),
            ),
        ),
        'tool_calls'
    );
}, 10, 3 );

$conversation = new WPAgent_Conversation();
$parent_conv  = $conversation->get_or_create( 1, 'agent', 'act-parent-' . wp_generate_uuid4() );
$GLOBALS['wp_agent_act_convs'][] = (int) $parent_conv;
$parent_msg = $conversation->add_message( $parent_conv, 'user', 'PARENT_GOAL: split into sub-tasks.' );
$parent_run = (int) WPAgent_Runs::create( $parent_conv, 1, $parent_msg, 'agent' );
$GLOBALS['wp_agent_act_runs'][] = $parent_run;

// Reflection accessor for the private activity payload builder the UI consumes.
$handler = new WPAgent_Webhook_Handler();
$method  = new ReflectionMethod( 'WPAgent_Webhook_Handler', 'run_activity_payload' );
$method->setAccessible( true );
$activity_of = function( $run_id ) use ( $handler, $method ) {
    return $method->invoke( $handler, (int) $run_id );
};

// Step 1: parent delegates and pauses.
WPAgent_Worker::run_once( $parent_run );
$parent = WPAgent_Runs::get( $parent_run );
wp_agent_act_assert( $parent && 'awaiting_subagents' === (string) $parent->status, 'Parent should pause in awaiting_subagents.' );

$mid_activity = $activity_of( $parent_run );
wp_agent_act_assert( isset( $mid_activity['subagents'] ) && 2 === count( $mid_activity['subagents'] ), 'Activity should expose two sub-agents while paused.' );
$labels = array_map( function( $s ) { return $s['label']; }, $mid_activity['subagents'] );
wp_agent_act_assert( in_array( 'Research', $labels, true ) && in_array( 'Draft', $labels, true ), 'Sub-agent labels should be surfaced to the panel.' );
$mid_types = array_map( function( $e ) { return $e['type']; }, $mid_activity['events'] );
wp_agent_act_assert( in_array( 'subagent_started', $mid_types, true ), 'Timeline should include subagent_started events.' );
wp_agent_act_assert( in_array( 'awaiting_subagents', $mid_types, true ), 'Timeline should record the awaiting_subagents pause.' );

// Collect + drain children, then resume parent.
foreach ( WPAgent_Runs::children_of( $parent_run ) as $child ) {
    $GLOBALS['wp_agent_act_runs'][]  = (int) $child->id;
    $GLOBALS['wp_agent_act_convs'][] = (int) $child->conversation_id;
    for ( $j = 0; $j < 6; $j++ ) {
        $fresh = WPAgent_Runs::get( (int) $child->id );
        if ( ! $fresh || in_array( (string) $fresh->status, array( 'done', 'error', 'canceled' ), true ) ) {
            break;
        }
        WPAgent_Worker::run_once( (int) $child->id );
    }
}
for ( $k = 0; $k < 4; $k++ ) {
    $parent = WPAgent_Runs::get( $parent_run );
    if ( $parent && 'done' === (string) $parent->status ) {
        break;
    }
    WPAgent_Worker::run_once( $parent_run );
}

$final_activity = $activity_of( $parent_run );
$final_types = array_map( function( $e ) { return $e['type']; }, $final_activity['events'] );
wp_agent_act_assert( in_array( 'subagent_group_complete', $final_types, true ), 'Timeline should record sub-agents finishing.' );
$done = 0;
$has_summary = 0;
foreach ( $final_activity['subagents'] as $sa ) {
    if ( 'done' === $sa['status'] ) {
        $done++;
    }
    if ( false !== strpos( $sa['summary'], 'SUMMARY' ) ) {
        $has_summary++;
    }
}
wp_agent_act_assert( 2 === $done, 'Both sub-agents should show as done in the panel.' );
wp_agent_act_assert( 2 === $has_summary, 'Each sub-agent should expose its summary to the panel.' );

wp_agent_act_cleanup();

echo wp_json_encode( array(
    'ok'                => true,
    'subagents'         => 2,
    'labels_surfaced'   => true,
    'timeline_events'   => count( $final_activity['events'] ),
    'group_complete'    => true,
) ) . "\n";
exit( 0 );
