<?php
/**
 * WP Agent daemon multi-rotation load checks with local fake AI responses.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/daemon-rotation-load.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This daemon rotation-load script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_daemon_rotation_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_daemon_rotation_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_daemon_rotation_fail( $message );
    }
}

function wp_agent_daemon_rotation_pending_count( $run_ids ) {
    $pending = 0;
    foreach ( $run_ids as $run_id ) {
        $run = WPAgent_Runs::get( $run_id );
        if ( $run && in_array( $run->status, array( 'queued', 'running' ), true ) ) {
            $pending++;
        }
    }
    return $pending;
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

$stop = WPAgent_Daemon::request_stop();
wp_agent_daemon_rotation_assert( ! is_wp_error( $stop ), is_wp_error( $stop ) ? $stop->get_error_message() : 'Initial daemon stop should not fail.' );

WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-test-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-rotation-test-model' );
WPAgent::update_option( 'monthly_budget', 0 );

add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) {
        if ( false === strpos( (string) $url, '/chat/completions' ) ) {
            return $preempt;
        }

        $request   = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
        $messages  = is_array( $request['messages'] ?? null ) ? $request['messages'] : array();
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
                'id'      => 'chatcmpl-wp-agent-rotation-load',
                'object'  => 'chat.completion',
                'created' => time(),
                'model'   => 'wp-agent-rotation-test-model',
                'choices' => array(
                    array(
                        'index'         => 0,
                        'message'       => array(
                            'role'    => 'assistant',
                            'content' => 'Rotation load completed: ' . substr( hash( 'sha256', $last_user ), 0, 12 ),
                        ),
                        'finish_reason' => 'stop',
                    ),
                ),
                'usage'   => array(
                    'prompt_tokens'     => 14,
                    'completion_tokens' => 8,
                    'total_tokens'      => 22,
                ),
            ) ),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'cookies'  => array(),
        );
    },
    10,
    3
);

$conversation = new WPAgent_Conversation();
$run_ids      = array();
$total_runs   = 6;
$max_jobs     = 2;

$usage_before = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    'wp-agent-rotation-test-model'
) );

for ( $i = 1; $i <= $total_runs; $i++ ) {
    $conversation_id = $conversation->get_or_create( 1, 'wpcli', 'daemon-rotation-load-' . wp_generate_uuid4() . '-' . $i );
    wp_agent_daemon_rotation_assert( $conversation_id > 0, 'Conversation should be created.' );

    $message_id = $conversation->add_message( $conversation_id, 'user', 'Daemon rotation load fixture #' . $i );
    wp_agent_daemon_rotation_assert( $message_id > 0, 'User message should be created.' );

    $run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
    wp_agent_daemon_rotation_assert( $run_id > 0, 'Run should be queued.' );
    $run_ids[] = $run_id;
}

$cycle_results = array();
$log_lines     = array();
$started_at    = microtime( true );

for ( $cycle = 1; $cycle <= 3; $cycle++ ) {
    $before_pending = wp_agent_daemon_rotation_pending_count( $run_ids );
    wp_agent_daemon_rotation_assert( $before_pending >= $max_jobs, 'Each rotation cycle should start with enough pending runs.' );

    $logs   = array();
    $result = WPAgent_Daemon::run(
        array(
            'max_children'      => 2,
            'idle_sleep'        => 0,
            'max_jobs'          => $max_jobs,
            'max_lifetime'      => 20,
            'max_idle_time'     => 5,
            'memory_soft_limit' => 512,
            'memory_hard_limit' => 768,
        ),
        function( $line ) use ( &$logs ) {
            if ( count( $logs ) < 20 ) {
                $logs[] = (string) $line;
            }
        }
    );

    wp_agent_daemon_rotation_assert( ! empty( $result['ok'] ), 'Daemon rotation cycle should succeed.' );
    wp_agent_daemon_rotation_assert( $max_jobs === (int) ( $result['processed_jobs'] ?? -1 ), 'Each rotation cycle should process max_jobs runs.' );
    wp_agent_daemon_rotation_assert( 'max_jobs' === ( $result['restart_reason'] ?? '' ), 'Each rotation cycle should stop at the max-jobs boundary.' );

    $after_pending = wp_agent_daemon_rotation_pending_count( $run_ids );
    wp_agent_daemon_rotation_assert( $before_pending - $max_jobs === $after_pending, 'Each rotation cycle should drain exactly max_jobs pending runs.' );

    $status = WPAgent_Daemon::status();
    wp_agent_daemon_rotation_assert( empty( $status['running'] ), 'Daemon should be stopped after each foreground rotation cycle.' );
    wp_agent_daemon_rotation_assert( $max_jobs === (int) ( $status['processed_jobs'] ?? -1 ), 'Daemon status should retain the cycle processed count.' );
    wp_agent_daemon_rotation_assert( 'max_jobs' === ( $status['restart_reason'] ?? '' ), 'Daemon status should retain max-jobs restart reason.' );
    wp_agent_daemon_rotation_assert( (int) ( $status['memory_baseline'] ?? 0 ) > 0, 'Daemon should report memory baseline after each cycle.' );
    wp_agent_daemon_rotation_assert( (int) ( $status['memory_per_job_delta'] ?? -1 ) >= 0, 'Daemon should report per-job memory delta after each cycle.' );

    $cycle_results[] = array(
        'cycle'          => $cycle,
        'processed'      => (int) $result['processed_jobs'],
        'restart_reason' => $result['restart_reason'],
        'pending_before' => $before_pending,
        'pending_after'  => $after_pending,
        'ticks'          => (int) ( $result['ticks'] ?? 0 ),
        'memory_delta'   => (int) ( $status['memory_delta'] ?? 0 ),
    );
    $log_lines = array_merge( $log_lines, $logs );
}

$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

wp_agent_daemon_rotation_assert( 0 === wp_agent_daemon_rotation_pending_count( $run_ids ), 'All created runs should be drained.' );

$done_count = 0;
foreach ( $run_ids as $run_id ) {
    $run = WPAgent_Runs::get( $run_id );
    wp_agent_daemon_rotation_assert( $run && 'done' === $run->status, 'Each queued run should complete.' );
    wp_agent_daemon_rotation_assert( empty( $run->locked_until ), 'Completed runs should not retain locks.' );

    $messages = $conversation->get_messages_for_display( (int) $run->conversation_id, 0, 20 );
    $assistant_messages = array_filter(
        $messages,
        function( $message ) {
            return 'assistant' === ( $message['role'] ?? '' )
                && false !== strpos( (string) ( $message['content'] ?? '' ), 'Rotation load completed:' );
        }
    );
    wp_agent_daemon_rotation_assert( ! empty( $assistant_messages ), 'Each run should store a fake assistant completion.' );

    $events = WPAgent_Run_Events::recent( $run_id, 20 );
    $event_types = array_map(
        function( $event ) {
            return $event['event_type'] ?? '';
        },
        $events
    );
    wp_agent_daemon_rotation_assert( in_array( 'claimed', $event_types, true ), 'Each run should record a claimed event.' );
    wp_agent_daemon_rotation_assert( in_array( 'done', $event_types, true ), 'Each run should record a done event.' );
    $done_count++;
}

$usage_after = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    'wp-agent-rotation-test-model'
) );
wp_agent_daemon_rotation_assert( $usage_after >= $usage_before + $total_runs, 'Each processed run should record model usage.' );

$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_daemon_rotation_assert( $max_jobs === (int) ( $diagnostics['daemon']['processed_jobs'] ?? -1 ), 'Diagnostics should expose the final cycle processed count.' );
wp_agent_daemon_rotation_assert( 'max_jobs' === ( $diagnostics['daemon']['restart_reason'] ?? '' ), 'Diagnostics should expose max-jobs restart reason.' );
wp_agent_daemon_rotation_assert( (int) ( $diagnostics['queue']['claimable_count'] ?? -1 ) >= 0, 'Diagnostics should expose claimable count.' );

$restore();

echo wp_json_encode( array(
    'success'       => true,
    'run_ids'       => $run_ids,
    'cycles'        => $cycle_results,
    'done_count'    => $done_count,
    'elapsed_ms'    => $elapsed_ms,
    'usage_delta'   => $usage_after - $usage_before,
    'final_status'  => WPAgent_Daemon::status(),
    'log_samples'   => array_slice( $log_lines, 0, 20 ),
) ) . "\n";
