<?php
/**
 * OpenAI-compatible AI provider.
 *
 * Routes chat completions through an OpenAI-compatible gateway.
 * Defaults to meowl, but site owners may configure another official,
 * third-party, self-hosted, or local endpoint. Deployment constants/env vars still win over
 * wp-admin settings when present.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_AI_Meowl extends WPAgent_AI_OpenAI {

    /** @var string Default OpenAI-compatible base URL. */
    const BASE_URL   = 'https://api.openai.com/v1';
    const API_URL    = 'https://api.openai.com/v1/chat/completions';
    const MODELS_URL = 'https://api.openai.com/v1/models';

    /** Models are fetched dynamically; no hardcoded table. */
    const MODELS = array();

    public function __construct( $api_key, $model = '' ) {
        // Skip the OpenAI gpt-4o default — models are discovered dynamically.
        WPAgent_AI_Provider::__construct( $api_key, $model );
    }

    public function get_provider_name() {
        return 'meowl';
    }

    /**
     * Get the configured OpenAI-compatible base URL.
     *
     * @return string
     */
    public static function base_url() {
        $configured = self::base_url_candidate();
        if ( '' !== $configured ) {
            $normalized = self::normalize_base_url( $configured );
            if ( ! is_wp_error( $normalized ) ) {
                return $normalized;
            }
        }

        return self::BASE_URL;
    }

    /**
     * Get the source of the active base URL.
     *
     * @return string constant|environment|settings|default
     */
    public static function base_url_source() {
        if ( defined( 'WP_AGENT_MEOWL_BASE_URL' ) ) {
            return 'constant';
        }
        if ( defined( 'WP_AGENT_AI_BASE_URL' ) ) {
            return 'constant';
        }
        if ( false !== getenv( 'WP_AGENT_MEOWL_BASE_URL' ) || false !== getenv( 'WP_AGENT_AI_BASE_URL' ) ) {
            return 'environment';
        }
        if ( '' !== trim( (string) get_option( 'wp_agent_ai_base_url', '' ) ) ) {
            return 'settings';
        }
        return 'default';
    }

    /**
     * Normalize and validate an OpenAI-compatible base URL.
     *
     * @param string $value Candidate URL.
     * @return string|WP_Error Normalized base URL or validation error.
     */
    public static function normalize_base_url( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        $value = esc_url_raw( $value, array( 'http', 'https' ) );
        $parts = wp_parse_url( $value );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return new WP_Error( 'wp_agent_ai_endpoint_invalid', 'AI endpoint must be a valid HTTP or HTTPS URL.' );
        }
        $scheme = strtolower( $parts['scheme'] );
        $host   = strtolower( $parts['host'] );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return new WP_Error( 'wp_agent_ai_endpoint_scheme', 'AI endpoint must use HTTP or HTTPS.' );
        }
        if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
            return new WP_Error( 'wp_agent_ai_endpoint_shape', 'AI endpoint must not include credentials, query strings, or fragments.' );
        }
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
        if ( $port < 0 || $port > 65535 ) {
            return new WP_Error( 'wp_agent_ai_endpoint_port', 'AI endpoint port is invalid.' );
        }

        $path = isset( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '';
        if ( preg_match( '#/(chat/completions|models)$#', $path ) ) {
            $path = preg_replace( '#/(chat/completions|models)$#', '', $path );
        }
        if ( '' === $path || '/' === $path ) {
            $path = '/v1';
        }

        $host_for_url = $host;
        if ( false !== strpos( $host_for_url, ':' ) && '[' !== substr( $host_for_url, 0, 1 ) ) {
            $host_for_url = '[' . $host_for_url . ']';
        }

        $normalized = $scheme . '://' . $host_for_url . ( $port ? ':' . $port : '' ) . $path;
        return untrailingslashit( $normalized );
    }

    /**
     * Get the first configured base URL candidate.
     *
     * @return string
     */
    private static function base_url_candidate() {
        if ( defined( 'WP_AGENT_MEOWL_BASE_URL' ) ) {
            return (string) WP_AGENT_MEOWL_BASE_URL;
        }
        if ( defined( 'WP_AGENT_AI_BASE_URL' ) ) {
            return (string) WP_AGENT_AI_BASE_URL;
        }
        $env = getenv( 'WP_AGENT_MEOWL_BASE_URL' );
        if ( false !== $env && '' !== trim( (string) $env ) ) {
            return (string) $env;
        }
        $env = getenv( 'WP_AGENT_AI_BASE_URL' );
        if ( false !== $env && '' !== trim( (string) $env ) ) {
            return (string) $env;
        }

        return (string) get_option( 'wp_agent_ai_base_url', '' );
    }

    /**
     * Get the Chat Completions endpoint for the configured base URL.
     *
     * @return string
     */
    protected function get_api_url() {
        return self::base_url() . '/chat/completions';
    }

    /**
     * Get the models endpoint for the configured base URL.
     *
     * @return string
     */
    public static function models_url() {
        return self::base_url() . '/models';
    }

    /**
     * Fetch available model IDs from the gateway.
     *
     * @return string[]|WP_Error Sorted model IDs, or WP_Error on failure.
     */
    public function list_models() {
        $response = wp_remote_get( self::models_url(), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept'        => 'application/json',
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = $body['error']['message'] ?? "Models request failed (HTTP {$code}).";
            return new WP_Error( 'wp_agent_api_error', $msg, array( 'status' => $code ) );
        }

        $models = array();
        foreach ( (array) ( $body['data'] ?? array() ) as $m ) {
            if ( ! empty( $m['id'] ) ) {
                $models[] = sanitize_text_field( $m['id'] );
            }
        }
        sort( $models );
        return $models;
    }
}
