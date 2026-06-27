<?php
/**
 * Host-side database persistence contract.
 *
 * Verifies the development and official WordPress acceptance databases are
 * persisted outside the plugin repository and protected from accidental Git
 * inclusion. This script does not start Docker or connect to MySQL.
 *
 * Run from the host:
 * php tests/database-persistence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This database persistence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_db_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_db_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_db_contract_fail( $message, $details );
	}
}

function wp_agent_db_contract_read( $path ) {
	wp_agent_db_contract_assert( is_file( $path ), 'Required file is missing.', array(
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_db_contract_assert( is_string( $text ) && '' !== $text, 'Required file could not be read.', array(
		'path' => $path,
	) );

	return $text;
}

function wp_agent_db_contract_path_inside( $path, $parent ) {
	$path   = rtrim( str_replace( '\\', '/', (string) $path ), '/' ) . '/';
	$parent = rtrim( str_replace( '\\', '/', (string) $parent ), '/' ) . '/';
	return 0 === strpos( $path, $parent );
}

function wp_agent_db_contract_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_db_contract_assert( empty( $missing ), $name . ' is missing required database persistence markers.', array(
		'missing' => $missing,
	) );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_db_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$workspace_dir = realpath( dirname( $plugin_dir ) );
wp_agent_db_contract_assert( $workspace_dir && is_dir( $workspace_dir ), 'Workspace directory could not be resolved.' );

$database_root = $workspace_dir . '/database';
$dev_db_dir    = $database_root . '/mysql';
$official_dir  = $database_root . '/official-mysql';
$dumps_dir     = $database_root . '/dumps';

foreach ( array(
	'database_root' => $database_root,
	'dev_db_dir'    => $dev_db_dir,
	'official_dir'  => $official_dir,
	'dumps_dir'     => $dumps_dir,
) as $label => $path ) {
	wp_agent_db_contract_assert( is_dir( $path ), 'Required database persistence directory is missing.', array(
		'label' => $label,
		'path'  => $path,
	) );
	wp_agent_db_contract_assert( ! wp_agent_db_contract_path_inside( realpath( $path ), $plugin_dir ), 'Database persistence directory must stay outside the plugin repository.', array(
		'label'      => $label,
		'path'       => $path,
		'plugin_dir' => $plugin_dir,
	) );
}

wp_agent_db_contract_assert( count( glob( $dev_db_dir . '/*' ) ?: array() ) > 0, 'Development MySQL datadir should contain persisted files.', array(
	'path' => $dev_db_dir,
) );
wp_agent_db_contract_assert( count( glob( $official_dir . '/*' ) ?: array() ) > 0, 'Official MySQL datadir should contain persisted files.', array(
	'path' => $official_dir,
) );
wp_agent_db_contract_assert( count( glob( $dumps_dir . '/*.sql' ) ?: array() ) + count( glob( $dumps_dir . '/*.sql.gz' ) ?: array() ) > 0, 'Database dumps directory should contain SQL dump artifacts.', array(
	'path' => $dumps_dir,
) );

$dev_compose      = wp_agent_db_contract_read( $workspace_dir . '/docker-compose.yml' );
$official_compose = wp_agent_db_contract_read( $plugin_dir . '/docker-compose.official.yml' );
$gitignore        = wp_agent_db_contract_read( $plugin_dir . '/.gitignore' );
$readme           = wp_agent_db_contract_read( $plugin_dir . '/README.md' );
$goals            = wp_agent_db_contract_read( $plugin_dir . '/goals.md' );

wp_agent_db_contract_require_markers( 'parent docker-compose.yml', $dev_compose, array(
	'./database/mysql:/var/lib/mysql',
	'Keep the development database on the host',
) );

wp_agent_db_contract_require_markers( 'docker-compose.official.yml', $official_compose, array(
	'${WP_AGENT_OFFICIAL_DB_DIR:-/path/to/wp-agent/database/official-mysql}:/var/lib/mysql',
	'WP_AGENT_OFFICIAL_DB_DIR',
	'official-mysql',
) );

wp_agent_db_contract_require_markers( '.gitignore', $gitignore, array(
	'database/',
	'*.sql',
	'*.sql.gz',
) );

wp_agent_db_contract_require_markers( 'README.md', $readme, array(
	'/path/to/wp-agent/database/official-mysql',
	'WP_AGENT_OFFICIAL_DB_DIR',
	'plugin settings',
) );

wp_agent_db_contract_require_markers( 'goals.md', $goals, array(
	'/path/to/wp-agent/database/mysql',
	'/path/to/wp-agent/database/official-mysql',
	'/path/to/wp-agent/database/dumps',
	'不得提交或公开数据库目录',
) );

wp_agent_db_contract_assert( ! is_dir( $plugin_dir . '/database' ), 'Plugin repository must not contain a database directory.', array(
	'path' => $plugin_dir . '/database',
) );

echo json_encode( array(
	'success'            => true,
	'contract'           => 'database_persistence_contract',
	'database_root'      => $database_root,
	'dev_db_dir'         => $dev_db_dir,
	'official_db_dir'    => $official_dir,
	'dumps_dir'          => $dumps_dir,
	'dev_db_files'       => count( glob( $dev_db_dir . '/*' ) ?: array() ),
	'official_db_files'  => count( glob( $official_dir . '/*' ) ?: array() ),
	'dump_files'         => count( glob( $dumps_dir . '/*.sql' ) ?: array() ) + count( glob( $dumps_dir . '/*.sql.gz' ) ?: array() ),
	'plugin_database_dir' => false,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
