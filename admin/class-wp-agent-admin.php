<?php
/**
 * Admin pages controller.
 *
 * Registers admin menus, pages, and coordinates
 * all admin-facing components.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Admin {

    /**
     * Initialize admin components.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'show_setup_notice' ) );
        add_action( 'update_option_wp_agent_telegram_bot_token', array( $this, 'on_telegram_token_saved' ), 10, 2 );
        add_action( 'add_option_wp_agent_telegram_bot_token', array( $this, 'on_telegram_token_added' ), 10, 2 );
        add_action( 'update_option_wp_agent_discord_bot_token', array( $this, 'register_discord_commands' ) );
        add_action( 'add_option_wp_agent_discord_bot_token', array( $this, 'register_discord_commands' ) );
        add_action( 'update_option_wp_agent_discord_application_id', array( $this, 'register_discord_commands' ) );
        add_action( 'add_option_wp_agent_discord_application_id', array( $this, 'register_discord_commands' ) );
        add_action( 'admin_post_wp_agent_daemon_wake', array( $this, 'handle_daemon_wake' ) );
        add_action( 'admin_post_wp_agent_daemon_stop', array( $this, 'handle_daemon_stop' ) );
    }

    /**
     * Constrain the custom PNG menu icon in the WordPress admin sidebar.
     *
     * `add_menu_page()` with an image URL renders a raw <img> that WordPress
     * does not size, so a large source PNG can blow up to its natural size and
     * overflow the screen. This runs on every admin page (the menu is global)
     * and clamps the icon to the standard 20x20 menu-icon box.
     */
    public function print_menu_icon_style() {
        echo '<style id="wp-agent-menu-icon">'
            . '#adminmenu #toplevel_page_wp-agent-agent .wp-menu-image img{'
            . 'width:20px;height:20px;max-width:20px;max-height:20px;'
            . 'object-fit:contain;padding:7px 0 0;display:inline-block;}'
            . '</style>';
    }

    /**
     * Register admin menu pages.
     */
    public function register_menus() {
        // Top-level menu. The landing page is the Agent chat so the most-used
        // surface opens first; secondary tools follow further down.
        add_menu_page(
            __( 'WP Agent', 'wp-agent' ),
            __( 'WP Agent', 'wp-agent' ),
            'manage_options',
            'wp-agent-agent',
            array( $this, 'render_chat' ),
            WP_AGENT_PLUGIN_URL . 'assets/images/logo.png',
            30
        );

        // Agent (same as top-level) — primary surface, listed first.
        add_submenu_page(
            'wp-agent-agent',
            __( 'Agent', 'wp-agent' ),
            __( 'Agent', 'wp-agent' ),
            'manage_options',
            'wp-agent-agent',
            array( $this, 'render_chat' )
        );

        // Dashboard.
        add_submenu_page(
            'wp-agent-agent',
            __( 'Dashboard', 'wp-agent' ),
            __( 'Dashboard', 'wp-agent' ),
            'manage_options',
            'wp-agent',
            array( $this, 'render_dashboard' )
        );

        // Skills.
        add_submenu_page(
            'wp-agent-agent',
            __( 'Skills', 'wp-agent' ),
            __( 'Skills', 'wp-agent' ),
            'manage_options',
            'wp-agent-skills',
            array( $this, 'render_skills' )
        );

        // Settings.
        add_submenu_page(
            'wp-agent-agent',
            __( 'Settings', 'wp-agent' ),
            __( 'Settings', 'wp-agent' ),
            'manage_options',
            'wp-agent-settings',
            array( $this, 'render_settings' )
        );

        // Scheduled Tasks.
        add_submenu_page(
            'wp-agent-agent',
            __( 'Scheduled Tasks', 'wp-agent' ),
            __( 'Scheduled Tasks', 'wp-agent' ),
            'manage_options',
            'wp-agent-schedules',
            array( $this, 'render_schedules' )
        );

        // Conversation logs.
        add_submenu_page(
            'wp-agent-agent',
            __( 'Logs', 'wp-agent' ),
            __( 'Logs', 'wp-agent' ),
            'manage_options',
            'wp-agent-logs',
            array( $this, 'render_logs' )
        );

        // Usage & Costs.
        add_submenu_page(
            'wp-agent-agent',
            __( 'Usage & Costs', 'wp-agent' ),
            __( 'Usage & Costs', 'wp-agent' ),
            'manage_options',
            'wp-agent-costs',
            array( $this, 'render_costs' )
        );

        // Audit Log.
        add_submenu_page(
            'wp-agent-agent',
            __( 'Audit Log', 'wp-agent' ),
            __( 'Audit Log', 'wp-agent' ),
            'manage_options',
            'wp-agent-audit',
            array( $this, 'render_audit_log' )
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        // Operating Mode — bounds the agent's WordPress capabilities.
        register_setting( 'wp_agent_settings', 'wp_agent_mode', array(
            'type'              => 'string',
            'default'           => 'author',
            'sanitize_callback' => array( $this, 'sanitize_mode' ),
        ) );

        // AI Provider settings.
        register_setting( 'wp_agent_settings', 'wp_agent_ai_base_url', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => array( $this, 'sanitize_ai_base_url' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_meowl_api_key', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_meowl_model', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_image_model', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        // Model token controls. max_tokens caps the completion (output) length;
        // context_window is the model's total input window used to decide when
        // to compact older turns; model_max_tokens is an optional per-model
        // JSON override map ({"model-id": 8192}).
        register_setting( 'wp_agent_settings', 'wp_agent_max_tokens', array(
            'type'              => 'integer',
            'default'           => 8192,
            'sanitize_callback' => array( $this, 'sanitize_max_tokens' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_context_window', array(
            'type'              => 'integer',
            'default'           => 128000,
            'sanitize_callback' => array( $this, 'sanitize_context_window' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_model_max_tokens', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => array( $this, 'sanitize_model_max_tokens' ),
        ) );

        // GitHub Skills Store settings.
        register_setting( 'wp_agent_settings', 'wp_agent_github_token', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_github_default_repository', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => array( $this, 'sanitize_github_repository' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_github_default_ref', array(
            'type'              => 'string',
            'default'           => 'main',
            'sanitize_callback' => array( $this, 'sanitize_github_ref' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_github_default_skill_path', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => array( $this, 'sanitize_github_skill_path' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_github_activation_policy', array(
            'type'              => 'string',
            'default'           => 'quarantine',
            'sanitize_callback' => array( $this, 'sanitize_github_activation_policy' ),
        ) );

        // Telegram settings.
        register_setting( 'wp_agent_settings', 'wp_agent_telegram_bot_token', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );

        // Slack settings.
        register_setting( 'wp_agent_settings', 'wp_agent_slack_bot_token', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_slack_signing_secret', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );

        // Discord settings.
        register_setting( 'wp_agent_settings', 'wp_agent_discord_bot_token', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_discord_application_id', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_discord_public_key', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        // Moderation & Publishing settings.
        register_setting( 'wp_agent_settings', 'wp_agent_moderation_enabled', array(
            'type'    => 'boolean',
            'default' => false,
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_syndicate_telegram', array(
            'type'    => 'boolean',
            'default' => false,
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_syndicate_telegram_chat', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_syndicate_discord', array(
            'type'    => 'boolean',
            'default' => false,
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_syndicate_discord_channel', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_syndicate_x', array(
            'type'    => 'boolean',
            'default' => false,
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_x_access_token', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_syndicate_reddit', array(
            'type'    => 'boolean',
            'default' => false,
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_reddit_client_id', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_reddit_client_secret', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_reddit_username', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_reddit_password', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_key' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_reddit_subreddit', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        // Budget settings.
        register_setting( 'wp_agent_settings', 'wp_agent_monthly_budget', array(
            'type'              => 'number',
            'default'           => 0,
            'sanitize_callback' => 'absint',
        ) );

        // Agent loop / iteration budget.
        register_setting( 'wp_agent_settings', 'wp_agent_max_iterations', array(
            'type'              => 'number',
            'default'           => WPAgent_Agent::DEFAULT_MAX_ITERATIONS,
            'sanitize_callback' => array( $this, 'sanitize_max_iterations' ),
        ) );
        register_setting( 'wp_agent_settings', 'wp_agent_background_iterations_unlimited', array(
            'type'    => 'boolean',
            'default' => false,
        ) );

        register_setting( 'wp_agent_settings', 'wp_agent_runtime_root', array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => array( $this, 'sanitize_runtime_root' ),
        ) );

    }

    /**
     * Clamp the operating mode to one of the four valid values.
     */
    public function sanitize_mode( $value ) {
        $valid = array( 'author', 'editor', 'administrator', 'root' );
        $value = is_string( $value ) ? $value : '';
        return in_array( $value, $valid, true ) ? $value : 'author';
    }

    /**
     * Clamp the agent iteration cap to 0..10000 (0 = unlimited).
     */
    public function sanitize_max_iterations( $value ) {
        $value = (int) $value;
        if ( $value < 0 ) {
            $value = 0;
        }
        return min( 10000, $value );
    }

    /**
     * Clamp the completion (output) token cap to a sane range.
     */
    public function sanitize_max_tokens( $value ) {
        $value = (int) $value;
        if ( $value <= 0 ) {
            $value = 8192;
        }
        $min = class_exists( 'WPAgent_AI_OpenAI' ) ? WPAgent_AI_OpenAI::MAX_TOKENS_MIN : 256;
        $max = class_exists( 'WPAgent_AI_OpenAI' ) ? WPAgent_AI_OpenAI::MAX_TOKENS_MAX : 200000;
        return max( $min, min( $max, $value ) );
    }

    /**
     * Clamp the model input context window to a sane range.
     */
    public function sanitize_context_window( $value ) {
        $value = (int) $value;
        if ( $value <= 0 ) {
            $value = 128000;
        }
        $min = class_exists( 'WPAgent_Context_Compactor' ) ? WPAgent_Context_Compactor::CONTEXT_WINDOW_MIN : 4000;
        $max = class_exists( 'WPAgent_Context_Compactor' ) ? WPAgent_Context_Compactor::CONTEXT_WINDOW_MAX : 2000000;
        return max( $min, min( $max, $value ) );
    }

    /**
     * Validate the optional per-model max_tokens override map.
     *
     * Accepts a JSON object of { "model-id": int }. Invalid entries are
     * dropped; an empty / unparseable value is stored as an empty string.
     */
    public function sanitize_model_max_tokens( $value ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( '' === $value ) {
            return '';
        }
        $decoded = json_decode( $value, true );
        if ( ! is_array( $decoded ) ) {
            return ''; // not valid JSON — drop silently rather than store garbage
        }
        $clean = array();
        foreach ( $decoded as $model => $cap ) {
            $model = sanitize_text_field( (string) $model );
            $cap   = (int) $cap;
            if ( '' === $model || $cap <= 0 ) {
                continue;
            }
            $clean[ $model ] = $this->sanitize_max_tokens( $cap );
        }
        return empty( $clean ) ? '' : wp_json_encode( $clean );
    }

    /**
     * Sanitize an optional private runtime root path.
     */
    public function sanitize_runtime_root( $value ) {
        $value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
        if ( '' === $value ) {
            return '';
        }

        $status = WPAgent_Sandbox::runtime_root_status( $value, true );
        if ( empty( $status['ok'] ) ) {
            $code = isset( $status['code'] ) && '' !== $status['code'] ? sanitize_key( $status['code'] ) : 'invalid';
            add_settings_error(
                'wp_agent_settings',
                'runtime_root_' . $code,
                isset( $status['message'] ) && '' !== $status['message'] ? $status['message'] : __( 'Runtime root could not be used by PHP.', 'wp-agent' ),
                'error'
            );
            return get_option( 'wp_agent_runtime_root', '' );
        }

        return $status['normalized'];
    }

    /**
     * Sanitize an OpenAI-compatible AI endpoint base URL.
     */
    public function sanitize_ai_base_url( $value ) {
        $value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
        if ( '' === $value ) {
            return '';
        }

        $normalized = WPAgent_AI_Meowl::normalize_base_url( $value );
        if ( is_wp_error( $normalized ) ) {
            add_settings_error( 'wp_agent_settings', 'ai_endpoint_invalid', $normalized->get_error_message(), 'error' );
            return get_option( 'wp_agent_ai_base_url', '' );
        }

        return $normalized;
    }

    /**
     * Sanitize a GitHub Skill Store repository.
     */
    public function sanitize_github_repository( $value ) {
        $value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
        if ( '' === $value ) {
            return '';
        }

        $normalized = WPAgent_Skills::normalize_github_repository_value( $value );
        if ( '' === $normalized ) {
            add_settings_error( 'wp_agent_settings', 'github_repository_invalid', __( 'GitHub Skill Store repository must be owner/repo or a GitHub repository URL.', 'wp-agent' ), 'error' );
            return get_option( 'wp_agent_github_default_repository', '' );
        }

        return $normalized;
    }

    /**
     * Sanitize a GitHub Skill Store package path.
     */
    public function sanitize_github_skill_path( $value ) {
        $value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
        if ( '' === $value ) {
            return '';
        }

        $normalized = WPAgent_Skills::normalize_skill_package_path( $value );
        if ( '' === $normalized ) {
            add_settings_error( 'wp_agent_settings', 'github_skill_path_invalid', __( 'GitHub Skill Store path must be a relative Skill directory or SKILL.md path without traversal.', 'wp-agent' ), 'error' );
            return get_option( 'wp_agent_github_default_skill_path', '' );
        }

        return $normalized;
    }

    /**
     * Sanitize a GitHub Skill Store ref.
     */
    public function sanitize_github_ref( $value ) {
        $value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
        if ( '' === $value ) {
            return 'main';
        }

        $normalized = WPAgent_Skills::sanitize_git_ref_value( $value );
        if ( '' === $normalized ) {
            add_settings_error( 'wp_agent_settings', 'github_ref_invalid', __( 'GitHub Skill Store ref is invalid.', 'wp-agent' ), 'error' );
            return get_option( 'wp_agent_github_default_ref', 'main' );
        }

        return $normalized;
    }

    /**
     * Sanitize a GitHub Skill Store activation policy.
     */
    public function sanitize_github_activation_policy( $value ) {
        return WPAgent_Skills::sanitize_github_activation_policy( $value );
    }

    /**
     * Encrypt API keys before storing.
     */
    public function sanitize_api_key( $value ) {
        $value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
        if ( '' === $value ) {
            return '';
        }
        // If the user didn't change the masked field, keep the existing value.
        if ( str_contains( $value, '••' ) || str_contains( $value, '********' ) ) {
            // Return the existing stored value unchanged.
            $option_name = current_filter();
            $option_name = str_replace( 'sanitize_option_', '', $option_name );
            return get_option( $option_name, '' );
        }
        // WordPress may pass the value through sanitize_option() more than once.
        if ( '' !== WPAgent::decrypt( $value ) ) {
            return $value;
        }
        return WPAgent::encrypt( $value );
    }

    /**
     * Auto-register Telegram webhook when bot token is saved.
     */
    public function on_telegram_token_saved( $old_value, $new_value ) {
        $this->register_telegram_webhook( $new_value );
    }

    public function on_telegram_token_added( $option, $value ) {
        $this->register_telegram_webhook( $value );
    }

    private function register_telegram_webhook( $encrypted_token ) {
        if ( empty( $encrypted_token ) ) {
            return;
        }
        $token   = WPAgent::decrypt( $encrypted_token );
        $channel = new WPAgent_Channel_Telegram( $token );
        $result  = $channel->register_webhook();

        if ( is_wp_error( $result ) ) {
            add_settings_error( 'wp_agent_settings', 'telegram_webhook', 'Telegram webhook failed: ' . esc_html( $result->get_error_message() ), 'error' );
        } else {
            add_settings_error( 'wp_agent_settings', 'telegram_webhook', 'Telegram webhook registered successfully!', 'success' );
        }
    }

    /**
     * Auto-register Discord slash commands when bot token or application ID is saved.
     *
     * Mirrors the Telegram webhook auto-registration. Requires both the bot token
     * and the application ID to be configured before attempting registration.
     */
    public function register_discord_commands() {
        $token  = WPAgent::decrypt( WPAgent::get_option( 'discord_bot_token' ) );
        $app_id = WPAgent::get_option( 'discord_application_id' );

        if ( empty( $token ) || empty( $app_id ) ) {
            return;
        }

        $channel = new WPAgent_Channel_Discord( $token, $app_id );
        $result  = $channel->register_commands();

        if ( is_wp_error( $result ) ) {
            add_settings_error( 'wp_agent_settings', 'discord_commands', 'Discord commands failed: ' . esc_html( $result->get_error_message() ), 'error' );
        } else {
            add_settings_error( 'wp_agent_settings', 'discord_commands', 'Discord slash commands registered successfully!', 'success' );
        }
    }

    /**
     * Show setup notice if API key is not configured.
     */
    public function show_setup_notice() {
        $api_key = WPAgent::get_option( 'meowl_api_key' );
        if ( empty( $api_key ) ) {
            $settings_url = admin_url( 'admin.php?page=wp-agent-settings' );
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__( 'WP Agent needs an API key to work.', 'wp-agent' ),
                esc_url( $settings_url ),
                esc_html__( 'Configure now', 'wp-agent' )
            );
        }
    }

    /**
     * Render the dashboard page.
     */
    public function render_dashboard() {
        $tracker = new WPAgent_Cost_Tracker();
        $user_id = get_current_user_id();
        $usage   = $tracker->get_usage_summary( $user_id, 'month' );

        $conversation_mgr = new WPAgent_Conversation();
        $recent            = $conversation_mgr->list_conversations( $user_id, '', 5 );

        $permissions    = new WPAgent_Permissions();
        $pairings       = $permissions->get_user_pairings( $user_id );
        $ai_readiness   = WPAgent::ai_provider_readiness();
        $has_api_key    = ! empty( $ai_readiness['api_key_configured'] );

        // IM channels: report every supported channel, not just Telegram.
        $im_channels = array(
            array(
                'key'       => 'telegram',
                'label'     => __( 'Telegram', 'wp-agent' ),
                'connected' => ! empty( WPAgent::get_option( 'telegram_bot_token' ) ),
            ),
            array(
                'key'       => 'slack',
                'label'     => __( 'Slack', 'wp-agent' ),
                'connected' => ! empty( WPAgent::get_option( 'slack_bot_token' ) ),
            ),
            array(
                'key'       => 'discord',
                'label'     => __( 'Discord', 'wp-agent' ),
                'connected' => ! empty( WPAgent::get_option( 'discord_bot_token' ) ),
            ),
        );
        $has_telegram   = (bool) $im_channels[0]['connected'];
        $runtime_status = $this->get_runtime_status();

        include WP_AGENT_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Gather lightweight autonomous runtime status for the dashboard.
     *
     * @return array
     */
    private function get_runtime_status() {
        $counts = array(
            'queued'   => 0,
            'running'  => 0,
            'done'     => 0,
            'error'    => 0,
            'canceled' => 0,
        );
        $counts = array_merge( $counts, WPAgent_Runs::status_counts() );

        $next_worker = wp_next_scheduled( 'wp_agent_worker_tick' );

        $broker       = new WPAgent_Sandbox_Broker();
        $isolation    = $broker->status( false );
        $runtime_selection = WPAgent_Sandbox::runtime_root_selection();
        $runtime_root      = (string) ( $runtime_selection['runtime_root'] ?? WPAgent_Sandbox::runtime_root() );
        $root_ready   = is_dir( $runtime_root ) || wp_mkdir_p( $runtime_root );
        if ( $root_ready ) {
            @chmod( $runtime_root, 0700 );
        }
        $runtime_source = (string) ( $runtime_selection['source'] ?? '' );

        $daemon = WPAgent_Daemon::status();

        return array(
            'counts'      => $counts,
            'next_worker' => $next_worker ? array(
                'timestamp' => (int) $next_worker,
                'relative'  => human_time_diff( time(), $next_worker ),
                'due'       => $next_worker <= time(),
            ) : null,
            'has_worker'  => (bool) $next_worker,
            'daemon'      => $daemon,
            'diagnostics' => WPAgent_Diagnostics::runtime( array( 'daemon' => $daemon ) ),
            'isolation'   => $isolation,
            'storage'     => array(
                'runtime_root'         => $runtime_root,
                'sandbox_base'         => WPAgent_Sandbox::base_dir(),
                'configured'           => '' !== get_option( 'wp_agent_runtime_root', '' ),
                'effective_configured' => in_array( $runtime_source, array( 'constant', 'environment', 'setting' ), true ),
                'active_source'        => $runtime_source,
                'active_source_label'  => (string) ( $runtime_selection['source_label'] ?? WPAgent_Sandbox::runtime_root_source_label( $runtime_source ) ),
                'writable'             => $root_ready && wp_is_writable( $runtime_root ),
            ),
        );
    }

    /**
     * Wake the native PHP agent daemon from Dashboard.
     */
    public function handle_daemon_wake() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-agent' ) );
        }
        check_admin_referer( 'wp_agent_daemon_wake' );

        $max_children = isset( $_POST['max_children'] ) ? (int) wp_unslash( $_POST['max_children'] ) : WPAgent_Daemon::configured_max_children();
        $result = WPAgent_Daemon::wake( array( 'max_children' => $max_children ) );
        $state = is_wp_error( $result ) ? 'error' : ( ! empty( $result['started'] ) ? 'started' : 'running' );

        wp_safe_redirect( add_query_arg(
            array( 'wp_agent_daemon' => $state ),
            admin_url( 'admin.php?page=wp-agent' )
        ) );
        exit;
    }

    /**
     * Stop the native PHP agent daemon from Dashboard.
     */
    public function handle_daemon_stop() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-agent' ) );
        }
        check_admin_referer( 'wp_agent_daemon_stop' );

        WPAgent_Daemon::request_stop();

        wp_safe_redirect( add_query_arg(
            array( 'wp_agent_daemon' => 'stopping' ),
            admin_url( 'admin.php?page=wp-agent' )
        ) );
        exit;
    }

    /**
     * Render the full-page chat.
     */
    public function render_chat() {
        include WP_AGENT_PLUGIN_DIR . 'admin/views/chat.php';
    }

    /**
     * Render the settings page.
     */
    public function render_settings() {
        include WP_AGENT_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Render the conversations page.
     */
    public function render_conversations() {
        wp_safe_redirect( admin_url( 'admin.php?page=wp-agent-agent#history' ) );
        exit;
    }

    /**
     * Render conversation logs.
     */
    public function render_logs() {
        include WP_AGENT_PLUGIN_DIR . 'admin/views/logs.php';
    }

    /**
     * Render the scheduled tasks page.
     */
    public function render_schedules() {
        include WP_AGENT_PLUGIN_DIR . 'admin/views/schedules.php';
    }

    /**
     * Render the skills page.
     */
    public function render_skills() {
        $notice = '';
        $error  = '';

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wp_agent_skills_nonce'] ) ) {
            if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_agent_skills_nonce'] ) ), 'wp_agent_skills' ) ) {
                $error = __( 'Could not verify the request.', 'wp-agent' );
            } else {
                $action = sanitize_key( $_POST['wp_agent_skill_action'] ?? '' );
                if ( 'save' === $action ) {
                    $skill = WPAgent_Skills::save( get_current_user_id(), array(
                        'name'        => sanitize_text_field( wp_unslash( $_POST['skill_name'] ?? '' ) ),
                        'slug'        => sanitize_title( wp_unslash( $_POST['skill_slug'] ?? '' ) ),
                        'description' => sanitize_textarea_field( wp_unslash( $_POST['skill_description'] ?? '' ) ),
                        'triggers'    => sanitize_textarea_field( wp_unslash( $_POST['skill_triggers'] ?? '' ) ),
                        'body'        => wp_unslash( $_POST['skill_body'] ?? '' ),
                    ) );
                    if ( is_wp_error( $skill ) ) {
                        $error = $skill->get_error_message();
                    } else {
                        $notice = __( 'Skill saved.', 'wp-agent' );
                    }
                } elseif ( 'archive' === $action ) {
                    $ok = WPAgent_Skills::archive( get_current_user_id(), sanitize_title( wp_unslash( $_POST['skill_slug'] ?? '' ) ) );
                    $notice = $ok ? __( 'Skill archived.', 'wp-agent' ) : __( 'Skill not found.', 'wp-agent' );
                } elseif ( 'install_github' === $action ) {
                    $result = WPAgent_Skills::install_from_github( get_current_user_id(), array(
                        'repository'   => sanitize_text_field( wp_unslash( $_POST['github_repository'] ?? '' ) ),
                        'ref'          => sanitize_text_field( wp_unslash( $_POST['github_ref'] ?? '' ) ),
                        'skill_path'   => sanitize_text_field( wp_unslash( $_POST['github_skill_path'] ?? '' ) ),
                        'github_token' => sanitize_text_field( wp_unslash( $_POST['github_token'] ?? '' ) ),
                    ) );
                    if ( is_wp_error( $result ) ) {
                        $error = $result->get_error_message();
                    } else {
                        $notice = __( 'Skill package downloaded to quarantine for review.', 'wp-agent' );
                    }
                } elseif ( 'activate_quarantine' === $action ) {
                    $result = WPAgent_Skills::activate_quarantined(
                        get_current_user_id(),
                        sanitize_text_field( wp_unslash( $_POST['quarantine_id'] ?? '' ) )
                    );
                    if ( is_wp_error( $result ) ) {
                        $error = $result->get_error_message();
                    } else {
                        $notice = __( 'Skill package activated.', 'wp-agent' );
                    }
                } elseif ( 'check_package_update' === $action ) {
                    $result = WPAgent_Skills::check_package_update( sanitize_title( wp_unslash( $_POST['package_slug'] ?? '' ) ) );
                    if ( is_wp_error( $result ) ) {
                        $error = $result->get_error_message();
                    } elseif ( ! empty( $result['has_update'] ) ) {
                        $notice = __( 'Remote source has changed. Download the latest package to quarantine before activation.', 'wp-agent' );
                    } else {
                        $notice = __( 'No remote changes detected for this package source.', 'wp-agent' );
                    }
                } elseif ( 'refresh_package' === $action ) {
                    $result = WPAgent_Skills::refresh_package_from_source(
                        get_current_user_id(),
                        sanitize_title( wp_unslash( $_POST['package_slug'] ?? '' ) )
                    );
                    if ( is_wp_error( $result ) ) {
                        $error = $result->get_error_message();
                    } else {
                        $notice = __( 'Latest package source downloaded to quarantine for review.', 'wp-agent' );
                    }
                } elseif ( 'rollback_package' === $action ) {
                    $result = WPAgent_Skills::rollback_package(
                        get_current_user_id(),
                        sanitize_title( wp_unslash( $_POST['package_slug'] ?? '' ) ),
                        sanitize_text_field( wp_unslash( $_POST['rollback_id'] ?? '' ) )
                    );
                    if ( is_wp_error( $result ) ) {
                        $error = $result->get_error_message();
                    } else {
                        $notice = __( 'Skill package rolled back to the selected snapshot.', 'wp-agent' );
                    }
                }
            }
        }

        $skills              = WPAgent_Skills::all( get_current_user_id(), 100 );
        $quarantine_packages = WPAgent_Skills::quarantine_list( 100 );
        $installed_packages  = WPAgent_Skills::installed_packages( 100 );
        $github_store        = WPAgent_Skills::github_store_defaults();
        $github_store_readiness = WPAgent_Skills::github_store_readiness();
        include WP_AGENT_PLUGIN_DIR . 'admin/views/skills.php';
    }

    /**
     * Render the costs page.
     */
    public function render_costs() {
        include WP_AGENT_PLUGIN_DIR . 'admin/views/costs.php';
    }

    /**
     * Render the audit log page.
     */
    public function render_audit_log() {
        include WP_AGENT_PLUGIN_DIR . 'admin/views/audit-log.php';
    }
}
