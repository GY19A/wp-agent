<?php
/**
 * WP Agent daemon higher-volume queue checks with local fake AI responses.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/daemon-high-volume.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This daemon high-volume script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_daemon_high_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_daemon_high_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_daemon_high_fail( $message );
    }
}

function wp_agent_daemon_high_pending_count( $run_ids ) {
    $pending = 0;
    foreach ( $run_ids as $run_id ) {
        $run = WPAgent_Runs::get( $run_id );
        if ( $run && in_array( (string) $run->status, array( 'queued', 'running' ), true ) ) {
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
$restore = function() use ( &$restored, $previous ) {
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
wp_agent_daemon_high_assert( ! is_wp_error( $stop ), is_wp_error( $stop ) ? $stop->get_error_message() : 'Initial daemon stop should not fail.' );

WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-high-volume-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-high-volume-model' );
WPAgent::update_option( 'monthly_budget', 0 );

$http_calls = 0;
add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) use ( &$http_calls ) {
        if ( false === strpos( (string) $url, '/chat/completions' ) ) {
            return $preempt;
        }

        $http_calls++;
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
                'id'      => 'chatcmpl-wp-agent-high-volume',
                'object'  => 'chat.completion',
                'created' => time(),
                'model'   => 'wp-agent-high-volume-model',
                'choices' => array(
                    array(
                        'index'         => 0,
                        'message'       => array(
                            'role'    => 'assistant',
                            'content' => 'High-volume daemon run completed: ' . substr( hash( 'sha256', $last_user ), 0, 12 ),
                        ),
                        'finish_reason' => 'stop',
                    ),
                ),
                'usage'   => array(
                    'prompt_tokens'     => 10,
                    'completion_tokens' => 6,
                    'total_tokens'      => 16,
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
$total_runs   = 18;
$max_jobs     = 3;

$usage_before = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    'wp-agent-high-volume-model'
) );

for ( $i = 1; $i <= $total_runs; $i++ ) {
    $conversation_id = $conversation->get_or_create( 1, 'wpcli', 'daemon-high-volume-' . wp_generate_uuid4() . '-' . $i );
    wp_agent_daemon_high_assert( $conversation_id > 0, 'Conversation should be created.' );

    $message_id = $conversation->add_message( $conversation_id, 'user', 'Daemon high-volume fixture #' . $i );
    wp_agent_daemon_high_assert( $message_id > 0, 'User message should be created.' );

    $run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
    wp_agent_daemon_high_assert( $run_id > 0, 'Run should be queued.' );
    $run_ids[] = $run_id;
}

$cycles     = array();
$started_at = microtime( true );
$max_cycles = (int) ceil( $total_runs / $max_jobs ) + 2;

for ( $cycle = 1; $cycle <= $max_cycles; $cycle++ ) {
    $before_pending = wp_agent_daemon_high_pending_count( $run_ids );
    if ( 0 === $before_pending ) {
        break;
    }

    $result = WPAgent_Daemon::run(
        array(
            'max_children'      => 3,
            'idle_sleep'        => 0,
            'max_jobs'          => $max_jobs,
            'max_lifetime'      => 30,
            'max_idle_time'     => 5,
            'memory_soft_limit' => 512,
            'memory_hard_limit' => 768,
        )
    );

    wp_agent_daemon_high_assert( ! empty( $result['ok'] ), 'Daemon high-volume cycle should succeed.' );
    wp_agent_daemon_high_assert( $max_jobs === (int) ( $result['processed_jobs'] ?? -1 ), 'Each cycle should process max_jobs runs while work remains.' );
    wp_agent_daemon_high_assert( 'max_jobs' === ( $result['restart_reason'] ?? '' ), 'Each cycle should stop at max-jobs boundary.' );

    $after_pending = wp_agent_daemon_high_pending_count( $run_ids );
    wp_agent_daemon_high_assert( $before_pending - $max_jobs === $after_pending, 'Each cycle should drain exactly max_jobs pending runs.' );

    $status = WPAgent_Daemon::status();
    wp_agent_daemon_high_assert( empty( $status['running'] ), 'Daemon should stop after each foreground cycle.' );
    wp_agent_daemon_high_assert( (int) ( $status['memory_baseline'] ?? 0 ) > 0, 'Daemon should report memory baseline.' );
    wp_agent_daemon_high_assert( (int) ( $status['memory_usage'] ?? 0 ) > 0, 'Daemon should report memory usage.' );
    wp_agent_daemon_high_assert( (int) ( $status['memory_peak'] ?? 0 ) >= (int) ( $status['memory_usage'] ?? 0 ), 'Daemon memory peak should be at least current usage.' );
    wp_agent_daemon_high_assert( (int) ( $status['memory_per_job_delta'] ?? -1 ) >= 0, 'Daemon should report per-job memory delta.' );

    $cycles[] = array(
        'cycle'                 => $cycle,
        'processed'             => (int) $result['processed_jobs'],
        'pending_before'        => $before_pending,
        'pending_after'         => $after_pending,
        'ticks'                 => (int) ( $result['ticks'] ?? 0 ),
        'memory_usage'          => (int) ( $status['memory_usage'] ?? 0 ),
        'memory_peak'           => (int) ( $status['memory_peak'] ?? 0 ),
        'memory_delta'          => (int) ( $status['memory_delta'] ?? 0 ),
        'memory_per_job_delta'  => (int) ( $status['memory_per_job_delta'] ?? 0 ),
        'restart_reason'        => $result['restart_reason'] ?? '',
    );
}

$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
wp_agent_daemon_high_assert( 0 === wp_agent_daemon_high_pending_count( $run_ids ), 'All high-volume runs should be drained.' );
wp_agent_daemon_high_assert( count( $cycles ) === (int) ceil( $total_runs / $max_jobs ), 'Expected rotation cycle count should run.' );

$done_count = 0;
foreach ( $run_ids as $run_id ) {
    $run = WPAgent_Runs::get( $run_id );
    wp_agent_daemon_high_assert( $run && 'done' === (string) $run->status, 'Each high-volume run should complete.' );
    wp_agent_daemon_high_assert( empty( $run->locked_until ), 'Completed high-volume runs should not retain locks.' );

    $messages = $conversation->get_messages_for_display( (int) $run->conversation_id, 0, 20 );
    $assistant_messages = array_filter(
        $messages,
        function( $message ) {
            return 'assistant' === ( $message['role'] ?? '' )
                && false !== strpos( (string) ( $message['content'] ?? '' ), 'High-volume daemon run completed:' );
        }
    );
    wp_agent_daemon_high_assert( ! empty( $assistant_messages ), 'Each high-volume run should store the fake assistant completion.' );
    $done_count++;
}

$usage_after = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
    1,
    'wp-agent-high-volume-model'
) );
wp_agent_daemon_high_assert( $usage_after >= $usage_before + $total_runs, 'Each high-volume run should record usage.' );
wp_agent_daemon_high_assert( $http_calls >= $total_runs, 'Fake provider should receive one chat completion per run.' );

$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_daemon_high_assert( 0 === (int) ( $diagnostics['queue']['claimable_count'] ?? -1 ), 'Diagnostics should report no claimable runs after drain.' );
wp_agent_daemon_high_assert( 0 === (int) ( $diagnostics['queue']['counts']['queued'] ?? -1 ), 'Diagnostics should report no queued runs after drain.' );
wp_agent_daemon_high_assert( 0 === (int) ( $diagnostics['queue']['counts']['running'] ?? -1 ), 'Diagnostics should report no running runs after drain.' );

$restore();

echo wp_json_encode( array(
    'success'        => true,
    'total_runs'     => $total_runs,
    'max_jobs'       => $max_jobs,
    'cycle_count'    => count( $cycles ),
    'done_count'     => $done_count,
    'http_calls'     => (int) $http_calls,
    'usage_delta'    => $usage_after - $usage_before,
    'elapsed_ms'     => $elapsed_ms,
    'cycles'         => $cycles,
    'queue'          => $diagnostics['queue'],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
