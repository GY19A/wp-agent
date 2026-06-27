<?php
/**
 * Host-side final live command plan contract.
 *
 * Verifies the dry-run command planner stays aligned with the final live
 * runbook and remains safe with the example input template. This script does
 * not execute any generated command, start Docker, call GitHub, or call the AI
 * gateway.
 *
 * Run from the host:
 * php tests/final-live-command-plan-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live command plan contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_command_plan_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_command_plan_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_command_plan_contract_fail( $message, $details );
	}
}

function wp_agent_command_plan_contract_read( $path ) {
	wp_agent_command_plan_contract_assert( is_file( $path ), 'Required command-plan file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_command_plan_contract_assert( is_string( $text ) && '' !== $text, 'Required command-plan file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_command_plan_contract_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_command_plan_contract_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_command_plan_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_command_plan_contract_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_command_plan_contract_replace_env_values( $source, $values ) {
	foreach ( $values as $key => $value ) {
		$source = preg_replace( '/^' . preg_quote( $key, '/' ) . '=.*$/m', $key . '=' . $value, $source );
	}
	return $source;
}

function wp_agent_command_plan_contract_write_fixture( $path, $contents ) {
	wp_agent_command_plan_contract_assert( false !== file_put_contents( $path, $contents ), 'Could not write command-plan fixture.', array(
		'path' => $path,
	) );
	register_shutdown_function( 'unlink', $path );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_command_plan_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$planner = $plugin_dir . '/tests/final-live-command-plan.php';
$template = $plugin_dir . '/tests/final-live-inputs.example.env';
$readme = wp_agent_command_plan_contract_read( $plugin_dir . '/README.md' );
$goals = wp_agent_command_plan_contract_read( $plugin_dir . '/goals.md' );
$planner_source = wp_agent_command_plan_contract_read( $planner );
$template_source = wp_agent_command_plan_contract_read( $template );

wp_agent_command_plan_contract_no_raw_secrets( 'README.md', $readme );
wp_agent_command_plan_contract_no_raw_secrets( 'goals.md', $goals );
wp_agent_command_plan_contract_no_raw_secrets( 'final-live-command-plan.php', $planner_source );

$result = wp_agent_command_plan_contract_command( array(
	PHP_BINARY,
	$planner,
	$template,
) );
wp_agent_command_plan_contract_assert( 0 === $result['status'], 'Final live command plan should exit successfully.', $result );
wp_agent_command_plan_contract_no_raw_secrets( 'final-live-command-plan output', $result['output'] );

$json = wp_agent_command_plan_contract_json( $result['output'] );
wp_agent_command_plan_contract_assert( is_array( $json ), 'Final live command plan should print JSON.', array(
	'output' => $result['output'],
) );

wp_agent_command_plan_contract_assert( true === (bool) ( $json['success'] ?? false ), 'Command plan should report success=true.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['ready'] ?? true ), 'Example command plan should not be ready while GitHub coordinates are placeholders.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['commands_executable'] ?? true ), 'Example command plan commands must not be marked executable.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['ready_for_live_execution'] ?? true ), 'Example command plan should not be ready for live execution without valid inputs and packet approval.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['review_packet_ready'] ?? true ), 'Example command plan should reject the tracked review packet template.', $json );
wp_agent_command_plan_contract_assert( true === (bool) ( $json['review_packet_required_for_live_execution'] ?? false ), 'Command plan should require a completed review packet before live execution.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['review_packet_env_consistent'] ?? true ), 'Example command plan should expose that the tracked review packet template does not match reviewed env values.', $json );
wp_agent_command_plan_contract_assert( in_array( 'Repository', $json['review_packet_env_mismatches'] ?? array(), true ), 'Example command plan should list packet/env mismatches.', $json );
wp_agent_command_plan_contract_assert( true === (bool) ( $json['review_packet_before_command_plan'] ?? false ), 'Command plan should check the review packet before regenerating the reviewed plan.', $json );
wp_agent_command_plan_contract_assert( true === (bool) ( $json['review_packet_before_live'] ?? false ), 'Command plan should check the review packet before strict preflight and live steps.', $json );
wp_agent_command_plan_contract_assert( true === (bool) ( $json['placeholder_rejected'] ?? false ), 'Example command plan should reject placeholder GitHub coordinates.', $json );
wp_agent_command_plan_contract_assert( true === (bool) ( $json['approval_phrase_rejected'] ?? false ), 'Example command plan should reject the placeholder soak approval phrase.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['token_disclosed'] ?? true ), 'Command plan must keep token_disclosed=false.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['secret_assignments'] ?? true ), 'Command plan must reject raw secret assignments in the example template.', $json );
wp_agent_command_plan_contract_assert( true === (bool) ( $json['ux_validation_before_manifest'] ?? false ), 'Command plan should expose ux_validation_before_manifest=true.', $json );
wp_agent_command_plan_contract_assert( true === (bool) ( $json['summary_before_manifest'] ?? false ), 'Command plan should expose summary_before_manifest=true.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['ai_gateway_calls'] ?? true ), 'Command plan must not call the AI gateway.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['github_calls'] ?? true ), 'Command plan must not call GitHub.', $json );
wp_agent_command_plan_contract_assert( in_array( 'replace placeholder GitHub repository/path with official Skill Store coordinates', $json['blocking_issues'] ?? array(), true ), 'Command plan should name the placeholder replacement issue.', $json );
wp_agent_command_plan_contract_assert( in_array( 'set WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak after reviewing the live soak parameters', $json['blocking_issues'] ?? array(), true ), 'Command plan should reject the placeholder soak approval phrase.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['source_url_rejected'] ?? true ), 'Example command plan should keep the default public source URL valid.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['official_db_rejected'] ?? true ), 'Example command plan should keep the official DB path valid.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['cost_budget_rejected'] ?? true ), 'Example command plan should keep the positive cost budget valid.', $json );
wp_agent_command_plan_contract_assert( false === (bool) ( $json['artifact_policy_rejected'] ?? true ), 'Example command plan should keep the artifact policy valid.', $json );

$invalid_source = $template_source;
foreach ( array(
	'WP_AGENT_LIVE_GITHUB_REPOSITORY'              => 'wp-agent-fixtures/official-skills',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH'              => 'skills/news-rewrite-publisher',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' => 'approve-multi-hour-soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' => '0',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' => 'publish_everything',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'          => '13',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'       => '60',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'  => '7200',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL'    => 'http://localhost/news/',
	'WP_AGENT_OFFICIAL_DB_DIR'                     => '/tmp/not-official-db',
) as $key => $value ) {
	$invalid_source = preg_replace( '/^' . preg_quote( $key, '/' ) . '=.*$/m', $key . '=' . $value, $invalid_source );
}
$invalid_template = tempnam( sys_get_temp_dir(), 'wp-agent-command-plan-invalid-' );
wp_agent_command_plan_contract_assert( is_string( $invalid_template ) && '' !== $invalid_template, 'Could not allocate invalid command-plan fixture.' );
register_shutdown_function( 'unlink', $invalid_template );
wp_agent_command_plan_contract_assert( false !== file_put_contents( $invalid_template, $invalid_source ), 'Could not write invalid command-plan fixture.' );

$invalid_result = wp_agent_command_plan_contract_command( array(
	PHP_BINARY,
	$planner,
	$invalid_template,
) );
wp_agent_command_plan_contract_assert( 0 === $invalid_result['status'], 'Invalid final live command plan fixture should exit successfully.', $invalid_result );
wp_agent_command_plan_contract_no_raw_secrets( 'invalid final-live-command-plan output', $invalid_result['output'] );
$invalid_json = wp_agent_command_plan_contract_json( $invalid_result['output'] );
wp_agent_command_plan_contract_assert( is_array( $invalid_json ), 'Invalid final live command plan fixture should print JSON.', array(
	'output' => $invalid_result['output'],
) );
wp_agent_command_plan_contract_assert( true === (bool) ( $invalid_json['success'] ?? false ), 'Invalid command plan fixture should report success=true.', $invalid_json );
wp_agent_command_plan_contract_assert( false === (bool) ( $invalid_json['ready'] ?? true ), 'Invalid command plan fixture should not be ready.', $invalid_json );
wp_agent_command_plan_contract_assert( false === (bool) ( $invalid_json['commands_executable'] ?? true ), 'Invalid command plan fixture commands must not be marked executable.', $invalid_json );
wp_agent_command_plan_contract_assert( false === (bool) ( $invalid_json['placeholder_rejected'] ?? true ), 'Invalid command plan fixture should use non-placeholder GitHub coordinates.', $invalid_json );
wp_agent_command_plan_contract_assert( false === (bool) ( $invalid_json['approval_phrase_rejected'] ?? true ), 'Invalid command plan fixture should use the exact approval phrase.', $invalid_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $invalid_json['cost_budget_rejected'] ?? false ), 'Invalid command plan fixture should reject non-positive cost budget.', $invalid_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $invalid_json['artifact_policy_rejected'] ?? false ), 'Invalid command plan fixture should reject an invalid artifact policy.', $invalid_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $invalid_json['source_url_rejected'] ?? false ), 'Invalid command plan fixture should reject a localhost source URL.', $invalid_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $invalid_json['official_db_rejected'] ?? false ), 'Invalid command plan fixture should reject a non-official DB path.', $invalid_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $invalid_json['soak_bounds_rejected'] ?? false ), 'Invalid command plan fixture should reject out-of-bounds soak parameters.', $invalid_json );
foreach ( array(
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
	'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL must not be localhost/private/reserved',
	'WP_AGENT_OFFICIAL_DB_DIR must stay under /path/to/wp-agent/database',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS must be between 1 and 12',
) as $expected_issue ) {
	wp_agent_command_plan_contract_assert( in_array( $expected_issue, $invalid_json['blocking_issues'] ?? array(), true ), 'Invalid command plan fixture should list the expected blocking issue.', array(
		'expected_issue' => $expected_issue,
		'blocking_issues' => $invalid_json['blocking_issues'] ?? array(),
	) );
}

$valid_env_source = wp_agent_command_plan_contract_replace_env_values( $template_source, array(
	'WP_AGENT_LIVE_GITHUB_REPOSITORY'                => 'wp-agent-fixtures/official-skills',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH'                => 'skills/news-rewrite-publisher',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' => 'approve-multi-hour-soak',
) );
$valid_env_path = $plugin_dir . '/tests/final-live-inputs.command-plan-contract.env';
wp_agent_command_plan_contract_write_fixture( $valid_env_path, $valid_env_source );

$valid_packet_source = wp_agent_command_plan_contract_read( $plugin_dir . '/tests/final-live-review-packet-template.md' );
$valid_packet_source = preg_replace( '/^- Reviewer:.*$/m', '- Reviewer: Command Plan Contract', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Review date:.*$/m', '- Review date: 2026-06-22', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Approved live window:.*$/m', '- Approved live window: Contract fixture window', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Approved API cost budget, `cost_budget_usd`:.*$/m', '- Approved API cost budget, `cost_budget_usd`: 5', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Approved artifact policy:.*$/m', '- Approved artifact policy: drafts_journal_usage', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- User-approved official Skill Store coordinates:.*$/m', '- User-approved official Skill Store coordinates: wp-agent-fixtures/official-skills skills/news-rewrite-publisher main', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Repository:.*$/m', '- Repository: wp-agent-fixtures/official-skills', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Skill path:.*$/m', '- Skill path: skills/news-rewrite-publisher', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Ref:.*$/m', '- Ref: main', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Review policy:.*$/m', '- Review policy: quarantine', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Activation\/pin requested:.*$/m', '- Activation/pin requested: no', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- GitHub token source:.*$/m', '- GitHub token source: shell', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Run count:.*$/m', '- Run count: 12', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Timeout seconds:.*$/m', '- Timeout seconds: 14400', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Soak seconds:.*$/m', '- Soak seconds: 14400', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Sample interval:.*$/m', '- Sample interval: 60', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Max usage rows:.*$/m', '- Max usage rows: 96', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Source URL public HTTP\(S\):.*$/m', '- Source URL public HTTP(S): https://wordpress.org/news/', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Expected source scope:.*$/m', '- Expected source scope: Public WordPress.org news posts only', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Throwaway database exception:.*$/m', '- Throwaway database exception: none', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- cleanup\/rollback policy:.*$/m', '- cleanup/rollback policy: pause temporary schedule and archive temporary Skill after review', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Temporary schedule handling:.*$/m', '- Temporary schedule handling: pause after soak', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Temporary Skill handling:.*$/m', '- Temporary Skill handling: archive or rollback after soak', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Daemon final state:.*$/m', '- Daemon final state: stopped or heartbeat fresh', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Draft\/media retention:.*$/m', '- Draft/media retention: retain drafts and media for review', $valid_packet_source );
$valid_packet_source = preg_replace( '/^- Archive redaction report:.*$/m', '- Archive redaction report: final-live-archive-redaction-20260622.md', $valid_packet_source );
$valid_packet_path = $plugin_dir . '/tests/final-live-review-packet-command-plan-contract.md';
wp_agent_command_plan_contract_write_fixture( $valid_packet_path, $valid_packet_source );

$valid_result = wp_agent_command_plan_contract_command( array(
	PHP_BINARY,
	$planner,
	$valid_env_path,
	$valid_packet_path,
) );
wp_agent_command_plan_contract_assert( 0 === $valid_result['status'], 'Valid reviewed env + review packet command plan should exit successfully.', $valid_result );
wp_agent_command_plan_contract_no_raw_secrets( 'valid final-live-command-plan output', $valid_result['output'] );
$valid_json = wp_agent_command_plan_contract_json( $valid_result['output'] );
wp_agent_command_plan_contract_assert( is_array( $valid_json ), 'Valid reviewed env + review packet command plan should print JSON.', array(
	'output' => $valid_result['output'],
) );
wp_agent_command_plan_contract_assert( true === (bool) ( $valid_json['commands_executable'] ?? false ), 'Valid reviewed env should be command executable.', $valid_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $valid_json['review_packet_ready'] ?? false ), 'Valid review packet fixture should be ready.', $valid_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $valid_json['review_packet_env_consistent'] ?? false ), 'Valid reviewed env and review packet should be consistent.', $valid_json );
wp_agent_command_plan_contract_assert( empty( $valid_json['review_packet_env_mismatches'] ?? array() ), 'Valid reviewed env and review packet should have no mismatches.', $valid_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $valid_json['ready_for_live_execution'] ?? false ), 'Valid reviewed env + review packet should be ready for live execution.', $valid_json );

$mismatch_packet_path = $plugin_dir . '/tests/final-live-review-packet-command-plan-mismatch.md';
$mismatch_packet_source = preg_replace( '/^- Skill path:.*$/m', '- Skill path: skills/mismatched-news-rewrite-publisher', $valid_packet_source );
wp_agent_command_plan_contract_write_fixture( $mismatch_packet_path, $mismatch_packet_source );
$mismatch_result = wp_agent_command_plan_contract_command( array(
	PHP_BINARY,
	$planner,
	$valid_env_path,
	$mismatch_packet_path,
) );
wp_agent_command_plan_contract_assert( 0 === $mismatch_result['status'], 'Mismatched review packet command plan should exit successfully.', $mismatch_result );
wp_agent_command_plan_contract_no_raw_secrets( 'mismatched final-live-command-plan output', $mismatch_result['output'] );
$mismatch_json = wp_agent_command_plan_contract_json( $mismatch_result['output'] );
wp_agent_command_plan_contract_assert( is_array( $mismatch_json ), 'Mismatched review packet command plan should print JSON.', array(
	'output' => $mismatch_result['output'],
) );
wp_agent_command_plan_contract_assert( true === (bool) ( $mismatch_json['commands_executable'] ?? false ), 'Mismatched packet fixture should keep env commands executable.', $mismatch_json );
wp_agent_command_plan_contract_assert( true === (bool) ( $mismatch_json['review_packet_ready'] ?? false ), 'Mismatched packet fixture should still pass packet readiness.', $mismatch_json );
wp_agent_command_plan_contract_assert( false === (bool) ( $mismatch_json['review_packet_env_consistent'] ?? true ), 'Mismatched packet fixture should fail packet/env consistency.', $mismatch_json );
wp_agent_command_plan_contract_assert( in_array( 'Skill path', $mismatch_json['review_packet_env_mismatches'] ?? array(), true ), 'Mismatched packet fixture should list Skill path mismatch.', $mismatch_json );
wp_agent_command_plan_contract_assert( in_array( 'review packet/env mismatch: Skill path', $mismatch_json['blocking_issues'] ?? array(), true ), 'Mismatched packet fixture should expose the blocking issue.', $mismatch_json );
wp_agent_command_plan_contract_assert( false === (bool) ( $mismatch_json['ready_for_live_execution'] ?? true ), 'Mismatched packet fixture should not be ready for live execution.', $mismatch_json );

$commands = $json['commands'] ?? array();
wp_agent_command_plan_contract_assert( is_array( $commands ) && count( $commands ) >= 9, 'Command plan should include the full final live execution sequence.', $json );

$commands_by_id = array();
$command_positions = array();
$all_commands = '';
foreach ( $commands as $index => $command ) {
	$id = (string) ( $command['id'] ?? '' );
	$commands_by_id[ $id ] = $command;
	$command_positions[ $id ] = $index;
	$all_commands .= "\n" . (string) ( $command['command'] ?? '' );
}
wp_agent_command_plan_contract_no_raw_secrets( 'generated command strings', $all_commands );

foreach ( array(
	'no_live_aggregate',
	'review_packet_status',
	'command_plan',
	'start_resident_daemon',
	'strict_preflight',
	'github_live',
	'editorial_daemon_soak',
	'stop_daemon',
	'git_hygiene',
	'ux_evidence_validation',
	'acceptance_summary',
	'artifact_manifest_build',
	'completion_gate',
	'artifact_manifest',
	'archive_redaction',
) as $required_step ) {
	wp_agent_command_plan_contract_assert( isset( $commands_by_id[ $required_step ] ), 'Command plan is missing a required step.', array(
		'step' => $required_step,
	) );
}

$ordered_steps = array(
	'review_packet_status',
	'command_plan',
	'start_resident_daemon',
	'strict_preflight',
	'github_live',
	'editorial_daemon_soak',
	'stop_daemon',
	'git_hygiene',
	'ux_evidence_validation',
	'acceptance_summary',
	'artifact_manifest_build',
	'completion_gate',
	'artifact_manifest',
	'archive_redaction',
);
for ( $i = 1; $i < count( $ordered_steps ); ++$i ) {
	$previous = $ordered_steps[ $i - 1 ];
	$current  = $ordered_steps[ $i ];
	wp_agent_command_plan_contract_assert(
		isset( $command_positions[ $previous ], $command_positions[ $current ] ) && $command_positions[ $previous ] < $command_positions[ $current ],
		'Command plan has an unsafe final evidence ordering.',
		array(
			'previous_step' => $previous,
			'current_step'  => $current,
			'positions'     => $command_positions,
		)
	);
}

foreach ( array(
	'php tests/final-no-live-acceptance-contract.php',
	'php tests/final-live-review-packet-status.php',
	'php tests/final-live-command-plan.php',
	'| tee /path/to/wp-agent/design/test-logs/final-live-command-plan-$(date +%Y%m%d).json',
	'docker exec -u www-data -d wp-agent-official-wordpress-1',
	'php /var/www/html/wp-content/plugins/wp-agent/bin/agentd.php',
	'--wp-load=/var/www/html/wp-load.php',
	'docker compose -p wp-agent-official -f docker-compose.official.yml --profile cli run --rm -T',
	'WP_AGENT_FINAL_PREFLIGHT_STRICT',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE',
	'wp-content/plugins/wp-agent/tests/final-acceptance-preflight.php',
	'WP_AGENT_LIVE_GITHUB_REPOSITORY',
	'wp-content/plugins/wp-agent/tests/live-github-skill-store.php',
	'| tee /path/to/wp-agent/design/test-logs/final-live-github-skill-store-$(date +%Y%m%d).json',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
	'wp-content/plugins/wp-agent/tests/live-editorial-daemon-soak.php',
	'| tee /path/to/wp-agent/design/test-logs/final-live-editorial-daemon-soak-$(date +%Y%m%d).json',
	'wp wp-agent daemon stop',
	'php tests/git-hygiene-contract.php',
	'php tests/ui-playwright-evidence-contract.php',
	'| tee /path/to/wp-agent/design/test-logs/ui-playwright-evidence-contract-$(date +%Y%m%d).md',
	'final-live-acceptance-summary-YYYYMMDD.md',
	'final-live-archive-redaction-YYYYMMDD.md',
	'completion_ready=true',
	'packet_ready=true',
	'ready_for_live_execution=true',
	'review_packet_ready=true',
	'review_packet_env_consistent=true',
	'chat_queue_status_playwright=true',
	'chat_stop_availability_playwright=true',
	'composer_unlocked_guard=true',
	'final-live-command-plan',
	'WP_AGENT_FINAL_LIVE_MANIFEST_WRITE=1',
	'php tests/final-live-artifact-manifest-build.php',
	'php tests/final-live-completion-gate-contract.php',
	'php tests/final-live-artifact-manifest-contract.php',
	'php tests/final-live-archive-redaction-contract.php',
	'| tee /path/to/wp-agent/design/test-logs/final-live-archive-redaction-$(date +%Y%m%d).md',
) as $marker ) {
	wp_agent_command_plan_contract_assert( false !== strpos( $all_commands, $marker ), 'Generated command sequence is missing a required marker.', array(
		'marker' => $marker,
	) );
}

$archive_targets = implode( "\n", $json['archive_targets'] ?? array() );
wp_agent_command_plan_contract_assert( count( $json['archive_targets'] ?? array() ) >= 7, 'Command plan should list the full final archive target set including command plan, UX, and redaction evidence.', $json );
foreach ( array(
	'/path/to/wp-agent/design/test-logs/final-live-command-plan-YYYYMMDD.json',
	'/path/to/wp-agent/design/test-logs/final-live-github-skill-store-YYYYMMDD.json',
	'/path/to/wp-agent/design/test-logs/final-live-editorial-daemon-soak-YYYYMMDD.json',
	'/path/to/wp-agent/design/test-logs/ui-playwright-evidence-contract-YYYYMMDD.md',
	'/path/to/wp-agent/design/test-logs/final-live-acceptance-summary-YYYYMMDD.md',
	'/path/to/wp-agent/design/test-logs/final-live-artifact-manifest-YYYYMMDD.json',
	'/path/to/wp-agent/design/test-logs/final-live-archive-redaction-YYYYMMDD.md',
) as $artifact ) {
	wp_agent_command_plan_contract_assert( false !== strpos( $archive_targets, $artifact ), 'Command plan is missing a required archive target.', array(
		'artifact' => $artifact,
	) );
}

foreach ( array(
	'php tests/final-live-command-plan-contract.php',
	'php tests/final-live-command-plan.php',
	'commands_executable=false',
	'review_packet_env_consistent=true',
	'review_packet_env_mismatches',
	'completion_ready=false',
) as $marker ) {
	wp_agent_command_plan_contract_assert( false !== strpos( $readme . "\n" . $goals, $marker ), 'README/goals should document final live command plan dry-run markers.', array(
		'marker' => $marker,
	) );
}

echo json_encode( array(
	'success'                  => true,
	'contract'                 => 'final_live_command_plan_contract',
	'commands_checked'         => count( $commands ),
	'placeholder_rejected'     => true,
	'approval_phrase_rejected' => true,
	'invalid_reviewed_inputs_rejected' => false === (bool) ( $invalid_json['commands_executable'] ?? true ),
	'source_url_rejected'      => (bool) ( $invalid_json['source_url_rejected'] ?? false ),
	'official_db_rejected'     => (bool) ( $invalid_json['official_db_rejected'] ?? false ),
	'cost_budget_rejected'     => (bool) ( $invalid_json['cost_budget_rejected'] ?? false ),
	'artifact_policy_rejected' => (bool) ( $invalid_json['artifact_policy_rejected'] ?? false ),
	'soak_bounds_rejected'     => (bool) ( $invalid_json['soak_bounds_rejected'] ?? false ),
	'commands_executable'      => false,
	'ready_for_live_execution' => (bool) ( $json['ready_for_live_execution'] ?? true ),
	'valid_review_packet_env_consistent' => (bool) ( $valid_json['review_packet_env_consistent'] ?? false ),
	'valid_ready_for_live_execution' => (bool) ( $valid_json['ready_for_live_execution'] ?? false ),
	'mismatched_packet_env_rejected' => false === (bool) ( $mismatch_json['ready_for_live_execution'] ?? true ),
	'mismatched_packet_env_mismatches' => $mismatch_json['review_packet_env_mismatches'] ?? array(),
	'archive_targets'          => count( $json['archive_targets'] ?? array() ),
	'ux_validation_before_manifest' => (bool) ( $json['ux_validation_before_manifest'] ?? false ),
	'summary_before_manifest'  => (bool) ( $json['summary_before_manifest'] ?? false ),
	'review_packet_ready'      => (bool) ( $json['review_packet_ready'] ?? false ),
	'review_packet_before_command_plan' => (bool) ( $json['review_packet_before_command_plan'] ?? false ),
	'review_packet_before_live' => (bool) ( $json['review_packet_before_live'] ?? false ),
	'secret_assignments'       => false,
	'live_network_calls'       => false,
	'ai_gateway_calls'         => false,
	'github_calls'             => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
