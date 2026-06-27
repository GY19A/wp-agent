<?php
/**
 * WP Agent AI provider retry/backoff checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/provider-retry-backoff.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This provider retry/backoff script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_provider_retry_previous_base_sentinel'] = '__wp_agent_provider_retry_missing_base__';
$GLOBALS['wp_agent_provider_retry_previous_base']          = get_option( 'wp_agent_ai_base_url', $GLOBALS['wp_agent_provider_retry_previous_base_sentinel'] );
$GLOBALS['wp_agent_provider_retry_sequence']               = array();
$GLOBALS['wp_agent_provider_retry_calls']                  = array();
$GLOBALS['wp_agent_provider_retry_filter']                 = null;

function wp_agent_provider_retry_cleanup() {
    if ( null !== $GLOBALS['wp_agent_provider_retry_filter'] ) {
        remove_filter( 'pre_http_request', $GLOBALS['wp_agent_provider_retry_filter'], 10 );
        $GLOBALS['wp_agent_provider_retry_filter'] = null;
    }

    if ( $GLOBALS['wp_agent_provider_retry_previous_base_sentinel'] === $GLOBALS['wp_agent_provider_retry_previous_base'] ) {
        delete_option( 'wp_agent_ai_base_url' );
    } else {
        update_option( 'wp_agent_ai_base_url', $GLOBALS['wp_agent_provider_retry_previous_base'] );
    }
}

function wp_agent_provider_retry_fail( $message ) {
    wp_agent_provider_retry_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_provider_retry_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_provider_retry_fail( $message );
    }
}

function wp_agent_provider_retry_response( $code ) {
    $code = (int) $code;
    $body = array(
        'error' => array(
            'message' => 'Fixture HTTP ' . $code,
        ),
    );

    if ( 200 === $code ) {
        $body = array(
            'id'      => 'chatcmpl-wp-agent-provider-retry',
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => 'wp-agent-retry-model',
            'choices' => array(
                array(
                    'index'         => 0,
                    'message'       => array(
                        'role'    => 'assistant',
                        'content' => 'Retry success after transient failures.',
                    ),
                    'finish_reason' => 'stop',
                ),
            ),
            'usage'   => array(
                'prompt_tokens'     => 13,
                'completion_tokens' => 5,
                'total_tokens'      => 18,
            ),
        );
    }

    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( $body ),
        'response' => array(
            'code'    => $code,
            'message' => 200 === $code ? 'OK' : 'Fixture Error',
        ),
        'cookies'  => array(),
    );
}

function wp_agent_provider_retry_run( array $sequence ) {
    $GLOBALS['wp_agent_provider_retry_sequence'] = $sequence;
    $GLOBALS['wp_agent_provider_retry_calls']    = array();

    $provider = new WPAgent_AI_Meowl( 'test-key', 'wp-agent-retry-model' );
    return $provider->chat(
        array(
            array(
                'role'    => 'user',
                'content' => 'Exercise provider retry behavior.',
            ),
        ),
        'System prompt for retry verification.'
    );
}

register_shutdown_function( 'wp_agent_provider_retry_cleanup' );
update_option( 'wp_agent_ai_base_url', 'https://retry.example.test/v1' );

$GLOBALS['wp_agent_provider_retry_filter'] = function( $preempt, $parsed_args, $url ) {
    if ( false === strpos( (string) $url, '/chat/completions' ) ) {
        return $preempt;
    }

    $request_body = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
    $GLOBALS['wp_agent_provider_retry_calls'][] = array(
        'url'     => (string) $url,
        'headers' => $parsed_args['headers'] ?? array(),
        'body'    => is_array( $request_body ) ? $request_body : array(),
    );

    if ( empty( $GLOBALS['wp_agent_provider_retry_sequence'] ) ) {
        return wp_agent_provider_retry_response( 500 );
    }

    $next = array_shift( $GLOBALS['wp_agent_provider_retry_sequence'] );
    if ( is_wp_error( $next ) ) {
        return $next;
    }

    return wp_agent_provider_retry_response( (int) $next );
};
add_filter( 'pre_http_request', $GLOBALS['wp_agent_provider_retry_filter'], 10, 3 );

$started  = microtime( true );
$response = wp_agent_provider_retry_run( array( 429, 503, 200 ) );
$success_elapsed = microtime( true ) - $started;
$success_calls   = $GLOBALS['wp_agent_provider_retry_calls'];

wp_agent_provider_retry_assert( $response instanceof WPAgent_AI_Response, 'Transient retry path should return a provider response.' );
wp_agent_provider_retry_assert( 3 === count( $success_calls ), '429/503/200 path should make exactly three HTTP attempts.' );
wp_agent_provider_retry_assert( $success_elapsed >= 2.0, 'Transient retry path should wait before retrying.' );
wp_agent_provider_retry_assert( 'Retry success after transient failures.' === $response->content, 'Successful retry response content should be parsed.' );
wp_agent_provider_retry_assert( 13 === (int) $response->tokens_in && 5 === (int) $response->tokens_out, 'Successful retry response usage should be parsed.' );
wp_agent_provider_retry_assert( 'wp-agent-retry-model' === $response->model, 'Successful retry response model should be retained.' );

$first_call = $success_calls[0];
wp_agent_provider_retry_assert( 'https://retry.example.test/v1/chat/completions' === $first_call['url'], 'Provider should use the configured base URL.' );
wp_agent_provider_retry_assert( 'Bearer test-key' === ( $first_call['headers']['Authorization'] ?? '' ), 'Provider should send the bearer token header.' );
wp_agent_provider_retry_assert( 'application/json' === ( $first_call['headers']['Content-Type'] ?? '' ), 'Provider should send JSON content type.' );
wp_agent_provider_retry_assert( 'wp-agent-retry-model' === ( $first_call['body']['model'] ?? '' ), 'Provider should send the configured model.' );
wp_agent_provider_retry_assert( 'system' === ( $first_call['body']['messages'][0]['role'] ?? '' ), 'Provider should prepend the system message.' );
wp_agent_provider_retry_assert( 'user' === ( $first_call['body']['messages'][1]['role'] ?? '' ), 'Provider should include the user message.' );

$non_retryable_exception = '';
try {
    wp_agent_provider_retry_run( array( 500 ) );
} catch ( Exception $e ) {
    $non_retryable_exception = $e->getMessage();
}
wp_agent_provider_retry_assert( 'Fixture HTTP 500' === $non_retryable_exception, 'HTTP 500 should surface the provider error message.' );
wp_agent_provider_retry_assert( 1 === count( $GLOBALS['wp_agent_provider_retry_calls'] ), 'HTTP 500 should not be retried.' );

$transport_exception = '';
try {
    wp_agent_provider_retry_run( array( new WP_Error( 'fixture_transport', 'Fixture transport failure.' ) ) );
} catch ( Exception $e ) {
    $transport_exception = $e->getMessage();
}
wp_agent_provider_retry_assert( 'Fixture transport failure.' === $transport_exception, 'Transport WP_Error should surface its message.' );
wp_agent_provider_retry_assert( 1 === count( $GLOBALS['wp_agent_provider_retry_calls'] ), 'Transport WP_Error should not be retried.' );

$started = microtime( true );
$exhausted_exception = '';
try {
    wp_agent_provider_retry_run( array( 529, 529, 529, 529 ) );
} catch ( Exception $e ) {
    $exhausted_exception = $e->getMessage();
}
$exhausted_elapsed = microtime( true ) - $started;
wp_agent_provider_retry_assert( 'Fixture HTTP 529' === $exhausted_exception, 'Retry exhaustion should surface the final provider error message.' );
wp_agent_provider_retry_assert( 4 === count( $GLOBALS['wp_agent_provider_retry_calls'] ), 'Retry exhaustion should make the initial call plus three retries.' );
wp_agent_provider_retry_assert( $exhausted_elapsed >= 6.0, 'Retry exhaustion should apply exponential backoff before retries.' );

$result = array(
    'success'                    => true,
    'base_url_source'            => WPAgent_AI_Meowl::base_url_source(),
    'successful_retry_attempts'  => count( $success_calls ),
    'successful_retry_elapsed_s' => round( $success_elapsed, 3 ),
    'non_retryable_attempts'     => 1,
    'transport_error_attempts'   => 1,
    'exhausted_attempts'         => count( $GLOBALS['wp_agent_provider_retry_calls'] ),
    'exhausted_elapsed_s'        => round( $exhausted_elapsed, 3 ),
    'content'                    => $response->content,
    'tokens_in'                  => (int) $response->tokens_in,
    'tokens_out'                 => (int) $response->tokens_out,
);

wp_agent_provider_retry_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
