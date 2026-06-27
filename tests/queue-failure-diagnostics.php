<?php
/**
 * WP Agent queue failure diagnostics checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/queue-failure-diagnostics.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This queue failure diagnostics script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_queue_failure_conversation_id'] = 0;
$GLOBALS['wp_agent_queue_failure_run_id']          = 0;

function wp_agent_queue_failure_cleanup() {
    global $wpdb;

    $run_id          = (int) $GLOBALS['wp_agent_queue_failure_run_id'];
    $conversation_id = (int) $GLOBALS['wp_agent_queue_failure_conversation_id'];

    if ( $run_id > 0 ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => $run_id ), array( '%d' ) );
    }
    if ( $conversation_id > 0 ) {
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => $conversation_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => $conversation_id ), array( '%d' ) );
    }
}

function wp_agent_queue_failure_fail( $message ) {
    wp_agent_queue_failure_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_queue_failure_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_queue_failure_fail( $message );
    }
}

register_shutdown_function( 'wp_agent_queue_failure_cleanup' );

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( 1, 'wpcli', 'queue-failure-diagnostics-' . wp_generate_uuid4() );
wp_agent_queue_failure_assert( $conversation_id > 0, 'Conversation should be created.' );
$GLOBALS['wp_agent_queue_failure_conversation_id'] = $conversation_id;

$message_id = $conversation->add_message( $conversation_id, 'user', 'Queue failure diagnostics acceptance fixture.' );
wp_agent_queue_failure_assert( $message_id > 0, 'Message should be created.' );

$run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
wp_agent_queue_failure_assert( $run_id > 0, 'Run should be created.' );
$GLOBALS['wp_agent_queue_failure_run_id'] = $run_id;

$raw_error = 'Fixture failure for diagnostics api_key=super-secret token: hidden-value Bearer abcdef1234567890 ' . str_repeat( 'x', 400 );
WPAgent_Runs::set_error( $run_id, $raw_error );

$diagnostics = WPAgent_Diagnostics::runtime();
$queue       = $diagnostics['queue'] ?? array();
$failures    = $queue['recent_failures'] ?? array();

wp_agent_queue_failure_assert( (int) ( $queue['counts']['error'] ?? 0 ) >= 1, 'Diagnostics should count errored runs.' );
wp_agent_queue_failure_assert( is_array( $failures ) && ! empty( $failures ), 'Diagnostics should expose recent failures.' );

$fixture = null;
foreach ( $failures as $failure ) {
    if ( (int) ( $failure['id'] ?? 0 ) === (int) $run_id ) {
        $fixture = $failure;
        break;
    }
}

wp_agent_queue_failure_assert( is_array( $fixture ), 'Recent failures should include the fixture run.' );
wp_agent_queue_failure_assert( (int) $fixture['conversation_id'] === (int) $conversation_id, 'Failure should include conversation ID.' );
wp_agent_queue_failure_assert( 1 === (int) $fixture['user_id'], 'Failure should include user ID.' );
wp_agent_queue_failure_assert( 'wpcli' === (string) $fixture['channel'], 'Failure should include channel.' );
wp_agent_queue_failure_assert( 0 === (int) $fixture['loop_count'], 'Failure should include loop count.' );
wp_agent_queue_failure_assert( '' !== (string) $fixture['created_at'], 'Failure should include creation timestamp.' );
wp_agent_queue_failure_assert( '' !== (string) $fixture['updated_at'], 'Failure should include update timestamp.' );
wp_agent_queue_failure_assert( isset( $fixture['age'] ) && (int) $fixture['age'] >= 0, 'Failure should include age.' );
wp_agent_queue_failure_assert( false !== strpos( (string) $fixture['error'], 'Fixture failure for diagnostics' ), 'Failure summary should retain useful context.' );
wp_agent_queue_failure_assert( false === strpos( (string) $fixture['error'], 'super-secret' ), 'Failure summary should redact api_key values.' );
wp_agent_queue_failure_assert( false === strpos( (string) $fixture['error'], 'hidden-value' ), 'Failure summary should redact token values.' );
wp_agent_queue_failure_assert( false === strpos( (string) $fixture['error'], 'abcdef1234567890' ), 'Failure summary should redact bearer tokens.' );
wp_agent_queue_failure_assert( strlen( (string) $fixture['error'] ) <= 300, 'Failure summary should be bounded.' );
wp_agent_queue_failure_assert( (string) $queue['last_failure_at'] === (string) $fixture['updated_at'], 'Latest failure timestamp should match the newest fixture.' );
wp_agent_queue_failure_assert( isset( $queue['last_failure_age'] ) && (int) $queue['last_failure_age'] >= 0, 'Latest failure age should be present.' );
wp_agent_queue_failure_assert( (string) $queue['last_failure_error'] === (string) $fixture['error'], 'Latest failure summary should match the newest fixture.' );

echo wp_json_encode( array(
    'success'            => true,
    'run_id'             => $run_id,
    'recent_failures'    => count( $failures ),
    'last_failure_age'   => (int) $queue['last_failure_age'],
    'summary_length'     => strlen( (string) $fixture['error'] ),
    'redaction_verified' => true,
) ) . "\n";
