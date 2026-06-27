<?php
/**
 * WP Agent AI provider readiness checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/ai-provider-readiness.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "This AI provider readiness script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_ai_readiness_previous'] = array();

function wp_agent_ai_readiness_capture_option( $name ) {
    $GLOBALS['wp_agent_ai_readiness_previous'][ $name ] = array(
        'exists' => false !== get_option( $name, false ),
        'value'  => get_option( $name, null ),
    );
}

function wp_agent_ai_readiness_restore() {
    foreach ( $GLOBALS['wp_agent_ai_readiness_previous'] as $name => $previous ) {
        if ( ! empty( $previous['exists'] ) ) {
            update_option( $name, $previous['value'] );
        } else {
            delete_option( $name );
        }
    }
}

function wp_agent_ai_readiness_fail( $message ) {
    wp_agent_ai_readiness_restore();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_ai_readiness_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_ai_readiness_fail( $message );
    }
}

register_shutdown_function( 'wp_agent_ai_readiness_restore' );

foreach ( array(
    'wp_agent_meowl_api_key',
    'wp_agent_meowl_model',
    'wp_agent_image_model',
    'wp_agent_ai_base_url',
) as $option_name ) {
    wp_agent_ai_readiness_capture_option( $option_name );
    delete_option( $option_name );
}

$empty = WPAgent::ai_provider_readiness();
wp_agent_ai_readiness_assert( empty( $empty['ready'] ), 'Empty AI settings should not be ready.' );
wp_agent_ai_readiness_assert( in_array( 'api_key', $empty['missing'], true ), 'Empty settings should report missing API key.' );
wp_agent_ai_readiness_assert( in_array( 'model', $empty['missing'], true ), 'Empty settings should report missing model.' );
wp_agent_ai_readiness_assert( 'not_configured' === ( $empty['api_key_state'] ?? '' ), 'Empty settings should report API key not configured.' );

WPAgent::update_option( 'meowl_api_key', 'not-an-encrypted-ai-key' );
WPAgent::update_option( 'meowl_model', 'wp-agent-readiness-model' );
$bad_key = WPAgent::ai_provider_readiness();
wp_agent_ai_readiness_assert( empty( $bad_key['ready'] ), 'Unreadable API key should not be ready.' );
wp_agent_ai_readiness_assert( 'unreadable' === ( $bad_key['api_key_state'] ?? '' ), 'Unreadable API key should be reported.' );
wp_agent_ai_readiness_assert( in_array( 'api_key_unreadable', $bad_key['warnings'], true ), 'Unreadable API key warning should be present.' );
wp_agent_ai_readiness_assert( 'resave_api_key' === ( $bad_key['next_action'] ?? '' ), 'Unreadable API key should request re-save.' );

WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-ai-readiness-key' ) );
WPAgent::update_option( 'meowl_model', '' );
$missing_model = WPAgent::ai_provider_readiness();
wp_agent_ai_readiness_assert( empty( $missing_model['ready'] ), 'Missing chat model should not be ready.' );
wp_agent_ai_readiness_assert( 'decryptable' === ( $missing_model['api_key_state'] ?? '' ), 'Encrypted API key should be decryptable.' );
wp_agent_ai_readiness_assert( in_array( 'model', $missing_model['missing'], true ), 'Missing model should be reported.' );
wp_agent_ai_readiness_assert( 'select_model' === ( $missing_model['next_action'] ?? '' ), 'Missing model should request model selection.' );

WPAgent::update_option( 'meowl_model', 'wp-agent-readiness-model' );
WPAgent::update_option( 'image_model', '' );
WPAgent::update_option( 'ai_base_url', 'https://readiness.example.test/v1' );
$text_ready = WPAgent::ai_provider_readiness();
wp_agent_ai_readiness_assert( ! empty( $text_ready['ready'] ), 'Decryptable key and chat model should make text generation ready.' );
wp_agent_ai_readiness_assert( ! empty( $text_ready['content_ready'] ), 'Content readiness should be true.' );
wp_agent_ai_readiness_assert( empty( $text_ready['image_generation_ready'] ), 'Image readiness should be false when image model is missing.' );
wp_agent_ai_readiness_assert( in_array( 'image_model_missing', $text_ready['warnings'], true ), 'Missing image model warning should be present.' );
wp_agent_ai_readiness_assert( 'configure_image_model' === ( $text_ready['next_action'] ?? '' ), 'Missing image model should request image setup.' );
wp_agent_ai_readiness_assert( 'readiness.example.test' === ( $text_ready['base_url_host'] ?? '' ), 'AI readiness should expose endpoint host only.' );
wp_agent_ai_readiness_assert( false === strpos( wp_json_encode( $text_ready ), 'wp-agent-ai-readiness-key' ), 'AI readiness must not disclose API key plaintext.' );

WPAgent::update_option( 'image_model', 'wp-agent-readiness-image-model' );
$full_ready = WPAgent::ai_provider_readiness();
wp_agent_ai_readiness_assert( ! empty( $full_ready['ready'] ), 'Full AI settings should be ready.' );
wp_agent_ai_readiness_assert( ! empty( $full_ready['image_generation_ready'] ), 'Image readiness should be true with image model.' );
wp_agent_ai_readiness_assert( 'ready' === ( $full_ready['next_action'] ?? '' ), 'Full AI settings should report ready.' );

$diagnostics = WPAgent_Diagnostics::runtime();
$diag_ai     = $diagnostics['ai'] ?? array();
wp_agent_ai_readiness_assert( ! empty( $diag_ai['ready'] ), 'Runtime diagnostics should expose ready AI state.' );
wp_agent_ai_readiness_assert( 'wp-agent-readiness-model' === ( $diag_ai['model'] ?? '' ), 'Runtime diagnostics should expose configured model.' );
wp_agent_ai_readiness_assert( false === strpos( wp_json_encode( $diag_ai ), 'wp-agent-ai-readiness-key' ), 'Runtime diagnostics must not disclose API key plaintext.' );

$cli_output = WP_CLI::runcommand( 'wp-agent diagnostics', array(
    'return'     => true,
    'parse'      => 'json',
    'launch'     => false,
    'exit_error' => false,
) );
wp_agent_ai_readiness_assert( is_array( $cli_output ), 'WP-CLI diagnostics should return parsed JSON.' );
$cli_ai = $cli_output['ai'] ?? array();
wp_agent_ai_readiness_assert( ! empty( $cli_ai['ready'] ), 'WP-CLI diagnostics should expose ready AI state.' );
wp_agent_ai_readiness_assert( ! empty( $cli_ai['image_generation_ready'] ), 'WP-CLI diagnostics should expose image readiness.' );
wp_agent_ai_readiness_assert( false === strpos( wp_json_encode( $cli_ai ), 'wp-agent-ai-readiness-key' ), 'WP-CLI diagnostics must not disclose API key plaintext.' );

$result = array(
    'success'             => true,
    'empty_missing'       => $empty['missing'],
    'bad_key_state'       => $bad_key['api_key_state'],
    'missing_model_next'  => $missing_model['next_action'],
    'text_next_action'    => $text_ready['next_action'],
    'full_next_action'    => $full_ready['next_action'],
    'base_url_host'       => $full_ready['base_url_host'],
    'token_disclosed'     => false,
);

wp_agent_ai_readiness_restore();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
