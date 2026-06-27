<?php
/**
 * Host-side live script gate contract check.
 *
 * Verifies live/cost-bearing helper scripts are inert unless their explicit
 * opt-in environment variable is set. This script does not call GitHub or the
 * AI gateway.
 *
 * Run from the host:
 * php tests/live-script-gates-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This live script gate contract check must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_live_gate_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_live_gate_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_live_gate_contract_fail( $message, $details );
	}
}

function wp_agent_live_gate_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_live_gate_contract_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_live_gate_contract_regex_offset( $pattern, $source ) {
	$matches = array();
	if ( 1 !== preg_match( $pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	return (int) $matches[0][1];
}

function wp_agent_live_gate_contract_static_check( $plugin_dir, $relative_path, $spec ) {
	$path = $plugin_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
	wp_agent_live_gate_contract_assert( is_file( $path ), $relative_path . ' is missing.' );

	$source = file_get_contents( $path );
	wp_agent_live_gate_contract_assert( is_string( $source ) && '' !== $source, $relative_path . ' could not be read.' );

	$flag        = $spec['flag'];
	$flag_regex  = '/getenv\s*\(\s*[\'"]' . preg_quote( $flag, '/' ) . '[\'"]\s*\)/';
	$gate_offset = wp_agent_live_gate_contract_regex_offset( $flag_regex, $source );
	wp_agent_live_gate_contract_assert( null !== $gate_offset, $relative_path . ' must gate execution with getenv(' . $flag . ').' );

	$skipped_offset = strpos( $source, "'skipped' => true" );
	if ( false === $skipped_offset ) {
		$skipped_offset = strpos( $source, '"skipped" => true' );
	}
	wp_agent_live_gate_contract_assert( false !== $skipped_offset, $relative_path . ' must return a skipped JSON payload when the live flag is absent.' );
	wp_agent_live_gate_contract_assert( $skipped_offset > $gate_offset, $relative_path . ' skipped payload must be inside the live flag gate.' );

	$return_offset = strpos( $source, 'return;', $skipped_offset );
	wp_agent_live_gate_contract_assert( false !== $return_offset, $relative_path . ' skipped gate must return before live work.' );
	wp_agent_live_gate_contract_assert( false !== strpos( $source, $flag, $skipped_offset ), $relative_path . ' skipped reason should name the required flag.' );

	$risky_offsets = array();
	foreach ( $spec['risky_markers'] as $marker ) {
		$offset = strpos( $source, $marker );
		if ( false !== $offset ) {
			$risky_offsets[ $marker ] = $offset;
		}
	}
	wp_agent_live_gate_contract_assert( ! empty( $risky_offsets ), $relative_path . ' must declare at least one known live-work marker.', array(
		'markers' => $spec['risky_markers'],
	) );

	$first_marker = array_search( min( $risky_offsets ), $risky_offsets, true );
	wp_agent_live_gate_contract_assert( $gate_offset < min( $risky_offsets ), $relative_path . ' live flag gate must appear before credential, network, import, or model work.', array(
		'first_marker'  => $first_marker,
		'gate_offset'   => $gate_offset,
		'marker_offset' => min( $risky_offsets ),
	) );

	return array(
		'file'         => $relative_path,
		'flag'         => $flag,
		'first_marker' => $first_marker,
	);
}

function wp_agent_live_gate_contract_dynamic_skip( $plugin_dir, $relative_path ) {
	$compose_file = $plugin_dir . DIRECTORY_SEPARATOR . 'docker-compose.official.yml';
	wp_agent_live_gate_contract_assert( is_file( $compose_file ), 'docker-compose.official.yml is missing.' );

	$args = array(
		'docker',
		'compose',
		'-p',
		'wp-agent-official',
		'-f',
		$compose_file,
		'--profile',
		'cli',
		'run',
		'--rm',
		'-T',
		'wpcli',
		'wp',
		'eval-file',
		'wp-content/plugins/wp-agent/' . $relative_path,
		'--allow-root',
	);

	$result = wp_agent_live_gate_contract_command( $args );
	wp_agent_live_gate_contract_assert( 0 === $result['status'], $relative_path . ' should exit successfully when skipped.', array(
		'status' => $result['status'],
		'output' => $result['output'],
	) );

	$json = wp_agent_live_gate_contract_json( $result['output'] );
	wp_agent_live_gate_contract_assert( is_array( $json ), $relative_path . ' skipped output should include JSON.', array(
		'output' => $result['output'],
	) );
	wp_agent_live_gate_contract_assert( true === (bool) ( $json['skipped'] ?? false ), $relative_path . ' must report skipped=true when the live flag is absent.', array(
		'output' => $result['output'],
	) );

	return array(
		'file'   => $relative_path,
		'reason' => (string) ( $json['reason'] ?? '' ),
	);
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_live_gate_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$scripts = array(
	'tests/import-live-ai-settings.php' => array(
		'flag'          => 'WP_AGENT_IMPORT_LIVE_AI_SETTINGS',
		'risky_markers' => array( 'stream_get_contents( STDIN )' ),
	),
	'tests/live-ai-content-e2e.php' => array(
		'flag'          => 'WP_AGENT_LIVE_AI',
		'risky_markers' => array( "WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' )" ),
	),
	'tests/live-daemon-soak.php' => array(
		'flag'          => 'WP_AGENT_LIVE_DAEMON',
		'risky_markers' => array( "WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' )" ),
	),
	'tests/live-editorial-daemon-soak.php' => array(
		'flag'          => 'WP_AGENT_LIVE_EDITORIAL_DAEMON',
		'risky_markers' => array( "WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' )" ),
	),
	'tests/live-editorial-repeat-budget.php' => array(
		'flag'          => 'WP_AGENT_LIVE_EDITORIAL_REPEAT',
		'risky_markers' => array( "WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' )" ),
	),
	'tests/live-github-skill-store.php' => array(
		'flag'          => 'WP_AGENT_LIVE_GITHUB_SKILLS',
		'risky_markers' => array( 'WPAgent_Skills::install_from_github' ),
	),
	'tests/live-image-generation.php' => array(
		'flag'          => 'WP_AGENT_LIVE_IMAGE',
		'risky_markers' => array( "WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' )" ),
	),
	'tests/live-schedule-skill.php' => array(
		'flag'          => 'WP_AGENT_LIVE_SCHEDULE',
		'risky_markers' => array( "WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' )" ),
	),
);

$discovered = glob( $plugin_dir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'live-*.php' );
$discovered[] = $plugin_dir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'import-live-ai-settings.php';
$discovered_rel = array();
foreach ( $discovered as $path ) {
	$discovered_rel[] = 'tests/' . basename( $path );
}
$discovered_rel = array_values( array_diff( $discovered_rel, array( 'tests/live-script-gates-contract.php' ) ) );
sort( $discovered_rel );

$expected_rel = array_keys( $scripts );
sort( $expected_rel );
wp_agent_live_gate_contract_assert( $expected_rel === $discovered_rel, 'Live script gate contract inventory is stale.', array(
	'expected'   => $expected_rel,
	'discovered' => $discovered_rel,
) );

$static_checks = array();
$dynamic_skips = array();
foreach ( $scripts as $relative_path => $spec ) {
	$static_checks[] = wp_agent_live_gate_contract_static_check( $plugin_dir, $relative_path, $spec );
	$dynamic_skips[] = wp_agent_live_gate_contract_dynamic_skip( $plugin_dir, $relative_path );
}

echo json_encode( array(
	'success'        => true,
	'checked_count'  => count( $scripts ),
	'static_checks'  => $static_checks,
	'dynamic_skips'  => $dynamic_skips,
	'live_network'   => false,
	'ai_gateway'     => false,
	'github_request' => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
