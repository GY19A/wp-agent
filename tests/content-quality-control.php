<?php
/**
 * WP Agent content quality control checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/content-quality-control.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This content quality control script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_quality_post_ids'] = array();
$GLOBALS['wp_agent_quality_marker']   = 'WP Agent Quality Fixture ' . wp_generate_uuid4();

function wp_agent_quality_cleanup() {
    foreach ( array_reverse( $GLOBALS['wp_agent_quality_post_ids'] ) as $post_id ) {
        if ( get_post( $post_id ) ) {
            wp_delete_post( $post_id, true );
        }
    }
}

function wp_agent_quality_fail( $message ) {
    wp_agent_quality_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_quality_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_quality_fail( $message );
    }
}

function wp_agent_quality_create_post( $title, $content, $status = 'draft' ) {
    $post_id = wp_insert_post( array(
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => $status,
        'post_type'    => 'post',
    ), true );
    wp_agent_quality_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Fixture post should be created.' );
    $GLOBALS['wp_agent_quality_post_ids'][] = (int) $post_id;
    return (int) $post_id;
}

function wp_agent_quality_long_content( $marker ) {
    return '<p>' . $marker . ' reports on public digital infrastructure planning, regional governance meetings, and newsroom workflow design. The article uses original phrasing and preserves public-source context for editorial review before publication.</p>'
        . '<p>Editors compare local needs, public records, platform accountability, and technical resilience before deciding whether the story is ready. The draft avoids copying source language and keeps enough context for search optimization, source tracing, and image planning.</p>';
}

register_shutdown_function( 'wp_agent_quality_cleanup' );

WPAgent_Roles::ensure();
$agent_user = WPAgent_Roles::get_user_id();
wp_agent_quality_assert( $agent_user > 0, 'Bounded agent user is missing.' );

$registry = new WPAgent_Tools();
$tool = $registry->get_tool( 'content_quality' );
wp_agent_quality_assert( $tool instanceof WPAgent_Tool_Content_Quality, 'Content quality tool should be registered.' );
$tool->set_context( $agent_user, 'wpcli', 0, 1, 0 );

$marker = $GLOBALS['wp_agent_quality_marker'];
$baseline_content = wp_agent_quality_long_content( $marker );
$baseline_id = wp_agent_quality_create_post( $marker . ' Baseline', $baseline_content, 'publish' );

$healthy = $tool->execute( array(
    'action'           => 'audit_text',
    'title'            => 'Community Libraries Expand Digital Learning Programs',
    'content'          => str_repeat( '<h2>Program detail section</h2><p>Community libraries keep expanding digital learning programs for students, workers, and older residents who need practical help with online services, covering safe account access, basic device skills, public benefit forms, scam awareness, resume building, and accessibility features through staff-led small-group instruction; the phased rollout moves branch by branch so early pilots surface the questions residents ask most and later branches reuse proven materials and volunteer training to reach more neighborhoods.</p>', 8 ) . '<h2>Why libraries are leaning into digital skills</h2><p>Community libraries are expanding digital learning programs for students, workers, and older residents who need practical help with online services. The initiative focuses on safe account access, basic device skills, public benefit forms, and small-group instruction led by trained staff. Demand has grown as more public services move online, leaving residents without confident digital skills at a disadvantage when they try to renew documents, apply for benefits, or manage appointments.</p><p>The draft is written as original editorial material for a local civic audience. It explains program goals, operational context, and reader relevance without reusing the infrastructure governance fixture stored elsewhere in the test database. Each section is intended to stand on its own so that an editor can extend it with local detail later.</p><h2>What the programs actually cover</h2><p>The programs are organized around a small number of practical outcomes rather than abstract technology lessons. Staff help residents create and recover accounts safely, recognize common scams, and understand which official websites are trustworthy. Sessions also cover device basics such as updating software, managing storage, and connecting to public networks without exposing personal information.</p><p>For job seekers, the libraries run focused workshops on building a simple resume, searching listings, and completing online applications. For older residents, the emphasis shifts toward confidence: navigating menus, enlarging text, and using accessibility features that make everyday tasks less intimidating. Trained staff lead small groups so that each participant can ask questions without feeling rushed.</p><h2>How the rollout is structured</h2><p>Rather than launching every service at once, the libraries phase the rollout branch by branch. Early branches act as pilots, surfacing the questions residents ask most often and revealing where instructions need to be simpler. Lessons learned in those pilots feed directly into printed guides, short reference cards, and the training that new volunteers receive before they assist the public.</p><p>Scheduling is deliberately flexible. Drop-in hours sit alongside booked sessions so that residents who cannot commit to a fixed time still have a path to help. Where possible, the libraries coordinate with local employment centers and senior groups so that referrals flow naturally and no single branch becomes overwhelmed.</p><h2>Measuring whether it works</h2><p>Success is measured less by attendance and more by whether residents can complete a real task on their own afterward. Staff track simple indicators: did the participant log in unassisted, complete the form, or leave knowing where to return for help. These signals are easier to act on than raw headcounts and keep the program honest about its impact.</p><p>Editors can use the retained source note to verify the public-service angle, add local interviews later, and prepare a neutral illustration before publication. The goal of this draft is to give a clear, original explanation of the initiative that a civic newsroom could responsibly expand, fact-check, and publish.</p>',
    'source_urls'      => array( 'https://example.com/public-records' ),
    'source_notes'     => 'Fixture source notes: public records and editorial planning context.',
    'meta_title'       => 'Community Libraries Expand Digital Learning Programs',
    'meta_description' => 'Community libraries expand practical digital learning programs that help residents access online services safely, with staff-led small-group support.',
    'focus_keyword'    => 'digital learning programs',
    'limit'            => 10,
) );
wp_agent_quality_assert( ! empty( $healthy['success'] ), 'Healthy draft audit should succeed.' );
wp_agent_quality_assert( 'pass' === ( $healthy['status'] ?? '' ), 'Healthy draft should pass: ' . wp_json_encode( $healthy ) );
wp_agent_quality_assert( 'low' === ( $healthy['checks']['duplicate']['risk'] ?? '' ), 'Healthy draft should have low duplicate risk.' );
wp_agent_quality_assert( 1 === (int) ( $healthy['checks']['provenance']['source_count'] ?? 0 ), 'Healthy draft should retain one valid source.' );

$invalid = $tool->execute( array(
    'action'           => 'audit_text',
    'title'            => 'Short',
    'content'          => '<p>Short draft with graphic violence and no useful structure.</p>',
    'source_urls'      => array( 'http://127.0.0.1/private' ),
    'source_notes'     => '',
    'meta_title'       => '',
    'meta_description' => '',
    'focus_keyword'    => '',
) );
wp_agent_quality_assert( ! empty( $invalid['success'] ), 'Invalid draft audit should still return structured results.' );
wp_agent_quality_assert( 'revise' === ( $invalid['status'] ?? '' ), 'Invalid draft should require revision.' );
wp_agent_quality_assert( in_array( 'invalid_source_urls', $invalid['issues'] ?? array(), true ), 'Invalid source URL should be flagged.' );
wp_agent_quality_assert( in_array( 'sensitive_topic_review', $invalid['issues'] ?? array(), true ), 'Sensitive topic should be flagged.' );
wp_agent_quality_assert( in_array( 'content_too_short', $invalid['issues'] ?? array(), true ), 'Short content should be flagged.' );

$duplicate_id = wp_agent_quality_create_post( $marker . ' Duplicate', $baseline_content, 'draft' );
update_post_meta( $duplicate_id, '_wp_agent_source_urls', wp_json_encode( array( 'https://example.com/public-records' ) ) );
update_post_meta( $duplicate_id, '_wp_agent_source_notes', 'Fixture source notes retained for duplicate audit.' );
update_post_meta( $duplicate_id, '_wp_agent_meta_title', $marker . ' Duplicate' );
update_post_meta( $duplicate_id, '_wp_agent_meta_description', 'A duplicate-quality fixture keeps enough metadata to isolate duplicate risk during deterministic acceptance testing.' );
update_post_meta( $duplicate_id, '_wp_agent_focus_keyword', 'quality fixture duplicate' );

$before_modified = get_post_field( 'post_modified_gmt', $duplicate_id );
$duplicate = $tool->execute( array(
    'action'  => 'audit_post',
    'post_id' => $duplicate_id,
    'limit'   => 10,
) );
$after_modified = get_post_field( 'post_modified_gmt', $duplicate_id );

wp_agent_quality_assert( ! empty( $duplicate['success'] ), 'Duplicate post audit should succeed.' );
wp_agent_quality_assert( 'high' === ( $duplicate['checks']['duplicate']['risk'] ?? '' ), 'Duplicate post should have high duplicate risk: ' . wp_json_encode( $duplicate['checks']['duplicate'] ?? array() ) );
wp_agent_quality_assert( in_array( 'high_duplicate_risk', $duplicate['issues'] ?? array(), true ), 'High duplicate risk should be in issues.' );
wp_agent_quality_assert( $baseline_id === (int) ( $duplicate['checks']['duplicate']['matches'][0]['post_id'] ?? 0 ), 'Duplicate match should point at the baseline post.' );
wp_agent_quality_assert( $before_modified === $after_modified, 'Quality audit must not mutate the audited post.' );

wp_agent_quality_cleanup();

echo wp_json_encode( array(
    'success' => true,
    'healthy' => array(
        'status' => $healthy['status'],
        'score'  => $healthy['score'],
    ),
    'invalid' => array(
        'status' => $invalid['status'],
        'issues' => $invalid['issues'],
    ),
    'duplicate' => array(
        'status'         => $duplicate['status'],
        'risk'           => $duplicate['checks']['duplicate']['risk'],
        'max_similarity' => $duplicate['checks']['duplicate']['max_similarity'],
    ),
) ) . "\n";
