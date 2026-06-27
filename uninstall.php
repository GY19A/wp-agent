<?php
/**
 * WP Agent uninstall handler.
 *
 * Removes all plugin data when the plugin is deleted
 * (not just deactivated) from WordPress.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove custom tables.
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
    // Table name is safe: $wpdb->prefix is from WordPress core, suffix is hardcoded above.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$suffix}`" );
}

// Remove the dedicated agent user and role (read the stored id before options are purged).
$agent_user_id = get_option( 'wp_agent_user_id' );
if ( $agent_user_id ) {
    if ( ! function_exists( 'wp_delete_user' ) ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    wp_delete_user( (int) $agent_user_id );
}
if ( function_exists( 'remove_role' ) ) {
    remove_role( 'wp_agent' );
}

// Remove all plugin options.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( 'wp_agent_' ) . '%'
    )
);

// Remove user meta.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( 'wp_agent_' ) . '%'
    )
);

// Remove scheduled events.
wp_clear_scheduled_hook( 'wp_agent_daily_cleanup' );
wp_clear_scheduled_hook( 'wp_agent_cost_alert_check' );
wp_clear_scheduled_hook( 'wp_agent_check_schedules' );
wp_clear_scheduled_hook( 'wp_agent_worker_tick' );

// Remove plugin transients (rate limits, pairing codes, confirmations).
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_wp_agent_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_wp_agent_' ) . '%'
    )
);
