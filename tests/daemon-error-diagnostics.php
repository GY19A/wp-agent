<?php
/**
 * WP Agent daemon error diagnostics checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/daemon-error-diagnostics.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This daemon error diagnostics script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_daemon_error_previous'] = array(
    'base_url'       => get_option( 'wp_agent_ai_base_url', null ),
    'base_exists'    => false !== get_option( 'wp_agent_ai_base_url', false ),
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'mode'           => get_option( 'wp_agent_mode', 'author' ),
    'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$GLOBALS['wp_agent_daemon_error_filter']          = null;
$GLOBALS['wp_agent_daemon_error_conversation_id'] = 0;
$GLOBALS['wp_agent_daemon_error_run_id']          = 0;

function wp_agent_daemon_error_cleanup() {
    global $wpdb;

    if ( null !== $GLOBALS['wp_agent_daemon_error_filter'] ) {
        remove_filter( 'pre_http_request', $GLOBALS['wp_agent_daemon_error_filter'], 10 );
        $GLOBALS['wp_agent_daemon_error_filter'] = null;
    }

    $run_id          = (int) $GLOBALS['wp_agent_daemon_error_run_id'];
    $conversation_id = (int) $GLOBALS['wp_agent_daemon_error_conversation_id'];

    if ( $run_id > 0 ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => $run_id ), array( '%d' ) );
    }

    if ( $conversation_id > 0 ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => $conversation_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => $conversation_id ), array( '%d' ) );
    }

    $previous = $GLOBALS['wp_agent_daemon_error_previous'];
    if ( $previous['base_exists'] ) {
        update_option( 'wp_agent_ai_base_url', $previous['base_url'] );
    } else {
        delete_option( 'wp_agent_ai_base_url' );
    }
    WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
    WPAgent::update_option( 'meowl_model', $previous['model'] );
    update_option( 'wp_agent_mode', $previous['mode'] );
    if ( $previous['budget_exists'] ) {
        update_option( 'wp_agent_monthly_budget', $previous['monthly_budget'] );
    } else {
        delete_option( 'wp_agent_monthly_budget' );
    }
}

function wp_agent_daemon_error_fail( $message ) {
    wp_agent_daemon_error_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_daemon_error_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_daemon_error_fail( $message );
    }
}

register_shutdown_function( 'wp_agent_daemon_error_cleanup' );

WPAgent_Daemon::request_stop();
update_option( 'wp_agent_ai_base_url', 'https://daemon-error.example.test/v1' );
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-daemon-error-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-daemon-error-model' );
WPAgent::update_option( 'monthly_budget', 0 );
update_option( 'wp_agent_mode', 'administrator' );

$GLOBALS['wp_agent_daemon_error_filter'] = function( $preempt, $parsed_args, $url ) {
    if ( 0 !== strpos( (string) $url, 'https://daemon-error.example.test/v1/chat/completions' ) ) {
        return $preempt;
    }

    return array(
        'headers'  => array(),
        'response' => array(
            'code'    => 500,
            'message' => 'Internal Server Error',
        ),
        'body'     => wp_json_encode( array(
            'error' => array(
                'message' => 'Fixture daemon failure api_key=super-secret token: hidden-value Bearer abcdef1234567890 ' . str_repeat( 'x', 400 ),
            ),
        ) ),
        'cookies'  => array(),
    );
};
add_filter( 'pre_http_request', $GLOBALS['wp_agent_daemon_error_filter'], 10, 3 );

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( 1, 'wpcli', 'daemon-error-diagnostics-' . wp_generate_uuid4() );
wp_agent_daemon_error_assert( $conversation_id > 0, 'Conversation should be created.' );
$GLOBALS['wp_agent_daemon_error_conversation_id'] = $conversation_id;

$message_id = $conversation->add_message( $conversation_id, 'user', 'Trigger daemon error diagnostics with a deterministic provider failure.' );
wp_agent_daemon_error_assert( $message_id > 0, 'Message should be created.' );

$run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
wp_agent_daemon_error_assert( $run_id > 0, 'Run should be queued.' );
$GLOBALS['wp_agent_daemon_error_run_id'] = $run_id;

$result = WPAgent_Daemon::run( array(
    'max_children'      => 1,
    'idle_sleep'        => 0,
    'max_jobs'          => 1,
    'max_lifetime'      => 20,
    'max_idle_time'     => 5,
    'memory_soft_limit' => 512,
    'memory_hard_limit' => 768,
) );

wp_agent_daemon_error_assert( ! empty( $result['ok'] ), 'Daemon run should complete.' );
wp_agent_daemon_error_assert( 1 === (int) ( $result['processed_jobs'] ?? 0 ), 'Daemon should process exactly one failed job.' );

$run = WPAgent_Runs::get( $run_id );
wp_agent_daemon_error_assert( $run && 'error' === (string) $run->status, 'Run should fail deterministically.' );
wp_agent_daemon_error_assert( false !== strpos( (string) $run->error, 'Fixture daemon failure' ), 'Run error should retain provider failure context.' );

$status = WPAgent_Daemon::status();
wp_agent_daemon_error_assert( false !== strpos( (string) ( $status['last_error'] ?? '' ), 'Fixture daemon failure' ), 'Daemon status should retain last error context.' );
wp_agent_daemon_error_assert( false === strpos( (string) ( $status['last_error'] ?? '' ), 'super-secret' ), 'Daemon status should redact api_key values.' );
wp_agent_daemon_error_assert( false === strpos( (string) ( $status['last_error'] ?? '' ), 'hidden-value' ), 'Daemon status should redact token values.' );
wp_agent_daemon_error_assert( false === strpos( (string) ( $status['last_error'] ?? '' ), 'abcdef1234567890' ), 'Daemon status should redact bearer tokens.' );
wp_agent_daemon_error_assert( strlen( (string) ( $status['last_error'] ?? '' ) ) <= 300, 'Daemon status last_error should be bounded.' );
wp_agent_daemon_error_assert( '' !== (string) ( $status['last_error_at'] ?? '' ), 'Daemon status should include last_error_at.' );

$diagnostics = WPAgent_Diagnostics::runtime();
$daemon      = $diagnostics['daemon'] ?? array();
wp_agent_daemon_error_assert( array_key_exists( 'restart_count', $daemon ), 'Diagnostics should expose restart_count.' );
wp_agent_daemon_error_assert( array_key_exists( 'watchdog_restart_count', $daemon ), 'Diagnostics should expose watchdog_restart_count.' );
wp_agent_daemon_error_assert( array_key_exists( 'watchdog_consecutive_failures', $daemon ), 'Diagnostics should expose watchdog_consecutive_failures.' );
wp_agent_daemon_error_assert( array_key_exists( 'watchdog_last_error', $daemon ), 'Diagnostics should expose watchdog_last_error.' );
wp_agent_daemon_error_assert( (string) ( $daemon['last_error'] ?? '' ) === (string) $status['last_error'], 'Diagnostics should mirror daemon last_error.' );
wp_agent_daemon_error_assert( (string) ( $daemon['last_error_at'] ?? '' ) === (string) $status['last_error_at'], 'Diagnostics should mirror daemon last_error_at.' );

echo wp_json_encode( array(
    'success'              => true,
    'run_id'               => $run_id,
    'restart_reason'       => $result['restart_reason'] ?? '',
    'last_error_length'    => strlen( (string) $status['last_error'] ),
    'restart_count'        => (int) $daemon['restart_count'],
    'watchdog_last_error'  => (string) $daemon['watchdog_last_error'],
    'redaction_verified'   => true,
) ) . "\n";
