<?php
/**
 * Host-side Git hygiene contract.
 *
 * Verifies this project remains a local-only development repository: remote
 * credentials are not embedded in local config, push URLs are disabled, and the
 * current HEAD is not already contained in the configured upstream ref. This
 * script does not fetch, push, or contact any remote service.
 *
 * Run from the host:
 * php tests/git-hygiene-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This Git hygiene contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_git_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_git_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_git_contract_fail( $message, $details );
	}
}

function wp_agent_git_contract_command( $args, $cwd ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	$previous_cwd = getcwd();
	chdir( $cwd );
	exec( $command, $output, $status );
	chdir( $previous_cwd );

	return array(
		'status' => $status,
		'output' => trim( implode( "\n", $output ) ),
	);
}

function wp_agent_git_contract_lines( $result ) {
	if ( '' === $result['output'] ) {
		return array();
	}
	return preg_split( '/\r\n|\r|\n/', $result['output'], -1, PREG_SPLIT_NO_EMPTY );
}

function wp_agent_git_contract_has_credentials( $url ) {
	return 1 === preg_match( '#^[a-z][a-z0-9+.-]*://[^/\s@]+:[^/\s@]+@#i', (string) $url )
		|| 1 === preg_match( '#^[a-z][a-z0-9+.-]*://[^/\s@]+@#i', (string) $url );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_git_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$inside = wp_agent_git_contract_command( array( 'git', 'rev-parse', '--is-inside-work-tree' ), $plugin_dir );
wp_agent_git_contract_assert( 0 === $inside['status'] && 'true' === $inside['output'], 'Repository must be a Git work tree.' );

$top = wp_agent_git_contract_command( array( 'git', 'rev-parse', '--show-toplevel' ), $plugin_dir );
wp_agent_git_contract_assert( 0 === $top['status'] && $plugin_dir === $top['output'], 'Git root should be the plugin repository.', array(
	'git_root_matches_plugin' => $plugin_dir === $top['output'],
) );

$remotes_result = wp_agent_git_contract_command( array( 'git', 'remote' ), $plugin_dir );
wp_agent_git_contract_assert( 0 === $remotes_result['status'], 'Could not inspect Git remotes.' );
$remotes = wp_agent_git_contract_lines( $remotes_result );

$push_disabled = true;
$credential_free = true;
$remote_summaries = array();
foreach ( $remotes as $remote ) {
	$url_result = wp_agent_git_contract_command( array( 'git', 'config', '--get-all', 'remote.' . $remote . '.url' ), $plugin_dir );
	$push_result = wp_agent_git_contract_command( array( 'git', 'config', '--get-all', 'remote.' . $remote . '.pushurl' ), $plugin_dir );
	$urls = wp_agent_git_contract_lines( $url_result );
	$push_urls = wp_agent_git_contract_lines( $push_result );

	foreach ( array_merge( $urls, $push_urls ) as $url ) {
		if ( wp_agent_git_contract_has_credentials( $url ) ) {
			$credential_free = false;
		}
	}

	$remote_push_disabled = in_array( 'DISABLED_FOR_LOCAL_ONLY_WP_AGENT', $push_urls, true );
	if ( ! $remote_push_disabled ) {
		$push_disabled = false;
	}

	$remote_summaries[] = array(
		'name'              => $remote,
		'fetch_url_count'   => count( $urls ),
		'push_url_count'    => count( $push_urls ),
		'push_disabled'     => $remote_push_disabled,
		'credential_free'   => true,
		'urls_redacted'     => true,
	);
}

wp_agent_git_contract_assert( $credential_free, 'Git remote URLs must not contain embedded credentials.' );
wp_agent_git_contract_assert( $push_disabled, 'Git remote push URLs must be disabled for local-only development.', array(
	'remotes' => $remote_summaries,
) );

$upstream_result = wp_agent_git_contract_command( array( 'git', 'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}' ), $plugin_dir );
$upstream = 0 === $upstream_result['status'] ? $upstream_result['output'] : '';
$ahead = 0;
$behind = 0;
$head_on_upstream = false;
if ( '' !== $upstream ) {
	$count_result = wp_agent_git_contract_command( array( 'git', 'rev-list', '--left-right', '--count', $upstream . '...HEAD' ), $plugin_dir );
	wp_agent_git_contract_assert( 0 === $count_result['status'], 'Could not compare HEAD with upstream.', array(
		'upstream' => $upstream,
	) );
	$parts = preg_split( '/\s+/', trim( $count_result['output'] ) );
	$behind = isset( $parts[0] ) ? (int) $parts[0] : 0;
	$ahead  = isset( $parts[1] ) ? (int) $parts[1] : 0;

	$ancestor = wp_agent_git_contract_command( array( 'git', 'merge-base', '--is-ancestor', 'HEAD', $upstream ), $plugin_dir );
	$head_on_upstream = 0 === $ancestor['status'];
	wp_agent_git_contract_assert( $ahead > 0 && ! $head_on_upstream, 'Current HEAD should remain local and not be contained in upstream.', array(
		'upstream' => $upstream,
		'ahead'    => $ahead,
		'behind'   => $behind,
	) );
}

$head = wp_agent_git_contract_command( array( 'git', 'rev-parse', '--short=12', 'HEAD' ), $plugin_dir );
wp_agent_git_contract_assert( 0 === $head['status'] && '' !== $head['output'], 'Could not resolve current HEAD.' );

echo json_encode( array(
	'success'              => true,
	'contract'             => 'git_hygiene_contract',
	'remote_count'         => count( $remotes ),
	'remote_push_disabled' => $push_disabled,
	'remote_credentials'   => false,
	'remote_urls_redacted' => true,
	'upstream'             => $upstream,
	'ahead_count'          => $ahead,
	'behind_count'         => $behind,
	'head_on_upstream'     => $head_on_upstream,
	'remote_push'          => false,
	'head'                 => $head['output'],
	'live_network_calls'   => false,
	'ai_gateway_calls'     => false,
	'github_calls'         => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
