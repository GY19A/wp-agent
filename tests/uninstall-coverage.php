<?php
/**
 * Non-destructive uninstall coverage checks.
 *
 * This script verifies that uninstall.php names every custom table created by
 * wp-agent.php and clears every plugin-owned cron hook. It does not execute the
 * uninstall handler or delete data.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/uninstall-coverage.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This uninstall coverage script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_uninstall_coverage_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_uninstall_coverage_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_uninstall_coverage_fail( $message );
    }
}

$plugin_file   = WP_AGENT_PLUGIN_DIR . 'wp-agent.php';
$uninstall_file = WP_AGENT_PLUGIN_DIR . 'uninstall.php';

$plugin_source = file_get_contents( $plugin_file );
$uninstall_source = file_get_contents( $uninstall_file );
wp_agent_uninstall_coverage_assert( false !== $plugin_source, 'Could not read wp-agent.php.' );
wp_agent_uninstall_coverage_assert( false !== $uninstall_source, 'Could not read uninstall.php.' );

preg_match_all( '/CREATE TABLE \{\$wpdb->prefix\}([a-z0-9_]+)/', $plugin_source, $created_matches );
$created_tables = array_values( array_unique( $created_matches[1] ?? array() ) );
sort( $created_tables );
wp_agent_uninstall_coverage_assert( ! empty( $created_tables ), 'No created WP Agent tables were detected.' );

preg_match_all( "/'((?:wp_agent_)[a-z0-9_]+)'/", $uninstall_source, $uninstall_matches );
$uninstall_tables = array_values( array_unique( array_intersect( $uninstall_matches[1] ?? array(), $created_tables ) ) );
sort( $uninstall_tables );

$missing_tables = array_values( array_diff( $created_tables, $uninstall_tables ) );
wp_agent_uninstall_coverage_assert( empty( $missing_tables ), 'uninstall.php does not drop every created table: ' . implode( ', ', $missing_tables ) );

$required_cron_hooks = array(
    'wp_agent_daily_cleanup',
    'wp_agent_cost_alert_check',
    'wp_agent_check_schedules',
    'wp_agent_worker_tick',
);
$missing_hooks = array();
foreach ( $required_cron_hooks as $hook ) {
    if ( false === strpos( $uninstall_source, 'wp_clear_scheduled_hook' )
        || false === strpos( $uninstall_source, $hook ) ) {
        $missing_hooks[] = $hook;
    }
}
wp_agent_uninstall_coverage_assert( empty( $missing_hooks ), 'uninstall.php does not clear every plugin cron hook: ' . implode( ', ', $missing_hooks ) );

echo wp_json_encode( array(
    'success'          => true,
    'created_tables'   => $created_tables,
    'uninstall_tables' => $uninstall_tables,
    'cron_hooks'       => $required_cron_hooks,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
