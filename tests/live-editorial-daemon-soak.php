<?php
/**
 * Live resident daemon editorial soak with bounded cost and time.
 *
 * This test uses the configured OpenAI-compatible gateway and the currently
 * running resident daemon. It may incur cost. Run only when explicitly enabled:
 *
 * WP_AGENT_LIVE_EDITORIAL_DAEMON=1 wp eval-file wp-content/plugins/wp-agent/tests/live-editorial-daemon-soak.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This live editorial daemon soak script must run through WP-CLI.\n" );
	exit( 1 );
}

function wp_agent_live_editorial_daemon_fail( $message ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	exit( 1 );
}

function wp_agent_live_editorial_daemon_assert( $condition, $message ) {
	if ( ! $condition ) {
		wp_agent_live_editorial_daemon_fail( $message );
	}
}

function wp_agent_live_editorial_daemon_usage_count( $user_id ) {
	global $wpdb;

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d",
		(int) $user_id
	) );
}

function wp_agent_live_editorial_daemon_usage_cost( $user_id ) {
	$summary = ( new WPAgent_Cost_Tracker() )->get_usage_summary( (int) $user_id, 'all' );
	return (float) ( $summary['total_cost'] ?? 0 );
}

function wp_agent_live_editorial_daemon_force_due( $schedule_id ) {
	global $wpdb;

	$wpdb->update(
		$wpdb->prefix . 'wp_agent_schedules',
		array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
		array( 'id' => (int) $schedule_id ),
		array( '%s' ),
		array( '%d' )
	);
}

function wp_agent_live_editorial_daemon_posts( $marker ) {
	global $wpdb;

	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'draft' AND post_title LIKE %s ORDER BY ID ASC",
		'%' . $wpdb->esc_like( $marker ) . '%'
	) ) );
}

function wp_agent_live_editorial_daemon_meta_array( $post_id, $key ) {
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

function wp_agent_live_editorial_daemon_tools_for_run( $conversation_id ) {
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

function wp_agent_live_editorial_daemon_wait_for_run( $run_id, $deadline, &$snapshots ) {
	do {
		sleep( 3 );
		$daemon = WPAgent_Daemon::status();
		$run    = WPAgent_Runs::get( $run_id );
		$status = $run ? (string) $run->status : 'missing';

		$snapshots[] = array(
			'time'            => time(),
			'run_id'          => (int) $run_id,
			'run_status'      => $status,
			'daemon_running'  => ! empty( $daemon['running'] ),
			'heartbeat_age'   => $daemon['heartbeat_age'] ?? null,
			'ticks'           => (int) ( $daemon['ticks'] ?? 0 ),
			'processed_jobs'  => (int) ( $daemon['processed_jobs'] ?? 0 ),
			'active_children' => (int) ( $daemon['active_children'] ?? 0 ),
			'memory_usage'    => (int) ( $daemon['memory_usage'] ?? 0 ),
			'memory_peak'     => (int) ( $daemon['memory_peak'] ?? 0 ),
			'memory_delta'    => (int) ( $daemon['memory_delta'] ?? 0 ),
		);

		if ( in_array( $status, array( 'done', 'error', 'awaiting_confirmation', 'canceled', 'missing' ), true ) ) {
			return $run;
		}
	} while ( time() < $deadline );

	return WPAgent_Runs::get( $run_id );
}

if ( '1' !== (string) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON' ) ) {
	echo wp_json_encode( array(
		'skipped' => true,
		'reason'  => 'Set WP_AGENT_LIVE_EDITORIAL_DAEMON=1 to run the credentials-backed resident editorial daemon soak.',
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	return;
}

if ( '1' !== (string) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED' ) ) {
	wp_agent_live_editorial_daemon_fail( 'Set WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1 after approving the live soak time window, API cost budget, and database artifact policy.' );
}

if ( 'approve-multi-hour-soak' !== trim( (string) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' ) ) ) {
	wp_agent_live_editorial_daemon_fail( 'Set WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak after reviewing the exact live soak time window, API cost budget, source URL, and artifact policy.' );
}
$approval_phrase_confirmed = true;

$cost_budget_usd = getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' );
if ( false === $cost_budget_usd || '' === trim( (string) $cost_budget_usd ) || ! is_numeric( $cost_budget_usd ) || (float) $cost_budget_usd <= 0 ) {
	wp_agent_live_editorial_daemon_fail( 'Set WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD to the approved positive API cost budget before running live editorial daemon soak.' );
}

$artifact_policy = trim( (string) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' ) );
if ( ! in_array( $artifact_policy, array( 'drafts_journal_usage', 'drafts_journal_usage_media' ), true ) ) {
	wp_agent_live_editorial_daemon_fail( 'Set WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY to drafts_journal_usage or drafts_journal_usage_media before running live editorial daemon soak.' );
}

$source_url       = (string) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL' );
$source_url       = '' !== $source_url ? $source_url : 'https://wordpress.org/news/';
$source_url_valid = WPAgent_URL_Safety::validate_public_http_url( $source_url, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL' );
wp_agent_live_editorial_daemon_assert(
	! is_wp_error( $source_url_valid ),
	is_wp_error( $source_url_valid ) ? $source_url_valid->get_error_message() : 'Live editorial daemon source URL must be public HTTP(S).'
);

global $wpdb;

$api_key = WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' ) );
wp_agent_live_editorial_daemon_assert( '' !== $api_key, 'Configured AI gateway API key is required.' );

$model = (string) WPAgent::get_option( 'meowl_model', '' );
wp_agent_live_editorial_daemon_assert( '' !== $model, 'Configured AI model is required.' );

$daemon_before = WPAgent_Daemon::status();
wp_agent_live_editorial_daemon_assert( ! empty( $daemon_before['running'] ), 'Resident daemon must be running before live editorial daemon soak.' );

$requester_id = 1;
$user         = get_user_by( 'id', $requester_id );
wp_agent_live_editorial_daemon_assert( $user instanceof WP_User, 'Requester user #1 is required for live editorial daemon soak.' );

$requested_run_count = (int) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS' );
$run_count           = $requested_run_count > 0 ? min( 12, $requested_run_count ) : 2;
$timeout             = (int) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT' );
$timeout             = $timeout > 0 ? min( 28800, max( 60, $timeout ) ) : 240;
$soak_seconds        = (int) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS' );
$soak_seconds        = $soak_seconds > 0 ? min( $timeout, min( 28800, max( 60, $soak_seconds ) ) ) : 0;
$sample_interval     = (int) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL' );
$sample_interval     = $sample_interval > 0 ? min( 300, max( 5, $sample_interval ) ) : 30;
$heartbeat_max_age   = (int) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_HEARTBEAT_MAX_AGE' );
$heartbeat_max_age   = $heartbeat_max_age > 0 ? max( 30, $heartbeat_max_age ) : max( 120, $sample_interval * 4 );
$max_usage_rows      = (int) getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS' );
$max_usage_rows      = $max_usage_rows > 0 ? $max_usage_rows : ( $run_count * 8 );
$memory_delta_max    = getenv( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_MEMORY_DELTA_MAX' );
$memory_delta_max    = ( false === $memory_delta_max || '' === $memory_delta_max ) ? null : max( 0, (int) $memory_delta_max );

$stamp       = gmdate( 'Ymd-His' );
$marker      = 'Live Editorial Daemon Soak ' . $stamp;
$skill_slug  = 'live-editorial-daemon-soak-' . strtolower( str_replace( '-', '', $stamp ) );
$schedule_id = 0;

$previous_mode            = get_option( 'wp_agent_mode', 'author' );
$previous_budget_sentinel = '__wp_agent_live_editorial_daemon_missing_budget__';
$previous_budget          = get_option( 'wp_agent_monthly_budget', $previous_budget_sentinel );
$restored_environment     = false;
$cleanup                  = function() use ( &$restored_environment, &$schedule_id, $requester_id, $skill_slug, $previous_mode, $previous_budget, $previous_budget_sentinel ) {
	if ( $restored_environment ) {
		return;
	}
	if ( $schedule_id > 0 ) {
		WPAgent_Schedules::set_status( $schedule_id, 'paused' );
	}
	if ( '' !== $skill_slug ) {
		WPAgent_Skills::archive( $requester_id, $skill_slug );
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
register_shutdown_function( $cleanup );

update_option( 'wp_agent_mode', 'administrator' );
WPAgent::update_option( 'monthly_budget', 0 );
WPAgent_Roles::ensure();

$agent_user_id = WPAgent_Roles::get_user_id();
wp_agent_live_editorial_daemon_assert( $agent_user_id > 0, 'Bounded agent user is missing.' );

$definitions = ( new WPAgent_Tools() )->get_definitions_for_user( $agent_user_id );
$tool_names  = wp_list_pluck( $definitions, 'name' );
foreach ( array( 'web', 'manage_taxonomies', 'manage_posts', 'manage_seo', 'journal' ) as $required_tool ) {
	wp_agent_live_editorial_daemon_assert( in_array( $required_tool, $tool_names, true ), $required_tool . ' should be available to the live editorial daemon agent.' );
}

$skill = WPAgent_Skills::save( $requester_id, array(
	'name'        => 'Live Editorial Daemon Soak ' . $stamp,
	'slug'        => $skill_slug,
	'description' => 'Temporary live acceptance Skill for resident daemon editorial soak and memory trend checks.',
	'triggers'    => array( 'live editorial daemon soak' ),
	'body'        => "## Workflow\n\nEvery scheduled invocation is one daemon-driven editorial cycle. Follow these steps exactly:\n\n1. Use the `web` tool to fetch {$source_url}.\n2. Create or reuse the category `Live Editorial Daemon Soak` with slug `live-editorial-daemon-soak`.\n3. Create exactly one new WordPress post as `draft`. The title must include `{$marker}`. Write original English content in two short paragraphs based only on the fetched source. Do not copy source text. Store `source_urls` with `{$source_url}` and a short `source_notes` value.\n4. Use `manage_seo` on the created post with the exact focus keyword `live editorial daemon soak`.\n5. Add a `journal` entry whose title includes `{$marker}` and summarizes the daemon cycle.\n6. Final response must be compact JSON with post_id, source_url, tools_used, and status=draft.\n\nConstraints: do not publish, do not request approval, do not create images, do not create comments, do not change settings, and do not delete content.\n",
	'visibility'  => 'private',
) );
wp_agent_live_editorial_daemon_assert( ! is_wp_error( $skill ), is_wp_error( $skill ) ? $skill->get_error_message() : 'Live editorial daemon Skill save failed.' );

$schedule_id = WPAgent_Schedules::create(
	$requester_id,
	"Run one daemon-driven editorial cycle for marker {$marker}. Follow the bound Skill exactly. Create a new draft even if earlier cycles already exist. Keep all content as draft.",
	'minutes',
	null,
	null,
	5,
	$skill_slug
);
wp_agent_live_editorial_daemon_assert( $schedule_id > 0, 'Live editorial daemon schedule could not be created.' );

$conversation    = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( $requester_id, 'schedule', 'schedule-' . $schedule_id );
$usage_before    = wp_agent_live_editorial_daemon_usage_count( $requester_id );
$cost_before     = wp_agent_live_editorial_daemon_usage_cost( $requester_id );
$posts_seen      = wp_agent_live_editorial_daemon_posts( $marker );
$run_ids         = array();
$post_ids        = array();
$cycle_results   = array();
$snapshots       = array();
$started_at      = time();

for ( $cycle = 1; $cycle <= $run_count; $cycle++ ) {
	wp_agent_live_editorial_daemon_assert( time() - $started_at < $timeout, 'Live editorial daemon soak exceeded the configured timeout before queuing cycle ' . $cycle . '.' );

	wp_agent_live_editorial_daemon_force_due( $schedule_id );
	$queued = WPAgent_Schedules::run( $schedule_id );
	wp_agent_live_editorial_daemon_assert( ! empty( $queued['ok'] ) && ! empty( $queued['run_id'] ), 'Schedule cycle ' . $cycle . ' should queue only the fixture run.' );

	$run_id    = (int) $queued['run_id'];
	$run_ids[] = $run_id;
	$deadline  = min( time() + $timeout, $started_at + $timeout );
	$finished  = wp_agent_live_editorial_daemon_wait_for_run( $run_id, $deadline, $snapshots );

	wp_agent_live_editorial_daemon_assert( $finished && 'done' === (string) $finished->status, 'Daemon editorial cycle ' . $cycle . ' did not finish successfully: ' . wp_json_encode( array(
		'run_id' => $run_id,
		'status' => $finished ? $finished->status : 'missing',
		'error'  => $finished ? $finished->error : '',
	) ) );
	wp_agent_live_editorial_daemon_assert( empty( $finished->locked_until ), 'Completed daemon editorial cycle should not retain a lock.' );

	$posts_after = wp_agent_live_editorial_daemon_posts( $marker );
	$new_posts   = array_values( array_diff( $posts_after, $posts_seen ) );
	wp_agent_live_editorial_daemon_assert( ! empty( $new_posts ), 'Daemon editorial cycle ' . $cycle . ' should create a new draft post containing the marker.' );

	$post_id       = (int) end( $new_posts );
	$post_ids[]    = $post_id;
	$posts_seen    = $posts_after;
	$source_urls   = wp_agent_live_editorial_daemon_meta_array( $post_id, '_wp_agent_source_urls' );
	$focus_keyword = get_post_meta( $post_id, '_wp_agent_focus_keyword', true );
	$tools_used    = wp_agent_live_editorial_daemon_tools_for_run( $conversation_id );

	wp_agent_live_editorial_daemon_assert( 'draft' === get_post_status( $post_id ), 'Daemon editorial cycle ' . $cycle . ' post should remain draft.' );
	wp_agent_live_editorial_daemon_assert( in_array( $source_url, $source_urls, true ), 'Daemon editorial cycle ' . $cycle . ' post should retain the source URL.' );
	wp_agent_live_editorial_daemon_assert( 'live editorial daemon soak' === (string) $focus_keyword, 'Daemon editorial cycle ' . $cycle . ' post should store the exact SEO focus keyword.' );
	wp_agent_live_editorial_daemon_assert( has_term( 'Live Editorial Daemon Soak', 'category', $post_id ), 'Daemon editorial cycle ' . $cycle . ' post should be assigned to the soak category.' );
	foreach ( array( 'web', 'manage_posts', 'manage_seo', 'journal' ) as $required_tool ) {
		wp_agent_live_editorial_daemon_assert( in_array( $required_tool, $tools_used, true ), 'Daemon editorial cycle did not use required tool ' . $required_tool . '. Used: ' . implode( ', ', $tools_used ) );
	}

	$usage_now = wp_agent_live_editorial_daemon_usage_count( $requester_id );
	wp_agent_live_editorial_daemon_assert( $usage_now - $usage_before <= $max_usage_rows, 'Live editorial daemon soak exceeded the usage-row guard.' );
	$cost_now       = wp_agent_live_editorial_daemon_usage_cost( $requester_id );
	$cost_usd_added = max( 0.0, $cost_now - $cost_before );
	wp_agent_live_editorial_daemon_assert( $cost_usd_added <= (float) $cost_budget_usd, 'Live editorial daemon soak exceeded the approved API cost budget.' );

	$cycle_results[] = array(
		'cycle'            => $cycle,
		'run_id'           => $run_id,
		'post_id'          => $post_id,
		'usage_rows_added' => $usage_now - $usage_before,
		'cost_usd_added'   => round( $cost_usd_added, 6 ),
		'tools_used'       => $tools_used,
	);
}

$soak_completed = false;
if ( $soak_seconds > 0 ) {
	$soak_deadline = $started_at + $soak_seconds;
	while ( time() < $soak_deadline ) {
		$sleep_for = min( $sample_interval, max( 1, $soak_deadline - time() ) );
		sleep( $sleep_for );

		$daemon_now = WPAgent_Daemon::status();
		$snapshots[] = array(
			'time'            => time(),
			'run_id'          => 0,
			'run_status'      => 'soak_sample',
			'daemon_running'  => ! empty( $daemon_now['running'] ),
			'heartbeat_age'   => $daemon_now['heartbeat_age'] ?? null,
			'ticks'           => (int) ( $daemon_now['ticks'] ?? 0 ),
			'processed_jobs'  => (int) ( $daemon_now['processed_jobs'] ?? 0 ),
			'active_children' => (int) ( $daemon_now['active_children'] ?? 0 ),
			'memory_usage'    => (int) ( $daemon_now['memory_usage'] ?? 0 ),
			'memory_peak'     => (int) ( $daemon_now['memory_peak'] ?? 0 ),
			'memory_delta'    => (int) ( $daemon_now['memory_delta'] ?? 0 ),
		);

		wp_agent_live_editorial_daemon_assert( ! empty( $daemon_now['running'] ), 'Resident daemon stopped during the configured live editorial soak window.' );
		if ( null !== ( $daemon_now['heartbeat_age'] ?? null ) ) {
			wp_agent_live_editorial_daemon_assert(
				(int) $daemon_now['heartbeat_age'] <= $heartbeat_max_age,
				'Resident daemon heartbeat became stale during the configured live editorial soak window.'
			);
		}
	}
	$soak_completed = true;
}

$daemon_after = WPAgent_Daemon::status();
wp_agent_live_editorial_daemon_assert( ! empty( $daemon_after['running'] ), 'Resident daemon should still be running after live editorial daemon soak.' );
wp_agent_live_editorial_daemon_assert( (int) ( $daemon_after['ticks'] ?? 0 ) > (int) ( $daemon_before['ticks'] ?? 0 ), 'Daemon ticks should advance during live editorial daemon soak.' );
wp_agent_live_editorial_daemon_assert( (int) ( $daemon_after['processed_jobs'] ?? 0 ) >= (int) ( $daemon_before['processed_jobs'] ?? 0 ) + $run_count, 'Daemon processed_jobs should increase by at least the queued editorial run count.' );

$usage_after = wp_agent_live_editorial_daemon_usage_count( $requester_id );
wp_agent_live_editorial_daemon_assert( $usage_after > $usage_before, 'Live editorial daemon soak should record model usage.' );
wp_agent_live_editorial_daemon_assert( $usage_after - $usage_before <= $max_usage_rows, 'Final live editorial daemon soak usage rows exceeded the configured guard.' );
$cost_after     = wp_agent_live_editorial_daemon_usage_cost( $requester_id );
$cost_usd_added = max( 0.0, $cost_after - $cost_before );
wp_agent_live_editorial_daemon_assert( $cost_usd_added <= (float) $cost_budget_usd, 'Final live editorial daemon soak estimated cost exceeded the approved API cost budget.' );

$journal_rows = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_journal WHERE user_id = %d AND (title LIKE %s OR body LIKE %s)",
	$requester_id,
	'%' . $wpdb->esc_like( $marker ) . '%',
	'%' . $wpdb->esc_like( $marker ) . '%'
) );
wp_agent_live_editorial_daemon_assert( $journal_rows >= $run_count, 'Each daemon editorial cycle should write journal evidence.' );

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
	wp_agent_live_editorial_daemon_assert( $memory_summary['usage_delta'] <= $memory_delta_max, 'Live editorial daemon memory usage delta exceeded WP_AGENT_LIVE_EDITORIAL_DAEMON_MEMORY_DELTA_MAX.' );
}

$diagnostics = WPAgent_Diagnostics::runtime( array( 'daemon' => $daemon_after ) );
wp_agent_live_editorial_daemon_assert( ! empty( $diagnostics['database']['ok'] ), 'Diagnostics database ping should pass.' );
wp_agent_live_editorial_daemon_assert( ! empty( $diagnostics['daemon']['running'] ), 'Diagnostics should report daemon running after soak.' );

WPAgent_Schedules::set_status( $schedule_id, 'paused' );
WPAgent_Skills::archive( $requester_id, $skill_slug );
$cleanup();

echo wp_json_encode( array(
	'success'             => true,
	'schedule_id'         => (int) $schedule_id,
	'run_ids'             => array_map( 'intval', $run_ids ),
	'post_ids'            => array_map( 'intval', $post_ids ),
	'run_count'           => (int) $run_count,
	'requested_run_count' => (int) $requested_run_count,
	'timeout_seconds'     => (int) $timeout,
	'soak_seconds'        => (int) $soak_seconds,
	'soak_completed'      => (bool) $soak_completed,
	'approval_phrase_confirmed' => $approval_phrase_confirmed,
	'sample_interval'     => (int) $sample_interval,
	'heartbeat_max_age'   => (int) $heartbeat_max_age,
	'elapsed_seconds'     => time() - $started_at,
	'cost_budget_usd'     => (float) $cost_budget_usd,
	'cost_usd_before'     => round( $cost_before, 6 ),
	'cost_usd_after'      => round( $cost_after, 6 ),
	'cost_usd_added'      => round( $cost_usd_added, 6 ),
	'artifact_policy'     => $artifact_policy,
	'usage_rows_added'    => $usage_after - $usage_before,
	'max_usage_rows'      => (int) $max_usage_rows,
	'journal_rows'        => (int) $journal_rows,
	'model'               => $model,
	'source_url'          => $source_url,
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
	'cycle_results'       => $cycle_results,
	'snapshots'           => array_slice( $snapshots, -16 ),
	'diagnostics'         => array(
		'queue'    => $diagnostics['queue'],
		'database' => $diagnostics['database'],
	),
	'schedule_status'     => 'paused',
	'skill_archived'      => true,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
