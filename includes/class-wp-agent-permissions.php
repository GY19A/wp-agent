<?php
/**
 * Permission and security system.
 *
 * Handles capability checks, channel-to-user pairing,
 * rate limiting, and action confirmation. This is where
 * WP Agent differentiates from OpenClaw's 512-CVE security posture.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Permissions {

    /** @var int Rate limit: max requests per hour per user. */
    const RATE_LIMIT_PER_HOUR = 60;

    /** @var int Pairing code expiration in seconds. */
    const PAIR_CODE_EXPIRY = 300;

    /**
     * Check if a user has permission to execute a tool.
     *
     * @param int    $user_id    WordPress user ID.
     * @param string $capability Required WordPress capability.
     * @return bool
     */
    public function can_execute( $user_id, $capability ) {
        if ( ! $user_id ) {
            return false;
        }
        return user_can( $user_id, $capability );
    }

    /**
     * Check if a tool action requires confirmation.
     *
     * @param string $tool_name
     * @param array  $params    Tool parameters.
     * @return bool
     */
    public function requires_confirmation( $tool_name, $params = array() ) {
        $action = $params['action'] ?? '';

        // Destructive content operations.
        if ( in_array( $tool_name, array( 'manage_posts', 'manage_pages', 'manage_media', 'manage_comments' ), true )
            && in_array( $action, array( 'delete', 'trash' ), true ) ) {
            return true;
        }

        // Taxonomy deletion can restructure the site.
        if ( 'manage_taxonomies' === $tool_name && 'delete' === $action ) {
            return true;
        }

        // Navigation deletion can remove important site structure.
        if ( 'manage_menus' === $tool_name && in_array( $action, array( 'delete_menu', 'delete_item' ), true ) ) {
            return true;
        }

        // Publishing content from draft.
        if ( in_array( $tool_name, array( 'manage_posts', 'manage_pages' ), true )
            && isset( $params['status'] ) && 'publish' === $params['status'] ) {
            return true;
        }

        // User management operations are always sensitive.
        if ( 'manage_users' === $tool_name
            && in_array( $action, array( 'create', 'delete', 'set_role', 'update' ), true ) ) {
            return true;
        }

        // Changing WP Agent settings.
        if ( 'manage_wp_agent_settings' === $tool_name && 'set' === $action ) {
            return true;
        }

        // Arbitrary code execution is high-risk even when a hardened backend exists.
        if ( 'execute_code' === $tool_name && 'run' === $action ) {
            return true;
        }

        // Skill writes and third-party package activation must be explicitly approved.
        if ( 'manage_skills' === $tool_name
            && in_array( $action, array( 'save', 'install_template', 'install_github', 'activate_quarantine', 'refresh_package', 'pin_package', 'unpin_package', 'rollback_package' ), true ) ) {
            return true;
        }

        // Bulk operations with many items.
        if ( isset( $params['count'] ) && (int) $params['count'] > 5 ) {
            return true;
        }

        return false;
    }

    /**
     * Check rate limit for a user.
     *
     * @param int $user_id
     * @return bool True if within limits.
     */
    public function check_rate_limit( $user_id ) {
        $user_id       = (int) $user_id;
        $transient_key = $this->rate_limit_transient_key( $user_id );
        $count         = (int) get_transient( $transient_key );

        if ( $count >= self::RATE_LIMIT_PER_HOUR ) {
            return false;
        }

        set_transient( $transient_key, $count + 1, HOUR_IN_SECONDS );
        return true;
    }

    /**
     * Get remaining rate limit for a user.
     *
     * @param int $user_id
     * @return int
     */
    public function get_remaining_rate( $user_id ) {
        $status = $this->rate_limit_status( $user_id );
        return (int) $status['remaining'];
    }

    /**
     * Read the current rate-limit window without incrementing it.
     *
     * @param int $user_id
     * @return array
     */
    public function rate_limit_status( $user_id ) {
        $user_id = (int) $user_id;
        $count   = $user_id > 0 ? (int) get_transient( $this->rate_limit_transient_key( $user_id ) ) : 0;
        $limit   = self::RATE_LIMIT_PER_HOUR;
        $now     = time();
        $window_start = (int) floor( $now / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS;
        $reset        = $window_start + HOUR_IN_SECONDS;

        return array(
            'user_id'           => $user_id,
            'limit_per_hour'    => $limit,
            'used'              => max( 0, $count ),
            'remaining'         => max( 0, $limit - $count ),
            'limited'           => $count >= $limit,
            'window_started_at' => gmdate( 'Y-m-d H:i:s', $window_start ),
            'reset_at'          => gmdate( 'Y-m-d H:i:s', $reset ),
        );
    }

    /**
     * Build the current-hour transient key for a user.
     *
     * @param int $user_id
     * @return string
     */
    private function rate_limit_transient_key( $user_id ) {
        return 'wp_agent_rl_' . wp_hash( (int) $user_id . '_' . gmdate( 'YmdH' ) );
    }

    // -------------------------------------------------------------------------
    // Channel Pairing
    // -------------------------------------------------------------------------

    /**
     * Generate a one-time pairing code for a channel.
     *
     * @param string $channel         Channel name (telegram, slack, etc.).
     * @param string $channel_user_id Channel-specific user identifier.
     * @param string $channel_chat_id Channel-specific chat identifier.
     * @return string 6-digit pairing code.
     */
    public function generate_pair_code( $channel, $channel_user_id, $channel_chat_id ) {
        // Use cryptographically secure randomness for pairing codes.
        try {
            $code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        } catch ( \Exception $e ) {
            // Fallback if random_int fails (should not happen on PHP 7+).
            $code = str_pad( wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        }

        // Use a hashed transient key to prevent code enumeration.
        $transient_key = 'wp_agent_pair_' . wp_hash( $code );

        set_transient( $transient_key, array(
            'channel'         => sanitize_text_field( $channel ),
            'channel_user_id' => sanitize_text_field( $channel_user_id ),
            'channel_chat_id' => sanitize_text_field( $channel_chat_id ),
            'created'         => time(),
        ), self::PAIR_CODE_EXPIRY );

        return $code;
    }

    /**
     * Complete the pairing process with a code.
     *
     * @param int    $user_id WordPress user ID (from admin).
     * @param string $code    6-digit pairing code.
     * @return array|WP_Error Pairing data on success, WP_Error on failure.
     */
    public function complete_pairing( $user_id, $code ) {
        // Validate code format before lookup.
        $code = sanitize_text_field( $code );
        if ( ! preg_match( '/^\d{6}$/', $code ) ) {
            return new WP_Error( 'invalid_code', __( 'Invalid pairing code format.', 'wp-agent' ) );
        }

        $transient_key = 'wp_agent_pair_' . wp_hash( $code );
        $pair_data     = get_transient( $transient_key );

        if ( ! $pair_data ) {
            return new WP_Error( 'invalid_code', __( 'Invalid or expired pairing code.', 'wp-agent' ) );
        }

        // Remove the transient so the code can't be reused.
        delete_transient( $transient_key );

        global $wpdb;

        // Check if this channel user is already paired.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wp_agent_pairings WHERE channel = %s AND channel_user_id = %s",
            $pair_data['channel'],
            $pair_data['channel_user_id']
        ) );

        if ( $existing ) {
            // Update existing pairing.
            $wpdb->update(
                $wpdb->prefix . 'wp_agent_pairings',
                array(
                    'user_id'         => $user_id,
                    'channel_chat_id' => $pair_data['channel_chat_id'],
                    'paired_at'       => current_time( 'mysql', true ),
                ),
                array( 'id' => $existing ),
                array( '%d', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $wpdb->prefix . 'wp_agent_pairings',
                array(
                    'user_id'         => $user_id,
                    'channel'         => $pair_data['channel'],
                    'channel_user_id' => $pair_data['channel_user_id'],
                    'channel_chat_id' => $pair_data['channel_chat_id'],
                    'paired_at'       => current_time( 'mysql', true ),
                ),
                array( '%d', '%s', '%s', '%s', '%s' )
            );
        }

        WPAgent::audit_log( $user_id, 'channel_paired', array(
            'channel'         => $pair_data['channel'],
            'channel_user_id' => $pair_data['channel_user_id'],
        ), $pair_data['channel'] );

        return $pair_data;
    }

    /**
     * Look up a WordPress user by their channel identity.
     *
     * @param string $channel         Channel name.
     * @param string $channel_user_id Channel-specific user identifier.
     * @return int|null WordPress user ID, or null if not paired.
     */
    public function get_user_by_channel( $channel, $channel_user_id ) {
        global $wpdb;

        $user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}wp_agent_pairings WHERE channel = %s AND channel_user_id = %s",
            $channel,
            $channel_user_id
        ) );

        return $user_id ? (int) $user_id : null;
    }

    /**
     * Get all pairings for a user.
     *
     * @param int $user_id
     * @return array
     */
    public function get_user_pairings( $user_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT channel, channel_user_id, channel_chat_id, paired_at
             FROM {$wpdb->prefix}wp_agent_pairings
             WHERE user_id = %d
             ORDER BY paired_at DESC",
            $user_id
        ), ARRAY_A );
    }

    /**
     * Remove a channel pairing.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $channel_user_id
     * @return bool
     */
    public function remove_pairing( $user_id, $channel, $channel_user_id ) {
        global $wpdb;

        $deleted = $wpdb->delete(
            $wpdb->prefix . 'wp_agent_pairings',
            array(
                'user_id'         => $user_id,
                'channel'         => $channel,
                'channel_user_id' => $channel_user_id,
            ),
            array( '%d', '%s', '%s' )
        );

        if ( $deleted ) {
            WPAgent::audit_log( $user_id, 'channel_unpaired', array(
                'channel'         => $channel,
                'channel_user_id' => $channel_user_id,
            ), $channel );
        }

        return (bool) $deleted;
    }

    /**
     * Verify a webhook request signature.
     *
     * @param string $channel   Channel name.
     * @param string $payload   Raw request body. For Slack pass v0:{timestamp}:{body}; for Discord pass {timestamp}{body}.
     * @param string $signature Signature header value.
     * @return bool
     */
    public function verify_webhook_signature( $channel, $payload, $signature ) {
        switch ( $channel ) {
            case 'telegram':
                // Telegram uses a secret_token set during setWebhook.
                $secret = WPAgent::get_option( 'telegram_webhook_secret' );
                return ! empty( $secret ) && hash_equals( $secret, $signature );

            case 'slack':
                // Slack uses HMAC-SHA256 with signing secret.
                $secret = $this->get_secret_option_plaintext( 'slack_signing_secret' );
                if ( empty( $secret ) ) {
                    return false;
                }
                $computed = 'v0=' . hash_hmac( 'sha256', $payload, $secret );
                return hash_equals( $computed, $signature );

            case 'discord':
                // Discord uses Ed25519 signature verification.
                // Requires sodium extension.
                $public_key = WPAgent::get_option( 'discord_public_key' );
                if ( empty( $public_key ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
                    return false;
                }
                $signature_binary = $this->hex_to_binary( $signature );
                $public_key_binary = $this->hex_to_binary( $public_key );
                if ( false === $signature_binary || false === $public_key_binary || 64 !== strlen( $signature_binary ) || 32 !== strlen( $public_key_binary ) ) {
                    return false;
                }
                try {
                    return sodium_crypto_sign_verify_detached(
                        $signature_binary,
                        $payload,
                        $public_key_binary
                    );
                } catch ( \Throwable $e ) {
                    return false;
                }

            default:
                return false;
        }
    }

    /**
     * Return a stored secret as plaintext, preserving raw legacy values.
     *
     * @param string $key Option key without prefix.
     * @return string
     */
    private function get_secret_option_plaintext( $key ) {
        $stored = WPAgent::get_option( $key );
        if ( empty( $stored ) ) {
            return '';
        }

        $decrypted = WPAgent::decrypt( $stored );
        return '' !== $decrypted ? $decrypted : (string) $stored;
    }

    /**
     * Decode strict hexadecimal input without warnings or type errors.
     *
     * @param string $value Hex-encoded value.
     * @return string|false
     */
    private function hex_to_binary( $value ) {
        $value = preg_replace( '/\s+/', '', (string) $value );
        if ( '' === $value || 0 !== strlen( $value ) % 2 || ! ctype_xdigit( $value ) ) {
            return false;
        }
        return hex2bin( $value );
    }
}
