<?php
/**
 * Host-side final live artifact manifest builder fixture contract.
 *
 * Proves the manifest builder creates a contract-valid manifest from archived
 * evidence and fails closed for placeholder inputs, missing artifacts, and
 * token-bearing archives. It uses temporary directories only and does not call
 * Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-artifact-manifest-build-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live artifact manifest builder contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_manifest_build_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_manifest_build_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_manifest_build_contract_fail( $message, $details );
	}
}

function wp_agent_manifest_build_contract_rm_rf( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = scandir( $path );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$child = $path . '/' . $item;
		if ( is_dir( $child ) ) {
			wp_agent_manifest_build_contract_rm_rf( $child );
		} else {
			@unlink( $child );
		}
	}
	@rmdir( $path );
}

function wp_agent_manifest_build_contract_write( $path, $text ) {
	$result = file_put_contents( $path, $text );
	wp_agent_manifest_build_contract_assert( false !== $result, 'Could not write fixture file.', array(
		'path' => $path,
	) );
}

function wp_agent_manifest_build_contract_json( $path, $data ) {
	wp_agent_manifest_build_contract_write( $path, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}

function wp_agent_manifest_build_contract_command( $args, $env = array() ) {
	$prefix = '';
	foreach ( $env as $key => $value ) {
		$prefix .= $key . '=' . escapeshellarg( $value ) . ' ';
	}
	$command = $prefix . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_manifest_build_contract_decode( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_manifest_build_contract_env( $path, $repository = 'wp-agent-fixtures/official-skills', $skill_path = 'skills/news-rewrite-publisher', $approval_phrase = 'approve-multi-hour-soak' ) {
	wp_agent_manifest_build_contract_write( $path, implode( "\n", array(
		'WP_AGENT_FINAL_PREFLIGHT_SCOPE=all',
		'WP_AGENT_FINAL_PREFLIGHT_STRICT=1',
		'WP_AGENT_LIVE_GITHUB_SKILLS=1',
		'WP_AGENT_LIVE_GITHUB_REPOSITORY=' . $repository,
		'WP_AGENT_LIVE_GITHUB_SKILL_PATH=' . $skill_path,
		'WP_AGENT_LIVE_GITHUB_REF=v1.0.0',
		'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY=quarantine',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON=1',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=' . $approval_phrase,
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD=5',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY=drafts_journal_usage',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS=12',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT=14400',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS=7200',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL=300',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS=30',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL=https://wordpress.org/news/',
		'WP_AGENT_OFFICIAL_DB_DIR=/path/to/wp-agent/database/official-mysql',
		'',
	) ) );
}

function wp_agent_manifest_build_contract_packet( $path, $skill_path = 'skills/news-rewrite-publisher' ) {
	wp_agent_manifest_build_contract_write( $path, implode( "\n", array(
		'# Final Live Review Packet',
		'',
		'This packet is for human review before the final #6/#9 live gates. Do not commit a completed packet. Do not paste tokens, API keys, passwords, or private repository credentials into this file.',
		'',
		'## Review Status',
		'',
		'- Reviewer: Manifest Build Contract',
		'- Review date: 2026-06-22',
		'- Approved live window: Contract fixture window',
		'- Approved API cost budget, `cost_budget_usd`: 5',
		'- Approved artifact policy: drafts_journal_usage',
		'- Completion expectation: `completion_ready=false` until all archived command plan, GitHub, soak, UX, summary, manifest, and redaction artifacts pass.',
		'',
		'## GitHub Skill Store Gate',
		'',
		'- User-approved official Skill Store coordinates: wp-agent-fixtures/official-skills ' . $skill_path . ' v1.0.0',
		'- Repository: wp-agent-fixtures/official-skills',
		'- Skill path: ' . $skill_path,
		'- Ref: v1.0.0',
		'- Review policy: quarantine',
		'- Activation/pin requested: no',
		'- GitHub token source: shell',
		'- Secret rule: `WP_AGENT_LIVE_GITHUB_TOKEN` must remain outside this packet, outside reviewed env files, outside design logs, outside lockfiles, and outside Git.',
		'',
		'## Multi-Hour Soak Gate',
		'',
		'- Run count: 12',
		'- Timeout seconds: 14400',
		'- Soak seconds: 7200',
		'- Sample interval: 300',
		'- Max usage rows: 30',
		'- Approval phrase handling: set `WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak` only after the reviewer approves this packet.',
		'- Cost guard: live summary and manifest must record actual usage and estimated cost against `cost_budget_usd`.',
		'',
		'## Content Source',
		'',
		'- Source URL public HTTP(S): https://wordpress.org/news/',
		'- Expected source scope: Public WordPress.org news posts only',
		'- Source safety rule: localhost, private, loopback, link-local, and reserved URLs must fail in command plan, strict preflight, and live harness before model work.',
		'',
		'## Official Database',
		'',
		'- Default database: `WP_AGENT_OFFICIAL_DB_DIR=/path/to/wp-agent/database/official-mysql`',
		'- Throwaway database exception: none',
		'- Exception rule: non-default DB use requires separate approval and `WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR=1`.',
		'',
		'## Cleanup/Rollback Policy',
		'',
		'- cleanup/rollback policy: pause temporary schedule and archive temporary Skill after review',
		'- Temporary schedule handling: pause after soak',
		'- Temporary Skill handling: archive or rollback after soak',
		'- Daemon final state: stopped or heartbeat fresh',
		'- Draft/media retention: retain drafts and media for review',
		'- Required live evidence: schedule paused, temporary Skill archived or rolled back, daemon stopped or heartbeat fresh, queue empty.',
		'',
		'## Archive Requirements',
		'',
		'- Archive root: `/path/to/wp-agent/design/test-logs/`',
		'- Command plan evidence: `final-live-command-plan-YYYYMMDD.json`',
		'- GitHub evidence: `final-live-github-skill-store-YYYYMMDD.json`',
		'- Soak evidence: `final-live-editorial-daemon-soak-YYYYMMDD.json`',
		'- UX evidence: `ui-playwright-evidence-contract-YYYYMMDD.md`',
		'- Acceptance summary: `final-live-acceptance-summary-YYYYMMDD.md`',
		'- Artifact manifest: `final-live-artifact-manifest-YYYYMMDD.json`',
		'- Archive redaction report: `final-live-archive-redaction-YYYYMMDD.md`',
		'- Required archive markers: `token_disclosed=false`, `remote_push=false`, `final-live-command-plan`, `ux_validation_before_manifest=true`, `summary_before_manifest=true`',
		'',
		'Failure rule: keep `goals.md` as `状态：实施中`, keep #6/#9 as partial, and do not claim final completion until the completion gate reports `completion_ready=true` with valid artifacts.',
		'',
	) ) );
}

function wp_agent_manifest_build_contract_artifacts( $dir, $command_plan_text, $token_disclosed = false, $approval_confirmed = true ) {
	wp_agent_manifest_build_contract_write( $dir . '/final-no-live-acceptance-contract-20260622.md', json_encode( array(
		'success' => true,
		'remote_push' => false,
		'live_network_calls' => false,
		'ai_gateway_calls' => false,
		'github_calls' => false,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	wp_agent_manifest_build_contract_json( $dir . '/final-acceptance-preflight-20260622.json', array(
		'ready' => true,
		'token_disclosed' => false,
	) );
	wp_agent_manifest_build_contract_write( $dir . '/final-live-command-plan-20260622.json', $command_plan_text );
	wp_agent_manifest_build_contract_json( $dir . '/final-live-github-skill-store-20260622.json', array(
		'success' => true,
		'repository' => 'wp-agent-fixtures/official-skills',
		'ref' => 'v1.0.0',
		'skill_path' => 'skills/news-rewrite-publisher',
		'review_policy' => 'quarantine',
		'quarantine_id' => 'q-fixture',
		'slug' => 'news-rewrite-publisher',
		'name' => 'News Rewrite Publisher',
		'file_count' => 2,
		'lock_under_runtime_root' => true,
		'token_disclosed' => $token_disclosed,
	) );
	wp_agent_manifest_build_contract_json( $dir . '/final-live-editorial-daemon-soak-20260622.json', array(
		'success' => true,
		'soak_completed' => true,
		'approval_phrase_confirmed' => $approval_confirmed,
		'soak_seconds' => 7200,
		'elapsed_seconds' => 7200,
		'cost_budget_usd' => 5,
		'cost_usd_added' => 1.25,
		'usage_rows_added' => 12,
		'max_usage_rows' => 30,
		'source_url' => 'https://wordpress.org/news/',
		'artifact_policy' => 'drafts_journal_usage',
		'memory_summary' => array( 'max_delta_mb' => 12 ),
		'daemon_before' => array( 'running' => true ),
		'daemon_after' => array( 'running' => true ),
		'schedule_status' => 'paused',
		'skill_archived' => true,
	) );
	wp_agent_manifest_build_contract_write( $dir . '/git-hygiene-contract-20260622.md', "remote_push=false\n" );
	wp_agent_manifest_build_contract_write( $dir . '/ui-playwright-evidence-contract-20260622.md', implode( "\n", array(
		'# UI Playwright Evidence Contract',
		'contract=ui_playwright_evidence_contract',
		'ux_quality_gate=true',
		'chat_stop_playwright=true',
		'chat_queue_status_playwright=true',
		'chat_stop_availability_playwright=true',
		'composer_unlocked_guard=true',
		'overflow_guard=true',
		'console_guard=true',
		'desktop_mobile_pairs=13',
		'screenshots_checked=26',
		'',
	) ) );
	wp_agent_manifest_build_contract_write( $dir . '/final-live-acceptance-summary-20260622.md', implode( "\n", array(
		'# Final Live Acceptance Summary',
		'/path/to/wp-agent/database/official-mysql',
		'remote_push=false',
		'token_disclosed=false',
		'completion_ready=true',
		'packet_ready=true',
		'ready_for_live_execution=true',
		'review_packet_ready=true',
		'review_packet_env_consistent=true',
		'ui-playwright-evidence-contract-20260622.md',
		'final-live-command-plan-20260622.json',
		'final-live-github-skill-store-20260622.json',
		'final-live-editorial-daemon-soak-20260622.json',
		'final-live-archive-redaction-20260622.md',
		'#6 passed',
		'#9 passed',
		'',
	) ) );
	wp_agent_manifest_build_contract_write( $dir . '/final-live-archive-redaction-20260622.md', implode( "\n", array(
		'# Final Live Archive Redaction',
		'contract=final_live_archive_redaction_contract',
		'token_disclosed=false',
		'raw_secret_hits=0',
		'',
	) ) );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_manifest_build_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$builder = $plugin_dir . '/tests/final-live-artifact-manifest-build.php';
$manifest_contract = $plugin_dir . '/tests/final-live-artifact-manifest-contract.php';
wp_agent_manifest_build_contract_assert( is_file( $builder ), 'Manifest builder is missing.' );
wp_agent_manifest_build_contract_assert( is_file( $manifest_contract ), 'Manifest contract is missing.' );
$builder_text = file_get_contents( $builder );
wp_agent_manifest_build_contract_assert( is_string( $builder_text ) && false !== strpos( $builder_text, 'summary_before_manifest' ) && false !== strpos( $builder_text, 'Final command plan must write the acceptance summary before building the manifest.' ), 'Manifest builder should fail closed when command plan summary ordering is missing.', array(
	'builder' => $builder,
) );

$base_dir = sys_get_temp_dir() . '/wp-agent-final-manifest-build-' . getmypid();
$valid_dir = $base_dir . '/valid';
$placeholder_dir = $base_dir . '/placeholder';
$approval_dir = $base_dir . '/approval';
$unconfirmed_dir = $base_dir . '/unconfirmed';
$missing_dir = $base_dir . '/missing';
$missing_ux_dir = $base_dir . '/missing-ux';
$token_dir = $base_dir . '/token';
foreach ( array( $valid_dir, $placeholder_dir, $approval_dir, $unconfirmed_dir, $missing_dir, $missing_ux_dir, $token_dir ) as $dir ) {
	@mkdir( $dir, 0700, true );
}
register_shutdown_function( 'wp_agent_manifest_build_contract_rm_rf', $base_dir );

$valid_env = $base_dir . '/valid.env';
$placeholder_env = $base_dir . '/placeholder.env';
$approval_env = $base_dir . '/approval.env';
$valid_packet = $plugin_dir . '/tests/final-live-review-packet-20260622.md';
$mismatch_packet = $plugin_dir . '/tests/final-live-review-packet-20260623.md';
register_shutdown_function( 'unlink', $valid_packet );
register_shutdown_function( 'unlink', $mismatch_packet );
wp_agent_manifest_build_contract_env( $valid_env );
wp_agent_manifest_build_contract_env( $placeholder_env, 'owner/repo', 'skills/example' );
wp_agent_manifest_build_contract_env( $approval_env, 'wp-agent-fixtures/official-skills', 'skills/news-rewrite-publisher', 'replace-after-review' );
wp_agent_manifest_build_contract_packet( $valid_packet );
wp_agent_manifest_build_contract_packet( $mismatch_packet, 'skills/mismatched-news-rewrite-publisher' );
$valid_command_plan_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-command-plan.php',
	$valid_env,
	$valid_packet,
) );
wp_agent_manifest_build_contract_assert( 0 === $valid_command_plan_run['status'], 'Valid command plan fixture should be generatable.', $valid_command_plan_run );
$valid_command_plan_text = $valid_command_plan_run['output'];
wp_agent_manifest_build_contract_artifacts( $valid_dir, $valid_command_plan_text );
wp_agent_manifest_build_contract_artifacts( $placeholder_dir, $valid_command_plan_text );
wp_agent_manifest_build_contract_artifacts( $approval_dir, $valid_command_plan_text );
wp_agent_manifest_build_contract_artifacts( $unconfirmed_dir, $valid_command_plan_text, false, false );
wp_agent_manifest_build_contract_artifacts( $missing_dir, $valid_command_plan_text );
wp_agent_manifest_build_contract_artifacts( $missing_ux_dir, $valid_command_plan_text );
wp_agent_manifest_build_contract_artifacts( $token_dir, $valid_command_plan_text, true );
@unlink( $missing_dir . '/final-live-editorial-daemon-soak-20260622.json' );
@unlink( $missing_ux_dir . '/ui-playwright-evidence-contract-20260622.md' );

$valid_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$builder,
	$valid_env,
	$valid_dir,
	$valid_packet,
), array(
	'WP_AGENT_FINAL_LIVE_MANIFEST_WRITE' => '1',
) );
wp_agent_manifest_build_contract_assert( 0 === $valid_run['status'], 'Valid manifest build fixture should pass.', $valid_run );
$valid_json = wp_agent_manifest_build_contract_decode( $valid_run['output'] );
wp_agent_manifest_build_contract_assert( is_array( $valid_json ), 'Valid manifest build fixture should print JSON.', $valid_run );
wp_agent_manifest_build_contract_assert( true === (bool) ( $valid_json['manifest_ready'] ?? false ), 'Valid manifest build fixture should report manifest_ready=true.', $valid_json );
wp_agent_manifest_build_contract_assert( true === (bool) ( $valid_json['written'] ?? false ), 'Valid manifest build fixture should write the manifest when explicitly enabled.', $valid_json );
wp_agent_manifest_build_contract_assert( is_file( $valid_json['manifest_path'] ?? '' ), 'Valid manifest build fixture should create the final manifest file.', $valid_json );
$valid_manifest = is_array( $valid_json['manifest'] ?? null ) ? $valid_json['manifest'] : array();
wp_agent_manifest_build_contract_assert( true === (bool) ( $valid_manifest['command_plan']['ux_validation_before_manifest'] ?? false ), 'Generated manifest should record UX validation before manifest build.', $valid_manifest );
wp_agent_manifest_build_contract_assert( true === (bool) ( $valid_manifest['command_plan']['summary_before_manifest'] ?? false ), 'Generated manifest should record acceptance summary before manifest build.', $valid_manifest );
wp_agent_manifest_build_contract_assert( true === (bool) ( $valid_manifest['command_plan']['ready_for_live_execution'] ?? false ), 'Generated manifest should record command-plan live readiness.', $valid_manifest );
wp_agent_manifest_build_contract_assert( true === (bool) ( $valid_manifest['command_plan']['review_packet_ready'] ?? false ), 'Generated manifest should record review packet readiness.', $valid_manifest );
wp_agent_manifest_build_contract_assert( true === (bool) ( $valid_manifest['command_plan']['review_packet_env_consistent'] ?? false ), 'Generated manifest should record packet/env consistency.', $valid_manifest );
wp_agent_manifest_build_contract_assert( $valid_packet === (string) ( $valid_manifest['inputs']['review_packet_source'] ?? '' ), 'Generated manifest should record the approved review packet source.', $valid_manifest );
wp_agent_manifest_build_contract_assert( false !== strpos( (string) ( $valid_manifest['command_plan']['artifact_path'] ?? '' ), 'final-live-command-plan-20260622.json' ), 'Generated manifest should record the archived command plan artifact path.', $valid_manifest );

$contract_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$manifest_contract,
), array(
	'WP_AGENT_FINAL_LIVE_MANIFEST_DIR' => $valid_dir,
) );
wp_agent_manifest_build_contract_assert( 0 === $contract_run['status'], 'Generated manifest should pass the artifact manifest contract.', $contract_run );
$contract_json = wp_agent_manifest_build_contract_decode( $contract_run['output'] );
wp_agent_manifest_build_contract_assert( is_array( $contract_json ) && true === (bool) ( $contract_json['actual_manifest_present'] ?? false ), 'Generated manifest contract should validate the actual manifest.', $contract_json ?? array() );

$placeholder_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$builder,
	$placeholder_env,
	$placeholder_dir,
	$valid_packet,
) );
wp_agent_manifest_build_contract_assert( 0 !== $placeholder_run['status'], 'Placeholder input fixture should fail closed.', $placeholder_run );
wp_agent_manifest_build_contract_assert( false !== strpos( $placeholder_run['output'], 'placeholder GitHub repository' ), 'Placeholder input fixture should name the placeholder failure.', $placeholder_run );

$approval_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$builder,
	$approval_env,
	$approval_dir,
	$valid_packet,
) );
wp_agent_manifest_build_contract_assert( 0 !== $approval_run['status'], 'Placeholder approval phrase fixture should fail closed.', $approval_run );
wp_agent_manifest_build_contract_assert( false !== strpos( $approval_run['output'], 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' ), 'Placeholder approval phrase fixture should name the approval phrase failure.', $approval_run );

$unconfirmed_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$builder,
	$valid_env,
	$unconfirmed_dir,
	$valid_packet,
) );
wp_agent_manifest_build_contract_assert( 0 !== $unconfirmed_run['status'], 'Unconfirmed soak approval artifact fixture should fail closed.', $unconfirmed_run );
wp_agent_manifest_build_contract_assert( false !== strpos( $unconfirmed_run['output'], 'approval_phrase_confirmed=true' ), 'Unconfirmed soak approval artifact fixture should name the missing approval confirmation.', $unconfirmed_run );

$missing_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$builder,
	$valid_env,
	$missing_dir,
	$valid_packet,
) );
wp_agent_manifest_build_contract_assert( 0 !== $missing_run['status'], 'Missing artifact fixture should fail closed.', $missing_run );
wp_agent_manifest_build_contract_assert( false !== strpos( $missing_run['output'], 'Required archived final live artifact pattern is missing' ), 'Missing artifact fixture should name the missing artifact failure.', $missing_run );

$missing_ux_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$builder,
	$valid_env,
	$missing_ux_dir,
	$valid_packet,
) );
wp_agent_manifest_build_contract_assert( 0 !== $missing_ux_run['status'], 'Missing UX evidence fixture should fail closed.', $missing_ux_run );
wp_agent_manifest_build_contract_assert( false !== strpos( $missing_ux_run['output'], 'ui-playwright-evidence-contract-*.md' ), 'Missing UX evidence fixture should name the UX artifact pattern.', $missing_ux_run );

$token_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$builder,
	$valid_env,
	$token_dir,
	$valid_packet,
) );
wp_agent_manifest_build_contract_assert( 0 !== $token_run['status'], 'Token disclosure fixture should fail closed.', $token_run );
wp_agent_manifest_build_contract_assert( false !== strpos( $token_run['output'], 'token_disclosed=true' ), 'Token disclosure fixture should name the token disclosure failure.', $token_run );

$mismatch_run = wp_agent_manifest_build_contract_command( array(
	PHP_BINARY,
	$builder,
	$valid_env,
	$valid_dir,
	$mismatch_packet,
) );
wp_agent_manifest_build_contract_assert( 0 !== $mismatch_run['status'], 'Mismatched review packet fixture should fail closed.', $mismatch_run );
wp_agent_manifest_build_contract_assert( false !== strpos( $mismatch_run['output'], 'review packet/env mismatch: Skill path' ) || false !== strpos( $mismatch_run['output'], 'review_packet_env_mismatches' ), 'Mismatched review packet fixture should name the packet/env mismatch.', $mismatch_run );

echo json_encode( array(
	'success'                  => true,
	'contract'                 => 'final_live_artifact_manifest_build_contract',
	'valid_manifest_ready'     => true,
	'valid_manifest_written'   => true,
	'valid_artifacts_checked'  => (int) ( $valid_json['artifact_count'] ?? 0 ),
	'generated_manifest_valid' => true,
	'command_plan_artifact_recorded' => true,
	'manifest_ux_order_recorded' => true,
	'manifest_summary_order_recorded' => true,
	'summary_order_guard'      => true,
	'placeholder_rejected'     => true,
	'approval_phrase_rejected' => true,
	'approval_confirmation_rejected' => true,
	'missing_artifact_rejected' => true,
	'missing_ux_evidence_rejected' => true,
	'token_disclosure_rejected' => true,
	'mismatched_packet_env_rejected' => true,
	'secret_assignments'       => false,
	'live_network_calls'       => false,
	'ai_gateway_calls'         => false,
	'github_calls'             => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
