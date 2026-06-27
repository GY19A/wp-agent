<?php
/**
 * Dynamic MCP tool wrapper.
 *
 * Wraps a discovered MCP tool definition into a WPAgent_Tool instance
 * so it can be registered alongside native tools and called by the AI agent.
 * Tool names are prefixed with the server ID to avoid collisions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_MCP_Tool extends WPAgent_Tool {

    /** @var array Server config from the MCP registry. */
    private $server;

    /** @var array MCP tool definition (name, description, inputSchema). */
    private $tool_def;

    /**
     * @param array $server   Server config from WPAgent_MCP_Registry.
     * @param array $tool_def Tool definition from MCP tools/list response.
     */
    public function __construct( $server, $tool_def ) {
        $this->server   = $server;
        $this->tool_def = $tool_def;
    }

    public function get_name() {
        return sanitize_key( $this->server['id'] ) . '_' . sanitize_key( $this->tool_def['name'] );
    }

    public function get_description() {
        $server_name = $this->server['name'] ?? $this->server['id'];
        $description = $this->tool_def['description'] ?? 'No description.';
        return '[' . $server_name . '] ' . $description;
    }

    public function get_parameters() {
        $schema = $this->tool_def['inputSchema'] ?? array();

        // Ensure the schema has the required top-level type.
        if ( empty( $schema ) ) {
            return array(
                'type'       => 'object',
                'properties' => new \stdClass(),
            );
        }

        // Ensure 'properties' is an object, not an empty array.
        // PHP encodes array() as [] but Anthropic expects {} for properties.
        if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && empty( $schema['properties'] ) ) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    public function get_required_capability() {
        return 'manage_options';
    }

    public function execute( array $params ) {
        $registry = new WPAgent_MCP_Registry();
        $client   = $registry->create_client( $this->server );

        // Call the tool using its original MCP name (not the prefixed name).
        $mcp_name = $this->tool_def['name'];
        $result   = $client->call_tool( $mcp_name, $params );

        if ( is_wp_error( $result ) ) {
            return array( 'error' => $result->get_error_message() );
        }

        // Extract text content from MCP response.
        $content = $result['content'] ?? array();
        $text    = $client->extract_text( $content );

        // Try to parse as JSON for structured data.
        if ( ! empty( $text ) ) {
            $parsed = json_decode( $text, true );
            if ( null !== $parsed && is_array( $parsed ) ) {
                return $parsed;
            }
        }

        // Return raw text if not JSON.
        return array(
            'success' => true,
            'result'  => $text ?: 'Tool executed successfully (no output).',
        );
    }

    /**
     * Get the original MCP tool name (without server prefix).
     *
     * @return string
     */
    public function get_mcp_name() {
        return $this->tool_def['name'];
    }

    /**
     * Get the server ID this tool belongs to.
     *
     * @return string
     */
    public function get_server_id() {
        return $this->server['id'];
    }
}
