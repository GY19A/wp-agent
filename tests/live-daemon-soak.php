<?php
/**
 * Live resident daemon soak with real AI/model/tool load.
 *
 * This test uses the configured OpenAI-compatible gateway and the currently
 * running resident daemon. It may incur cost. Run only when explicitly enabled:
 *
 * WP_AGENT_LIVE_DAEMON=1 wp eval-file wp-content/plugins/wp-agent/tests/live-daemon-soak.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This live daemon soak script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_live_daemon_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_live_daemon_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_live_daemon_fail( $message );
    }
}

if ( '1' !== (string) getenv( 'WP_AGENT_LIVE_DAEMON' ) ) {
    echo wp_json_encode( array(
        'skipped' => true,
        'reason'  => 'Set WP_AGENT_LIVE_DAEMON=1 to run the credentials-backed resident daemon soak.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    return;
}

global $wpdb;

$api_key = WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' ) );
wp_agent_live_daemon_assert( '' !== $api_key, 'Configured AI gateway API key is required.' );

$model = (string) WPAgent::get_option( 'meowl_model', '' );
wp_agent_live_daemon_assert( '' !== $model, 'Configured AI model is required.' );

$daemon_before = WPAgent_Daemon::status();
wp_agent_live_daemon_assert( ! empty( $daemon_before['running'] ), 'Resident daemon must be running before live soak.' );

$previous_mode            = get_option( 'wp_agent_mode', 'author' );
$previous_budget_sentinel = '__wp_agent_live_daemon_missing_budget__';
$previous_budget          = get_option( 'wp_agent_monthly_budget', $previous_budget_sentinel );
$restored_environment     = false;
$restore_environment      = function() use ( &$restored_environment, $previous_mode, $previous_budget, $previous_budget_sentinel ) {
    if ( $restored_environment ) {
        return;
    }
    update_option( 'wp_agent_mode', $previous_mode );
    if ( $previous_budget_sentinel === $previous_budget ) {
        delete_option( 'wp_agent_monthly_budget' );
    } else {
        update_option( 'wp_agent_monthly_budget', $previous_budget );
    }
    WPAgent_Roles::ensure();
    $restored_environment = true;
};
register_shutdown_function( $restore_environment );

update_option( 'wp_agent_mode', 'administrator' );
WPAgent::update_option( 'monthly_budget', 0 );
WPAgent_Roles::ensure();

$requester_id = 1;
$user = get_user_by( 'id', $requester_id );
wp_agent_live_daemon_assert( $user instanceof WP_User, 'Requester user #1 is required for live daemon soak.' );

$agent_user_id = WPAgent_Roles::get_user_id();
wp_agent_live_daemon_assert( $agent_user_id > 0, 'Bounded agent user is missing.' );

$definitions = ( new WPAgent_Tools() )->get_definitions_for_user( $agent_user_id );
$tool_names  = wp_list_pluck( $definitions, 'name' );
foreach ( array( 'runtime', 'web', 'journal' ) as $required_tool ) {
    wp_agent_live_daemon_assert( in_array( $required_tool, $tool_names, true ), $required_tool . ' should be available to the live daemon agent.' );
}

$conversation = new WPAgent_Conversation();
$stamp        = gmdate( 'Ymd-His' );
$source_url   = 'https://wordpress.org/news/';
$requested_run_count = (int) ( getenv( 'WP_AGENT_LIVE_DAEMON_RUNS' ) ?: 2 );
$run_count           = max( 1, min( $requested_run_count, 12 ) );
$memory_delta_max    = getenv( 'WP_AGENT_LIVE_DAEMON_MEMORY_DELTA_MAX' );
$memory_delta_max    = ( false === $memory_delta_max || '' === $memory_delta_max ) ? null : max( 0, (int) $memory_delta_max );
$run_ids             = array();

$usage_before = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d",
    $requester_id
) );

for ( $i = 1; $i <= $run_count; $i++ ) {
    $conversation_id = $conversation->get_or_create( $requester_id, 'wpcli', 'live-daemon-soak-' . $stamp . '-' . $i );
    wp_agent_live_daemon_assert( $conversation_id > 0, 'Conversation should be created.' );

    $prompt = "Live resident daemon soak fixture {$i}.\n"
        . "Use tools instead of answering directly. Do not create posts, pages, images, users, schedules, settings, or delete anything.\n"
        . "Required tool work: call runtime action status, call web action fetch for {$source_url}, and call journal action add with entry_type note.\n"
        . "After tool results, reply with compact JSON containing live_daemon_soak=true, fixture={$i}, source_url, and tools_used.";

    $message_id = $conversation->add_message( $conversation_id, 'user', $prompt );
    wp_agent_live_daemon_assert( $message_id > 0, 'User message should be created.' );

    $run_id = WPAgent_Runs::create( $conversation_id, $requester_id, $message_id, 'wpcli' );
    wp_agent_live_daemon_assert( $run_id > 0, 'Run should be queued.' );
    $run_ids[] = $run_id;
}

$deadline = time() + max( 60, min( (int) ( getenv( 'WP_AGENT_LIVE_DAEMON_TIMEOUT' ) ?: 180 ), 600 ) );
$snapshots = array();
$last_statuses = array();
do {
    sleep( 3 );

    $daemon_now = WPAgent_Daemon::status();
    $statuses   = array();
    foreach ( $run_ids as $run_id ) {
        $run = WPAgent_Runs::get( $run_id );
        $statuses[ $run_id ] = $run ? (string) $run->status : 'missing';
    }
    $last_statuses = $statuses;
    $snapshots[] = array(
        'time'            => time(),
        'daemon_running'  => ! empty( $daemon_now['running'] ),
        'heartbeat_age'   => $daemon_now['heartbeat_age'] ?? null,
        'ticks'           => (int) ( $daemon_now['ticks'] ?? 0 ),
        'active_children' => (int) ( $daemon_now['active_children'] ?? 0 ),
        'processed_jobs'  => (int) ( $daemon_now['processed_jobs'] ?? 0 ),
        'memory_usage'    => (int) ( $daemon_now['memory_usage'] ?? 0 ),
        'memory_peak'     => (int) ( $daemon_now['memory_peak'] ?? 0 ),
        'statuses'        => $statuses,
    );

    $unfinished = array_filter(
        $statuses,
        function( $status ) {
            return ! in_array( $status, array( 'done', 'error', 'awaiting_confirmation', 'canceled', 'missing' ), true );
        }
    );
} while ( ! empty( $unfinished ) && time() < $deadline );

$daemon_after = WPAgent_Daemon::status();
wp_agent_live_daemon_assert( ! empty( $daemon_after['running'] ), 'Resident daemon should still be running after live soak.' );
wp_agent_live_daemon_assert( (int) ( $daemon_after['ticks'] ?? 0 ) > (int) ( $daemon_before['ticks'] ?? 0 ), 'Daemon ticks should advance during live soak.' );
wp_agent_live_daemon_assert( (int) ( $daemon_after['processed_jobs'] ?? 0 ) >= (int) ( $daemon_before['processed_jobs'] ?? 0 ) + $run_count, 'Daemon processed_jobs should increase by the queued run count.' );
wp_agent_live_daemon_assert( (int) ( $daemon_after['memory_usage'] ?? 0 ) > 0, 'Daemon should report memory usage after live soak.' );
wp_agent_live_daemon_assert( (int) ( $daemon_after['memory_peak'] ?? 0 ) >= (int) ( $daemon_after['memory_usage'] ?? 0 ), 'Daemon memory peak should be at least memory usage.' );

$tools_by_run = array();
$final_by_run = array();
foreach ( $run_ids as $run_id ) {
    $run = WPAgent_Runs::get( $run_id );
    wp_agent_live_daemon_assert( $run && 'done' === (string) $run->status, 'Live daemon run did not finish successfully: ' . wp_json_encode( array(
        'run_id'   => $run_id,
        'status'   => $run ? $run->status : 'missing',
        'error'    => $run ? $run->error : '',
        'statuses' => $last_statuses,
    ) ) );
    wp_agent_live_daemon_assert( empty( $run->locked_until ), 'Completed run should not retain a lock.' );

    $messages = $conversation->get_messages_for_display( (int) $run->conversation_id, 0, 500 );
    $tools = array();
    $final = '';
    foreach ( $messages as $message ) {
        if ( 'assistant' === ( $message['role'] ?? '' ) && ! empty( $message['tool_calls'] ) ) {
            foreach ( (array) $message['tool_calls'] as $tool_call ) {
                if ( ! empty( $tool_call['name'] ) ) {
                    $tools[] = (string) $tool_call['name'];
                }
            }
        }
        if ( 'assistant' === ( $message['role'] ?? '' ) && empty( $message['tool_calls'] ) ) {
            $final = (string) $message['content'];
        }
    }
    $tools = array_values( array_unique( $tools ) );
    foreach ( array( 'runtime', 'web', 'journal' ) as $required_tool ) {
        wp_agent_live_daemon_assert( in_array( $required_tool, $tools, true ), 'Run ' . $run_id . ' did not use required tool ' . $required_tool . '. Used: ' . implode( ', ', $tools ) );
    }
    $tools_by_run[ $run_id ] = $tools;
    $final_by_run[ $run_id ] = mb_substr( $final, 0, 300 );
}

$usage_after = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d",
    $requester_id
) );
wp_agent_live_daemon_assert( $usage_after > $usage_before, 'Live daemon soak should record model usage.' );

$diagnostics = WPAgent_Diagnostics::runtime( array( 'daemon' => $daemon_after ) );
wp_agent_live_daemon_assert( ! empty( $diagnostics['database']['ok'] ), 'Diagnostics database ping should pass.' );
wp_agent_live_daemon_assert( ! empty( $diagnostics['daemon']['running'] ), 'Diagnostics should report live daemon running.' );

$memory_usages = array();
$memory_peaks  = array();
foreach ( $snapshots as $snapshot ) {
    $memory_usages[] = (int) ( $snapshot['memory_usage'] ?? 0 );
    $memory_peaks[]  = (int) ( $snapshot['memory_peak'] ?? 0 );
}
$first_memory_usage = ! empty( $memory_usages ) ? $memory_usages[0] : (int) ( $daemon_before['memory_usage'] ?? 0 );
$last_memory_usage  = ! empty( $memory_usages ) ? $memory_usages[ count( $memory_usages ) - 1 ] : (int) ( $daemon_after['memory_usage'] ?? 0 );
$memory_summary     = array(
    'samples'       => count( $memory_usages ),
    'first_usage'   => $first_memory_usage,
    'last_usage'    => $last_memory_usage,
    'min_usage'     => ! empty( $memory_usages ) ? min( $memory_usages ) : 0,
    'max_usage'     => ! empty( $memory_usages ) ? max( $memory_usages ) : 0,
    'usage_delta'   => $last_memory_usage - $first_memory_usage,
    'max_peak'      => ! empty( $memory_peaks ) ? max( $memory_peaks ) : 0,
    'threshold_max' => $memory_delta_max,
);
if ( null !== $memory_delta_max ) {
    wp_agent_live_daemon_assert( $memory_summary['usage_delta'] <= $memory_delta_max, 'Live daemon memory usage delta exceeded WP_AGENT_LIVE_DAEMON_MEMORY_DELTA_MAX.' );
}

$restore_environment();

echo wp_json_encode( array(
    'success'             => true,
    'run_ids'             => array_map( 'intval', $run_ids ),
    'run_count'           => $run_count,
    'requested_run_count' => $requested_run_count,
    'model'               => $model,
    'usage_rows_added'    => $usage_after - $usage_before,
    'daemon_before'       => array(
        'running'        => ! empty( $daemon_before['running'] ),
        'ticks'          => (int) ( $daemon_before['ticks'] ?? 0 ),
        'processed_jobs' => (int) ( $daemon_before['processed_jobs'] ?? 0 ),
        'memory_usage'   => (int) ( $daemon_before['memory_usage'] ?? 0 ),
    ),
    'daemon_after'        => array(
        'running'              => ! empty( $daemon_after['running'] ),
        'pid_verified'         => ! empty( $daemon_after['pid_verified'] ),
        'status'               => $daemon_after['status'] ?? '',
        'ticks'                => (int) ( $daemon_after['ticks'] ?? 0 ),
        'processed_jobs'       => (int) ( $daemon_after['processed_jobs'] ?? 0 ),
        'memory_usage'         => (int) ( $daemon_after['memory_usage'] ?? 0 ),
        'memory_peak'          => (int) ( $daemon_after['memory_peak'] ?? 0 ),
        'memory_delta'         => (int) ( $daemon_after['memory_delta'] ?? 0 ),
        'memory_per_job_delta' => (int) ( $daemon_after['memory_per_job_delta'] ?? 0 ),
        'heartbeat_age'        => $daemon_after['heartbeat_age'] ?? null,
    ),
    'memory_summary'      => $memory_summary,
    'tools_by_run'        => $tools_by_run,
    'final_by_run'        => $final_by_run,
    'snapshots'           => array_slice( $snapshots, -12 ),
    'diagnostics'         => array(
        'queue'    => $diagnostics['queue'],
        'database' => $diagnostics['database'],
    ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
