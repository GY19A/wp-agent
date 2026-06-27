<?php
/**
 * WP Agent human confirmation workflow checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/confirmation-workflow.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This confirmation workflow script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_confirmation_previous_mode'] = get_option( 'wp_agent_mode', 'author' );
$GLOBALS['wp_agent_confirmation_post_ids']      = array();
$GLOBALS['wp_agent_confirmation_comment_ids']   = array();
$GLOBALS['wp_agent_confirmation_run_ids']       = array();
$GLOBALS['wp_agent_confirmation_conversation_ids'] = array();
$GLOBALS['wp_agent_confirmation_restored']      = false;

function wp_agent_confirmation_cleanup() {
    global $wpdb;

    if ( ! empty( $GLOBALS['wp_agent_confirmation_restored'] ) ) {
        return;
    }

    foreach ( array_reverse( $GLOBALS['wp_agent_confirmation_comment_ids'] ) as $comment_id ) {
        if ( get_comment( $comment_id ) ) {
            wp_delete_comment( $comment_id, true );
        }
    }

    foreach ( array_reverse( $GLOBALS['wp_agent_confirmation_post_ids'] ) as $post_id ) {
        if ( get_post( $post_id ) ) {
            wp_delete_post( $post_id, true );
        }
    }

    foreach ( $GLOBALS['wp_agent_confirmation_run_ids'] as $run_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_confirmations', array( 'run_id' => (int) $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $run_id ), array( '%d' ) );
    }

    foreach ( $GLOBALS['wp_agent_confirmation_conversation_ids'] as $conversation_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $conversation_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $conversation_id ), array( '%d' ) );
    }

    update_option( 'wp_agent_mode', $GLOBALS['wp_agent_confirmation_previous_mode'] );
    WPAgent_Roles::ensure();
    $GLOBALS['wp_agent_confirmation_restored'] = true;
}

register_shutdown_function( 'wp_agent_confirmation_cleanup' );

function wp_agent_confirmation_fail( $message ) {
    wp_agent_confirmation_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_confirmation_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_confirmation_fail( $message );
    }
}

function wp_agent_confirmation_requester_id() {
    $admin = get_user_by( 'login', 'admin' );
    if ( $admin ) {
        return (int) $admin->ID;
    }
    return 1;
}

function wp_agent_confirmation_create_post( $agent_user, $marker ) {
    $post_id = wp_insert_post(
        array(
            'post_author'    => $agent_user,
            'post_title'     => 'WP Agent Confirmation Workflow ' . $marker,
            'post_content'   => '<p>Fixture post for WP Agent human confirmation acceptance.</p>',
            'post_status'    => 'publish',
            'post_type'      => 'post',
            'comment_status' => 'open',
            'ping_status'    => 'closed',
        ),
        true
    );

    wp_agent_confirmation_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Fixture post was not created.' );
    $GLOBALS['wp_agent_confirmation_post_ids'][] = (int) $post_id;
    return (int) $post_id;
}

function wp_agent_confirmation_create_comment( $post_id, $marker ) {
    $comment_id = wp_insert_comment(
        array(
            'comment_post_ID'      => (int) $post_id,
            'comment_author'       => 'WP Agent Confirmation Fixture',
            'comment_author_email' => 'confirmation-fixture@example.test',
            'comment_content'      => 'WP Agent confirmation workflow fixture ' . $marker,
            'comment_approved'     => '1',
        )
    );

    wp_agent_confirmation_assert( $comment_id > 0, 'Fixture comment was not created.' );
    $GLOBALS['wp_agent_confirmation_comment_ids'][] = (int) $comment_id;
    return (int) $comment_id;
}

function wp_agent_confirmation_create_run_context( $requester_id, $marker ) {
    $conversation = new WPAgent_Conversation();
    $conversation_id = $conversation->get_or_create( $requester_id, 'webchat', 'confirmation-' . $marker );
    $message_id      = $conversation->add_message( $conversation_id, 'user', 'Please perform the confirmation workflow fixture.' );
    $run_id          = WPAgent_Runs::create( $conversation_id, $requester_id, $message_id, 'webchat' );

    wp_agent_confirmation_assert( $conversation_id > 0 && $message_id > 0 && $run_id > 0, 'Run context was not created.' );

    $GLOBALS['wp_agent_confirmation_conversation_ids'][] = (int) $conversation_id;
    $GLOBALS['wp_agent_confirmation_run_ids'][]          = (int) $run_id;

    return array( (int) $conversation_id, (int) $message_id, (int) $run_id );
}

function wp_agent_confirmation_create_confirmation( $run_id, $conversation_id, $requester_id, $actor_id, $tool_call_id, $comment_id ) {
    $confirmation = WPAgent_Confirmations::create(
        array(
            'run_id'          => (int) $run_id,
            'conversation_id' => (int) $conversation_id,
            'user_id'         => (int) $requester_id,
            'actor_id'        => (int) $actor_id,
            'channel'         => 'webchat',
            'tool_name'       => 'manage_comments',
            'tool_call_id'    => $tool_call_id,
            'params'          => array(
                'action'     => 'trash',
                'comment_id' => (int) $comment_id,
            ),
        )
    );

    wp_agent_confirmation_assert( ! is_wp_error( $confirmation ) && ! empty( $confirmation['id'] ), 'Confirmation was not created.' );
    WPAgent_Runs::set_awaiting_confirmation(
        (int) $run_id,
        'Run paused for human confirmation.',
        array( 'confirmation_id' => (int) $confirmation['id'], 'tool' => 'manage_comments' )
    );

    return $confirmation;
}

function wp_agent_confirmation_latest_tool_message( $conversation_id ) {
    global $wpdb;

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wp_agent_messages WHERE conversation_id = %d AND role = 'tool' ORDER BY id DESC LIMIT 1",
            (int) $conversation_id
        ),
        ARRAY_A
    );
}

update_option( 'wp_agent_mode', 'author' );
WPAgent_Roles::ensure();

$requester_id = wp_agent_confirmation_requester_id();
$agent_user   = WPAgent_Roles::get_user_id();
wp_agent_confirmation_assert( $requester_id > 0, 'Requester user is missing.' );
wp_agent_confirmation_assert( $agent_user > 0, 'Bounded agent user is missing.' );
wp_agent_confirmation_assert( user_can( $agent_user, 'moderate_comments' ), 'Agent should be allowed to execute confirmed comment moderation.' );

$marker = 'confirmation-workflow-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
$post_id = wp_agent_confirmation_create_post( $agent_user, $marker );

$approved_comment_id = wp_agent_confirmation_create_comment( $post_id, $marker . '-approved' );
list( $approve_conversation_id, $approve_message_id, $approve_run_id ) = wp_agent_confirmation_create_run_context( $requester_id, $marker . '-approve' );
$approve_confirmation = wp_agent_confirmation_create_confirmation(
    $approve_run_id,
    $approve_conversation_id,
    $requester_id,
    $agent_user,
    'call_confirmed_trash',
    $approved_comment_id
);

$duplicate = WPAgent_Confirmations::create(
    array(
        'run_id'          => $approve_run_id,
        'conversation_id' => $approve_conversation_id,
        'user_id'         => $requester_id,
        'actor_id'        => $agent_user,
        'channel'         => 'webchat',
        'tool_name'       => 'manage_comments',
        'tool_call_id'    => 'call_confirmed_trash_duplicate',
        'params'          => array(
            'comment_id' => $approved_comment_id,
            'action'     => 'trash',
        ),
    )
);
wp_agent_confirmation_assert( ! is_wp_error( $duplicate ) && (int) $duplicate['id'] === (int) $approve_confirmation['id'], 'Duplicate pending operation should return the existing confirmation.' );

$decided = WPAgent_Confirmations::decide( (int) $approve_confirmation['id'], $requester_id, 'approved' );
wp_agent_confirmation_assert( ! is_wp_error( $decided ) && WPAgent_Confirmations::STATUS_APPROVED === $decided['status'], 'Confirmation approval failed.' );

$result = WPAgent::get_agent()->execute_confirmed_tool( (int) $approve_confirmation['id'] );
wp_agent_confirmation_assert( ! is_wp_error( $result ) && ! empty( $result['success'] ), 'Approved confirmation did not execute the tool: ' . wp_json_encode( $result ) );
wp_agent_confirmation_assert( 'trash' === wp_get_comment_status( $approved_comment_id ), 'Approved confirmed action should trash the comment.' );

$executed = WPAgent_Confirmations::get( (int) $approve_confirmation['id'] );
wp_agent_confirmation_assert( $executed && WPAgent_Confirmations::STATUS_EXECUTED === $executed['status'], 'Executed confirmation should be marked executed.' );
wp_agent_confirmation_assert( ! empty( $executed['result']['success'] ), 'Executed confirmation should store the tool result.' );
wp_agent_confirmation_assert( 'queued' === WPAgent_Runs::get( $approve_run_id )->status, 'Run should be re-queued after confirmed execution.' );

$retry = WPAgent::get_agent()->execute_confirmed_tool( (int) $approve_confirmation['id'] );
wp_agent_confirmation_assert( is_wp_error( $retry ), 'Executed confirmation should not execute twice.' );

$approve_tool_message = wp_agent_confirmation_latest_tool_message( $approve_conversation_id );
wp_agent_confirmation_assert( $approve_tool_message && false !== strpos( (string) $approve_tool_message['content'], 'Comment trashed' ), 'Confirmed execution should append a tool result message.' );
wp_agent_confirmation_assert( false !== strpos( (string) $approve_tool_message['tool_results'], 'call_confirmed_trash' ), 'Tool result message should preserve the tool call ID.' );

$approve_events = WPAgent_Run_Events::recent( $approve_run_id, 20 );
$approve_event_types = wp_list_pluck( $approve_events, 'event_type' );
wp_agent_confirmation_assert( in_array( 'confirmation_executed', $approve_event_types, true ), 'Confirmed execution should add a run event.' );

$rejected_comment_id = wp_agent_confirmation_create_comment( $post_id, $marker . '-rejected' );
list( $reject_conversation_id, $reject_message_id, $reject_run_id ) = wp_agent_confirmation_create_run_context( $requester_id, $marker . '-reject' );
$reject_confirmation = wp_agent_confirmation_create_confirmation(
    $reject_run_id,
    $reject_conversation_id,
    $requester_id,
    $agent_user,
    'call_rejected_trash',
    $rejected_comment_id
);

wp_set_current_user( $requester_id );
$handler = new WPAgent_Webhook_Handler();
$request = new WP_REST_Request( 'POST', '/wp-agent/v1/confirmations/' . (int) $reject_confirmation['id'] . '/reject' );
$request->set_param( 'id', (int) $reject_confirmation['id'] );
$response = $handler->handle_confirmation_reject( $request );
wp_agent_confirmation_assert( $response instanceof WP_REST_Response && 200 === $response->get_status(), 'REST rejection should return success.' );

$rejected = WPAgent_Confirmations::get( (int) $reject_confirmation['id'] );
wp_agent_confirmation_assert( $rejected && WPAgent_Confirmations::STATUS_REJECTED === $rejected['status'], 'Rejected confirmation should be marked rejected.' );
wp_agent_confirmation_assert( 'approved' === wp_get_comment_status( $rejected_comment_id ), 'Rejected confirmation must not execute the destructive action.' );
wp_agent_confirmation_assert( 'queued' === WPAgent_Runs::get( $reject_run_id )->status, 'Run should be re-queued after rejection.' );

$reject_tool_message = wp_agent_confirmation_latest_tool_message( $reject_conversation_id );
wp_agent_confirmation_assert( $reject_tool_message && false !== strpos( (string) $reject_tool_message['content'], 'human_rejected' ), 'Rejection should append a tool result message.' );
wp_agent_confirmation_assert( false !== strpos( (string) $reject_tool_message['tool_results'], 'call_rejected_trash' ), 'Rejected tool result should preserve the tool call ID.' );

$reject_events = WPAgent_Run_Events::recent( $reject_run_id, 20 );
$reject_event_types = wp_list_pluck( $reject_events, 'event_type' );
wp_agent_confirmation_assert( in_array( 'confirmation_rejected', $reject_event_types, true ), 'Rejection should add a run event.' );

$expired_comment_id = wp_agent_confirmation_create_comment( $post_id, $marker . '-expired' );
list( $expired_conversation_id, $expired_message_id, $expired_run_id ) = wp_agent_confirmation_create_run_context( $requester_id, $marker . '-expired' );
$expired_confirmation = wp_agent_confirmation_create_confirmation(
    $expired_run_id,
    $expired_conversation_id,
    $requester_id,
    $agent_user,
    'call_expired_trash',
    $expired_comment_id
);
global $wpdb;
$wpdb->update(
    $wpdb->prefix . 'wp_agent_confirmations',
    array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
    array( 'id' => (int) $expired_confirmation['id'] ),
    array( '%s' ),
    array( '%d' )
);
$expired_decision = WPAgent_Confirmations::decide( (int) $expired_confirmation['id'], $requester_id, 'approved' );
wp_agent_confirmation_assert( is_wp_error( $expired_decision ), 'Expired confirmation should not be approvable.' );
$expired = WPAgent_Confirmations::get( (int) $expired_confirmation['id'] );
wp_agent_confirmation_assert( $expired && WPAgent_Confirmations::STATUS_EXPIRED === $expired['status'], 'Expired confirmation should be marked expired.' );
wp_agent_confirmation_assert( 'approved' === wp_get_comment_status( $expired_comment_id ), 'Expired confirmation must not execute the destructive action.' );

$result = array(
    'success'                  => true,
    'approved_confirmation_id' => (int) $approve_confirmation['id'],
    'rejected_confirmation_id' => (int) $reject_confirmation['id'],
    'expired_confirmation_id'  => (int) $expired_confirmation['id'],
    'approved_comment_status'  => wp_get_comment_status( $approved_comment_id ),
    'rejected_comment_status'  => wp_get_comment_status( $rejected_comment_id ),
    'expired_comment_status'   => wp_get_comment_status( $expired_comment_id ),
    'approved_run_status'      => WPAgent_Runs::get( $approve_run_id )->status,
    'rejected_run_status'      => WPAgent_Runs::get( $reject_run_id )->status,
);

wp_agent_confirmation_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
