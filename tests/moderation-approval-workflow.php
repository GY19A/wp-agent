<?php
/**
 * WP Agent moderation approval workflow checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/moderation-approval-workflow.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This moderation workflow script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_moderation_previous_mode']    = get_option( 'wp_agent_mode', 'author' );
$GLOBALS['wp_agent_moderation_post_ids']         = array();
$GLOBALS['wp_agent_moderation_tokens']           = array();
$GLOBALS['wp_agent_moderation_previous_options'] = array();
$GLOBALS['wp_agent_moderation_restored']         = false;

function wp_agent_moderation_table() {
    global $wpdb;
    return $wpdb->prefix . 'wp_agent_moderation';
}

function wp_agent_moderation_cleanup() {
    global $wpdb, $wp_agent_moderation_previous_mode, $wp_agent_moderation_post_ids, $wp_agent_moderation_tokens, $wp_agent_moderation_previous_options;

    if ( ! empty( $GLOBALS['wp_agent_moderation_restored'] ) ) {
        return;
    }

    foreach ( $wp_agent_moderation_tokens as $token ) {
        $wpdb->delete( wp_agent_moderation_table(), array( 'token' => $token ), array( '%s' ) );
    }

    foreach ( array_reverse( $wp_agent_moderation_post_ids ) as $post_id ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_syndication_log', array( 'object_id' => (int) $post_id ), array( '%d' ) );
        if ( get_post( $post_id ) ) {
            wp_delete_post( $post_id, true );
        }
    }

    foreach ( $wp_agent_moderation_previous_options as $key => $state ) {
        if ( $state['exists'] ) {
            update_option( 'wp_agent_' . $key, $state['value'] );
        } else {
            delete_option( 'wp_agent_' . $key );
        }
    }

    update_option( 'wp_agent_mode', $wp_agent_moderation_previous_mode );
    WPAgent_Roles::ensure();
    $GLOBALS['wp_agent_moderation_restored'] = true;
}

register_shutdown_function( 'wp_agent_moderation_cleanup' );

function wp_agent_moderation_fail( $message ) {
    wp_agent_moderation_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_moderation_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_moderation_fail( $message );
    }
}

function wp_agent_moderation_disable_syndication() {
    global $wp_agent_moderation_previous_options;

    foreach ( array( 'syndicate_telegram', 'syndicate_discord', 'syndicate_x', 'syndicate_reddit' ) as $key ) {
        $option_name = 'wp_agent_' . $key;
        $sentinel    = 'wp-agent-option-missing-' . $key;
        $value       = get_option( $option_name, $sentinel );

        $wp_agent_moderation_previous_options[ $key ] = array(
            'exists' => $sentinel !== $value,
            'value'  => $sentinel !== $value ? $value : null,
        );

        WPAgent::update_option( $key, false );
    }
}

function wp_agent_moderation_extract_token( $url ) {
    $query = wp_parse_url( (string) $url, PHP_URL_QUERY );
    $args  = array();
    parse_str( (string) $query, $args );
    return isset( $args['token'] ) ? (string) $args['token'] : '';
}

function wp_agent_moderation_create_post( $tool, $title, $status ) {
    global $wp_agent_moderation_post_ids;

    $result = $tool->execute(
        array(
            'action'  => 'create',
            'title'   => $title,
            'content' => '<p>WP Agent moderation workflow fixture.</p>',
            'status'  => $status,
        )
    );

    wp_agent_moderation_assert( ! empty( $result['success'] ) && ! empty( $result['post_id'] ), 'Post fixture was not created: ' . wp_json_encode( $result ) );
    $wp_agent_moderation_post_ids[] = (int) $result['post_id'];
    return $result;
}

function wp_agent_moderation_quality_content( $marker, $topic ) {
    if ( 'publish' === $topic ) {
        return '<p>' . $marker . ' reports on a municipal open-data briefing where public agencies explain new transit dashboards, service reliability notices, and data access plans for residents. The article frames the announcement as a practical civic information update for local readers.</p>'
            . '<p>Editors can review source records, verify the civic-service angle, and decide whether the draft should become a public post after human approval. The prose is original, avoids copied language, and includes context about transparency, accessibility, and reader usefulness.</p>'
            . '<p>The quality gate has enough text to evaluate provenance, search metadata, structure, and duplication before the approval request is created.</p>';
    }

    if ( 'reject' === $topic ) {
        return '<p>' . $marker . ' covers a neighborhood arts council launching weekend workshops for families, independent artists, and youth groups. The draft focuses on cultural programming, registration logistics, and how organizers plan to measure community participation.</p>'
            . '<p>The wording uses a distinct editorial angle from other moderation fixtures. Reviewers can examine retained source notes, consider whether the event needs additional quotes, and reject the item without publishing if the timing or details are not ready.</p>'
            . '<p>The fixture remains long enough for deterministic readability, SEO, and provenance checks before moderation links are generated.</p>';
    }

    return '<p>' . $marker . ' describes a regional health department publishing seasonal preparedness guidance for clinics, schools, and volunteer coordinators. The article explains planning timelines, communication channels, and practical steps for public-service teams.</p>'
        . '<p>This text intentionally differs from the other moderation fixtures while preserving source-aware editorial structure. Human reviewers can allow the request to expire without the draft becoming public, proving that token age checks still protect publication.</p>'
        . '<p>The native quality gate can inspect source notes, metadata, paragraphs, and duplicate risk before the expired approval scenario begins.</p>';
}

function wp_agent_moderation_prepare_quality_post( $post_id, $marker, $topic ) {
    update_post_meta( $post_id, '_wp_agent_source_urls', wp_json_encode( array( 'https://example.com/moderation-quality-source-' . sanitize_key( $topic ) ) ) );
    update_post_meta( $post_id, '_wp_agent_source_notes', 'Fixture notes for moderation quality gate acceptance.' );
    update_post_meta( $post_id, '_wp_agent_meta_title', 'Moderation Quality Gate ' . ucfirst( $topic ) );
    update_post_meta( $post_id, '_wp_agent_meta_description', 'A deterministic moderation quality fixture verifies source provenance, review readiness, and approval gating before publication.' );
    update_post_meta( $post_id, '_wp_agent_focus_keyword', 'moderation quality ' . sanitize_key( $topic ) );
}

function wp_agent_moderation_submit( $tool, $post_id ) {
    global $wp_agent_moderation_tokens;

    $result = $tool->execute(
        array(
            'action'  => 'submit',
            'post_id' => (int) $post_id,
        )
    );

    wp_agent_moderation_assert( ! empty( $result['success'] ), 'Moderation submit failed: ' . wp_json_encode( $result ) );
    wp_agent_moderation_assert( 'pending' === get_post_status( $post_id ), 'Submitted post should be pending review.' );
    wp_agent_moderation_assert( isset( $result['quality']['score'] ) && (int) $result['quality']['score'] >= 80, 'Moderation submit should include passing quality gate evidence.' );

    $approve_token = wp_agent_moderation_extract_token( $result['approve_url'] ?? '' );
    $reject_token  = wp_agent_moderation_extract_token( $result['reject_url'] ?? '' );
    wp_agent_moderation_assert( '' !== $approve_token && $approve_token === $reject_token, 'Approve and reject URLs should contain the same moderation token.' );
    wp_agent_moderation_assert( strlen( $approve_token ) >= 32, 'Moderation token should be high entropy.' );
    $approve_url = rawurldecode( (string) ( $result['approve_url'] ?? '' ) );
    $reject_url  = rawurldecode( (string) ( $result['reject_url'] ?? '' ) );
    wp_agent_moderation_assert( false !== strpos( $approve_url, 'wp-agent/v1/moderate/approve' ), 'Approve URL should use the moderation REST route.' );
    wp_agent_moderation_assert( false !== strpos( $reject_url, 'wp-agent/v1/moderate/reject' ), 'Reject URL should use the moderation REST route.' );
    wp_agent_moderation_assert( ! empty( $result['preview_url'] ), 'Moderation submit should return a preview URL.' );

    $wp_agent_moderation_tokens[] = $approve_token;
    $row = WPAgent_Moderation::get_by_token( $approve_token );
    wp_agent_moderation_assert( $row && 'pending' === $row->status, 'Moderation row should be pending.' );
    wp_agent_moderation_assert( (int) $row->object_id === (int) $post_id, 'Moderation row should point to the submitted post.' );

    return array( $result, $approve_token );
}

wp_agent_moderation_disable_syndication();

update_option( 'wp_agent_mode', 'author' );
WPAgent_Roles::ensure();

$agent_user = WPAgent_Roles::get_user_id();
wp_agent_moderation_assert( $agent_user > 0, 'Bounded agent user is missing.' );
wp_agent_moderation_assert( user_can( $agent_user, 'edit_posts' ), 'Author-mode agent should be able to request approval.' );
wp_agent_moderation_assert( ! user_can( $agent_user, 'publish_posts' ), 'Author-mode agent should not publish directly.' );
wp_set_current_user( $agent_user );

$registry    = new WPAgent_Tools();
$definitions = $registry->get_definitions_for_user( $agent_user );
$tool_names  = array();
foreach ( $definitions as $definition ) {
    $tool_names[] = $definition['name'] ?? '';
}
wp_agent_moderation_assert( in_array( 'request_approval', $tool_names, true ), 'request_approval should be visible to the bounded agent.' );

$marker     = 'moderation-workflow-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
$posts      = new WPAgent_Tool_Posts();
$moderation = new WPAgent_Tool_Moderation();
$context    = array( $agent_user, 'wpcli', 0, 1, 0 );
$posts->set_context( ...$context );
$moderation->set_context( ...$context );

global $wpdb;

$draft_before_quality = wp_agent_moderation_create_post( $posts, 'WP Agent Quality Gate Block ' . $marker, 'draft' );
$blocked_quality = $moderation->execute( array(
    'action'  => 'submit',
    'post_id' => (int) $draft_before_quality['post_id'],
) );
wp_agent_moderation_assert( ! empty( $blocked_quality['error'] ), 'Low-quality draft should not enter moderation.' );
wp_agent_moderation_assert( 'draft' === get_post_status( $draft_before_quality['post_id'] ), 'Quality-gated draft should remain draft.' );
wp_agent_moderation_assert( 'revise' === ( $blocked_quality['quality']['status'] ?? '' ), 'Blocked moderation should expose revise quality status.' );
$blocked_rows = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM " . wp_agent_moderation_table() . " WHERE object_id = %d",
    (int) $draft_before_quality['post_id']
) );
wp_agent_moderation_assert( 0 === (int) $blocked_rows, 'Quality-gated draft should not create a moderation row.' );

$publish_attempt = wp_agent_moderation_create_post( $posts, 'WP Agent Publish Funnel ' . $marker, 'publish' );
wp_agent_moderation_assert( 'pending' === ( $publish_attempt['status'] ?? '' ), 'Author-mode publish attempt should be downgraded to pending.' );
wp_agent_moderation_assert( false !== strpos( (string) ( $publish_attempt['note'] ?? '' ), 'request_approval' ), 'Publish downgrade should instruct the agent to request approval.' );
wp_update_post( array(
    'ID'           => (int) $publish_attempt['post_id'],
    'post_content' => wp_agent_moderation_quality_content( $marker . ' publish', 'publish' ),
) );
wp_agent_moderation_prepare_quality_post( (int) $publish_attempt['post_id'], $marker . ' publish', 'publish' );

list( $approval_submit, $approval_token ) = wp_agent_moderation_submit( $moderation, (int) $publish_attempt['post_id'] );
wp_agent_moderation_assert( in_array( $approval_submit['quality']['status'] ?? '', array( 'pass', 'review' ), true ), 'Approval submit should include pass/review quality status.' );
$approved = WPAgent_Moderation::approve( $approval_token );
wp_agent_moderation_assert( ! empty( $approved['success'] ), 'Approval should publish the post: ' . wp_json_encode( $approved ) );
wp_agent_moderation_assert( 'publish' === get_post_status( $publish_attempt['post_id'] ), 'Approved post should be published.' );
wp_agent_moderation_assert( ! empty( $approved['permalink'] ), 'Approval should return a permalink.' );

$approved_row = WPAgent_Moderation::get_by_token( $approval_token );
wp_agent_moderation_assert( $approved_row && 'approved' === $approved_row->status && ! empty( $approved_row->decided_at ), 'Approved moderation row should be decided.' );

$syndication_rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT target, status FROM {$wpdb->prefix}wp_agent_syndication_log WHERE object_id = %d ORDER BY target ASC",
        (int) $publish_attempt['post_id']
    ),
    ARRAY_A
);
wp_agent_moderation_assert( 4 === count( $syndication_rows ), 'Approval should record one syndication attempt per supported target.' );
foreach ( $syndication_rows as $row ) {
    wp_agent_moderation_assert( 'skipped' === $row['status'], 'Disabled syndication target should be skipped: ' . wp_json_encode( $row ) );
}

$reuse = WPAgent_Moderation::approve( $approval_token );
wp_agent_moderation_assert( empty( $reuse['success'] ) && ! empty( $reuse['error'] ), 'A used moderation token should not approve twice.' );

$reject_post = wp_agent_moderation_create_post( $posts, 'WP Agent Reject Funnel ' . $marker, 'draft' );
wp_update_post( array(
    'ID'           => (int) $reject_post['post_id'],
    'post_content' => wp_agent_moderation_quality_content( $marker . ' reject', 'reject' ),
) );
wp_agent_moderation_prepare_quality_post( (int) $reject_post['post_id'], $marker . ' reject', 'reject' );
list( $reject_submit, $reject_token ) = wp_agent_moderation_submit( $moderation, (int) $reject_post['post_id'] );
$rejected = WPAgent_Moderation::reject( $reject_token );
wp_agent_moderation_assert( ! empty( $rejected['success'] ), 'Rejection should succeed: ' . wp_json_encode( $rejected ) );
wp_agent_moderation_assert( 'draft' === get_post_status( $reject_post['post_id'] ), 'Rejected post should be left as draft.' );

$rejected_row = WPAgent_Moderation::get_by_token( $reject_token );
wp_agent_moderation_assert( $rejected_row && 'rejected' === $rejected_row->status && ! empty( $rejected_row->decided_at ), 'Rejected moderation row should be decided.' );

$expired_post = wp_agent_moderation_create_post( $posts, 'WP Agent Expired Moderation ' . $marker, 'draft' );
wp_update_post( array(
    'ID'           => (int) $expired_post['post_id'],
    'post_content' => wp_agent_moderation_quality_content( $marker . ' expired', 'expired' ),
) );
wp_agent_moderation_prepare_quality_post( (int) $expired_post['post_id'], $marker . ' expired', 'expired' );
list( $expired_submit, $expired_token ) = wp_agent_moderation_submit( $moderation, (int) $expired_post['post_id'] );
$wpdb->update(
    wp_agent_moderation_table(),
    array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 8 * DAY_IN_SECONDS ) ) ),
    array( 'token' => $expired_token ),
    array( '%s' ),
    array( '%s' )
);
$expired = WPAgent_Moderation::approve( $expired_token );
wp_agent_moderation_assert( empty( $expired['success'] ) && false !== strpos( strtolower( $expired['error'] ?? '' ), 'expired' ), 'Expired moderation token should be rejected.' );
wp_agent_moderation_assert( 'pending' === get_post_status( $expired_post['post_id'] ), 'Expired approval attempt should not publish the post.' );

$result = array(
    'success'                => true,
    'published_post_id'      => (int) $publish_attempt['post_id'],
    'rejected_post_id'       => (int) $reject_post['post_id'],
    'expired_post_id'        => (int) $expired_post['post_id'],
    'quality_blocked_post_id' => (int) $draft_before_quality['post_id'],
    'approval_quality_score' => (int) ( $approval_submit['quality']['score'] ?? 0 ),
    'approval_token_length'  => strlen( $approval_token ),
    'syndication_log_count'  => count( $syndication_rows ),
    'publish_downgrade_note' => $publish_attempt['note'] ?? '',
);

wp_agent_moderation_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
