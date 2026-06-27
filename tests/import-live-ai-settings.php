<?php
/**
 * Import live AI settings from STDIN for acceptance environments.
 *
 * This script re-encrypts the API key with the current WordPress salts and
 * prints only a redacted summary. Run only when explicitly enabled:
 *
 * WP_AGENT_IMPORT_LIVE_AI_SETTINGS=1 wp eval-file wp-content/plugins/wp-agent/tests/import-live-ai-settings.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This live settings import script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_import_live_settings_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_import_live_settings_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_import_live_settings_fail( $message );
    }
}

if ( '1' !== (string) getenv( 'WP_AGENT_IMPORT_LIVE_AI_SETTINGS' ) ) {
    echo wp_json_encode( array(
        'skipped' => true,
        'reason'  => 'Set WP_AGENT_IMPORT_LIVE_AI_SETTINGS=1 to import live AI settings from STDIN.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    return;
}

$raw = stream_get_contents( STDIN );
wp_agent_import_live_settings_assert( is_string( $raw ) && '' !== trim( $raw ), 'Expected JSON settings on STDIN.' );

$settings = json_decode( $raw, true );
wp_agent_import_live_settings_assert( is_array( $settings ), 'STDIN must be a JSON object.' );

$api_key = isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : '';
wp_agent_import_live_settings_assert( '' !== $api_key, 'api_key is required.' );
wp_agent_import_live_settings_assert( strlen( $api_key ) <= 8192, 'api_key is unexpectedly large.' );

$base_url = isset( $settings['base_url'] ) ? trim( (string) $settings['base_url'] ) : '';
$base_url = WPAgent_AI_Meowl::normalize_base_url( $base_url );
wp_agent_import_live_settings_assert( ! is_wp_error( $base_url ) && '' !== $base_url, 'base_url must be a valid OpenAI-compatible HTTP(S) endpoint.' );

$chat_model = isset( $settings['chat_model'] ) ? sanitize_text_field( (string) $settings['chat_model'] ) : '';
wp_agent_import_live_settings_assert( '' !== $chat_model, 'chat_model is required.' );
wp_agent_import_live_settings_assert( strlen( $chat_model ) <= 200, 'chat_model is unexpectedly large.' );

$image_model = isset( $settings['image_model'] ) ? sanitize_text_field( (string) $settings['image_model'] ) : '';
wp_agent_import_live_settings_assert( strlen( $image_model ) <= 200, 'image_model is unexpectedly large.' );

WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( $api_key ) );
WPAgent::update_option( 'meowl_model', $chat_model );
WPAgent::update_option( 'image_model', $image_model );
WPAgent::update_option( 'ai_base_url', $base_url );

unset( $api_key, $settings, $raw );

$stored_key = (string) WPAgent::get_option( 'meowl_api_key', '' );
wp_agent_import_live_settings_assert( '' !== WPAgent::decrypt( $stored_key ), 'Stored API key could not be decrypted after import.' );

echo wp_json_encode( array(
    'success'              => true,
    'base_url'             => WPAgent_AI_Meowl::base_url(),
    'base_url_source'      => WPAgent_AI_Meowl::base_url_source(),
    'has_key'              => '' !== $stored_key,
    'stored_key_length'    => strlen( $stored_key ),
    'chat_model'           => WPAgent::get_option( 'meowl_model', '' ),
    'image_model'          => WPAgent::get_option( 'image_model', '' ),
    'plaintext_disclosed'  => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
