<?php
/**
 * Host-side final live user input status contract.
 *
 * Proves the user-input status script rejects the example template, accepts a
 * safe ignored reviewed fixture, and fails closed for unsafe source URLs and
 * inline token assignments. This script does not call Docker, GitHub,
 * WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-user-input-status-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live user input status contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_user_input_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_user_input_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_user_input_contract_fail( $message, $details );
	}
}

function wp_agent_user_input_contract_read( $path ) {
	wp_agent_user_input_contract_assert( is_file( $path ), 'Required user-input contract file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_user_input_contract_assert( is_string( $text ) && '' !== $text, 'Required user-input contract file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_user_input_contract_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_user_input_contract_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_user_input_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_user_input_contract_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_user_input_contract_run( $script, $input = null ) {
	$args = array( PHP_BINARY, $script );
	if ( null !== $input ) {
		$args[] = $input;
	}
	$result = wp_agent_user_input_contract_command( $args );
	wp_agent_user_input_contract_assert( 0 === (int) $result['status'], 'Final live user input status should exit successfully.', $result );
	wp_agent_user_input_contract_no_raw_secrets( 'final-live-user-input-status output', $result['output'] );
	$json = wp_agent_user_input_contract_json( $result['output'] );
	wp_agent_user_input_contract_assert( is_array( $json ), 'Final live user input status should print JSON.', array(
		'output' => $result['output'],
	) );
	wp_agent_user_input_contract_assert( true === (bool) ( $json['success'] ?? false ), 'Final live user input status should report success=true.', $json );
	return $json;
}

function wp_agent_user_input_contract_replace_env( $source, $updates ) {
	foreach ( $updates as $key => $value ) {
		$source = preg_replace( '/^' . preg_quote( $key, '/' ) . '=.*$/m', $key . '=' . $value, $source );
	}
	return $source;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_user_input_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$script = $plugin_dir . '/tests/final-live-user-input-status.php';
$template = $plugin_dir . '/tests/final-live-inputs.example.env';
$goals = wp_agent_user_input_contract_read( $plugin_dir . '/goals.md' );
$source = wp_agent_user_input_contract_read( $script );
$template_source = wp_agent_user_input_contract_read( $template );

wp_agent_user_input_contract_no_raw_secrets( 'goals.md', $goals );
wp_agent_user_input_contract_no_raw_secrets( 'final-live-user-input-status.php', $source );
wp_agent_user_input_contract_no_raw_secrets( 'final-live-inputs.example.env', $template_source );

$default = wp_agent_user_input_contract_run( $script );
wp_agent_user_input_contract_assert( 'final_live_user_input_status' === (string) ( $default['contract'] ?? '' ), 'User input status should identify its contract name.', $default );
wp_agent_user_input_contract_assert( false === (bool) ( $default['user_input_ready'] ?? true ), 'Example template must not be user-input ready.', $default );
wp_agent_user_input_contract_assert( false === (bool) ( $default['github_inputs_ready'] ?? true ), 'Example template should require official GitHub coordinates.', $default );
wp_agent_user_input_contract_assert( false === (bool) ( $default['soak_inputs_ready'] ?? true ), 'Example template should require live soak approval review.', $default );
wp_agent_user_input_contract_assert( false === (bool) ( $default['reviewed_env_ready'] ?? true ), 'Example template should not be reviewed-env ready.', $default );
wp_agent_user_input_contract_assert( in_array( 'official_skill_store_repository', $default['pending_user_inputs'] ?? array(), true ), 'Example template should expose the missing official repository input.', $default );
wp_agent_user_input_contract_assert( in_array( 'official_skill_store_skill_path', $default['pending_user_inputs'] ?? array(), true ), 'Example template should expose the missing official Skill path input.', $default );
wp_agent_user_input_contract_assert( in_array( 'reviewed_env_file', $default['pending_user_inputs'] ?? array(), true ), 'Example template should expose the reviewed env file requirement.', $default );
wp_agent_user_input_contract_assert( in_array( 'multi_hour_soak_approval_phrase', $default['pending_review_items'] ?? array(), true ), 'Example template should expose the live soak approval phrase requirement.', $default );

$valid_source = wp_agent_user_input_contract_replace_env( $template_source, array(
	'WP_AGENT_LIVE_GITHUB_REPOSITORY' => 'wp-agent-fixtures/official-skills',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH' => 'skills/news-rewrite-publisher',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' => 'approve-multi-hour-soak',
) );

$valid_path = $plugin_dir . '/tests/final-live-inputs.user-input.contract.env';
register_shutdown_function( 'unlink', $valid_path );
wp_agent_user_input_contract_assert( false !== file_put_contents( $valid_path, $valid_source ), 'Could not write valid user-input fixture.' );
$valid = wp_agent_user_input_contract_run( $script, $valid_path );
wp_agent_user_input_contract_assert( true === (bool) ( $valid['user_input_ready'] ?? false ), 'Valid ignored reviewed fixture should be user-input ready.', $valid );
wp_agent_user_input_contract_assert( true === (bool) ( $valid['github_inputs_ready'] ?? false ), 'Valid fixture should have GitHub inputs ready.', $valid );
wp_agent_user_input_contract_assert( true === (bool) ( $valid['soak_inputs_ready'] ?? false ), 'Valid fixture should have soak inputs ready.', $valid );
wp_agent_user_input_contract_assert( true === (bool) ( $valid['reviewed_env_ready'] ?? false ), 'Valid fixture should be reviewed-env ready.', $valid );
wp_agent_user_input_contract_assert( true === (bool) ( $valid['commands_executable'] ?? false ), 'Valid fixture should make the command plan executable.', $valid );
wp_agent_user_input_contract_assert( true === (bool) ( $valid['path_ignored_by_git'] ?? false ), 'Valid fixture should be ignored by Git.', $valid );
wp_agent_user_input_contract_assert( false === (bool) ( $valid['secret_assignments'] ?? true ), 'Valid fixture should not contain secret assignments.', $valid );

$invalid_source_path = $plugin_dir . '/tests/final-live-inputs.user-input-invalid-source.env';
register_shutdown_function( 'unlink', $invalid_source_path );
$invalid_source = wp_agent_user_input_contract_replace_env( $valid_source, array(
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL' => 'http://localhost/news/',
) );
wp_agent_user_input_contract_assert( false !== file_put_contents( $invalid_source_path, $invalid_source ), 'Could not write invalid source fixture.' );
$invalid = wp_agent_user_input_contract_run( $script, $invalid_source_path );
wp_agent_user_input_contract_assert( false === (bool) ( $invalid['user_input_ready'] ?? true ), 'Invalid source fixture should not be user-input ready.', $invalid );
wp_agent_user_input_contract_assert( false === (bool) ( $invalid['soak_inputs_ready'] ?? true ), 'Invalid source fixture should not be soak-input ready.', $invalid );
wp_agent_user_input_contract_assert( in_array( 'public_source_url_not_localhost_private_or_reserved', $invalid['pending_user_inputs'] ?? array(), true ), 'Invalid source fixture should expose unsafe source URL input.', $invalid );

$secret_path = $plugin_dir . '/tests/final-live-inputs.user-input-secret.env';
register_shutdown_function( 'unlink', $secret_path );
$secret_key = 'WP_AGENT_LIVE_GITHUB_' . 'TOKEN';
$secret_source = $valid_source . "\n" . $secret_key . '=do-not-use' . "\n";
wp_agent_user_input_contract_assert( false !== file_put_contents( $secret_path, $secret_source ), 'Could not write secret fixture.' );
$secret = wp_agent_user_input_contract_run( $script, $secret_path );
wp_agent_user_input_contract_assert( false === (bool) ( $secret['user_input_ready'] ?? true ), 'Secret fixture should not be user-input ready.', $secret );
wp_agent_user_input_contract_assert( true === (bool) ( $secret['secret_assignments'] ?? false ), 'Secret fixture should expose secret assignment rejection.', $secret );

echo json_encode( array(
	'success'                     => true,
	'contract'                    => 'final_live_user_input_status_contract',
	'default_user_input_ready'    => (bool) ( $default['user_input_ready'] ?? true ),
	'default_github_inputs_ready' => (bool) ( $default['github_inputs_ready'] ?? true ),
	'default_soak_inputs_ready'   => (bool) ( $default['soak_inputs_ready'] ?? true ),
	'valid_user_input_ready'      => (bool) ( $valid['user_input_ready'] ?? false ),
	'valid_reviewed_env_ready'    => (bool) ( $valid['reviewed_env_ready'] ?? false ),
	'invalid_source_rejected'     => in_array( 'public_source_url_not_localhost_private_or_reserved', $invalid['pending_user_inputs'] ?? array(), true ),
	'secret_assignment_rejected'  => true === (bool) ( $secret['secret_assignments'] ?? false ) && false === (bool) ( $secret['user_input_ready'] ?? true ),
	'live_network_calls'          => false,
	'ai_gateway_calls'            => false,
	'github_calls'                => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
