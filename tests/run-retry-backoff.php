<?php
/**
 * WP Agent durable run retry/backoff checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/run-retry-backoff.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This run retry/backoff script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_retry_previous'] = array(
    'base_url'       => get_option( 'wp_agent_ai_base_url', null ),
    'base_exists'    => false !== get_option( 'wp_agent_ai_base_url', false ),
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'mode'           => get_option( 'wp_agent_mode', 'author' ),
    'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$GLOBALS['wp_agent_retry_filter']          = null;
$GLOBALS['wp_agent_retry_delay_filter']    = null;
$GLOBALS['wp_agent_retry_conversation_id'] = 0;
$GLOBALS['wp_agent_retry_run_id']          = 0;
$GLOBALS['wp_agent_retry_responses']       = array();

function wp_agent_retry_cleanup() {
    global $wpdb;

    if ( null !== $GLOBALS['wp_agent_retry_filter'] ) {
        remove_filter( 'pre_http_request', $GLOBALS['wp_agent_retry_filter'], 10 );
        $GLOBALS['wp_agent_retry_filter'] = null;
    }
    if ( null !== $GLOBALS['wp_agent_retry_delay_filter'] ) {
        remove_filter( 'wp_agent_run_retry_delay', $GLOBALS['wp_agent_retry_delay_filter'], 10 );
        $GLOBALS['wp_agent_retry_delay_filter'] = null;
    }

    $run_id          = (int) $GLOBALS['wp_agent_retry_run_id'];
    $conversation_id = (int) $GLOBALS['wp_agent_retry_conversation_id'];
    if ( $run_id > 0 ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => $run_id ), array( '%d' ) );
    }
    if ( $conversation_id > 0 ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => $conversation_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => $conversation_id ), array( '%d' ) );
    }

    $previous = $GLOBALS['wp_agent_retry_previous'];
    if ( $previous['base_exists'] ) {
        update_option( 'wp_agent_ai_base_url', $previous['base_url'] );
    } else {
        delete_option( 'wp_agent_ai_base_url' );
    }
    WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
    WPAgent::update_option( 'meowl_model', $previous['model'] );
    update_option( 'wp_agent_mode', $previous['mode'] );
    if ( $previous['budget_exists'] ) {
        update_option( 'wp_agent_monthly_budget', $previous['monthly_budget'] );
    } else {
        delete_option( 'wp_agent_monthly_budget' );
    }
}

function wp_agent_retry_fail( $message ) {
    wp_agent_retry_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_retry_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_retry_fail( $message );
    }
}

function wp_agent_retry_response( $type ) {
    if ( 'success' === $type ) {
        return array(
            'headers'  => array(),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'body'     => wp_json_encode( array(
                'id'      => 'chatcmpl-wp-agent-run-retry',
                'object'  => 'chat.completion',
                'model'   => 'wp-agent-run-retry-model',
                'choices' => array(
                    array(
                        'message'       => array(
                            'role'    => 'assistant',
                            'content' => 'Recovered after durable retry.',
                        ),
                        'finish_reason' => 'stop',
                    ),
                ),
                'usage'   => array(
                    'prompt_tokens'     => 11,
                    'completion_tokens' => 4,
                ),
            ) ),
            'cookies'  => array(),
        );
    }

    return array(
        'headers'  => array(),
        'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
        'body'     => wp_json_encode( array(
            'error' => array(
                'message' => 'Temporary transport timeout from retry fixture.',
            ),
        ) ),
        'cookies'  => array(),
    );
}

register_shutdown_function( 'wp_agent_retry_cleanup' );

update_option( 'wp_agent_ai_base_url', 'https://run-retry.example.test/v1' );
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-run-retry-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-run-retry-model' );
WPAgent::update_option( 'monthly_budget', 0 );
update_option( 'wp_agent_mode', 'administrator' );

$GLOBALS['wp_agent_retry_responses'] = array( 'retryable_error', 'success' );
$GLOBALS['wp_agent_retry_filter'] = function( $preempt, $parsed_args, $url ) {
    if ( 0 !== strpos( (string) $url, 'https://run-retry.example.test/v1/chat/completions' ) ) {
        return $preempt;
    }

    $next = array_shift( $GLOBALS['wp_agent_retry_responses'] );
    return wp_agent_retry_response( $next ? $next : 'success' );
};
add_filter( 'pre_http_request', $GLOBALS['wp_agent_retry_filter'], 10, 3 );

$GLOBALS['wp_agent_retry_delay_filter'] = function() {
    return 60;
};
add_filter( 'wp_agent_run_retry_delay', $GLOBALS['wp_agent_retry_delay_filter'], 10, 4 );

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( 1, 'wpcli', 'run-retry-backoff-' . wp_generate_uuid4() );
wp_agent_retry_assert( $conversation_id > 0, 'Conversation should be created.' );
$GLOBALS['wp_agent_retry_conversation_id'] = $conversation_id;

$message_id = $conversation->add_message( $conversation_id, 'user', 'Exercise durable retry backoff.' );
wp_agent_retry_assert( $message_id > 0, 'Message should be created.' );

$run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
wp_agent_retry_assert( $run_id > 0, 'Run should be created.' );
$GLOBALS['wp_agent_retry_run_id'] = $run_id;

$first = WPAgent_Worker::run_once( $run_id );
wp_agent_retry_assert( 'retry_scheduled' === ( $first['status'] ?? '' ), 'First transient failure should schedule a retry.' );

$retrying = WPAgent_Runs::get( $run_id );
wp_agent_retry_assert( $retrying && 'queued' === (string) $retrying->status, 'Retryable failure should keep run queued.' );
wp_agent_retry_assert( 1 === (int) $retrying->attempt_count, 'Retryable failure should increment attempt count.' );
wp_agent_retry_assert( '' !== (string) $retrying->next_attempt_at, 'Retryable failure should set next_attempt_at.' );
wp_agent_retry_assert( strtotime( $retrying->next_attempt_at . ' UTC' ) > time(), 'Retry should be delayed into the future.' );
wp_agent_retry_assert( WPAgent_Runs::claimable_count() >= 0, 'Claimable count should be readable.' );

$next = WPAgent_Runs::next_claimable();
wp_agent_retry_assert( ! $next || (int) $next->id !== (int) $run_id, 'Backoff-delayed run should not be immediately claimable.' );

$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_retry_assert( (int) ( $diagnostics['queue']['retry_scheduled_count'] ?? 0 ) >= 1, 'Diagnostics should expose scheduled retry count.' );
wp_agent_retry_assert( '' !== (string) ( $diagnostics['queue']['next_retry_at'] ?? '' ), 'Diagnostics should expose next retry timestamp.' );
wp_agent_retry_assert( isset( $diagnostics['queue']['next_retry_in'] ) && (int) $diagnostics['queue']['next_retry_in'] >= 0, 'Diagnostics should expose next retry delay.' );

$events = WPAgent_Run_Events::recent( $run_id, 20 );
$event_types = array_map(
    function( $event ) {
        return $event['event_type'] ?? '';
    },
    $events
);
wp_agent_retry_assert( in_array( 'retry_scheduled', $event_types, true ), 'Retry scheduling should create a run event.' );

global $wpdb;
$wpdb->update(
    $wpdb->prefix . 'wp_agent_runs',
    array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
    array( 'id' => $run_id ),
    array( '%s' ),
    array( '%d' )
);

$second = WPAgent_Worker::run_once( $run_id );
wp_agent_retry_assert( 'done' === ( $second['status'] ?? '' ), 'Expired retry should run again and complete.' );

$done = WPAgent_Runs::get( $run_id );
wp_agent_retry_assert( $done && 'done' === (string) $done->status, 'Run should finish after retry.' );
wp_agent_retry_assert( 1 === (int) $done->attempt_count, 'Completed retry should retain attempt count for audit.' );
wp_agent_retry_assert( empty( $done->next_attempt_at ), 'Completed retry should clear next_attempt_at.' );

echo wp_json_encode( array(
    'success'          => true,
    'run_id'           => $run_id,
    'first_status'     => $first['status'] ?? '',
    'second_status'    => $second['status'] ?? '',
    'attempt_count'    => (int) $done->attempt_count,
    'retry_event_seen' => in_array( 'retry_scheduled', $event_types, true ),
) ) . "\n";
