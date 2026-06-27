<?php
/**
 * Tool registry and base class.
 *
 * Discovers, registers, and provides tool definitions
 * to the agent engine for AI tool-use.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract base class for all tools.
 */
abstract class WPAgent_Tool {

    /**
     * Acting WordPress user ID for this execution.
     *
     * @var int
     */
    protected $user_id = 0;

    /**
     * Requesting/owning WordPress user ID for this execution.
     *
     * The agent may act as the bounded wp-agent user while durable state
     * belongs to the human requester.
     *
     * @var int
     */
    protected $requester_id = 0;

    /**
     * Originating channel for this execution.
     *
     * @var string
     */
    protected $channel = '';

    /** @var int Active conversation/session ID. */
    protected $conversation_id = 0;

    /** @var int Active async run ID, when available. */
    protected $run_id = 0;

    /**
     * Set the execution context. Called by the agent immediately before execute().
     *
     * @param int    $user_id Acting WordPress user ID.
     * @param string $channel         Originating channel name.
     * @param int    $conversation_id Active conversation/session ID.
     * @param int    $requester_id    Requesting/owning WordPress user ID.
     * @param int    $run_id          Active async run ID.
     */
    public function set_context( $user_id, $channel, $conversation_id = 0, $requester_id = 0, $run_id = 0 ) {
        $this->user_id         = (int) $user_id;
        $this->requester_id    = (int) $requester_id > 0 ? (int) $requester_id : (int) $user_id;
        $this->channel         = (string) $channel;
        $this->conversation_id = (int) $conversation_id;
        $this->run_id          = (int) $run_id;
    }

    /**
     * Owner for requester-scoped durable data.
     *
     * @return int
     */
    protected function owner_id() {
        return $this->requester_id > 0 ? $this->requester_id : $this->user_id;
    }

    /**
     * Get the tool name (used as function name in AI API calls).
     *
     * @return string
     */
    abstract public function get_name();

    /**
     * Get a human-readable description of what this tool does.
     *
     * @return string
     */
    abstract public function get_description();

    /**
     * Get the JSON Schema for the tool's parameters.
     *
     * @return array
     */
    abstract public function get_parameters();

    /**
     * Execute the tool with the given parameters.
     *
     * @param array $params Validated parameters.
     * @return mixed Result to send back to the AI.
     */
    abstract public function execute( array $params );

    /**
     * Get the WordPress capability required to use this tool.
     *
     * @return string
     */
    abstract public function get_required_capability();

    /**
     * Get the tool definition for the AI API.
     *
     * @return array
     */
    public function get_definition() {
        return array(
            'name'        => $this->get_name(),
            'description' => $this->get_description(),
            'parameters'  => $this->get_parameters(),
        );
    }
}

/**
 * Tool registry — manages all available tools.
 */
class WPAgent_Tools {

    /** @var WPAgent_Tool[] */
    private $tools = array();

    public function __construct() {
        $this->register_core_tools();

        /**
         * Allow Pro and third-party tools to register.
         *
         * @param WPAgent_Tools $registry The tool registry instance.
         */
        do_action( 'wp_agent_register_tools', $this );
    }

    /**
     * Register all built-in core tools.
     */
    private function register_core_tools() {
        $tool_classes = array(
            'WPAgent_Tool_Posts',
            'WPAgent_Tool_Pages',
            'WPAgent_Tool_Comments',
            'WPAgent_Tool_Media',
            'WPAgent_Tool_Taxonomies',
            'WPAgent_Tool_Menus',
            'WPAgent_Tool_SEO',
            'WPAgent_Tool_Content_Quality',
            'WPAgent_Tool_Site_Info',
            'WPAgent_Tool_WooCommerce',
            'WPAgent_Tool_Files',
            'WPAgent_Tool_Runtime',
            'WPAgent_Tool_Users',
            'WPAgent_Tool_Settings',
            'WPAgent_Tool_Web',
            'WPAgent_Tool_Images',
            'WPAgent_Tool_Moderation',
            'WPAgent_Tool_Plan',
            'WPAgent_Tool_Delegate',
            'WPAgent_Tool_Schedules',
            'WPAgent_Tool_Journal',
            'WPAgent_Tool_Skills',
            'WPAgent_Tool_Code_Execution',
        );

        foreach ( $tool_classes as $class ) {
            if ( class_exists( $class ) ) {
                $this->register( new $class() );
            }
        }

        // Register MCP tools from connected servers.
        $this->register_mcp_tools();
    }

    /**
     * Register tools discovered from MCP servers.
     */
    private function register_mcp_tools() {
        $registry = new WPAgent_MCP_Registry();

        foreach ( $registry->get_all_tools() as $server_id => $server_tools ) {
            $server = $registry->get_server( $server_id );
            if ( ! $server ) {
                continue;
            }

            foreach ( $server_tools as $tool_def ) {
                $this->register( new WPAgent_MCP_Tool( $server, $tool_def ) );
            }
        }
    }

    /**
     * Register a tool.
     *
     * @param WPAgent_Tool $tool
     */
    public function register( WPAgent_Tool $tool ) {
        $this->tools[ $tool->get_name() ] = $tool;
    }

    /**
     * Get a tool by name.
     *
     * @param string $name
     * @return WPAgent_Tool|null
     */
    public function get_tool( $name ) {
        return $this->tools[ $name ] ?? null;
    }

    /**
     * Get all registered tools.
     *
     * @return WPAgent_Tool[]
     */
    public function get_all() {
        return $this->tools;
    }

    /**
     * Get tool definitions for a specific user (filtered by capabilities).
     *
     * @param int $user_id WordPress user ID.
     * @return array Tool definitions for the AI API.
     */
    public function get_definitions_for_user( $user_id ) {
        $definitions = array();

        foreach ( $this->tools as $tool ) {
            // Only include tools the user has permission for.
            if ( user_can( $user_id, $tool->get_required_capability() ) ) {
                if ( method_exists( $tool, 'is_available' ) && ! $tool->is_available() ) {
                    continue;
                }
                $definitions[] = $tool->get_definition();
            }
        }

        return $definitions;
    }

    /**
     * Get a list of tool names for display.
     *
     * @return array
     */
    public function get_tool_names() {
        return array_keys( $this->tools );
    }
}
