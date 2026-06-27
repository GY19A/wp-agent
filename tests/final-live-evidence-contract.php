<?php
/**
 * Host-side final live evidence contract.
 *
 * Verifies the final live harnesses print enough structured JSON for review
 * after user-approved GitHub/API/multi-hour runs. This script only reads local
 * source files. It does not call GitHub, Docker, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-evidence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live evidence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_live_evidence_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_live_evidence_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_live_evidence_fail( $message, $details );
	}
}

function wp_agent_live_evidence_read( $path ) {
	wp_agent_live_evidence_assert( is_file( $path ), 'Required live harness is missing.', array(
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_live_evidence_assert( is_string( $text ) && '' !== $text, 'Required live harness could not be read.', array(
		'path' => $path,
	) );

	return $text;
}

function wp_agent_live_evidence_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_live_evidence_assert( empty( $missing ), $name . ' is missing required evidence markers.', array(
		'missing' => $missing,
	) );
}

function wp_agent_live_evidence_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_live_evidence_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_live_evidence_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$github_path    = $plugin_dir . '/tests/live-github-skill-store.php';
$editorial_path = $plugin_dir . '/tests/live-editorial-daemon-soak.php';
$github         = wp_agent_live_evidence_read( $github_path );
$editorial      = wp_agent_live_evidence_read( $editorial_path );

wp_agent_live_evidence_assert_no_raw_secrets( 'live-github-skill-store.php', $github );
wp_agent_live_evidence_assert_no_raw_secrets( 'live-editorial-daemon-soak.php', $editorial );

$github_markers = array(
	"'success'",
	"'repository'",
	"'ref'",
	"'skill_path'",
	"'quarantine_id'",
	"'slug'",
	"'name'",
	"'version'",
	"'review_policy'",
	"'warnings'",
	"'file_count'",
	"'has_token'",
	"'token_disclosed'",
	"'activated'",
	"'activated_skill'",
	"'pinned'",
	"'lock_under_runtime_root'",
	'WPAgent_Skills::github_store_placeholder_reason',
	'GitHub token must not be returned in source summary',
	'Quarantine lock must not persist a GitHub token key',
	'Quarantine lock file should live under private runtime root',
	'Quarantine lock file must not live under the plugin directory',
);

$editorial_markers = array(
	"'success'",
	"'schedule_id'",
	"'run_ids'",
	"'post_ids'",
	"'run_count'",
	"'requested_run_count'",
	"'timeout_seconds'",
	"'soak_seconds'",
	"'soak_completed'",
	"'approval_phrase_confirmed'",
	"'sample_interval'",
	"'heartbeat_max_age'",
	"'elapsed_seconds'",
	"'cost_budget_usd'",
	"'cost_usd_before'",
	"'cost_usd_after'",
	"'cost_usd_added'",
	"'artifact_policy'",
	"'usage_rows_added'",
	"'max_usage_rows'",
	"'journal_rows'",
	"'model'",
	"'source_url'",
	"'daemon_before'",
	"'daemon_after'",
	"'memory_summary'",
	"'cycle_results'",
	"'snapshots'",
	"'diagnostics'",
	"'schedule_status'",
	"'skill_archived'",
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
	'WPAgent_URL_Safety::validate_public_http_url',
	'usage-row guard',
	'approved API cost budget',
	'Resident daemon heartbeat became stale',
	'Live editorial daemon memory usage delta exceeded WP_AGENT_LIVE_EDITORIAL_DAEMON_MEMORY_DELTA_MAX',
	'WPAgent_Schedules::set_status( $schedule_id, \'paused\' )',
	'WPAgent_Skills::archive( $requester_id, $skill_slug )',
);

wp_agent_live_evidence_require_markers( 'live-github-skill-store.php', $github, $github_markers );
wp_agent_live_evidence_require_markers( 'live-editorial-daemon-soak.php', $editorial, $editorial_markers );

echo json_encode( array(
	'success'                 => true,
	'contract'                => 'final_live_evidence_contract',
	'harnesses_checked'       => 2,
	'github_evidence_markers' => count( $github_markers ),
	'soak_evidence_markers'   => count( $editorial_markers ),
	'live_network_calls'      => false,
	'ai_gateway_calls'        => false,
	'github_calls'            => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
