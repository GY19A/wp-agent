<?php
/**
 * WP Agent sub-agent delegation — full worker-driven integration check.
 *
 * Stubs the model via pre_http_request and drives WPAgent_Worker::run_once()
 * to prove: a parent run delegates, pauses (awaiting_subagents), children run
 * in isolated conversations on their own budget, and the parent resumes with
 * the aggregated sub-agent summaries injected as a tool result, then finishes.
 *
 * wp eval-file wp-content/plugins/wp-agent/tests/subagent-delegation.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_sa_runs']     = array();
$GLOBALS['wp_agent_sa_convs']    = array();
$GLOBALS['wp_agent_sa_calls']    = 0;
$GLOBALS['wp_agent_sa_previous'] = array(
    'mode'           => get_option( 'wp_agent_mode', 'author' ),
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'budget'         => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);

function wp_agent_sa_cleanup() {
    global $wpdb;
    foreach ( array_unique( $GLOBALS['wp_agent_sa_runs'] ) as $rid ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $rid ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => (int) $rid ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $rid ), array( '%d' ) );
    }
    foreach ( array_unique( $GLOBALS['wp_agent_sa_convs'] ) as $cid ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $cid ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $cid ), array( '%d' ) );
    }
    $previous = $GLOBALS['wp_agent_sa_previous'];
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
register_shutdown_function( 'wp_agent_sa_cleanup' );

function wp_agent_sa_fail( $m ) {
    wp_agent_sa_cleanup();
    fwrite( STDERR, "FAIL: $m\n" );
    exit( 1 );
}
function wp_agent_sa_assert( $c, $m ) {
    if ( ! $c ) {
        wp_agent_sa_fail( $m );
    }
}

function wp_agent_sa_response( $message, $finish = 'stop' ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'id'      => 'chatcmpl-wp-agent-subagent',
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => 'wp-agent-subagent-model',
            'choices' => array( array( 'index' => 0, 'message' => $message, 'finish_reason' => $finish ) ),
            'usage'   => array( 'prompt_tokens' => 18, 'completion_tokens' => 7, 'total_tokens' => 25 ),
        ) ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
    );
}

function wp_agent_sa_tool_call( $id, $name, $arguments ) {
    return array(
        'id'       => $id,
        'type'     => 'function',
        'function' => array( 'name' => $name, 'arguments' => wp_json_encode( $arguments ) ),
    );
}

update_option( 'wp_agent_mode', 'administrator' );
WPAgent_Roles::ensure();
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-subagent-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-subagent-model' );
update_option( 'wp_agent_monthly_budget', 0 );

add_filter(
    'pre_http_request',
    function( $preempt, $args, $url ) {
        if ( false === strpos( (string) $url, '/chat/completions' ) ) {
            return $preempt;
        }
        $GLOBALS['wp_agent_sa_calls']++;
        $body = isset( $args['body'] ) ? (string) $args['body'] : '';

        // Parent resume: the aggregated sub-agent results are in context.
        if ( false !== strpos( $body, 'subagent_results' ) ) {
            return wp_agent_sa_response( array( 'role' => 'assistant', 'content' => 'PARENT_FINAL: combined the sub-agent results into the deliverable.' ) );
        }
        // Child A (isolated conversation: contains its goal, not the parent goal).
        if ( false !== strpos( $body, 'SUBTASK_ALPHA' ) && false === strpos( $body, 'PARENT_GOAL' ) ) {
            return wp_agent_sa_response( array( 'role' => 'assistant', 'content' => 'ALPHA_SUMMARY: research complete.' ) );
        }
        // Child B.
        if ( false !== strpos( $body, 'SUBTASK_BETA' ) && false === strpos( $body, 'PARENT_GOAL' ) ) {
            return wp_agent_sa_response( array( 'role' => 'assistant', 'content' => 'BETA_SUMMARY: draft complete.' ) );
        }
        // Parent first turn: delegate two sub-tasks.
        return wp_agent_sa_response(
            array(
                'role'       => 'assistant',
                'content'    => 'Splitting the goal into sub-tasks.',
                'tool_calls' => array(
                    wp_agent_sa_tool_call( 'call_delegate_1', 'delegate', array( 'tasks' => array(
                        array( 'goal' => 'SUBTASK_ALPHA: research the topic', 'tools' => array( 'web' ) ),
                        array( 'goal' => 'SUBTASK_BETA: draft an outline' ),
                    ) ) ),
                ),
            ),
            'tool_calls'
        );
    },
    10,
    3
);

$conversation = new WPAgent_Conversation();
$parent_conv  = $conversation->get_or_create( 1, 'agent', 'sa-parent-' . wp_generate_uuid4() );
$GLOBALS['wp_agent_sa_convs'][] = (int) $parent_conv;
$parent_msg = $conversation->add_message( $parent_conv, 'user', 'PARENT_GOAL: build a big deliverable; split it into independent sub-tasks.' );
$parent_run = (int) WPAgent_Runs::create( $parent_conv, 1, $parent_msg, 'agent' );
$GLOBALS['wp_agent_sa_runs'][] = $parent_run;

// Drive the worker by TARGETING this test's own runs, so the test is robust to
// any unrelated runs already in the queue and never touches them.
// Step 1: advance the parent — it delegates two sub-tasks and pauses.
WPAgent_Worker::run_once( $parent_run );
$parent       = WPAgent_Runs::get( $parent_run );
$saw_awaiting = ( $parent && 'awaiting_subagents' === (string) $parent->status );

// Step 2: drain each child run to a terminal state.
foreach ( WPAgent_Runs::children_of( $parent_run ) as $child ) {
    $GLOBALS['wp_agent_sa_runs'][]  = (int) $child->id;
    $GLOBALS['wp_agent_sa_convs'][] = (int) $child->conversation_id;
    for ( $j = 0; $j < 6; $j++ ) {
        $fresh = WPAgent_Runs::get( (int) $child->id );
        if ( ! $fresh || in_array( (string) $fresh->status, array( 'done', 'error', 'canceled' ), true ) ) {
            break;
        }
        WPAgent_Worker::run_once( (int) $child->id );
    }
}

// Step 3: advance the parent again until it resumes and finishes.
for ( $k = 0; $k < 4; $k++ ) {
    $parent = WPAgent_Runs::get( $parent_run );
    if ( $parent && 'done' === (string) $parent->status ) {
        break;
    }
    WPAgent_Worker::run_once( $parent_run );
}

// --- assertions ---
wp_agent_sa_assert( $saw_awaiting, 'Parent run should pause in awaiting_subagents while sub-agents run.' );

$children = WPAgent_Runs::children_of( $parent_run );
wp_agent_sa_assert( 2 === count( $children ), 'Two child sub-agent runs should have been created.' );

$summaries = array();
foreach ( $children as $child ) {
    wp_agent_sa_assert( 'done' === (string) $child->status, 'Each sub-agent should finish (done). Got: ' . $child->status );
    wp_agent_sa_assert( (int) $child->conversation_id !== (int) $parent_conv, 'Sub-agent must run in its own conversation.' );
    wp_agent_sa_assert( (int) $child->user_id === 1, 'Sub-agent cost must be attributed to the requesting user.' );
    $summaries[] = (string) $child->result_summary;
}
$joined = implode( ' | ', $summaries );
wp_agent_sa_assert( false !== strpos( $joined, 'ALPHA_SUMMARY' ), 'Child A summary should be stored.' );
wp_agent_sa_assert( false !== strpos( $joined, 'BETA_SUMMARY' ), 'Child B summary should be stored.' );

$parent = WPAgent_Runs::get( $parent_run );
wp_agent_sa_assert( 'done' === (string) $parent->status, 'Parent run should finish after sub-agents complete. Got: ' . $parent->status );

global $wpdb;
$parent_messages = $wpdb->get_results( $wpdb->prepare(
    "SELECT role, content FROM {$wpdb->prefix}wp_agent_messages WHERE conversation_id = %d ORDER BY id ASC",
    (int) $parent_conv
) );
$tool_payload = '';
$final_text   = '';
foreach ( $parent_messages as $m ) {
    if ( 'tool' === $m->role && false !== strpos( (string) $m->content, 'subagent_results' ) ) {
        $tool_payload = (string) $m->content;
    }
    if ( 'assistant' === $m->role && false !== strpos( (string) $m->content, 'PARENT_FINAL' ) ) {
        $final_text = (string) $m->content;
    }
}
wp_agent_sa_assert( '' !== $tool_payload, 'Parent conversation should receive the aggregated sub-agent results as a tool message.' );
wp_agent_sa_assert( false !== strpos( $tool_payload, 'ALPHA_SUMMARY' ) && false !== strpos( $tool_payload, 'BETA_SUMMARY' ), 'Aggregated tool result should contain both sub-agent summaries.' );
wp_agent_sa_assert( '' !== $final_text, 'Parent should produce a final response after consuming sub-agent results.' );

wp_agent_sa_cleanup();

echo wp_json_encode( array(
    'ok'             => true,
    'saw_awaiting'   => $saw_awaiting,
    'children'       => 2,
    'model_calls'    => (int) $GLOBALS['wp_agent_sa_calls'],
    'parent_done'    => true,
    'aggregated'     => true,
) ) . "\n";
exit( 0 );
