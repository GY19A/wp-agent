<?php
/**
 * WP Agent run-event telemetry checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/run-event-telemetry.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This run-event telemetry script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_telemetry_run_id']          = 0;
$GLOBALS['wp_agent_telemetry_conversation_id'] = 0;
$GLOBALS['wp_agent_telemetry_previous_mode']   = get_option( 'wp_agent_mode', 'author' );
$GLOBALS['wp_agent_telemetry_previous']        = array(
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$GLOBALS['wp_agent_telemetry_model_calls']     = 0;

function wp_agent_telemetry_cleanup() {
    global $wpdb;

    if ( $GLOBALS['wp_agent_telemetry_run_id'] > 0 ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $GLOBALS['wp_agent_telemetry_run_id'] ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => (int) $GLOBALS['wp_agent_telemetry_run_id'] ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $GLOBALS['wp_agent_telemetry_run_id'] ), array( '%d' ) );
    }

    if ( $GLOBALS['wp_agent_telemetry_conversation_id'] > 0 ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $GLOBALS['wp_agent_telemetry_conversation_id'] ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $GLOBALS['wp_agent_telemetry_conversation_id'] ), array( '%d' ) );
    }

    update_option( 'wp_agent_mode', $GLOBALS['wp_agent_telemetry_previous_mode'] );
    WPAgent::update_option( 'meowl_api_key', $GLOBALS['wp_agent_telemetry_previous']['api_key'] );
    WPAgent::update_option( 'meowl_model', $GLOBALS['wp_agent_telemetry_previous']['model'] );
    if ( $GLOBALS['wp_agent_telemetry_previous']['budget_exists'] ) {
        update_option( 'wp_agent_monthly_budget', $GLOBALS['wp_agent_telemetry_previous']['monthly_budget'] );
    } else {
        delete_option( 'wp_agent_monthly_budget' );
    }
    WPAgent_Roles::ensure();
}

function wp_agent_telemetry_fail( $message ) {
    wp_agent_telemetry_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_telemetry_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_telemetry_fail( $message );
    }
}

function wp_agent_telemetry_response( $message, $finish_reason = 'stop' ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'id'      => 'chatcmpl-wp-agent-run-telemetry',
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => 'wp-agent-telemetry-model',
            'choices' => array(
                array(
                    'index'         => 0,
                    'message'       => $message,
                    'finish_reason' => $finish_reason,
                ),
            ),
            'usage'   => array(
                'prompt_tokens'     => 21,
                'completion_tokens' => 9,
                'total_tokens'      => 30,
            ),
        ) ),
        'response' => array(
            'code'    => 200,
            'message' => 'OK',
        ),
        'cookies'  => array(),
    );
}

function wp_agent_telemetry_tool_call( $id, $name, $arguments ) {
    return array(
        'id'       => $id,
        'type'     => 'function',
        'function' => array(
            'name'      => $name,
            'arguments' => wp_json_encode( $arguments ),
        ),
    );
}

function wp_agent_telemetry_recent_metadata( $run_id, $event_type ) {
    $events = WPAgent_Run_Events::recent( $run_id, 50 );
    $items  = array();
    foreach ( $events as $event ) {
        if ( $event_type !== ( $event['event_type'] ?? '' ) ) {
            continue;
        }
        $metadata = json_decode( (string) ( $event['metadata'] ?? '' ), true );
        $items[]  = is_array( $metadata ) ? $metadata : array();
    }
    return array_reverse( $items );
}

register_shutdown_function( 'wp_agent_telemetry_cleanup' );

global $wpdb;

update_option( 'wp_agent_mode', 'administrator' );
WPAgent_Roles::ensure();
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-test-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-telemetry-model' );
WPAgent::update_option( 'monthly_budget', 0 );

add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) {
        if ( false === strpos( (string) $url, '/chat/completions' ) ) {
            return $preempt;
        }

        $GLOBALS['wp_agent_telemetry_model_calls']++;
        if ( 1 === (int) $GLOBALS['wp_agent_telemetry_model_calls'] ) {
            return wp_agent_telemetry_response(
                array(
                    'role'       => 'assistant',
                    'content'    => 'Inspecting runtime telemetry.',
                    'tool_calls' => array(
                        wp_agent_telemetry_tool_call( 'call_runtime_status', 'runtime', array( 'action' => 'status' ) ),
                    ),
                ),
                'tool_calls'
            );
        }

        return wp_agent_telemetry_response(
            array(
                'role'    => 'assistant',
                'content' => 'Telemetry acceptance completed.',
            )
        );
    },
    10,
    3
);

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( 1, 'wpcli', 'run-event-telemetry-' . wp_generate_uuid4() );
wp_agent_telemetry_assert( $conversation_id > 0, 'Conversation should be created.' );
$GLOBALS['wp_agent_telemetry_conversation_id'] = (int) $conversation_id;

$message_id = $conversation->add_message( $conversation_id, 'user', 'Run one runtime tool call and finish so telemetry can be inspected.' );
wp_agent_telemetry_assert( $message_id > 0, 'User message should be created.' );

$run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
wp_agent_telemetry_assert( $run_id > 0, 'Run should be created.' );
$GLOBALS['wp_agent_telemetry_run_id'] = (int) $run_id;

$first_step = WPAgent_Worker::run_once( $run_id );
wp_agent_telemetry_assert( empty( $first_step['idle'] ) && 'running' === ( $first_step['status'] ?? '' ), 'First worker step should execute the runtime tool and keep the run running.' );

$second_step = WPAgent_Worker::run_once( $run_id );
wp_agent_telemetry_assert( empty( $second_step['idle'] ) && 'done' === ( $second_step['status'] ?? '' ), 'Second worker step should finish the run.' );

$run = WPAgent_Runs::get( $run_id );
wp_agent_telemetry_assert( $run && 'done' === (string) $run->status, 'Run should be done.' );

$model_events = wp_agent_telemetry_recent_metadata( $run_id, 'model_call' );
$tool_events  = wp_agent_telemetry_recent_metadata( $run_id, 'tool_call' );

wp_agent_telemetry_assert( 2 === count( $model_events ), 'Run should have two model_call telemetry events.' );
wp_agent_telemetry_assert( 1 === count( $tool_events ), 'Run should have one tool_call telemetry event.' );

$first_model = $model_events[0];
wp_agent_telemetry_assert( 'success' === ( $first_model['status'] ?? '' ), 'First model event should be successful.' );
wp_agent_telemetry_assert( 'meowl' === ( $first_model['provider'] ?? '' ), 'Model event should include provider.' );
wp_agent_telemetry_assert( 'wp-agent-telemetry-model' === ( $first_model['model'] ?? '' ), 'Model event should include response model.' );
wp_agent_telemetry_assert( (int) ( $first_model['duration_ms'] ?? -1 ) >= 0, 'Model event should include duration_ms.' );
wp_agent_telemetry_assert( 21 === (int) ( $first_model['tokens_in'] ?? 0 ), 'Model event should include input tokens.' );
wp_agent_telemetry_assert( 9 === (int) ( $first_model['tokens_out'] ?? 0 ), 'Model event should include output tokens.' );
wp_agent_telemetry_assert( 1 === (int) ( $first_model['tool_call_count'] ?? 0 ), 'First model event should include tool_call_count.' );

$tool_event = $tool_events[0];
wp_agent_telemetry_assert( 'runtime' === ( $tool_event['tool'] ?? '' ), 'Tool event should include tool name.' );
wp_agent_telemetry_assert( 'status' === ( $tool_event['action'] ?? '' ), 'Tool event should include action.' );
wp_agent_telemetry_assert( 'success' === ( $tool_event['status'] ?? '' ), 'Tool event should include successful status.' );
wp_agent_telemetry_assert( (int) ( $tool_event['duration_ms'] ?? -1 ) >= 0, 'Tool event should include duration_ms.' );
wp_agent_telemetry_assert( 'status' === ( $tool_event['params']['action'] ?? '' ), 'Tool event should include redacted params.' );

$event_types = wp_list_pluck( WPAgent_Run_Events::recent( $run_id, 20 ), 'event_type' );
wp_agent_telemetry_assert( in_array( 'claimed', $event_types, true ), 'Run should retain claimed events.' );
wp_agent_telemetry_assert( in_array( 'done', $event_types, true ), 'Run should retain done event.' );

$usage_rows = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    'wp-agent-telemetry-model'
) );
wp_agent_telemetry_assert( $usage_rows >= 2, 'Both model calls should record usage rows.' );

$result = array(
    'success'       => true,
    'run_id'        => (int) $run_id,
    'model_events'  => count( $model_events ),
    'tool_events'   => count( $tool_events ),
    'model_duration_ms' => (int) $first_model['duration_ms'],
    'tool_duration_ms'  => (int) $tool_event['duration_ms'],
    'tokens_in'     => (int) $first_model['tokens_in'],
    'tokens_out'    => (int) $first_model['tokens_out'],
    'event_types'   => array_values( array_unique( $event_types ) ),
);

wp_agent_telemetry_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
