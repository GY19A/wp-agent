<?php
/**
 * Host-side final live archive redaction contract.
 *
 * Scans final live archive templates, final-live artifacts, and UX evidence
 * artifacts for raw GitHub/API tokens, inline secret assignments, or
 * token_disclosed=true records. Set
 * WP_AGENT_FINAL_LIVE_REDACTION_DIR only for fixture validation. This script
 * reads local files only and does not call Docker, GitHub, WordPress, or the AI
 * gateway.
 *
 * Run from the host:
 * php tests/final-live-archive-redaction-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live archive redaction contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_redaction_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_redaction_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_redaction_fail( $message, $details );
	}
}

function wp_agent_redaction_read( $path ) {
	wp_agent_redaction_assert( is_file( $path ), 'Required redaction file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_redaction_assert( is_string( $text ), 'Required redaction file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_redaction_secret_patterns() {
	return array(
		'github_env_assignment' => '/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'ai_env_assignment'     => '/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'openai_key'            => '/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'github_token'          => '/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'github_pat'            => '/github_pat_[A-Za-z0-9_]{20,}/',
	);
}

function wp_agent_redaction_scan_text( $name, $text ) {
	$hits = array();
	foreach ( wp_agent_redaction_secret_patterns() as $label => $pattern ) {
		if ( 1 === preg_match( $pattern, $text ) ) {
			$hits[] = $label;
		}
	}
	wp_agent_redaction_assert( empty( $hits ), $name . ' appears to contain a raw token or inline secret assignment.', array(
		'hits' => $hits,
	) );
}

function wp_agent_redaction_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}
	wp_agent_redaction_assert( empty( $missing ), $name . ' is missing required redaction markers.', array(
		'missing' => $missing,
	) );
	return count( $markers );
}

function wp_agent_redaction_token_flag( $name, $path, $text ) {
	$compact = preg_replace( '/\s+/', '', $text );
	if ( false !== strpos( (string) $compact, 'token_disclosed=true' ) || false !== strpos( (string) $compact, '"token_disclosed":true' ) ) {
		wp_agent_redaction_fail( $name . ' records token_disclosed=true.', array(
			'path' => $path,
		) );
	}
	if ( false !== strpos( (string) $compact, 'token_disclosed=false' ) || false !== strpos( (string) $compact, '"token_disclosed":false' ) ) {
		return true;
	}
	if ( '.json' !== substr( $path, -5 ) ) {
		return false;
	}
	$data = json_decode( $text, true );
	if ( ! is_array( $data ) ) {
		return false;
	}
	$encoded = json_encode( $data, JSON_UNESCAPED_SLASHES );
	return false !== strpos( (string) $encoded, '"token_disclosed":false' );
}

function wp_agent_redaction_matching_files( $dir, $patterns ) {
	$files = array();
	foreach ( $patterns as $pattern ) {
		$matches = glob( rtrim( $dir, '/' ) . '/' . $pattern );
		if ( is_array( $matches ) ) {
			$files = array_merge( $files, $matches );
		}
	}
	$files = array_values( array_unique( $files ) );
	sort( $files );
	return $files;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_redaction_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$artifact_dir_override = getenv( 'WP_AGENT_FINAL_LIVE_REDACTION_DIR' );
$artifact_dir          = is_string( $artifact_dir_override ) && '' !== trim( $artifact_dir_override )
	? trim( $artifact_dir_override )
	: dirname( $plugin_dir ) . '/design/test-logs';
$artifact_dir_real     = realpath( $artifact_dir );
wp_agent_redaction_assert( $artifact_dir_real && is_dir( $artifact_dir_real ), 'Final live redaction artifact directory is missing.', array(
	'artifact_dir' => $artifact_dir,
) );

$docs = array(
	'README.md'                              => $plugin_dir . '/README.md',
	'goals.md'                               => $plugin_dir . '/goals.md',
	'final-live-report-template.md'          => $plugin_dir . '/tests/final-live-report-template.md',
	'final-live-inputs.example.env'          => $plugin_dir . '/tests/final-live-inputs.example.env',
	'final-live-review-packet-template.md'   => $plugin_dir . '/tests/final-live-review-packet-template.md',
	'final-live-artifact-manifest-template'  => $plugin_dir . '/tests/final-live-artifact-manifest-template.json',
);

$texts = array();
foreach ( $docs as $name => $path ) {
	$texts[ $name ] = wp_agent_redaction_read( $path );
	wp_agent_redaction_scan_text( $name, $texts[ $name ] );
}

$required_markers = 0;
$required_markers += wp_agent_redaction_require_markers( 'README.md', $texts['README.md'], array(
	'php tests/final-live-archive-redaction-contract.php',
	'php tests/final-live-archive-redaction-fixture-contract.php',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
) );
$required_markers += wp_agent_redaction_require_markers( 'goals.md', $texts['goals.md'], array(
	'tests/final-live-archive-redaction-contract.php',
	'tests/final-live-archive-redaction-fixture-contract.php',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'最终 live archive redaction',
) );
$required_markers += wp_agent_redaction_require_markers( 'final-live-report-template.md', $texts['final-live-report-template.md'], array(
	'php tests/final-live-archive-redaction-contract.php',
	'Archive redaction result: `token_disclosed=false`',
) );
$required_markers += wp_agent_redaction_require_markers( 'final-live-review-packet-template.md', $texts['final-live-review-packet-template.md'], array(
	'Do not paste tokens, API keys, passwords, or private repository credentials',
	'token_disclosed=false',
) );

$archive_files = wp_agent_redaction_matching_files( $artifact_dir_real, array(
	'final-live-github-skill-store-*.json',
	'final-live-editorial-daemon-soak-*.json',
	'final-live-command-plan-*.json',
	'final-live-acceptance-summary-*.md',
	'final-live-artifact-manifest-*.json',
	'final-live-archive-redaction-[0-9]*.md',
	'ui-playwright-evidence-contract-*.md',
) );

$token_flag_files = 0;
foreach ( $archive_files as $path ) {
	$name = basename( $path );
	$text = wp_agent_redaction_read( $path );
	wp_agent_redaction_scan_text( $name, $text );
	if ( wp_agent_redaction_token_flag( $name, $path, $text ) ) {
		++$token_flag_files;
	}
}

echo json_encode( array(
	'success'                => true,
	'contract'               => 'final_live_archive_redaction_contract',
	'artifact_dir'           => $artifact_dir_real,
	'artifact_dir_source'    => is_string( $artifact_dir_override ) && '' !== trim( $artifact_dir_override ) ? 'env' : 'default',
	'docs_checked'           => count( $docs ),
	'archive_files_scanned'  => count( $archive_files ),
	'token_flag_files'       => $token_flag_files,
	'required_markers'       => $required_markers,
	'secret_assignments'     => false,
	'raw_secret_hits'        => 0,
	'live_network_calls'     => false,
	'ai_gateway_calls'       => false,
	'github_calls'           => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
