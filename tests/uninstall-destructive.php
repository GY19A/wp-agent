<?php
/**
 * Destructive uninstall handler acceptance.
 *
 * This script executes uninstall.php and deletes WP Agent data from the current
 * database. Run only inside a throwaway WordPress database:
 *
 * WP_AGENT_DESTRUCTIVE_UNINSTALL=1 WP_AGENT_UNINSTALL_THROWAWAY=1 \
 *   wp eval-file wp-content/plugins/wp-agent/tests/uninstall-destructive.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This destructive uninstall script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_uninstall_destructive_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_uninstall_destructive_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_uninstall_destructive_fail( $message );
    }
}

function wp_agent_uninstall_destructive_table_exists( $suffix ) {
    global $wpdb;
    $table = $wpdb->prefix . $suffix;
    return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

if ( '1' !== (string) getenv( 'WP_AGENT_DESTRUCTIVE_UNINSTALL' )
    || '1' !== (string) getenv( 'WP_AGENT_UNINSTALL_THROWAWAY' ) ) {
    echo wp_json_encode( array(
        'skipped' => true,
        'reason'  => 'Set WP_AGENT_DESTRUCTIVE_UNINSTALL=1 and WP_AGENT_UNINSTALL_THROWAWAY=1 inside a throwaway database.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    return;
}

global $wpdb;

$table_suffixes = array(
    'wp_agent_conversations',
    'wp_agent_messages',
    'wp_agent_memories',
    'wp_agent_journal',
    'wp_agent_skills',
    'wp_agent_pairings',
    'wp_agent_audit_log',
    'wp_agent_usage',
    'wp_agent_runs',
    'wp_agent_run_events',
    'wp_agent_confirmations',
    'wp_agent_moderation',
    'wp_agent_syndication_log',
    'wp_agent_schedules',
);

foreach ( $table_suffixes as $suffix ) {
    wp_agent_uninstall_destructive_assert( wp_agent_uninstall_destructive_table_exists( $suffix ), 'Expected table before uninstall: ' . $suffix );
}

WPAgent_Roles::ensure();
$agent_user_id = (int) get_option( 'wp_agent_user_id', 0 );
wp_agent_uninstall_destructive_assert( $agent_user_id > 0 && get_user_by( 'id', $agent_user_id ), 'Dedicated agent user should exist before uninstall.' );
wp_agent_uninstall_destructive_assert( get_role( 'wp_agent' ), 'Dedicated agent role should exist before uninstall.' );

WPAgent_Schedules::init();
WPAgent_Schedules::schedule_cron();
WPAgent_Worker::init();
WPAgent_Worker::schedule_cron();
if ( ! wp_next_scheduled( 'wp_agent_daily_cleanup' ) ) {
    wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_agent_daily_cleanup' );
}
if ( ! wp_next_scheduled( 'wp_agent_cost_alert_check' ) ) {
    wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wp_agent_cost_alert_check' );
}

$skill = WPAgent_Skills::save( 1, array(
    'name'        => 'Destructive Uninstall Fixture',
    'slug'        => 'destructive-uninstall-fixture',
    'description' => 'Fixture that should be removed by uninstall.',
    'triggers'    => array( 'destructive uninstall' ),
    'body'        => "## Workflow\n\nTemporary uninstall fixture.\n",
    'visibility'  => 'private',
) );
wp_agent_uninstall_destructive_assert( ! is_wp_error( $skill ), is_wp_error( $skill ) ? $skill->get_error_message() : 'Fixture Skill should be saved.' );

$schedule_id = WPAgent_Schedules::create( 1, 'Temporary uninstall fixture schedule.', 'minutes', null, null, 5, 'destructive-uninstall-fixture' );
wp_agent_uninstall_destructive_assert( $schedule_id > 0, 'Fixture schedule should be created.' );

update_option( 'wp_agent_destructive_uninstall_probe', 'remove-me' );
update_user_meta( 1, 'wp_agent_destructive_uninstall_probe', 'remove-me' );
set_transient( 'wp_agent_destructive_uninstall_probe', 'remove-me', HOUR_IN_SECONDS );

foreach ( array( 'wp_agent_daily_cleanup', 'wp_agent_cost_alert_check', 'wp_agent_check_schedules', 'wp_agent_worker_tick' ) as $hook ) {
    wp_agent_uninstall_destructive_assert( false !== wp_next_scheduled( $hook ), 'Expected cron hook before uninstall: ' . $hook );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    define( 'WP_UNINSTALL_PLUGIN', WP_AGENT_PLUGIN_BASENAME );
}
require WP_AGENT_PLUGIN_DIR . 'uninstall.php';

$remaining_tables = array();
foreach ( $table_suffixes as $suffix ) {
    if ( wp_agent_uninstall_destructive_table_exists( $suffix ) ) {
        $remaining_tables[] = $suffix;
    }
}
wp_agent_uninstall_destructive_assert( empty( $remaining_tables ), 'Uninstall left custom tables behind: ' . implode( ', ', $remaining_tables ) );

$remaining_options = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( 'wp_agent_' ) . '%'
) );
$remaining_user_meta = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like( 'wp_agent_' ) . '%'
) );
$remaining_transients = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_wp_agent_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_wp_agent_' ) . '%'
) );

wp_agent_uninstall_destructive_assert( 0 === $remaining_options, 'Uninstall should remove wp_agent options.' );
wp_agent_uninstall_destructive_assert( 0 === $remaining_user_meta, 'Uninstall should remove wp_agent user meta.' );
wp_agent_uninstall_destructive_assert( 0 === $remaining_transients, 'Uninstall should remove wp_agent transients.' );
wp_agent_uninstall_destructive_assert( ! get_user_by( 'id', $agent_user_id ), 'Dedicated agent user should be deleted.' );
wp_agent_uninstall_destructive_assert( ! get_role( 'wp_agent' ), 'Dedicated agent role should be removed.' );

$remaining_hooks = array();
foreach ( array( 'wp_agent_daily_cleanup', 'wp_agent_cost_alert_check', 'wp_agent_check_schedules', 'wp_agent_worker_tick' ) as $hook ) {
    if ( false !== wp_next_scheduled( $hook ) ) {
        $remaining_hooks[] = $hook;
    }
}
wp_agent_uninstall_destructive_assert( empty( $remaining_hooks ), 'Uninstall left cron hooks behind: ' . implode( ', ', $remaining_hooks ) );

echo wp_json_encode( array(
    'success'              => true,
    'dropped_tables'       => $table_suffixes,
    'remaining_options'    => $remaining_options,
    'remaining_user_meta'  => $remaining_user_meta,
    'remaining_transients' => $remaining_transients,
    'agent_user_deleted'   => true,
    'agent_role_removed'   => true,
    'cron_hooks_cleared'   => true,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
