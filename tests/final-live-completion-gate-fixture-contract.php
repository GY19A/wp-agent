<?php
/**
 * Host-side final live completion gate fixture contract.
 *
 * Proves the completion gate validates artifact schema, not only missing-file
 * state. It creates temporary valid and invalid final-live artifact sets and
 * runs final-live-completion-gate-contract.php against them. This script does
 * not call Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-completion-gate-fixture-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live completion gate fixture contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_completion_fixture_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_completion_fixture_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_completion_fixture_fail( $message, $details );
	}
}

function wp_agent_completion_fixture_command( $artifact_dir, $script ) {
	$command = 'WP_AGENT_FINAL_LIVE_ARTIFACT_DIR=' . escapeshellarg( $artifact_dir ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_completion_fixture_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_completion_fixture_write_json( $path, $data ) {
	$result = file_put_contents( $path, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	wp_agent_completion_fixture_assert( false !== $result, 'Could not write fixture JSON.', array(
		'path' => $path,
	) );
}

function wp_agent_completion_fixture_write_text( $path, $text ) {
	$result = file_put_contents( $path, $text );
	wp_agent_completion_fixture_assert( false !== $result, 'Could not write fixture text.', array(
		'path' => $path,
	) );
}

function wp_agent_completion_fixture_rm_rf( $path ) {
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
			wp_agent_completion_fixture_rm_rf( $child );
		} else {
			@unlink( $child );
		}
	}
	@rmdir( $path );
}

function wp_agent_completion_fixture_artifact_entry( $kind, $path, $validator ) {
	return array(
		'kind'            => $kind,
		'path'            => $path,
		'sha256'          => hash_file( 'sha256', $path ),
		'size_bytes'      => filesize( $path ),
		'validated_by'    => $validator,
		'token_disclosed' => false,
	);
}

function wp_agent_completion_fixture_build_manifest( $dir, $artifacts, $security = array() ) {
	return array(
		'schema_version' => 1,
		'manifest_type'  => 'wp-agent-final-live-artifact-manifest',
		'status'         => 'reviewed_live_complete',
		'created_at'     => '2026-06-22T12:00:00Z',
		'official_stack' => array(
			'compose_file'    => 'docker-compose.official.yml',
			'project'         => 'wp-agent-official',
			'wordpress_image' => 'wordpress:php8.3-apache',
			'wpcli_image'     => 'wordpress:cli-php8.3',
			'database_dir'    => '/path/to/wp-agent/database/official-mysql',
		),
		'git'            => array(
			'head'        => 'fixture-head',
			'remote_push' => false,
		),
		'inputs'         => array(
			'review_packet_source'     => $dir . '/final-live-review-packet-20260622.md',
			'soak_approval_phrase'    => 'approve-multi-hour-soak',
			'soak_approval_confirmed' => true,
			'token_disclosed'         => false,
		),
		'command_plan'   => array(
			'commands_executable'             => true,
			'ready_for_live_execution'        => true,
			'review_packet_ready'             => true,
			'review_packet_env_consistent'    => true,
			'ux_validation_before_manifest'   => true,
			'summary_before_manifest'         => true,
		),
		'contracts'      => array(
			'completion_gate' => array(
				'command' => 'php tests/final-live-completion-gate-contract.php',
			),
		),
		'artifacts'      => array_values( $artifacts ),
		'external_gates' => array(
			'row_6_skills_store' => 'passed',
			'row_9_daemon_soak'  => 'passed',
		),
		'security'       => array_merge( array(
			'secret_scan'               => 'passed',
			'token_disclosed'           => false,
			'official_db_dir_confirmed' => true,
			'raw_secret_assignments'    => false,
		), $security ),
		'review'         => array(
			'reviewer' => 'fixture',
			'decision' => 'approved',
		),
	);
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_completion_fixture_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$gate_script = $plugin_dir . '/tests/final-live-completion-gate-contract.php';
wp_agent_completion_fixture_assert( is_file( $gate_script ), 'Completion gate script is missing.' );

$base_dir = sys_get_temp_dir() . '/wp-agent-final-completion-fixtures-' . getmypid();
$valid_dir = $base_dir . '/valid';
$invalid_dir = $base_dir . '/invalid';
@mkdir( $valid_dir, 0700, true );
@mkdir( $invalid_dir, 0700, true );
wp_agent_completion_fixture_assert( is_dir( $valid_dir ) && is_dir( $invalid_dir ), 'Could not create fixture directories.', array(
	'base_dir' => $base_dir,
) );
register_shutdown_function( 'wp_agent_completion_fixture_rm_rf', $base_dir );

$valid_github = array(
	'success'                 => true,
	'repository'              => 'wp-agent-fixtures/official-skills',
	'ref'                     => 'v1.0.0',
	'skill_path'              => 'skills/news-rewrite-publisher',
	'quarantine_id'           => 'q_20260622_fixture',
	'slug'                    => 'news-rewrite-publisher',
	'name'                    => 'News Rewrite Publisher',
	'version'                 => '1.0.0',
	'review_policy'           => 'quarantine',
	'warnings'                => array(),
	'file_count'              => 3,
	'has_token'               => false,
	'token_disclosed'         => false,
	'activated'               => false,
	'activated_skill'         => null,
	'pinned'                  => false,
	'lock_under_runtime_root' => true,
);

$valid_soak = array(
	'success'             => true,
	'schedule_id'         => 1001,
	'run_ids'             => array( 2001, 2002 ),
	'post_ids'            => array( 3001, 3002 ),
	'run_count'           => 2,
	'requested_run_count' => 2,
	'timeout_seconds'     => 7200,
	'soak_seconds'        => 7200,
	'soak_completed'      => true,
	'approval_phrase_confirmed' => true,
	'sample_interval'     => 60,
	'heartbeat_max_age'   => 4,
	'elapsed_seconds'     => 7201,
	'cost_budget_usd'     => 5.0,
	'cost_usd_before'     => 1.0,
	'cost_usd_after'      => 2.5,
	'cost_usd_added'      => 1.5,
	'artifact_policy'     => 'drafts_journal_usage',
	'usage_rows_added'    => 4,
	'max_usage_rows'      => 96,
	'journal_rows'        => 2,
	'model'               => 'gpt-5.4-mini',
	'source_url'          => 'https://wordpress.org/news/',
	'daemon_before'       => array( 'status' => 'running' ),
	'daemon_after'        => array( 'status' => 'running' ),
	'memory_summary'      => array(
		'baseline' => 1000000,
		'peak'     => 1500000,
		'delta'    => 500000,
	),
	'cycle_results'       => array(),
	'snapshots'           => array(),
	'diagnostics'         => array(),
	'schedule_status'     => 'paused',
	'skill_archived'      => true,
);

$valid_summary = "# Final Live Acceptance Summary\n\n"
	. "- #6 passed with final-live-github-skill-store-20260622.json\n"
	. "- #9 passed with final-live-editorial-daemon-soak-20260622.json\n"
	. "- Command plan retained in final-live-command-plan-20260622.json\n"
	. "- UX evidence retained in ui-playwright-evidence-contract-20260622.md\n"
	. "- Redaction report retained in final-live-archive-redaction-20260622.md\n"
	. "- official database: /path/to/wp-agent/database/official-mysql\n"
	. "- remote_push=false\n"
	. "- token_disclosed=false\n"
	. "- packet_ready=true\n"
	. "- ready_for_live_execution=true\n"
	. "- review_packet_ready=true\n"
	. "- review_packet_env_consistent=true\n"
	. "- chat_queue_status_playwright=true\n"
	. "- chat_stop_availability_playwright=true\n"
	. "- composer_unlocked_guard=true\n"
	. "- completion_ready=true\n";

$valid_ux = "# UI Playwright Evidence Contract\n\n"
	. "contract=ui_playwright_evidence_contract\n"
	. "ux_quality_gate=true\n"
	. "chat_stop_playwright=true\n"
	. "chat_queue_status_playwright=true\n"
	. "chat_stop_availability_playwright=true\n"
	. "composer_unlocked_guard=true\n"
	. "overflow_guard=true\n"
	. "console_guard=true\n"
	. "desktop_mobile_pairs=13\n"
	. "screenshots_checked=26\n";

wp_agent_completion_fixture_write_json( $valid_dir . '/final-live-github-skill-store-20260622.json', $valid_github );
wp_agent_completion_fixture_write_json( $valid_dir . '/final-live-editorial-daemon-soak-20260622.json', $valid_soak );
wp_agent_completion_fixture_write_text( $valid_dir . '/final-live-acceptance-summary-20260622.md', $valid_summary );
wp_agent_completion_fixture_write_text( $valid_dir . '/final-no-live-acceptance-contract-20260622.md', "# no-live\nsuccess=true\n" );
wp_agent_completion_fixture_write_json( $valid_dir . '/final-acceptance-preflight-20260622.json', array( 'ready' => true, 'token_disclosed' => false ) );
wp_agent_completion_fixture_write_json( $valid_dir . '/final-live-command-plan-20260622.json', array(
	'commands_executable' => true,
	'ready_for_live_execution' => true,
	'review_packet_ready' => true,
	'review_packet_env_consistent' => true,
	'placeholder_rejected' => false,
	'ux_validation_before_manifest' => true,
	'summary_before_manifest' => true,
	'secret_assignments' => false,
) );
wp_agent_completion_fixture_write_text( $valid_dir . '/git-hygiene-contract-20260622.md', "# git\nremote_push=false\n" );
wp_agent_completion_fixture_write_text( $valid_dir . '/ui-playwright-evidence-contract-20260622.md', $valid_ux );
wp_agent_completion_fixture_write_text( $valid_dir . '/final-live-archive-redaction-20260622.md', "# redaction\ncontract=final_live_archive_redaction_contract\ntoken_disclosed=false\nraw_secret_hits=0\n" );

$valid_artifacts = array(
	wp_agent_completion_fixture_artifact_entry( 'github_skill_store', $valid_dir . '/final-live-github-skill-store-20260622.json', 'tests/final-live-completion-gate-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'editorial_daemon_soak', $valid_dir . '/final-live-editorial-daemon-soak-20260622.json', 'tests/final-live-completion-gate-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'acceptance_summary', $valid_dir . '/final-live-acceptance-summary-20260622.md', 'tests/final-live-completion-gate-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'no_live_acceptance', $valid_dir . '/final-no-live-acceptance-contract-20260622.md', 'tests/final-no-live-acceptance-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'strict_preflight', $valid_dir . '/final-acceptance-preflight-20260622.json', 'tests/final-acceptance-preflight.php' ),
	wp_agent_completion_fixture_artifact_entry( 'command_plan', $valid_dir . '/final-live-command-plan-20260622.json', 'tests/final-live-command-plan.php' ),
	wp_agent_completion_fixture_artifact_entry( 'git_hygiene', $valid_dir . '/git-hygiene-contract-20260622.md', 'tests/git-hygiene-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'ux_evidence', $valid_dir . '/ui-playwright-evidence-contract-20260622.md', 'tests/ui-playwright-evidence-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'archive_redaction_report', $valid_dir . '/final-live-archive-redaction-20260622.md', 'tests/final-live-archive-redaction-contract.php' ),
);
wp_agent_completion_fixture_write_json( $valid_dir . '/final-live-artifact-manifest-20260622.json', wp_agent_completion_fixture_build_manifest( $valid_dir, $valid_artifacts ) );

$invalid_github = $valid_github;
$invalid_github['repository'] = 'owner/repo';
$invalid_github['skill_path'] = 'skills/example';
$invalid_github['token_disclosed'] = true;
$invalid_github['lock_under_runtime_root'] = false;

$invalid_soak = $valid_soak;
$invalid_soak['success'] = false;
$invalid_soak['soak_completed'] = false;
$invalid_soak['approval_phrase_confirmed'] = false;
$invalid_soak['soak_seconds'] = 60;
$invalid_soak['elapsed_seconds'] = 30;
$invalid_soak['source_url'] = 'http://localhost/news/';
$invalid_soak['usage_rows_added'] = 99;
$invalid_soak['schedule_status'] = 'active';
$invalid_soak['skill_archived'] = false;

wp_agent_completion_fixture_write_json( $invalid_dir . '/final-live-github-skill-store-20260622.json', $invalid_github );
wp_agent_completion_fixture_write_json( $invalid_dir . '/final-live-editorial-daemon-soak-20260622.json', $invalid_soak );
wp_agent_completion_fixture_write_text( $invalid_dir . '/final-live-acceptance-summary-20260622.md', "# Incomplete Summary\n\n- token_disclosed=false\n" );
wp_agent_completion_fixture_write_text( $invalid_dir . '/final-no-live-acceptance-contract-20260622.md', "# no-live\nsuccess=true\n" );
wp_agent_completion_fixture_write_json( $invalid_dir . '/final-acceptance-preflight-20260622.json', array( 'ready' => true, 'token_disclosed' => false ) );
wp_agent_completion_fixture_write_text( $invalid_dir . '/git-hygiene-contract-20260622.md', "# git\nremote_push=false\n" );
$invalid_artifacts = array(
	wp_agent_completion_fixture_artifact_entry( 'github_skill_store', $invalid_dir . '/final-live-github-skill-store-20260622.json', 'tests/final-live-completion-gate-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'editorial_daemon_soak', $invalid_dir . '/final-live-editorial-daemon-soak-20260622.json', 'tests/final-live-completion-gate-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'acceptance_summary', $invalid_dir . '/final-live-acceptance-summary-20260622.md', 'tests/final-live-completion-gate-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'no_live_acceptance', $invalid_dir . '/final-no-live-acceptance-contract-20260622.md', 'tests/final-no-live-acceptance-contract.php' ),
	wp_agent_completion_fixture_artifact_entry( 'strict_preflight', $invalid_dir . '/final-acceptance-preflight-20260622.json', 'tests/final-acceptance-preflight.php' ),
	wp_agent_completion_fixture_artifact_entry( 'git_hygiene', $invalid_dir . '/git-hygiene-contract-20260622.md', 'tests/git-hygiene-contract.php' ),
);
$invalid_artifacts[0]['sha256'] = str_repeat( '0', 64 );
$invalid_manifest = wp_agent_completion_fixture_build_manifest( $invalid_dir, $invalid_artifacts, array(
	'token_disclosed' => true,
) );
$invalid_manifest['inputs']['review_packet_source'] = 'tests/final-live-review-packet-template.md';
$invalid_manifest['inputs']['soak_approval_confirmed'] = false;
$invalid_manifest['command_plan']['commands_executable'] = false;
$invalid_manifest['command_plan']['ready_for_live_execution'] = false;
$invalid_manifest['command_plan']['review_packet_ready'] = false;
$invalid_manifest['command_plan']['review_packet_env_consistent'] = false;
wp_agent_completion_fixture_write_json( $invalid_dir . '/final-live-artifact-manifest-20260622.json', $invalid_manifest );

$valid_run = wp_agent_completion_fixture_command( $valid_dir, $gate_script );
wp_agent_completion_fixture_assert( 0 === $valid_run['status'], 'Valid fixture completion gate should exit successfully.', $valid_run );
$valid_json = wp_agent_completion_fixture_json( $valid_run['output'] );
wp_agent_completion_fixture_assert( is_array( $valid_json ), 'Valid fixture completion gate should print JSON.', $valid_run );
wp_agent_completion_fixture_assert( true === (bool) ( $valid_json['success'] ?? false ), 'Valid fixture should report success=true.', $valid_json );
wp_agent_completion_fixture_assert( true === (bool) ( $valid_json['completion_ready'] ?? false ), 'Valid fixture should report completion_ready=true.', $valid_json );
wp_agent_completion_fixture_assert( 'env' === (string) ( $valid_json['artifact_dir_source'] ?? '' ), 'Valid fixture should use env artifact directory.', $valid_json );

$invalid_run = wp_agent_completion_fixture_command( $invalid_dir, $gate_script );
wp_agent_completion_fixture_assert( 0 === $invalid_run['status'], 'Invalid fixture completion gate should still exit successfully while goals.md remains in progress.', $invalid_run );
$invalid_json = wp_agent_completion_fixture_json( $invalid_run['output'] );
wp_agent_completion_fixture_assert( is_array( $invalid_json ), 'Invalid fixture completion gate should print JSON.', $invalid_run );
wp_agent_completion_fixture_assert( true === (bool) ( $invalid_json['success'] ?? false ), 'Invalid fixture should report success=true.', $invalid_json );
wp_agent_completion_fixture_assert( false === (bool) ( $invalid_json['completion_ready'] ?? true ), 'Invalid fixture should report completion_ready=false.', $invalid_json );
wp_agent_completion_fixture_assert( in_array( 'github_repository_missing_or_placeholder', $invalid_json['artifacts']['github']['errors'] ?? array(), true ), 'Invalid GitHub fixture should reject placeholder repository.', $invalid_json['artifacts']['github'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'github_token_disclosed', $invalid_json['artifacts']['github']['errors'] ?? array(), true ), 'Invalid GitHub fixture should reject token disclosure.', $invalid_json['artifacts']['github'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'soak_approval_phrase_not_confirmed', $invalid_json['artifacts']['soak']['errors'] ?? array(), true ), 'Invalid soak fixture should reject missing approval phrase confirmation.', $invalid_json['artifacts']['soak'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'soak_seconds_below_multi_hour_minimum', $invalid_json['artifacts']['soak']['errors'] ?? array(), true ), 'Invalid soak fixture should reject short soak duration.', $invalid_json['artifacts']['soak'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'soak_source_url_not_public_http', $invalid_json['artifacts']['soak']['errors'] ?? array(), true ), 'Invalid soak fixture should reject localhost source URL.', $invalid_json['artifacts']['soak'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_soak_approval_not_confirmed', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should reject missing manifest approval confirmation.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_review_packet_source_missing', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should reject missing approved review packet source.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_command_plan_not_executable', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should reject non-executable command plans.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_command_plan_not_ready_for_live_execution', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should reject command plans that are not live-ready.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_review_packet_not_ready', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should reject unapproved review packets.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_review_packet_env_not_consistent', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should reject packet/env inconsistency.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_token_disclosed', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should reject token disclosure state.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_artifact_hash_mismatch:github_skill_store', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should reject artifact hash mismatch.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'manifest_missing_artifact:ux_evidence', $invalid_json['artifacts']['manifest']['errors'] ?? array(), true ), 'Invalid manifest fixture should require UX evidence artifact.', $invalid_json['artifacts']['manifest'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:completion_ready=true', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing completion_ready=true marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:packet_ready=true', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing packet_ready=true marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:ready_for_live_execution=true', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing ready_for_live_execution=true marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:review_packet_ready=true', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing review_packet_ready=true marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:review_packet_env_consistent=true', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing review_packet_env_consistent=true marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:final-live-command-plan', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing final-live-command-plan marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:chat_queue_status_playwright=true', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing chat_queue_status_playwright=true marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:chat_stop_availability_playwright=true', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing chat_stop_availability_playwright=true marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'summary_missing_marker:composer_unlocked_guard=true', $invalid_json['artifacts']['summary']['errors'] ?? array(), true ), 'Invalid summary fixture should reject missing composer_unlocked_guard=true marker.', $invalid_json['artifacts']['summary'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'ux_evidence_missing', $invalid_json['artifacts']['ux']['errors'] ?? array(), true ), 'Invalid fixture should reject missing UX evidence.', $invalid_json['artifacts']['ux'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'redaction_token_disclosed_true:github', $invalid_json['artifacts']['redaction']['errors'] ?? array(), true ), 'Invalid redaction fixture should reject token_disclosed=true artifacts.', $invalid_json['artifacts']['redaction'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'redaction_missing_artifact:command_plan', $invalid_json['artifacts']['redaction']['errors'] ?? array(), true ), 'Invalid redaction fixture should reject missing command plan artifact redaction.', $invalid_json['artifacts']['redaction'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'redaction_missing_artifact:ux', $invalid_json['artifacts']['redaction']['errors'] ?? array(), true ), 'Invalid redaction fixture should reject missing UX evidence redaction.', $invalid_json['artifacts']['redaction'] ?? array() );
wp_agent_completion_fixture_assert( in_array( 'redaction_missing_artifact:redaction_report', $invalid_json['artifacts']['redaction']['errors'] ?? array(), true ), 'Invalid redaction fixture should reject missing redaction report self-check.', $invalid_json['artifacts']['redaction'] ?? array() );

echo json_encode( array(
	'success'                  => true,
	'contract'                 => 'final_live_completion_gate_fixture_contract',
	'valid_completion_ready'   => (bool) ( $valid_json['completion_ready'] ?? false ),
	'invalid_completion_ready' => (bool) ( $invalid_json['completion_ready'] ?? true ),
	'invalid_github_errors'    => $invalid_json['artifacts']['github']['errors'] ?? array(),
	'invalid_soak_errors'      => $invalid_json['artifacts']['soak']['errors'] ?? array(),
	'invalid_summary_errors'   => $invalid_json['artifacts']['summary']['errors'] ?? array(),
	'invalid_ux_errors'        => $invalid_json['artifacts']['ux']['errors'] ?? array(),
	'invalid_manifest_errors'  => $invalid_json['artifacts']['manifest']['errors'] ?? array(),
	'invalid_redaction_errors' => $invalid_json['artifacts']['redaction']['errors'] ?? array(),
	'secret_assignments'       => false,
	'live_network_calls'       => false,
	'ai_gateway_calls'         => false,
	'github_calls'             => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
