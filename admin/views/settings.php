<?php
/**
 * Settings page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$agent_mode     = WPAgent::get_option( 'mode', 'author' );
$ai_readiness   = WPAgent::ai_provider_readiness();
$meowl_key      = WPAgent::get_option( 'meowl_api_key' );
$has_meowl_key  = ! empty( $meowl_key );
$meowl_model    = WPAgent::get_option( 'meowl_model', '' );
$image_model    = WPAgent::get_option( 'image_model', '' );
$meowl_endpoint = WPAgent_AI_Meowl::base_url();
$meowl_endpoint_option = WPAgent::get_option( 'ai_base_url', '' );
$meowl_endpoint_source = WPAgent_AI_Meowl::base_url_source();
$ai_missing = is_array( $ai_readiness['missing'] ?? null ) ? $ai_readiness['missing'] : array();
$ai_warnings = is_array( $ai_readiness['warnings'] ?? null ) ? $ai_readiness['warnings'] : array();
$ai_status_class = ! empty( $ai_readiness['ready'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn';
$ai_status_label = ! empty( $ai_readiness['ready'] ) ? __( 'Ready', 'wp-agent' ) : __( 'Needs setup', 'wp-agent' );
if ( 'unreadable' === ( $ai_readiness['api_key_state'] ?? '' ) ) {
    $ai_status_label = __( 'API key needs re-save', 'wp-agent' );
}
$github_token   = WPAgent::get_option( 'github_token', '' );
$has_github_token = ! empty( $github_token );
$github_store   = WPAgent_Skills::github_store_defaults();
$github_default_repository = WPAgent::get_option( 'github_default_repository', '' );
$github_default_ref = WPAgent::get_option( 'github_default_ref', 'main' );
$github_default_skill_path = WPAgent::get_option( 'github_default_skill_path', '' );
$github_activation_policy = $github_store['activation_policy'] ?? 'quarantine';
$github_store_readiness = WPAgent_Skills::github_store_readiness();
$github_store_missing = is_array( $github_store_readiness['missing'] ?? null ) ? $github_store_readiness['missing'] : array();
$github_store_warnings = is_array( $github_store_readiness['warnings'] ?? null ) ? $github_store_readiness['warnings'] : array();
$github_store_ready = ! empty( $github_store_readiness['ready'] );
$github_store_status_class = $github_store_ready ? 'wp-agent-status--ok' : 'wp-agent-status--warn';
$github_store_status_label = $github_store_ready ? __( 'Ready', 'wp-agent' ) : __( 'Needs configuration', 'wp-agent' );
if ( in_array( 'github_token_unreadable', $github_store_warnings, true ) ) {
    $github_store_status_label = __( 'Token needs re-save', 'wp-agent' );
}
$telegram_token  = WPAgent::get_option( 'telegram_bot_token' );
$has_telegram    = ! empty( $telegram_token );
$slack_token     = WPAgent::get_option( 'slack_bot_token' );
$has_slack       = ! empty( $slack_token );
$slack_secret    = WPAgent::get_option( 'slack_signing_secret' );
$has_slack_secret = ! empty( $slack_secret );
$discord_token   = WPAgent::get_option( 'discord_bot_token' );
$has_discord     = ! empty( $discord_token );
$discord_app_id  = WPAgent::get_option( 'discord_application_id' );
$discord_pubkey  = WPAgent::get_option( 'discord_public_key' );
$mcp_registry    = new WPAgent_MCP_Registry();
$mcp_servers     = $mcp_registry->get_servers();
$monthly_budget  = WPAgent::get_option( 'monthly_budget', 0 );
$max_iterations  = WPAgent::get_option( 'max_iterations', WPAgent_Agent::DEFAULT_MAX_ITERATIONS );
$max_tokens      = WPAgent::get_option( 'max_tokens', 8192 );
$context_window  = WPAgent::get_option( 'context_window', 128000 );
$model_max_tokens = WPAgent::get_option( 'model_max_tokens', '' );
$bg_unlimited    = WPAgent::get_option( 'background_iterations_unlimited', false );
$runtime_root    = WPAgent::get_option( 'runtime_root', '' );
$runtime_selection = WPAgent_Sandbox::runtime_root_selection();
$runtime_default = (string) ( $runtime_selection['runtime_root'] ?? WPAgent_Sandbox::runtime_root() );
$runtime_active_source = (string) ( $runtime_selection['source'] ?? '' );
$runtime_active_source_label = (string) ( $runtime_selection['source_label'] ?? WPAgent_Sandbox::runtime_root_source_label( $runtime_active_source ) );
$runtime_configured_status = WPAgent_Sandbox::runtime_root_status( $runtime_root, false );
$runtime_active_status     = WPAgent_Sandbox::runtime_root_status( $runtime_default, false );
$runtime_configured_code   = $runtime_configured_status['code'] ?? '';
$runtime_configured_class  = ! empty( $runtime_configured_status['ok'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--error';
if ( 'empty' === $runtime_configured_code ) {
    $runtime_configured_class = 'wp-agent-status--info';
} elseif ( 'missing' === $runtime_configured_code ) {
    $runtime_configured_class = 'wp-agent-status--warn';
}
$runtime_configured_label = '' === $runtime_root ? __( 'Using default', 'wp-agent' ) : __( 'Configured root rejected', 'wp-agent' );
if ( ! empty( $runtime_configured_status['ok'] ) ) {
    $runtime_configured_label = __( 'Configured root ready', 'wp-agent' );
}
if ( in_array( $runtime_active_source, array( 'constant', 'environment' ), true ) ) {
    $runtime_configured_class = 'wp-agent-status--info';
    $runtime_configured_label = sprintf(
        /* translators: %s: active runtime root source label. */
        __( '%s active', 'wp-agent' ),
        $runtime_active_source_label
    );
} elseif ( '' === $runtime_root && '' !== $runtime_active_source_label ) {
    $runtime_configured_label = $runtime_active_source_label;
}
$runtime_active_class = ! empty( $runtime_active_status['ok'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--error';
$runtime_active_label = ! empty( $runtime_active_status['ok'] ) ? __( 'Active root ready', 'wp-agent' ) : __( 'Active root unavailable', 'wp-agent' );
$runtime_show_configured_status = ! ( '' === $runtime_root && in_array( $runtime_active_source, array( 'constant', 'environment' ), true ) );

// Moderation & Publishing.
$moderation_enabled       = WPAgent::get_option( 'moderation_enabled', false );
$syndicate_telegram       = WPAgent::get_option( 'syndicate_telegram', false );
$syndicate_telegram_chat  = WPAgent::get_option( 'syndicate_telegram_chat', '' );
$syndicate_discord        = WPAgent::get_option( 'syndicate_discord', false );
$syndicate_discord_channel = WPAgent::get_option( 'syndicate_discord_channel', '' );
$syndicate_x              = WPAgent::get_option( 'syndicate_x', false );
$x_access_token           = WPAgent::get_option( 'x_access_token' );
$has_x_token              = ! empty( $x_access_token );
$syndicate_reddit         = WPAgent::get_option( 'syndicate_reddit', false );
$reddit_client_id         = WPAgent::get_option( 'reddit_client_id', '' );
$reddit_client_secret     = WPAgent::get_option( 'reddit_client_secret' );
$has_reddit_secret        = ! empty( $reddit_client_secret );
$reddit_username          = WPAgent::get_option( 'reddit_username', '' );
$reddit_password          = WPAgent::get_option( 'reddit_password' );
$has_reddit_password      = ! empty( $reddit_password );
$reddit_subreddit         = WPAgent::get_option( 'reddit_subreddit', '' );

$permissions = new WPAgent_Permissions();
$pairings    = $permissions->get_user_pairings( get_current_user_id() );
?>
<div class="wp-agent-wrap">
    <div class="wp-agent-page-header">
        <h1><?php esc_html_e( 'Settings', 'wp-agent' ); ?></h1>
        <p><?php esc_html_e( 'Configure your AI agent, channels, and preferences', 'wp-agent' ); ?></p>
    </div>

    <div class="wp-agent-page-content">
        <form method="post" action="options.php">
            <?php settings_fields( 'wp_agent_settings' ); ?>

            <!-- AI Provider -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'AI Provider', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Readiness', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <span class="wp-agent-status <?php echo esc_attr( $ai_status_class ); ?>"><?php echo esc_html( $ai_status_label ); ?></span>
                            <?php if ( ! empty( $ai_missing ) ) : ?>
                                <p class="wp-agent-form-help">
                                    <?php
                                    printf(
                                        /* translators: %s: comma-separated missing AI settings. */
                                        esc_html__( 'Required settings missing: %s.', 'wp-agent' ),
                                        esc_html( implode( ', ', array_map( 'sanitize_key', $ai_missing ) ) )
                                    );
                                    ?>
                                </p>
                            <?php elseif ( in_array( 'image_model_missing', $ai_warnings, true ) ) : ?>
                                <p class="wp-agent-form-help"><?php esc_html_e( 'Text generation is ready. Configure an image model before relying on generated images.', 'wp-agent' ); ?></p>
                            <?php else : ?>
                                <p class="wp-agent-form-help"><?php esc_html_e( 'Text and image generation settings are ready for content automation.', 'wp-agent' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Endpoint', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="url" id="wp_agent_ai_base_url" name="wp_agent_ai_base_url"
                                   value="<?php echo esc_attr( $meowl_endpoint_option ); ?>"
                                class="wp-agent-input" placeholder="http://localhost:11434/v1" autocomplete="off"
                                   <?php disabled( in_array( $meowl_endpoint_source, array( 'constant', 'environment' ), true ) ); ?> />
                            <?php if ( in_array( $meowl_endpoint_source, array( 'constant', 'environment' ), true ) ) : ?>
                                <p class="wp-agent-form-help"><?php esc_html_e( 'This value is currently overridden by WP_AGENT_AI_BASE_URL or WP_AGENT_MEOWL_BASE_URL in wp-config.php or the server environment.', 'wp-agent' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'API Key', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password" id="wp_agent_meowl_api_key" name="wp_agent_meowl_api_key"
                                       value="<?php echo esc_attr( $has_meowl_key ? '••••••••••••••••' : '' ); ?>"
                                        class="wp-agent-input" placeholder="<?php esc_attr_e( 'Your AI gateway API key', 'wp-agent' ); ?>" autocomplete="off" />
                                <?php if ( ! empty( $ai_readiness['api_key_usable'] ) ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Connected', 'wp-agent' ); ?></span>
                                <?php elseif ( $has_meowl_key ) : ?>
                                    <span class="wp-agent-status wp-agent-status--warn"><?php esc_html_e( 'Re-save key', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Stored encrypted. Save your key, then the model list loads automatically.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Model', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <select id="wp_agent_meowl_model" name="wp_agent_meowl_model" class="wp-agent-select"
                                        data-current="<?php echo esc_attr( $meowl_model ); ?>" data-has-key="<?php echo $has_meowl_key ? '1' : '0'; ?>">
                                    <?php if ( $meowl_model ) : ?>
                                        <option value="<?php echo esc_attr( $meowl_model ); ?>" selected><?php echo esc_html( $meowl_model ); ?></option>
                                    <?php else : ?>
                                        <option value=""><?php esc_html_e( '— save your API key to load models —', 'wp-agent' ); ?></option>
                                    <?php endif; ?>
                                </select>
                                <button type="button" class="wp-agent-btn wp-agent-btn-sm" id="wp_agent_refresh_models"><?php esc_html_e( 'Refresh models', 'wp-agent' ); ?></button>
                            </div>
                            <p class="wp-agent-form-help" id="wp_agent_models_status"></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Image Model', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_image_model"
                                   name="wp_agent_image_model"
                                   value="<?php echo esc_attr( $image_model ); ?>"
                                   class="wp-agent-input"
                                   placeholder="<?php esc_attr_e( 'Optional image-capable model ID', 'wp-agent' ); ?>"
                                   autocomplete="off" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Used by generate_image. Leave blank only if your gateway has a working default image model.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Max output tokens', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="number"
                                   id="wp_agent_max_tokens"
                                   name="wp_agent_max_tokens"
                                   value="<?php echo esc_attr( $max_tokens ); ?>"
                                   class="wp-agent-input"
                                   min="256" max="200000" step="256"
                                   autocomplete="off" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Maximum tokens the model may generate per reply (the completion cap). Default 8192. Raise it for long-form articles; lower it to control cost.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Context window', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="number"
                                   id="wp_agent_context_window"
                                   name="wp_agent_context_window"
                                   value="<?php echo esc_attr( $context_window ); ?>"
                                   class="wp-agent-input"
                                   min="4000" max="2000000" step="1000"
                                   autocomplete="off" />
                            <p class="wp-agent-form-help"><?php esc_html_e( "The model's total input context window in tokens. When a conversation grows close to this limit, older turns are automatically compacted into a summary so the agent never overflows the model. Set this to match your configured model.", 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Per-model output caps', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <textarea id="wp_agent_model_max_tokens"
                                   name="wp_agent_model_max_tokens"
                                   class="wp-agent-input"
                                   rows="3"
                                   placeholder='{"gpt-4o": 8192, "claude-sonnet-4": 16384}'
                                   autocomplete="off"><?php echo esc_textarea( $model_max_tokens ); ?></textarea>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Optional JSON map overriding the max output tokens for specific model IDs. Leave blank to use the global value above for all models.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Skills Store', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Readiness', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <span class="wp-agent-status <?php echo esc_attr( $github_store_status_class ); ?>"><?php echo esc_html( $github_store_status_label ); ?></span>
                            <?php if ( ! empty( $github_store_missing ) ) : ?>
                                <p class="wp-agent-form-help">
                                    <?php
                                    printf(
                                        /* translators: %s: comma-separated missing Skills Store settings. */
                                        esc_html__( 'Required defaults missing: %s.', 'wp-agent' ),
                                        esc_html( implode( ', ', array_map( 'sanitize_key', $github_store_missing ) ) )
                                    );
                                    ?>
                                </p>
                            <?php elseif ( ! empty( $github_store_readiness['token_configured'] ) ) : ?>
                                <p class="wp-agent-form-help"><?php esc_html_e( 'Default installs and live acceptance can use the configured repository, ref, Skill path, review policy, and encrypted GitHub token.', 'wp-agent' ); ?></p>
                            <?php else : ?>
                                <p class="wp-agent-form-help"><?php esc_html_e( 'Default installs and live acceptance can use the configured repository, ref, Skill path, and review policy for public repositories.', 'wp-agent' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'GitHub Token', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password"
                                       id="wp_agent_github_token"
                                       name="wp_agent_github_token"
                                       value="<?php echo esc_attr( $has_github_token ? '••••••••••••••••' : '' ); ?>"
                                       class="wp-agent-input"
                                       placeholder="<?php esc_attr_e( 'github_pat_...', 'wp-agent' ); ?>"
                                       autocomplete="off" />
                                <?php if ( $has_github_token ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Configured', 'wp-agent' ); ?></span>
                                <?php else : ?>
                                    <span class="wp-agent-status wp-agent-status--info"><?php esc_html_e( 'Optional', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Used for private GitHub skill packages and higher API limits. Stored encrypted and never written to package lockfiles.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Default Repository', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_github_default_repository"
                                   name="wp_agent_github_default_repository"
                                   value="<?php echo esc_attr( $github_default_repository ); ?>"
                                   class="wp-agent-input"
                                   placeholder="<?php esc_attr_e( 'owner/repository', 'wp-agent' ); ?>"
                                   autocomplete="off" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Optional default repository for the official Skills Store. GitHub installs can omit repository when this is set.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Default Ref', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_github_default_ref"
                                   name="wp_agent_github_default_ref"
                                   value="<?php echo esc_attr( $github_default_ref ); ?>"
                                   class="wp-agent-input"
                                   placeholder="<?php esc_attr_e( 'main, tag, or commit', 'wp-agent' ); ?>"
                                   autocomplete="off" />
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Default Skill Path', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_github_default_skill_path"
                                   name="wp_agent_github_default_skill_path"
                                   value="<?php echo esc_attr( $github_default_skill_path ); ?>"
                                   class="wp-agent-input"
                                   placeholder="<?php esc_attr_e( 'skills/news-site-operator', 'wp-agent' ); ?>"
                                   autocomplete="off" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Optional default package directory or SKILL.md path. GitHub installs can omit Skill Path when this is set.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Review Policy', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <select id="wp_agent_github_activation_policy" name="wp_agent_github_activation_policy" class="wp-agent-select">
                                <option value="quarantine" <?php selected( $github_activation_policy, 'quarantine' ); ?>><?php esc_html_e( 'Quarantine only', 'wp-agent' ); ?></option>
                                <option value="activate" <?php selected( $github_activation_policy, 'activate' ); ?>><?php esc_html_e( 'Activate when requested', 'wp-agent' ); ?></option>
                                <option value="activate_pin" <?php selected( $github_activation_policy, 'activate_pin' ); ?>><?php esc_html_e( 'Activate and pin when requested', 'wp-agent' ); ?></option>
                            </select>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Live acceptance and future store workflows can use this default. Normal downloads still enter quarantine for review.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Operating Mode -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Operating Mode', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Mode', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <select id="wp_agent_mode" name="wp_agent_mode" class="wp-agent-select" style="max-width: 280px;">
                                <option value="author" <?php selected( $agent_mode, 'author' ); ?>><?php esc_html_e( 'Author', 'wp-agent' ); ?></option>
                                <option value="editor" <?php selected( $agent_mode, 'editor' ); ?>><?php esc_html_e( 'Editor', 'wp-agent' ); ?></option>
                                <option value="administrator" <?php selected( $agent_mode, 'administrator' ); ?>><?php esc_html_e( 'Administrator', 'wp-agent' ); ?></option>
                                <option value="root" <?php selected( $agent_mode, 'root' ); ?>><?php esc_html_e( 'Root', 'wp-agent' ); ?></option>
                            </select>
                            <p class="wp-agent-form-help">
                                <?php esc_html_e( 'Sets a hard capability ceiling for the agent. The agent acts as a dedicated "wp-agent" WordPress user — never as you — so it can never do more than the mode allows.', 'wp-agent' ); ?>
                            </p>
                            <ul class="wp-agent-form-help" style="margin: 8px 0 0; padding-left: 18px; list-style: disc;">
                                <li><strong><?php esc_html_e( 'Author', 'wp-agent' ); ?></strong> — <?php esc_html_e( 'Create and edit posts and pages, but publishing requires your approval.', 'wp-agent' ); ?></li>
                                <li><strong><?php esc_html_e( 'Editor', 'wp-agent' ); ?></strong> — <?php esc_html_e( 'Everything in Author, plus publishing and managing others\' content.', 'wp-agent' ); ?></li>
                                <li><strong><?php esc_html_e( 'Administrator', 'wp-agent' ); ?></strong> — <?php esc_html_e( 'Everything in Editor, plus site options, users, and WooCommerce.', 'wp-agent' ); ?></li>
                                <li><strong><?php esc_html_e( 'Root', 'wp-agent' ); ?></strong> — <?php esc_html_e( 'Full control of all WordPress data and settings.', 'wp-agent' ); ?></li>
                            </ul>
                            <p class="wp-agent-form-help" style="margin-top: 10px; color: #d97706;">
                                <strong><?php esc_html_e( 'Root warning:', 'wp-agent' ); ?></strong>
                                <?php esc_html_e( 'Full control of all WordPress data & settings; still cannot execute code on the server.', 'wp-agent' ); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Telegram -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Telegram', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Bot Token', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password"
                                       id="wp_agent_telegram_bot_token"
                                       name="wp_agent_telegram_bot_token"
                                       value="<?php echo esc_attr( $has_telegram ? '••••••••••••••••' : '' ); ?>"
                                       class="wp-agent-input"
                                       placeholder="123456789:ABCdefGHIjklMNOpqrSTUvwxYZ"
                                       autocomplete="off" />
                                <?php if ( $has_telegram ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Connected', 'wp-agent' ); ?></span>
                                <?php else : ?>
                                    <span class="wp-agent-status wp-agent-status--info"><?php esc_html_e( 'Optional', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Create a bot with @BotFather on Telegram, then paste the token here.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Slack -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Slack', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Bot Token', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password"
                                       id="wp_agent_slack_bot_token"
                                       name="wp_agent_slack_bot_token"
                                       value="<?php echo esc_attr( $has_slack ? '••••••••••••••••' : '' ); ?>"
                                       class="wp-agent-input"
                                       placeholder="xoxb-..."
                                       autocomplete="off" />
                                <?php if ( $has_slack ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Connected', 'wp-agent' ); ?></span>
                                <?php else : ?>
                                    <span class="wp-agent-status wp-agent-status--info"><?php esc_html_e( 'Optional', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="wp-agent-form-help">
                                <?php printf(
                                    esc_html__( 'Create a Slack app at %s, install it to your workspace, and paste the Bot User OAuth Token here.', 'wp-agent' ),
                                    '<a href="https://api.slack.com/apps" target="_blank" rel="noopener">api.slack.com/apps</a>'
                                ); ?>
                            </p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Signing Secret', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password"
                                       id="wp_agent_slack_signing_secret"
                                       name="wp_agent_slack_signing_secret"
                                       value="<?php echo esc_attr( $has_slack_secret ? '••••••••••••••••' : '' ); ?>"
                                       class="wp-agent-input"
                                       placeholder="Signing Secret from Basic Information"
                                       autocomplete="off" />
                                <?php if ( $has_slack_secret ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Set', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Found under your Slack app\'s Basic Information > Signing Secret. Used to verify incoming webhooks.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <?php if ( $has_slack ) : ?>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Webhook URL', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <code class="wp-agent-code-block"><?php echo esc_url( rest_url( 'wp-agent/v1/slack' ) ); ?></code>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Enter this URL in your Slack app\'s Event Subscriptions > Request URL.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Discord -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Discord', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Bot Token', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password"
                                       id="wp_agent_discord_bot_token"
                                       name="wp_agent_discord_bot_token"
                                       value="<?php echo esc_attr( $has_discord ? '••••••••••••••••' : '' ); ?>"
                                       class="wp-agent-input"
                                       placeholder="Discord bot token"
                                       autocomplete="off" />
                                <?php if ( $has_discord ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Connected', 'wp-agent' ); ?></span>
                                <?php else : ?>
                                    <span class="wp-agent-status wp-agent-status--info"><?php esc_html_e( 'Optional', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="wp-agent-form-help">
                                <?php printf(
                                    esc_html__( 'Create a Discord application at %s, add a bot, and paste the token here.', 'wp-agent' ),
                                    '<a href="https://discord.com/developers/applications" target="_blank" rel="noopener">discord.com/developers</a>'
                                ); ?>
                            </p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Application ID', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_discord_application_id"
                                   name="wp_agent_discord_application_id"
                                   value="<?php echo esc_attr( $discord_app_id ); ?>"
                                   class="wp-agent-input"
                                   placeholder="e.g. 123456789012345678" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Found on your Discord application\'s General Information page.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Public Key', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_discord_public_key"
                                   name="wp_agent_discord_public_key"
                                   value="<?php echo esc_attr( $discord_pubkey ); ?>"
                                   class="wp-agent-input"
                                   placeholder="Ed25519 public key (hex)" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Found on your Discord application\'s General Information page. Used for webhook signature verification.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <?php if ( $has_discord ) : ?>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Interactions URL', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <code class="wp-agent-code-block"><?php echo esc_url( rest_url( 'wp-agent/v1/discord' ) ); ?></code>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Enter this URL in your Discord application\'s General Information > Interactions Endpoint URL.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Moderation & Publishing -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Moderation & Publishing', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Require Approval', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-toggle-row">
                                <label class="wp-agent-toggle">
                                    <input type="checkbox"
                                           id="wp_agent_moderation_enabled"
                                           name="wp_agent_moderation_enabled"
                                           value="1"
                                           <?php checked( $moderation_enabled ); ?> />
                                    <span class="wp-agent-toggle-track"></span>
                                </label>
                                <span class="wp-agent-toggle-label"><?php esc_html_e( 'Submit drafts for human approval before publishing', 'wp-agent' ); ?></span>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'When enabled, the agent can send drafts for approval via your paired IM channels. Approved posts are published and syndicated to the enabled targets below.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Telegram syndication -->
                <h3 style="font-size: 14px; font-weight: 600; color: var(--cwp-text); margin: 24px 0 12px;"><?php esc_html_e( 'Syndicate to Telegram', 'wp-agent' ); ?></h3>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Enabled', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-toggle-row">
                                <label class="wp-agent-toggle">
                                    <input type="checkbox"
                                           id="wp_agent_syndicate_telegram"
                                           name="wp_agent_syndicate_telegram"
                                           value="1"
                                           <?php checked( $syndicate_telegram ); ?> />
                                    <span class="wp-agent-toggle-track"></span>
                                </label>
                                <span class="wp-agent-toggle-label"><?php esc_html_e( 'Post a link to Telegram on publish', 'wp-agent' ); ?></span>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Uses the Telegram bot token configured above.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Chat ID', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_syndicate_telegram_chat"
                                   name="wp_agent_syndicate_telegram_chat"
                                   value="<?php echo esc_attr( $syndicate_telegram_chat ); ?>"
                                   class="wp-agent-input"
                                   placeholder="e.g. -1001234567890 or @channelname" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'The chat, group, or channel ID to broadcast to.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Discord syndication -->
                <h3 style="font-size: 14px; font-weight: 600; color: var(--cwp-text); margin: 24px 0 12px;"><?php esc_html_e( 'Syndicate to Discord', 'wp-agent' ); ?></h3>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Enabled', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-toggle-row">
                                <label class="wp-agent-toggle">
                                    <input type="checkbox"
                                           id="wp_agent_syndicate_discord"
                                           name="wp_agent_syndicate_discord"
                                           value="1"
                                           <?php checked( $syndicate_discord ); ?> />
                                    <span class="wp-agent-toggle-track"></span>
                                </label>
                                <span class="wp-agent-toggle-label"><?php esc_html_e( 'Post a link to Discord on publish', 'wp-agent' ); ?></span>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Uses the Discord bot token and application ID configured above.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Channel ID', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_syndicate_discord_channel"
                                   name="wp_agent_syndicate_discord_channel"
                                   value="<?php echo esc_attr( $syndicate_discord_channel ); ?>"
                                   class="wp-agent-input"
                                   placeholder="e.g. 123456789012345678" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'The Discord channel ID to broadcast to.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                </div>

                <!-- X syndication -->
                <h3 style="font-size: 14px; font-weight: 600; color: var(--cwp-text); margin: 24px 0 12px;"><?php esc_html_e( 'Syndicate to X', 'wp-agent' ); ?></h3>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Enabled', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-toggle-row">
                                <label class="wp-agent-toggle">
                                    <input type="checkbox"
                                           id="wp_agent_syndicate_x"
                                           name="wp_agent_syndicate_x"
                                           value="1"
                                           <?php checked( $syndicate_x ); ?> />
                                    <span class="wp-agent-toggle-track"></span>
                                </label>
                                <span class="wp-agent-toggle-label"><?php esc_html_e( 'Post a tweet on publish', 'wp-agent' ); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Access Token', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password"
                                       id="wp_agent_x_access_token"
                                       name="wp_agent_x_access_token"
                                       value="<?php echo esc_attr( $has_x_token ? '••••••••••••••••' : '' ); ?>"
                                       class="wp-agent-input"
                                       placeholder="OAuth 2.0 user access token"
                                       autocomplete="off" />
                                <?php if ( $has_x_token ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Set', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'OAuth 2.0 bearer token with tweet.write scope. Stored encrypted.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Reddit syndication -->
                <h3 style="font-size: 14px; font-weight: 600; color: var(--cwp-text); margin: 24px 0 12px;"><?php esc_html_e( 'Syndicate to Reddit', 'wp-agent' ); ?></h3>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Enabled', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-toggle-row">
                                <label class="wp-agent-toggle">
                                    <input type="checkbox"
                                           id="wp_agent_syndicate_reddit"
                                           name="wp_agent_syndicate_reddit"
                                           value="1"
                                           <?php checked( $syndicate_reddit ); ?> />
                                    <span class="wp-agent-toggle-track"></span>
                                </label>
                                <span class="wp-agent-toggle-label"><?php esc_html_e( 'Submit a link post on publish', 'wp-agent' ); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Client ID', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_reddit_client_id"
                                   name="wp_agent_reddit_client_id"
                                   value="<?php echo esc_attr( $reddit_client_id ); ?>"
                                   class="wp-agent-input"
                                   placeholder="Reddit app client ID" />
                            <p class="wp-agent-form-help">
                                <?php printf(
                                    esc_html__( 'Create a "script" app at %s to get a client ID and secret.', 'wp-agent' ),
                                    '<a href="https://www.reddit.com/prefs/apps" target="_blank" rel="noopener">reddit.com/prefs/apps</a>'
                                ); ?>
                            </p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Client Secret', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password"
                                       id="wp_agent_reddit_client_secret"
                                       name="wp_agent_reddit_client_secret"
                                       value="<?php echo esc_attr( $has_reddit_secret ? '••••••••••••••••' : '' ); ?>"
                                       class="wp-agent-input"
                                       placeholder="Reddit app client secret"
                                       autocomplete="off" />
                                <?php if ( $has_reddit_secret ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Set', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Username', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_reddit_username"
                                   name="wp_agent_reddit_username"
                                   value="<?php echo esc_attr( $reddit_username ); ?>"
                                   class="wp-agent-input"
                                   placeholder="Reddit username"
                                   autocomplete="off" />
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Password', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="password"
                                       id="wp_agent_reddit_password"
                                       name="wp_agent_reddit_password"
                                       value="<?php echo esc_attr( $has_reddit_password ? '••••••••••••••••' : '' ); ?>"
                                       class="wp-agent-input"
                                       placeholder="Reddit password"
                                       autocomplete="off" />
                                <?php if ( $has_reddit_password ) : ?>
                                    <span class="wp-agent-status wp-agent-status--ok"><?php esc_html_e( 'Set', 'wp-agent' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Stored encrypted. Used for the OAuth2 password grant.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Subreddit', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_reddit_subreddit"
                                   name="wp_agent_reddit_subreddit"
                                   value="<?php echo esc_attr( $reddit_subreddit ); ?>"
                                   class="wp-agent-input"
                                   placeholder="e.g. test" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'The subreddit name (without the r/ prefix) to submit link posts to.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Custom MCP Servers -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Custom MCP Servers', 'wp-agent' ); ?></h2>

                <?php
                // Filter out built-in servers from the table.
                $custom_servers = array_filter( $mcp_servers, function( $srv ) { return empty( $srv['builtin'] ); } );
                ?>
                <?php if ( ! empty( $custom_servers ) ) : ?>
                <div class="wp-agent-table-wrap" style="margin-bottom: 20px;">
                    <table class="wp-agent-table" id="wp_agent_mcp_table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Endpoint', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Tools', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $custom_servers as $srv ) : ?>
                            <tr data-server-id="<?php echo esc_attr( $srv['id'] ); ?>">
                                <td data-label="<?php esc_attr_e( 'Name', 'wp-agent' ); ?>">
                                    <strong><?php echo esc_html( $srv['name'] ); ?></strong>
                                    <span class="wp-agent-badge" style="margin-left: 6px; font-size: 10px;"><?php echo esc_html( strtoupper( $srv['transport'] ?? 'http' ) ); ?></span>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Endpoint', 'wp-agent' ); ?>"><code style="font-size: 12px;"><?php echo esc_html( 'stdio' === ( $srv['transport'] ?? 'http' ) ? ( $srv['command'] ?? '' ) : $srv['endpoint'] ); ?></code></td>
                                <td class="wp-agent-mcp-tool-count" data-label="<?php esc_attr_e( 'Tools', 'wp-agent' ); ?>">
                                    <span class="wp-agent-badge wp-agent-badge--channel"><?php echo esc_html( count( $srv['tools'] ?? array() ) ); ?> tools</span>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Actions', 'wp-agent' ); ?>">
                                    <button type="button" class="wp-agent-btn wp-agent-btn-primary wp-agent-btn-sm wp-agent-mcp-discover"
                                            data-id="<?php echo esc_attr( $srv['id'] ); ?>">
                                        <?php esc_html_e( 'Rediscover', 'wp-agent' ); ?>
                                    </button>
                                    <button type="button" class="wp-agent-btn wp-agent-btn-danger wp-agent-btn-sm wp-agent-mcp-remove"
                                            data-id="<?php echo esc_attr( $srv['id'] ); ?>">
                                        <?php esc_html_e( 'Remove', 'wp-agent' ); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <div class="wp-agent-form-card">
                    <p style="margin: 0 0 16px; color: #64748b; font-size: 13px;">
                        <?php esc_html_e( 'Connect any MCP server to add its tools to your Ai agent. Supports HTTP endpoints and local stdio commands.', 'wp-agent' ); ?>
                    </p>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Server Name', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_mcp_name"
                                   class="wp-agent-input"
                                   placeholder="e.g. My MCP Server" />
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Transport', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <select id="wp_agent_mcp_transport" class="wp-agent-select" style="max-width: 280px;">
                                <option value="http"><?php esc_html_e( 'HTTP (Streamable HTTP endpoint)', 'wp-agent' ); ?></option>
                                <option value="stdio"><?php esc_html_e( 'Stdio (local command)', 'wp-agent' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="wp-agent-form-row" id="wp_agent_mcp_endpoint_row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Endpoint URL', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="url"
                                   id="wp_agent_mcp_endpoint"
                                   class="wp-agent-input"
                                   placeholder="https://example.com/mcp" />
                        </div>
                    </div>
                    <div class="wp-agent-form-row" id="wp_agent_mcp_command_row" style="display:none;">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Command', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_mcp_command"
                                   class="wp-agent-input"
                                   placeholder="npx some-mcp-server" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'The shell command to run the MCP server. Requires Node.js on this server.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row" id="wp_agent_mcp_auth_row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Authentication', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <select id="wp_agent_mcp_auth_type" class="wp-agent-select" style="max-width: 200px;">
                                <option value="none"><?php esc_html_e( 'None', 'wp-agent' ); ?></option>
                                <option value="basic"><?php esc_html_e( 'Basic Auth', 'wp-agent' ); ?></option>
                                <option value="bearer"><?php esc_html_e( 'Bearer Token', 'wp-agent' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="wp-agent-form-row" id="wp_agent_mcp_creds_row" style="display:none;">
                        <div class="wp-agent-form-label" id="wp_agent_mcp_creds_label"><?php esc_html_e( 'Credentials', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="password"
                                   id="wp_agent_mcp_credentials"
                                   class="wp-agent-input"
                                   placeholder=""
                                   autocomplete="off" />
                            <p class="wp-agent-form-help" id="wp_agent_mcp_creds_help"></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"></div>
                        <div class="wp-agent-form-field">
                            <button type="button" id="wp_agent_mcp_add" class="wp-agent-btn wp-agent-btn-primary">
                                <?php esc_html_e( 'Add Server', 'wp-agent' ); ?>
                            </button>
                            <div id="wp_agent_mcp_result" class="wp-agent-pair-result"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Channel Pairings -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Channel Pairings', 'wp-agent' ); ?></h2>

                <?php if ( ! empty( $pairings ) ) : ?>
                <div class="wp-agent-table-wrap" style="margin-bottom: 20px;">
                    <table class="wp-agent-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Channel', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'User ID', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Paired', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $pairings as $pairing ) : ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Channel', 'wp-agent' ); ?>"><span class="wp-agent-badge wp-agent-badge--channel"><?php echo esc_html( ucfirst( $pairing['channel'] ) ); ?></span></td>
                                <td data-label="<?php esc_attr_e( 'User ID', 'wp-agent' ); ?>"><code><?php echo esc_html( $pairing['channel_user_id'] ); ?></code></td>
                                <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Paired', 'wp-agent' ); ?>"><?php echo esc_html( human_time_diff( strtotime( $pairing['paired_at'] ) ) ); ?> ago</td>
                                <td data-label="<?php esc_attr_e( 'Actions', 'wp-agent' ); ?>">
                                    <button type="button" class="wp-agent-btn wp-agent-btn-danger wp-agent-btn-sm wp-agent-unpair"
                                            data-channel="<?php echo esc_attr( $pairing['channel'] ); ?>"
                                            data-user="<?php echo esc_attr( $pairing['channel_user_id'] ); ?>">
                                        <?php esc_html_e( 'Unpair', 'wp-agent' ); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Pairing Code', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-form-field-row">
                                <input type="text"
                                       id="wp_agent_pair_code"
                                       class="wp-agent-input wp-agent-input--small"
                                       placeholder="123456"
                                       maxlength="6"
                                       pattern="[0-9]{6}"
                                       style="max-width: 140px; letter-spacing: 0.15em; text-align: center; font-weight: 600;" />
                                <button type="button" id="wp_agent_pair_submit" class="wp-agent-btn wp-agent-btn-primary">
                                    <?php esc_html_e( 'Pair', 'wp-agent' ); ?>
                                </button>
                            </div>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Send /pair to your Telegram bot to get a code, then enter it here.', 'wp-agent' ); ?></p>
                            <div id="wp_agent_pair_result" class="wp-agent-pair-result"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Budget & Limits -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Budget & Limits', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Monthly Budget (USD)', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="number"
                                   id="wp_agent_monthly_budget"
                                   name="wp_agent_monthly_budget"
                                   value="<?php echo esc_attr( $monthly_budget ); ?>"
                                   min="0"
                                   step="1"
                                   class="wp-agent-input wp-agent-input--small" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Set to 0 for no limit. You will receive alerts at 80% and 100% usage.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Agent Iterations per Request', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="number"
                                   id="wp_agent_max_iterations"
                                   name="wp_agent_max_iterations"
                                   value="<?php echo esc_attr( $max_iterations ); ?>"
                                   min="0"
                                   max="10000"
                                   step="1"
                                   class="wp-agent-input wp-agent-input--small" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Maximum agent steps (model calls) per request before the agent stops and writes a final summary. Default 100. Set to 0 for unlimited.', 'wp-agent' ); ?></p>
                        </div>
                    </div>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Unlimited Background Iterations', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-toggle-row">
                                <label class="wp-agent-toggle">
                                    <input type="checkbox"
                                           id="wp_agent_background_iterations_unlimited"
                                           name="wp_agent_background_iterations_unlimited"
                                           value="1"
                                           <?php checked( $bg_unlimited ); ?> />
                                    <span class="wp-agent-toggle-track"></span>
                                </label>
                                <span class="wp-agent-toggle-label"><?php esc_html_e( 'Let scheduled / autonomous background runs ignore the iteration limit and run until they finish.', 'wp-agent' ); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Runtime Storage -->
            <div class="wp-agent-section">
                <h2 class="wp-agent-section-title"><?php esc_html_e( 'Runtime Storage', 'wp-agent' ); ?></h2>
                <div class="wp-agent-form-card">
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Private Runtime Root', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <input type="text"
                                   id="wp_agent_runtime_root"
                                   name="wp_agent_runtime_root"
                                   value="<?php echo esc_attr( $runtime_root ); ?>"
                                   class="wp-agent-input"
                                   placeholder="<?php echo esc_attr( $runtime_default ); ?>" />
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Stores private workspaces, isolated execution scratch files, daemon PID files, and runtime logs outside the web root. Leave blank to use the server temp directory.', 'wp-agent' ); ?></p>
                            <?php if ( in_array( $runtime_active_source, array( 'constant', 'environment' ), true ) ) : ?>
                                <p class="wp-agent-form-help"><?php esc_html_e( 'The current effective root is controlled by WP_AGENT_RUNTIME_ROOT; the saved setting is ignored until that override is removed.', 'wp-agent' ); ?></p>
                            <?php endif; ?>
                            <div class="wp-agent-form-field-row">
                                <span class="wp-agent-status <?php echo esc_attr( $runtime_configured_class ); ?>">
                                    <?php echo esc_html( $runtime_configured_label ); ?>
                                </span>
                                <?php if ( $runtime_show_configured_status && '' !== $runtime_configured_code ) : ?>
                                    <code><?php echo esc_html( $runtime_configured_code ); ?></code>
                                <?php endif; ?>
                            </div>
                            <?php if ( $runtime_show_configured_status && ! empty( $runtime_configured_status['message'] ) ) : ?>
                                <p class="wp-agent-form-help"><?php echo esc_html( $runtime_configured_status['message'] ); ?></p>
                            <?php endif; ?>
                            <p class="wp-agent-form-help">
                                <?php esc_html_e( 'Current effective root:', 'wp-agent' ); ?>
                                <code><?php echo esc_html( $runtime_default ); ?></code>
                                <span class="wp-agent-status <?php echo esc_attr( $runtime_active_class ); ?>">
                                    <?php echo esc_html( $runtime_active_label ); ?>
                                </span>
                                <span class="wp-agent-text-muted"><?php echo esc_html( $runtime_active_source_label ); ?></span>
                            </p>
                            <?php if ( empty( $runtime_active_status['ok'] ) && ! empty( $runtime_active_status['message'] ) ) : ?>
                                <p class="wp-agent-form-help"><?php echo esc_html( $runtime_active_status['message'] ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="submit">
                <button type="submit" id="submit" class="wp-agent-btn wp-agent-btn-primary"><?php esc_html_e( 'Save Changes', 'wp-agent' ); ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    // Escape HTML to prevent XSS from API responses.
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    // MCP Servers — transport type toggle.
    var mcpTransportSelect = document.getElementById('wp_agent_mcp_transport');
    if (mcpTransportSelect) {
        mcpTransportSelect.addEventListener('change', function() {
            var isStdio = this.value === 'stdio';
            var endpointRow = document.getElementById('wp_agent_mcp_endpoint_row');
            var commandRow = document.getElementById('wp_agent_mcp_command_row');
            var authRow = document.getElementById('wp_agent_mcp_auth_row');
            var credsRow = document.getElementById('wp_agent_mcp_creds_row');

            endpointRow.style.display = isStdio ? 'none' : '';
            commandRow.style.display = isStdio ? '' : 'none';
            authRow.style.display = isStdio ? 'none' : '';
            if (isStdio) credsRow.style.display = 'none';
        });
    }

    // MCP Servers — auth type toggle.
    var mcpAuthSelect = document.getElementById('wp_agent_mcp_auth_type');
    if (mcpAuthSelect) {
        mcpAuthSelect.addEventListener('change', function() {
            var val = this.value;
            var row = document.getElementById('wp_agent_mcp_creds_row');
            var label = document.getElementById('wp_agent_mcp_creds_label');
            var input = document.getElementById('wp_agent_mcp_credentials');
            var help = document.getElementById('wp_agent_mcp_creds_help');

            if (val === 'none') {
                row.style.display = 'none';
            } else {
                row.style.display = '';
                if (val === 'basic') {
                    label.textContent = 'Username:Password';
                    input.placeholder = 'username:password';
                    help.textContent = 'Format: username:password';
                } else {
                    label.textContent = 'Bearer Token';
                    input.placeholder = 'your-api-token';
                    help.textContent = 'API token or key for the MCP server.';
                }
            }
        });
    }

    // MCP Servers — add server.
    var mcpAddBtn = document.getElementById('wp_agent_mcp_add');
    if (mcpAddBtn) {
        mcpAddBtn.addEventListener('click', function() {
            var name = document.getElementById('wp_agent_mcp_name').value.trim();
            var transport = document.getElementById('wp_agent_mcp_transport').value;
            var endpoint = document.getElementById('wp_agent_mcp_endpoint').value.trim();
            var authType = document.getElementById('wp_agent_mcp_auth_type').value;
            var credentials = document.getElementById('wp_agent_mcp_credentials')?.value || '';
            var command = document.getElementById('wp_agent_mcp_command')?.value.trim() || '';
            var result = document.getElementById('wp_agent_mcp_result');

            if (!name) {
                result.innerHTML = '<span style="color:#dc2626;">Server name is required.</span>';
                return;
            }
            if (transport === 'stdio' && !command) {
                result.innerHTML = '<span style="color:#dc2626;">Command is required for stdio transport.</span>';
                return;
            }
            if (transport === 'http' && !endpoint) {
                result.innerHTML = '<span style="color:#dc2626;">Endpoint URL is required for HTTP transport.</span>';
                return;
            }

            result.innerHTML = '<span style="color:#666;">Connecting and discovering tools...</span>';
            mcpAddBtn.disabled = true;

            fetch(wpAgentChat.restUrl + 'mcp-servers', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpAgentChat.nonce
                },
                body: JSON.stringify({ name: name, transport: transport, endpoint: endpoint, auth_type: authType, credentials: credentials, command: command })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var msg = '&#10003; Added "' + escHtml(data.server.name) + '" with ' + escHtml(data.server.tools) + ' tools.';
                    if (data.discover_error) {
                        msg += ' <span style="color:#d97706;">(Discovery warning: ' + escHtml(data.discover_error) + ')</span>';
                    }
                    result.innerHTML = '<span style="color:#16a34a;">' + msg + '</span>';
                    // Reload after short delay to show the server in the table.
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    result.innerHTML = '<span style="color:#dc2626;">&#10007; ' + escHtml(data.error || 'Failed to add server.') + '</span>';
                }
            })
            .catch(function() {
                result.innerHTML = '<span style="color:#dc2626;">&#10007; Request failed.</span>';
            })
            .finally(function() {
                mcpAddBtn.disabled = false;
            });
        });
    }

    // MCP Servers — discover tools.
    document.querySelectorAll('.wp-agent-mcp-discover').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var row = this.closest('tr');
            var countCell = row.querySelector('.wp-agent-mcp-tool-count');
            btn.disabled = true;
            btn.textContent = 'Discovering...';

            fetch(wpAgentChat.restUrl + 'mcp-servers/' + id + '/discover', {
                method: 'POST',
                headers: { 'X-WP-Nonce': wpAgentChat.nonce }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    countCell.innerHTML = '<span class="wp-agent-badge wp-agent-badge--channel">' + escHtml(data.tools) + ' tools</span>';
                    btn.textContent = 'Rediscover';
                } else {
                    btn.textContent = 'Failed';
                    setTimeout(function() { btn.textContent = 'Rediscover'; }, 2000);
                }
            })
            .catch(function() {
                btn.textContent = 'Error';
                setTimeout(function() { btn.textContent = 'Rediscover'; }, 2000);
            })
            .finally(function() {
                btn.disabled = false;
            });
        });
    });

    // MCP Servers — remove server.
    document.querySelectorAll('.wp-agent-mcp-remove').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Remove this MCP server and all its tools?')) return;

            var id = this.dataset.id;
            var row = this.closest('tr');
            btn.disabled = true;

            fetch(wpAgentChat.restUrl + 'mcp-servers/' + id, {
                method: 'DELETE',
                headers: { 'X-WP-Nonce': wpAgentChat.nonce }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    row.remove();
                }
            })
            .finally(function() {
                btn.disabled = false;
            });
        });
    });
})();

// Run after DOMContentLoaded so wpAgentChat (localized in the footer) is defined.
document.addEventListener('DOMContentLoaded', function(){
    var modelSelect = document.getElementById('wp_agent_meowl_model');
    var refreshBtn  = document.getElementById('wp_agent_refresh_models');
    var statusEl    = document.getElementById('wp_agent_models_status');
    if (!modelSelect) return;
    function loadModels(){
        var cfg = window.wpAgentChat || {};
        if (!cfg.restUrl) { if (statusEl) statusEl.textContent = 'Could not reach the REST API — reload the page and try again.'; return; }
        if (statusEl) statusEl.textContent = 'Loading models…';
        fetch(cfg.restUrl + 'models', { headers: { 'X-WP-Nonce': cfg.nonce || '' } })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.success) { if (statusEl) statusEl.textContent = (d.error || 'Failed to load models') + ' — check your API key.'; return; }
                var current = modelSelect.getAttribute('data-current') || '';
                modelSelect.innerHTML = '';
                (d.models || []).forEach(function(id){
                    var o = document.createElement('option');
                    o.value = id; o.textContent = id;
                    if (id === current) o.selected = true;
                    modelSelect.appendChild(o);
                });
                if (statusEl) statusEl.textContent = (d.models || []).length + ' models available';
            })
            .catch(function(){ if (statusEl) statusEl.textContent = 'Failed to load models'; });
    }
    if (refreshBtn) refreshBtn.addEventListener('click', loadModels);
    if (modelSelect.getAttribute('data-has-key') === '1') loadModels();
});
</script>
