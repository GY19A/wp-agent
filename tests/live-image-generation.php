<?php
/**
 * Live image generation acceptance.
 *
 * This test uses the configured OpenAI-compatible image endpoint and may incur cost.
 * Run only when explicitly enabled:
 *
 * WP_AGENT_LIVE_IMAGE=1 wp eval-file wp-content/plugins/wp-agent/tests/live-image-generation.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This live image acceptance script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_live_image_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_live_image_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_live_image_fail( $message );
    }
}

function wp_agent_live_image_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

if ( '1' !== (string) getenv( 'WP_AGENT_LIVE_IMAGE' ) ) {
    echo wp_json_encode( array(
        'skipped' => true,
        'reason'  => 'Set WP_AGENT_LIVE_IMAGE=1 to run the credentials-backed live image acceptance.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    return;
}

global $wpdb;

$api_key = WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' ) );
wp_agent_live_image_assert( '' !== $api_key, 'Configured AI gateway API key is required.' );

$model = (string) WPAgent::get_option( 'meowl_model', '' );
wp_agent_live_image_assert( '' !== $model, 'Configured AI model is required.' );

$previous_budget_sentinel = '__wp_agent_live_image_missing_budget__';
$previous_budget          = get_option( 'wp_agent_monthly_budget', $previous_budget_sentinel );
$restored_environment     = false;
$restore_environment      = function() use ( &$restored_environment, $previous_budget, $previous_budget_sentinel ) {
    if ( $restored_environment ) {
        return;
    }
    if ( $previous_budget_sentinel === $previous_budget ) {
        delete_option( 'wp_agent_monthly_budget' );
    } else {
        update_option( 'wp_agent_monthly_budget', $previous_budget );
    }
    $restored_environment = true;
};
register_shutdown_function( $restore_environment );
WPAgent::update_option( 'monthly_budget', 0 );

WPAgent_Roles::ensure();
$agent_user = WPAgent_Roles::get_user_id();
wp_agent_live_image_assert( $agent_user > 0, 'Bounded agent user is missing.' );

$tool = new WPAgent_Tool_Images();
$tool->set_context( $agent_user, 'wpcli', 0, 1, 0 );

$title = 'WP Agent Live Image Verification ' . gmdate( 'Ymd-His' );
$image_size = '1024x1024';
$image_usage_model = WPAgent_Cost_Tracker::image_usage_model( WPAgent::get_option( 'image_model', '' ), $image_size );
$usage_before = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    $image_usage_model
) );

$result = $tool->execute( array(
    'prompt'   => 'Clean editorial illustration for a WordPress AI agent live verification article, document-style layout, no text.',
    'title'    => $title,
    'alt_text' => 'Editorial illustration generated during WP Agent live image verification.',
    'size'     => $image_size,
) );

wp_agent_live_image_assert( ! empty( $result['success'] ) && ! empty( $result['attachment_id'] ), 'Live image generation failed: ' . wp_json_encode( $result ) );
wp_agent_live_image_assert( ! empty( $result['usage_recorded'] ), 'Live image generation should record image usage.' );
wp_agent_live_image_assert( $image_usage_model === ( $result['usage_model'] ?? '' ), 'Live image usage model should identify the configured image model and size.' );

$attachment_id = (int) $result['attachment_id'];
$attachment    = get_post( $attachment_id );
wp_agent_live_image_assert( $attachment && 'attachment' === $attachment->post_type, 'Generated image attachment should exist.' );
wp_agent_live_image_assert( 0 === strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ), 'Generated attachment should be an image.' );
wp_agent_live_image_assert( 'Editorial illustration generated during WP Agent live image verification.' === get_post_meta( $attachment_id, '_wp_attachment_alt_text', true ), 'Generated image alt text should be stored.' );

$file_path  = get_attached_file( $attachment_id );
$upload_dir = wp_upload_dir();
wp_agent_live_image_assert( wp_agent_live_image_path_starts_with( $file_path, $upload_dir['basedir'] ), 'Generated image should live in WordPress uploads.' );
wp_agent_live_image_assert( ! wp_agent_live_image_path_starts_with( $file_path, WP_AGENT_PLUGIN_DIR ), 'Generated image must not live under the plugin directory.' );

$usage_after = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    $image_usage_model
) );
wp_agent_live_image_assert( $usage_after > $usage_before, 'Live image generation should add a usage row.' );

$restore_environment();

echo wp_json_encode( array(
    'success'       => true,
    'attachment_id' => $attachment_id,
    'mime_type'     => get_post_mime_type( $attachment_id ),
    'width'         => (int) ( $result['width'] ?? 0 ),
    'height'        => (int) ( $result['height'] ?? 0 ),
    'url_present'   => '' !== (string) ( $result['url'] ?? '' ),
    'chat_model'    => $model,
    'image_model'   => WPAgent::get_option( 'image_model', '' ),
    'usage_model'   => $image_usage_model,
    'usage_rows_added' => $usage_after - $usage_before,
    'base_url'      => WPAgent_AI_Meowl::base_url(),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
