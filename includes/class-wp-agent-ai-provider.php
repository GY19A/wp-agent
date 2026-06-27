<?php
/**
 * Abstract AI provider interface.
 *
 * Defines the contract that all AI model providers must implement.
 * The current implementation routes requests through the configured
 * OpenAI-compatible gateway.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Response object returned by AI providers.
 */
class WPAgent_AI_Response {

    /** @var string */
    public $content;

    /** @var array|null Tool calls requested by the model. */
    public $tool_calls;

    /** @var string Stop reason (end_turn, tool_use, max_tokens). */
    public $stop_reason;

    /** @var int Input tokens consumed. */
    public $tokens_in;

    /** @var int Output tokens generated. */
    public $tokens_out;

    /** @var int Cached input tokens (prompt-cache hits), a subset of tokens_in. */
    public $cached_tokens;

    /** @var string Model ID used. */
    public $model;

    public function __construct( array $data = array() ) {
        $this->content     = $data['content'] ?? '';
        $this->tool_calls  = $data['tool_calls'] ?? null;
        $this->stop_reason = $data['stop_reason'] ?? 'end_turn';
        $this->tokens_in   = $data['tokens_in'] ?? 0;
        $this->tokens_out  = $data['tokens_out'] ?? 0;
        $this->cached_tokens = $data['cached_tokens'] ?? 0;
        $this->model       = $data['model'] ?? '';
    }

    /**
     * Whether the model is requesting tool execution.
     */
    public function has_tool_calls() {
        return ! empty( $this->tool_calls );
    }
}

/**
 * Abstract AI provider.
 */
abstract class WPAgent_AI_Provider {

    /** @var string */
    protected $api_key;

    /** @var string */
    protected $model;

    public function __construct( $api_key, $model = '' ) {
        $this->api_key = $api_key;
        $this->model   = $model;
    }

    /**
     * Send a chat completion request.
     *
     * @param array  $messages Conversation messages.
     * @param string $system   System prompt.
     * @param array  $tools    Tool definitions (JSON Schema format).
     * @return WPAgent_AI_Response
     * @throws Exception On API error.
     */
    abstract public function chat( array $messages, string $system = '', array $tools = array() );

    /**
     * Send a streaming chat completion request.
     *
     * @param array    $messages Conversation messages.
     * @param string   $system   System prompt.
     * @param array    $tools    Tool definitions.
     * @param callable $callback Called with each chunk: fn(string $type, mixed $data).
     * @return WPAgent_AI_Response Final complete response.
     * @throws Exception On API error.
     */
    abstract public function stream( array $messages, string $system = '', array $tools = array(), callable $callback = null );

    /**
     * Get the model identifier.
     *
     * @return string
     */
    abstract public function get_model_id();

    /**
     * Get the provider name.
     *
     * @return string
     */
    abstract public function get_provider_name();

    /**
     * Check if the API key is configured and valid.
     *
     * @return bool
     */
    public function is_configured() {
        return ! empty( $this->api_key );
    }

    /**
     * Make an HTTP request to the AI API.
     *
     * @param string $url     API endpoint.
     * @param array  $body    Request body.
     * @param array  $headers Additional headers.
     * @return array|WP_Error Response body as array, or WP_Error.
     */
    protected function api_request( $url, $body, $headers = array() ) {
        $default_headers = array(
            'Content-Type' => 'application/json',
        );

        $encoded_body = wp_json_encode( $body );
        $retryable_codes = array( 429, 529, 503 );
        $max_retries = 3;

        for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
            if ( $attempt > 0 ) {
                // Exponential backoff: 1s, 2s, 4s.
                sleep( pow( 2, $attempt - 1 ) );
            }

            $response = wp_remote_post( $url, array(
                'headers' => array_merge( $default_headers, $headers ),
                'body'    => $encoded_body,
                'timeout' => 120,
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );

            // Retry on transient errors (rate limit, overloaded, service unavailable).
            if ( in_array( $code, $retryable_codes, true ) && $attempt < $max_retries ) {
                continue;
            }

            break;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $error_msg = $body['error']['message'] ?? "API returned HTTP {$code}";
            return new WP_Error( 'wp_agent_api_error', $error_msg, array( 'status' => $code ) );
        }

        return $body;
    }

    /**
     * Make a streaming HTTP request.
     *
     * @param string   $url      API endpoint.
     * @param array    $body     Request body.
     * @param array    $headers  Additional headers.
     * @param callable $on_chunk Called with each SSE data chunk.
     * @return true|WP_Error
     */
    protected function api_stream_request( $url, $body, $headers = array(), callable $on_chunk = null ) {
        $default_headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'text/event-stream',
        );

        $response = wp_remote_post( $url, array(
            'headers'  => array_merge( $default_headers, $headers ),
            'body'     => wp_json_encode( $body ),
            'timeout'  => 300,
            'stream'   => true,
            'filename' => '',
            'blocking' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            $body_text = wp_remote_retrieve_body( $response );
            $error     = json_decode( $body_text, true );
            $error_msg = $error['error']['message'] ?? "API returned HTTP {$code}";
            return new WP_Error( 'wp_agent_api_error', $error_msg, array( 'status' => $code ) );
        }

        // Parse SSE events from the response body.
        $body_text = wp_remote_retrieve_body( $response );
        $lines     = explode( "\n", $body_text );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( strpos( $line, 'data: ' ) === 0 ) {
                $data = substr( $line, 6 );
                if ( '[DONE]' === $data ) {
                    break;
                }
                $parsed = json_decode( $data, true );
                if ( $parsed && $on_chunk ) {
                    $on_chunk( $parsed );
                }
            }
        }

        return true;
    }
}
