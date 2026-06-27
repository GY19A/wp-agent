<?php
/**
 * Settings tool — read and configure WP Agent's own plugin settings.
 *
 * Lets the agent inspect and update the plugin's configuration (channel
 * tokens, model, and budget). Secret values are write-only and are
 * never echoed back to the AI — get/list only report whether a secret is set.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Settings extends WPAgent_Tool {

    /**
     * Allowlist of settable option keys (without the wp_agent_ prefix).
     *
     * Each entry maps the option key to whether it holds a secret. Secret
     * values are encrypted on write and masked on read. Keys NOT present here
    * cannot be set through this tool (notably the AI API key, which is too
     * sensitive and must be set in the admin UI).
     *
     * @var array<string,bool>
     */
    private static $allowlist = array(
        'telegram_bot_token'    => true,
        'slack_bot_token'       => true,
        'slack_signing_secret'  => true,
        'discord_bot_token'     => true,
        'discord_application_id' => false,
        'discord_public_key'    => false,
        'meowl_model'           => false,
        'monthly_budget'        => false,
    );

    public function get_name() {
        return 'manage_wp_agent_settings';
    }

    public function get_description() {
        return "Read and update WP Agent's own configuration (channel tokens, model, and budget). Secrets are write-only and never echoed back.";
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'list', 'get', 'set' ),
                    'description' => 'The operation to perform: list all settable settings, get one setting, or set a setting.',
                ),
                'key' => array(
                    'type'        => 'string',
                    'description' => 'The setting key to get or set (required for get/set). One of: ' . implode( ', ', array_keys( self::$allowlist ) ) . '.',
                ),
                'value' => array(
                    'type'        => 'string',
                    'description' => 'The new value (required for set). For booleans use true/false; for budget use a whole number.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'manage_options';
    }

    public function execute( array $params ) {
        // Re-check capability against the acting user (not get_current_user_id).
        if ( ! user_can( $this->user_id, 'manage_options' ) ) {
            return array( 'error' => 'You do not have permission to manage WP Agent settings.' );
        }

        $action = $params['action'] ?? '';

        switch ( $action ) {
            case 'list':
                return $this->list_settings();
            case 'get':
                return $this->get_setting( $params );
            case 'set':
                return $this->set_setting( $params );
            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    /**
     * List all settable settings with their current (masked) values.
     */
    private function list_settings() {
        $settings = array();

        foreach ( self::$allowlist as $key => $is_secret ) {
            $settings[] = array(
                'key'    => $key,
                'secret' => $is_secret,
                'value'  => $this->display_value( $key, $is_secret ),
            );
        }

        return array(
            'success'  => true,
            'settings' => $settings,
        );
    }

    /**
     * Get a single setting's (masked) value.
     */
    private function get_setting( $params ) {
        $key = sanitize_key( $params['key'] ?? '' );

        if ( '' === $key ) {
            return array( 'error' => 'key is required for get action.' );
        }

        if ( ! isset( self::$allowlist[ $key ] ) ) {
            return array( 'error' => 'Unknown or non-readable setting key: ' . $key );
        }

        $is_secret = self::$allowlist[ $key ];

        return array(
            'success' => true,
            'key'     => $key,
            'secret'  => $is_secret,
            'value'   => $this->display_value( $key, $is_secret ),
        );
    }

    /**
     * Set a single setting.
     */
    private function set_setting( $params ) {
        $key = sanitize_key( $params['key'] ?? '' );

        if ( '' === $key ) {
            return array( 'error' => 'key is required for set action.' );
        }

        if ( ! isset( self::$allowlist[ $key ] ) ) {
            return array( 'error' => 'Setting "' . $key . '" cannot be set through this tool.' );
        }

        if ( ! array_key_exists( 'value', $params ) ) {
            return array( 'error' => 'value is required for set action.' );
        }

        $value     = is_scalar( $params['value'] ) ? (string) $params['value'] : '';
        $is_secret = self::$allowlist[ $key ];

        if ( $is_secret ) {
            // Encrypt secrets before storing; never echo the value back.
            WPAgent::update_option( $key, WPAgent::encrypt( $value ) );

            return array(
                'success' => true,
                'key'     => $key,
                'message' => sprintf( 'Setting "%s" updated. (Secret value stored and hidden.)', $key ),
                'value'   => '' === $value ? 'not set' : 'set',
            );
        }

        // Plain values: cast per key.
        $stored = $this->cast_plain_value( $key, $value );
        WPAgent::update_option( $key, $stored );

        return array(
            'success' => true,
            'key'     => $key,
            'message' => sprintf( 'Setting "%s" updated.', $key ),
            'value'   => $stored,
        );
    }

    /**
     * Cast a plain (non-secret) value to its storage type.
     *
     * @param string $key   Option key.
     * @param string $value Raw incoming value.
     * @return mixed Casted value ready for storage.
     */
    private function cast_plain_value( $key, $value ) {
        switch ( $key ) {
            case 'monthly_budget':
                return absint( $value );
            default:
                return sanitize_text_field( $value );
        }
    }

    /**
     * Build the display value for a setting, masking secrets.
     *
     * @param string $key       Option key.
     * @param bool   $is_secret Whether the option holds a secret.
     * @return mixed Masked or plain value for output.
     */
    private function display_value( $key, $is_secret ) {
        if ( $is_secret ) {
            $raw = WPAgent::get_option( $key );
            return empty( $raw ) ? 'not set' : 'set';
        }

        switch ( $key ) {
            case 'monthly_budget':
                return (int) WPAgent::get_option( $key, 0 );
            default:
                return (string) WPAgent::get_option( $key, '' );
        }
    }
}
