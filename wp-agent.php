<?php
/**
 * Plugin Name: WP Agent
 * Plugin URI: https://github.com/GY19A/wp-agent
 * Description: A natural-language WordPress agent for content, research, moderation, scheduling, and site management.
 * Version: 1.6.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: GY19A
 * Author URI: https://github.com/GY19A
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-agent
 * Domain Path: /languages
 *
 * WP Agent — natural-language AI agent for WordPress.
 * Copyright (C) 2026 GY19A <info@wp-agent.org>
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, version 2 or (at your option) any
 * later version, as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program (see LICENSE.txt). If not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_AGENT_VERSION', '1.6.0' );
define( 'WP_AGENT_PLUGIN_FILE', __FILE__ );
define( 'WP_AGENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_AGENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_AGENT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load core class explicitly (not prefixed with WPAgent_).
 */
require_once WP_AGENT_PLUGIN_DIR . 'includes/class-wp-agent.php';

/**
 * Autoloader for WP Agent classes.
 */
spl_autoload_register( function ( $class ) {
    // Skip if not a WP Agent class.
    if ( 'WPAgent' === $class ) {
        return; // Already loaded above.
    }

    $prefix = 'WPAgent_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $filename = 'class-wp-agent-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

    // Map classes that share a file with another class.
    $shared_files = array(
        'class-wp-agent-ai-response.php' => 'class-wp-agent-ai-provider.php',
        'class-wp-agent-tool.php'        => 'class-wp-agent-tools.php',
    );
    if ( isset( $shared_files[ $filename ] ) ) {
        $filename = $shared_files[ $filename ];
    }

    $directories = array(
        WP_AGENT_PLUGIN_DIR . 'includes/',
        WP_AGENT_PLUGIN_DIR . 'channels/',
        WP_AGENT_PLUGIN_DIR . 'tools/',
        WP_AGENT_PLUGIN_DIR . 'admin/',
    );

    foreach ( $directories as $directory ) {
        $filepath = $directory . $filename;
        if ( file_exists( $filepath ) ) {
            require_once $filepath;
            return;
        }
    }
} );

/**
 * Plugin activation.
 */
function wp_agent_activate() {
    wp_agent_create_tables();
    add_option( 'wp_agent_version', WP_AGENT_VERSION );
    add_option( 'wp_agent_activation_time', time() );
    add_option( 'wp_agent_daemon_max_children', WPAgent_Daemon::DEFAULT_MAX_CHILDREN );
    // Create the dedicated bounded agent role + user. Harmless if re-run on upgrade.
    WPAgent_Roles::ensure();
    // Register the cron interval so the recurring schedule check can be scheduled,
    // then schedule the minute-level schedule-check event.
    WPAgent_Schedules::init();
    WPAgent_Schedules::schedule_cron();
    WPAgent_Worker::init();
    WPAgent_Worker::schedule_cron();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wp_agent_activate' );

/**
 * Plugin deactivation.
 */
function wp_agent_deactivate() {
    wp_clear_scheduled_hook( 'wp_agent_daily_cleanup' );
    wp_clear_scheduled_hook( 'wp_agent_cost_alert_check' );
    WPAgent_Daemon::request_stop();
    WPAgent_Schedules::clear_cron();
    WPAgent_Worker::clear_cron();
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wp_agent_deactivate' );

/**
 * Create custom database tables.
 */
function wp_agent_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = array();

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_conversations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        channel VARCHAR(32) NOT NULL,
        channel_chat_id VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_channel (user_id, channel)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        role ENUM('user', 'assistant', 'system', 'tool') NOT NULL,
        content LONGTEXT NOT NULL,
        tool_calls JSON DEFAULT NULL,
        tool_results JSON DEFAULT NULL,
        tokens_in INT UNSIGNED DEFAULT 0,
        tokens_out INT UNSIGNED DEFAULT 0,
        model VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_conversation (conversation_id),
        INDEX idx_created (created_at)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_memories (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        fact TEXT NOT NULL,
        category VARCHAR(64) DEFAULT 'general',
        importance FLOAT DEFAULT 0.5,
        last_accessed DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_importance (importance)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_journal (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED DEFAULT NULL,
        run_id BIGINT UNSIGNED DEFAULT NULL,
        entry_type VARCHAR(32) NOT NULL DEFAULT 'note',
        title VARCHAR(190) NOT NULL,
        body LONGTEXT NOT NULL,
        metadata JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_date (user_id, created_at),
        INDEX idx_entry_type (entry_type)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_skills (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        slug VARCHAR(120) NOT NULL,
        name VARCHAR(190) NOT NULL,
        description TEXT DEFAULT NULL,
        triggers TEXT DEFAULT NULL,
        permissions JSON DEFAULT NULL,
        body LONGTEXT NOT NULL,
        visibility VARCHAR(16) NOT NULL DEFAULT 'private',
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        version INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_user_slug (user_id, slug),
        INDEX idx_user_status (user_id, status)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_pairings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        channel VARCHAR(32) NOT NULL,
        channel_user_id VARCHAR(255) NOT NULL,
        channel_chat_id VARCHAR(255) NOT NULL,
        paired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_channel_user (channel, channel_user_id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        channel VARCHAR(32) NOT NULL DEFAULT 'system',
        action VARCHAR(128) NOT NULL,
        details JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_date (user_id, created_at)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_usage (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        model VARCHAR(64) NOT NULL,
        tokens_in INT UNSIGNED NOT NULL,
        tokens_out INT UNSIGNED NOT NULL,
        estimated_cost DECIMAL(10,6) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_date (user_id, created_at)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NOT NULL,
        channel VARCHAR(32) NOT NULL DEFAULT 'webchat',
        status VARCHAR(32) NOT NULL DEFAULT 'queued',
        error TEXT DEFAULT NULL,
        loop_count INT UNSIGNED NOT NULL DEFAULT 0,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        next_attempt_at DATETIME DEFAULT NULL,
        last_error_code VARCHAR(64) DEFAULT NULL,
        locked_until DATETIME DEFAULT NULL,
        parent_run_id BIGINT UNSIGNED DEFAULT NULL,
        subagent_group VARCHAR(40) DEFAULT NULL,
        depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
        role VARCHAR(16) NOT NULL DEFAULT 'orchestrator',
        result_summary MEDIUMTEXT DEFAULT NULL,
        parent_tool_call_id VARCHAR(190) DEFAULT NULL,
        tool_policy_json MEDIUMTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_conversation (conversation_id),
        INDEX idx_channel (channel),
        INDEX idx_status (status),
        INDEX idx_claimable (status, next_attempt_at, locked_until),
        INDEX idx_parent_run (parent_run_id),
        INDEX idx_subagent_group (subagent_group)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_run_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        message TEXT DEFAULT NULL,
        metadata JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_run (run_id),
        INDEX idx_user_date (user_id, created_at),
        INDEX idx_type (event_type)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_confirmations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        actor_id BIGINT UNSIGNED NOT NULL,
        channel VARCHAR(32) NOT NULL DEFAULT 'webchat',
        tool_name VARCHAR(120) NOT NULL,
        tool_call_id VARCHAR(190) NOT NULL,
        action VARCHAR(80) DEFAULT NULL,
        operation_hash VARCHAR(64) NOT NULL,
        params LONGTEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        result LONGTEXT DEFAULT NULL,
        decided_by BIGINT UNSIGNED DEFAULT NULL,
        decided_at DATETIME DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_run_status (run_id, status),
        INDEX idx_user_status (user_id, status),
        UNIQUE KEY idx_run_hash (run_id, operation_hash)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_moderation (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        object_type VARCHAR(20) NOT NULL DEFAULT 'post',
        object_id BIGINT UNSIGNED NOT NULL,
        token VARCHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        requested_by BIGINT UNSIGNED NOT NULL,
        channel VARCHAR(32) DEFAULT 'webchat',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME DEFAULT NULL,
        UNIQUE KEY token (token),
        INDEX idx_status (status)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_syndication_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        object_id BIGINT UNSIGNED NOT NULL,
        target VARCHAR(20) NOT NULL,
        status VARCHAR(16) NOT NULL,
        remote_id VARCHAR(190) DEFAULT NULL,
        error TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_object (object_id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE {$wpdb->prefix}wp_agent_schedules (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        created_by BIGINT UNSIGNED NOT NULL,
        prompt LONGTEXT NOT NULL,
        skill_slug VARCHAR(191) DEFAULT NULL,
        schedule_interval VARCHAR(16) NOT NULL DEFAULT 'daily',
        interval_minutes SMALLINT UNSIGNED DEFAULT NULL,
        time_of_day VARCHAR(5) DEFAULT NULL,
        day_of_week TINYINT UNSIGNED DEFAULT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        next_run DATETIME DEFAULT NULL,
        locked_until DATETIME DEFAULT NULL,
        last_run DATETIME DEFAULT NULL,
        last_run_id BIGINT UNSIGNED DEFAULT NULL,
        last_status VARCHAR(16) DEFAULT NULL,
        last_summary TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status_next (status, next_run),
        INDEX idx_status_next_lock (status, next_run, locked_until),
        INDEX idx_created_by (created_by),
        INDEX idx_last_run_id (last_run_id),
        INDEX idx_skill_slug (skill_slug)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ( $sql as $query ) {
        dbDelta( $query );
    }
}

/**
 * Run upgrade routines when plugin version changes.
 */
function wp_agent_maybe_upgrade() {
    $stored_version = get_option( 'wp_agent_version', '0' );
    if ( version_compare( $stored_version, WP_AGENT_VERSION, '<' ) ) {
        wp_agent_create_tables(); // Safe to re-run — uses dbDelta.
        WPAgent::migrate_encryption();
        WPAgent_Schedules::init();
        WPAgent_Schedules::schedule_cron();
        update_option( 'wp_agent_version', WP_AGENT_VERSION );
    }
}

/**
 * Initialize the plugin.
 */
function wp_agent_init() {
    wp_agent_maybe_upgrade();
    WPAgent_CLI::register();
    $plugin = new WPAgent();
    $plugin->init();
}
add_action( 'plugins_loaded', 'wp_agent_init' );
