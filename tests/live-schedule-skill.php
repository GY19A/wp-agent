<?php
/**
 * Live scheduled Skill acceptance.
 *
 * This test uses the configured OpenAI-compatible gateway and may incur cost.
 * Run only when explicitly enabled:
 *
 * WP_AGENT_LIVE_SCHEDULE=1 wp eval-file wp-content/plugins/wp-agent/tests/live-schedule-skill.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This live schedule Skill script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_live_schedule_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_live_schedule_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_live_schedule_fail( $message );
    }
}

if ( '1' !== (string) getenv( 'WP_AGENT_LIVE_SCHEDULE' ) ) {
    echo wp_json_encode( array(
        'skipped' => true,
        'reason'  => 'Set WP_AGENT_LIVE_SCHEDULE=1 to run the credentials-backed scheduled Skill acceptance.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    return;
}

global $wpdb;

$api_key = WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' ) );
wp_agent_live_schedule_assert( '' !== $api_key, 'Configured AI gateway API key is required.' );

$model = (string) WPAgent::get_option( 'meowl_model', '' );
wp_agent_live_schedule_assert( '' !== $model, 'Configured AI model is required.' );

$previous_mode            = get_option( 'wp_agent_mode', 'author' );
$previous_budget_sentinel = '__wp_agent_live_schedule_missing_budget__';
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
wp_agent_live_schedule_assert( $user instanceof WP_User, 'Requester user #1 is required for live schedule acceptance.' );

$agent_user_id = WPAgent_Roles::get_user_id();
wp_agent_live_schedule_assert( $agent_user_id > 0, 'Bounded agent user is missing.' );

$definitions = ( new WPAgent_Tools() )->get_definitions_for_user( $agent_user_id );
$tool_names  = wp_list_pluck( $definitions, 'name' );
foreach ( array( 'runtime', 'journal' ) as $required_tool ) {
    wp_agent_live_schedule_assert( in_array( $required_tool, $tool_names, true ), $required_tool . ' should be available to the scheduled live agent.' );
}

$stamp      = gmdate( 'Ymd-His' );
$skill_slug = 'live-schedule-skill-' . strtolower( $stamp );
$skill      = WPAgent_Skills::save( $requester_id, array(
    'name'        => 'Live Schedule Skill Verification ' . $stamp,
    'slug'        => $skill_slug,
    'description' => 'Temporary live acceptance Skill for scheduled runtime and journal tool execution.',
    'triggers'    => array( 'live schedule skill verification' ),
    'body'        => "## Workflow\n\nWhen this Skill is run from a schedule:\n\n1. Use the `runtime` tool with action `status`.\n2. Use the `journal` tool with action `add`, entry_type `note`, and a short title mentioning live schedule verification.\n3. Do not create posts, pages, images, users, schedules, settings, or delete anything.\n4. Final response must be compact JSON with live_schedule_skill=true and tools_used.\n",
    'visibility'  => 'private',
) );
wp_agent_live_schedule_assert( ! is_wp_error( $skill ), is_wp_error( $skill ) ? $skill->get_error_message() : 'Live schedule Skill save failed.' );

$schedule_id = WPAgent_Schedules::create(
    $requester_id,
    'Live scheduled Skill acceptance fixture. Follow the bound Skill exactly. Use tools instead of answering directly.',
    'minutes',
    null,
    null,
    5,
    $skill_slug
);
wp_agent_live_schedule_assert( $schedule_id > 0, 'Live schedule could not be created.' );

$wpdb->update(
    $wpdb->prefix . 'wp_agent_schedules',
    array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
    array( 'id' => $schedule_id ),
    array( '%s' ),
    array( '%d' )
);

$usage_before = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d",
    $requester_id
) );

WPAgent_Schedules::check_and_run();

$schedule = WPAgent_Schedules::get( $schedule_id );
wp_agent_live_schedule_assert( $schedule && 'queued' === (string) $schedule->last_status, 'Due schedule should queue a run.' );
wp_agent_live_schedule_assert( false !== strpos( (string) $schedule->last_summary, 'Queued run #' ), 'Schedule summary should mention the queued run.' );

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( $requester_id, 'schedule', 'schedule-' . $schedule_id );
$run = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wp_agent_runs WHERE conversation_id = %d ORDER BY id DESC LIMIT 1",
    $conversation_id
) );
wp_agent_live_schedule_assert( $run && (int) $run->id > 0, 'Queued schedule run should exist.' );
$run_id = (int) $run->id;

$queued_message = $wpdb->get_var( $wpdb->prepare(
    "SELECT content FROM {$wpdb->prefix}wp_agent_messages WHERE id = %d",
    (int) $run->message_id
) );
wp_agent_live_schedule_assert( false !== strpos( (string) $queued_message, 'Bound Skill:' ), 'Queued message should include the bound Skill header.' );
wp_agent_live_schedule_assert( false !== strpos( (string) $queued_message, 'runtime' ), 'Queued message should include the runtime instruction.' );
wp_agent_live_schedule_assert( false !== strpos( (string) $queued_message, 'journal' ), 'Queued message should include the journal instruction.' );

$worker_results = array();
for ( $i = 0; $i < WPAgent_Agent::MAX_TOOL_LOOPS + 2; $i++ ) {
    $worker_results[] = WPAgent_Worker::run_once( $run_id );
    $current = WPAgent_Runs::get( $run_id );
    if ( $current && in_array( (string) $current->status, array( 'done', 'error', 'awaiting_confirmation', 'canceled' ), true ) ) {
        break;
    }
}

$run = WPAgent_Runs::get( $run_id );
wp_agent_live_schedule_assert( $run && 'done' === (string) $run->status, 'Live scheduled Skill run did not finish successfully: ' . wp_json_encode( array(
    'status' => $run ? $run->status : 'missing',
    'error'  => $run ? $run->error : '',
) ) );
wp_agent_live_schedule_assert( empty( $run->locked_until ), 'Completed scheduled run should not retain a lock.' );

$messages = $conversation->get_messages_for_display( $conversation_id, 0, 500 );
$tools_used = array();
$final_response = '';
foreach ( $messages as $message ) {
    if ( 'assistant' === ( $message['role'] ?? '' ) && ! empty( $message['tool_calls'] ) ) {
        foreach ( (array) $message['tool_calls'] as $tool_call ) {
            if ( ! empty( $tool_call['name'] ) ) {
                $tools_used[] = (string) $tool_call['name'];
            }
        }
    }
    if ( 'assistant' === ( $message['role'] ?? '' ) && empty( $message['tool_calls'] ) ) {
        $final_response = (string) $message['content'];
    }
}
$tools_used = array_values( array_unique( $tools_used ) );
foreach ( array( 'runtime', 'journal' ) as $required_tool ) {
    wp_agent_live_schedule_assert( in_array( $required_tool, $tools_used, true ), 'Live scheduled Skill did not use required tool: ' . $required_tool . '. Used: ' . implode( ', ', $tools_used ) );
}

$usage_after = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d",
    $requester_id
) );
wp_agent_live_schedule_assert( $usage_after > $usage_before, 'Live scheduled Skill run should record model usage.' );

WPAgent_Schedules::set_status( $schedule_id, 'paused' );
WPAgent_Skills::archive( $requester_id, $skill_slug );
$restore_environment();

echo wp_json_encode( array(
    'success'          => true,
    'schedule_id'      => (int) $schedule_id,
    'run_id'           => (int) $run_id,
    'skill_slug'       => $skill_slug,
    'tools_used'       => $tools_used,
    'usage_rows_added' => $usage_after - $usage_before,
    'model'            => $model,
    'final_length'     => strlen( $final_response ),
    'schedule_status'  => 'paused',
    'skill_archived'   => true,
    'worker_results'   => $worker_results,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
