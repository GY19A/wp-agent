<?php
/**
 * Host-side final live review packet contract.
 *
 * Verifies the human review packet template for the remaining #6/#9 live
 * gates stays complete, non-executable, and secret-free. This script reads
 * local files only and does not call Docker, GitHub, WordPress, or the AI
 * gateway.
 *
 * Run from the host:
 * php tests/final-live-review-packet-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live review packet contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_review_packet_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_review_packet_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_review_packet_fail( $message, $details );
	}
}

function wp_agent_review_packet_read( $path ) {
	wp_agent_review_packet_assert( is_file( $path ), 'Required review packet file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_review_packet_assert( is_string( $text ) && '' !== $text, 'Required review packet file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_review_packet_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}
	wp_agent_review_packet_assert( empty( $missing ), $name . ' is missing required review packet markers.', array(
		'missing' => $missing,
	) );
	return count( $markers );
}

function wp_agent_review_packet_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_review_packet_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_review_packet_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$template_path = $plugin_dir . '/tests/final-live-review-packet-template.md';
$template      = wp_agent_review_packet_read( $template_path );
$readme        = wp_agent_review_packet_read( $plugin_dir . '/README.md' );
$goals         = wp_agent_review_packet_read( $plugin_dir . '/goals.md' );
$gitignore     = wp_agent_review_packet_read( $plugin_dir . '/.gitignore' );

wp_agent_review_packet_assert_no_raw_secrets( 'final-live-review-packet-template.md', $template );
wp_agent_review_packet_assert_no_raw_secrets( 'README.md', $readme );
wp_agent_review_packet_assert_no_raw_secrets( 'goals.md', $goals );
wp_agent_review_packet_assert_no_raw_secrets( '.gitignore', $gitignore );

$required_markers = 0;
$required_markers += wp_agent_review_packet_require_markers( 'final-live-review-packet-template.md', $template, array(
	'# Final Live Review Packet',
	'Do not commit a completed packet.',
	'Do not paste tokens, API keys, passwords, or private repository credentials',
	'Approved API cost budget, `cost_budget_usd`',
	'Completion expectation: `completion_ready=false`',
	'User-approved official Skill Store coordinates',
	'Repository:',
	'Skill path:',
	'Ref:',
	'Review policy: `quarantine`, `activate`, or `activate_pin`',
	'GitHub token source: shell, WordPress Settings, or ignored env only',
	'`WP_AGENT_LIVE_GITHUB_TOKEN` must remain outside this packet',
	'Run count:',
	'Timeout seconds:',
	'Soak seconds:',
	'Sample interval:',
	'Max usage rows:',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak',
	'Source URL public HTTP(S):',
	'localhost, private, loopback, link-local, and reserved URLs must fail',
	'WP_AGENT_OFFICIAL_DB_DIR=/path/to/wp-agent/database/official-mysql',
	'WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR=1',
	'cleanup/rollback policy',
	'Archive root: `/path/to/wp-agent/design/test-logs/`',
	'Command plan evidence: `final-live-command-plan-YYYYMMDD.json`',
	'final-live-github-skill-store-YYYYMMDD.json',
	'final-live-editorial-daemon-soak-YYYYMMDD.json',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'final-live-acceptance-summary-YYYYMMDD.md',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'Archive redaction report: `final-live-archive-redaction-YYYYMMDD.md`',
	'token_disclosed=false',
	'remote_push=false',
	'final-live-command-plan',
	'ux_validation_before_manifest=true',
	'summary_before_manifest=true',
	'Run command plan dry-run.',
	'Run strict final preflight.',
	'Run GitHub live gate.',
	'Run multi-hour soak gate.',
	'Run archive redaction.',
	'Run completion gate.',
	'goals.md` as `状态：实施中`',
	'completion_ready=true',
) );

wp_agent_review_packet_assert( false === strpos( $template, 'owner/repo' ), 'Review packet template must avoid executable GitHub placeholder coordinates.' );
wp_agent_review_packet_assert( false === strpos( $template, 'skills/example' ), 'Review packet template must avoid executable Skill path placeholders.' );

$required_markers += wp_agent_review_packet_require_markers( 'README.md', $readme, array(
	'tests/final-live-review-packet-template.md',
	'php tests/final-live-review-packet-contract.php',
	'final-live-command-plan-YYYYMMDD.json',
	'final-live-archive-redaction-YYYYMMDD.md',
) );

$required_markers += wp_agent_review_packet_require_markers( 'goals.md', $goals, array(
	'tests/final-live-review-packet-template.md',
	'tests/final-live-review-packet-contract.php',
	'最终用户输入与审批包',
	'final live review packet',
	'final-live-command-plan-YYYYMMDD.json',
	'final-live-archive-redaction-YYYYMMDD.md',
) );

$required_markers += wp_agent_review_packet_require_markers( '.gitignore', $gitignore, array(
	'/final-live-review-packet-*.md',
	'/final-live-inputs.*.env',
	'tests/final-live-review-packet-*.md',
	'!tests/final-live-review-packet-template.md',
	'tests/final-live-inputs.*.env',
	'!tests/final-live-inputs.example.env',
) );

echo json_encode( array(
	'success'                => true,
	'contract'               => 'final_live_review_packet_contract',
	'template'               => $template_path,
	'required_markers'       => $required_markers,
	'review_packet_template' => true,
	'gitignore_review_inputs' => true,
	'secret_assignments'     => false,
	'live_network_calls'     => false,
	'ai_gateway_calls'       => false,
	'github_calls'           => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
