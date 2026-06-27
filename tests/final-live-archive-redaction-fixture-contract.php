<?php
/**
 * Host-side final live archive redaction fixture contract.
 *
 * Proves the archive redaction contract accepts clean final-live and UX
 * evidence artifacts, and fails closed when a raw token or token_disclosed=true
 * appears. This script uses a temporary directory through
 * WP_AGENT_FINAL_LIVE_REDACTION_DIR and does not call Docker, GitHub,
 * WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-archive-redaction-fixture-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live archive redaction fixture contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_redaction_fixture_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_redaction_fixture_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_redaction_fixture_fail( $message, $details );
	}
}

function wp_agent_redaction_fixture_rm_rf( $path ) {
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
			wp_agent_redaction_fixture_rm_rf( $child );
		} else {
			@unlink( $child );
		}
	}
	@rmdir( $path );
}

function wp_agent_redaction_fixture_write( $path, $text ) {
	$result = file_put_contents( $path, $text );
	wp_agent_redaction_fixture_assert( false !== $result, 'Could not write redaction fixture file.', array(
		'path' => $path,
	) );
}

function wp_agent_redaction_fixture_command( $artifact_dir, $script ) {
	$command = 'WP_AGENT_FINAL_LIVE_REDACTION_DIR=' . escapeshellarg( $artifact_dir ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_redaction_fixture_decode( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_redaction_fixture_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$contract = $plugin_dir . '/tests/final-live-archive-redaction-contract.php';
wp_agent_redaction_fixture_assert( is_file( $contract ), 'Archive redaction contract is missing.' );

$base_dir = sys_get_temp_dir() . '/wp-agent-final-redaction-fixtures-' . getmypid();
$valid_dir = $base_dir . '/valid';
$invalid_dir = $base_dir . '/invalid';
@mkdir( $valid_dir, 0700, true );
@mkdir( $invalid_dir, 0700, true );
wp_agent_redaction_fixture_assert( is_dir( $valid_dir ) && is_dir( $invalid_dir ), 'Could not create redaction fixture directories.', array(
	'base_dir' => $base_dir,
) );
register_shutdown_function( 'wp_agent_redaction_fixture_rm_rf', $base_dir );

wp_agent_redaction_fixture_write( $valid_dir . '/final-live-github-skill-store-20260622.json', "{\"success\":true,\"token_disclosed\":false,\"repository\":\"wp-agent-fixtures/official-skills\"}\n" );
wp_agent_redaction_fixture_write( $valid_dir . '/final-live-editorial-daemon-soak-20260622.json', "{\"success\":true,\"soak_completed\":true,\"token_disclosed\":false}\n" );
wp_agent_redaction_fixture_write( $valid_dir . '/final-live-command-plan-20260622.json', "{\"commands_executable\":true,\"ready_for_live_execution\":true,\"token_disclosed\":false}\n" );
wp_agent_redaction_fixture_write( $valid_dir . '/final-live-acceptance-summary-20260622.md', "# summary\n\ntoken_disclosed=false\n" );
wp_agent_redaction_fixture_write( $valid_dir . '/final-live-artifact-manifest-20260622.json', "{\"manifest_type\":\"wp-agent-final-live-artifact-manifest\",\"security\":{\"token_disclosed\":false}}\n" );
wp_agent_redaction_fixture_write( $valid_dir . '/final-live-archive-redaction-20260622.md', "# redaction\n\ntoken_disclosed=false\nraw_secret_hits=0\n" );
wp_agent_redaction_fixture_write( $valid_dir . '/ui-playwright-evidence-contract-20260622.md', "# UX evidence\n\ncontract=ui_playwright_evidence_contract\ntoken_disclosed=false\n" );

wp_agent_redaction_fixture_write( $invalid_dir . '/final-live-github-skill-store-20260622.json', "{\"success\":true,\"token_disclosed\":false}\n" );
wp_agent_redaction_fixture_write( $invalid_dir . '/final-live-editorial-daemon-soak-20260622.json', "{\"success\":true,\"soak_completed\":true,\"token_disclosed\":false}\n" );
wp_agent_redaction_fixture_write( $invalid_dir . '/final-live-command-plan-20260622.json', "{\"commands_executable\":true,\"ready_for_live_execution\":true,\"token_disclosed\":true}\n" );
wp_agent_redaction_fixture_write( $invalid_dir . '/final-live-acceptance-summary-20260622.md', "# summary\n\ntoken_disclosed=false\n" );
wp_agent_redaction_fixture_write( $invalid_dir . '/final-live-artifact-manifest-20260622.json', "{\"manifest_type\":\"wp-agent-final-live-artifact-manifest\",\"security\":{\"token_disclosed\":false}}\n" );
wp_agent_redaction_fixture_write( $invalid_dir . '/final-live-archive-redaction-20260622.md', "# redaction\n\ntoken_disclosed=false\nraw_secret_hits=0\n" );
wp_agent_redaction_fixture_write( $invalid_dir . '/ui-playwright-evidence-contract-20260622.md', "# UX evidence\n\ncontract=ui_playwright_evidence_contract\ntoken_disclosed=false\n" );

$valid_run = wp_agent_redaction_fixture_command( $valid_dir, $contract );
wp_agent_redaction_fixture_assert( 0 === $valid_run['status'], 'Valid redaction fixture should pass.', $valid_run );
$valid_json = wp_agent_redaction_fixture_decode( $valid_run['output'] );
wp_agent_redaction_fixture_assert( is_array( $valid_json ), 'Valid redaction fixture should print JSON.', $valid_run );
wp_agent_redaction_fixture_assert( true === (bool) ( $valid_json['success'] ?? false ), 'Valid redaction fixture should report success=true.', $valid_json );
wp_agent_redaction_fixture_assert( 7 === (int) ( $valid_json['archive_files_scanned'] ?? 0 ), 'Valid redaction fixture should scan all final live and UX archive files.', $valid_json );
wp_agent_redaction_fixture_assert( 0 === (int) ( $valid_json['raw_secret_hits'] ?? 1 ), 'Valid redaction fixture should report no raw secret hits.', $valid_json );

$invalid_run = wp_agent_redaction_fixture_command( $invalid_dir, $contract );
wp_agent_redaction_fixture_assert( 0 !== $invalid_run['status'], 'Invalid redaction fixture should fail.', $invalid_run );
wp_agent_redaction_fixture_assert( false !== strpos( $invalid_run['output'], 'final-live-command-plan-20260622.json' ) && false !== strpos( $invalid_run['output'], 'token_disclosed=true' ), 'Invalid redaction fixture should fail for command plan token disclosure.', $invalid_run );

echo json_encode( array(
	'success'                => true,
	'contract'               => 'final_live_archive_redaction_fixture_contract',
	'valid_redaction_ready'  => true,
	'invalid_redaction_ready'=> false,
	'invalid_status'         => $invalid_run['status'],
	'valid_files_scanned'    => (int) ( $valid_json['archive_files_scanned'] ?? 0 ),
	'secret_assignments'     => false,
	'live_network_calls'     => false,
	'ai_gateway_calls'       => false,
	'github_calls'           => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
