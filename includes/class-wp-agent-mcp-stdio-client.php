<?php
/**
 * MCP (Model Context Protocol) stdio client.
 *
 * Speaks the MCP stdio transport via PHP's proc_open().
 * Spawns the MCP server as a child process, communicates
 * via stdin/stdout using newline-delimited JSON-RPC 2.0.
 *
 * Same public API as WPAgent_MCP_Client (HTTP) so that
 * WPAgent_MCP_Tool can use either transparently.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_MCP_Stdio_Client {

    const PROTOCOL_VERSION = '2025-03-26';
    const TIMEOUT          = 15;

    /** @var string Shell command to spawn the MCP server. */
    private $command;

    /** @var resource|null proc_open process handle. */
    private $process = null;

    /** @var array stdin/stdout/stderr pipes. */
    private $pipes = array();

    /** @var bool Whether initialize handshake is complete. */
    private $initialized = false;

    /** @var array|null Server info from initialize response. */
    private $server_info = null;

    /** @var int Auto-incrementing JSON-RPC request ID. */
    private $request_id = 0;

    /**
     * @param string $command Shell command to run the MCP server (e.g. "npx -y @modelcontextprotocol/server-filesystem").
     */
    public function __construct( $command ) {
        $this->command = $command;
    }

    /**
     * Ensure process is cleaned up on destruction.
     */
    public function __destruct() {
        $this->close();
    }

    /** @var string[] Allowed command prefixes for stdio transport. */
    const ALLOWED_BINARIES = array( 'npx', 'node', 'python', 'python3', 'uvx', 'bunx', 'bun' );

    /**
     * Validate the command against allowlist and dangerous characters.
     *
     * @return true|WP_Error
     */
    private function validate_command() {
        if ( empty( $this->command ) ) {
            return new WP_Error( 'mcp_stdio_no_command', 'No command specified for stdio MCP server.' );
        }

        // Reject shell metacharacters, quotes, and control characters that enable command injection.
        if ( preg_match( '/[;&|`$><()\[\]{}!\\\\\"\'\n\r\x00~]/', $this->command ) ) {
            return new WP_Error( 'mcp_stdio_invalid_command', 'MCP command contains disallowed shell characters.' );
        }

        // Block eval/exec flags that allow arbitrary code execution via allowed binaries.
        if ( preg_match( '/\s-e\b|\s--eval\b|\s-c\b|\s--exec\b/', $this->command ) ) {
            return new WP_Error( 'mcp_stdio_eval_blocked', 'MCP command must not use eval or exec flags.' );
        }

        // Validate command starts with an allowed binary.
        $parts  = explode( ' ', $this->command, 2 );
        $binary = basename( $parts[0] );
        if ( ! in_array( $binary, self::ALLOWED_BINARIES, true ) ) {
            return new WP_Error(
                'mcp_stdio_blocked',
                'MCP command must start with an allowed binary: ' . implode( ', ', self::ALLOWED_BINARIES ) . '.'
            );
        }

        return true;
    }

    /**
     * Spawn the child process.
     *
     * @return true|WP_Error
     */
    private function spawn() {
        if ( ! function_exists( 'proc_open' ) ) {
            return new WP_Error(
                'mcp_stdio_unavailable',
                'Stdio MCP transport requires proc_open() which is disabled on this server. Use HTTP transport instead.'
            );
        }

        $valid = $this->validate_command();
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $descriptors = array(
            0 => array( 'pipe', 'r' ), // stdin
            1 => array( 'pipe', 'w' ), // stdout
            2 => array( 'pipe', 'w' ), // stderr
        );

        // Pass a minimal environment to prevent leaking server secrets (DB creds, salts, etc.).
        $safe_env = array(
            'PATH' => getenv( 'PATH' ) ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv( 'HOME' ) ?: sys_get_temp_dir(),
            'LANG' => 'en_US.UTF-8',
        );

        $this->process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Required for MCP stdio transport.
            $this->command,
            $descriptors,
            $this->pipes,
            null,     // cwd
            $safe_env // curated env — no server secrets
        );

        if ( ! is_resource( $this->process ) ) {
            return new WP_Error( 'mcp_stdio_spawn_failed', 'Failed to spawn MCP server process: ' . $this->command );
        }

        // Set stdout and stderr to non-blocking so we can use stream_select with timeout.
        stream_set_blocking( $this->pipes[1], false );
        stream_set_blocking( $this->pipes[2], false );

        return true;
    }

    /**
     * Close the child process and all pipes.
     */
    public function close() {
        foreach ( $this->pipes as $pipe ) {
            if ( is_resource( $pipe ) ) {
                fclose( $pipe );
            }
        }
        $this->pipes = array();

        if ( is_resource( $this->process ) ) {
            proc_terminate( $this->process );
            proc_close( $this->process );
        }
        $this->process     = null;
        $this->initialized = false;
    }

    /**
     * Perform the MCP initialization handshake.
     *
     * @return array|WP_Error Server info on success.
     */
    public function initialize() {
        $spawned = $this->spawn();
        if ( is_wp_error( $spawned ) ) {
            return $spawned;
        }

        // Step 1: Send initialize request.
        $result = $this->send_request( 'initialize', array(
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => new \stdClass(),
            'clientInfo'      => array(
                'name'    => 'WP Agent',
                'version' => defined( 'WP_AGENT_VERSION' ) ? WP_AGENT_VERSION : '1.0.0',
            ),
        ) );

        if ( is_wp_error( $result ) ) {
            $this->close();
            return $result;
        }

        $this->server_info = $result['result'] ?? array();
        $this->initialized = true;

        // Step 2: Send initialized notification.
        $this->send_notification( 'notifications/initialized' );

        return $this->server_info;
    }

    /**
     * Discover tools from the MCP server.
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
                $this->close();
                return $result;
            }

            $tools     = $result['result']['tools'] ?? array();
            $all_tools = array_merge( $all_tools, $tools );
            $cursor    = $result['result']['nextCursor'] ?? null;
        } while ( ! empty( $cursor ) );

        $this->close();
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

        $this->close();

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

    /**
     * Ensure the client is initialized (lazy init).
     *
     * @return true|WP_Error
     */
    private function ensure_initialized() {
        if ( $this->initialized && is_resource( $this->process ) ) {
            return true;
        }

        $result = $this->initialize();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    /**
     * Send a JSON-RPC request and wait for response.
     *
     * @param string     $method JSON-RPC method.
     * @param array|null $params Method parameters.
     * @return array|WP_Error Parsed JSON-RPC response.
     */
    private function send_request( $method, $params = null ) {
        $this->request_id++;

        $message = array(
            'jsonrpc' => '2.0',
            'id'      => $this->request_id,
            'method'  => $method,
        );

        if ( null !== $params ) {
            $message['params'] = $params;
        }

        $written = $this->write_message( $message );
        if ( is_wp_error( $written ) ) {
            return $written;
        }

        return $this->read_response( $this->request_id );
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     *
     * @param string     $method JSON-RPC method.
     * @param array|null $params Method parameters.
     */
    private function send_notification( $method, $params = null ) {
        $message = array(
            'jsonrpc' => '2.0',
            'method'  => $method,
        );

        if ( null !== $params ) {
            $message['params'] = $params;
        }

        $this->write_message( $message );
    }

    /**
     * Write a JSON-RPC message to the child process stdin.
     *
     * @param array $message JSON-RPC message.
     * @return true|WP_Error
     */
    private function write_message( $message ) {
        if ( ! is_resource( $this->pipes[0] ?? null ) ) {
            return new WP_Error( 'mcp_stdio_not_running', 'MCP server process is not running.' );
        }

        $json = wp_json_encode( $message );
        $written = fwrite( $this->pipes[0], $json . "\n" );

        if ( false === $written ) {
            return new WP_Error( 'mcp_stdio_write_failed', 'Failed to write to MCP server stdin.' );
        }

        fflush( $this->pipes[0] );
        return true;
    }

    /**
     * Read a JSON-RPC response from stdout, matching by request ID.
     *
     * Skips notifications and reads until the matching response is found
     * or the timeout is reached.
     *
     * @param int $expected_id The JSON-RPC request ID to match.
     * @return array|WP_Error Parsed JSON-RPC response.
     */
    private function read_response( $expected_id ) {
        $stdout  = $this->pipes[1] ?? null;
        $stderr  = $this->pipes[2] ?? null;
        $timeout = self::TIMEOUT;
        $start   = microtime( true );

        if ( ! is_resource( $stdout ) ) {
            return new WP_Error( 'mcp_stdio_not_running', 'MCP server process is not running.' );
        }

        while ( ( microtime( true ) - $start ) < $timeout ) {
            $read   = array( $stdout );
            $write  = null;
            $except = null;

            // Wait up to 1 second for data.
            $ready = @stream_select( $read, $write, $except, 1 );

            if ( false === $ready ) {
                return new WP_Error( 'mcp_stdio_select_failed', 'stream_select failed on MCP server stdout.' );
            }

            if ( 0 === $ready ) {
                // No data yet — check if process is still alive.
                $status = proc_get_status( $this->process );
                if ( ! $status['running'] ) {
                    $error_output = is_resource( $stderr ) ? substr( stream_get_contents( $stderr ), 0, 200 ) : '';
                    return new WP_Error(
                        'mcp_stdio_process_exited',
                        'MCP server process exited unexpectedly.' . ( $error_output ? ' stderr: ' . sanitize_text_field( trim( $error_output ) ) : '' )
                    );
                }
                continue;
            }

            $line = fgets( $stdout );
            if ( false === $line || '' === $line ) {
                continue;
            }

            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $parsed = json_decode( $line, true );
            if ( null === $parsed ) {
                continue; // Skip non-JSON lines (e.g. debug output).
            }

            // Skip notifications (messages without an 'id').
            if ( ! isset( $parsed['id'] ) ) {
                continue;
            }

            // Match our request ID.
            if ( (int) $parsed['id'] === $expected_id ) {
                return $parsed;
            }
        }

        return new WP_Error( 'mcp_stdio_timeout', 'Timed out waiting for response from MCP server (' . $timeout . 's).' );
    }
}
