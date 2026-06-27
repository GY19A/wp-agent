<?php
/**
 * Host-side plugin independence contract.
 *
 * Verifies WP Agent remains a standalone plugin: no WordPress core/theme/other
 * plugin files are vendored into this repository, the official container only
 * bind-mounts WP Agent into the plugin directory, and lifecycle cleanup remains
 * covered. This script does not start Docker or WordPress.
 *
 * Run from the host:
 * php tests/plugin-independence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This plugin independence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_independence_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_independence_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_independence_fail( $message, $details );
	}
}

function wp_agent_independence_read( $path ) {
	wp_agent_independence_assert( is_file( $path ), 'Required file is missing.', array(
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_independence_assert( is_string( $text ) && '' !== $text, 'Required file could not be read.', array(
		'path' => $path,
	) );

	return $text;
}

function wp_agent_independence_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_independence_assert( empty( $missing ), $name . ' is missing required independence markers.', array(
		'missing' => $missing,
	) );
}

function wp_agent_independence_contains_forbidden_url_credentials( $text ) {
	return 1 === preg_match( '#https?://[^/\s:@]+:[^/\s@]+@#i', $text );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_independence_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$forbidden_root_entries = array(
	'wp-admin',
	'wp-includes',
	'wp-content',
	'themes',
	'plugins',
	'mu-plugins',
	'wp-config.php',
	'wp-load.php',
	'index.php',
	'.htaccess',
	'nginx.conf',
	'apache.conf',
	'apache2.conf',
	'Dockerfile',
);

$violations = array();
foreach ( $forbidden_root_entries as $entry ) {
	if ( file_exists( $plugin_dir . DIRECTORY_SEPARATOR . $entry ) ) {
		$violations[] = $entry;
	}
}
wp_agent_independence_assert( empty( $violations ), 'Plugin repository must not contain WordPress core, theme/other-plugin, web-server, or custom-image files.', array(
	'violations' => $violations,
) );

$wp_agent      = wp_agent_independence_read( $plugin_dir . '/wp-agent.php' );
$uninstall     = wp_agent_independence_read( $plugin_dir . '/uninstall.php' );
$compose       = wp_agent_independence_read( $plugin_dir . '/docker-compose.official.yml' );
$readme        = wp_agent_independence_read( $plugin_dir . '/README.md' );
$goals         = wp_agent_independence_read( $plugin_dir . '/goals.md' );
$lifecycle     = wp_agent_independence_read( $plugin_dir . '/tests/plugin-lifecycle-cleanup.php' );
$coverage      = wp_agent_independence_read( $plugin_dir . '/tests/uninstall-coverage.php' );
$destructive   = wp_agent_independence_read( $plugin_dir . '/tests/uninstall-destructive.php' );

wp_agent_independence_require_markers( 'wp-agent.php', $wp_agent, array(
	'Plugin Name: WP Agent',
	'register_activation_hook',
	'register_deactivation_hook',
	'wp_agent_activate',
	'wp_agent_deactivate',
	'WPAgent_Schedules::schedule_cron',
	'WPAgent_Worker::schedule_cron',
	'WPAgent_Daemon::request_stop',
) );

wp_agent_independence_require_markers( 'uninstall.php', $uninstall, array(
	'WP_UNINSTALL_PLUGIN',
	'DROP TABLE IF EXISTS',
	'remove_role( \'wp_agent\' )',
	'wp_clear_scheduled_hook( \'wp_agent_daily_cleanup\' )',
	'wp_clear_scheduled_hook( \'wp_agent_cost_alert_check\' )',
	'wp_clear_scheduled_hook( \'wp_agent_check_schedules\' )',
	'wp_clear_scheduled_hook( \'wp_agent_worker_tick\' )',
) );

wp_agent_independence_require_markers( 'docker-compose.official.yml', $compose, array(
	'image: wordpress:php8.3-apache',
	'image: wordpress:cli-php8.3',
	'.:/var/www/html/wp-content/plugins/wp-agent',
	'official_wp_core:/var/www/html',
	'no agentd',
	'no agentd',
) );
foreach ( array(
	'build:',
	'privileged: true',
	'/var/www/html/wp-admin',
	'/var/www/html/wp-includes',
	'/var/www/html/wp-content/themes',
	'/etc/apache2',
	'/etc/nginx',
	'wp-content/mu-plugins',
	'agentd:',
) as $forbidden_compose_marker ) {
	wp_agent_independence_assert( false === strpos( $compose, $forbidden_compose_marker ), 'Official compose contains a forbidden independence marker.', array(
		'marker' => $forbidden_compose_marker,
	) );
}

wp_agent_independence_require_markers( 'README.md', $readme, array(
	'official WordPress images',
	'does not build a custom image',
	'does not',
	'`agentd` sidecar',
) );
wp_agent_independence_require_markers( 'goals.md', $goals, array(
	'插件独立性',
	'不得修改 WordPress 核心代码',
	'Web Server 配置',
	'主题/其他插件代码',
	'WP Agent 必须始终作为一个独立插件运行',
) );
wp_agent_independence_require_markers( 'plugin-lifecycle-cleanup.php', $lifecycle, array(
	'deactivate_plugins',
	'activate_plugin',
	'Cron hook should be cleared after deactivation',
	'data_tables_preserved',
) );
wp_agent_independence_require_markers( 'uninstall-coverage.php', $coverage, array(
	'Non-destructive uninstall coverage checks',
	'does not execute the',
	'uninstall handler',
	'created_tables',
	'cron_hooks',
) );
wp_agent_independence_require_markers( 'uninstall-destructive.php', $destructive, array(
	'WP_AGENT_DESTRUCTIVE_UNINSTALL',
	'WP_AGENT_UNINSTALL_THROWAWAY',
	'cron_hooks_cleared',
	'dropped_tables',
) );

wp_agent_independence_assert( ! wp_agent_independence_contains_forbidden_url_credentials( $readme . "\n" . $goals ), 'Documentation must not include embedded remote credentials.' );

echo json_encode( array(
	'success'             => true,
	'contract'            => 'plugin_independence_contract',
	'forbidden_root_entries' => count( $forbidden_root_entries ),
	'root_violations'     => 0,
	'activation_hook'     => true,
	'deactivation_hook'   => true,
	'uninstall_coverage'  => true,
	'official_plugin_bind_mount' => true,
	'core_webserver_theme_modifications' => false,
	'custom_image_required' => false,
	'sidecar_required'    => false,
	'live_network_calls'  => false,
	'ai_gateway_calls'    => false,
	'github_calls'        => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
