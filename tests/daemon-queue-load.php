<?php
/**
 * WP Agent daemon queue-load checks with a local fake AI response.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/daemon-queue-load.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This daemon queue-load script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_daemon_queue_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_daemon_queue_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_daemon_queue_fail( $message );
    }
}

global $wpdb;

$previous = array(
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$restored = false;
$restore  = function() use ( &$restored, $previous ) {
    if ( $restored ) {
        return;
    }
    WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
    WPAgent::update_option( 'meowl_model', $previous['model'] );
    if ( $previous['budget_exists'] ) {
        update_option( 'wp_agent_monthly_budget', $previous['monthly_budget'] );
    } else {
        delete_option( 'wp_agent_monthly_budget' );
    }
    $restored = true;
};
register_shutdown_function( $restore );

WPAgent_Daemon::request_stop();
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-test-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-test-model' );
WPAgent::update_option( 'monthly_budget', 0 );

add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) {
        if ( false === strpos( (string) $url, '/chat/completions' ) ) {
            return $preempt;
        }

        $request = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
        $messages = is_array( $request['messages'] ?? null ) ? $request['messages'] : array();
        $last_user = '';
        foreach ( array_reverse( $messages ) as $message ) {
            if ( 'user' === ( $message['role'] ?? '' ) ) {
                $last_user = (string) ( $message['content'] ?? '' );
                break;
            }
        }

        return array(
            'headers'  => array(),
            'body'     => wp_json_encode( array(
                'id'      => 'chatcmpl-wp-agent-queue-load',
                'object'  => 'chat.completion',
                'created' => time(),
                'model'   => 'wp-agent-test-model',
                'choices' => array(
                    array(
                        'index'         => 0,
                        'message'       => array(
                            'role'    => 'assistant',
                            'content' => 'Queue load completed: ' . substr( hash( 'sha256', $last_user ), 0, 12 ),
                        ),
                        'finish_reason' => 'stop',
                    ),
                ),
                'usage'   => array(
                    'prompt_tokens'     => 12,
                    'completion_tokens' => 7,
                    'total_tokens'      => 19,
                ),
            ) ),
            'response' => array(
                'code'    => 200,
                'message' => 'OK',
            ),
            'cookies'  => array(),
        );
    },
    10,
    3
);

$conversation = new WPAgent_Conversation();
$run_ids      = array();
$count        = 3;

$usage_before = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    'wp-agent-test-model'
) );

for ( $i = 1; $i <= $count; $i++ ) {
    $conversation_id = $conversation->get_or_create( 1, 'wpcli', 'daemon-queue-load-' . wp_generate_uuid4() . '-' . $i );
    wp_agent_daemon_queue_assert( $conversation_id > 0, 'Conversation should be created.' );

    $message_id = $conversation->add_message( $conversation_id, 'user', 'Daemon queue load fixture #' . $i );
    wp_agent_daemon_queue_assert( $message_id > 0, 'User message should be created.' );

    $run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
    wp_agent_daemon_queue_assert( $run_id > 0, 'Run should be queued.' );
    $run_ids[] = $run_id;
}

$logs       = array();
$started_at = microtime( true );
$result     = WPAgent_Daemon::run(
    array(
        'max_children'      => 2,
        'idle_sleep'        => 0,
        'max_jobs'          => $count,
        'max_lifetime'      => 15,
        'max_idle_time'     => 5,
        'memory_soft_limit' => 512,
        'memory_hard_limit' => 768,
    ),
    function( $line ) use ( &$logs ) {
        if ( count( $logs ) < 30 ) {
            $logs[] = (string) $line;
        }
    }
);
$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

wp_agent_daemon_queue_assert( ! empty( $result['ok'] ), 'Daemon foreground run should succeed.' );
wp_agent_daemon_queue_assert( $count === (int) ( $result['processed_jobs'] ?? -1 ), 'Daemon should process the queued run count.' );
wp_agent_daemon_queue_assert( 'max_jobs' === ( $result['restart_reason'] ?? '' ), 'Daemon should stop at the max-jobs rotation boundary.' );

$done_count = 0;
foreach ( $run_ids as $run_id ) {
    $run = WPAgent_Runs::get( $run_id );
    wp_agent_daemon_queue_assert( $run && 'done' === $run->status, 'Each queued run should complete.' );
    wp_agent_daemon_queue_assert( empty( $run->locked_until ), 'Completed runs should not retain locks.' );

    $messages = $conversation->get_messages_for_display( (int) $run->conversation_id, 0, 20 );
    $assistant_messages = array_filter(
        $messages,
        function( $message ) {
            return 'assistant' === ( $message['role'] ?? '' )
                && false !== strpos( (string) ( $message['content'] ?? '' ), 'Queue load completed:' );
        }
    );
    wp_agent_daemon_queue_assert( ! empty( $assistant_messages ), 'Each run should store a fake assistant completion.' );

    $events = WPAgent_Run_Events::recent( $run_id, 20 );
    $event_types = array_map(
        function( $event ) {
            return $event['event_type'] ?? '';
        },
        $events
    );
    wp_agent_daemon_queue_assert( in_array( 'claimed', $event_types, true ), 'Each run should record a claimed event.' );
    wp_agent_daemon_queue_assert( in_array( 'done', $event_types, true ), 'Each run should record a done event.' );
    $done_count++;
}

$usage_after = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    'wp-agent-test-model'
) );
wp_agent_daemon_queue_assert( $usage_after >= $usage_before + $count, 'Each processed run should record model usage.' );

$status = WPAgent_Daemon::status();
wp_agent_daemon_queue_assert( empty( $status['running'] ), 'Daemon should stop after the foreground queue-load run.' );
wp_agent_daemon_queue_assert( 'stopped' === ( $status['status'] ?? '' ), 'Daemon status should be stopped.' );
wp_agent_daemon_queue_assert( $count === (int) ( $status['processed_jobs'] ?? -1 ), 'Daemon status should retain processed job count.' );
wp_agent_daemon_queue_assert( 'max_jobs' === ( $status['restart_reason'] ?? '' ), 'Daemon status should retain max-jobs restart reason.' );
wp_agent_daemon_queue_assert( (int) ( $status['memory_baseline'] ?? 0 ) > 0, 'Daemon should report memory baseline.' );
wp_agent_daemon_queue_assert( (int) ( $status['memory_usage'] ?? 0 ) > 0, 'Daemon should report memory usage.' );
wp_agent_daemon_queue_assert( (int) ( $status['memory_delta'] ?? -1 ) >= 0, 'Daemon should report memory delta.' );
wp_agent_daemon_queue_assert( (int) ( $status['memory_per_job_delta'] ?? -1 ) >= 0, 'Daemon should report per-job memory delta.' );
wp_agent_daemon_queue_assert( 0 === WPAgent_Runs::claimable_count(), 'No created runs should remain claimable after completion.' );

$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_daemon_queue_assert( $count === (int) ( $diagnostics['daemon']['processed_jobs'] ?? -1 ), 'Diagnostics should expose processed jobs.' );
wp_agent_daemon_queue_assert( 'max_jobs' === ( $diagnostics['daemon']['restart_reason'] ?? '' ), 'Diagnostics should expose max-jobs restart reason.' );
wp_agent_daemon_queue_assert( (int) ( $diagnostics['queue']['claimable_count'] ?? -1 ) >= 0, 'Diagnostics should expose claimable count.' );

$restore();

echo wp_json_encode( array(
    'success'       => true,
    'run_ids'       => $run_ids,
    'done_count'    => $done_count,
    'processed_jobs' => (int) $status['processed_jobs'],
    'restart_reason' => $status['restart_reason'],
    'elapsed_ms'    => $elapsed_ms,
    'pcntl'         => ! empty( $status['pcntl'] ),
    'memory'        => array(
        'baseline'      => (int) $status['memory_baseline'],
        'usage'         => (int) $status['memory_usage'],
        'delta'         => (int) $status['memory_delta'],
        'per_job_delta' => (int) $status['memory_per_job_delta'],
    ),
    'logs'          => $logs,
) ) . "\n";
