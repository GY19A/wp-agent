<?php
/**
 * WP Agent official-container shared runtime root checks.
 *
 * Requires WordPress to be loaded. Examples:
 * WP_AGENT_RUNTIME_SHARED_MODE=write WP_AGENT_RUNTIME_SHARED_TOKEN=abc php -r 'require "/var/www/html/wp-load.php"; require "/var/www/html/wp-content/plugins/wp-agent/tests/runtime-root-shared.php";'
 * WP_AGENT_RUNTIME_SHARED_MODE=read WP_AGENT_RUNTIME_SHARED_TOKEN=abc wp eval-file wp-content/plugins/wp-agent/tests/runtime-root-shared.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This runtime-root sharing script must run with WordPress loaded under PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_runtime_shared_fail( $message ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	exit( 1 );
}

function wp_agent_runtime_shared_assert( $condition, $message ) {
	if ( ! $condition ) {
		wp_agent_runtime_shared_fail( $message );
	}
}

function wp_agent_runtime_shared_normalize( $path ) {
	return untrailingslashit( wp_normalize_path( (string) $path ) );
}

$mode  = sanitize_key( getenv( 'WP_AGENT_RUNTIME_SHARED_MODE' ) ?: 'write' );
$token = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( getenv( 'WP_AGENT_RUNTIME_SHARED_TOKEN' ) ?: '' ) );
if ( '' === $token ) {
	$token = 'default';
}

$configured_root = getenv( 'WP_AGENT_RUNTIME_ROOT' );
$runtime_root    = WPAgent_Sandbox::runtime_root();
$selection       = WPAgent_Sandbox::runtime_root_selection();
$site_root       = WPAgent_Sandbox::site_runtime_root();
$probe_dir       = WPAgent_Sandbox::runtime_area_dir( 'shared-probes' );
$probe_file      = trailingslashit( $probe_dir ) . $token . '.json';

wp_agent_runtime_shared_assert( '' !== (string) $configured_root, 'WP_AGENT_RUNTIME_ROOT should be configured in the official stack.' );
wp_agent_runtime_shared_assert(
	wp_agent_runtime_shared_normalize( $configured_root ) === wp_agent_runtime_shared_normalize( $runtime_root ),
	'Runtime root should use WP_AGENT_RUNTIME_ROOT instead of an unshared fallback.'
);
wp_agent_runtime_shared_assert( 'environment' === ( $selection['source'] ?? '' ), 'Runtime root source should be the official stack environment variable.' );
wp_agent_runtime_shared_assert( is_dir( $runtime_root ), 'Runtime root should exist.' );
wp_agent_runtime_shared_assert( wp_is_writable( $runtime_root ), 'Runtime root should be writable by the current PHP user.' );
wp_agent_runtime_shared_assert( is_dir( $site_root ), 'Site runtime root should exist.' );
wp_agent_runtime_shared_assert( wp_is_writable( $site_root ), 'Site runtime root should be writable by the current PHP user.' );
wp_agent_runtime_shared_assert( is_dir( $probe_dir ), 'Shared probe directory should exist.' );
wp_agent_runtime_shared_assert( wp_is_writable( $probe_dir ), 'Shared probe directory should be writable.' );

$uid = function_exists( 'posix_geteuid' ) ? (int) posix_geteuid() : -1;

if ( 'write' === $mode ) {
	$payload = array(
		'token'        => $token,
		'written_at'   => current_time( 'mysql', true ),
		'runtime_root' => $runtime_root,
		'site_root'    => $site_root,
		'uid'          => $uid,
		'sapi'         => PHP_SAPI,
	);
	$written = file_put_contents( $probe_file, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	wp_agent_runtime_shared_assert( false !== $written, 'Probe file should be writable.' );
	@chmod( $probe_file, 0600 );
	$result = array_merge( array( 'success' => true, 'mode' => 'write', 'probe_file' => $probe_file ), $payload );
	echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}

if ( 'read' === $mode || 'cleanup' === $mode ) {
	wp_agent_runtime_shared_assert( is_readable( $probe_file ), 'Probe file should be readable across official containers.' );
	$payload = json_decode( (string) file_get_contents( $probe_file ), true );
	wp_agent_runtime_shared_assert( is_array( $payload ), 'Probe file should contain JSON.' );
	wp_agent_runtime_shared_assert( $token === ( $payload['token'] ?? '' ), 'Probe file token should match.' );
	wp_agent_runtime_shared_assert(
		wp_agent_runtime_shared_normalize( $runtime_root ) === wp_agent_runtime_shared_normalize( $payload['runtime_root'] ?? '' ),
		'Reader and writer should report the same runtime root.'
	);

	if ( 'cleanup' === $mode ) {
		@unlink( $probe_file );
		wp_agent_runtime_shared_assert( ! file_exists( $probe_file ), 'Probe file should be removed during cleanup.' );
	}

	echo wp_json_encode( array(
		'success'      => true,
		'mode'         => $mode,
		'token'        => $token,
		'probe_file'   => $probe_file,
		'runtime_root' => $runtime_root,
		'site_root'    => $site_root,
		'writer_uid'   => (int) ( $payload['uid'] ?? -1 ),
		'reader_uid'   => $uid,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}

wp_agent_runtime_shared_fail( 'Unsupported WP_AGENT_RUNTIME_SHARED_MODE.' );
