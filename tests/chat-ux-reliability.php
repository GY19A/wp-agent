<?php
/**
 * WP Agent chat UX reliability checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/chat-ux-reliability.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This chat UX reliability script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_chat_ux_cleanup'] = array(
    'attachments'      => array(),
    'runs'             => array(),
    'conversations'    => array(),
    'confirmations'    => array(),
);

function wp_agent_chat_ux_cleanup() {
    global $wpdb;

    foreach ( $GLOBALS['wp_agent_chat_ux_cleanup']['confirmations'] as $confirmation_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_confirmations', array( 'id' => (int) $confirmation_id ), array( '%d' ) );
    }

    foreach ( $GLOBALS['wp_agent_chat_ux_cleanup']['runs'] as $run_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => (int) $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $run_id ), array( '%d' ) );
    }

    foreach ( $GLOBALS['wp_agent_chat_ux_cleanup']['conversations'] as $conversation_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $conversation_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $conversation_id ), array( '%d' ) );
    }

    foreach ( $GLOBALS['wp_agent_chat_ux_cleanup']['attachments'] as $attachment_id ) {
        if ( get_post( $attachment_id ) ) {
            wp_delete_attachment( (int) $attachment_id, true );
        }
    }
}

function wp_agent_chat_ux_fail( $message ) {
    wp_agent_chat_ux_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_chat_ux_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_chat_ux_fail( $message );
    }
}

function wp_agent_chat_ux_admin_id() {
    $admin = get_user_by( 'login', 'admin' );
    if ( $admin ) {
        return (int) $admin->ID;
    }

    $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
    return ! empty( $admins ) ? (int) $admins[0] : 1;
}

register_shutdown_function( 'wp_agent_chat_ux_cleanup' );

$user_id = wp_agent_chat_ux_admin_id();
wp_set_current_user( $user_id );
wp_agent_chat_ux_assert( current_user_can( 'manage_options' ), 'Admin user is required.' );
add_filter( 'wp_agent_chat_send_wake_daemon', '__return_false' );

$handler = new WPAgent_Webhook_Handler();

$fixture_text = "WP Agent attachment context line one.\nSecond line for extraction.";
$upload = wp_upload_bits( 'wp-agent-chat-context-' . gmdate( 'YmdHis' ) . '.txt', null, $fixture_text );
wp_agent_chat_ux_assert( empty( $upload['error'] ) && ! empty( $upload['file'] ), 'Text attachment fixture upload failed.' );

$attachment_id = wp_insert_attachment(
    array(
        'post_mime_type' => 'text/plain',
        'post_title'     => 'wp-agent-chat-context',
        'post_status'    => 'inherit',
    ),
    $upload['file']
);
wp_agent_chat_ux_assert( $attachment_id > 0 && ! is_wp_error( $attachment_id ), 'Text attachment fixture insert failed.' );
$GLOBALS['wp_agent_chat_ux_cleanup']['attachments'][] = (int) $attachment_id;

$send_request = new WP_REST_Request( 'POST', '/wp-agent/v1/chat/send' );
$send_request->set_param( 'message', 'Use the attached notes.' );
$send_request->set_param( 'attachments', array( array( 'id' => (int) $attachment_id ) ) );
$send_response = $handler->handle_chat_send( $send_request );
wp_agent_chat_ux_assert( $send_response instanceof WP_REST_Response && 200 === $send_response->get_status(), 'Chat send with text attachment should succeed.' );

$send_data = $send_response->get_data();
$GLOBALS['wp_agent_chat_ux_cleanup']['runs'][]          = (int) $send_data['run_id'];
$GLOBALS['wp_agent_chat_ux_cleanup']['conversations'][] = (int) $send_data['conversation_id'];

wp_agent_chat_ux_assert( 1 === (int) ( $send_data['queue']['active_total'] ?? 0 ), 'Initial send response should expose one active queued run.' );
wp_agent_chat_ux_assert( 1 === (int) ( $send_data['queue']['position'] ?? 0 ), 'Initial send response should expose queue position 1 of 1.' );

$stored_message = (string) ( $send_data['message']['content'] ?? '' );
wp_agent_chat_ux_assert( false !== strpos( $stored_message, 'Extracted content:' ), 'Text attachment content heading should be included.' );
wp_agent_chat_ux_assert( false !== strpos( $stored_message, 'WP Agent attachment context line one.' ), 'First text attachment line should be included.' );
wp_agent_chat_ux_assert( false !== strpos( $stored_message, 'Second line for extraction.' ), 'Second text attachment line should be included.' );

$queued_request = new WP_REST_Request( 'POST', '/wp-agent/v1/chat/send' );
$queued_request->set_param( 'message', 'Queue this follow-up while the first run is still pending.' );
$queued_request->set_param( 'conversation_id', (int) $send_data['conversation_id'] );
$queued_response = $handler->handle_chat_send( $queued_request );
wp_agent_chat_ux_assert( $queued_response instanceof WP_REST_Response && 200 === $queued_response->get_status(), 'A new chat message should enqueue while another run is unfinished.' );

$queued_data = $queued_response->get_data();
$GLOBALS['wp_agent_chat_ux_cleanup']['runs'][] = (int) $queued_data['run_id'];

wp_agent_chat_ux_assert( 2 === (int) ( $queued_data['queue']['active_total'] ?? 0 ), 'Follow-up response should expose both active queued runs.' );
wp_agent_chat_ux_assert( 2 === (int) ( $queued_data['queue']['position'] ?? 0 ), 'Follow-up response should expose queue position 2 of 2.' );
wp_agent_chat_ux_assert( ! empty( $queued_data['queue']['blocked_by_prior'] ), 'Follow-up response should expose that earlier work is ahead in the conversation.' );

$first_run = WPAgent_Runs::get( (int) $send_data['run_id'] );
$followup_run = WPAgent_Runs::get( (int) $queued_data['run_id'] );
wp_agent_chat_ux_assert( $first_run && 'queued' === (string) $first_run->status, 'First chat run should remain queued before worker execution.' );
wp_agent_chat_ux_assert( $followup_run && 'queued' === (string) $followup_run->status, 'Follow-up chat run should be queued instead of rejected.' );
wp_agent_chat_ux_assert( (int) $send_data['run_id'] !== (int) $queued_data['run_id'], 'Follow-up chat send should create a distinct run.' );
wp_agent_chat_ux_assert( ! WPAgent_Runs::claim( (int) $queued_data['run_id'] ), 'Follow-up run should not be claimable before earlier conversation work is terminal.' );
$followup_run = WPAgent_Runs::get( (int) $queued_data['run_id'] );
wp_agent_chat_ux_assert( $followup_run && 'queued' === (string) $followup_run->status, 'Blocked follow-up claim should leave the run queued.' );

$cancel_request = new WP_REST_Request( 'POST', '/wp-agent/v1/chat/runs/' . (int) $send_data['run_id'] . '/cancel' );
$cancel_request->set_param( 'id', (int) $send_data['run_id'] );
$cancel_request->set_param( 'conversation_id', (int) $send_data['conversation_id'] );
$cancel_response = $handler->handle_chat_run_cancel( $cancel_request );
wp_agent_chat_ux_assert( $cancel_response instanceof WP_REST_Response && 200 === $cancel_response->get_status(), 'Canceling a queued chat run should succeed.' );
$cancel_data = $cancel_response->get_data();
wp_agent_chat_ux_assert( ! empty( $cancel_data['canceled'] ) && 'canceled' === (string) $cancel_data['status'], 'Cancel endpoint should mark the run canceled.' );
wp_agent_chat_ux_assert( 1 === (int) ( $cancel_data['queue']['active_total'] ?? 0 ), 'Cancel response should expose remaining active queued work.' );
wp_agent_chat_ux_assert( 0 === (int) ( $cancel_data['queue']['position'] ?? -1 ), 'Canceled run should not keep a queue position.' );

$active_followup_request = new WP_REST_Request( 'GET', '/wp-agent/v1/chat/active-run' );
$active_followup_request->set_param( 'conversation_id', (int) $send_data['conversation_id'] );
$active_followup_response = $handler->handle_chat_active_run( $active_followup_request );
wp_agent_chat_ux_assert( $active_followup_response instanceof WP_REST_Response && 200 === $active_followup_response->get_status(), 'Active-run check after cancel should succeed.' );
$active_followup_data = $active_followup_response->get_data();
wp_agent_chat_ux_assert( (int) ( $active_followup_data['run']['id'] ?? 0 ) === (int) $queued_data['run_id'], 'Canceled run should no longer be returned as the active run.' );
wp_agent_chat_ux_assert( 1 === (int) ( $active_followup_data['queue']['active_total'] ?? 0 ), 'Active follow-up response should expose one remaining active run.' );
wp_agent_chat_ux_assert( 1 === (int) ( $active_followup_data['queue']['position'] ?? 0 ), 'Active follow-up response should expose queue position 1 of 1.' );

$conversation = new WPAgent_Conversation();
$expired_conversation_id = $conversation->get_or_create( $user_id, 'webchat', 'chat-expired-confirmation-' . wp_generate_uuid4() );
$expired_message_id      = $conversation->add_message( $expired_conversation_id, 'user', 'Create an expiring confirmation fixture.' );
$expired_run_id          = WPAgent_Runs::create( $expired_conversation_id, $user_id, $expired_message_id, 'webchat' );
wp_agent_chat_ux_assert( $expired_conversation_id > 0 && $expired_message_id > 0 && $expired_run_id > 0, 'Expired confirmation run context should be created.' );
$GLOBALS['wp_agent_chat_ux_cleanup']['conversations'][] = (int) $expired_conversation_id;
$GLOBALS['wp_agent_chat_ux_cleanup']['runs'][]          = (int) $expired_run_id;

$confirmation = WPAgent_Confirmations::create(
    array(
        'run_id'          => (int) $expired_run_id,
        'conversation_id' => (int) $expired_conversation_id,
        'user_id'         => (int) $user_id,
        'actor_id'        => (int) WPAgent_Roles::get_user_id(),
        'channel'         => 'webchat',
        'tool_name'       => 'manage_wp_agent_settings',
        'tool_call_id'    => 'call_expired_chat_fixture',
        'params'          => array(
            'action' => 'set',
            'key'    => 'monthly_budget',
            'value'  => '1',
        ),
    )
);
wp_agent_chat_ux_assert( ! is_wp_error( $confirmation ) && ! empty( $confirmation['id'] ), 'Confirmation fixture should be created.' );
$GLOBALS['wp_agent_chat_ux_cleanup']['confirmations'][] = (int) $confirmation['id'];

WPAgent_Runs::set_awaiting_confirmation(
    (int) $expired_run_id,
    'Run paused for human confirmation.',
    array( 'confirmation_id' => (int) $confirmation['id'] )
);

global $wpdb;
$wpdb->update(
    $wpdb->prefix . 'wp_agent_confirmations',
    array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
    array( 'id' => (int) $confirmation['id'] ),
    array( '%s' ),
    array( '%d' )
);

$active_request = new WP_REST_Request( 'GET', '/wp-agent/v1/chat/active-run' );
$active_request->set_param( 'conversation_id', (int) $expired_conversation_id );
$active_response = $handler->handle_chat_active_run( $active_request );
wp_agent_chat_ux_assert( $active_response instanceof WP_REST_Response && 200 === $active_response->get_status(), 'Active-run check should succeed.' );
$active_data = $active_response->get_data();
wp_agent_chat_ux_assert( empty( $active_data['run'] ), 'Expired confirmation should not remain resumable as an active run.' );

$expired_run = WPAgent_Runs::get( $expired_run_id );
wp_agent_chat_ux_assert( $expired_run && 'error' === (string) $expired_run->status, 'Expired confirmation run should be marked error.' );
wp_agent_chat_ux_assert( false !== strpos( (string) $expired_run->error, 'Confirmation expired' ), 'Expired confirmation run should expose a clear error.' );

$expired_confirmation = WPAgent_Confirmations::get( (int) $confirmation['id'] );
wp_agent_chat_ux_assert( $expired_confirmation && WPAgent_Confirmations::STATUS_EXPIRED === $expired_confirmation['status'], 'Expired confirmation should be marked expired.' );

$events = WPAgent_Run_Events::recent( $expired_run_id, 10 );
$event_types = wp_list_pluck( $events, 'event_type' );
wp_agent_chat_ux_assert( in_array( 'confirmation_expired', $event_types, true ), 'Expired confirmation should create a run event.' );

$result = array(
    'success'                     => true,
    'text_attachment_extracted'   => true,
    'followup_enqueue_status'     => (string) $followup_run->status,
    'followup_queue_position'     => (int) ( $queued_data['queue']['position'] ?? 0 ),
    'remaining_queue_total'       => (int) ( $active_followup_data['queue']['active_total'] ?? 0 ),
    'cancel_status'               => (string) $cancel_data['status'],
    'expired_confirmation_status' => $expired_confirmation['status'],
    'expired_run_status'          => (string) $expired_run->status,
);

wp_agent_chat_ux_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
