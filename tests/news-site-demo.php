<?php
/**
 * Deterministic WP Agent news-site workflow acceptance demo.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/news-site-demo.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This acceptance script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_news_demo_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_news_demo_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_news_demo_fail( $message );
    }
}

function wp_agent_news_demo_image_attachment() {
    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true );
    wp_agent_news_demo_assert( false !== $png, 'Fixture image could not be decoded.' );

    $upload = wp_upload_bits( 'wp-agent-news-demo.png', null, $png );
    wp_agent_news_demo_assert( empty( $upload['error'] ) && ! empty( $upload['file'] ), 'Fixture image upload failed.' );

    $attachment_id = wp_insert_attachment( array(
        'post_title'     => 'WP Agent News Demo Image',
        'post_excerpt'   => 'Generated fixture image for WP Agent news demo acceptance.',
        'post_mime_type' => 'image/png',
        'post_status'    => 'inherit',
    ), $upload['file'] );
    wp_agent_news_demo_assert( ! is_wp_error( $attachment_id ) && $attachment_id > 0, 'Fixture image attachment could not be created.' );

    $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
    if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    return (int) $attachment_id;
}

WPAgent_Roles::ensure();
$agent_user = WPAgent_Roles::get_user_id();
wp_agent_news_demo_assert( $agent_user > 0, 'Bounded agent user is missing.' );

$context = array( $agent_user, 'wpcli', 0, 1, 0 );

$skills = new WPAgent_Tool_Skills();
$skills->set_context( ...$context );
$templates = $skills->execute( array( 'action' => 'list_templates' ) );
wp_agent_news_demo_assert( ! empty( $templates['success'] ), 'Built-in Skill templates are unavailable.' );
$installed = WPAgent_Skills::install_template( 1, 'news-site-operator' );
wp_agent_news_demo_assert( ! is_wp_error( $installed ), is_wp_error( $installed ) ? $installed->get_error_message() : 'Skill template install failed.' );

$taxonomies = new WPAgent_Tool_Taxonomies();
$taxonomies->set_context( ...$context );
$world = $taxonomies->execute( array(
    'action'      => 'create',
    'taxonomy'    => 'category',
    'name'        => 'World Affairs',
    'slug'        => 'world-affairs',
    'description' => 'Global public-source news coverage.',
) );
wp_agent_news_demo_assert( ! empty( $world['success'] ), 'World Affairs category was not created: ' . wp_json_encode( $world ) );
$tech = $taxonomies->execute( array(
    'action'      => 'create',
    'taxonomy'    => 'category',
    'name'        => 'Technology',
    'slug'        => 'technology',
    'description' => 'Technology policy, platforms, and infrastructure.',
) );
wp_agent_news_demo_assert( ! empty( $tech['success'] ), 'Technology category was not created: ' . wp_json_encode( $tech ) );
$source_tag = $taxonomies->execute( array(
    'action'   => 'create',
    'taxonomy' => 'post_tag',
    'name'     => 'Public Source',
    'slug'     => 'public-source',
) );
wp_agent_news_demo_assert( ! empty( $source_tag['success'] ), 'Public Source tag was not created: ' . wp_json_encode( $source_tag ) );

$image_id = wp_agent_news_demo_image_attachment();
$media = new WPAgent_Tool_Media();
$media->set_context( ...$context );
$media_update = $media->execute( array(
    'action'        => 'update',
    'attachment_id' => $image_id,
    'alt_text'      => 'Abstract public-source news briefing image.',
    'caption'       => 'WP Agent demo image for a public-source news draft.',
    'title'         => 'Public-source briefing image',
) );
wp_agent_news_demo_assert( ! empty( $media_update['success'] ), 'Media metadata update failed: ' . wp_json_encode( $media_update ) );

$source_urls = array(
    'https://www.un.org/press/en',
    'https://www.reuters.com/world/',
);

$posts = new WPAgent_Tool_Posts();
$posts->set_context( ...$context );
$post = $posts->execute( array(
    'action'            => 'create',
    'title'             => 'Global Institutions Expand Public Briefings on Digital Policy',
    'content'           => '<p>Global institutions are widening public briefings on digital policy as governments weigh new rules for online platforms, data infrastructure, and cross-border services.</p><p>This demo article is original acceptance content. It preserves source URLs for follow-up reporting and avoids copying source language.</p><h2>Sources</h2><ul><li>United Nations press materials</li><li>Reuters world news index</li></ul>',
    'excerpt'           => 'A deterministic WP Agent acceptance draft showing source retention, taxonomy, SEO, and media workflow.',
    'status'            => 'draft',
    'categories'        => array( 'World Affairs', 'Technology' ),
    'tags'              => array( 'Public Source', 'Digital Policy' ),
    'source_urls'       => $source_urls,
    'source_notes'      => 'Acceptance fixture: original prose based on public-source style notes, not copied article text.',
    'featured_image_id' => $image_id,
) );
wp_agent_news_demo_assert( ! empty( $post['success'] ) && ! empty( $post['post_id'] ), 'Draft post was not created: ' . wp_json_encode( $post ) );
wp_agent_news_demo_assert( 'draft' === $post['status'], 'Acceptance post must remain a draft.' );
wp_agent_news_demo_assert( ! empty( $post['preview_url'] ), 'Draft post did not return a preview URL.' );
wp_agent_news_demo_assert( has_term( 'World Affairs', 'category', $post['post_id'] ), 'Draft post missing World Affairs category.' );
wp_agent_news_demo_assert( has_term( 'Technology', 'category', $post['post_id'] ), 'Draft post missing Technology category.' );
wp_agent_news_demo_assert( has_term( 'Public Source', 'post_tag', $post['post_id'] ), 'Draft post missing Public Source tag.' );
wp_agent_news_demo_assert( $image_id === (int) get_post_thumbnail_id( $post['post_id'] ), 'Featured image was not assigned.' );
wp_agent_news_demo_assert( $source_urls === ( $post['metadata']['source_urls'] ?? array() ), 'Source URLs were not retained in post metadata.' );

$seo = new WPAgent_Tool_SEO();
$seo->set_context( ...$context );
$seo_update = $seo->execute( array(
    'action'           => 'update',
    'post_id'          => $post['post_id'],
    'meta_title'       => 'Global Digital Policy Briefings Expand',
    'meta_description' => 'Public-source draft on digital policy briefings with retained source URLs, taxonomy, media, and SEO metadata.',
    'focus_keyword'    => 'digital policy briefings',
) );
wp_agent_news_demo_assert( ! empty( $seo_update['success'] ), 'SEO update failed: ' . wp_json_encode( $seo_update ) );
$seo_read = $seo->execute( array( 'action' => 'get', 'post_id' => $post['post_id'] ) );
wp_agent_news_demo_assert( 'digital policy briefings' === ( $seo_read['focus_keyword'] ?? '' ), 'SEO focus keyword was not stored.' );

$journal = new WPAgent_Tool_Journal();
$journal->set_context( ...$context );
$journal_entry = $journal->execute( array(
    'action'     => 'add',
    'entry_type' => 'decision',
    'title'      => 'News-site demo editorial plan',
    'body'       => 'Created World Affairs and Technology categories, Public Source tag, a draft article with retained source URLs, SEO metadata, and a featured image. Installed and scheduled the News Site Operator Skill.',
) );
wp_agent_news_demo_assert( ! empty( $journal_entry['success'] ), 'Journal entry was not stored.' );

$schedules = new WPAgent_Tool_Schedules();
$schedules->set_context( ...$context );
$schedule = $schedules->execute( array(
    'action'           => 'create',
    'natural_language' => '每天早上8点',
    'prompt'           => 'Find 2 public-source stories for the World Affairs and Technology sections, retain source URLs, draft original summaries with SEO metadata and image suggestions, and leave them as drafts.',
    'skill_slug'       => 'news-site-operator',
) );
wp_agent_news_demo_assert( ! empty( $schedule['success'] ) && ! empty( $schedule['schedule_id'] ), 'News schedule was not created: ' . wp_json_encode( $schedule ) );
wp_agent_news_demo_assert( 'daily' === $schedule['interval'] && '08:00' === $schedule['time'], 'Natural-language schedule did not normalize to daily 08:00.' );

$run = WPAgent_Schedules::run( $schedule['schedule_id'] );
wp_agent_news_demo_assert( ! empty( $run['ok'] ) && ! empty( $run['run_id'] ), 'Schedule run was not queued: ' . wp_json_encode( $run ) );
global $wpdb;
$queued_message = $wpdb->get_var( $wpdb->prepare(
    "SELECT content FROM {$wpdb->prefix}wp_agent_messages WHERE id = (SELECT message_id FROM {$wpdb->prefix}wp_agent_runs WHERE id = %d)",
    (int) $run['run_id']
) );
wp_agent_news_demo_assert( false !== strpos( $queued_message, 'Bound Skill: News Site Operator' ), 'Queued schedule missing bound Skill header.' );
wp_agent_news_demo_assert( false !== strpos( $queued_message, '## Quality Gate' ), 'Queued schedule missing Skill quality gate.' );
wp_agent_news_demo_assert( false !== strpos( $queued_message, 'source URLs' ), 'Queued schedule missing source-retention instruction.' );
wp_agent_news_demo_assert( false !== strpos( $queued_message, 'manage_taxonomies' ), 'Queued schedule missing taxonomy planning guidance.' );

echo wp_json_encode( array(
    'success'       => true,
    'post_id'       => (int) $post['post_id'],
    'preview_url'   => $post['preview_url'],
    'category_ids'  => array( (int) $world['term']['term_id'], (int) $tech['term']['term_id'] ),
    'image_id'      => $image_id,
    'schedule_id'   => (int) $schedule['schedule_id'],
    'run_id'        => (int) $run['run_id'],
    'source_urls'   => $post['metadata']['source_urls'],
    'seo_keyword'   => $seo_read['focus_keyword'],
) ) . "\n";
