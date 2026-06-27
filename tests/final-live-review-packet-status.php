<?php
/**
 * Host-side final live review packet status.
 *
 * Verifies a completed final-live review packet is local-only, non-template,
 * secret-free, and has the human approval fields needed before #6/#9 live
 * acceptance. This script reads local files and Git state only; it does not
 * call Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-review-packet-status.php [path/to/final-live-review-packet-YYYYMMDD.md]
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live review packet status script must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_review_packet_status_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_review_packet_status_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_review_packet_status_fail( $message, $details );
	}
}

function wp_agent_review_packet_status_read( $path ) {
	wp_agent_review_packet_status_assert( is_file( $path ), 'Review packet file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_review_packet_status_assert( is_string( $text ), 'Review packet file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_review_packet_status_command( $args, $cwd = null ) {
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

function wp_agent_review_packet_status_relative_path( $plugin_dir, $path ) {
	$prefix = rtrim( str_replace( '\\', '/', $plugin_dir ), '/' ) . '/';
	$path   = str_replace( '\\', '/', $path );
	if ( 0 !== strpos( $path, $prefix ) ) {
		return '';
	}
	return substr( $path, strlen( $prefix ) );
}

function wp_agent_review_packet_status_secret_disclosed( $text ) {
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

function wp_agent_review_packet_status_fields( $text ) {
	$fields = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		if ( 1 === preg_match( '/^-\s*([^:]+):\s*(.*)$/', trim( $line ), $matches ) ) {
			$fields[ trim( $matches[1] ) ] = trim( $matches[2] );
		}
	}
	return $fields;
}

function wp_agent_review_packet_status_has_value( $fields, $label ) {
	return array_key_exists( $label, $fields ) && '' !== trim( (string) $fields[ $label ] );
}

function wp_agent_review_packet_status_positive_number( $fields, $label ) {
	$value = trim( (string) ( $fields[ $label ] ?? '' ) );
	if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
		return null;
	}
	return (float) $value;
}

function wp_agent_review_packet_status_public_url_issue( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return 'source_url_public_http';
	}
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return 'source_url_public_http';
	}
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return 'source_url_public_http';
	}
	$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), "[] \t\n\r\0\x0B." ) );
	if ( '' === $host ) {
		return 'source_url_public_http';
	}
	if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) || '.local' === substr( $host, -6 ) ) {
		return 'source_url_not_localhost_private_or_reserved';
	}
	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$public_ip = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( false === $public_ip ) {
			return 'source_url_not_localhost_private_or_reserved';
		}
	}
	return '';
}

function wp_agent_review_packet_status_is_placeholder( $value, $placeholders ) {
	return in_array( strtolower( trim( (string) $value ) ), $placeholders, true );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_review_packet_status_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$input_path = $argv[1] ?? ( $plugin_dir . '/tests/final-live-review-packet-template.md' );
$real_path  = realpath( $input_path );
wp_agent_review_packet_status_assert( is_string( $real_path ) && '' !== $real_path, 'Review packet path could not be resolved.', array(
	'path' => $input_path,
) );

$packet_text = wp_agent_review_packet_status_read( $real_path );
$template    = realpath( $plugin_dir . '/tests/final-live-review-packet-template.md' );
$relative    = wp_agent_review_packet_status_relative_path( $plugin_dir, $real_path );

$path_under_repo = '' !== $relative;
$path_is_template = $template && $real_path === $template;
$matches_packet_pattern = (bool) preg_match( '#(^|/)final-live-review-packet-[^/]+\.md$#', str_replace( '\\', '/', $relative ) );

$path_ignored_by_git = false;
$path_tracked_by_git = false;
if ( $path_under_repo ) {
	$ignore = wp_agent_review_packet_status_command( array( 'git', 'check-ignore', '--quiet', '--', $relative ), $plugin_dir );
	$path_ignored_by_git = 0 === (int) $ignore['status'];

	$tracked = wp_agent_review_packet_status_command( array( 'git', 'ls-files', '--error-unmatch', '--', $relative ), $plugin_dir );
	$path_tracked_by_git = 0 === (int) $tracked['status'];
}

$fields = wp_agent_review_packet_status_fields( $packet_text );
$missing_fields = array();
foreach ( array(
	'Reviewer',
	'Review date',
	'Approved live window',
	'Approved API cost budget, `cost_budget_usd`',
	'Approved artifact policy',
	'User-approved official Skill Store coordinates',
	'Repository',
	'Skill path',
	'Ref',
	'Review policy',
	'Activation/pin requested',
	'GitHub token source',
	'Run count',
	'Timeout seconds',
	'Soak seconds',
	'Sample interval',
	'Max usage rows',
	'Source URL public HTTP(S)',
	'Expected source scope',
	'Throwaway database exception',
	'cleanup/rollback policy',
	'Temporary schedule handling',
	'Temporary Skill handling',
	'Daemon final state',
	'Draft/media retention',
	'Command plan evidence',
	'Archive redaction report',
) as $label ) {
	if ( ! wp_agent_review_packet_status_has_value( $fields, $label ) ) {
		$missing_fields[] = $label;
	}
}

$invalid_fields = array();
$repository = (string) ( $fields['Repository'] ?? '' );
if ( '' === trim( $repository ) || wp_agent_review_packet_status_is_placeholder( $repository, array( 'owner/repo', 'example/repo' ) ) || 1 !== preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', trim( $repository ) ) ) {
	$invalid_fields[] = 'Repository';
}
$skill_path = (string) ( $fields['Skill path'] ?? '' );
if ( '' === trim( $skill_path ) || wp_agent_review_packet_status_is_placeholder( $skill_path, array( 'skills/example', 'skills/default-store-fixture' ) ) || false !== strpos( $skill_path, '..' ) ) {
	$invalid_fields[] = 'Skill path';
}
if ( ! in_array( (string) ( $fields['Review policy'] ?? '' ), array( 'quarantine', 'activate', 'activate_pin' ), true ) ) {
	$invalid_fields[] = 'Review policy';
}
if ( ! in_array( (string) ( $fields['Approved artifact policy'] ?? '' ), array( 'drafts_journal_usage', 'drafts_journal_usage_media' ), true ) ) {
	$invalid_fields[] = 'Approved artifact policy';
}
if ( ! in_array( (string) ( $fields['GitHub token source'] ?? '' ), array( 'shell', 'WordPress Settings', 'ignored env' ), true ) ) {
	$invalid_fields[] = 'GitHub token source';
}
if ( null === wp_agent_review_packet_status_positive_number( $fields, 'Approved API cost budget, `cost_budget_usd`' ) ) {
	$invalid_fields[] = 'Approved API cost budget, `cost_budget_usd`';
}
$runs = (int) ( $fields['Run count'] ?? 0 );
if ( $runs <= 0 || $runs > 12 ) {
	$invalid_fields[] = 'Run count';
}
$timeout = (int) ( $fields['Timeout seconds'] ?? 0 );
if ( $timeout <= 0 || $timeout > 28800 ) {
	$invalid_fields[] = 'Timeout seconds';
}
$soak_seconds = (int) ( $fields['Soak seconds'] ?? 0 );
if ( $soak_seconds < 7200 || $soak_seconds > 28800 ) {
	$invalid_fields[] = 'Soak seconds';
}
if ( $timeout > 0 && $soak_seconds > $timeout ) {
	$invalid_fields[] = 'Timeout seconds must cover soak seconds';
}
$sample_interval = (int) ( $fields['Sample interval'] ?? 0 );
if ( $sample_interval <= 0 || $sample_interval > 3600 ) {
	$invalid_fields[] = 'Sample interval';
}
$usage_rows = (int) ( $fields['Max usage rows'] ?? 0 );
if ( $usage_rows <= 0 ) {
	$invalid_fields[] = 'Max usage rows';
}
$source_issue = wp_agent_review_packet_status_public_url_issue( $fields['Source URL public HTTP(S)'] ?? '' );
if ( '' !== $source_issue ) {
	$invalid_fields[] = $source_issue;
}
$archive_redaction_report = (string) ( $fields['Archive redaction report'] ?? '' );
if ( 1 !== preg_match( '/\bfinal-live-archive-redaction-(?:YYYYMMDD|\d{8})\.md\b/', $archive_redaction_report ) ) {
	$invalid_fields[] = 'Archive redaction report';
}
$command_plan_evidence = (string) ( $fields['Command plan evidence'] ?? '' );
if ( 1 !== preg_match( '/\bfinal-live-command-plan-(?:YYYYMMDD|\d{8})\.json\b/', $command_plan_evidence ) ) {
	$invalid_fields[] = 'Command plan evidence';
}
if ( false === strpos( $packet_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak' ) ) {
	$invalid_fields[] = 'approval phrase marker';
}
if ( false === strpos( $packet_text, 'WP_AGENT_OFFICIAL_DB_DIR=/path/to/wp-agent/database/official-mysql' ) ) {
	$invalid_fields[] = 'official database marker';
}
if ( false === strpos( $packet_text, 'completion_ready=false' ) ) {
	$invalid_fields[] = 'completion expectation marker';
}
if ( false === strpos( $packet_text, 'final-live-command-plan' ) ) {
	$invalid_fields[] = 'command plan archive marker';
}

$path_issues = array();
if ( $path_is_template ) {
	$path_issues[] = 'copy tests/final-live-review-packet-template.md to an ignored completed packet before live execution';
}
if ( ! $path_under_repo ) {
	$path_issues[] = 'completed review packet must live under the plugin repository so .gitignore can protect it';
}
if ( ! $matches_packet_pattern ) {
	$path_issues[] = 'completed review packet name must match final-live-review-packet-*.md';
}
if ( ! $path_ignored_by_git ) {
	$path_issues[] = 'completed review packet must be ignored by Git';
}
if ( $path_tracked_by_git ) {
	$path_issues[] = 'completed review packet must not be tracked by Git';
}

$secret_assignments = wp_agent_review_packet_status_secret_disclosed( $packet_text );
if ( $secret_assignments ) {
	$path_issues[] = 'completed review packet must not contain raw tokens or inline secret assignments';
}

$packet_ready = empty( $path_issues )
	&& empty( $missing_fields )
	&& empty( $invalid_fields )
	&& ! $secret_assignments;

echo json_encode( array(
	'success'                  => true,
	'contract'                 => 'final_live_review_packet_status',
	'input_file'               => $real_path,
	'packet_ready'             => $packet_ready,
	'path_under_repo'          => $path_under_repo,
	'path_relative'            => $relative,
	'path_is_template'         => $path_is_template,
	'path_matches_packet_pattern' => $matches_packet_pattern,
	'path_ignored_by_git'      => $path_ignored_by_git,
	'path_tracked_by_git'      => $path_tracked_by_git,
	'missing_fields'           => $missing_fields,
	'invalid_fields'           => array_values( array_unique( $invalid_fields ) ),
	'secret_assignments'       => $secret_assignments,
	'blocking_issues'          => array_values( array_unique( array_merge(
		$path_issues,
		$missing_fields,
		$invalid_fields
	) ) ),
	'review_summary'           => array(
		'repository'            => '' === trim( $repository ) ? '' : '[provided]',
		'skill_path'            => '' === trim( $skill_path ) ? '' : '[provided]',
		'ref_present'           => wp_agent_review_packet_status_has_value( $fields, 'Ref' ),
		'review_policy'         => (string) ( $fields['Review policy'] ?? '' ),
		'artifact_policy'       => (string) ( $fields['Approved artifact policy'] ?? '' ),
		'cost_budget_present'   => null !== wp_agent_review_packet_status_positive_number( $fields, 'Approved API cost budget, `cost_budget_usd`' ),
		'source_url_public'     => '' === $source_issue,
		'command_plan_evidence_present' => 1 === preg_match( '/\bfinal-live-command-plan-(?:YYYYMMDD|\d{8})\.json\b/', $command_plan_evidence ),
		'archive_redaction_report_present' => 1 === preg_match( '/\bfinal-live-archive-redaction-(?:YYYYMMDD|\d{8})\.md\b/', $archive_redaction_report ),
		'cleanup_policy_present' => wp_agent_review_packet_status_has_value( $fields, 'cleanup/rollback policy' ),
	),
	'live_network_calls'       => false,
	'ai_gateway_calls'         => false,
	'github_calls'             => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
