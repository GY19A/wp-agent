<?php
/**
 * Host-side final live command plan dry-run.
 *
 * Prints the reviewed command order for final live acceptance without running
 * Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-command-plan.php [path/to/final-live-inputs.env] [path/to/final-live-review-packet-YYYYMMDD.md]
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live command plan must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_command_plan_read( $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "Input file is missing: " . $path . "\n" );
		exit( 1 );
	}
	$text = file_get_contents( $path );
	if ( ! is_string( $text ) ) {
		fwrite( STDERR, "Input file could not be read: " . $path . "\n" );
		exit( 1 );
	}
	return $text;
}

function wp_agent_command_plan_parse_env( $text ) {
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

function wp_agent_command_plan_parse_packet_fields( $text ) {
	$fields = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		if ( 1 === preg_match( '/^-\s*([^:]+):\s*(.*)$/', trim( $line ), $matches ) ) {
			$fields[ trim( $matches[1] ) ] = trim( $matches[2] );
		}
	}
	return $fields;
}

function wp_agent_command_plan_clean_packet_value( $value ) {
	$value = trim( (string) $value );
	if ( strlen( $value ) >= 2 && '`' === $value[0] && '`' === substr( $value, -1 ) ) {
		$value = trim( $value, '`' );
	}
	return trim( $value );
}

function wp_agent_command_plan_packet_assignment( $text, $key ) {
	if ( 1 === preg_match( '/\b' . preg_quote( $key, '/' ) . '\s*=\s*([^\s`]+)/', $text, $matches ) ) {
		return trim( $matches[1] );
	}
	return '';
}

function wp_agent_command_plan_is_secret_key( $key ) {
	return (bool) preg_match( '/(?:TOKEN|KEY|SECRET|PASSWORD)/i', (string) $key );
}

function wp_agent_command_plan_secret_disclosed( $text ) {
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

function wp_agent_command_plan_positive_number( $values, $key ) {
	$value = trim( (string) ( $values[ $key ] ?? '' ) );
	if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
		return null;
	}
	return (float) $value;
}

function wp_agent_command_plan_compare_packet_env_value( &$mismatches, $name, $env_value, $packet_value, $type = 'string' ) {
	$env_value    = trim( (string) $env_value );
	$packet_value = wp_agent_command_plan_clean_packet_value( $packet_value );

	if ( 'number' === $type ) {
		if ( '' === $env_value || '' === $packet_value || ! is_numeric( $env_value ) || ! is_numeric( $packet_value ) || (float) $env_value !== (float) $packet_value ) {
			$mismatches[] = $name;
		}
		return;
	}

	if ( 'int' === $type ) {
		if ( '' === $env_value || '' === $packet_value || ! is_numeric( $env_value ) || ! is_numeric( $packet_value ) || (int) $env_value !== (int) $packet_value ) {
			$mismatches[] = $name;
		}
		return;
	}

	if ( $env_value !== $packet_value ) {
		$mismatches[] = $name;
	}
}

function wp_agent_command_plan_public_source_url_issue( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL';
	}
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL';
	}
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL';
	}
	$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), "[] \t\n\r\0\x0B." ) );
	if ( '' === $host ) {
		return 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL';
	}
	if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) || '.local' === substr( $host, -6 ) ) {
		return 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL must not be localhost/private/reserved';
	}
	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$public_ip = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( false === $public_ip ) {
			return 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL must not be localhost/private/reserved';
		}
	}
	return '';
}

function wp_agent_command_plan_official_db_issue( $values ) {
	$db_dir       = trim( (string) ( $values['WP_AGENT_OFFICIAL_DB_DIR'] ?? '' ) );
	$normalized   = rtrim( str_replace( '\\', '/', $db_dir ), '/' );
	$root         = '/path/to/wp-agent/database';
	$default      = $root . '/official-mysql';
	$allow_custom = '1' === (string) ( $values['WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR'] ?? '' );

	if ( '' === $normalized ) {
		return 'WP_AGENT_OFFICIAL_DB_DIR';
	}
	if ( 0 !== strpos( $normalized, '/' ) ) {
		return 'WP_AGENT_OFFICIAL_DB_DIR must be an absolute host path';
	}
	if ( $root !== $normalized && 0 !== strpos( $normalized . '/', $root . '/' ) ) {
		return 'WP_AGENT_OFFICIAL_DB_DIR must stay under /path/to/wp-agent/database';
	}
	if ( $default !== $normalized && ! $allow_custom ) {
		return 'WP_AGENT_OFFICIAL_DB_DIR must use official-mysql for final acceptance, or explicitly set WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR=1 for an approved throwaway database';
	}
	return '';
}

function wp_agent_command_plan_env_flags( $keys ) {
	$flags = array();
	foreach ( $keys as $key ) {
		$flags[] = '-e ' . $key;
	}
	return implode( ' ', $flags );
}

function wp_agent_command_plan_step( $id, $description, $command, $writes_artifact = false ) {
	return array(
		'id'              => $id,
		'description'     => $description,
		'command'         => $command,
		'writes_artifact' => $writes_artifact,
		'executes_live'   => in_array( $id, array( 'strict_preflight', 'github_live', 'editorial_daemon_soak' ), true ),
	);
}

function wp_agent_command_plan_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_command_plan_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
if ( ! $plugin_dir || ! is_dir( $plugin_dir ) ) {
	fwrite( STDERR, "Plugin directory could not be resolved.\n" );
	exit( 1 );
}

$input_path = $argv[1] ?? ( $plugin_dir . '/tests/final-live-inputs.example.env' );
$input_path = realpath( $input_path ) ?: $input_path;
$packet_path = $argv[2] ?? ( $plugin_dir . '/tests/final-live-review-packet-template.md' );
$packet_path = realpath( $packet_path ) ?: $packet_path;
$input_text = wp_agent_command_plan_read( $input_path );
$values     = wp_agent_command_plan_parse_env( $input_text );
$packet_text = wp_agent_command_plan_read( $packet_path );
$packet_fields = wp_agent_command_plan_parse_packet_fields( $packet_text );

$review_packet_result = wp_agent_command_plan_command( array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-review-packet-status.php',
	$packet_path,
) );
$review_packet = wp_agent_command_plan_json( $review_packet_result['output'] );
if ( 0 !== (int) $review_packet_result['status'] || ! is_array( $review_packet ) || true !== (bool) ( $review_packet['success'] ?? false ) ) {
	$review_packet = array(
		'success'         => false,
		'packet_ready'    => false,
		'blocking_issues' => array( 'final live review packet status could not be read' ),
	);
}
$review_packet_ready = true === (bool) ( $review_packet['packet_ready'] ?? false );
$review_packet_issues = array_values( array_unique( $review_packet['blocking_issues'] ?? array() ) );
$review_packet_env_mismatches = array();
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Repository', $values['WP_AGENT_LIVE_GITHUB_REPOSITORY'] ?? '', $packet_fields['Repository'] ?? '' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Skill path', $values['WP_AGENT_LIVE_GITHUB_SKILL_PATH'] ?? '', $packet_fields['Skill path'] ?? '' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Ref', $values['WP_AGENT_LIVE_GITHUB_REF'] ?? '', $packet_fields['Ref'] ?? '' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Review policy', $values['WP_AGENT_LIVE_GITHUB_REVIEW_POLICY'] ?? '', $packet_fields['Review policy'] ?? '' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Approved API cost budget, `cost_budget_usd`', $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD'] ?? '', $packet_fields['Approved API cost budget, `cost_budget_usd`'] ?? '', 'number' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Approved artifact policy', $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY'] ?? '', $packet_fields['Approved artifact policy'] ?? '' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Run count', $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'] ?? '', $packet_fields['Run count'] ?? '', 'int' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Timeout seconds', $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'] ?? '', $packet_fields['Timeout seconds'] ?? '', 'int' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Soak seconds', $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'] ?? '', $packet_fields['Soak seconds'] ?? '', 'int' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Sample interval', $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL'] ?? '', $packet_fields['Sample interval'] ?? '', 'int' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Max usage rows', $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS'] ?? '', $packet_fields['Max usage rows'] ?? '', 'int' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'Source URL public HTTP(S)', $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL'] ?? '', $packet_fields['Source URL public HTTP(S)'] ?? '' );
wp_agent_command_plan_compare_packet_env_value( $review_packet_env_mismatches, 'WP_AGENT_OFFICIAL_DB_DIR', $values['WP_AGENT_OFFICIAL_DB_DIR'] ?? '', wp_agent_command_plan_packet_assignment( $packet_text, 'WP_AGENT_OFFICIAL_DB_DIR' ) );
$review_packet_env_mismatches = array_values( array_unique( $review_packet_env_mismatches ) );
$review_packet_env_consistent = empty( $review_packet_env_mismatches );

$required_keys = array(
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE',
	'WP_AGENT_FINAL_PREFLIGHT_STRICT',
	'WP_AGENT_LIVE_GITHUB_SKILLS',
	'WP_AGENT_LIVE_GITHUB_REPOSITORY',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
	'WP_AGENT_LIVE_GITHUB_REF',
	'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
	'WP_AGENT_OFFICIAL_DB_DIR',
);

$missing = array();
foreach ( $required_keys as $key ) {
	if ( ! array_key_exists( $key, $values ) || '' === trim( (string) $values[ $key ] ) ) {
		$missing[] = $key;
	}
}

$repository          = (string) ( $values['WP_AGENT_LIVE_GITHUB_REPOSITORY'] ?? '' );
$skill_path          = (string) ( $values['WP_AGENT_LIVE_GITHUB_SKILL_PATH'] ?? '' );
$placeholder_reasons = array();
if ( in_array( strtolower( trim( $repository ) ), array( 'owner/repo', 'example/repo' ), true ) ) {
	$placeholder_reasons[] = 'replace placeholder GitHub repository/path with official Skill Store coordinates';
}
if ( in_array( strtolower( trim( $skill_path ) ), array( 'skills/example', 'skills/default-store-fixture' ), true ) ) {
	$placeholder_reasons[] = 'replace placeholder GitHub repository/path with official Skill Store coordinates';
}
$placeholder_reasons = array_values( array_unique( $placeholder_reasons ) );

$approval_phrase = trim( (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE'] ?? '' ) );
$approval_reasons = array();
if ( 'approve-multi-hour-soak' !== $approval_phrase ) {
	$approval_reasons[] = 'set WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak after reviewing the live soak parameters';
}

$cost_budget_reasons = array();
if ( null === wp_agent_command_plan_positive_number( $values, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' ) ) {
	$cost_budget_reasons[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD';
}

$artifact_policy_reasons = array();
if ( ! in_array( (string) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY'] ?? '' ), array( 'drafts_journal_usage', 'drafts_journal_usage_media' ), true ) ) {
	$artifact_policy_reasons[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY';
}

$source_url_reasons = array();
$source_url_issue   = wp_agent_command_plan_public_source_url_issue( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL'] ?? '' );
if ( '' !== $source_url_issue ) {
	$source_url_reasons[] = $source_url_issue;
}

$official_db_reasons = array();
$official_db_issue   = wp_agent_command_plan_official_db_issue( $values );
if ( '' !== $official_db_issue ) {
	$official_db_reasons[] = $official_db_issue;
}

$soak_bound_reasons = array();
$runs               = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'] ?? 0 );
$timeout            = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'] ?? 0 );
$soak_seconds       = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'] ?? 0 );
$usage_rows         = (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS'] ?? 0 );
if ( $runs <= 0 || $runs > 12 ) {
	$soak_bound_reasons[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS must be between 1 and 12';
}
if ( $timeout <= 0 || $timeout > 28800 ) {
	$soak_bound_reasons[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT must be between 1 and 28800';
}
if ( $soak_seconds < 7200 || $soak_seconds > 28800 ) {
	$soak_bound_reasons[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS must be between 7200 and 28800';
}
if ( $timeout > 0 && $soak_seconds > $timeout ) {
	$soak_bound_reasons[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT must be greater than or equal to soak seconds';
}
if ( $usage_rows <= 0 ) {
	$soak_bound_reasons[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS';
}

$secret_disclosed = wp_agent_command_plan_secret_disclosed( $input_text );
$ready            = empty( $missing )
	&& empty( $placeholder_reasons )
	&& empty( $approval_reasons )
	&& empty( $cost_budget_reasons )
	&& empty( $artifact_policy_reasons )
	&& empty( $source_url_reasons )
	&& empty( $official_db_reasons )
	&& empty( $soak_bound_reasons )
	&& ! $secret_disclosed;

$max_lifetime = max( 600, min( 32400, max( $timeout, $soak_seconds ) + 3600 ) );

$compose           = 'docker compose -p wp-agent-official -f docker-compose.official.yml --profile cli run --rm -T';
$archive_dir       = '/path/to/wp-agent/design/test-logs';
$archive_date      = '$(date +%Y%m%d)';
$github_archive    = $archive_dir . '/final-live-github-skill-store-' . $archive_date . '.json';
$soak_archive      = $archive_dir . '/final-live-editorial-daemon-soak-' . $archive_date . '.json';
$command_plan_archive = $archive_dir . '/final-live-command-plan-' . $archive_date . '.json';
$ux_archive        = $archive_dir . '/ui-playwright-evidence-contract-' . $archive_date . '.md';
$redaction_archive = $archive_dir . '/final-live-archive-redaction-' . $archive_date . '.md';
$github_env_keys   = array(
	'WP_AGENT_LIVE_GITHUB_SKILLS',
	'WP_AGENT_LIVE_GITHUB_REPOSITORY',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
	'WP_AGENT_LIVE_GITHUB_REF',
	'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
);
$soak_env_keys = array(
	'WP_AGENT_LIVE_EDITORIAL_DAEMON',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
	'WP_AGENT_OFFICIAL_DB_DIR',
);
$preflight_env_keys = array_merge(
	array(
		'WP_AGENT_FINAL_PREFLIGHT_STRICT',
		'WP_AGENT_FINAL_PREFLIGHT_SCOPE',
		'WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR',
	),
	$github_env_keys,
	$soak_env_keys
);

$commands = array(
	wp_agent_command_plan_step(
		'no_live_aggregate',
		'Run all host-side no-live gates before setting live flags.',
		'php tests/final-no-live-acceptance-contract.php'
	),
	wp_agent_command_plan_step(
		'review_packet_status',
		'Verify the completed human approval packet is ignored, untracked, secret-free, and packet_ready=true before live execution.',
		'php tests/final-live-review-packet-status.php ' . escapeshellarg( $packet_path )
	),
	wp_agent_command_plan_step(
		'command_plan',
		'Regenerate this reviewed dry-run plan after replacing placeholders and approving the review packet.',
		'php tests/final-live-command-plan.php ' . escapeshellarg( $input_path ) . ' ' . escapeshellarg( $packet_path ) . ' | tee ' . $command_plan_archive,
		true
	),
	wp_agent_command_plan_step(
		'start_resident_daemon',
		'Start the resident PHP daemon inside the official WordPress container.',
		'docker exec -u www-data -d wp-agent-official-wordpress-1 php /var/www/html/wp-content/plugins/wp-agent/bin/agentd.php --wp-load=/var/www/html/wp-load.php --max-children=1 --idle-sleep=1 --max-lifetime=' . $max_lifetime . ' --memory-soft-limit=512 --memory-hard-limit=768'
	),
	wp_agent_command_plan_step(
		'strict_preflight',
		'Run strict final preflight with reviewed env keys before any live GitHub/API work.',
		$compose . ' ' . wp_agent_command_plan_env_flags( $preflight_env_keys ) . ' wpcli wp eval-file wp-content/plugins/wp-agent/tests/final-acceptance-preflight.php --allow-root'
	),
	wp_agent_command_plan_step(
		'github_live',
		'Run official GitHub Skill Store live acceptance and archive JSON output.',
		$compose . ' ' . wp_agent_command_plan_env_flags( $github_env_keys ) . ' wpcli wp eval-file wp-content/plugins/wp-agent/tests/live-github-skill-store.php --allow-root | tee ' . $github_archive,
		true
	),
	wp_agent_command_plan_step(
		'editorial_daemon_soak',
		'Run user-approved multi-hour editorial daemon soak and archive JSON output.',
		$compose . ' ' . wp_agent_command_plan_env_flags( $soak_env_keys ) . ' wpcli wp eval-file wp-content/plugins/wp-agent/tests/live-editorial-daemon-soak.php --allow-root | tee ' . $soak_archive,
		true
	),
	wp_agent_command_plan_step(
		'stop_daemon',
		'Stop the resident daemon after live soak unless the approved artifact policy says to keep it running.',
		$compose . ' wpcli wp wp-agent daemon stop --allow-root'
	),
	wp_agent_command_plan_step(
		'git_hygiene',
		'Verify local-only Git state and archive the output before building the final manifest.',
		'php tests/git-hygiene-contract.php'
	),
	wp_agent_command_plan_step(
		'ux_evidence_validation',
		'Validate the current UX evidence artifact before building the final manifest.',
		'php tests/ui-playwright-evidence-contract.php | tee ' . $ux_archive,
		true
	),
	wp_agent_command_plan_step(
		'acceptance_summary',
		'Write and archive the final acceptance summary after reviewing no-live, GitHub, soak, Git hygiene, and UX evidence artifacts.',
		"printf '%s\n' 'Write /path/to/wp-agent/design/test-logs/final-live-acceptance-summary-YYYYMMDD.md from tests/final-live-report-template.md; required markers: /path/to/wp-agent/database/official-mysql remote_push=false token_disclosed=false completion_ready=true packet_ready=true ready_for_live_execution=true review_packet_ready=true review_packet_env_consistent=true ui-playwright-evidence-contract chat_queue_status_playwright=true chat_stop_availability_playwright=true composer_unlocked_guard=true final-live-command-plan final-live-github-skill-store final-live-editorial-daemon-soak final-live-archive-redaction #6 #9; archive final-live-archive-redaction-YYYYMMDD.md after php tests/final-live-archive-redaction-contract.php passes'",
		true
	),
	wp_agent_command_plan_step(
		'artifact_manifest_build',
		'Generate the final live artifact manifest from reviewed local archives after the summary is written.',
		'WP_AGENT_FINAL_LIVE_MANIFEST_WRITE=1 php tests/final-live-artifact-manifest-build.php ' . escapeshellarg( $input_path ) . ' /path/to/wp-agent/design/test-logs ' . escapeshellarg( $packet_path )
	),
	wp_agent_command_plan_step(
		'completion_gate',
		'Verify archived final live artifacts are schema-valid before marking goals complete.',
		'php tests/final-live-completion-gate-contract.php'
	),
	wp_agent_command_plan_step(
		'artifact_manifest',
		'Validate the final live artifact manifest template and any archived manifest.',
		'php tests/final-live-artifact-manifest-contract.php'
	),
	wp_agent_command_plan_step(
		'archive_redaction',
		'Scan archived final live artifacts for raw tokens or secret assignments, then archive the redaction report.',
		'php tests/final-live-archive-redaction-contract.php | tee ' . $redaction_archive,
		true
	),
);

$command_positions = array();
foreach ( $commands as $index => $command ) {
	$command_positions[ (string) ( $command['id'] ?? '' ) ] = $index;
}
$ux_validation_before_manifest = isset( $command_positions['ux_evidence_validation'], $command_positions['artifact_manifest_build'] )
	&& $command_positions['ux_evidence_validation'] < $command_positions['artifact_manifest_build'];
$summary_before_manifest = isset( $command_positions['acceptance_summary'], $command_positions['artifact_manifest_build'] )
	&& $command_positions['acceptance_summary'] < $command_positions['artifact_manifest_build'];
$review_packet_before_command_plan = isset( $command_positions['review_packet_status'], $command_positions['command_plan'] )
	&& $command_positions['review_packet_status'] < $command_positions['command_plan'];
$review_packet_before_live = isset( $command_positions['review_packet_status'], $command_positions['strict_preflight'], $command_positions['github_live'], $command_positions['editorial_daemon_soak'] )
	&& $command_positions['review_packet_status'] < $command_positions['strict_preflight']
	&& $command_positions['review_packet_status'] < $command_positions['github_live']
	&& $command_positions['review_packet_status'] < $command_positions['editorial_daemon_soak'];

$safe_values = array();
foreach ( $values as $key => $value ) {
	$safe_values[ $key ] = wp_agent_command_plan_is_secret_key( $key ) ? '[redacted]' : $value;
}

echo json_encode( array(
	'success'                  => true,
	'contract'                 => 'final_live_command_plan',
	'input_file'               => $input_path,
	'review_packet_file'       => $packet_path,
	'ready'                    => $ready,
	'commands_executable'      => $ready,
	'ready_for_live_execution' => $ready && $review_packet_ready && $review_packet_env_consistent,
	'review_packet_ready'      => $review_packet_ready,
	'review_packet_required_for_live_execution' => true,
	'review_packet_env_consistent' => $review_packet_env_consistent,
	'review_packet_env_mismatches' => $review_packet_env_mismatches,
	'review_packet_blocking_issues' => $review_packet_issues,
	'placeholder_rejected'     => ! empty( $placeholder_reasons ),
	'approval_phrase_rejected' => ! empty( $approval_reasons ),
	'cost_budget_rejected'     => ! empty( $cost_budget_reasons ),
	'artifact_policy_rejected' => ! empty( $artifact_policy_reasons ),
	'source_url_rejected'      => ! empty( $source_url_reasons ),
	'official_db_rejected'     => ! empty( $official_db_reasons ),
	'soak_bounds_rejected'     => ! empty( $soak_bound_reasons ),
	'missing_keys'             => $missing,
	'blocking_issues'          => array_values( array_unique( array_merge(
		$missing,
		$placeholder_reasons,
		$approval_reasons,
		$cost_budget_reasons,
		$artifact_policy_reasons,
		$source_url_reasons,
		$official_db_reasons,
		$soak_bound_reasons,
		array_map(
			static function ( $field ) {
				return 'review packet/env mismatch: ' . $field;
			},
			$review_packet_env_mismatches
		)
	) ) ),
	'env_summary'              => $safe_values,
	'commands'                 => $commands,
	'review_packet_before_command_plan' => $review_packet_before_command_plan,
	'review_packet_before_live' => $review_packet_before_live,
	'ux_validation_before_manifest' => $ux_validation_before_manifest,
	'summary_before_manifest'  => $summary_before_manifest,
	'archive_targets'          => array(
		'/path/to/wp-agent/design/test-logs/final-live-command-plan-YYYYMMDD.json',
		'/path/to/wp-agent/design/test-logs/final-live-github-skill-store-YYYYMMDD.json',
		'/path/to/wp-agent/design/test-logs/final-live-editorial-daemon-soak-YYYYMMDD.json',
		'/path/to/wp-agent/design/test-logs/ui-playwright-evidence-contract-YYYYMMDD.md',
		'/path/to/wp-agent/design/test-logs/final-live-acceptance-summary-YYYYMMDD.md',
		'/path/to/wp-agent/design/test-logs/final-live-artifact-manifest-YYYYMMDD.json',
		'/path/to/wp-agent/design/test-logs/final-live-archive-redaction-YYYYMMDD.md',
	),
	'token_disclosed'          => false,
	'secret_assignments'       => $secret_disclosed,
	'live_network_calls'       => false,
	'ai_gateway_calls'         => false,
	'github_calls'             => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
