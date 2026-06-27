<?php
/**
 * Host-side final live blocker status snapshot.
 *
 * Aggregates the user-input status, reviewed-env status, local command plan,
 * completion gate, and Git hygiene into a concise JSON status report. This
 * script reads local files and runs local PHP scripts only; it does not call
 * Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-blocker-status.php [path/to/final-live-inputs.env] [path/to/final-live-review-packet.md]
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live blocker status script must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_blocker_status_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_blocker_status_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_blocker_status_fail( $message, $details );
	}
}

function wp_agent_blocker_status_read( $path ) {
	wp_agent_blocker_status_assert( is_file( $path ), 'Required blocker status file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_blocker_status_assert( is_string( $text ) && '' !== $text, 'Required blocker status file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_blocker_status_partial_rows( $goals ) {
	$rows = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $goals ) as $line ) {
		if ( 0 !== strpos( $line, '|' ) ) {
			continue;
		}
		$cells = array_map( 'trim', explode( '|', trim( $line, " \t|" ) ) );
		if ( count( $cells ) >= 3 && ctype_digit( $cells[0] ) && '部分' === $cells[2] ) {
			$rows[] = (int) $cells[0];
		}
	}
	sort( $rows );
	return $rows;
}

function wp_agent_blocker_status_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_blocker_status_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_blocker_status_run_json( $name, $args ) {
	$result = wp_agent_blocker_status_command( $args );
	wp_agent_blocker_status_assert( 0 === $result['status'], $name . ' failed.', array(
		'status' => $result['status'],
		'output' => $result['output'],
	) );
	$json = wp_agent_blocker_status_json( $result['output'] );
	wp_agent_blocker_status_assert( is_array( $json ), $name . ' should print JSON.', array(
		'output' => $result['output'],
	) );
	wp_agent_blocker_status_assert( true === (bool) ( $json['success'] ?? false ), $name . ' should report success=true.', $json );
	return $json;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_blocker_status_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$input_path = $argv[1] ?? ( $plugin_dir . '/tests/final-live-inputs.example.env' );
$input_path = realpath( $input_path ) ?: $input_path;
$packet_path = $argv[2] ?? ( $plugin_dir . '/tests/final-live-review-packet-template.md' );
$packet_path = realpath( $packet_path ) ?: $packet_path;
$goals      = wp_agent_blocker_status_read( $plugin_dir . '/goals.md' );

$command_plan = wp_agent_blocker_status_run_json( 'final live command plan', array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-command-plan.php',
	$input_path,
	$packet_path,
) );
$reviewed_env = wp_agent_blocker_status_run_json( 'final live reviewed env status', array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-reviewed-env-status.php',
	$input_path,
) );
$user_input = wp_agent_blocker_status_run_json( 'final live user input status', array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-user-input-status.php',
	$input_path,
) );
$review_packet = wp_agent_blocker_status_run_json( 'final live review packet status', array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-review-packet-status.php',
	$packet_path,
) );
$completion_gate = wp_agent_blocker_status_run_json( 'final live completion gate', array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-completion-gate-contract.php',
) );
$git_hygiene = wp_agent_blocker_status_run_json( 'git hygiene contract', array(
	PHP_BINARY,
	$plugin_dir . '/tests/git-hygiene-contract.php',
) );

$partial_rows = wp_agent_blocker_status_partial_rows( $goals );
$artifacts    = $completion_gate['artifacts'] ?? array();
$missing_or_invalid_artifacts = array();
foreach ( $artifacts as $name => $artifact ) {
	if ( 'redaction' === $name ) {
		if ( true !== (bool) ( $artifact['valid'] ?? false ) ) {
			$missing_or_invalid_artifacts[] = $name;
		}
		continue;
	}
	if ( true !== (bool) ( $artifact['valid'] ?? false ) ) {
		$missing_or_invalid_artifacts[] = $name;
	}
}

$ready_for_live_execution = true === (bool) ( $command_plan['ready_for_live_execution'] ?? false )
	&& true === (bool) ( $reviewed_env['reviewed_env_ready'] ?? false )
	&& true === (bool) ( $user_input['user_input_ready'] ?? false )
	&& true === (bool) ( $review_packet['packet_ready'] ?? false );
$ready_to_mark_complete   = true === (bool) ( $completion_gate['completion_ready'] ?? false );
$summary_required_markers = array(
	'/path/to/wp-agent/database/official-mysql',
	'remote_push=false',
	'token_disclosed=false',
	'completion_ready=true',
	'packet_ready=true',
	'ready_for_live_execution=true',
	'review_packet_ready=true',
	'review_packet_env_consistent=true',
	'ui-playwright-evidence-contract',
	'chat_queue_status_playwright=true',
	'chat_stop_availability_playwright=true',
	'composer_unlocked_guard=true',
	'final-live-command-plan',
	'final-live-github-skill-store',
	'final-live-editorial-daemon-soak',
	'final-live-archive-redaction',
	'#6',
	'#9',
);
$review_date        = gmdate( 'Ymd' );
$review_env_path    = 'final-live-inputs.' . $review_date . '.env';
$review_packet_path = 'final-live-review-packet-' . $review_date . '.md';
$operator_init_commands = array(
	'cp -n tests/final-live-inputs.example.env ' . $review_env_path,
	'cp -n tests/final-live-review-packet-template.md ' . $review_packet_path,
	'git check-ignore -q ' . $review_env_path,
	'git check-ignore -q ' . $review_packet_path,
	'php tests/final-live-review-packet-status.php ' . $review_packet_path,
	'php tests/final-live-user-input-status.php ' . $review_env_path,
	'php tests/final-live-reviewed-env-status.php ' . $review_env_path,
	'php tests/final-live-command-plan.php ' . $review_env_path . ' ' . $review_packet_path,
);

$next_actions = array();
if ( ! $ready_for_live_execution ) {
	$next_actions[] = 'Initialize local-only reviewed inputs with operator_init_commands, then fill the ignored env and review packet from the approved #6/#9 inputs; do not put tokens in either file.';
	$next_actions[] = 'Complete an ignored review packet and reviewed env from the templates until php tests/final-live-review-packet-status.php reports packet_ready=true, php tests/final-live-user-input-status.php reports user_input_ready=true and reviewed_env_ready=true, and php tests/final-live-command-plan.php path/to/reviewed.env path/to/final-live-review-packet-YYYYMMDD.md reports commands_executable=true, review_packet_env_consistent=true, and ready_for_live_execution=true.';
}
if ( ! $ready_to_mark_complete ) {
	$next_actions[] = 'Run the approved GitHub Skill Store live gate and multi-hour soak, then archive command plan JSON, GitHub JSON, soak JSON, UX evidence, acceptance summary, artifact manifest, and archive redaction report.';
	$next_actions[] = 'Write /path/to/wp-agent/design/test-logs/final-live-acceptance-summary-YYYYMMDD.md with required markers: ' . implode( ' ', $summary_required_markers ) . '.';
}
if ( ! empty( $missing_or_invalid_artifacts ) ) {
	$next_actions[] = 'Keep goals.md as 状态：实施中 with #6/#9 partial while final artifacts are missing or invalid.';
}

echo json_encode( array(
	'success'                 => true,
	'contract'                => 'final_live_blocker_status',
	'input_file'              => $input_path,
	'review_packet_file'      => $packet_path,
	'goals_status'            => false !== strpos( $goals, '状态：实施中' ) ? 'in_progress' : 'unknown',
	'partial_rows'            => $partial_rows,
	'external_blockers'       => $completion_gate['external_blockers'] ?? array(
		'official_skills_github_repository',
		'multi_hour_live_soak_budget_and_approval',
	),
	'ready_for_live_execution' => $ready_for_live_execution,
	'ready_to_mark_complete'  => $ready_to_mark_complete,
	'command_plan'            => array(
		'commands_executable'      => (bool) ( $command_plan['commands_executable'] ?? false ),
		'ready_for_live_execution' => (bool) ( $command_plan['ready_for_live_execution'] ?? false ),
		'placeholder_rejected'     => (bool) ( $command_plan['placeholder_rejected'] ?? false ),
		'approval_phrase_rejected' => (bool) ( $command_plan['approval_phrase_rejected'] ?? false ),
		'token_disclosed'          => (bool) ( $command_plan['token_disclosed'] ?? true ),
		'review_packet_ready'      => (bool) ( $command_plan['review_packet_ready'] ?? false ),
		'review_packet_env_consistent' => (bool) ( $command_plan['review_packet_env_consistent'] ?? false ),
		'review_packet_env_mismatches' => $command_plan['review_packet_env_mismatches'] ?? array(),
		'review_packet_before_live' => (bool) ( $command_plan['review_packet_before_live'] ?? false ),
		'blocking_issues'          => $command_plan['blocking_issues'] ?? array(),
	),
	'user_input'             => array(
		'user_input_ready'       => (bool) ( $user_input['user_input_ready'] ?? false ),
		'github_inputs_ready'    => (bool) ( $user_input['github_inputs_ready'] ?? false ),
		'soak_inputs_ready'      => (bool) ( $user_input['soak_inputs_ready'] ?? false ),
		'reviewed_env_ready'     => (bool) ( $user_input['reviewed_env_ready'] ?? false ),
		'commands_executable'    => (bool) ( $user_input['commands_executable'] ?? false ),
		'secret_assignments'     => (bool) ( $user_input['secret_assignments'] ?? true ),
		'pending_user_inputs'    => $user_input['pending_user_inputs'] ?? array(),
		'pending_review_items'   => $user_input['pending_review_items'] ?? array(),
	),
	'review_packet'          => array(
		'packet_ready'          => (bool) ( $review_packet['packet_ready'] ?? false ),
		'path_is_template'      => (bool) ( $review_packet['path_is_template'] ?? false ),
		'path_ignored_by_git'   => (bool) ( $review_packet['path_ignored_by_git'] ?? false ),
		'path_tracked_by_git'   => (bool) ( $review_packet['path_tracked_by_git'] ?? true ),
		'secret_assignments'    => (bool) ( $review_packet['secret_assignments'] ?? true ),
		'missing_fields'        => $review_packet['missing_fields'] ?? array(),
		'invalid_fields'        => $review_packet['invalid_fields'] ?? array(),
		'blocking_issues'       => $review_packet['blocking_issues'] ?? array(),
	),
	'reviewed_env'            => array(
		'reviewed_env_ready'       => (bool) ( $reviewed_env['reviewed_env_ready'] ?? false ),
		'path_is_example'          => (bool) ( $reviewed_env['path_is_example'] ?? false ),
		'path_ignored_by_git'      => (bool) ( $reviewed_env['path_ignored_by_git'] ?? false ),
		'path_tracked_by_git'      => (bool) ( $reviewed_env['path_tracked_by_git'] ?? true ),
		'secret_assignments'       => (bool) ( $reviewed_env['secret_assignments'] ?? true ),
		'commands_executable'      => (bool) ( $reviewed_env['commands_executable'] ?? false ),
		'blocking_issues'          => $reviewed_env['blocking_issues'] ?? array(),
	),
	'completion_gate'         => array(
		'completion_ready'            => (bool) ( $completion_gate['completion_ready'] ?? false ),
		'missing_or_invalid_artifacts' => $missing_or_invalid_artifacts,
		),
		'summary_required_markers' => $summary_required_markers,
		'operator_init_commands'   => $operator_init_commands,
		'operator_secret_rule'     => 'Do not write WP_AGENT_LIVE_GITHUB_TOKEN, API keys, passwords, or private credentials into reviewed env files, review packets, design logs, lockfiles, or Git.',
		'git_hygiene'             => array(
		'remote_push'          => (bool) ( $git_hygiene['remote_push'] ?? true ),
		'remote_push_disabled' => (bool) ( $git_hygiene['remote_push_disabled'] ?? false ),
		'remote_credentials'   => (bool) ( $git_hygiene['remote_credentials'] ?? true ),
		'ahead_count'          => (int) ( $git_hygiene['ahead_count'] ?? 0 ),
		'head_on_upstream'     => (bool) ( $git_hygiene['head_on_upstream'] ?? true ),
	),
	'next_actions'            => $next_actions,
	'live_network_calls'      => false,
	'ai_gateway_calls'        => false,
	'github_calls'            => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
