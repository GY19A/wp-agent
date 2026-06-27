<?php
/**
 * Host-side final live reviewed env status.
 *
 * Verifies a reviewed final-live env file is safe to use as the input for the
 * final command plan. This script reads local files and runs local PHP/Git
 * checks only; it does not call Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-reviewed-env-status.php [path/to/final-live-inputs.reviewed.env]
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live reviewed env status script must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_reviewed_env_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_reviewed_env_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_reviewed_env_fail( $message, $details );
	}
}

function wp_agent_reviewed_env_read( $path ) {
	wp_agent_reviewed_env_assert( is_file( $path ), 'Reviewed env file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_reviewed_env_assert( is_string( $text ), 'Reviewed env file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_reviewed_env_command( $args, $cwd = null ) {
	$command = '';
	if ( null !== $cwd ) {
		$command .= 'cd ' . escapeshellarg( $cwd ) . ' && ';
	}
	$command .= implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_reviewed_env_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_reviewed_env_secret_disclosed( $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		if ( 1 === preg_match( $pattern, $text ) ) {
			return true;
		}
	}
	return false;
}

function wp_agent_reviewed_env_relative_path( $plugin_dir, $path ) {
	$prefix = rtrim( str_replace( '\\', '/', $plugin_dir ), '/' ) . '/';
	$path   = str_replace( '\\', '/', $path );
	if ( 0 !== strpos( $path, $prefix ) ) {
		return '';
	}
	return substr( $path, strlen( $prefix ) );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_reviewed_env_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$input_path = $argv[1] ?? ( $plugin_dir . '/tests/final-live-inputs.example.env' );
$real_path  = realpath( $input_path );
wp_agent_reviewed_env_assert( is_string( $real_path ) && '' !== $real_path, 'Reviewed env path could not be resolved.', array(
	'path' => $input_path,
) );

$input_text = wp_agent_reviewed_env_read( $real_path );
$example    = realpath( $plugin_dir . '/tests/final-live-inputs.example.env' );
$relative   = wp_agent_reviewed_env_relative_path( $plugin_dir, $real_path );

$path_under_repo = '' !== $relative;
$path_is_example = $example && $real_path === $example;
$matches_reviewed_pattern = (bool) preg_match( '#(^|/)final-live-inputs\.[^/]+\.env$#', str_replace( '\\', '/', $relative ) );

$path_ignored_by_git = false;
$path_tracked_by_git = false;
if ( $path_under_repo ) {
	$ignore = wp_agent_reviewed_env_command( array( 'git', 'check-ignore', '--quiet', '--', $relative ), $plugin_dir );
	$path_ignored_by_git = 0 === (int) $ignore['status'];

	$tracked = wp_agent_reviewed_env_command( array( 'git', 'ls-files', '--error-unmatch', '--', $relative ), $plugin_dir );
	$path_tracked_by_git = 0 === (int) $tracked['status'];
}

$secret_disclosed = wp_agent_reviewed_env_secret_disclosed( $input_text );

$plan_result = wp_agent_reviewed_env_command( array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-command-plan.php',
	$real_path,
) );
wp_agent_reviewed_env_assert( 0 === (int) $plan_result['status'], 'Final live command plan failed for reviewed env.', array(
	'status' => $plan_result['status'],
	'output' => $plan_result['output'],
) );
$command_plan = wp_agent_reviewed_env_json( $plan_result['output'] );
wp_agent_reviewed_env_assert( is_array( $command_plan ), 'Final live command plan should print JSON for reviewed env.', array(
	'output' => $plan_result['output'],
) );

$path_issues = array();
if ( $path_is_example ) {
	$path_issues[] = 'copy tests/final-live-inputs.example.env to an ignored reviewed env file before live execution';
}
if ( ! $path_under_repo ) {
	$path_issues[] = 'reviewed env file must live under the plugin repository so .gitignore can protect it';
}
if ( ! $matches_reviewed_pattern ) {
	$path_issues[] = 'reviewed env file name must match final-live-inputs.*.env';
}
if ( ! $path_ignored_by_git ) {
	$path_issues[] = 'reviewed env file must be ignored by Git';
}
if ( $path_tracked_by_git ) {
	$path_issues[] = 'reviewed env file must not be tracked by Git';
}
if ( $secret_disclosed || true === (bool) ( $command_plan['secret_assignments'] ?? false ) ) {
	$path_issues[] = 'reviewed env file must not contain raw tokens or inline secret assignments';
}

$commands_executable = true === (bool) ( $command_plan['commands_executable'] ?? false );
if ( ! $commands_executable ) {
	$path_issues[] = 'command plan must report commands_executable=true for reviewed env';
}

$command_plan_env_issues = array_values( array_filter(
	$command_plan['blocking_issues'] ?? array(),
	static function ( $issue ) {
		return 0 !== strpos( (string) $issue, 'review packet/env mismatch:' );
	}
) );

$reviewed_env_ready = empty( $path_issues )
	&& $commands_executable
	&& false === (bool) ( $command_plan['token_disclosed'] ?? true )
	&& false === $secret_disclosed;

echo json_encode( array(
	'success'                  => true,
	'contract'                 => 'final_live_reviewed_env_status',
	'input_file'               => $real_path,
	'path_under_repo'          => $path_under_repo,
	'path_relative'            => $relative,
	'path_is_example'          => $path_is_example,
	'path_matches_reviewed_pattern' => $matches_reviewed_pattern,
	'path_ignored_by_git'      => $path_ignored_by_git,
	'path_tracked_by_git'      => $path_tracked_by_git,
	'secret_assignments'       => $secret_disclosed,
	'token_disclosed'          => (bool) ( $command_plan['token_disclosed'] ?? true ),
	'commands_executable'      => $commands_executable,
	'reviewed_env_ready'       => $reviewed_env_ready,
	'blocking_issues'          => array_values( array_unique( array_merge(
		$path_issues,
		$command_plan_env_issues
	) ) ),
	'command_plan'             => array(
		'placeholder_rejected'     => (bool) ( $command_plan['placeholder_rejected'] ?? false ),
		'approval_phrase_rejected' => (bool) ( $command_plan['approval_phrase_rejected'] ?? false ),
		'source_url_rejected'      => (bool) ( $command_plan['source_url_rejected'] ?? false ),
		'official_db_rejected'     => (bool) ( $command_plan['official_db_rejected'] ?? false ),
		'cost_budget_rejected'     => (bool) ( $command_plan['cost_budget_rejected'] ?? false ),
		'artifact_policy_rejected' => (bool) ( $command_plan['artifact_policy_rejected'] ?? false ),
		'soak_bounds_rejected'     => (bool) ( $command_plan['soak_bounds_rejected'] ?? false ),
	),
	'live_network_calls'       => false,
	'ai_gateway_calls'         => false,
	'github_calls'             => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
