<?php
/**
 * MCP (Model Context Protocol) HTTP client.
 *
 * Speaks the MCP Streamable HTTP transport via wp_remote_post().
 * Handles initialization, tool discovery, and tool execution
 * using JSON-RPC 2.0 over HTTP POST.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_MCP_Client {

    const PROTOCOL_VERSION = '2025-03-26';
    const TIMEOUT          = 15;

    /** @var string MCP server endpoint URL. */
    private $endpoint;

    /** @var string Auth type: none, basic, bearer. */
    private $auth_type;

    /** @var string Auth credentials (plain text). */
    private $auth_credentials;

    /** @var string|null Session ID assigned by server. */
    private $session_id = null;

    /** @var bool Whether initialize handshake is complete. */
    private $initialized = false;

    /** @var array|null Server info from initialize response. */
    private $server_info = null;

    /** @var int Auto-incrementing JSON-RPC request ID. */
    private $request_id = 0;

    /**
     * @param string $endpoint        MCP server URL.
     * @param string $auth_type       Auth type: none, basic, bearer.
     * @param string $auth_credentials Plain text credentials (user:pass for basic, token for bearer).
     */
    public function __construct( $endpoint, $auth_type = 'none', $auth_credentials = '' ) {
        $this->endpoint         = rtrim( $endpoint, '/' );
        $this->auth_type        = $auth_type;
        $this->auth_credentials = $auth_credentials;
    }

    /**
     * Validate the endpoint URL to prevent SSRF.
     *
     * Rejects private/reserved IP ranges and non-HTTP(S) schemes.
     *
     * @return true|WP_Error
     */
    private function validate_endpoint() {
        $url = wp_http_validate_url( $this->endpoint );
        if ( false === $url ) {
            return new WP_Error( 'mcp_invalid_endpoint', 'MCP endpoint URL is invalid or points to a private/reserved IP address.' );
        }
        return true;
    }

    /**
     * Perform the MCP initialization handshake.
     *
     * Sends initialize request, stores session ID, then sends
     * notifications/initialized notification.
     *
     * @return array|WP_Error Server info on success.
     */
    public function initialize() {
        // Step 1: Send initialize request.
        $result = $this->send_request( 'initialize', array(
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => new \stdClass(), // Empty object — we don't declare client capabilities.
            'clientInfo'      => array(
                'name'    => 'WP Agent',
                'version' => defined( 'WP_AGENT_VERSION' ) ? WP_AGENT_VERSION : '1.0.0',
            ),
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->server_info = $result['result'] ?? array();
        $this->initialized = true;

        // Step 2: Send initialized notification (no response expected).
        $this->send_notification( 'notifications/initialized' );

        return $this->server_info;
    }

    /**
     * Discover tools from the MCP server.
     *
     * Handles pagination via nextCursor.
     *
     * @return array|WP_Error Array of tool definitions.
     */
    public function list_tools() {
        $init = $this->ensure_initialized();
        if ( is_wp_error( $init ) ) {
            return $init;
        }

        $all_tools = array();
        $cursor    = null;

        do {
            $params = array();
            if ( null !== $cursor ) {
                $params['cursor'] = $cursor;
            }

            $result = $this->send_request( 'tools/list', ! empty( $params ) ? $params : null );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $tools = $result['result']['tools'] ?? array();
            $all_tools = array_merge( $all_tools, $tools );

            $cursor = $result['result']['nextCursor'] ?? null;
        } while ( ! empty( $cursor ) );

        return $all_tools;
    }

    /**
     * Call a tool on the MCP server.
     *
     * @param string $name      Tool name.
     * @param array  $arguments Tool arguments.
     * @return array|WP_Error Tool result content.
     */
    public function call_tool( $name, $arguments = array() ) {
        $init = $this->ensure_initialized();
        if ( is_wp_error( $init ) ) {
            return $init;
        }

        $result = $this->send_request( 'tools/call', array(
            'name'      => $name,
            'arguments' => ! empty( $arguments ) ? $arguments : new \stdClass(),
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Check for JSON-RPC level error.
        if ( isset( $result['error'] ) ) {
            $msg = $result['error']['message'] ?? 'MCP tool call failed.';
            return new WP_Error( 'mcp_tool_error', $msg );
        }

        $tool_result = $result['result'] ?? array();

        // Check for tool-level error (isError flag).
        if ( ! empty( $tool_result['isError'] ) ) {
            $error_text = $this->extract_text( $tool_result['content'] ?? array() );
            return new WP_Error( 'mcp_tool_execution_error', $error_text ?: 'Tool execution failed.' );
        }

        return $tool_result;
    }

    /**
     * Get server info from the last initialize call.
     *
     * @return array|null
     */
    public function get_server_info() {
        return $this->server_info;
    }

    /**
     * Ensure the client is initialized (lazy init).
     *
     * @return true|WP_Error
     */
    private function ensure_initialized() {
        if ( $this->initialized ) {
            return true;
        }

        $valid = $this->validate_endpoint();
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $result = $this->initialize();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    /**
     * Send a JSON-RPC request (expects response).
     *
     * @param string     $method JSON-RPC method.
     * @param array|null $params Method parameters.
     * @return array|WP_Error Parsed JSON-RPC response.
     */
    private function send_request( $method, $params = null ) {
        $this->request_id++;

        $body = array(
            'jsonrpc' => '2.0',
            'id'      => $this->request_id,
            'method'  => $method,
        );

        if ( null !== $params ) {
            $body['params'] = $params;
        }

        return $this->http_post( $body );
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     *
     * @param string     $method JSON-RPC method.
     * @param array|null $params Method parameters.
     */
    private function send_notification( $method, $params = null ) {
        $body = array(
            'jsonrpc' => '2.0',
            'method'  => $method,
        );

        if ( null !== $params ) {
            $body['params'] = $params;
        }

        // Fire and forget — notifications may return 202 with no body.
        $this->http_post( $body, true );
    }

    /**
     * Execute an HTTP POST to the MCP endpoint.
     *
     * @param array $body           JSON-RPC message body.
     * @param bool  $is_notification Whether this is a notification (don't parse response).
     * @return array|WP_Error Parsed response or error.
     */
    private function http_post( $body, $is_notification = false ) {
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json, text/event-stream',
        );

        // Protocol version header (required after initialization).
        if ( $this->initialized ) {
            $headers['Mcp-Protocol-Version'] = self::PROTOCOL_VERSION;
        }

        // Session ID header (if assigned).
        if ( ! empty( $this->session_id ) ) {
            $headers['Mcp-Session-Id'] = $this->session_id;
        }

        // Auth headers.
        if ( 'basic' === $this->auth_type && ! empty( $this->auth_credentials ) ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $this->auth_credentials ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
        } elseif ( 'bearer' === $this->auth_type && ! empty( $this->auth_credentials ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->auth_credentials;
        }

        $response = wp_remote_post( $this->endpoint, array(
            'timeout' => self::TIMEOUT,
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'mcp_connection_failed', 'MCP server connection failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );

        // Capture session ID from response headers. Validate format to prevent header injection.
        $session_header = wp_remote_retrieve_header( $response, 'mcp-session-id' );
        if ( ! empty( $session_header ) && preg_match( '/^[a-zA-Z0-9_\-\.]{1,128}$/', $session_header ) ) {
            $this->session_id = $session_header;
        }

        // Notifications expect 202 Accepted with no body.
        if ( $is_notification ) {
            if ( $code >= 400 ) {
                return new WP_Error( 'mcp_notification_failed', 'MCP notification failed (HTTP ' . $code . ').' );
            }
            return array( 'ok' => true );
        }

        // Parse response body.
        if ( $code >= 400 ) {
            $resp_body = wp_remote_retrieve_body( $response );
            $parsed    = json_decode( $resp_body, true );
            $msg       = $parsed['error']['message'] ?? ( 'MCP server error (HTTP ' . $code . ').' );
            return new WP_Error( 'mcp_server_error', $msg );
        }

        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        $resp_body    = wp_remote_retrieve_body( $response );

        // Handle SSE response — extract the last JSON-RPC message from event stream.
        if ( false !== strpos( $content_type, 'text/event-stream' ) ) {
            return $this->parse_sse_response( $resp_body );
        }

        // Standard JSON response.
        $parsed = json_decode( $resp_body, true );
        if ( null === $parsed ) {
            return new WP_Error( 'mcp_invalid_response', 'Invalid JSON response from MCP server.' );
        }

        return $parsed;
    }

    /**
     * Parse a Server-Sent Events response to extract JSON-RPC messages.
     *
     * SSE format:
     *   event: message
     *   data: {"jsonrpc":"2.0","id":1,"result":{...}}
     *
     * @param string $body Raw SSE response body.
     * @return array|WP_Error Last JSON-RPC message.
     */
    private function parse_sse_response( $body ) {
        $last_message = null;
        $lines        = explode( "\n", $body );
        $data_buffer  = '';

        foreach ( $lines as $line ) {
            $line = trim( $line );

            if ( 0 === strpos( $line, 'data: ' ) ) {
                $data_buffer .= substr( $line, 6 );
            } elseif ( '' === $line && ! empty( $data_buffer ) ) {
                // Empty line = end of event.
                $parsed = json_decode( $data_buffer, true );
                if ( null !== $parsed ) {
                    $last_message = $parsed;
                }
                $data_buffer = '';
            }
        }

        // Handle trailing data without final newline.
        if ( ! empty( $data_buffer ) ) {
            $parsed = json_decode( $data_buffer, true );
            if ( null !== $parsed ) {
                $last_message = $parsed;
            }
        }

        if ( null === $last_message ) {
            return new WP_Error( 'mcp_sse_parse_error', 'Failed to parse SSE response from MCP server.' );
        }

        return $last_message;
    }

    /**
     * Extract text from MCP content array.
     *
     * @param array $content MCP content items.
     * @return string Concatenated text content.
     */
    public function extract_text( $content ) {
        $texts = array();
        foreach ( $content as $item ) {
            if ( 'text' === ( $item['type'] ?? '' ) ) {
                $texts[] = $item['text'] ?? '';
            }
        }
        return implode( "\n", $texts );
    }
}
