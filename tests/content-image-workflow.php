<?php
/**
 * WP Agent generated-image content workflow checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/content-image-workflow.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This content image workflow script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_content_image_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_content_image_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_content_image_fail( $message );
    }
}

function wp_agent_content_image_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_content_image_response( $b64_json ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'created' => time(),
            'data'    => array(
                array( 'b64_json' => $b64_json ),
            ),
        ) ),
        'response' => array(
            'code'    => 200,
            'message' => 'OK',
        ),
        'cookies'  => array(),
    );
}

WPAgent_Roles::ensure();
$agent_user = WPAgent_Roles::get_user_id();
wp_agent_content_image_assert( $agent_user > 0, 'Bounded agent user is missing.' );

$context = array( $agent_user, 'wpcli', 0, 1, 0 );

$previous = array(
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'image_model'    => WPAgent::get_option( 'image_model', '' ),
    'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$restored = false;
$restore = function() use ( &$restored, $previous ) {
    if ( $restored ) {
        return;
    }
    WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
    WPAgent::update_option( 'meowl_model', $previous['model'] );
    WPAgent::update_option( 'image_model', $previous['image_model'] );
    if ( $previous['budget_exists'] ) {
        update_option( 'wp_agent_monthly_budget', $previous['monthly_budget'] );
    } else {
        delete_option( 'wp_agent_monthly_budget' );
    }
    $restored = true;
};
register_shutdown_function( $restore );

WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-image-test-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-test-model' );
WPAgent::update_option( 'image_model', 'wp-agent-image-test-model' );
WPAgent::update_option( 'monthly_budget', 0 );

$valid_png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
$image_payload = $valid_png;
$image_calls   = 0;
$last_request  = array();
$image_usage_model = WPAgent_Cost_Tracker::image_usage_model( 'wp-agent-image-test-model', '1024x1024' );
global $wpdb;
$usage_before = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    $image_usage_model
) );

add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) use ( &$image_calls, &$image_payload, &$last_request ) {
        if ( false === strpos( (string) $url, '/images/generations' ) ) {
            return $preempt;
        }

        $image_calls++;
        $last_request = array(
            'url'           => (string) $url,
            'authorization' => $parsed_args['headers']['Authorization'] ?? '',
            'body'          => json_decode( (string) ( $parsed_args['body'] ?? '' ), true ),
        );

        return wp_agent_content_image_response( $image_payload );
    },
    10,
    3
);

$images = new WPAgent_Tool_Images();
$images->set_context( ...$context );

$image_payload = base64_encode( 'not-an-image' );
$bad = $images->execute( array(
    'prompt'   => 'This deliberately returns invalid bytes.',
    'title'    => 'Invalid image fixture',
    'alt_text' => 'Invalid generated image fixture.',
) );
wp_agent_content_image_assert( ! empty( $bad['error'] ), 'Invalid generated image bytes should fail closed.' );
$usage_after_invalid = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    $image_usage_model
) );
wp_agent_content_image_assert( $usage_before + 1 === $usage_after_invalid, 'A successful image API response should record usage even when returned bytes fail validation.' );

$image_payload = $valid_png;
$generated = $images->execute( array(
    'prompt'   => 'Editorial illustration for a public-source digital policy article, clean document-style composition.',
    'title'    => 'Digital Policy Briefing Illustration',
    'alt_text' => 'Abstract editorial illustration for a digital policy briefing.',
    'size'     => '1024x1024',
) );
wp_agent_content_image_assert( ! empty( $generated['success'] ) && ! empty( $generated['attachment_id'] ), 'Generated image should import into Media Library: ' . wp_json_encode( $generated ) );
wp_agent_content_image_assert( 'Bearer wp-agent-image-test-key' === ( $last_request['authorization'] ?? '' ), 'Image generation should send the configured API key.' );
wp_agent_content_image_assert( 'b64_json' === ( $last_request['body']['response_format'] ?? '' ), 'Image generation should request b64_json output.' );
wp_agent_content_image_assert( 'wp-agent-image-test-model' === ( $last_request['body']['model'] ?? '' ), 'Image generation should send the configured image model.' );
wp_agent_content_image_assert( 'image/png' === ( $generated['mime_type'] ?? '' ), 'Generated fixture should be stored as image/png.' );
wp_agent_content_image_assert( 1 === (int) ( $generated['width'] ?? 0 ) && 1 === (int) ( $generated['height'] ?? 0 ), 'Generated fixture dimensions should be recorded.' );
wp_agent_content_image_assert( ! empty( $generated['usage_recorded'] ), 'Successful image generation should report usage recording.' );
wp_agent_content_image_assert( $image_usage_model === ( $generated['usage_model'] ?? '' ), 'Generated image usage model should identify the image model and size.' );
wp_agent_content_image_assert( (float) ( $generated['estimated_cost'] ?? 0 ) > 0, 'Generated image result should include a positive estimated cost.' );

$usage_after_generated = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    $image_usage_model
) );
wp_agent_content_image_assert( $usage_before + 2 === $usage_after_generated, 'Valid generated image should add a second image usage row.' );

$tracker = new WPAgent_Cost_Tracker();
$summary = $tracker->get_usage_summary( 1, 'month' );
WPAgent::update_option( 'monthly_budget', (string) (float) $summary['total_cost'] );
$calls_before_budget = $image_calls;
$blocked = $images->execute( array(
    'prompt'   => 'This image should be blocked by the monthly budget before the HTTP request.',
    'title'    => 'Budget blocked image fixture',
    'alt_text' => 'Budget blocked generated image fixture.',
    'size'     => '1024x1024',
) );
wp_agent_content_image_assert( ! empty( $blocked['error'] ) && false !== stripos( (string) $blocked['error'], 'budget' ), 'Image generation should fail closed when the projected image cost reaches the monthly budget.' );
wp_agent_content_image_assert( $calls_before_budget === $image_calls, 'Budget-blocked image generation should not call the image endpoint.' );
WPAgent::update_option( 'monthly_budget', 0 );

$attachment_id = (int) $generated['attachment_id'];
$attachment = get_post( $attachment_id );
wp_agent_content_image_assert( $attachment && 'attachment' === $attachment->post_type, 'Generated image attachment should exist.' );
wp_agent_content_image_assert( 'image/png' === get_post_mime_type( $attachment_id ), 'Generated attachment MIME should be image/png.' );
wp_agent_content_image_assert( 'Abstract editorial illustration for a digital policy briefing.' === get_post_meta( $attachment_id, '_wp_attachment_alt_text', true ), 'Generated image alt text should be stored.' );

$file_path  = get_attached_file( $attachment_id );
$upload_dir = wp_upload_dir();
wp_agent_content_image_assert( wp_agent_content_image_path_starts_with( $file_path, $upload_dir['basedir'] ), 'Generated image file should live in WordPress uploads.' );
wp_agent_content_image_assert( ! wp_agent_content_image_path_starts_with( $file_path, WP_AGENT_PLUGIN_DIR ), 'Generated image file must not live under the plugin directory.' );

$media = new WPAgent_Tool_Media();
$media->set_context( ...$context );
$media_update = $media->execute( array(
    'action'        => 'update',
    'attachment_id' => $attachment_id,
    'alt_text'      => 'Updated alt text for the digital policy briefing illustration.',
    'caption'       => 'Generated editorial image for a WP Agent content workflow acceptance test.',
    'title'         => 'Updated Digital Policy Illustration',
) );
wp_agent_content_image_assert( ! empty( $media_update['success'] ), 'Media metadata update should succeed: ' . wp_json_encode( $media_update ) );
$media_get = $media->execute( array( 'action' => 'get', 'attachment_id' => $attachment_id ) );
wp_agent_content_image_assert( 'Updated alt text for the digital policy briefing illustration.' === ( $media_get['alt_text'] ?? '' ), 'Updated media alt text should be readable.' );
wp_agent_content_image_assert( 'Generated editorial image for a WP Agent content workflow acceptance test.' === ( $media_get['caption'] ?? '' ), 'Updated media caption should be readable.' );

$taxonomies = new WPAgent_Tool_Taxonomies();
$taxonomies->set_context( ...$context );
$category = $taxonomies->execute( array(
    'action'      => 'create',
    'taxonomy'    => 'category',
    'name'        => 'Generated Images',
    'slug'        => 'generated-images',
    'description' => 'Acceptance category for generated-image content workflows.',
) );
wp_agent_content_image_assert( ! empty( $category['success'] ), 'Generated Images category should be created or reused: ' . wp_json_encode( $category ) );
$category_again = $taxonomies->execute( array(
    'action'   => 'create',
    'taxonomy' => 'category',
    'name'     => 'Generated Images',
    'slug'     => 'generated-images',
) );
wp_agent_content_image_assert( ! empty( $category_again['success'] ) && ! empty( $category_again['existing'] ), 'Duplicate category creation should return the existing term.' );

$posts = new WPAgent_Tool_Posts();
$posts->set_context( ...$context );
$source_urls = array(
    'https://www.un.org/press/en',
    'https://www.reuters.com/technology/',
);
$post = $posts->execute( array(
    'action'            => 'create',
    'title'             => 'Generated Image Workflow Preserves Source Context',
    'content'           => '<p>This deterministic acceptance draft verifies that generated media can become a featured image while original source context and SEO metadata remain attached.</p>',
    'excerpt'           => 'Generated-image content workflow acceptance draft.',
    'status'            => 'draft',
    'categories'        => array( 'Generated Images' ),
    'tags'              => array( 'Generated Media', 'Public Source' ),
    'source_urls'       => $source_urls,
    'source_notes'      => 'Acceptance fixture for generated-image workflow; original prose, no copied source text.',
    'featured_image_id' => $attachment_id,
) );
wp_agent_content_image_assert( ! empty( $post['success'] ) && ! empty( $post['post_id'] ), 'Draft with generated image should be created: ' . wp_json_encode( $post ) );
wp_agent_content_image_assert( 'draft' === $post['status'], 'Generated-image acceptance post should remain a draft.' );
wp_agent_content_image_assert( $attachment_id === (int) get_post_thumbnail_id( $post['post_id'] ), 'Generated image should be assigned as featured image.' );
wp_agent_content_image_assert( $source_urls === ( $post['metadata']['source_urls'] ?? array() ), 'Source URLs should be retained on the generated-image draft.' );

$seo = new WPAgent_Tool_SEO();
$seo->set_context( ...$context );
$seo_update = $seo->execute( array(
    'action'           => 'update',
    'post_id'          => $post['post_id'],
    'meta_title'       => 'Generated Image Workflow Source Context',
    'meta_description' => 'WP Agent acceptance draft proving generated images, source URLs, featured media, and SEO metadata work together.',
    'focus_keyword'    => 'generated image workflow',
) );
wp_agent_content_image_assert( ! empty( $seo_update['success'] ), 'SEO update should succeed: ' . wp_json_encode( $seo_update ) );
$seo_read = $seo->execute( array( 'action' => 'get', 'post_id' => $post['post_id'] ) );
wp_agent_content_image_assert( 'generated image workflow' === ( $seo_read['focus_keyword'] ?? '' ), 'SEO focus keyword should be stored.' );

echo wp_json_encode( array(
    'success'       => true,
    'image_calls'   => (int) $image_calls,
    'attachment_id' => $attachment_id,
    'post_id'       => (int) $post['post_id'],
    'usage_model'   => $image_usage_model,
    'image_usage_delta' => $usage_after_generated - $usage_before,
    'image_url'     => $generated['url'],
    'source_urls'   => $post['metadata']['source_urls'],
    'seo_keyword'   => $seo_read['focus_keyword'],
) ) . "\n";
