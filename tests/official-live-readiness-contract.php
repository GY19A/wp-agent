<?php
/**
 * Host-side official live readiness contract.
 *
 * Reads the final acceptance preflight report from the official WordPress
 * container and verifies the persisted AI settings, database path, and runtime
 * posture are ready for user-provided live inputs. This script does not call
 * GitHub or the AI gateway.
 *
 * Run from the host:
 * php tests/official-live-readiness-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This official live readiness contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_official_readiness_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_official_readiness_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_official_readiness_fail( $message, $details );
	}
}

function wp_agent_official_readiness_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_official_readiness_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_official_readiness_starts_with( $path, $prefix ) {
	$path   = rtrim( str_replace( '\\', '/', (string) $path ), '/' ) . '/';
	$prefix = rtrim( str_replace( '\\', '/', (string) $prefix ), '/' ) . '/';
	return 0 === strpos( $path, $prefix );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_official_readiness_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$compose_file = $plugin_dir . '/docker-compose.official.yml';
wp_agent_official_readiness_assert( is_file( $compose_file ), 'docker-compose.official.yml is missing.' );

$result = wp_agent_official_readiness_command( array(
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
	'wp-content/plugins/wp-agent/tests/final-acceptance-preflight.php',
	'--allow-root',
) );
wp_agent_official_readiness_assert( 0 === $result['status'], 'Final acceptance preflight report should run in the official WP-CLI container.', array(
	'status' => $result['status'],
	'output' => $result['output'],
) );

$report = wp_agent_official_readiness_json( $result['output'] );
wp_agent_official_readiness_assert( is_array( $report ), 'Final acceptance preflight should print JSON.', array(
	'output' => $result['output'],
) );
wp_agent_official_readiness_assert( true === (bool) ( $report['success'] ?? false ), 'Default preflight report mode should succeed.', $report );
wp_agent_official_readiness_assert( false === (bool) ( $report['strict'] ?? true ), 'Readiness contract must use non-strict report mode.' );
wp_agent_official_readiness_assert( false === (bool) ( $report['ready'] ?? true ), 'Default report should remain not-ready until live inputs are supplied.' );
wp_agent_official_readiness_assert( false === (bool) ( $report['all_ready'] ?? true ), 'Default report should not claim all final live gates are ready.' );

$github = $report['gates']['github_skill_store'] ?? array();
$soak   = $report['gates']['multi_hour_editorial_daemon_soak'] ?? array();
$runtime = $report['gates']['official_runtime'] ?? array();

wp_agent_official_readiness_assert( false === (bool) ( $github['token_disclosed'] ?? true ), 'GitHub token disclosure flag must remain false.', $github );
wp_agent_official_readiness_assert( in_array( 'WP_AGENT_LIVE_GITHUB_REPOSITORY', $github['missing'] ?? array(), true ), 'GitHub repository should remain an explicit external input before live acceptance.', $github );

wp_agent_official_readiness_assert( true === (bool) ( $soak['ai_ready'] ?? false ), 'Official persisted AI settings should be ready for text generation.', $soak );
wp_agent_official_readiness_assert( true === (bool) ( $soak['ai_content_ready'] ?? false ), 'Official persisted AI content readiness should be true.', $soak );
wp_agent_official_readiness_assert( 'decryptable' === (string) ( $soak['api_key_state'] ?? '' ), 'Official persisted API key should be decryptable without printing it.', $soak );
wp_agent_official_readiness_assert( '' !== trim( (string) ( $soak['model'] ?? '' ) ), 'Official chat model should be configured.', $soak );
wp_agent_official_readiness_assert( '' !== trim( (string) ( $soak['image_model'] ?? '' ) ), 'Official image model should be configured.', $soak );
wp_agent_official_readiness_assert( '' !== trim( (string) ( $soak['base_url_host'] ?? '' ) ), 'Official AI endpoint host should be reported without credentials.', $soak );
wp_agent_official_readiness_assert( '' === (string) ( $soak['source_url_error'] ?? '' ), 'Default live source URL should pass public URL validation.', $soak );
wp_agent_official_readiness_assert( true === (bool) ( $soak['database_persistence_declared'] ?? false ), 'Official DB persistence should be declared for soak preflight.', $soak );
wp_agent_official_readiness_assert( true === (bool) ( $soak['official_db_dir_is_default'] ?? false ), 'Official DB dir should use the default official-mysql path.', $soak );
wp_agent_official_readiness_assert( false === (bool) ( $soak['database_directory_inside_plugin'] ?? true ), 'Database directory must not exist inside the plugin directory.', $soak );

wp_agent_official_readiness_assert( true === (bool) ( $runtime['plugin_active'] ?? false ), 'WP Agent plugin should be active in the official stack.', $runtime );
wp_agent_official_readiness_assert( true === (bool) ( $runtime['database_ok'] ?? false ), 'Official runtime database ping should pass.', $runtime );
wp_agent_official_readiness_assert( true === (bool) ( $runtime['database_persistence_declared'] ?? false ), 'Official runtime should expose the DB persistence declaration.', $runtime );
wp_agent_official_readiness_assert( true === (bool) ( $runtime['official_db_dir_under_root'] ?? false ), 'Official DB dir should stay under the approved database root.', $runtime );
wp_agent_official_readiness_assert( true === (bool) ( $runtime['official_db_dir_is_default'] ?? false ), 'Official runtime should use the default official-mysql DB dir.', $runtime );
wp_agent_official_readiness_assert( false === (bool) ( $runtime['database_directory_inside_plugin'] ?? true ), 'Official runtime must not report a database directory inside the plugin.', $runtime );
wp_agent_official_readiness_assert( ! wp_agent_official_readiness_starts_with( $runtime['runtime_root'] ?? '', $plugin_dir ), 'Official runtime root must not live inside the plugin directory.', $runtime );

$raw_json = json_encode( $report, JSON_UNESCAPED_SLASHES );
wp_agent_official_readiness_assert( 0 === preg_match( '/"(?:api_key|token|authorization)"\s*:/i', $raw_json ), 'Preflight report must not include raw secret fields.', array(
	'matched_pattern' => '"api_key", "token", or "authorization"',
) );
wp_agent_official_readiness_assert( false === stripos( $raw_json, 'Bearer ' ), 'Preflight report must not include bearer tokens.' );

echo json_encode( array(
	'success'               => true,
	'official_live_readiness' => true,
	'ai'                    => array(
		'api_key_state' => (string) ( $soak['api_key_state'] ?? '' ),
		'model'         => (string) ( $soak['model'] ?? '' ),
		'image_model'   => (string) ( $soak['image_model'] ?? '' ),
		'base_url_host' => (string) ( $soak['base_url_host'] ?? '' ),
	),
	'official_runtime'      => array(
		'home_url'        => (string) ( $runtime['home_url'] ?? '' ),
		'runtime_root'    => (string) ( $runtime['runtime_root'] ?? '' ),
		'official_db_dir' => (string) ( $runtime['official_db_dir'] ?? '' ),
		'database_ok'     => (bool) ( $runtime['database_ok'] ?? false ),
	),
	'external_inputs_missing' => array(
		'github' => $github['missing'] ?? array(),
		'soak'   => $soak['missing'] ?? array(),
	),
	'secret_disclosed'      => false,
	'live_network_calls'   => false,
	'ai_gateway_calls'     => false,
	'github_calls'         => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
