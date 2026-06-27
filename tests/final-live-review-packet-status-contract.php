<?php
/**
 * Host-side final live review packet status contract.
 *
 * Proves the review-packet status script rejects the tracked template, accepts
 * a safe ignored completed packet fixture, and fails closed for localhost
 * source URLs and inline token assignments. This script does not call Docker,
 * GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-review-packet-status-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live review packet status contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_review_packet_status_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_review_packet_status_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_review_packet_status_contract_fail( $message, $details );
	}
}

function wp_agent_review_packet_status_contract_read( $path ) {
	wp_agent_review_packet_status_contract_assert( is_file( $path ), 'Required review-packet status contract file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_review_packet_status_contract_assert( is_string( $text ) && '' !== $text, 'Required review-packet status contract file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_review_packet_status_contract_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_review_packet_status_contract_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_review_packet_status_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_review_packet_status_contract_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_review_packet_status_contract_run( $script, $input = null ) {
	$args = array( PHP_BINARY, $script );
	if ( null !== $input ) {
		$args[] = $input;
	}
	$result = wp_agent_review_packet_status_contract_command( $args );
	wp_agent_review_packet_status_contract_assert( 0 === (int) $result['status'], 'Review packet status should exit successfully.', $result );
	wp_agent_review_packet_status_contract_no_raw_secrets( 'review packet status output', $result['output'] );
	$json = wp_agent_review_packet_status_contract_json( $result['output'] );
	wp_agent_review_packet_status_contract_assert( is_array( $json ), 'Review packet status should print JSON.', array(
		'output' => $result['output'],
	) );
	wp_agent_review_packet_status_contract_assert( true === (bool) ( $json['success'] ?? false ), 'Review packet status should report success=true.', $json );
	return $json;
}

function wp_agent_review_packet_status_contract_replace_field( $source, $field, $value ) {
	$lines = preg_split( '/(\r\n|\r|\n)/', $source );
	foreach ( $lines as $index => $line ) {
		if ( 1 === preg_match( '/^-\s*([^:]+):/', $line, $matches ) && trim( $matches[1] ) === $field ) {
			$lines[ $index ] = '- ' . $field . ': ' . $value;
		}
	}
	return implode( "\n", $lines );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_review_packet_status_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$script = $plugin_dir . '/tests/final-live-review-packet-status.php';
$template = $plugin_dir . '/tests/final-live-review-packet-template.md';
$goals = wp_agent_review_packet_status_contract_read( $plugin_dir . '/goals.md' );
$source = wp_agent_review_packet_status_contract_read( $script );
$template_source = wp_agent_review_packet_status_contract_read( $template );

wp_agent_review_packet_status_contract_no_raw_secrets( 'goals.md', $goals );
wp_agent_review_packet_status_contract_no_raw_secrets( 'final-live-review-packet-status.php', $source );
wp_agent_review_packet_status_contract_no_raw_secrets( 'final-live-review-packet-template.md', $template_source );

$default = wp_agent_review_packet_status_contract_run( $script );
wp_agent_review_packet_status_contract_assert( 'final_live_review_packet_status' === (string) ( $default['contract'] ?? '' ), 'Review packet status should identify its contract name.', $default );
wp_agent_review_packet_status_contract_assert( false === (bool) ( $default['packet_ready'] ?? true ), 'Tracked template must not be packet-ready.', $default );
wp_agent_review_packet_status_contract_assert( true === (bool) ( $default['path_is_template'] ?? false ), 'Default status should identify the template path.', $default );
wp_agent_review_packet_status_contract_assert( false === (bool) ( $default['path_ignored_by_git'] ?? true ), 'Template should not be treated as an ignored completed packet.', $default );
wp_agent_review_packet_status_contract_assert( true === (bool) ( $default['path_tracked_by_git'] ?? false ), 'Template should be tracked.', $default );
wp_agent_review_packet_status_contract_assert( in_array( 'Repository', $default['missing_fields'] ?? array(), true ), 'Template should expose missing repository approval.', $default );

$valid_source = $template_source;
foreach ( array(
	'Reviewer' => 'WP Agent reviewer',
	'Review date' => '2026-06-22',
	'Approved live window' => '2026-06-22T20:00:00Z/2026-06-23T00:00:00Z',
	'Approved API cost budget, `cost_budget_usd`' => '5',
	'Approved artifact policy' => 'drafts_journal_usage',
	'User-approved official Skill Store coordinates' => 'wp-agent-fixtures/official-skills skills/news-rewrite-publisher main',
	'Repository' => 'wp-agent-fixtures/official-skills',
	'Skill path' => 'skills/news-rewrite-publisher',
	'Ref' => 'main',
	'Review policy' => 'quarantine',
	'Activation/pin requested' => 'no',
	'GitHub token source' => 'shell',
	'Run count' => '12',
	'Timeout seconds' => '14400',
	'Soak seconds' => '14400',
	'Sample interval' => '60',
	'Max usage rows' => '96',
	'Source URL public HTTP(S)' => 'https://wordpress.org/news/',
	'Expected source scope' => 'WordPress public news feed only',
	'Throwaway database exception' => 'none',
	'cleanup/rollback policy' => 'pause schedules, archive temporary Skill, stop daemon after soak',
	'Temporary schedule handling' => 'pause after test',
	'Temporary Skill handling' => 'archive after test',
	'Daemon final state' => 'stopped or heartbeat fresh',
	'Draft/media retention' => 'drafts and media retained for review',
	'Command plan evidence' => 'final-live-command-plan-20260622.json',
	'Archive redaction report' => 'final-live-archive-redaction-20260622.md',
) as $field => $value ) {
	$valid_source = wp_agent_review_packet_status_contract_replace_field( $valid_source, $field, $value );
}

$valid_path = $plugin_dir . '/tests/final-live-review-packet-status.contract.md';
register_shutdown_function( 'unlink', $valid_path );
wp_agent_review_packet_status_contract_assert( false !== file_put_contents( $valid_path, $valid_source ), 'Could not write valid review packet fixture.' );
$valid = wp_agent_review_packet_status_contract_run( $script, $valid_path );
wp_agent_review_packet_status_contract_assert( true === (bool) ( $valid['packet_ready'] ?? false ), 'Valid ignored completed packet should be ready.', $valid );
wp_agent_review_packet_status_contract_assert( true === (bool) ( $valid['path_ignored_by_git'] ?? false ), 'Valid completed packet should be ignored by Git.', $valid );
wp_agent_review_packet_status_contract_assert( false === (bool) ( $valid['path_tracked_by_git'] ?? true ), 'Valid completed packet should not be tracked.', $valid );
wp_agent_review_packet_status_contract_assert( empty( $valid['missing_fields'] ?? array() ), 'Valid completed packet should not miss fields.', $valid );
wp_agent_review_packet_status_contract_assert( empty( $valid['invalid_fields'] ?? array() ), 'Valid completed packet should not have invalid fields.', $valid );
wp_agent_review_packet_status_contract_assert( true === (bool) ( $valid['review_summary']['command_plan_evidence_present'] ?? false ), 'Valid completed packet should expose command plan evidence readiness.', $valid );
wp_agent_review_packet_status_contract_assert( true === (bool) ( $valid['review_summary']['archive_redaction_report_present'] ?? false ), 'Valid completed packet should expose archive redaction report readiness.', $valid );

$invalid_command_plan_path = $plugin_dir . '/tests/final-live-review-packet-status-invalid-command-plan.md';
register_shutdown_function( 'unlink', $invalid_command_plan_path );
$invalid_command_plan = wp_agent_review_packet_status_contract_replace_field( $valid_source, 'Command plan evidence', 'required before completion' );
wp_agent_review_packet_status_contract_assert( false !== file_put_contents( $invalid_command_plan_path, $invalid_command_plan ), 'Could not write invalid command plan review packet fixture.' );
$invalid_command_plan_result = wp_agent_review_packet_status_contract_run( $script, $invalid_command_plan_path );
wp_agent_review_packet_status_contract_assert( false === (bool) ( $invalid_command_plan_result['packet_ready'] ?? true ), 'Invalid command plan packet should not be ready.', $invalid_command_plan_result );
wp_agent_review_packet_status_contract_assert( in_array( 'Command plan evidence', $invalid_command_plan_result['invalid_fields'] ?? array(), true ), 'Invalid command plan packet should expose the command plan evidence rejection.', $invalid_command_plan_result );

$invalid_redaction_path = $plugin_dir . '/tests/final-live-review-packet-status-invalid-redaction.md';
register_shutdown_function( 'unlink', $invalid_redaction_path );
$invalid_redaction = wp_agent_review_packet_status_contract_replace_field( $valid_source, 'Archive redaction report', 'required before completion' );
wp_agent_review_packet_status_contract_assert( false !== file_put_contents( $invalid_redaction_path, $invalid_redaction ), 'Could not write invalid redaction review packet fixture.' );
$invalid_redaction_result = wp_agent_review_packet_status_contract_run( $script, $invalid_redaction_path );
wp_agent_review_packet_status_contract_assert( false === (bool) ( $invalid_redaction_result['packet_ready'] ?? true ), 'Invalid redaction report packet should not be ready.', $invalid_redaction_result );
wp_agent_review_packet_status_contract_assert( in_array( 'Archive redaction report', $invalid_redaction_result['invalid_fields'] ?? array(), true ), 'Invalid redaction report packet should expose the archive redaction report rejection.', $invalid_redaction_result );

$invalid_source_path = $plugin_dir . '/tests/final-live-review-packet-status-invalid-source.md';
register_shutdown_function( 'unlink', $invalid_source_path );
$invalid_source = wp_agent_review_packet_status_contract_replace_field( $valid_source, 'Source URL public HTTP(S)', 'http://localhost/news/');
wp_agent_review_packet_status_contract_assert( false !== file_put_contents( $invalid_source_path, $invalid_source ), 'Could not write invalid source review packet fixture.' );
$invalid = wp_agent_review_packet_status_contract_run( $script, $invalid_source_path );
wp_agent_review_packet_status_contract_assert( false === (bool) ( $invalid['packet_ready'] ?? true ), 'Invalid source packet should not be ready.', $invalid );
wp_agent_review_packet_status_contract_assert( in_array( 'source_url_not_localhost_private_or_reserved', $invalid['invalid_fields'] ?? array(), true ), 'Invalid source packet should expose source URL rejection.', $invalid );

$secret_path = $plugin_dir . '/tests/final-live-review-packet-status-secret.md';
register_shutdown_function( 'unlink', $secret_path );
$secret_key = 'WP_AGENT_LIVE_GITHUB_' . 'TOKEN';
$secret_source = $valid_source . "\n" . $secret_key . '=do-not-use' . "\n";
wp_agent_review_packet_status_contract_assert( false !== file_put_contents( $secret_path, $secret_source ), 'Could not write secret review packet fixture.' );
$secret = wp_agent_review_packet_status_contract_run( $script, $secret_path );
wp_agent_review_packet_status_contract_assert( false === (bool) ( $secret['packet_ready'] ?? true ), 'Secret packet should not be ready.', $secret );
wp_agent_review_packet_status_contract_assert( true === (bool) ( $secret['secret_assignments'] ?? false ), 'Secret packet should expose secret assignment rejection.', $secret );

echo json_encode( array(
	'success'                    => true,
	'contract'                   => 'final_live_review_packet_status_contract',
	'default_packet_ready'       => (bool) ( $default['packet_ready'] ?? true ),
	'default_path_is_template'   => (bool) ( $default['path_is_template'] ?? false ),
	'valid_packet_ready'         => (bool) ( $valid['packet_ready'] ?? false ),
	'valid_path_ignored_by_git'  => (bool) ( $valid['path_ignored_by_git'] ?? false ),
	'invalid_command_plan_rejected' => in_array( 'Command plan evidence', $invalid_command_plan_result['invalid_fields'] ?? array(), true ),
	'invalid_redaction_report_rejected' => in_array( 'Archive redaction report', $invalid_redaction_result['invalid_fields'] ?? array(), true ),
	'invalid_source_rejected'    => in_array( 'source_url_not_localhost_private_or_reserved', $invalid['invalid_fields'] ?? array(), true ),
	'secret_assignment_rejected' => true === (bool) ( $secret['secret_assignments'] ?? false ) && false === (bool) ( $secret['packet_ready'] ?? true ),
	'live_network_calls'         => false,
	'ai_gateway_calls'           => false,
	'github_calls'               => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
