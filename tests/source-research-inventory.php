<?php
/**
 * Host-side source research inventory check.
 *
 * This script verifies that external framework references are cloned outside
 * the plugin directory and are used only as research material.
 *
 * Run from the host:
 * php tests/source-research-inventory.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This source research inventory check must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_source_research_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_source_research_starts_with( $path, $prefix ) {
	$path   = rtrim( str_replace( '\\', '/', (string) $path ), '/' ) . '/';
	$prefix = rtrim( str_replace( '\\', '/', (string) $prefix ), '/' ) . '/';
	return 0 === strpos( $path, $prefix );
}

function wp_agent_source_research_git_head( $repo_dir ) {
	$command = 'git -C ' . escapeshellarg( $repo_dir ) . ' rev-parse --short HEAD 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	if ( 0 !== $status || empty( $output[0] ) ) {
		return '';
	}
	return trim( (string) $output[0] );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
// Resolve the source-research directory relative to the repository parent
// (the plugin lives at <repo>/wp-agent, research lives at <repo>/other_freameworks).
// An explicit override via WP_AGENT_RESEARCH_ROOT wins when set.
$research_env  = getenv( 'WP_AGENT_RESEARCH_ROOT' );
$research_root = $research_env
	? realpath( $research_env )
	: realpath( dirname( $plugin_dir ) . '/other_freameworks' );

if ( false === $plugin_dir ) {
	wp_agent_source_research_fail( 'Plugin directory could not be resolved.' );
}
if ( false === $research_root || ! is_dir( $research_root ) ) {
	wp_agent_source_research_fail( 'Source research directory is missing.', array(
		'expected' => dirname( (string) $plugin_dir ) . '/other_freameworks (override with WP_AGENT_RESEARCH_ROOT)',
	) );
}
if ( wp_agent_source_research_starts_with( $research_root, $plugin_dir ) ) {
	wp_agent_source_research_fail( 'Source research directory must not live inside the plugin directory.', array(
		'plugin_dir'    => $plugin_dir,
		'research_root' => $research_root,
	) );
}

$expected = array(
	'OpenHands'            => 'agent_reference',
	'OpenManus'            => 'agent_reference',
	'openclaw'             => 'agent_reference',
	'hermes-agent'         => 'agent_reference',
	'reactphp-event-loop'  => 'php_loop_reference',
	'amphp-amp'            => 'php_loop_reference',
	'workerman'            => 'php_loop_reference',
	'roadrunner'           => 'php_loop_reference',
	'laravel-octane'       => 'php_loop_reference',
	'swoole-src'           => 'php_loop_reference',
);

$repos      = array();
$violations = array();

foreach ( $expected as $dir_name => $purpose ) {
	$repo_dir = realpath( $research_root . DIRECTORY_SEPARATOR . $dir_name );
	if ( false === $repo_dir || ! is_dir( $repo_dir ) ) {
		$violations[] = array(
			'repository' => $dir_name,
			'problem'    => 'missing_directory',
		);
		continue;
	}
	if ( ! wp_agent_source_research_starts_with( $repo_dir, $research_root ) ) {
		$violations[] = array(
			'repository' => $dir_name,
			'problem'    => 'outside_research_root',
			'path'       => $repo_dir,
		);
		continue;
	}
	if ( wp_agent_source_research_starts_with( $repo_dir, $plugin_dir ) ) {
		$violations[] = array(
			'repository' => $dir_name,
			'problem'    => 'vendored_inside_plugin',
			'path'       => $repo_dir,
		);
		continue;
	}
	if ( ! is_dir( $repo_dir . DIRECTORY_SEPARATOR . '.git' ) ) {
		$violations[] = array(
			'repository' => $dir_name,
			'problem'    => 'not_a_git_clone',
			'path'       => $repo_dir,
		);
		continue;
	}

	$head = wp_agent_source_research_git_head( $repo_dir );
	if ( '' === $head ) {
		$violations[] = array(
			'repository' => $dir_name,
			'problem'    => 'git_head_unreadable',
			'path'       => $repo_dir,
		);
		continue;
	}

	$repos[] = array(
		'name'    => $dir_name,
		'purpose' => $purpose,
		'path'    => $repo_dir,
		'head'    => $head,
	);
}

$plugin_external_refs = glob( $plugin_dir . DIRECTORY_SEPARATOR . 'other_freameworks*' );
if ( ! empty( $plugin_external_refs ) ) {
	$violations[] = array(
		'problem' => 'research_directory_inside_plugin',
		'paths'   => $plugin_external_refs,
	);
}

if ( ! empty( $violations ) ) {
	wp_agent_source_research_fail( 'Source research inventory failed.', $violations );
}

echo json_encode( array(
	'success'       => true,
	'plugin_dir'    => $plugin_dir,
	'research_root' => $research_root,
	'repo_count'    => count( $repos ),
	'repositories'  => $repos,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
