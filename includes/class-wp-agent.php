<?php
/**
 * Core plugin orchestrator.
 *
 * Registers hooks, loads dependencies, initializes components,
 * and coordinates all plugin subsystems.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent {

    /**
     * @var WPAgent_Agent
     */
    private $agent;

    /**
     * @var WPAgent_Webhook_Handler
     */
    private $webhook_handler;

    /**
     * @var WPAgent_Admin
     */
    private $admin;

    /**
     * Initialize the plugin.
     */
    public function init() {
        $this->load_textdomain();
        $this->register_hooks();

        if ( is_admin() ) {
            $this->init_admin();
        }

        $this->init_rest_api();
        $this->init_channels();
        $this->schedule_events();
    }

    /**
     * Load plugin text domain for translations.
     */
    private function load_textdomain() {
        load_plugin_textdomain( 'wp-agent', false, dirname( WP_AGENT_PLUGIN_BASENAME ) . '/languages' );
    }

    /**
     * Register core WordPress hooks.
     */
    private function register_hooks() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_agent_daily_cleanup', array( $this, 'daily_cleanup' ) );
        add_action( 'wp_agent_cost_alert_check', array( $this, 'check_cost_alerts' ) );
        add_filter( 'plugin_action_links_' . WP_AGENT_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

        // Register the mode-change hook so the wp_agent role caps track the
        // operating mode, and ensure the bounded agent role/user exist on
        // existing installs (where activation may predate this feature).
        WPAgent_Roles::init();
        WPAgent_Roles::ensure();

        // Register the one-minute cron interval and the recurring schedule-check
        // callback so scheduled agent tasks run on the cron tick.
        WPAgent_Schedules::init();

        // Register the autonomous worker fallback tick. A WP-CLI worker is the
        // preferred execution path; this keeps shared-hosting installs moving.
        WPAgent_Worker::init();
        WPAgent_Worker::schedule_cron();
    }

    /**
     * Initialize admin components.
     */
    private function init_admin() {
        $this->admin = new WPAgent_Admin();
        $this->admin->init();
    }

    /**
     * Initialize REST API routes.
     */
    private function init_rest_api() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    /**
     * Register all REST API routes.
     */
    public function register_rest_routes() {
        $this->webhook_handler = new WPAgent_Webhook_Handler();
        $this->webhook_handler->register_routes();
    }

    /**
     * Initialize messaging channels.
     */
    private function init_channels() {
        $channels = $this->get_active_channels();
        foreach ( $channels as $channel ) {
            $channel->init();
        }
    }

    /**
     * Get active channel instances based on configuration.
     *
     * @return WPAgent_Channel[]
     */
    private function get_active_channels() {
        $channels = array();

        // Webchat is always active.
        $channels[] = new WPAgent_Channel_Webchat();

        // Telegram — active if bot token is configured.
        $telegram_token = self::get_option( 'telegram_bot_token' );
        if ( ! empty( $telegram_token ) ) {
            $channels[] = new WPAgent_Channel_Telegram( self::decrypt( $telegram_token ) );
        }

        // Slack — active if bot token is configured.
        $slack_token = self::get_option( 'slack_bot_token' );
        if ( ! empty( $slack_token ) ) {
            $channels[] = new WPAgent_Channel_Slack( self::decrypt( $slack_token ) );
        }

        // Discord — active if bot token is configured.
        $discord_token = self::get_option( 'discord_bot_token' );
        if ( ! empty( $discord_token ) ) {
            $app_id     = self::get_option( 'discord_application_id' );
            $channels[] = new WPAgent_Channel_Discord( self::decrypt( $discord_token ), $app_id );
        }

        /**
         * Filter the active channels.
         *
         * @param WPAgent_Channel[] $channels Active channel instances.
         */
        return apply_filters( 'wp_agent_active_channels', $channels );
    }

    /**
     * Schedule recurring events.
     */
    private function schedule_events() {
        if ( ! wp_next_scheduled( 'wp_agent_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'wp_agent_daily_cleanup' );
        }
        if ( ! wp_next_scheduled( 'wp_agent_cost_alert_check' ) ) {
            wp_schedule_event( time(), 'hourly', 'wp_agent_cost_alert_check' );
        }
        // Ensure the recurring schedule check is scheduled on installs that
        // activated before this feature existed (interval registered via init()).
        WPAgent_Schedules::schedule_cron();
    }

    /**
     * Enqueue admin CSS and JS.
     */
    public function enqueue_admin_assets( $hook ) {
        // Admin page styles only on WP Agent pages.
        if ( strpos( $hook, 'wp-agent' ) !== false ) {
            // Web fonts for the Notion-inspired admin UI. Enqueue first so the
            // CSS custom properties (--wpa-sans / --wpa-mono)
            // resolve before the admin/chat stylesheets load.
            wp_enqueue_style(
                'wp-agent-fonts',
                'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap',
                array(),
                null
            );
            wp_enqueue_style(
                'wp-agent-admin',
                WP_AGENT_PLUGIN_URL . 'assets/css/admin.css',
                array( 'wp-agent-fonts' ),
                $this->asset_version( 'assets/css/admin.css' )
            );
            wp_enqueue_script(
                'wp-agent-admin',
                WP_AGENT_PLUGIN_URL . 'assets/js/admin.js',
                array(),
                $this->asset_version( 'assets/js/admin.js' ),
                true
            );
            wp_localize_script( 'wp-agent-admin', 'wpAgentChat', array(
                'restUrl'       => esc_url_raw( rest_url( 'wp-agent/v1/' ) ),
                'nonce'         => wp_create_nonce( 'wp_rest' ),
                'userId'        => get_current_user_id(),
                'siteName'      => get_bloginfo( 'name' ),
                'slashCommands' => array_map(
                    static function ( $cmd ) {
                        // The full prompt stays server-side; the composer only
                        // needs the short command + metadata to render the menu.
                        unset( $cmd['prompt'] );
                        return $cmd;
                    },
                    self::chat_slash_commands()
                ),
            ) );

            // Full-page chat assets only on the Chat page.
            // wpAgentChat is already localized on the wp-agent-admin handle.
            if ( strpos( $hook, 'wp-agent-agent' ) !== false ) {
                wp_enqueue_style(
                    'wp-agent-chat',
                    WP_AGENT_PLUGIN_URL . 'assets/css/chat.css',
                    array( 'wp-agent-fonts', 'wp-agent-admin' ),
                    $this->asset_version( 'assets/css/chat.css' )
                );
                wp_enqueue_script(
                    'wp-agent-chat',
                    WP_AGENT_PLUGIN_URL . 'assets/js/chat.js',
                    array( 'wp-agent-admin' ),
                    $this->asset_version( 'assets/js/chat.js' ),
                    true
                );
            }
        }
    }

    /**
     * Slash commands offered in the Agent composer. Each command is a short
     * token (e.g. /title-to-article) that the user types with an optional
     * argument; the backend expands it into the full instruction so the
     * composer stays clean — the agent reads the workflow template, not a long
     * pasted prompt. The agent still runs through the normal queue, tools, and
     * confirmation gates.
     *
     * @return array
     */
    public static function chat_slash_commands() {
        return array(
            array(
                'command'     => '/image-to-article',
                'title'       => __( 'Image to article', 'wp-agent' ),
                'description' => __( 'Generate a complete article from the attached image(s).', 'wp-agent' ),
                'needs'       => 'attachment',
                'hint'        => '',
                'prompt'      => __( 'Using the attached image(s), write a complete original article: title, body, excerpt, a fitting category and tags, SEO meta title/description and focus keyword, and set the image(s) with alt text and a caption. Save it as a draft and return the post ID and preview URL.', 'wp-agent' ),
            ),
            array(
                'command'     => '/title-to-article',
                'title'       => __( 'Title to article', 'wp-agent' ),
                'description' => __( 'Expand a title into a complete article.', 'wp-agent' ),
                'needs'       => 'text',
                'hint'        => __( 'article title', 'wp-agent' ),
                'prompt'      => __( 'Write a complete original article for this title: "%s". Include body, excerpt, a fitting category and tags, and SEO meta title/description and focus keyword. Save it as a draft and return the post ID and preview URL.', 'wp-agent' ),
            ),
            array(
                'command'     => '/research-article',
                'title'       => __( 'Research and write', 'wp-agent' ),
                'description' => __( 'Search public sources, then write a sourced article.', 'wp-agent' ),
                'needs'       => 'text',
                'hint'        => __( 'topic to research', 'wp-agent' ),
                'prompt'      => __( 'Research this topic from current public sources, then write an original, sourced article: "%s". Keep source URLs, write original prose, add a category, tags, and SEO metadata, and save it as a draft. Return the post ID, preview URL, and the source URLs used.', 'wp-agent' ),
            ),
            array(
                'command'     => '/paper-to-article',
                'title'       => __( 'Paper to article', 'wp-agent' ),
                'description' => __( 'Turn an uploaded PDF or a paper link into an accessible article with a generated cover image.', 'wp-agent' ),
                'needs'       => 'text',
                'hint'        => __( 'paper link (or attach a PDF)', 'wp-agent' ),
                'prompt'      => __( 'Turn this academic paper into a clear, accessible original article: "%s". If a PDF is attached, read it directly; if it is an HTML or PDF link, fetch it. Faithfully explain the paper for a general audience, keep the source title/authors/year and URL or DOI, and reuse the paper\'s own figures when they are available (otherwise say so). You MUST also call the image generation tool to create one detailed, intuitive cover image (landscape 1792x1024) that visualizes the core idea, and set it as the post\'s featured image — this cover image is required, do not skip it. Add a category, tags, and SEO metadata, run a quality check, and save it as a draft. Return the post ID, preview URL, the source, the featured image ID, and which figures were reused vs. the generated cover.', 'wp-agent' ),
            ),
            array(
                'command'     => '/expand-categories',
                'title'       => __( 'Expand categories', 'wp-agent' ),
                'description' => __( 'Propose and, after approval, create new categories and tags.', 'wp-agent' ),
                'needs'       => 'none',
                'hint'        => '',
                'prompt'      => __( 'Review the site\'s positioning, existing categories, tags, and recent posts, then propose an expanded category and tag structure with a one-line rationale for each. Ask me to confirm before creating anything, then create the approved terms.', 'wp-agent' ),
            ),
            array(
                'command'     => '/skill-creator',
                'title'       => __( 'Create a skill', 'wp-agent' ),
                'description' => __( 'Turn a described workflow into a reusable WP Agent skill.', 'wp-agent' ),
                'needs'       => 'text',
                'hint'        => __( 'describe the workflow to automate', 'wp-agent' ),
                'prompt'      => __( 'Create a new reusable WP Agent skill for this workflow: "%s". Interview me briefly if anything is unclear, check the site language and structure, choose the minimal set of real built-in tools the workflow needs, and write a complete skill with frontmatter (name, slug, description, permissions, triggers) and a body (Purpose, Operating Rules, Required Deliverables, Workflow, Quality Checklist). Show me the proposed frontmatter and body outline for confirmation, then save it with the manage_skills tool and tell me its slug, the tools it uses, its triggers, and how to run it.', 'wp-agent' ),
            ),
            array(
                'command'     => '/skill-search',
                'title'       => __( 'Search for a skill', 'wp-agent' ),
                'description' => __( 'Search GitHub for installable skills and add one after review.', 'wp-agent' ),
                'needs'       => 'text',
                'hint'        => __( 'what kind of skill to find', 'wp-agent' ),
                'prompt'      => __( 'Search GitHub for installable skills matching: "%s". Use the manage_skills tool with action search_github (it defaults to the configured Skill Store repository; I can also name another public owner/repo). Show me the matches as a short list — each with its name, what it does, the tools it uses, and its skill path. Ask me which one to add. When I pick one, install it with manage_skills action install_github using that repository and skill path so it lands in quarantine for review, then tell me its status and how to activate it.', 'wp-agent' ),
            ),
        );
    }

    /**
     * Expand a chat message that starts with a known slash command into the
     * full instruction the agent should act on. Leaves non-command messages
     * untouched. The user's argument (text after the command) is injected into
     * the template's "%s" placeholder, or appended when no placeholder exists.
     *
     * @param string $message Raw user message (may start with "/command ...").
     * @return string Expanded instruction, or the original message.
     */
    public static function expand_chat_slash_command( $message ) {
        $message = (string) $message;
        if ( '' === $message || '/' !== $message[0] ) {
            return $message;
        }

        // Separate the first line (which may carry the command + inline arg)
        // from the rest of the body (e.g. an attachment list with media markers
        // appended by compose_chat_message_with_attachments). Only the command
        // line is expanded; the remainder is preserved verbatim.
        $newline_pos = strpos( $message, "\n" );
        $first_line  = false === $newline_pos ? $message : substr( $message, 0, $newline_pos );
        $remainder   = false === $newline_pos ? '' : substr( $message, $newline_pos ); // keeps the leading "\n".

        if ( ! preg_match( '/^(\/[a-z0-9-]+)(?:\s+(.*))?$/i', trim( $first_line ), $m ) ) {
            return $message;
        }

        $command = strtolower( $m[1] );
        $arg     = isset( $m[2] ) ? trim( $m[2] ) : '';

        foreach ( self::chat_slash_commands() as $cmd ) {
            if ( strtolower( $cmd['command'] ) !== $command ) {
                continue;
            }
            $prompt = (string) $cmd['prompt'];
            if ( false !== strpos( $prompt, '%s' ) ) {
                // Templates with a placeholder need the argument; if missing, a
                // neutral instruction is used so the agent can still act.
                $value    = '' !== $arg ? $arg : __( '(no specific value given — choose a suitable one)', 'wp-agent' );
                $expanded = str_replace( '%s', $value, $prompt );
            } else {
                $expanded = $prompt;
                if ( '' !== $arg ) {
                    $expanded .= "\n\n" . sprintf(
                        /* translators: %s: extra user instruction. */
                        __( 'Additional instruction: %s', 'wp-agent' ),
                        $arg
                    );
                }
            }
            // Re-attach any preserved remainder (attachment list / media markers).
            return $expanded . $remainder;
        }

        return $message;
    }

    /**
     * Version plugin assets by file modification time during local development.
     */
    private function asset_version( $relative_path ) {
        $path = WP_AGENT_PLUGIN_DIR . ltrim( $relative_path, '/' );
        return file_exists( $path ) ? (string) filemtime( $path ) : WP_AGENT_VERSION;
    }

    /**
     * Add settings link on plugin list page.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wp-agent-settings' ) ) . '">'
            . esc_html__( 'Settings', 'wp-agent' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Daily cleanup task: prune old conversations (free tier), compact audit log.
     */
    public function daily_cleanup() {
        $conversation = new WPAgent_Conversation();
        $conversation->prune_expired();

        $audit_days = (int) self::get_option( 'audit_log_retention_days', 90 );
        if ( $audit_days > 0 ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}wp_agent_audit_log WHERE created_at < %s",
                gmdate( 'Y-m-d H:i:s', strtotime( "-{$audit_days} days" ) )
            ) );
        }
    }

    /**
     * Check if any user has exceeded their cost budget.
     */
    public function check_cost_alerts() {
        $tracker = new WPAgent_Cost_Tracker();
        $tracker->check_budget_alerts();
    }

    /**
     * Re-encrypt any legacy-format secrets with the modern HMAC-authenticated format.
     * Called on plugin upgrade.
     */
    public static function migrate_encryption() {
        $encrypted_keys = array(
            'meowl_api_key',
            'telegram_bot_token', 'slack_bot_token', 'slack_signing_secret',
            'discord_bot_token', 'discord_public_key',
        );
        foreach ( $encrypted_keys as $key ) {
            $stored = self::get_option( $key );
            if ( empty( $stored ) ) {
                continue;
            }
            // Try to decrypt — if it's legacy format, re-encrypt with modern format.
            $raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            // Modern format is HMAC(32) + IV(16) + ciphertext, so raw length > 48.
            // If raw length <= 48 or HMAC check fails, it's legacy format.
            if ( false === $raw || strlen( $raw ) <= 48 ) {
                $decrypted = self::decrypt( $stored );
                if ( ! empty( $decrypted ) ) {
                    self::update_option( $key, self::encrypt( $decrypted ) );
                }
            }
        }
    }

    /**
     * Get the AI provider instance based on configuration.
     *
     * @return WPAgent_AI_Provider
     */
    public static function get_ai_provider() {
        $api_key = self::decrypt( self::get_option( 'meowl_api_key' ) );
        $model   = self::get_option( 'meowl_model', '' );
        return new WPAgent_AI_Meowl( $api_key, $model );
    }

    /**
     * Read-only AI provider readiness without contacting the provider.
     *
     * @return array
     */
    public static function ai_provider_readiness() {
        $stored_key  = (string) self::get_option( 'meowl_api_key', '' );
        $api_key     = self::decrypt( $stored_key );
        $model       = trim( (string) self::get_option( 'meowl_model', '' ) );
        $image_model = trim( (string) self::get_option( 'image_model', '' ) );
        $base_url    = class_exists( 'WPAgent_AI_Meowl' ) ? WPAgent_AI_Meowl::base_url() : '';
        $source      = class_exists( 'WPAgent_AI_Meowl' ) ? WPAgent_AI_Meowl::base_url_source() : '';
        $host        = '' !== $base_url ? ( wp_parse_url( $base_url, PHP_URL_HOST ) ?: '' ) : '';

        $missing  = array();
        $warnings = array();
        if ( '' === $stored_key ) {
            $missing[] = 'api_key';
        } elseif ( '' === $api_key ) {
            $warnings[] = 'api_key_unreadable';
        }
        if ( '' === $model ) {
            $missing[] = 'model';
        }
        if ( '' === $image_model ) {
            $warnings[] = 'image_model_missing';
        }

        $api_key_usable = '' !== $api_key;
        $chat_ready     = $api_key_usable && '' !== $model;
        $image_ready    = $chat_ready && '' !== $image_model;
        if ( '' === $stored_key ) {
            $key_state = 'not_configured';
        } elseif ( $api_key_usable ) {
            $key_state = 'decryptable';
        } else {
            $key_state = 'unreadable';
        }

        if ( ! $chat_ready && in_array( 'api_key', $missing, true ) ) {
            $next_action = 'configure_api_key';
        } elseif ( ! $chat_ready && in_array( 'api_key_unreadable', $warnings, true ) ) {
            $next_action = 'resave_api_key';
        } elseif ( ! $chat_ready && in_array( 'model', $missing, true ) ) {
            $next_action = 'select_model';
        } elseif ( ! $image_ready ) {
            $next_action = 'configure_image_model';
        } else {
            $next_action = 'ready';
        }

        return array(
            'ready'                  => $chat_ready,
            'content_ready'          => $chat_ready,
            'image_generation_ready' => $image_ready,
            'api_key_configured'     => '' !== $stored_key,
            'api_key_usable'         => $api_key_usable,
            'api_key_state'          => $key_state,
            'model'                  => $model,
            'image_model'            => $image_model,
            'base_url'               => $base_url,
            'base_url_host'          => $host,
            'base_url_source'        => $source,
            'missing'                => $missing,
            'warnings'               => $warnings,
            'next_action'            => $next_action,
        );
    }

    /**
     * Get the agent instance.
     *
     * @return WPAgent_Agent
     */
    public static function get_agent() {
        static $agent = null;
        if ( null === $agent ) {
            $memory = new WPAgent_Memory();

            $agent = new WPAgent_Agent(
                self::get_ai_provider(),
                new WPAgent_Conversation(),
                $memory,
                new WPAgent_Permissions(),
                new WPAgent_Cost_Tracker()
            );
        }
        return $agent;
    }

    /**
     * Get a plugin option with optional default.
     *
     * @param string $key     Option key (without prefix).
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get_option( $key, $default = '' ) {
        return get_option( 'wp_agent_' . $key, $default );
    }

    /**
     * Update a plugin option.
     *
     * @param string $key   Option key (without prefix).
     * @param mixed  $value Option value.
     * @return bool
     */
    public static function update_option( $key, $value ) {
        return update_option( 'wp_agent_' . $key, $value );
    }

    /**
     * Encrypt a sensitive value before storing.
     *
     * @param string $value Plain text value.
     * @return string Encrypted value.
     */
    public static function encrypt( $value ) {
        if ( empty( $value ) ) {
            return '';
        }
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv  = random_bytes( 16 );
        $encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $encrypted ) {
            return '';
        }
        // Append HMAC for authenticated encryption.
        $hmac_key  = hash( 'sha256', wp_salt( 'secure_auth' ), true );
        $hmac      = hash_hmac( 'sha256', $iv . $encrypted, $hmac_key, true );
        return base64_encode( $hmac . $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for AES-256-CBC ciphertext encoding.
    }

    /**
     * Decrypt a sensitive value after retrieval.
     *
     * @param string $value Encrypted value.
     * @return string Decrypted plain text.
     */
    public static function decrypt( $value ) {
        if ( empty( $value ) ) {
            return '';
        }
        $raw = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required to decode AES-256-CBC ciphertext.
        if ( false === $raw ) {
            return '';
        }

        $key      = hash( 'sha256', wp_salt( 'auth' ), true );
        $hmac_key = hash( 'sha256', wp_salt( 'secure_auth' ), true );

        // New format: 32-byte HMAC + 16-byte IV + ciphertext.
        if ( strlen( $raw ) > 48 ) {
            $hmac       = substr( $raw, 0, 32 );
            $iv         = substr( $raw, 32, 16 );
            $ciphertext = substr( $raw, 48 );
            $expected   = hash_hmac( 'sha256', $iv . $ciphertext, $hmac_key, true );
            if ( hash_equals( $expected, $hmac ) ) {
                $decrypted = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
                if ( false !== $decrypted ) {
                    return $decrypted;
                }
            }
        }

        // Legacy fallback: static IV, base64-encoded ciphertext (pre-security-audit format).
        // Re-encrypts with the current (HMAC-authenticated) format on successful decrypt.
        $legacy_key = wp_salt( 'auth' );
        $legacy_iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
        $decrypted  = openssl_decrypt( base64_decode( $value ), 'AES-256-CBC', $legacy_key, 0, $legacy_iv ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Legacy decryption fallback.
        if ( false !== $decrypted && '' !== $decrypted ) {
            // Trigger re-encryption to modern format on next save opportunity.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WP Agent] Legacy encryption detected — value will be re-encrypted on next save.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return $decrypted;
        }
        return '';
    }

    /**
     * Log an audit event.
     *
     * @param int    $user_id WordPress user ID.
     * @param string $action  Action performed.
     * @param array  $details Additional details.
     * @param string $channel Channel the action originated from.
     */
    public static function audit_log( $user_id, $action, $details = array(), $channel = 'system' ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wp_agent_audit_log',
            array(
                'user_id'    => $user_id,
                'channel'    => $channel,
                'action'     => $action,
                'details'    => wp_json_encode( $details ),
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );
    }
}
