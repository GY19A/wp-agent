<?php
/**
 * Live repeated scheduled editorial automation with a budget guard.
 *
 * This test uses the configured OpenAI-compatible gateway and may incur cost.
 * Run only when explicitly enabled:
 *
 * WP_AGENT_LIVE_EDITORIAL_REPEAT=1 wp eval-file wp-content/plugins/wp-agent/tests/live-editorial-repeat-budget.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This live editorial repeat script must run through WP-CLI.\n" );
	exit( 1 );
}

function wp_agent_live_editorial_repeat_fail( $message ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	exit( 1 );
}

function wp_agent_live_editorial_repeat_assert( $condition, $message ) {
	if ( ! $condition ) {
		wp_agent_live_editorial_repeat_fail( $message );
	}
}

function wp_agent_live_editorial_repeat_force_due( $schedule_id ) {
	global $wpdb;

	$wpdb->update(
		$wpdb->prefix . 'wp_agent_schedules',
		array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
		array( 'id' => (int) $schedule_id ),
		array( '%s' ),
		array( '%d' )
	);
}

function wp_agent_live_editorial_repeat_run_to_terminal( $run_id ) {
	$worker_results = array();

	for ( $i = 0; $i < WPAgent_Agent::MAX_TOOL_LOOPS + 2; $i++ ) {
		$worker_results[] = WPAgent_Worker::run_once( $run_id );
		$current          = WPAgent_Runs::get( $run_id );
		if ( $current && in_array( (string) $current->status, array( 'done', 'error', 'awaiting_confirmation', 'canceled' ), true ) ) {
			break;
		}
	}

	return $worker_results;
}

function wp_agent_live_editorial_repeat_usage_count( $user_id ) {
	global $wpdb;

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d",
		(int) $user_id
	) );
}

function wp_agent_live_editorial_repeat_posts( $marker ) {
	global $wpdb;

	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'draft' AND post_title LIKE %s ORDER BY ID ASC",
		'%' . $wpdb->esc_like( $marker ) . '%'
	) ) );
}

function wp_agent_live_editorial_repeat_meta_array( $post_id, $key ) {
	$value = get_post_meta( $post_id, $key, true );
	if ( is_array( $value ) ) {
		return $value;
	}
	if ( is_string( $value ) && '' !== $value ) {
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}
	return array();
}

function wp_agent_live_editorial_repeat_collect_tools( $conversation_id ) {
	$conversation = new WPAgent_Conversation();
	$messages     = $conversation->get_messages_for_display( $conversation_id, 0, 1000 );
	$tools        = array();

	foreach ( $messages as $message ) {
		if ( 'assistant' !== ( $message['role'] ?? '' ) || empty( $message['tool_calls'] ) ) {
			continue;
		}
		foreach ( (array) $message['tool_calls'] as $tool_call ) {
			if ( ! empty( $tool_call['name'] ) ) {
				$tools[] = (string) $tool_call['name'];
			}
		}
	}

	return array_values( array_unique( $tools ) );
}

if ( '1' !== (string) getenv( 'WP_AGENT_LIVE_EDITORIAL_REPEAT' ) ) {
	echo wp_json_encode( array(
		'skipped' => true,
		'reason'  => 'Set WP_AGENT_LIVE_EDITORIAL_REPEAT=1 to run the credentials-backed repeated editorial budget acceptance.',
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	return;
}

global $wpdb;

$api_key = WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' ) );
wp_agent_live_editorial_repeat_assert( '' !== $api_key, 'Configured AI gateway API key is required.' );

$model = (string) WPAgent::get_option( 'meowl_model', '' );
wp_agent_live_editorial_repeat_assert( '' !== $model, 'Configured AI model is required.' );

$requester_id = 1;
$user         = get_user_by( 'id', $requester_id );
wp_agent_live_editorial_repeat_assert( $user instanceof WP_User, 'Requester user #1 is required for live editorial repeat acceptance.' );

$normal_runs    = (int) getenv( 'WP_AGENT_LIVE_EDITORIAL_REPEAT_RUNS' );
$normal_runs    = $normal_runs > 0 ? min( 3, $normal_runs ) : 2;
$max_usage_rows = (int) getenv( 'WP_AGENT_LIVE_EDITORIAL_REPEAT_MAX_USAGE_ROWS' );
$max_usage_rows = $max_usage_rows > 0 ? $max_usage_rows : ( $normal_runs * 6 );
$source_url     = (string) getenv( 'WP_AGENT_LIVE_EDITORIAL_REPEAT_SOURCE_URL' );
$source_url     = '' !== $source_url ? $source_url : 'https://wordpress.org/news/';
$stamp          = gmdate( 'Ymd-His' );
$marker         = 'Live Editorial Repeat Budget ' . $stamp;
$skill_slug     = 'live-editorial-repeat-budget-' . strtolower( str_replace( '-', '', $stamp ) );
$schedule_id    = 0;
$guard_model    = 'wp-agent-live-editorial-repeat-budget-guard-' . strtolower( str_replace( '-', '', $stamp ) );

$previous_mode            = get_option( 'wp_agent_mode', 'author' );
$previous_budget_sentinel = '__wp_agent_live_editorial_repeat_missing_budget__';
$previous_budget          = get_option( 'wp_agent_monthly_budget', $previous_budget_sentinel );
$restored_environment     = false;
$cleanup                  = function() use ( &$restored_environment, &$schedule_id, $requester_id, $skill_slug, $guard_model, $previous_mode, $previous_budget, $previous_budget_sentinel ) {
	global $wpdb;

	if ( $restored_environment ) {
		return;
	}

	if ( $schedule_id > 0 ) {
		WPAgent_Schedules::set_status( $schedule_id, 'paused' );
	}
	if ( '' !== $skill_slug ) {
		WPAgent_Skills::archive( $requester_id, $skill_slug );
	}

	$wpdb->delete(
		$wpdb->prefix . 'wp_agent_usage',
		array(
			'user_id' => (int) $requester_id,
			'model'   => $guard_model,
		),
		array( '%d', '%s' )
	);

	update_option( 'wp_agent_mode', $previous_mode );
	if ( $previous_budget_sentinel === $previous_budget ) {
		delete_option( 'wp_agent_monthly_budget' );
	} else {
		update_option( 'wp_agent_monthly_budget', $previous_budget );
	}
	WPAgent_Roles::ensure();
	$restored_environment = true;
};
register_shutdown_function( $cleanup );

update_option( 'wp_agent_mode', 'administrator' );
WPAgent::update_option( 'monthly_budget', 0 );
WPAgent_Roles::ensure();

$agent_user_id = WPAgent_Roles::get_user_id();
wp_agent_live_editorial_repeat_assert( $agent_user_id > 0, 'Bounded agent user is missing.' );

$definitions = ( new WPAgent_Tools() )->get_definitions_for_user( $agent_user_id );
$tool_names  = wp_list_pluck( $definitions, 'name' );
foreach ( array( 'web', 'manage_taxonomies', 'manage_posts', 'manage_seo', 'journal' ) as $required_tool ) {
	wp_agent_live_editorial_repeat_assert( in_array( $required_tool, $tool_names, true ), $required_tool . ' should be available to the live scheduled editorial agent.' );
}

$skill = WPAgent_Skills::save( $requester_id, array(
	'name'        => 'Live Editorial Repeat Budget ' . $stamp,
	'slug'        => $skill_slug,
	'description' => 'Temporary live acceptance Skill for repeated scheduled editorial automation with budget enforcement.',
	'triggers'    => array( 'live editorial repeat budget' ),
	'body'        => "## Workflow\n\nEvery scheduled invocation is a new editorial cycle. Follow these steps exactly:\n\n1. Use the `web` tool to fetch {$source_url}.\n2. Create or reuse the category `Live Editorial Repeat Budget` with slug `live-editorial-repeat-budget`.\n3. Create exactly one new WordPress post as `draft`. The title must include `{$marker}`. Write original English content in two short paragraphs based only on the fetched source. Do not copy source text. Store `source_urls` with `{$source_url}` and a short `source_notes` value.\n4. Use `manage_seo` on the created post with focus keyword `live editorial repeat budget`.\n5. Add a `journal` entry whose title includes `{$marker}` and summarizes the cycle.\n6. Final response must be compact JSON with post_id, source_url, tools_used, and status=draft.\n\nConstraints: do not publish, do not request approval, do not create images, do not change settings, do not create comments, and do not delete content.\n",
	'visibility'  => 'private',
) );
wp_agent_live_editorial_repeat_assert( ! is_wp_error( $skill ), is_wp_error( $skill ) ? $skill->get_error_message() : 'Live editorial repeat Skill save failed.' );

$schedule_id = WPAgent_Schedules::create(
	$requester_id,
	"Run one new live editorial repeat budget cycle for marker {$marker}. Follow the bound Skill exactly. Create a new draft even if earlier cycles already exist. Keep all content as draft.",
	'minutes',
	null,
	null,
	5,
	$skill_slug
);
wp_agent_live_editorial_repeat_assert( $schedule_id > 0, 'Live editorial repeat schedule could not be created.' );

$conversation    = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( $requester_id, 'schedule', 'schedule-' . $schedule_id );
$run_ids         = array();
$post_ids        = array();
$worker_results  = array();
$usage_before    = wp_agent_live_editorial_repeat_usage_count( $requester_id );
$posts_seen      = wp_agent_live_editorial_repeat_posts( $marker );

for ( $cycle = 1; $cycle <= $normal_runs; $cycle++ ) {
	wp_agent_live_editorial_repeat_force_due( $schedule_id );
	$queued = WPAgent_Schedules::run( $schedule_id );
	wp_agent_live_editorial_repeat_assert( ! empty( $queued['ok'] ) && ! empty( $queued['run_id'] ), 'Schedule cycle ' . $cycle . ' should queue only the fixture run.' );

	$schedule = WPAgent_Schedules::get( $schedule_id );
	wp_agent_live_editorial_repeat_assert( $schedule && 'queued' === (string) $schedule->last_status, 'Schedule cycle ' . $cycle . ' should queue a run.' );

	$run = WPAgent_Runs::get( (int) $queued['run_id'] );
	wp_agent_live_editorial_repeat_assert( $run && (int) $run->id > 0, 'Cycle ' . $cycle . ' queued run should exist.' );
	$run_id    = (int) $run->id;
	$run_ids[] = $run_id;

	$worker_results[ $run_id ] = wp_agent_live_editorial_repeat_run_to_terminal( $run_id );

	$finished = WPAgent_Runs::get( $run_id );
	wp_agent_live_editorial_repeat_assert( $finished && 'done' === (string) $finished->status, 'Cycle ' . $cycle . ' run should finish: ' . wp_json_encode( array(
		'status' => $finished ? $finished->status : 'missing',
		'error'  => $finished ? $finished->error : '',
	) ) );
	wp_agent_live_editorial_repeat_assert( empty( $finished->locked_until ), 'Cycle ' . $cycle . ' run should not retain a lock.' );

	$posts_after = wp_agent_live_editorial_repeat_posts( $marker );
	$new_posts   = array_values( array_diff( $posts_after, $posts_seen ) );
	wp_agent_live_editorial_repeat_assert( ! empty( $new_posts ), 'Cycle ' . $cycle . ' should create a new draft post containing the marker.' );

	$post_id      = (int) end( $new_posts );
	$post_ids[]   = $post_id;
	$posts_seen   = $posts_after;
	$source_urls  = wp_agent_live_editorial_repeat_meta_array( $post_id, '_wp_agent_source_urls' );
	$focus_keyword = get_post_meta( $post_id, '_wp_agent_focus_keyword', true );

	wp_agent_live_editorial_repeat_assert( 'draft' === get_post_status( $post_id ), 'Cycle ' . $cycle . ' post should remain draft.' );
	wp_agent_live_editorial_repeat_assert( in_array( $source_url, $source_urls, true ), 'Cycle ' . $cycle . ' post should retain the source URL.' );
	wp_agent_live_editorial_repeat_assert( 'live editorial repeat budget' === (string) $focus_keyword, 'Cycle ' . $cycle . ' post should store the expected SEO focus keyword.' );
	wp_agent_live_editorial_repeat_assert( has_term( 'Live Editorial Repeat Budget', 'category', $post_id ), 'Cycle ' . $cycle . ' post should be assigned to the editorial repeat budget category.' );
}

$usage_after_normal = wp_agent_live_editorial_repeat_usage_count( $requester_id );
$usage_rows_added   = $usage_after_normal - $usage_before;
wp_agent_live_editorial_repeat_assert( $usage_rows_added >= $normal_runs, 'Normal live scheduled runs should record usage rows.' );
wp_agent_live_editorial_repeat_assert( $usage_rows_added <= $max_usage_rows, 'Live repeated editorial runs exceeded the usage row guard: ' . $usage_rows_added . ' > ' . $max_usage_rows );

$tools_used = wp_agent_live_editorial_repeat_collect_tools( $conversation_id );
foreach ( array( 'web', 'manage_posts', 'manage_seo', 'journal' ) as $required_tool ) {
	wp_agent_live_editorial_repeat_assert( in_array( $required_tool, $tools_used, true ), 'Live editorial repeat did not use required tool: ' . $required_tool . '. Used: ' . implode( ', ', $tools_used ) );
}

$journal_rows = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_journal WHERE user_id = %d AND (title LIKE %s OR body LIKE %s)",
	$requester_id,
	'%' . $wpdb->esc_like( $marker ) . '%',
	'%' . $wpdb->esc_like( $marker ) . '%'
) );
wp_agent_live_editorial_repeat_assert( $journal_rows >= $normal_runs, 'Each completed cycle should write journal evidence.' );

$tracker              = new WPAgent_Cost_Tracker();
$summary_after_normal = $tracker->get_usage_summary( $requester_id, 'month' );
$budget_value         = (float) $summary_after_normal['total_cost'];
$synthetic_guard      = false;

if ( $budget_value <= 0 ) {
	$tracker->record( $requester_id, $guard_model, 1, 1 );
	$summary_after_normal = $tracker->get_usage_summary( $requester_id, 'month' );
	$budget_value         = max( 0.000001, (float) $summary_after_normal['total_cost'] );
	$synthetic_guard      = true;
}

WPAgent::update_option( 'monthly_budget', (string) $budget_value );
$usage_before_block = wp_agent_live_editorial_repeat_usage_count( $requester_id );

wp_agent_live_editorial_repeat_force_due( $schedule_id );
$blocked_queued = WPAgent_Schedules::run( $schedule_id );
wp_agent_live_editorial_repeat_assert( ! empty( $blocked_queued['ok'] ) && ! empty( $blocked_queued['run_id'] ), 'Budget guard run should queue only the fixture run.' );

$blocked_run = WPAgent_Runs::get( (int) $blocked_queued['run_id'] );
wp_agent_live_editorial_repeat_assert( $blocked_run && (int) $blocked_run->id > 0, 'Budget guard run should be queued.' );
$blocked_run_id = (int) $blocked_run->id;
$worker_results[ $blocked_run_id ] = wp_agent_live_editorial_repeat_run_to_terminal( $blocked_run_id );

$blocked_finished = WPAgent_Runs::get( $blocked_run_id );
wp_agent_live_editorial_repeat_assert( $blocked_finished && 'error' === (string) $blocked_finished->status, 'Budget guard run should fail closed.' );
wp_agent_live_editorial_repeat_assert( false !== stripos( (string) $blocked_finished->error, 'budget' ), 'Budget guard run error should mention budget.' );
wp_agent_live_editorial_repeat_assert( $usage_before_block === wp_agent_live_editorial_repeat_usage_count( $requester_id ), 'Budget-blocked run should not add usage rows.' );

WPAgent_Schedules::set_status( $schedule_id, 'paused' );
WPAgent_Skills::archive( $requester_id, $skill_slug );
$cleanup();

echo wp_json_encode( array(
	'success'                => true,
	'schedule_id'            => (int) $schedule_id,
	'run_ids'                => array_map( 'intval', $run_ids ),
	'blocked_run_id'         => (int) $blocked_run_id,
	'post_ids'               => array_map( 'intval', $post_ids ),
	'normal_runs'            => (int) $normal_runs,
	'tools_used'             => $tools_used,
	'usage_rows_added'       => (int) $usage_rows_added,
	'max_usage_rows'         => (int) $max_usage_rows,
	'budget_value'           => (float) $budget_value,
	'synthetic_budget_guard' => $synthetic_guard,
	'journal_rows'           => (int) $journal_rows,
	'model'                  => $model,
	'source_url'             => $source_url,
	'schedule_status'        => 'paused',
	'skill_archived'         => true,
	'worker_results'         => $worker_results,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
