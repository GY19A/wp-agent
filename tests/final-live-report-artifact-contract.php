<?php
/**
 * Host-side final live report artifact contract.
 *
 * Verifies the final live acceptance report template and runbook require
 * reviewable archived artifacts for the two remaining external gates. This
 * script reads local files only. It does not call Docker, GitHub, WordPress, or
 * the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-report-artifact-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live report artifact contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_live_report_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_live_report_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_live_report_fail( $message, $details );
	}
}

function wp_agent_live_report_read( $path ) {
	wp_agent_live_report_assert( is_file( $path ), 'Required final live report file is missing.', array(
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_live_report_assert( is_string( $text ) && '' !== $text, 'Required final live report file could not be read.', array(
		'path' => $path,
	) );

	return $text;
}

function wp_agent_live_report_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_live_report_assert( empty( $missing ), $name . ' is missing required final live report markers.', array(
		'missing' => $missing,
	) );

	return count( $markers );
}

function wp_agent_live_report_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_live_report_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_live_report_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$files = array(
	'template'          => $plugin_dir . '/tests/final-live-report-template.md',
	'README.md'         => $plugin_dir . '/README.md',
	'goals.md'          => $plugin_dir . '/goals.md',
	'github_harness'    => $plugin_dir . '/tests/live-github-skill-store.php',
	'soak_harness'      => $plugin_dir . '/tests/live-editorial-daemon-soak.php',
	'input_template'    => $plugin_dir . '/tests/final-live-inputs.example.env',
	'manifest_template' => $plugin_dir . '/tests/final-live-artifact-manifest-template.json',
	'manifest_builder'  => $plugin_dir . '/tests/final-live-artifact-manifest-build.php',
);

$texts = array();
foreach ( $files as $name => $path ) {
	$texts[ $name ] = wp_agent_live_report_read( $path );
	wp_agent_live_report_assert_no_raw_secrets( $name, $texts[ $name ] );
}

$required_markers = 0;

$required_markers += wp_agent_live_report_require_markers( 'final-live-report-template.md', $texts['template'], array(
	'# Final Live Acceptance Report',
	'Do not mark `goals.md` complete from this template alone.',
	'Official stack: `docker-compose.official.yml`, project `wp-agent-official`',
	'Official database dir: `/path/to/wp-agent/database/official-mysql`',
	'Local Git state: `remote_push=false`',
	'Reviewed input source: `tests/final-live-inputs.example.env`',
	'`owner/repo` and `skills/example` replaced before live execution',
	'Review packet source: `final-live-review-packet-YYYYMMDD.md`, ignored by Git and not tracked',
	'php tests/final-no-live-acceptance-contract.php',
	'php tests/final-live-report-artifact-contract.php',
	'php tests/final-live-artifact-manifest-contract.php',
	'php tests/final-live-archive-redaction-contract.php',
	'php tests/ui-playwright-evidence-contract.php',
	'Strict preflight result: `ready=true`',
	'live_network_calls=false',
	'ai_gateway_calls=false',
	'github_calls=false',
	'Review packet status: `packet_ready=true`, `path_ignored_by_git=true`, `path_tracked_by_git=false`',
	'Command plan readiness: `commands_executable=true`, `ready_for_live_execution=true`, `review_packet_ready=true`, `review_packet_env_consistent=true`',
	'Command plan evidence path: `/path/to/wp-agent/design/test-logs/final-live-command-plan-YYYYMMDD.json`',
	'UX evidence result: `ux_quality_gate=true`, `chat_stop_playwright=true`, `chat_queue_status_playwright=true`, `chat_stop_availability_playwright=true`, `composer_unlocked_guard=true`',
	'Command plan artifact order: `ux_validation_before_manifest=true`, `summary_before_manifest=true`',
	'token_disclosed=false',
	'Archive redaction result: `token_disclosed=false`, `raw_secret_hits=0`',
	'Archive redaction evidence path: `/path/to/wp-agent/design/test-logs/final-live-archive-redaction-YYYYMMDD.md`',
	'GitHub Skill Store Gate (#6)',
	'Repository:',
	'Ref:',
	'Skill path:',
	'Review policy:',
	'Quarantine ID:',
	'Lock path under private runtime root: `lock_under_runtime_root=true`',
	'Activation state: `activated=true|false`, `pinned=true|false`',
	'final-live-github-skill-store-YYYYMMDD.json',
	'Multi-Hour Daemon Soak Gate (#9)',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak',
	'Cost budget:',
	'Artifact policy:',
	'Public source URL:',
	'Requested run count:',
	'Timeout seconds:',
	'Soak seconds:',
	'Sample interval:',
	'Max usage rows:',
	'Resident daemon command:',
	'Cost before/after/added:',
	'Usage rows added:',
	'Heartbeat max age:',
	'Soak completed: `soak_completed=true`',
	'Approval phrase confirmed: `approval_phrase_confirmed=true`',
	'Memory summary:',
	'Cleanup state: schedule paused, temporary Skill archived, daemon stopped or intentionally left running',
	'final-live-editorial-daemon-soak-YYYYMMDD.json',
	'final-live-command-plan-YYYYMMDD.json',
	'final-live-acceptance-summary-YYYYMMDD.md',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'chat_queue_status_playwright=true',
	'chat_stop_availability_playwright=true',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'final-live-archive-redaction-YYYYMMDD.md',
	'Acceptance Summary',
	'Summary evidence path: `/path/to/wp-agent/design/test-logs/final-live-acceptance-summary-YYYYMMDD.md`',
	'Required markers: `/path/to/wp-agent/database/official-mysql`, `remote_push=false`, `token_disclosed=false`, `completion_ready=true`, `packet_ready=true`, `ready_for_live_execution=true`, `review_packet_ready=true`, `review_packet_env_consistent=true`, `chat_queue_status_playwright=true`, `chat_stop_availability_playwright=true`, `composer_unlocked_guard=true`, `final-live-command-plan`',
	'Required artifact references: `ui-playwright-evidence-contract`, `final-live-command-plan`, `final-live-github-skill-store`, `final-live-editorial-daemon-soak`, `final-live-archive-redaction`',
	'Required acceptance rows: `#6`, `#9`',
	'tests/final-live-artifact-manifest-template.json',
	'php tests/final-live-artifact-manifest-build.php',
	'WP_AGENT_FINAL_LIVE_MANIFEST_WRITE=1',
	'path/to/final-live-review-packet-YYYYMMDD.md',
	'Required contents: artifact paths, sha256 hashes, local Git HEAD, `remote_push=false`, official DB dir, completed review packet source, archived command plan path/hash, `ready_for_live_execution=true`, `review_packet_ready=true`, `review_packet_env_consistent=true`, command plan result, `ux_validation_before_manifest=true`, `summary_before_manifest=true`, archive redaction report, completion gate result, and `token_disclosed=false`',
	'Completion Rule',
) );

$required_markers += wp_agent_live_report_require_markers( 'README.md', $texts['README.md'], array(
	'tests/final-live-report-template.md',
	'php tests/final-live-report-artifact-contract.php',
	'php tests/final-live-artifact-manifest-build.php',
	'final-live-github-skill-store-YYYYMMDD.json',
	'final-live-editorial-daemon-soak-YYYYMMDD.json',
	'final-live-command-plan-YYYYMMDD.json',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'chat_queue_status_playwright=true',
	'chat_stop_availability_playwright=true',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'final-live-archive-redaction-YYYYMMDD.md',
	'ux_validation_before_manifest=true',
	'summary_before_manifest=true',
	'ready_for_live_execution=true',
	'review_packet_ready=true',
	'review_packet_env_consistent=true',
	'packet_ready=true',
	'completion_ready=true',
) );

$required_markers += wp_agent_live_report_require_markers( 'goals.md', $texts['goals.md'], array(
	'tests/final-live-report-template.md',
	'tests/final-live-report-artifact-contract.php',
	'tests/final-live-artifact-manifest-build.php',
	'tests/final-live-artifact-manifest-contract.php',
	'最终 live 报告 artifact',
	'最终 live artifact manifest',
	'final-live-github-skill-store-YYYYMMDD.json',
	'final-live-editorial-daemon-soak-YYYYMMDD.json',
	'final-live-command-plan-YYYYMMDD.json',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'final-live-archive-redaction-YYYYMMDD.md',
	'ux_validation_before_manifest=true',
	'summary_before_manifest=true',
	'ready_for_live_execution=true',
	'review_packet_ready=true',
	'review_packet_env_consistent=true',
	'packet_ready=true',
	'completion_ready=true',
) );

$harnesses = $texts['github_harness'] . "\n" . $texts['soak_harness'];
$required_markers += wp_agent_live_report_require_markers( 'live harnesses', $harnesses, array(
	"'token_disclosed'",
	"'lock_under_runtime_root'",
	"'quarantine_id'",
	"'cost_budget_usd'",
	"'usage_rows_added'",
	"'heartbeat_max_age'",
	"'soak_completed'",
	"'memory_summary'",
	"'source_url'",
) );

$required_markers += wp_agent_live_report_require_markers( 'final-live-inputs.example.env', $texts['input_template'], array(
	'WP_AGENT_LIVE_GITHUB_REPOSITORY=owner/repo',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH=skills/example',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=replace-after-review',
	'approve-multi-hour-soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD=5',
	'WP_AGENT_OFFICIAL_DB_DIR=/path/to/wp-agent/database/official-mysql',
) );

$required_markers += wp_agent_live_report_require_markers( 'final-live-artifact-manifest-template.json', $texts['manifest_template'], array(
	'wp-agent-final-live-artifact-manifest',
	'final-live-github-skill-store-YYYYMMDD.json',
	'final-live-editorial-daemon-soak-YYYYMMDD.json',
	'final-live-command-plan-YYYYMMDD.json',
	'final-live-acceptance-summary-YYYYMMDD.md',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'final-live-archive-redaction-YYYYMMDD.md',
	'/path/to/wp-agent/database/official-mysql',
	'summary_before_manifest',
	'php tests/final-live-completion-gate-contract.php',
	'php tests/final-live-artifact-manifest-contract.php',
) );

$required_markers += wp_agent_live_report_require_markers( 'final-live-artifact-manifest-build.php', $texts['manifest_builder'], array(
	'final-live-github-skill-store-*.json',
	'final-live-editorial-daemon-soak-*.json',
	'final-live-command-plan-*.json',
	'ui-playwright-evidence-contract-*.md',
	'final-live-acceptance-summary-*.md',
	'final-live-archive-redaction-[0-9]*.md',
	'WP_AGENT_FINAL_LIVE_MANIFEST_WRITE',
	'review_packet_env_consistent',
	'summary_before_manifest',
	'manifest_ready',
) );

$report_ux_order_recorded = false !== strpos( $texts['template'], 'Command plan artifact order: `ux_validation_before_manifest=true`, `summary_before_manifest=true`' )
	&& false !== strpos( $texts['README.md'], 'report-level `ux_validation_before_manifest=true`' )
	&& false !== strpos( $texts['goals.md'], 'ux_validation_before_manifest=true' );
$report_summary_order_recorded = false !== strpos( $texts['template'], 'summary_before_manifest=true' )
	&& false !== strpos( $texts['README.md'], 'summary_before_manifest=true' )
	&& false !== strpos( $texts['goals.md'], 'summary_before_manifest=true' );

echo json_encode( array(
	'success'                 => true,
	'contract'                => 'final_live_report_artifact_contract',
	'template'                => $files['template'],
	'files_checked'           => count( $files ),
	'required_markers'        => $required_markers,
	'report_ux_order_recorded' => $report_ux_order_recorded,
	'report_summary_order_recorded' => $report_summary_order_recorded,
	'archive_targets'         => array(
		'final-live-github-skill-store-YYYYMMDD.json',
		'final-live-editorial-daemon-soak-YYYYMMDD.json',
		'final-live-command-plan-YYYYMMDD.json',
		'ui-playwright-evidence-contract-YYYYMMDD.md',
		'final-live-acceptance-summary-YYYYMMDD.md',
		'final-live-artifact-manifest-YYYYMMDD.json',
		'final-live-archive-redaction-YYYYMMDD.md',
	),
	'secret_assignments'      => false,
	'live_network_calls'      => false,
	'ai_gateway_calls'        => false,
	'github_calls'            => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
