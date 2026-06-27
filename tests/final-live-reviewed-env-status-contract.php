<?php
/**
 * Host-side final live reviewed env status contract.
 *
 * Proves the reviewed-env status script rejects the example template, accepts
 * a safe ignored reviewed fixture, and fails closed for unsafe paths, source
 * URLs, and inline token assignments. This script does not call Docker,
 * GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-reviewed-env-status-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live reviewed env status contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_reviewed_env_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_reviewed_env_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_reviewed_env_contract_fail( $message, $details );
	}
}

function wp_agent_reviewed_env_contract_read( $path ) {
	wp_agent_reviewed_env_contract_assert( is_file( $path ), 'Required reviewed-env contract file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_reviewed_env_contract_assert( is_string( $text ) && '' !== $text, 'Required reviewed-env contract file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_reviewed_env_contract_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_reviewed_env_contract_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_reviewed_env_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_reviewed_env_contract_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_reviewed_env_contract_run( $script, $input = null ) {
	$args = array( PHP_BINARY, $script );
	if ( null !== $input ) {
		$args[] = $input;
	}
	$result = wp_agent_reviewed_env_contract_command( $args );
	wp_agent_reviewed_env_contract_assert( 0 === (int) $result['status'], 'Reviewed env status should exit successfully.', $result );
	wp_agent_reviewed_env_contract_no_raw_secrets( 'reviewed env status output', $result['output'] );
	$json = wp_agent_reviewed_env_contract_json( $result['output'] );
	wp_agent_reviewed_env_contract_assert( is_array( $json ), 'Reviewed env status should print JSON.', array(
		'output' => $result['output'],
	) );
	wp_agent_reviewed_env_contract_assert( true === (bool) ( $json['success'] ?? false ), 'Reviewed env status should report success=true.', $json );
	return $json;
}

function wp_agent_reviewed_env_contract_replace_env( $source, $updates ) {
	foreach ( $updates as $key => $value ) {
		$source = preg_replace( '/^' . preg_quote( $key, '/' ) . '=.*$/m', $key . '=' . $value, $source );
	}
	return $source;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_reviewed_env_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$script   = $plugin_dir . '/tests/final-live-reviewed-env-status.php';
$template = $plugin_dir . '/tests/final-live-inputs.example.env';
$goals    = wp_agent_reviewed_env_contract_read( $plugin_dir . '/goals.md' );
$source   = wp_agent_reviewed_env_contract_read( $script );
$template_source = wp_agent_reviewed_env_contract_read( $template );

wp_agent_reviewed_env_contract_no_raw_secrets( 'goals.md', $goals );
wp_agent_reviewed_env_contract_no_raw_secrets( 'final-live-reviewed-env-status.php', $source );
wp_agent_reviewed_env_contract_no_raw_secrets( 'final-live-inputs.example.env', $template_source );

$default = wp_agent_reviewed_env_contract_run( $script );
wp_agent_reviewed_env_contract_assert( 'final_live_reviewed_env_status' === (string) ( $default['contract'] ?? '' ), 'Reviewed env status should identify its contract name.', $default );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $default['path_is_example'] ?? false ), 'Default status should identify the example template.', $default );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $default['path_ignored_by_git'] ?? true ), 'Example template should not be treated as an ignored reviewed env.', $default );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $default['path_tracked_by_git'] ?? false ), 'Example template should be tracked.', $default );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $default['reviewed_env_ready'] ?? true ), 'Example template must not be reviewed-env ready.', $default );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $default['commands_executable'] ?? true ), 'Example template command plan must not be executable.', $default );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $default['command_plan']['placeholder_rejected'] ?? false ), 'Example template should reject placeholder GitHub coordinates.', $default );

$valid_source = wp_agent_reviewed_env_contract_replace_env( $template_source, array(
	'WP_AGENT_LIVE_GITHUB_REPOSITORY'              => 'wp-agent-fixtures/official-skills',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH'              => 'skills/news-rewrite-publisher',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' => 'approve-multi-hour-soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' => 'drafts_journal_usage',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'          => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'       => '7200',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'  => '7200',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS' => '8',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL'    => 'https://wordpress.org/news/',
	'WP_AGENT_OFFICIAL_DB_DIR'                     => '/path/to/wp-agent/database/official-mysql',
) );

$valid_path = $plugin_dir . '/tests/final-live-inputs.contract.env';
register_shutdown_function( 'unlink', $valid_path );
wp_agent_reviewed_env_contract_assert( false !== file_put_contents( $valid_path, $valid_source ), 'Could not write valid reviewed-env fixture.' );
$valid = wp_agent_reviewed_env_contract_run( $script, $valid_path );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $valid['path_under_repo'] ?? false ), 'Valid reviewed fixture should live under the repo.', $valid );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $valid['path_ignored_by_git'] ?? false ), 'Valid reviewed fixture should be ignored by Git.', $valid );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $valid['path_tracked_by_git'] ?? true ), 'Valid reviewed fixture must not be tracked.', $valid );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $valid['commands_executable'] ?? false ), 'Valid reviewed fixture command plan should be executable.', $valid );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $valid['reviewed_env_ready'] ?? false ), 'Valid reviewed fixture should be reviewed-env ready.', $valid );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $valid['secret_assignments'] ?? true ), 'Valid reviewed fixture should not contain secret assignments.', $valid );

$outside_path = tempnam( sys_get_temp_dir(), 'wp-agent-reviewed-env-outside-' );
wp_agent_reviewed_env_contract_assert( is_string( $outside_path ) && '' !== $outside_path, 'Could not allocate outside reviewed-env fixture.' );
register_shutdown_function( 'unlink', $outside_path );
wp_agent_reviewed_env_contract_assert( false !== file_put_contents( $outside_path, $valid_source ), 'Could not write outside reviewed-env fixture.' );
$outside = wp_agent_reviewed_env_contract_run( $script, $outside_path );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $outside['reviewed_env_ready'] ?? true ), 'Outside reviewed fixture should not be ready.', $outside );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $outside['path_under_repo'] ?? true ), 'Outside reviewed fixture should be outside the repo.', $outside );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $outside['commands_executable'] ?? false ), 'Outside reviewed fixture should prove command-plan readiness alone is insufficient.', $outside );

$invalid_source_path = $plugin_dir . '/tests/final-live-inputs.invalid-source.env';
register_shutdown_function( 'unlink', $invalid_source_path );
$invalid_source = wp_agent_reviewed_env_contract_replace_env( $valid_source, array(
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL' => 'http://localhost/news/',
) );
wp_agent_reviewed_env_contract_assert( false !== file_put_contents( $invalid_source_path, $invalid_source ), 'Could not write invalid source reviewed-env fixture.' );
$invalid_source_status = wp_agent_reviewed_env_contract_run( $script, $invalid_source_path );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $invalid_source_status['reviewed_env_ready'] ?? true ), 'Invalid source reviewed fixture should not be ready.', $invalid_source_status );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $invalid_source_status['command_plan']['source_url_rejected'] ?? false ), 'Invalid source reviewed fixture should reject localhost source URL.', $invalid_source_status );

$secret_path = $plugin_dir . '/tests/final-live-inputs.secret.env';
register_shutdown_function( 'unlink', $secret_path );
$secret_key = 'WP_AGENT_LIVE_GITHUB_' . 'TOKEN';
$secret_source = $valid_source . "\n" . $secret_key . '=do-not-use' . "\n";
wp_agent_reviewed_env_contract_assert( false !== file_put_contents( $secret_path, $secret_source ), 'Could not write secret reviewed-env fixture.' );
$secret_status = wp_agent_reviewed_env_contract_run( $script, $secret_path );
wp_agent_reviewed_env_contract_assert( false === (bool) ( $secret_status['reviewed_env_ready'] ?? true ), 'Secret reviewed fixture should not be ready.', $secret_status );
wp_agent_reviewed_env_contract_assert( true === (bool) ( $secret_status['secret_assignments'] ?? false ), 'Secret reviewed fixture should expose secret assignment rejection.', $secret_status );

echo json_encode( array(
	'success'                       => true,
	'contract'                      => 'final_live_reviewed_env_status_contract',
	'default_reviewed_env_ready'    => (bool) ( $default['reviewed_env_ready'] ?? true ),
	'default_path_is_example'       => (bool) ( $default['path_is_example'] ?? false ),
	'default_path_ignored_by_git'   => (bool) ( $default['path_ignored_by_git'] ?? true ),
	'valid_reviewed_fixture_ready'  => (bool) ( $valid['reviewed_env_ready'] ?? false ),
	'valid_path_ignored_by_git'     => (bool) ( $valid['path_ignored_by_git'] ?? false ),
	'outside_path_rejected'         => false === (bool) ( $outside['reviewed_env_ready'] ?? true ),
	'invalid_source_url_rejected'   => true === (bool) ( $invalid_source_status['command_plan']['source_url_rejected'] ?? false ),
	'secret_assignment_rejected'    => true === (bool) ( $secret_status['secret_assignments'] ?? false ) && false === (bool) ( $secret_status['reviewed_env_ready'] ?? true ),
	'live_network_calls'            => false,
	'ai_gateway_calls'              => false,
	'github_calls'                  => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
