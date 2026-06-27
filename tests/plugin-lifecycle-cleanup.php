<?php
/**
 * WP Agent plugin lifecycle cleanup checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/plugin-lifecycle-cleanup.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This plugin lifecycle script must run through WP-CLI.\n" );
    exit( 1 );
}

if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'deactivate_plugins' ) || ! function_exists( 'activate_plugin' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

function wp_agent_lifecycle_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_lifecycle_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_lifecycle_fail( $message );
    }
}

function wp_agent_lifecycle_table_exists( $suffix ) {
    global $wpdb;
    $table = $wpdb->prefix . $suffix;
    return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

$plugin = WP_AGENT_PLUGIN_BASENAME;
wp_agent_lifecycle_assert( is_plugin_active( $plugin ), 'WP Agent must be active before lifecycle cleanup test.' );

// Seed all plugin-owned cron hooks so deactivation cleanup is directly verifiable.
if ( ! wp_next_scheduled( 'wp_agent_daily_cleanup' ) ) {
    wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_agent_daily_cleanup' );
}
if ( ! wp_next_scheduled( 'wp_agent_cost_alert_check' ) ) {
    wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wp_agent_cost_alert_check' );
}
WPAgent_Schedules::init();
WPAgent_Schedules::schedule_cron();
WPAgent_Worker::init();
WPAgent_Worker::schedule_cron();

foreach ( array( 'wp_agent_daily_cleanup', 'wp_agent_cost_alert_check', 'wp_agent_check_schedules', 'wp_agent_worker_tick' ) as $hook ) {
    wp_agent_lifecycle_assert( false !== wp_next_scheduled( $hook ), 'Expected cron hook to be scheduled before deactivation: ' . $hook );
}
wp_agent_lifecycle_assert( wp_agent_lifecycle_table_exists( 'wp_agent_runs' ), 'Runs table should exist before deactivation.' );

delete_option( 'wp_agent_daemon_stop_request' );
@unlink( WPAgent_Daemon::stop_file() );

deactivate_plugins( $plugin, false, false );
wp_agent_lifecycle_assert( ! is_plugin_active( $plugin ), 'WP Agent should be inactive after deactivate_plugins().' );

foreach ( array( 'wp_agent_daily_cleanup', 'wp_agent_cost_alert_check', 'wp_agent_check_schedules', 'wp_agent_worker_tick' ) as $hook ) {
    wp_agent_lifecycle_assert( false === wp_next_scheduled( $hook ), 'Cron hook should be cleared after deactivation: ' . $hook );
}

$stop_request = get_option( 'wp_agent_daemon_stop_request', array() );
$daemon_after_deactivation = WPAgent_Daemon::status();
$stop_recorded = is_array( $stop_request ) && ! empty( $stop_request['time'] ) && file_exists( WPAgent_Daemon::stop_file() );
$already_stopped_clean = empty( $daemon_after_deactivation['running'] ) && 'stopped' === ( $daemon_after_deactivation['status'] ?? '' ) && ! file_exists( WPAgent_Daemon::stop_file() );
wp_agent_lifecycle_assert( $stop_recorded || $already_stopped_clean, 'Deactivation should either request a running daemon stop or leave an already-stopped daemon clean.' );
wp_agent_lifecycle_assert( wp_agent_lifecycle_table_exists( 'wp_agent_runs' ), 'Deactivation must not drop plugin data tables.' );

$activation = activate_plugin( $plugin, '', false, false );
wp_agent_lifecycle_assert( ! is_wp_error( $activation ), is_wp_error( $activation ) ? $activation->get_error_message() : 'WP Agent reactivation failed.' );
wp_agent_lifecycle_assert( is_plugin_active( $plugin ), 'WP Agent should be active after activate_plugin().' );

wp_agent_lifecycle_assert( false !== wp_next_scheduled( 'wp_agent_check_schedules' ), 'Schedule cron should be restored after activation.' );
wp_agent_lifecycle_assert( false !== wp_next_scheduled( 'wp_agent_worker_tick' ), 'Worker cron should be restored after activation.' );

// Simulate the next normal plugin load in this CLI process so maintenance cron
// registration is also verified without requiring a second WP-CLI invocation.
$plugin_instance = new WPAgent();
$plugin_instance->init();
wp_agent_lifecycle_assert( false !== wp_next_scheduled( 'wp_agent_daily_cleanup' ), 'Daily cleanup cron should be restored during plugin init.' );
wp_agent_lifecycle_assert( false !== wp_next_scheduled( 'wp_agent_cost_alert_check' ), 'Cost alert cron should be restored during plugin init.' );

echo wp_json_encode( array(
    'success'                 => true,
    'deactivated_cleanly'     => true,
    'reactivated_cleanly'     => true,
    'stop_request_recorded'   => $stop_recorded,
    'already_stopped_clean'   => $already_stopped_clean,
    'stop_file_exists'        => file_exists( WPAgent_Daemon::stop_file() ),
    'data_tables_preserved'   => wp_agent_lifecycle_table_exists( 'wp_agent_runs' ),
    'restored_cron_hooks'     => array(
        'wp_agent_daily_cleanup'    => false !== wp_next_scheduled( 'wp_agent_daily_cleanup' ),
        'wp_agent_cost_alert_check' => false !== wp_next_scheduled( 'wp_agent_cost_alert_check' ),
        'wp_agent_check_schedules'  => false !== wp_next_scheduled( 'wp_agent_check_schedules' ),
        'wp_agent_worker_tick'      => false !== wp_next_scheduled( 'wp_agent_worker_tick' ),
    ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
