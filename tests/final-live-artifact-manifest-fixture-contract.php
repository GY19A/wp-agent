<?php
/**
 * Host-side final live artifact manifest fixture contract.
 *
 * Proves the artifact manifest contract validates real archive manifests, not
 * only the template and runbook. It creates temporary valid and invalid
 * manifest sets and runs final-live-artifact-manifest-contract.php against
 * them through WP_AGENT_FINAL_LIVE_MANIFEST_DIR. This script does not call
 * Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-artifact-manifest-fixture-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live artifact manifest fixture contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_manifest_fixture_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_manifest_fixture_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_manifest_fixture_fail( $message, $details );
	}
}

function wp_agent_manifest_fixture_rm_rf( $path ) {
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
			wp_agent_manifest_fixture_rm_rf( $child );
		} else {
			@unlink( $child );
		}
	}
	@rmdir( $path );
}

function wp_agent_manifest_fixture_write( $path, $text ) {
	$result = file_put_contents( $path, $text );
	wp_agent_manifest_fixture_assert( false !== $result, 'Could not write fixture file.', array(
		'path' => $path,
	) );
	return (int) $result;
}

function wp_agent_manifest_fixture_json( $path, $data ) {
	wp_agent_manifest_fixture_write( $path, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}

function wp_agent_manifest_fixture_command( $artifact_dir, $script ) {
	$command = 'WP_AGENT_FINAL_LIVE_MANIFEST_DIR=' . escapeshellarg( $artifact_dir ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_manifest_fixture_decode( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_manifest_fixture_artifact( $dir, $kind, $filename, $content, $validator ) {
	$path = $dir . '/' . $filename;
	wp_agent_manifest_fixture_write( $path, $content );
	return array(
		'kind'            => $kind,
		'path'            => $path,
		'sha256'          => hash_file( 'sha256', $path ),
		'size_bytes'      => filesize( $path ),
		'validated_by'    => $validator,
		'token_disclosed' => false,
	);
}

function wp_agent_manifest_fixture_artifacts( $dir, $specs ) {
	$artifacts = array();
	foreach ( $specs as $spec ) {
		$artifacts[] = wp_agent_manifest_fixture_artifact( $dir, $spec[0], $spec[1], $spec[2], $spec[3] );
	}
	return $artifacts;
}

function wp_agent_manifest_fixture_build_manifest( $dir, $artifacts ) {
	$artifacts_by_kind = array();
	foreach ( $artifacts as $artifact ) {
		$artifacts_by_kind[ (string) $artifact['kind'] ] = $artifact;
	}
	$command_plan_path = (string) ( $artifacts_by_kind['command_plan']['path'] ?? ( $dir . '/final-live-command-plan-20260622.json' ) );
	$command_plan_sha  = (string) ( $artifacts_by_kind['command_plan']['sha256'] ?? str_repeat( 'a', 64 ) );

	return array(
		'schema_version' => 1,
		'manifest_type'  => 'wp-agent-final-live-artifact-manifest',
		'status'         => 'reviewed_live_complete',
		'created_at'     => '2026-06-22T12:00:00Z',
		'created_by'     => 'fixture',
		'self_archive'   => array(
			'path'             => $dir . '/final-live-artifact-manifest-20260622.json',
			'self_hash_policy' => 'do_not_embed_self_hash; verify with sha256sum after archive and record in the final acceptance summary',
		),
		'official_stack' => array(
			'compose_file'     => 'docker-compose.official.yml',
			'project'          => 'wp-agent-official',
			'wordpress_image'  => 'wordpress:php8.3-apache',
			'wpcli_image'      => 'wordpress:cli-php8.3',
			'wordpress_url'    => 'http://localhost:12910',
			'database_dir'     => '/path/to/wp-agent/database/official-mysql',
		),
		'git'            => array(
			'head'                => '4a8150f73d99',
			'remote_push'         => false,
			'status_short'        => 'fixture clean',
			'git_hygiene_command' => 'php tests/git-hygiene-contract.php',
		),
		'inputs'         => array(
			'reviewed_input_source' => 'tests/final-live-inputs.example.env',
			'review_packet_source'  => 'tests/final-live-review-packet-20260622.md',
			'github_repository'     => 'wp-agent-fixtures/official-skills',
			'github_ref'            => 'v1.0.0',
			'github_skill_path'     => 'skills/news-rewrite-publisher',
			'github_review_policy'  => 'quarantine',
			'soak_source_url'       => 'https://wordpress.org/news/',
			'soak_approval_phrase'  => 'approve-multi-hour-soak',
			'soak_approval_confirmed' => true,
			'soak_seconds'          => 7200,
			'cost_budget_usd'       => 5,
			'artifact_policy'       => 'drafts_journal_usage',
			'token_disclosed'       => false,
		),
		'command_plan'   => array(
			'command'              => 'php tests/final-live-command-plan.php path/to/reviewed.env path/to/final-live-review-packet-YYYYMMDD.md',
			'artifact_path'        => $command_plan_path,
			'commands_executable'  => true,
			'ready_for_live_execution' => true,
			'review_packet_ready'  => true,
			'review_packet_env_consistent' => true,
			'placeholder_rejected' => false,
			'ux_validation_before_manifest' => true,
			'summary_before_manifest' => true,
			'output_sha256'        => $command_plan_sha,
		),
		'contracts'      => array(
			'no_live_acceptance' => array(
				'command'       => 'php tests/final-no-live-acceptance-contract.php',
				'success'       => true,
				'output_sha256' => str_repeat( 'b', 64 ),
			),
			'completion_gate'    => array(
				'command'          => 'php tests/final-live-completion-gate-contract.php',
				'completion_ready' => true,
				'output_sha256'    => str_repeat( 'c', 64 ),
			),
			'artifact_manifest' => array(
				'command' => 'php tests/final-live-artifact-manifest-contract.php',
				'success' => true,
			),
		),
		'artifacts'      => array_values( $artifacts ),
		'external_gates' => array(
			'row_6_skills_store' => 'passed',
			'row_9_daemon_soak'  => 'passed',
		),
		'security'       => array(
			'secret_scan'               => 'passed',
			'token_disclosed'           => false,
			'official_db_dir_confirmed' => true,
			'raw_secret_assignments'    => false,
		),
		'review'         => array(
			'reviewer' => 'fixture',
			'decision' => 'approved',
			'notes'    => '',
		),
	);
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_manifest_fixture_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$contract = $plugin_dir . '/tests/final-live-artifact-manifest-contract.php';
wp_agent_manifest_fixture_assert( is_file( $contract ), 'Artifact manifest contract is missing.' );

$base_dir = sys_get_temp_dir() . '/wp-agent-final-manifest-fixtures-' . getmypid();
$valid_dir = $base_dir . '/valid';
$invalid_dir = $base_dir . '/invalid';
$invalid_review_source_dir = $base_dir . '/invalid-review-source';
$invalid_command_ready_dir = $base_dir . '/invalid-command-ready';
@mkdir( $valid_dir, 0700, true );
@mkdir( $invalid_dir, 0700, true );
@mkdir( $invalid_review_source_dir, 0700, true );
@mkdir( $invalid_command_ready_dir, 0700, true );
wp_agent_manifest_fixture_assert( is_dir( $valid_dir ) && is_dir( $invalid_dir ) && is_dir( $invalid_review_source_dir ) && is_dir( $invalid_command_ready_dir ), 'Could not create manifest fixture directories.', array(
	'base_dir' => $base_dir,
) );
register_shutdown_function( 'wp_agent_manifest_fixture_rm_rf', $base_dir );

$artifact_specs = array(
	array( 'no_live_acceptance', 'final-no-live-acceptance-contract-20260622.md', "# no-live\n", 'tests/final-no-live-acceptance-contract.php' ),
	array( 'strict_preflight', 'final-acceptance-preflight-20260622.json', "{\"ready\":true}\n", 'tests/final-acceptance-preflight.php' ),
	array( 'command_plan', 'final-live-command-plan-20260622.json', "{\"commands_executable\":true,\"ready_for_live_execution\":true,\"review_packet_ready\":true,\"review_packet_env_consistent\":true,\"placeholder_rejected\":false,\"ux_validation_before_manifest\":true,\"summary_before_manifest\":true,\"secret_assignments\":false}\n", 'tests/final-live-command-plan.php' ),
	array( 'github_skill_store', 'final-live-github-skill-store-20260622.json', "{\"success\":true,\"token_disclosed\":false}\n", 'tests/final-live-completion-gate-contract.php' ),
	array( 'editorial_daemon_soak', 'final-live-editorial-daemon-soak-20260622.json', "{\"success\":true,\"soak_completed\":true}\n", 'tests/final-live-completion-gate-contract.php' ),
	array( 'git_hygiene', 'git-hygiene-contract-20260622.md', "# git hygiene\nremote_push=false\n", 'tests/git-hygiene-contract.php' ),
	array( 'ux_evidence', 'ui-playwright-evidence-contract-20260622.md', "# UX\ncontract=ui_playwright_evidence_contract\nux_quality_gate=true\nchat_stop_playwright=true\nchat_queue_status_playwright=true\nchat_stop_availability_playwright=true\ncomposer_unlocked_guard=true\noverflow_guard=true\nconsole_guard=true\ndesktop_mobile_pairs=13\nscreenshots_checked=26\n", 'tests/ui-playwright-evidence-contract.php' ),
	array( 'acceptance_summary', 'final-live-acceptance-summary-20260622.md', "# summary\ncompletion_ready=true\ntoken_disclosed=false\n", 'tests/final-live-completion-gate-contract.php' ),
	array( 'archive_redaction_report', 'final-live-archive-redaction-20260622.md', "# redaction\ncontract=final_live_archive_redaction_contract\ntoken_disclosed=false\nraw_secret_hits=0\n", 'tests/final-live-archive-redaction-contract.php' ),
);

$valid_artifacts = wp_agent_manifest_fixture_artifacts( $valid_dir, $artifact_specs );
$invalid_artifacts = wp_agent_manifest_fixture_artifacts( $invalid_dir, $artifact_specs );
$invalid_review_source_artifacts = wp_agent_manifest_fixture_artifacts( $invalid_review_source_dir, $artifact_specs );
$invalid_command_ready_artifacts = wp_agent_manifest_fixture_artifacts( $invalid_command_ready_dir, $artifact_specs );

$valid_manifest = wp_agent_manifest_fixture_build_manifest( $valid_dir, $valid_artifacts );
wp_agent_manifest_fixture_json( $valid_dir . '/final-live-artifact-manifest-20260622.json', $valid_manifest );

$invalid_manifest = wp_agent_manifest_fixture_build_manifest( $invalid_dir, $invalid_artifacts );
$invalid_manifest['inputs']['github_repository'] = 'owner/repo';
$invalid_manifest['inputs']['github_skill_path'] = 'skills/example';
$invalid_manifest['command_plan']['review_packet_env_consistent'] = false;
$invalid_manifest['security']['token_disclosed'] = true;
$invalid_manifest['artifacts'][2]['sha256'] = str_repeat( '0', 64 );
wp_agent_manifest_fixture_json( $invalid_dir . '/final-live-artifact-manifest-20260622.json', $invalid_manifest );

$invalid_review_source_manifest = wp_agent_manifest_fixture_build_manifest( $invalid_review_source_dir, $invalid_review_source_artifacts );
$invalid_review_source_manifest['inputs']['review_packet_source'] = 'tests/final-live-review-packet-template.md';
wp_agent_manifest_fixture_json( $invalid_review_source_dir . '/final-live-artifact-manifest-20260622.json', $invalid_review_source_manifest );

$invalid_command_ready_manifest = wp_agent_manifest_fixture_build_manifest( $invalid_command_ready_dir, $invalid_command_ready_artifacts );
$invalid_command_ready_manifest['command_plan']['ready_for_live_execution'] = false;
wp_agent_manifest_fixture_json( $invalid_command_ready_dir . '/final-live-artifact-manifest-20260622.json', $invalid_command_ready_manifest );

$valid_run = wp_agent_manifest_fixture_command( $valid_dir, $contract );
wp_agent_manifest_fixture_assert( 0 === $valid_run['status'], 'Valid manifest fixture should pass.', $valid_run );
$valid_json = wp_agent_manifest_fixture_decode( $valid_run['output'] );
wp_agent_manifest_fixture_assert( is_array( $valid_json ), 'Valid manifest fixture should print JSON.', $valid_run );
wp_agent_manifest_fixture_assert( true === (bool) ( $valid_json['success'] ?? false ), 'Valid manifest fixture should report success=true.', $valid_json );
wp_agent_manifest_fixture_assert( true === (bool) ( $valid_json['actual_manifest_present'] ?? false ), 'Valid manifest fixture should validate an actual manifest.', $valid_json );
wp_agent_manifest_fixture_assert( 'env' === (string) ( $valid_json['artifact_dir_source'] ?? '' ), 'Valid manifest fixture should use the env artifact directory.', $valid_json );

$invalid_run = wp_agent_manifest_fixture_command( $invalid_dir, $contract );
wp_agent_manifest_fixture_assert( 0 !== $invalid_run['status'], 'Invalid manifest fixture should fail.', $invalid_run );
wp_agent_manifest_fixture_assert( false !== strpos( $invalid_run['output'], 'Actual artifact manifest should record the official GitHub repository' ) || false !== strpos( $invalid_run['output'], 'review_packet_env_consistent=true' ) || false !== strpos( $invalid_run['output'], 'token_disclosed=false' ) || false !== strpos( $invalid_run['output'], 'sha256 does not match' ), 'Invalid manifest fixture should fail for a concrete manifest validation reason.', $invalid_run );

$invalid_review_source_run = wp_agent_manifest_fixture_command( $invalid_review_source_dir, $contract );
wp_agent_manifest_fixture_assert( 0 !== $invalid_review_source_run['status'], 'Invalid review packet source manifest fixture should fail.', $invalid_review_source_run );
wp_agent_manifest_fixture_assert( false !== strpos( $invalid_review_source_run['output'], 'completed review packet source' ) || false !== strpos( $invalid_review_source_run['output'], 'approved review packet source' ), 'Invalid review packet source fixture should fail for the review packet source guard.', $invalid_review_source_run );

$invalid_command_ready_run = wp_agent_manifest_fixture_command( $invalid_command_ready_dir, $contract );
wp_agent_manifest_fixture_assert( 0 !== $invalid_command_ready_run['status'], 'Invalid command-plan readiness manifest fixture should fail.', $invalid_command_ready_run );
wp_agent_manifest_fixture_assert( false !== strpos( $invalid_command_ready_run['output'], 'ready_for_live_execution=true' ), 'Invalid command-plan readiness fixture should fail for the live-ready guard.', $invalid_command_ready_run );

echo json_encode( array(
	'success'                 => true,
	'contract'                => 'final_live_artifact_manifest_fixture_contract',
	'valid_manifest_ready'    => true,
	'invalid_manifest_ready'  => false,
	'invalid_status'          => $invalid_run['status'],
	'invalid_review_packet_source_rejected' => true,
	'invalid_command_plan_ready_rejected' => true,
	'valid_artifacts_checked' => count( $valid_artifacts ),
	'secret_assignments'      => false,
	'live_network_calls'      => false,
	'ai_gateway_calls'        => false,
	'github_calls'            => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
