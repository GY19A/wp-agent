<?php
/**
 * MCP server registry.
 *
 * Manages the list of configured MCP servers (both built-in
 * and user-added), handles tool discovery, and caches tool
 * definitions in wp_options for fast retrieval.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_MCP_Registry {

    const OPTION_KEY       = 'wp_agent_mcp_servers';
    const BUILTIN_CACHE_KEY = 'wp_agent_mcp_builtin_cache';

    /**
     * Built-in MCP servers shipped with WP Agent.
     *
     * These are pre-configured and cannot be removed by the user,
     * only enabled/disabled. Tool definitions are cached separately
     * from user-added servers.
     */
    const BUILTIN_SERVERS = array();

    /**
     * Check if a server ID is a built-in server.
     *
     * @param string $id Server ID.
     * @return bool
     */
    public function is_builtin( $id ) {
        return isset( self::BUILTIN_SERVERS[ $id ] );
    }

    /**
     * Get all configured MCP servers (built-in + user-added).
     *
     * Built-in servers are merged with their cached discovery data
     * and returned first, followed by user-added servers.
     *
     * @return array Associative array keyed by server ID.
     */
    public function get_servers() {
        $builtin_cache = get_option( self::BUILTIN_CACHE_KEY, array() );
        $user_servers  = get_option( self::OPTION_KEY, array() );

        $servers = array();

        foreach ( self::BUILTIN_SERVERS as $id => $server ) {
            $server['enabled']       = true;
            $server['tools']         = array();
            $server['server_info']   = array();
            $server['discovered_at'] = null;

            // Merge cached discovery data.
            if ( isset( $builtin_cache[ $id ] ) ) {
                if ( isset( $builtin_cache[ $id ]['tools'] ) ) {
                    $server['tools'] = $builtin_cache[ $id ]['tools'];
                }
                if ( isset( $builtin_cache[ $id ]['server_info'] ) ) {
                    $server['server_info'] = $builtin_cache[ $id ]['server_info'];
                }
                if ( isset( $builtin_cache[ $id ]['discovered_at'] ) ) {
                    $server['discovered_at'] = $builtin_cache[ $id ]['discovered_at'];
                }
                // Allow user to disable a built-in server.
                if ( isset( $builtin_cache[ $id ]['enabled'] ) ) {
                    $server['enabled'] = $builtin_cache[ $id ]['enabled'];
                }
            }

            $servers[ $id ] = $server;
        }

        return array_merge( $servers, $user_servers );
    }

    /**
     * Get a single server config.
     *
     * @param string $id Server ID.
     * @return array|null Server config or null.
     */
    public function get_server( $id ) {
        $servers = $this->get_servers();
        return $servers[ $id ] ?? null;
    }

    /**
     * Add a new MCP server.
     *
     * @param string $id          Unique server slug (alphanumeric, hyphens, underscores).
     * @param string $name        Display name.
     * @param string $endpoint    Streamable HTTP endpoint URL (for HTTP transport).
     * @param string $auth_type   Auth type: none, basic, bearer.
     * @param string $credentials Plain text credentials (encrypted before storage).
     * @param string $transport   Transport type: http or stdio.
     * @param string $command     Shell command for stdio transport (e.g. "npx some-mcp-server").
     * @return array The saved server config.
     */
    public function add_server( $id, $name, $endpoint, $auth_type = 'none', $credentials = '', $transport = 'http', $command = '' ) {
        $servers = get_option( self::OPTION_KEY, array() );

        $id = sanitize_key( $id );

        $servers[ $id ] = array(
            'id'            => $id,
            'name'          => sanitize_text_field( $name ),
            'transport'     => in_array( $transport, array( 'http', 'stdio' ), true ) ? $transport : 'http',
            'endpoint'      => esc_url_raw( $endpoint ),
            'auth_type'     => in_array( $auth_type, array( 'none', 'basic', 'bearer' ), true ) ? $auth_type : 'none',
            'credentials'   => ! empty( $credentials ) ? WPAgent::encrypt( $credentials ) : '',
            'command'       => sanitize_text_field( $command ),
            'enabled'       => true,
            'tools'         => array(),
            'server_info'   => array(),
            'discovered_at' => null,
        );

        update_option( self::OPTION_KEY, $servers );

        return $servers[ $id ];
    }

    /**
     * Update a server's config fields.
     *
     * @param string $id   Server ID.
     * @param array  $data Fields to update.
     * @return array|WP_Error Updated config or error.
     */
    public function update_server( $id, $data ) {
        $servers = get_option( self::OPTION_KEY, array() );

        if ( ! isset( $servers[ $id ] ) ) {
            return new WP_Error( 'mcp_server_not_found', 'MCP server not found: ' . $id );
        }

        // Whitelist and sanitize updatable fields.
        if ( isset( $data['name'] ) ) {
            $servers[ $id ]['name'] = sanitize_text_field( $data['name'] );
        }
        if ( isset( $data['endpoint'] ) ) {
            $servers[ $id ]['endpoint'] = esc_url_raw( $data['endpoint'] );
        }
        if ( isset( $data['auth_type'] ) ) {
            $servers[ $id ]['auth_type'] = in_array( $data['auth_type'], array( 'none', 'basic', 'bearer' ), true )
                ? $data['auth_type'] : 'none';
        }
        if ( isset( $data['enabled'] ) ) {
            $servers[ $id ]['enabled'] = (bool) $data['enabled'];
        }
        if ( isset( $data['transport'] ) ) {
            $servers[ $id ]['transport'] = in_array( $data['transport'], array( 'http', 'stdio' ), true )
                ? $data['transport'] : 'http';
        }
        if ( isset( $data['command'] ) ) {
            $servers[ $id ]['command'] = sanitize_text_field( $data['command'] );
        }

        // Handle credentials separately (encrypt).
        if ( isset( $data['credentials'] ) && ! empty( $data['credentials'] ) ) {
            $servers[ $id ]['credentials'] = WPAgent::encrypt( $data['credentials'] );
        }

        update_option( self::OPTION_KEY, $servers );

        return $servers[ $id ];
    }

    /**
     * Remove an MCP server.
     *
     * Built-in servers cannot be removed.
     *
     * @param string $id Server ID.
     * @return bool True if removed.
     */
    public function remove_server( $id ) {
        if ( $this->is_builtin( $id ) ) {
            return false;
        }

        $servers = get_option( self::OPTION_KEY, array() );

        if ( ! isset( $servers[ $id ] ) ) {
            return false;
        }

        unset( $servers[ $id ] );
        update_option( self::OPTION_KEY, $servers );

        return true;
    }

    /**
     * Toggle a built-in server on or off.
     *
     * @param string $id      Server ID.
     * @param bool   $enabled Whether to enable.
     * @return bool True if toggled.
     */
    public function toggle_builtin( $id, $enabled ) {
        if ( ! $this->is_builtin( $id ) ) {
            return false;
        }

        $cache = get_option( self::BUILTIN_CACHE_KEY, array() );

        if ( ! isset( $cache[ $id ] ) ) {
            $cache[ $id ] = array();
        }

        $cache[ $id ]['enabled'] = (bool) $enabled;
        update_option( self::BUILTIN_CACHE_KEY, $cache );

        return true;
    }

    /**
     * Discover tools from an MCP server.
     *
     * Creates a client, initializes, calls tools/list,
     * and caches the result.
     *
     * @param string $id Server ID.
     * @return array|WP_Error Array of tool definitions or error.
     */
    public function discover_tools( $id ) {
        $server = $this->get_server( $id );
        if ( ! $server ) {
            return new WP_Error( 'mcp_server_not_found', 'MCP server not found: ' . $id );
        }

        $client = $this->create_client( $server );
        $tools  = $client->list_tools();

        if ( is_wp_error( $tools ) ) {
            return $tools;
        }

        if ( $this->is_builtin( $id ) ) {
            // Cache built-in server tools separately.
            $cache = get_option( self::BUILTIN_CACHE_KEY, array() );

            if ( ! isset( $cache[ $id ] ) ) {
                $cache[ $id ] = array();
            }

            $cache[ $id ]['tools']         = $tools;
            $cache[ $id ]['server_info']   = $client->get_server_info() ?: array();
            $cache[ $id ]['discovered_at'] = gmdate( 'c' );

            update_option( self::BUILTIN_CACHE_KEY, $cache );
        } else {
            // User-added servers store in the main option.
            $servers = get_option( self::OPTION_KEY, array() );
            $servers[ $id ]['tools']         = $tools;
            $servers[ $id ]['server_info']   = $client->get_server_info() ?: array();
            $servers[ $id ]['discovered_at'] = gmdate( 'c' );
            update_option( self::OPTION_KEY, $servers );
        }

        return $tools;
    }

    /**
     * Get all tools across all enabled servers.
     *
     * Returns array keyed by server ID, each containing
     * the server's cached tool definitions.
     *
     * @return array [ 'server_id' => [ tool_def, ... ], ... ]
     */
    public function get_all_tools() {
        $servers = $this->get_servers();
        $result  = array();

        foreach ( $servers as $id => $server ) {
            if ( empty( $server['enabled'] ) ) {
                continue;
            }
            if ( empty( $server['tools'] ) ) {
                continue;
            }
            $result[ $id ] = $server['tools'];
        }

        return $result;
    }

    /**
     * Create an MCP client instance from a server config.
     *
     * Returns the appropriate client (HTTP or stdio) based on
     * the server's transport setting.
     *
     * @param array $server Server config from registry.
     * @return WPAgent_MCP_Client|WPAgent_MCP_Stdio_Client
     */
    public function create_client( $server ) {
        if ( 'stdio' === ( $server['transport'] ?? 'http' ) ) {
            return new WPAgent_MCP_Stdio_Client( $server['command'] );
        }

        $credentials = '';
        if ( ! empty( $server['credentials'] ) ) {
            $credentials = WPAgent::decrypt( $server['credentials'] );
        }

        return new WPAgent_MCP_Client(
            $server['endpoint'],
            $server['auth_type'] ?? 'none',
            $credentials
        );
    }
}
