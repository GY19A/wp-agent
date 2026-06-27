<?php
/**
 * Host-side final live user input status.
 *
 * Reports whether the remaining user-provided inputs for final live acceptance
 * are present, non-placeholder, reviewed, and safe to use. This script reads
 * local files and runs local PHP/Git checks only; it does not call Docker,
 * GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-user-input-status.php [path/to/final-live-inputs.reviewed.env]
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live user input status script must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_user_input_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_user_input_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_user_input_fail( $message, $details );
	}
}

function wp_agent_user_input_read( $path ) {
	wp_agent_user_input_assert( is_file( $path ), 'Final live input file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_user_input_assert( is_string( $text ), 'Final live input file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_user_input_parse_env( $text ) {
	$values = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
			continue;
		}
		list( $key, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
		$values[ $key ] = trim( $value, "\"'" );
	}
	return $values;
}

function wp_agent_user_input_command( $args, $cwd = null ) {
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

function wp_agent_user_input_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_user_input_run_json( $name, $args ) {
	$result = wp_agent_user_input_command( $args );
	wp_agent_user_input_assert( 0 === (int) $result['status'], $name . ' failed.', array(
		'status' => $result['status'],
		'output' => $result['output'],
	) );
	$json = wp_agent_user_input_json( $result['output'] );
	wp_agent_user_input_assert( is_array( $json ), $name . ' should print JSON.', array(
		'output' => $result['output'],
	) );
	wp_agent_user_input_assert( true === (bool) ( $json['success'] ?? false ), $name . ' should report success=true.', $json );
	return $json;
}

function wp_agent_user_input_secret_disclosed( $text ) {
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

function wp_agent_user_input_positive_number( $values, $key ) {
	$value = trim( (string) ( $values[ $key ] ?? '' ) );
	if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
		return null;
	}
	return (float) $value;
}

function wp_agent_user_input_public_source_url_issue( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return 'public_source_url';
	}
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return 'public_source_url';
	}
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return 'public_source_url';
	}
	$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), "[] \t\n\r\0\x0B." ) );
	if ( '' === $host ) {
		return 'public_source_url';
	}
	if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) || '.local' === substr( $host, -6 ) ) {
		return 'public_source_url_not_localhost_private_or_reserved';
	}
	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$public_ip = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( false === $public_ip ) {
			return 'public_source_url_not_localhost_private_or_reserved';
		}
	}
	return '';
}

function wp_agent_user_input_official_db_issue( $values ) {
	$db_dir       = trim( (string) ( $values['WP_AGENT_OFFICIAL_DB_DIR'] ?? '' ) );
	$normalized   = rtrim( str_replace( '\\', '/', $db_dir ), '/' );
	$root         = '/path/to/wp-agent/database';
	$default      = $root . '/official-mysql';
	$allow_custom = '1' === (string) ( $values['WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR'] ?? '' );

	if ( '' === $normalized ) {
		return 'official_db_dir';
	}
	if ( 0 !== strpos( $normalized, '/' ) ) {
		return 'official_db_dir_absolute_path';
	}
	if ( $root !== $normalized && 0 !== strpos( $normalized . '/', $root . '/' ) ) {
		return 'official_db_dir_under_wp_agent_database';
	}
	if ( $default !== $normalized && ! $allow_custom ) {
		return 'official_db_dir_default_or_throwaway_approval';
	}
	return '';
}

function wp_agent_user_input_is_placeholder( $value, $placeholders ) {
	return in_array( strtolower( trim( (string) $value ) ), $placeholders, true );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_user_input_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$input_path = $argv[1] ?? ( $plugin_dir . '/tests/final-live-inputs.example.env' );
$real_path  = realpath( $input_path );
wp_agent_user_input_assert( is_string( $real_path ) && '' !== $real_path, 'Final live input path could not be resolved.', array(
	'path' => $input_path,
) );

$input_text = wp_agent_user_input_read( $real_path );
$values     = wp_agent_user_input_parse_env( $input_text );

$reviewed_env = wp_agent_user_input_run_json( 'final live reviewed env status', array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-reviewed-env-status.php',
	$real_path,
) );
$command_plan = wp_agent_user_input_run_json( 'final live command plan', array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-command-plan.php',
	$real_path,
) );

$pending_user_inputs = array();
$pending_review_items = array();

$repository = trim( (string) ( $values['WP_AGENT_LIVE_GITHUB_REPOSITORY'] ?? '' ) );
$skill_path = trim( (string) ( $values['WP_AGENT_LIVE_GITHUB_SKILL_PATH'] ?? '' ) );
$github_ref = trim( (string) ( $values['WP_AGENT_LIVE_GITHUB_REF'] ?? '' ) );
$review_policy = trim( (string) ( $values['WP_AGENT_LIVE_GITHUB_REVIEW_POLICY'] ?? '' ) );

if ( '1' !== (string) ( $values['WP_AGENT_LIVE_GITHUB_SKILLS'] ?? '' ) ) {
	$pending_user_inputs[] = 'github_live_flag';
}
if ( '' === $repository || wp_agent_user_input_is_placeholder( $repository, array( 'owner/repo', 'example/repo' ) ) || 1 !== preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository ) ) {
	$pending_user_inputs[] = 'official_skill_store_repository';
}
if ( '' === $skill_path || wp_agent_user_input_is_placeholder( $skill_path, array( 'skills/example', 'skills/default-store-fixture' ) ) || false !== strpos( $skill_path, '..' ) ) {
	$pending_user_inputs[] = 'official_skill_store_skill_path';
}
if ( '' === $github_ref ) {
	$pending_user_inputs[] = 'official_skill_store_ref';
}
if ( ! in_array( $review_policy, array( 'quarantine', 'activate', 'activate_pin' ), true ) ) {
	$pending_user_inputs[] = 'official_skill_store_review_policy';
}

$github_inputs_ready = ! array_intersect( $pending_user_inputs, array(
	'github_live_flag',
	'official_skill_store_repository',
	'official_skill_store_skill_path',
	'official_skill_store_ref',
	'official_skill_store_review_policy',
) );

if ( '1' !== (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON'] ?? '' ) ) {
	$pending_user_inputs[] = 'soak_live_flag';
}
if ( '1' !== (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED'] ?? '' ) ) {
	$pending_review_items[] = 'multi_hour_soak_approval_flag';
}
if ( 'approve-multi-hour-soak' !== trim( (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE'] ?? '' ) ) ) {
	$pending_review_items[] = 'multi_hour_soak_approval_phrase';
}
if ( null === wp_agent_user_input_positive_number( $values, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' ) ) {
	$pending_review_items[] = 'api_cost_budget_usd';
}
if ( ! in_array( (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY'] ?? '' ), array( 'drafts_journal_usage', 'drafts_journal_usage_media' ), true ) ) {
	$pending_review_items[] = 'artifact_policy';
}
$source_url_issue = wp_agent_user_input_public_source_url_issue( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL'] ?? '' );
if ( '' !== $source_url_issue ) {
	$pending_user_inputs[] = $source_url_issue;
}
$db_issue = wp_agent_user_input_official_db_issue( $values );
if ( '' !== $db_issue ) {
	$pending_user_inputs[] = $db_issue;
}

$runs = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'] ?? 0 );
$timeout = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'] ?? 0 );
$soak_seconds = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'] ?? 0 );
$sample_interval = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL'] ?? 0 );
$usage_rows = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS'] ?? 0 );
if ( $runs <= 0 || $runs > 12 ) {
	$pending_review_items[] = 'soak_run_count_bounds';
}
if ( $timeout <= 0 || $timeout > 28800 ) {
	$pending_review_items[] = 'soak_timeout_bounds';
}
if ( $soak_seconds < 7200 || $soak_seconds > 28800 ) {
	$pending_review_items[] = 'soak_seconds_bounds';
}
if ( $timeout > 0 && $soak_seconds > $timeout ) {
	$pending_review_items[] = 'soak_timeout_covers_soak_seconds';
}
if ( $sample_interval <= 0 || $sample_interval > 3600 ) {
	$pending_review_items[] = 'soak_sample_interval_bounds';
}
if ( $usage_rows <= 0 ) {
	$pending_review_items[] = 'max_usage_rows_guard';
}

$soak_inputs_ready = ! array_intersect( $pending_user_inputs, array(
	'soak_live_flag',
	'public_source_url',
	'public_source_url_not_localhost_private_or_reserved',
	'official_db_dir',
	'official_db_dir_absolute_path',
	'official_db_dir_under_wp_agent_database',
	'official_db_dir_default_or_throwaway_approval',
) ) && ! array_intersect( $pending_review_items, array(
	'multi_hour_soak_approval_flag',
	'multi_hour_soak_approval_phrase',
	'api_cost_budget_usd',
	'artifact_policy',
	'soak_run_count_bounds',
	'soak_timeout_bounds',
	'soak_seconds_bounds',
	'soak_timeout_covers_soak_seconds',
	'soak_sample_interval_bounds',
	'max_usage_rows_guard',
) );

$secret_assignments = wp_agent_user_input_secret_disclosed( $input_text )
	|| true === (bool) ( $reviewed_env['secret_assignments'] ?? false )
	|| true === (bool) ( $command_plan['secret_assignments'] ?? false );
if ( $secret_assignments ) {
	$pending_user_inputs[] = 'remove_raw_tokens_or_inline_secret_assignments';
}
if ( true !== (bool) ( $reviewed_env['reviewed_env_ready'] ?? false ) ) {
	$pending_user_inputs[] = 'reviewed_env_file';
}
if ( true !== (bool) ( $command_plan['commands_executable'] ?? false ) ) {
	$pending_user_inputs[] = 'commands_executable_true';
}

$pending_user_inputs = array_values( array_unique( $pending_user_inputs ) );
$pending_review_items = array_values( array_unique( $pending_review_items ) );
$command_plan_input_issues = array_values( array_filter(
	$command_plan['blocking_issues'] ?? array(),
	static function ( $issue ) {
		return 0 !== strpos( (string) $issue, 'review packet/env mismatch:' );
	}
) );

$user_input_ready = $github_inputs_ready
	&& $soak_inputs_ready
	&& true === (bool) ( $reviewed_env['reviewed_env_ready'] ?? false )
	&& true === (bool) ( $command_plan['commands_executable'] ?? false )
	&& ! $secret_assignments;

echo json_encode( array(
	'success'                    => true,
	'contract'                   => 'final_live_user_input_status',
	'input_file'                 => $real_path,
	'user_input_ready'           => $user_input_ready,
	'github_inputs_ready'        => $github_inputs_ready,
	'soak_inputs_ready'          => $soak_inputs_ready,
	'reviewed_env_ready'         => (bool) ( $reviewed_env['reviewed_env_ready'] ?? false ),
	'commands_executable'        => (bool) ( $command_plan['commands_executable'] ?? false ),
	'path_ignored_by_git'        => (bool) ( $reviewed_env['path_ignored_by_git'] ?? false ),
	'path_tracked_by_git'        => (bool) ( $reviewed_env['path_tracked_by_git'] ?? true ),
	'secret_assignments'         => $secret_assignments,
	'token_disclosed'            => (bool) ( $command_plan['token_disclosed'] ?? true ),
	'pending_user_inputs'        => $pending_user_inputs,
	'pending_review_items'       => $pending_review_items,
	'blocking_issues'            => array_values( array_unique( array_merge(
		$pending_user_inputs,
		$pending_review_items,
		$reviewed_env['blocking_issues'] ?? array(),
		$command_plan_input_issues
	) ) ),
	'github'                     => array(
		'repository_placeholder' => wp_agent_user_input_is_placeholder( $repository, array( 'owner/repo', 'example/repo' ) ),
		'skill_path_placeholder' => wp_agent_user_input_is_placeholder( $skill_path, array( 'skills/example', 'skills/default-store-fixture' ) ),
		'ref_present'            => '' !== $github_ref,
		'review_policy_valid'    => in_array( $review_policy, array( 'quarantine', 'activate', 'activate_pin' ), true ),
		'token_assignment_present' => false !== strpos( $input_text, 'WP_AGENT_LIVE_GITHUB_TOKEN=' ),
	),
	'soak'                       => array(
		'approved_flag'          => '1' === (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED'] ?? '' ),
		'approval_phrase_ready'  => 'approve-multi-hour-soak' === trim( (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE'] ?? '' ) ),
		'cost_budget_usd'        => wp_agent_user_input_positive_number( $values, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' ),
		'artifact_policy'        => (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY'] ?? '' ),
		'source_url_issue'       => $source_url_issue,
		'official_db_issue'      => $db_issue,
		'runs'                   => $runs,
		'timeout'                => $timeout,
		'soak_seconds'           => $soak_seconds,
		'sample_interval'        => $sample_interval,
		'max_usage_rows'         => $usage_rows,
	),
	'live_network_calls'         => false,
	'ai_gateway_calls'           => false,
	'github_calls'               => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
