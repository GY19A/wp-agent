<?php
/**
 * Host-side final live blocker status contract.
 *
 * Verifies the final live blocker status snapshot remains useful and safe for
 * both the example input template and reviewed-looking invalid inputs. This
 * script does not call Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-blocker-status-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live blocker status contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_blocker_status_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_blocker_status_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_blocker_status_contract_fail( $message, $details );
	}
}

function wp_agent_blocker_status_contract_read( $path ) {
	wp_agent_blocker_status_contract_assert( is_file( $path ), 'Required blocker status contract file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_blocker_status_contract_assert( is_string( $text ) && '' !== $text, 'Required blocker status contract file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_blocker_status_contract_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_blocker_status_contract_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_blocker_status_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_blocker_status_contract_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_blocker_status_contract_run( $script, $input = null ) {
	$args = array( PHP_BINARY, $script );
	if ( null !== $input ) {
		$args[] = $input;
	}
	$result = wp_agent_blocker_status_contract_command( $args );
	wp_agent_blocker_status_contract_assert( 0 === $result['status'], 'Final live blocker status should exit successfully.', $result );
	wp_agent_blocker_status_contract_no_raw_secrets( 'final-live-blocker-status output', $result['output'] );
	$json = wp_agent_blocker_status_contract_json( $result['output'] );
	wp_agent_blocker_status_contract_assert( is_array( $json ), 'Final live blocker status should print JSON.', array(
		'output' => $result['output'],
	) );
	wp_agent_blocker_status_contract_assert( true === (bool) ( $json['success'] ?? false ), 'Final live blocker status should report success=true.', $json );
	return $json;
}

function wp_agent_blocker_status_contract_git_path_ignored( $plugin_dir, $path ) {
	$result = wp_agent_blocker_status_contract_command( array( 'git', '-C', $plugin_dir, 'check-ignore', '-q', '--', $path ) );
	return 0 === (int) $result['status'];
}

function wp_agent_blocker_status_contract_git_path_tracked( $plugin_dir, $path ) {
	$result = wp_agent_blocker_status_contract_command( array( 'git', '-C', $plugin_dir, 'ls-files', '--error-unmatch', '--', $path ) );
	return 0 === (int) $result['status'];
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_blocker_status_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$script = $plugin_dir . '/tests/final-live-blocker-status.php';
$template = $plugin_dir . '/tests/final-live-inputs.example.env';
$goals = wp_agent_blocker_status_contract_read( $plugin_dir . '/goals.md' );
$source = wp_agent_blocker_status_contract_read( $script );
$template_source = wp_agent_blocker_status_contract_read( $template );

wp_agent_blocker_status_contract_no_raw_secrets( 'goals.md', $goals );
wp_agent_blocker_status_contract_no_raw_secrets( 'final-live-blocker-status.php', $source );
wp_agent_blocker_status_contract_no_raw_secrets( 'final-live-inputs.example.env', $template_source );

$default = wp_agent_blocker_status_contract_run( $script );
wp_agent_blocker_status_contract_assert( 'final_live_blocker_status' === (string) ( $default['contract'] ?? '' ), 'Blocker status should identify its contract name.', $default );
wp_agent_blocker_status_contract_assert( array( 6, 9 ) === array_values( $default['partial_rows'] ?? array() ), 'Default blocker status should report only #6 and #9 as partial.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['ready_for_live_execution'] ?? true ), 'Default blocker status should not be executable.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['ready_to_mark_complete'] ?? true ), 'Default blocker status should not allow completion.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['command_plan']['ready_for_live_execution'] ?? true ), 'Default blocker command plan should not be ready for live execution.', $default );
wp_agent_blocker_status_contract_assert( true === (bool) ( $default['command_plan']['placeholder_rejected'] ?? false ), 'Default blocker status should preserve placeholder rejection.', $default );
wp_agent_blocker_status_contract_assert( true === (bool) ( $default['command_plan']['approval_phrase_rejected'] ?? false ), 'Default blocker status should preserve approval phrase rejection.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['command_plan']['token_disclosed'] ?? true ), 'Default blocker status must keep token_disclosed=false.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['command_plan']['review_packet_ready'] ?? true ), 'Default blocker status should expose command-plan review packet readiness.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['command_plan']['review_packet_env_consistent'] ?? true ), 'Default blocker status should expose command-plan review packet/env consistency.', $default );
wp_agent_blocker_status_contract_assert( in_array( 'Repository', $default['command_plan']['review_packet_env_mismatches'] ?? array(), true ), 'Default blocker status should expose command-plan review packet/env mismatches.', $default );
wp_agent_blocker_status_contract_assert( true === (bool) ( $default['command_plan']['review_packet_before_live'] ?? false ), 'Default blocker status should preserve review-packet-before-live ordering.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['user_input']['user_input_ready'] ?? true ), 'Default blocker status should reject the example user inputs.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['user_input']['github_inputs_ready'] ?? true ), 'Default blocker status should expose missing official GitHub coordinates.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['user_input']['soak_inputs_ready'] ?? true ), 'Default blocker status should expose missing soak approval review.', $default );
wp_agent_blocker_status_contract_assert( in_array( 'official_skill_store_repository', $default['user_input']['pending_user_inputs'] ?? array(), true ), 'Default blocker status should list official Skill Store repository as pending.', $default );
wp_agent_blocker_status_contract_assert( in_array( 'multi_hour_soak_approval_phrase', $default['user_input']['pending_review_items'] ?? array(), true ), 'Default blocker status should list live soak approval phrase as pending.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['review_packet']['packet_ready'] ?? true ), 'Default blocker status should reject the review packet template.', $default );
wp_agent_blocker_status_contract_assert( true === (bool) ( $default['review_packet']['path_is_template'] ?? false ), 'Default blocker status should expose the review packet template path.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['review_packet']['path_ignored_by_git'] ?? true ), 'Default blocker status should expose that the review packet template is not ignored.', $default );
wp_agent_blocker_status_contract_assert( true === (bool) ( $default['review_packet']['path_tracked_by_git'] ?? false ), 'Default blocker status should expose that the review packet template is tracked.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['reviewed_env']['reviewed_env_ready'] ?? true ), 'Default blocker status should reject the example env as not reviewed ready.', $default );
wp_agent_blocker_status_contract_assert( true === (bool) ( $default['reviewed_env']['path_is_example'] ?? false ), 'Default blocker status should expose the example env path state.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['reviewed_env']['path_ignored_by_git'] ?? true ), 'Default blocker status should expose that example env is not ignored.', $default );
wp_agent_blocker_status_contract_assert( true === (bool) ( $default['reviewed_env']['path_tracked_by_git'] ?? false ), 'Default blocker status should expose that example env is tracked.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['reviewed_env']['secret_assignments'] ?? true ), 'Default blocker status should expose no reviewed-env secret assignment.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['git_hygiene']['remote_push'] ?? true ), 'Default blocker status should report remote_push=false.', $default );
wp_agent_blocker_status_contract_assert( true === (bool) ( $default['git_hygiene']['remote_push_disabled'] ?? false ), 'Default blocker status should report remote push URLs disabled.', $default );
wp_agent_blocker_status_contract_assert( false === (bool) ( $default['git_hygiene']['remote_credentials'] ?? true ), 'Default blocker status should report credential-free remotes.', $default );
wp_agent_blocker_status_contract_assert(
	is_array( $default['next_actions'] ?? null )
	&& false !== strpos( implode( "\n", $default['next_actions'] ), 'operator_init_commands' )
	&& false !== strpos( implode( "\n", $default['next_actions'] ), 'php tests/final-live-command-plan.php path/to/reviewed.env path/to/final-live-review-packet-YYYYMMDD.md' )
	&& false !== strpos( implode( "\n", $default['next_actions'] ), 'review_packet_env_consistent=true' )
	&& false !== strpos( implode( "\n", $default['next_actions'] ), 'ready_for_live_execution=true' ),
	'Default blocker status should tell the operator to verify the combined reviewed env and review packet command plan.',
	$default
);
wp_agent_blocker_status_contract_assert(
	is_array( $default['operator_init_commands'] ?? null )
	&& false !== strpos( implode( "\n", $default['operator_init_commands'] ), 'cp -n tests/final-live-inputs.example.env final-live-inputs.' )
	&& false !== strpos( implode( "\n", $default['operator_init_commands'] ), 'cp -n tests/final-live-review-packet-template.md final-live-review-packet-' )
	&& false !== strpos( implode( "\n", $default['operator_init_commands'] ), 'git check-ignore -q final-live-inputs.' )
	&& false !== strpos( implode( "\n", $default['operator_init_commands'] ), 'git check-ignore -q final-live-review-packet-' )
	&& false !== strpos( implode( "\n", $default['operator_init_commands'] ), 'php tests/final-live-command-plan.php final-live-inputs.' ),
	'Default blocker status should expose safe local initialization commands for the final live review packet and reviewed env.',
	$default
);
$operator_commands = implode( "\n", $default['operator_init_commands'] ?? array() );
$operator_files    = array();
wp_agent_blocker_status_contract_assert( 1 === preg_match( '/cp -n tests\/final-live-inputs\.example\.env (final-live-inputs\.\d{8}\.env)/', $operator_commands, $env_match ), 'Operator init commands should name a dated reviewed env file.', $default );
wp_agent_blocker_status_contract_assert( 1 === preg_match( '/cp -n tests\/final-live-review-packet-template\.md (final-live-review-packet-\d{8}\.md)/', $operator_commands, $packet_match ), 'Operator init commands should name a dated review packet file.', $default );
$operator_files[] = $env_match[1];
$operator_files[] = $packet_match[1];
foreach ( $operator_files as $operator_file ) {
	wp_agent_blocker_status_contract_assert( wp_agent_blocker_status_contract_git_path_ignored( $plugin_dir, $operator_file ), 'Operator init file should be ignored by Git before it is created.', array(
		'operator_file' => $operator_file,
		'commands'      => $default['operator_init_commands'] ?? array(),
	) );
	wp_agent_blocker_status_contract_assert( ! wp_agent_blocker_status_contract_git_path_tracked( $plugin_dir, $operator_file ), 'Operator init file should not already be tracked by Git.', array(
		'operator_file' => $operator_file,
	) );
}
wp_agent_blocker_status_contract_assert(
	false !== strpos( (string) ( $default['operator_secret_rule'] ?? '' ), 'Do not write WP_AGENT_LIVE_GITHUB_TOKEN' )
	&& false !== strpos( (string) ( $default['operator_secret_rule'] ?? '' ), 'Git' ),
	'Default blocker status should expose a no-secret rule for operator-created local files.',
	$default
);
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
foreach ( $summary_required_markers as $marker ) {
	wp_agent_blocker_status_contract_assert( in_array( $marker, $default['summary_required_markers'] ?? array(), true ), 'Default blocker status should expose the required final summary marker.', array(
		'marker' => $marker,
		'status' => $default,
	) );
	wp_agent_blocker_status_contract_assert( false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), $marker ), 'Default blocker status next actions should include the required final summary marker.', array(
		'marker'       => $marker,
		'next_actions' => $default['next_actions'] ?? array(),
	) );
}
foreach ( array( 'github', 'soak', 'command_plan', 'summary', 'manifest', 'redaction' ) as $artifact ) {
	wp_agent_blocker_status_contract_assert( in_array( $artifact, $default['completion_gate']['missing_or_invalid_artifacts'] ?? array(), true ), 'Default blocker status should report the missing final artifact.', array(
		'artifact' => $artifact,
		'status'   => $default,
	) );
}

$invalid_source = $template_source;
foreach ( array(
	'WP_AGENT_LIVE_GITHUB_REPOSITORY'              => 'wp-agent-fixtures/official-skills',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH'              => 'skills/news-rewrite-publisher',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' => 'approve-multi-hour-soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL'    => 'http://localhost/news/',
) as $key => $value ) {
	$invalid_source = preg_replace( '/^' . preg_quote( $key, '/' ) . '=.*$/m', $key . '=' . $value, $invalid_source );
}
$invalid_template = tempnam( sys_get_temp_dir(), 'wp-agent-blocker-status-invalid-' );
wp_agent_blocker_status_contract_assert( is_string( $invalid_template ) && '' !== $invalid_template, 'Could not allocate invalid blocker-status fixture.' );
register_shutdown_function( 'unlink', $invalid_template );
wp_agent_blocker_status_contract_assert( false !== file_put_contents( $invalid_template, $invalid_source ), 'Could not write invalid blocker-status fixture.' );

$invalid = wp_agent_blocker_status_contract_run( $script, $invalid_template );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['ready_for_live_execution'] ?? true ), 'Invalid reviewed-looking inputs should not be executable.', $invalid );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['ready_to_mark_complete'] ?? true ), 'Invalid reviewed-looking inputs should not allow completion.', $invalid );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['command_plan']['placeholder_rejected'] ?? true ), 'Invalid reviewed-looking fixture should use non-placeholder GitHub coordinates.', $invalid );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['command_plan']['approval_phrase_rejected'] ?? true ), 'Invalid reviewed-looking fixture should use the exact approval phrase.', $invalid );
wp_agent_blocker_status_contract_assert( in_array( 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL must not be localhost/private/reserved', $invalid['command_plan']['blocking_issues'] ?? array(), true ), 'Invalid reviewed-looking fixture should expose the unsafe source URL blocker.', $invalid );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['user_input']['user_input_ready'] ?? true ), 'Invalid reviewed-looking fixture should not be user-input ready.', $invalid );
wp_agent_blocker_status_contract_assert( in_array( 'public_source_url_not_localhost_private_or_reserved', $invalid['user_input']['pending_user_inputs'] ?? array(), true ), 'Invalid reviewed-looking fixture should expose source URL through user-input status.', $invalid );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['reviewed_env']['reviewed_env_ready'] ?? true ), 'Invalid reviewed-looking fixture should not be reviewed-env ready.', $invalid );
wp_agent_blocker_status_contract_assert( in_array( 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL must not be localhost/private/reserved', $invalid['reviewed_env']['blocking_issues'] ?? array(), true ), 'Invalid reviewed-looking fixture should expose source URL blocker through reviewed-env status.', $invalid );
wp_agent_blocker_status_contract_assert( in_array( 'github', $invalid['completion_gate']['missing_or_invalid_artifacts'] ?? array(), true ), 'Invalid reviewed-looking fixture should still require the GitHub artifact before completion.', $invalid );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['git_hygiene']['remote_push'] ?? true ), 'Invalid reviewed-looking fixture should still report remote_push=false.', $invalid );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['ai_gateway_calls'] ?? true ), 'Blocker status contract must not call the AI gateway.', $invalid );
wp_agent_blocker_status_contract_assert( false === (bool) ( $invalid['github_calls'] ?? true ), 'Blocker status contract must not call GitHub.', $invalid );

echo json_encode( array(
	'success'                           => true,
	'contract'                          => 'final_live_blocker_status_contract',
	'default_partial_rows'              => $default['partial_rows'] ?? array(),
	'default_ready_for_live_execution'  => (bool) ( $default['ready_for_live_execution'] ?? true ),
	'default_command_plan_ready_for_live_execution' => (bool) ( $default['command_plan']['ready_for_live_execution'] ?? true ),
	'default_ready_to_mark_complete'    => (bool) ( $default['ready_to_mark_complete'] ?? true ),
	'default_missing_or_invalid_artifacts' => $default['completion_gate']['missing_or_invalid_artifacts'] ?? array(),
	'default_user_input_ready'        => (bool) ( $default['user_input']['user_input_ready'] ?? true ),
	'default_github_inputs_ready'     => (bool) ( $default['user_input']['github_inputs_ready'] ?? true ),
	'default_soak_inputs_ready'       => (bool) ( $default['user_input']['soak_inputs_ready'] ?? true ),
	'default_review_packet_ready'     => (bool) ( $default['review_packet']['packet_ready'] ?? true ),
	'default_command_plan_review_packet_ready' => (bool) ( $default['command_plan']['review_packet_ready'] ?? true ),
	'default_command_plan_review_packet_env_consistent' => (bool) ( $default['command_plan']['review_packet_env_consistent'] ?? true ),
		'default_review_packet_before_live' => (bool) ( $default['command_plan']['review_packet_before_live'] ?? false ),
		'default_review_packet_ignored'   => (bool) ( $default['review_packet']['path_ignored_by_git'] ?? true ),
		'default_next_actions_command_plan' => false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'ready_for_live_execution=true' ) && false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'review_packet_env_consistent=true' ),
		'default_operator_init_commands' => is_array( $default['operator_init_commands'] ?? null ) && false !== strpos( implode( "\n", $default['operator_init_commands'] ?? array() ), 'git check-ignore -q final-live-inputs.' ),
		'default_operator_init_files_ignored' => ! empty( $operator_files ) && array_reduce( $operator_files, function ( $carry, $operator_file ) use ( $plugin_dir ) {
			return $carry && wp_agent_blocker_status_contract_git_path_ignored( $plugin_dir, $operator_file ) && ! wp_agent_blocker_status_contract_git_path_tracked( $plugin_dir, $operator_file );
		}, true ),
		'default_operator_init_files' => $operator_files,
		'default_operator_secret_rule' => false !== strpos( (string) ( $default['operator_secret_rule'] ?? '' ), 'WP_AGENT_LIVE_GITHUB_TOKEN' ),
		'default_next_actions_summary_markers' => false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'final-live-acceptance-summary-YYYYMMDD.md' ) && false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'packet_ready=true' ) && false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'review_packet_ready=true' ) && false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'review_packet_env_consistent=true' ) && false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'chat_stop_availability_playwright=true' ) && false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'final-live-command-plan' ) && false !== strpos( implode( "\n", $default['next_actions'] ?? array() ), 'final-live-archive-redaction' ),
	'summary_required_markers'        => $default['summary_required_markers'] ?? array(),
	'default_reviewed_env_ready'       => (bool) ( $default['reviewed_env']['reviewed_env_ready'] ?? true ),
	'default_reviewed_env_ignored'     => (bool) ( $default['reviewed_env']['path_ignored_by_git'] ?? true ),
	'default_remote_push'               => (bool) ( $default['git_hygiene']['remote_push'] ?? true ),
	'default_remote_push_disabled'      => (bool) ( $default['git_hygiene']['remote_push_disabled'] ?? false ),
	'invalid_reviewed_inputs_rejected'  => false === (bool) ( $invalid['ready_for_live_execution'] ?? true ),
	'invalid_user_input_rejected'       => false === (bool) ( $invalid['user_input']['user_input_ready'] ?? true ),
	'invalid_reviewed_env_rejected'     => false === (bool) ( $invalid['reviewed_env']['reviewed_env_ready'] ?? true ),
	'invalid_source_url_rejected'       => in_array( 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL must not be localhost/private/reserved', $invalid['command_plan']['blocking_issues'] ?? array(), true ),
	'secret_assignments'                => false,
	'live_network_calls'                => false,
	'ai_gateway_calls'                  => false,
	'github_calls'                      => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
