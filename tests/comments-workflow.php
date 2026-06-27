<?php
/**
 * WP Agent comment moderation workflow checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/comments-workflow.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This comments workflow script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_comments_previous_mode'] = get_option( 'wp_agent_mode', 'author' );
$GLOBALS['wp_agent_comments_post_id']       = 0;
$GLOBALS['wp_agent_comments_comment_ids']   = array();
$GLOBALS['wp_agent_comments_restored']      = false;

function wp_agent_comments_cleanup() {
    global $wp_agent_comments_previous_mode, $wp_agent_comments_post_id, $wp_agent_comments_comment_ids;

    if ( ! empty( $GLOBALS['wp_agent_comments_restored'] ) ) {
        return;
    }

    foreach ( array_reverse( $wp_agent_comments_comment_ids ) as $comment_id ) {
        if ( get_comment( $comment_id ) ) {
            wp_delete_comment( $comment_id, true );
        }
    }

    if ( $wp_agent_comments_post_id > 0 && get_post( $wp_agent_comments_post_id ) ) {
        wp_delete_post( $wp_agent_comments_post_id, true );
    }

    update_option( 'wp_agent_mode', $wp_agent_comments_previous_mode );
    WPAgent_Roles::ensure();
    $GLOBALS['wp_agent_comments_restored'] = true;
}

register_shutdown_function( 'wp_agent_comments_cleanup' );

function wp_agent_comments_fail( $message ) {
    wp_agent_comments_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_comments_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_comments_fail( $message );
    }
}

function wp_agent_comments_create_comment( $post_id, $marker, $approved = '0', $content = '', $author = 'WP Agent Comment Fixture', $email = 'comment-fixture@example.test' ) {
    global $wp_agent_comments_comment_ids;

    if ( '' === $content ) {
        $content = 'WP Agent comments workflow fixture ' . $marker;
    }

    $comment_id = wp_insert_comment(
        array(
            'comment_post_ID'      => $post_id,
            'comment_author'       => $author,
            'comment_author_email' => $email,
            'comment_content'      => $content,
            'comment_approved'     => $approved,
        )
    );

    wp_agent_comments_assert( $comment_id > 0, 'Fixture comment was not created for ' . $marker . '.' );
    $wp_agent_comments_comment_ids[] = (int) $comment_id;
    return (int) $comment_id;
}

update_option( 'wp_agent_mode', 'author' );
WPAgent_Roles::ensure();

$agent_user = WPAgent_Roles::get_user_id();
wp_agent_comments_assert( $agent_user > 0, 'Bounded agent user is missing.' );
wp_agent_comments_assert( user_can( $agent_user, 'moderate_comments' ), 'Author-mode agent should be able to moderate comments.' );
wp_set_current_user( $agent_user );

$registry    = new WPAgent_Tools();
$definitions = $registry->get_definitions_for_user( $agent_user );
$tool_names  = array();
foreach ( $definitions as $definition ) {
    $tool_names[] = $definition['name'] ?? '';
}
wp_agent_comments_assert( in_array( 'manage_comments', $tool_names, true ), 'manage_comments should be visible to the bounded agent.' );

$marker = 'comments-workflow-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );

$GLOBALS['wp_agent_comments_post_id'] = wp_insert_post(
    array(
        'post_author'    => $agent_user,
        'post_title'     => 'WP Agent Comments Workflow ' . $marker,
        'post_content'   => '<p>Fixture post for WP Agent comment moderation acceptance.</p>',
        'post_status'    => 'publish',
        'post_type'      => 'post',
        'comment_status' => 'open',
        'ping_status'    => 'closed',
    ),
    true
);
$wp_agent_comments_post_id = $GLOBALS['wp_agent_comments_post_id'];
wp_agent_comments_assert( ! is_wp_error( $wp_agent_comments_post_id ) && $wp_agent_comments_post_id > 0, 'Fixture post was not created.' );
$wp_agent_comments_post_id = (int) $wp_agent_comments_post_id;
$GLOBALS['wp_agent_comments_post_id'] = $wp_agent_comments_post_id;

$approve_id = wp_agent_comments_create_comment( $wp_agent_comments_post_id, $marker . '-approve', '0' );
$reply_id   = wp_agent_comments_create_comment( $wp_agent_comments_post_id, $marker . '-reply', '0' );
$spam_id    = wp_agent_comments_create_comment( $wp_agent_comments_post_id, $marker . '-spam', '1' );
$trash_id   = wp_agent_comments_create_comment( $wp_agent_comments_post_id, $marker . '-trash', '1' );
$triage_spam_id = wp_agent_comments_create_comment(
    $wp_agent_comments_post_id,
    $marker . '-triage-spam',
    '0',
    'Cheap VIAGRA backlink deal. Click here http://spam.example.test/a and http://spam.example.test/b for free money.',
    'Backlink Bot',
    'bot@example.test'
);
$triage_question_id = wp_agent_comments_create_comment(
    $wp_agent_comments_post_id,
    $marker . '-triage-question',
    '0',
    'How can I subscribe to updates on this topic? Thank you for the article.',
    'Maya Reader',
    'maya@example.test'
);

$comments = new WPAgent_Tool_Comments();
$comments->set_context( $agent_user, 'wpcli', 0, 1, 0 );

$pending = $comments->execute(
    array(
        'action'        => 'list',
        'status_filter' => 'hold',
        'limit'         => 50,
    )
);
wp_agent_comments_assert( is_array( $pending ) && isset( $pending['comments'] ), 'Pending comment list failed: ' . wp_json_encode( $pending ) );
$pending_ids = array_map( 'intval', wp_list_pluck( $pending['comments'], 'id' ) );
wp_agent_comments_assert( in_array( $approve_id, $pending_ids, true ), 'Pending list should include the approve fixture comment.' );
wp_agent_comments_assert( in_array( $reply_id, $pending_ids, true ), 'Pending list should include the reply fixture comment.' );
wp_agent_comments_assert( in_array( $triage_spam_id, $pending_ids, true ), 'Pending list should include the spam triage fixture comment.' );
wp_agent_comments_assert( in_array( $triage_question_id, $pending_ids, true ), 'Pending list should include the question triage fixture comment.' );
wp_agent_comments_assert( (int) ( $pending['counts']['pending'] ?? 0 ) >= 4, 'Pending count should include fixture comments.' );

$full = $comments->execute(
    array(
        'action'     => 'get',
        'comment_id' => $triage_question_id,
    )
);
wp_agent_comments_assert( ! empty( $full['success'] ) && ! empty( $full['comment']['content'] ), 'Get action should return full comment context: ' . wp_json_encode( $full ) );
wp_agent_comments_assert( false !== strpos( $full['comment']['content'], 'subscribe to updates' ), 'Get action should include the full comment body.' );
wp_agent_comments_assert( (int) $full['comment']['post_id'] === (int) $wp_agent_comments_post_id, 'Get action should include the parent post ID.' );

$spam_triage = $comments->execute(
    array(
        'action'     => 'triage',
        'comment_id' => $triage_spam_id,
    )
);
wp_agent_comments_assert( ! empty( $spam_triage['success'] ), 'Spam triage failed: ' . wp_json_encode( $spam_triage ) );
wp_agent_comments_assert( 'spam' === ( $spam_triage['recommended_action'] ?? '' ), 'Spam triage should recommend spam.' );
wp_agent_comments_assert( (int) ( $spam_triage['spam_score'] ?? 0 ) >= 3, 'Spam triage should assign a meaningful spam score.' );
wp_agent_comments_assert( in_array( 'many_links', $spam_triage['signals'] ?? array(), true ), 'Spam triage should detect many links.' );
wp_agent_comments_assert( in_array( 'spam_term:viagra', $spam_triage['signals'] ?? array(), true ), 'Spam triage should detect spam terms.' );
wp_agent_comments_assert( 'unapproved' === wp_get_comment_status( $triage_spam_id ), 'Triage must not mutate spam fixture status.' );

$question_triage = $comments->execute(
    array(
        'action'     => 'triage',
        'comment_id' => $triage_question_id,
        'reply_tone' => 'formal',
    )
);
wp_agent_comments_assert( ! empty( $question_triage['success'] ), 'Question triage failed: ' . wp_json_encode( $question_triage ) );
wp_agent_comments_assert( 'reply' === ( $question_triage['recommended_action'] ?? '' ), 'Question triage should recommend a reply.' );
wp_agent_comments_assert( ! empty( $question_triage['requires_review'] ), 'Reply triage should require review.' );
wp_agent_comments_assert( false !== strpos( $question_triage['reply_suggestion'] ?? '', 'Thank you for your question' ), 'Question triage should include a formal reply suggestion.' );
wp_agent_comments_assert( 'unapproved' === wp_get_comment_status( $triage_question_id ), 'Triage must not mutate question fixture status.' );

$approved = $comments->execute(
    array(
        'action'     => 'approve',
        'comment_id' => $approve_id,
    )
);
wp_agent_comments_assert( ! empty( $approved['success'] ), 'Approve action failed: ' . wp_json_encode( $approved ) );
wp_agent_comments_assert( 'approved' === wp_get_comment_status( $approve_id ), 'Approve action should set comment status to approved.' );

$reply = $comments->execute(
    array(
        'action'        => 'reply',
        'comment_id'    => $reply_id,
        'reply_content' => 'Thank you for the useful moderation workflow fixture.',
    )
);
wp_agent_comments_assert( ! empty( $reply['success'] ) && ! empty( $reply['reply_id'] ), 'Reply action failed: ' . wp_json_encode( $reply ) );
$reply_comment_id = (int) $reply['reply_id'];
$wp_agent_comments_comment_ids[] = $reply_comment_id;
$reply_comment = get_comment( $reply_comment_id );
wp_agent_comments_assert( $reply_comment, 'Reply comment should exist.' );
wp_agent_comments_assert( (int) $reply_comment->comment_parent === $reply_id, 'Reply should point at the parent comment.' );
wp_agent_comments_assert( (int) $reply_comment->comment_post_ID === (int) $wp_agent_comments_post_id, 'Reply should stay on the fixture post.' );
wp_agent_comments_assert( (int) $reply_comment->user_id === $agent_user, 'Reply should be authored by the bounded agent user.' );
wp_agent_comments_assert( 'approved' === wp_get_comment_status( $reply_comment_id ), 'Reply should be approved.' );

$spammed = $comments->execute(
    array(
        'action'     => 'spam',
        'comment_id' => $spam_id,
    )
);
wp_agent_comments_assert( ! empty( $spammed['success'] ), 'Spam action failed: ' . wp_json_encode( $spammed ) );
wp_agent_comments_assert( 'spam' === wp_get_comment_status( $spam_id ), 'Spam action should set comment status to spam.' );

$trashed = $comments->execute(
    array(
        'action'     => 'trash',
        'comment_id' => $trash_id,
    )
);
wp_agent_comments_assert( ! empty( $trashed['success'] ), 'Trash action failed: ' . wp_json_encode( $trashed ) );
wp_agent_comments_assert( 'trash' === wp_get_comment_status( $trash_id ), 'Trash action should set comment status to trash.' );

$spam_list = $comments->execute(
    array(
        'action'        => 'list',
        'status_filter' => 'spam',
        'limit'         => 50,
    )
);
$spam_ids = array_map( 'intval', wp_list_pluck( $spam_list['comments'] ?? array(), 'id' ) );
wp_agent_comments_assert( in_array( $spam_id, $spam_ids, true ), 'Spam list should include the spam fixture comment.' );

$trash_list = $comments->execute(
    array(
        'action'        => 'list',
        'status_filter' => 'trash',
        'limit'         => 50,
    )
);
$trash_ids = array_map( 'intval', wp_list_pluck( $trash_list['comments'] ?? array(), 'id' ) );
wp_agent_comments_assert( in_array( $trash_id, $trash_ids, true ), 'Trash list should include the trash fixture comment.' );

$permissions = new WPAgent_Permissions();
wp_agent_comments_assert( $permissions->requires_confirmation( 'manage_comments', array( 'action' => 'trash' ) ), 'Comment trash should require confirmation.' );
wp_agent_comments_assert( ! $permissions->requires_confirmation( 'manage_comments', array( 'action' => 'approve' ) ), 'Comment approve should not require confirmation.' );
wp_agent_comments_assert( ! $permissions->requires_confirmation( 'manage_comments', array( 'action' => 'reply' ) ), 'Comment reply should not require confirmation.' );

$result = array(
    'success'         => true,
    'post_id'         => (int) $wp_agent_comments_post_id,
    'approved_id'     => $approve_id,
    'reply_parent_id' => $reply_id,
    'reply_id'        => $reply_comment_id,
    'spam_id'         => $spam_id,
    'trash_id'        => $trash_id,
    'pending_seen'    => count( $pending_ids ),
    'spam_seen'       => count( $spam_ids ),
    'trash_seen'      => count( $trash_ids ),
    'triage_spam_score' => (int) ( $spam_triage['spam_score'] ?? 0 ),
    'triage_question_action' => $question_triage['recommended_action'] ?? '',
);

wp_agent_comments_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
