<?php
/**
 * WP Agent schedule-to-run status synchronization checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/schedule-run-status-sync.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This schedule status sync script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_schedule_sync_previous'] = array(
    'base_url'       => get_option( 'wp_agent_ai_base_url', null ),
    'base_exists'    => false !== get_option( 'wp_agent_ai_base_url', false ),
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'mode'           => get_option( 'wp_agent_mode', 'author' ),
    'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$GLOBALS['wp_agent_schedule_sync_filter']           = null;
$GLOBALS['wp_agent_schedule_sync_schedule_ids']     = array();
$GLOBALS['wp_agent_schedule_sync_run_ids']          = array();
$GLOBALS['wp_agent_schedule_sync_conversation_ids'] = array();
$GLOBALS['wp_agent_schedule_sync_model']            = 'wp-agent-schedule-sync-model-' . strtolower( wp_generate_password( 6, false, false ) );

function wp_agent_schedule_sync_cleanup() {
    global $wpdb;

    if ( null !== $GLOBALS['wp_agent_schedule_sync_filter'] ) {
        remove_filter( 'pre_http_request', $GLOBALS['wp_agent_schedule_sync_filter'], 10 );
        $GLOBALS['wp_agent_schedule_sync_filter'] = null;
    }

    foreach ( $GLOBALS['wp_agent_schedule_sync_schedule_ids'] as $schedule_id ) {
        WPAgent_Schedules::delete( (int) $schedule_id );
    }
    foreach ( $GLOBALS['wp_agent_schedule_sync_run_ids'] as $run_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => (int) $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $run_id ), array( '%d' ) );
    }
    foreach ( $GLOBALS['wp_agent_schedule_sync_conversation_ids'] as $conversation_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $conversation_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $conversation_id ), array( '%d' ) );
    }
    $wpdb->delete( $wpdb->prefix . 'wp_agent_usage', array( 'model' => $GLOBALS['wp_agent_schedule_sync_model'] ), array( '%s' ) );

    $previous = $GLOBALS['wp_agent_schedule_sync_previous'];
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

function wp_agent_schedule_sync_fail( $message ) {
    wp_agent_schedule_sync_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_schedule_sync_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_schedule_sync_fail( $message );
    }
}

function wp_agent_schedule_sync_admin_id() {
    $admin = get_user_by( 'login', 'admin' );
    if ( $admin ) {
        return (int) $admin->ID;
    }
    return 1;
}

function wp_agent_schedule_sync_response( $ok, $content = '' ) {
    if ( ! $ok ) {
        return array(
            'headers'  => array(),
            'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
            'body'     => wp_json_encode( array(
                'error' => array(
                    'message' => 'Fixture HTTP 500 schedule status sync.',
                ),
            ) ),
            'cookies'  => array(),
        );
    }

    return array(
        'headers'  => array(),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'body'     => wp_json_encode( array(
            'id'      => 'chatcmpl-wp-agent-schedule-sync',
            'object'  => 'chat.completion',
            'model'   => $GLOBALS['wp_agent_schedule_sync_model'],
            'choices' => array(
                array(
                    'message'       => array(
                        'role'    => 'assistant',
                        'content' => $content,
                    ),
                    'finish_reason' => 'stop',
                ),
            ),
            'usage'   => array(
                'prompt_tokens'     => 10,
                'completion_tokens' => 6,
            ),
        ) ),
        'cookies'  => array(),
    );
}

register_shutdown_function( 'wp_agent_schedule_sync_cleanup' );

$user_id = wp_agent_schedule_sync_admin_id();
wp_set_current_user( $user_id );
WPAgent_Roles::ensure();
update_option( 'wp_agent_ai_base_url', 'https://schedule-sync.example.test/v1' );
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-schedule-sync-key' ) );
WPAgent::update_option( 'meowl_model', $GLOBALS['wp_agent_schedule_sync_model'] );
WPAgent::update_option( 'monthly_budget', 0 );
update_option( 'wp_agent_mode', 'administrator' );

$GLOBALS['wp_agent_schedule_sync_filter'] = function( $preempt, $parsed_args, $url ) {
    if ( false === strpos( (string) $url, '/chat/completions' ) ) {
        return $preempt;
    }

    $request = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
    $messages = is_array( $request['messages'] ?? null ) ? $request['messages'] : array();
    $last_user = '';
    foreach ( array_reverse( $messages ) as $message ) {
        if ( 'user' === ( $message['role'] ?? '' ) ) {
            $last_user = (string) ( $message['content'] ?? '' );
            break;
        }
    }

    if ( false !== strpos( $last_user, 'schedule status sync error' ) ) {
        return wp_agent_schedule_sync_response( false );
    }

    return wp_agent_schedule_sync_response( true, 'Schedule status sync completed.' );
};
add_filter( 'pre_http_request', $GLOBALS['wp_agent_schedule_sync_filter'], 10, 3 );

$success_schedule_id = WPAgent_Schedules::create( $user_id, 'WP Agent schedule status sync success.', 'minutes', null, null, 5 );
wp_agent_schedule_sync_assert( $success_schedule_id > 0, 'Success schedule should be created.' );
$GLOBALS['wp_agent_schedule_sync_schedule_ids'][] = $success_schedule_id;

$queued = WPAgent_Schedules::run( $success_schedule_id );
wp_agent_schedule_sync_assert( ! empty( $queued['ok'] ) && ! empty( $queued['run_id'] ), 'Success schedule should queue a run.' );
$success_run_id = (int) $queued['run_id'];
$GLOBALS['wp_agent_schedule_sync_run_ids'][] = $success_run_id;

$success_run = WPAgent_Runs::get( $success_run_id );
wp_agent_schedule_sync_assert( $success_run, 'Queued success run should exist.' );
$GLOBALS['wp_agent_schedule_sync_conversation_ids'][] = (int) $success_run->conversation_id;

$schedule_after_queue = WPAgent_Schedules::get( $success_schedule_id );
wp_agent_schedule_sync_assert( $schedule_after_queue && (int) $schedule_after_queue->last_run_id === $success_run_id, 'Schedule should store last_run_id after queuing.' );
wp_agent_schedule_sync_assert( 'queued' === (string) $schedule_after_queue->last_status, 'Schedule should initially show queued.' );

$worker = WPAgent_Worker::run_once( $success_run_id );
wp_agent_schedule_sync_assert( 'done' === ( $worker['status'] ?? '' ), 'Success run should complete.' );

$schedule_done = WPAgent_Schedules::get( $success_schedule_id );
wp_agent_schedule_sync_assert( $schedule_done && 'done' === (string) $schedule_done->last_status, 'Schedule should sync completed run status.' );
wp_agent_schedule_sync_assert( false !== strpos( (string) $schedule_done->last_summary, 'completed' ), 'Schedule done summary should mention completion.' );

$error_schedule_id = WPAgent_Schedules::create( $user_id, 'WP Agent schedule status sync error.', 'minutes', null, null, 5 );
wp_agent_schedule_sync_assert( $error_schedule_id > 0, 'Error schedule should be created.' );
$GLOBALS['wp_agent_schedule_sync_schedule_ids'][] = $error_schedule_id;

$queued_error = WPAgent_Schedules::run( $error_schedule_id );
wp_agent_schedule_sync_assert( ! empty( $queued_error['ok'] ) && ! empty( $queued_error['run_id'] ), 'Error schedule should queue a run.' );
$error_run_id = (int) $queued_error['run_id'];
$GLOBALS['wp_agent_schedule_sync_run_ids'][] = $error_run_id;

$error_run = WPAgent_Runs::get( $error_run_id );
wp_agent_schedule_sync_assert( $error_run, 'Queued error run should exist.' );
$GLOBALS['wp_agent_schedule_sync_conversation_ids'][] = (int) $error_run->conversation_id;

$worker_error = WPAgent_Worker::run_once( $error_run_id );
wp_agent_schedule_sync_assert( 'error' === ( $worker_error['status'] ?? '' ), 'Error run should fail.' );

$schedule_error = WPAgent_Schedules::get( $error_schedule_id );
wp_agent_schedule_sync_assert( $schedule_error && 'error' === (string) $schedule_error->last_status, 'Schedule should sync failed run status.' );
wp_agent_schedule_sync_assert( false !== strpos( (string) $schedule_error->last_summary, 'Fixture HTTP 500' ), 'Schedule error summary should include bounded failure evidence.' );

$tool = new WPAgent_Tool_Schedules();
$tool->set_context( $user_id, 'wpcli', 0, $user_id, 0 );
$listed = $tool->execute( array( 'action' => 'list' ) );
wp_agent_schedule_sync_assert( ! empty( $listed['success'] ) && ! empty( $listed['schedules'] ), 'Schedule tool list should succeed.' );
$listed_success = null;
foreach ( $listed['schedules'] as $item ) {
    if ( (int) $item['id'] === $success_schedule_id ) {
        $listed_success = $item;
        break;
    }
}
wp_agent_schedule_sync_assert( $listed_success && (int) $listed_success['last_run_id'] === $success_run_id, 'Schedule tool should expose last_run_id.' );
wp_agent_schedule_sync_assert( 'done' === (string) $listed_success['last_status'], 'Schedule tool should expose synced last_status.' );
wp_agent_schedule_sync_assert( false !== strpos( (string) $listed_success['last_summary'], 'completed' ), 'Schedule tool should expose synced last_summary.' );

$result = array(
    'success'               => true,
    'success_schedule_id'   => $success_schedule_id,
    'success_run_id'        => $success_run_id,
    'success_last_status'   => (string) $schedule_done->last_status,
    'error_schedule_id'     => $error_schedule_id,
    'error_run_id'          => $error_run_id,
    'error_last_status'     => (string) $schedule_error->last_status,
    'tool_exposed_run_id'   => (int) $listed_success['last_run_id'],
);

wp_agent_schedule_sync_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
