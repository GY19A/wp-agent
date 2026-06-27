<?php
/**
 * WP Agent channel pairing and webhook signature checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/channel-pairing-security.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This channel pairing security script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_channel_pairing_summary'] = array(
    'pairing'           => 0,
    'pairing_update'    => 0,
    'audit'             => 0,
    'webhook_signature' => 0,
);
$GLOBALS['wp_agent_channel_pairing_codes'] = array();
$GLOBALS['wp_agent_channel_pairing_temp_user_id'] = 0;
$GLOBALS['wp_agent_channel_pairing_fixture_user'] = 'wp-agent-channel-user-' . wp_generate_uuid4();
$GLOBALS['wp_agent_channel_pairing_fixture_chats'] = array(
    'chat-a-' . wp_generate_uuid4(),
    'chat-b-' . wp_generate_uuid4(),
);
$GLOBALS['wp_agent_channel_pairing_option_sentinel'] = '__wp_agent_channel_pairing_missing__';
$GLOBALS['wp_agent_channel_pairing_options'] = array();

foreach ( array( 'telegram_webhook_secret', 'slack_signing_secret', 'discord_public_key' ) as $option_key ) {
    $GLOBALS['wp_agent_channel_pairing_options'][ $option_key ] = get_option(
        'wp_agent_' . $option_key,
        $GLOBALS['wp_agent_channel_pairing_option_sentinel']
    );
}

function wp_agent_channel_pairing_cleanup() {
    global $wpdb;

    foreach ( $GLOBALS['wp_agent_channel_pairing_codes'] as $code ) {
        delete_transient( 'wp_agent_pair_' . wp_hash( $code ) );
    }

    $wpdb->delete(
        $wpdb->prefix . 'wp_agent_pairings',
        array(
            'channel'         => 'telegram',
            'channel_user_id' => $GLOBALS['wp_agent_channel_pairing_fixture_user'],
        ),
        array( '%s', '%s' )
    );

    $like_user = '%' . $wpdb->esc_like( $GLOBALS['wp_agent_channel_pairing_fixture_user'] ) . '%';
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}wp_agent_audit_log WHERE action IN ('channel_paired', 'channel_unpaired') AND details LIKE %s",
        $like_user
    ) );

    if ( ! empty( $GLOBALS['wp_agent_channel_pairing_temp_user_id'] ) ) {
        wp_delete_user( (int) $GLOBALS['wp_agent_channel_pairing_temp_user_id'] );
    }

    foreach ( $GLOBALS['wp_agent_channel_pairing_options'] as $option_key => $value ) {
        if ( $GLOBALS['wp_agent_channel_pairing_option_sentinel'] === $value ) {
            delete_option( 'wp_agent_' . $option_key );
        } else {
            update_option( 'wp_agent_' . $option_key, $value );
        }
    }
}

function wp_agent_channel_pairing_fail( $message ) {
    wp_agent_channel_pairing_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_channel_pairing_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_channel_pairing_fail( $message );
    }
}

function wp_agent_channel_pairing_primary_user_id() {
    $admin = get_user_by( 'login', 'admin' );
    if ( $admin ) {
        return (int) $admin->ID;
    }

    wp_agent_channel_pairing_assert( get_userdata( 1 ), 'Primary fixture user should exist.' );
    return 1;
}

function wp_agent_channel_pairing_create_temp_user() {
    $login = 'wpagent_channel_' . strtolower( wp_generate_password( 8, false, false ) );
    $email = $login . '@example.test';
    $user_id = wp_insert_user( array(
        'user_login' => $login,
        'user_pass'  => wp_generate_password( 24, true, true ),
        'user_email' => $email,
        'role'       => 'subscriber',
    ) );

    wp_agent_channel_pairing_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Temporary pairing user should be created.' );
    $GLOBALS['wp_agent_channel_pairing_temp_user_id'] = (int) $user_id;
    return (int) $user_id;
}

function wp_agent_channel_pairing_has_pairing( array $pairings, $channel, $channel_user_id, $channel_chat_id ) {
    foreach ( $pairings as $pairing ) {
        if (
            $channel === ( $pairing['channel'] ?? '' )
            && $channel_user_id === ( $pairing['channel_user_id'] ?? '' )
            && $channel_chat_id === ( $pairing['channel_chat_id'] ?? '' )
        ) {
            return true;
        }
    }
    return false;
}

function wp_agent_channel_pairing_audit_count( $action ) {
    global $wpdb;

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_audit_log WHERE action = %s AND details LIKE %s",
        $action,
        '%' . $wpdb->esc_like( $GLOBALS['wp_agent_channel_pairing_fixture_user'] ) . '%'
    ) );
}

register_shutdown_function( 'wp_agent_channel_pairing_cleanup' );
wp_agent_channel_pairing_cleanup();

$permissions = new WPAgent_Permissions();
$primary_user_id = wp_agent_channel_pairing_primary_user_id();
$temp_user_id = wp_agent_channel_pairing_create_temp_user();

$code = $permissions->generate_pair_code(
    'telegram',
    $GLOBALS['wp_agent_channel_pairing_fixture_user'],
    $GLOBALS['wp_agent_channel_pairing_fixture_chats'][0]
);
$GLOBALS['wp_agent_channel_pairing_codes'][] = $code;
wp_agent_channel_pairing_assert( 1 === preg_match( '/^\d{6}$/', $code ), 'Generated pairing code should be six digits.' );
$GLOBALS['wp_agent_channel_pairing_summary']['pairing']++;

$paired = $permissions->complete_pairing( $primary_user_id, $code );
wp_agent_channel_pairing_assert( is_array( $paired ) && 'telegram' === ( $paired['channel'] ?? '' ), 'Pairing code should complete.' );
wp_agent_channel_pairing_assert( $primary_user_id === $permissions->get_user_by_channel( 'telegram', $GLOBALS['wp_agent_channel_pairing_fixture_user'] ), 'Channel lookup should return the primary user.' );
wp_agent_channel_pairing_assert(
    wp_agent_channel_pairing_has_pairing(
        $permissions->get_user_pairings( $primary_user_id ),
        'telegram',
        $GLOBALS['wp_agent_channel_pairing_fixture_user'],
        $GLOBALS['wp_agent_channel_pairing_fixture_chats'][0]
    ),
    'Primary user pairings should include the fixture channel.'
);
$GLOBALS['wp_agent_channel_pairing_summary']['pairing'] += 3;

$reuse = $permissions->complete_pairing( $primary_user_id, $code );
wp_agent_channel_pairing_assert( is_wp_error( $reuse ) && 'invalid_code' === $reuse->get_error_code(), 'Pairing code reuse should fail closed.' );
$invalid = $permissions->complete_pairing( $primary_user_id, 'abc1234' );
wp_agent_channel_pairing_assert( is_wp_error( $invalid ) && 'invalid_code' === $invalid->get_error_code(), 'Invalid pairing code format should fail closed.' );
$GLOBALS['wp_agent_channel_pairing_summary']['pairing'] += 2;

$code = $permissions->generate_pair_code(
    'telegram',
    $GLOBALS['wp_agent_channel_pairing_fixture_user'],
    $GLOBALS['wp_agent_channel_pairing_fixture_chats'][1]
);
$GLOBALS['wp_agent_channel_pairing_codes'][] = $code;
$updated = $permissions->complete_pairing( $temp_user_id, $code );
wp_agent_channel_pairing_assert( is_array( $updated ), 'Existing channel user should be re-pairable.' );
wp_agent_channel_pairing_assert( $temp_user_id === $permissions->get_user_by_channel( 'telegram', $GLOBALS['wp_agent_channel_pairing_fixture_user'] ), 'Re-pairing should update the owning WordPress user.' );
wp_agent_channel_pairing_assert(
    wp_agent_channel_pairing_has_pairing(
        $permissions->get_user_pairings( $temp_user_id ),
        'telegram',
        $GLOBALS['wp_agent_channel_pairing_fixture_user'],
        $GLOBALS['wp_agent_channel_pairing_fixture_chats'][1]
    ),
    'Re-pairing should update the stored chat id.'
);
$GLOBALS['wp_agent_channel_pairing_summary']['pairing_update'] += 3;

wp_agent_channel_pairing_assert( wp_agent_channel_pairing_audit_count( 'channel_paired' ) >= 2, 'Pairing audit rows should be written.' );
$GLOBALS['wp_agent_channel_pairing_summary']['audit']++;

$removed = $permissions->remove_pairing( $temp_user_id, 'telegram', $GLOBALS['wp_agent_channel_pairing_fixture_user'] );
wp_agent_channel_pairing_assert( true === $removed, 'Pairing removal should delete the row.' );
wp_agent_channel_pairing_assert( null === $permissions->get_user_by_channel( 'telegram', $GLOBALS['wp_agent_channel_pairing_fixture_user'] ), 'Channel lookup should be empty after removal.' );
wp_agent_channel_pairing_assert( wp_agent_channel_pairing_audit_count( 'channel_unpaired' ) >= 1, 'Unpair audit row should be written.' );
$GLOBALS['wp_agent_channel_pairing_summary']['pairing'] += 2;
$GLOBALS['wp_agent_channel_pairing_summary']['audit']++;

WPAgent::update_option( 'telegram_webhook_secret', 'fixture-telegram-secret' );
wp_agent_channel_pairing_assert( true === $permissions->verify_webhook_signature( 'telegram', '{}', 'fixture-telegram-secret' ), 'Telegram valid secret should verify.' );
wp_agent_channel_pairing_assert( false === $permissions->verify_webhook_signature( 'telegram', '{}', 'wrong-secret' ), 'Telegram wrong secret should fail.' );
WPAgent::update_option( 'telegram_webhook_secret', '' );
wp_agent_channel_pairing_assert( false === $permissions->verify_webhook_signature( 'telegram', '{}', 'fixture-telegram-secret' ), 'Telegram missing configured secret should fail.' );
$GLOBALS['wp_agent_channel_pairing_summary']['webhook_signature'] += 3;

$slack_secret = 'wp-agent-slack-signing-fixture';
$slack_body = '{"type":"event_callback","event_id":"EvFixture"}';
$slack_base = 'v0:1710000000:' . $slack_body;
$slack_signature = 'v0=' . hash_hmac( 'sha256', $slack_base, $slack_secret );
WPAgent::update_option( 'slack_signing_secret', WPAgent::encrypt( $slack_secret ) );
wp_agent_channel_pairing_assert( true === $permissions->verify_webhook_signature( 'slack', $slack_base, $slack_signature ), 'Slack encrypted signing secret should verify.' );
wp_agent_channel_pairing_assert( false === $permissions->verify_webhook_signature( 'slack', $slack_base, 'v0=' . str_repeat( '0', 64 ) ), 'Slack wrong signature should fail.' );
WPAgent::update_option( 'slack_signing_secret', $slack_secret );
wp_agent_channel_pairing_assert( true === $permissions->verify_webhook_signature( 'slack', $slack_base, $slack_signature ), 'Slack raw legacy signing secret should verify.' );
$GLOBALS['wp_agent_channel_pairing_summary']['webhook_signature'] += 3;

if ( function_exists( 'sodium_crypto_sign_keypair' ) && function_exists( 'sodium_crypto_sign_detached' ) ) {
    $keypair = sodium_crypto_sign_keypair();
    $public_key = sodium_crypto_sign_publickey( $keypair );
    $secret_key = sodium_crypto_sign_secretkey( $keypair );
    $discord_payload = '1710000000' . '{"type":1}';
    $discord_signature = bin2hex( sodium_crypto_sign_detached( $discord_payload, $secret_key ) );
    WPAgent::update_option( 'discord_public_key', bin2hex( $public_key ) );

    wp_agent_channel_pairing_assert( true === $permissions->verify_webhook_signature( 'discord', $discord_payload, $discord_signature ), 'Discord valid Ed25519 signature should verify.' );
    wp_agent_channel_pairing_assert( false === $permissions->verify_webhook_signature( 'discord', $discord_payload, str_repeat( '0', 128 ) ), 'Discord wrong signature should fail.' );
    wp_agent_channel_pairing_assert( false === $permissions->verify_webhook_signature( 'discord', $discord_payload, 'not-hex' ), 'Discord malformed signature should fail closed.' );
    WPAgent::update_option( 'discord_public_key', 'not-hex' );
    wp_agent_channel_pairing_assert( false === $permissions->verify_webhook_signature( 'discord', $discord_payload, $discord_signature ), 'Discord malformed public key should fail closed.' );
    $GLOBALS['wp_agent_channel_pairing_summary']['webhook_signature'] += 4;
}

wp_agent_channel_pairing_assert( false === $permissions->verify_webhook_signature( 'unknown', '{}', 'signature' ), 'Unknown channel signature should fail.' );
$GLOBALS['wp_agent_channel_pairing_summary']['webhook_signature']++;

echo wp_json_encode( array(
    'success' => true,
    'summary' => $GLOBALS['wp_agent_channel_pairing_summary'],
    'sodium'  => function_exists( 'sodium_crypto_sign_verify_detached' ),
) ) . "\n";
