<?php
/**
 * WP Agent runtime-root diagnostics checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/runtime-root-diagnostics.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This runtime-root diagnostics script must run through WP-CLI.\n" );
    exit( 1 );
}

if ( ! function_exists( 'add_settings_error' ) ) {
    require_once ABSPATH . 'wp-admin/includes/template.php';
}

function wp_agent_runtime_root_diag_fail( $message ) {
    if ( function_exists( 'wp_agent_runtime_root_diag_cleanup' ) ) {
        wp_agent_runtime_root_diag_cleanup();
    }
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_runtime_root_diag_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_runtime_root_diag_fail( $message );
    }
}

function wp_agent_runtime_root_diag_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

$GLOBALS['wp_agent_runtime_root_diag_previous']      = get_option( 'wp_agent_runtime_root', '' );
$GLOBALS['wp_agent_runtime_root_diag_cleanup_dirs']  = array();
$GLOBALS['wp_agent_runtime_root_diag_restored']      = false;

function wp_agent_runtime_root_diag_cleanup() {
    if ( empty( $GLOBALS['wp_agent_runtime_root_diag_restored'] ) ) {
        update_option( 'wp_agent_runtime_root', $GLOBALS['wp_agent_runtime_root_diag_previous'], false );
        $GLOBALS['wp_agent_runtime_root_diag_restored'] = true;
    }
    foreach ( $GLOBALS['wp_agent_runtime_root_diag_cleanup_dirs'] as $dir ) {
        if ( is_string( $dir ) && '' !== $dir && is_dir( $dir ) ) {
            @rmdir( $dir );
        }
    }
}

register_shutdown_function( 'wp_agent_runtime_root_diag_cleanup' );

$previous = $GLOBALS['wp_agent_runtime_root_diag_previous'];
$env_root = getenv( 'WP_AGENT_RUNTIME_ROOT' );
$env_root = false !== $env_root ? trim( (string) $env_root ) : '';

$selection = WPAgent_Sandbox::runtime_root_selection();
wp_agent_runtime_root_diag_assert( '' !== (string) ( $selection['runtime_root'] ?? '' ), 'Runtime root selection should include the active root.' );
wp_agent_runtime_root_diag_assert( '' !== (string) ( $selection['source'] ?? '' ), 'Runtime root selection should include the active source.' );
wp_agent_runtime_root_diag_assert( '' !== (string) ( $selection['source_label'] ?? '' ), 'Runtime root selection should include the active source label.' );
wp_agent_runtime_root_diag_assert( ! empty( $selection['candidates'] ) && is_array( $selection['candidates'] ), 'Runtime root selection should include candidate statuses.' );
if ( '' !== $env_root && ! defined( 'WP_AGENT_RUNTIME_ROOT' ) ) {
    wp_agent_runtime_root_diag_assert( 'environment' === ( $selection['source'] ?? '' ), 'WP_AGENT_RUNTIME_ROOT should be reported as the runtime root source.' );
    wp_agent_runtime_root_diag_assert(
        wp_agent_runtime_root_diag_path_starts_with( $selection['runtime_root'] ?? '', $env_root ),
        'Runtime root selection should use WP_AGENT_RUNTIME_ROOT.'
    );
}

$relative = WPAgent_Sandbox::runtime_root_status( 'relative/runtime-root', false );
wp_agent_runtime_root_diag_assert( empty( $relative['ok'] ), 'Relative runtime root should be rejected.' );
wp_agent_runtime_root_diag_assert( 'not_absolute' === ( $relative['code'] ?? '' ), 'Relative runtime root should explain not_absolute.' );
wp_agent_runtime_root_diag_assert( '' !== ( $relative['message'] ?? '' ), 'Relative runtime root should include a message.' );

$traversal = WPAgent_Sandbox::runtime_root_status( trailingslashit( sys_get_temp_dir() ) . 'wp-agent/../escape', false );
wp_agent_runtime_root_diag_assert( empty( $traversal['ok'] ), 'Traversal runtime root should be rejected.' );
wp_agent_runtime_root_diag_assert( 'traversal' === ( $traversal['code'] ?? '' ), 'Traversal runtime root should explain traversal.' );

$public = WPAgent_Sandbox::runtime_root_status( trailingslashit( WP_AGENT_PLUGIN_DIR ) . 'runtime-root', false );
wp_agent_runtime_root_diag_assert( empty( $public['ok'] ), 'Plugin-directory runtime root should be rejected.' );
wp_agent_runtime_root_diag_assert( 'public_path' === ( $public['code'] ?? '' ), 'Plugin-directory runtime root should explain public_path.' );

$admin = new WPAgent_Admin();
update_option( 'wp_agent_runtime_root', $previous, false );
$settings_public = $admin->sanitize_runtime_root( trailingslashit( WP_AGENT_PLUGIN_DIR ) . 'settings-public-root' );
wp_agent_runtime_root_diag_assert( $previous === $settings_public, 'Settings sanitizer should preserve previous root when public path is rejected.' );

$settings_private_root = trailingslashit( sys_get_temp_dir() ) . 'wp-agent-settings-runtime-diagnostics-' . wp_generate_password( 8, false, false );
$settings_private = $admin->sanitize_runtime_root( $settings_private_root );
wp_agent_runtime_root_diag_assert( wp_normalize_path( $settings_private_root ) === $settings_private, 'Settings sanitizer should normalize accepted private roots.' );
wp_agent_runtime_root_diag_assert( is_dir( $settings_private ), 'Settings sanitizer should create accepted private roots.' );
$GLOBALS['wp_agent_runtime_root_diag_cleanup_dirs'][] = $settings_private;

$private_root = trailingslashit( sys_get_temp_dir() ) . 'wp-agent-runtime-diagnostics-' . wp_generate_password( 8, false, false );
$private = WPAgent_Sandbox::runtime_root_status( $private_root, true );
wp_agent_runtime_root_diag_assert( ! empty( $private['ok'] ), 'Private temp runtime root should be accepted and created.' );
wp_agent_runtime_root_diag_assert( 'ok' === ( $private['code'] ?? '' ), 'Private temp runtime root should report ok.' );
wp_agent_runtime_root_diag_assert( ! empty( $private['created'] ), 'Private temp runtime root should report created.' );
wp_agent_runtime_root_diag_assert( is_dir( $private['normalized'] ?? '' ), 'Private temp runtime root should exist after creation.' );
wp_agent_runtime_root_diag_assert( wp_is_writable( $private['normalized'] ?? '' ), 'Private temp runtime root should be writable.' );
$GLOBALS['wp_agent_runtime_root_diag_cleanup_dirs'][] = $private['normalized'] ?? $private_root;

update_option( 'wp_agent_runtime_root', trailingslashit( WP_AGENT_PLUGIN_DIR ) . 'diagnostics-public-root', false );
$diagnostics = WPAgent_Diagnostics::runtime();
wp_agent_runtime_root_diag_assert( ! empty( $diagnostics['storage'] ), 'Runtime diagnostics should include storage.' );
$storage = $diagnostics['storage'];
wp_agent_runtime_root_diag_assert( '' !== (string) ( $storage['active_source'] ?? '' ), 'Runtime diagnostics should include active source.' );
wp_agent_runtime_root_diag_assert( '' !== (string) ( $storage['active_source_label'] ?? '' ), 'Runtime diagnostics should include active source label.' );
wp_agent_runtime_root_diag_assert( array_key_exists( 'effective_configured', $storage ), 'Runtime diagnostics should include effective configuration state.' );
wp_agent_runtime_root_diag_assert( array_key_exists( 'configured_by', $storage ), 'Runtime diagnostics should include configured source.' );
wp_agent_runtime_root_diag_assert( array_key_exists( 'setting_root', $storage ), 'Runtime diagnostics should include setting root.' );
wp_agent_runtime_root_diag_assert( array_key_exists( 'constant_root', $storage ), 'Runtime diagnostics should include constant root.' );
wp_agent_runtime_root_diag_assert( array_key_exists( 'env_root', $storage ), 'Runtime diagnostics should include environment root.' );
wp_agent_runtime_root_diag_assert( ! empty( $storage['candidate_statuses'] ) && is_array( $storage['candidate_statuses'] ), 'Runtime diagnostics should include candidate statuses.' );
if ( '' !== $env_root && ! defined( 'WP_AGENT_RUNTIME_ROOT' ) ) {
    wp_agent_runtime_root_diag_assert( 'environment' === ( $storage['active_source'] ?? '' ), 'Runtime diagnostics should report the environment runtime root source.' );
    wp_agent_runtime_root_diag_assert( ! empty( $storage['effective_configured'] ), 'Environment runtime root should count as effectively configured.' );
    wp_agent_runtime_root_diag_assert( 'environment' === ( $storage['configured_by'] ?? '' ), 'Runtime diagnostics should report configured_by=environment.' );
}
wp_agent_runtime_root_diag_assert( ! empty( $diagnostics['storage']['configured'] ), 'Runtime diagnostics should report configured runtime root.' );
wp_agent_runtime_root_diag_assert( empty( $diagnostics['storage']['configured_status']['ok'] ), 'Configured public runtime root should be rejected in diagnostics.' );
wp_agent_runtime_root_diag_assert( 'public_path' === ( $diagnostics['storage']['configured_status']['code'] ?? '' ), 'Diagnostics should expose public_path rejection.' );
wp_agent_runtime_root_diag_assert( ! empty( $diagnostics['storage']['active_status']['ok'] ), 'Diagnostics should expose usable active fallback runtime root.' );
wp_agent_runtime_root_diag_assert( ! wp_agent_runtime_root_diag_path_starts_with( $diagnostics['storage']['runtime_root'] ?? '', WP_AGENT_PLUGIN_DIR ), 'Active fallback runtime root must not live under plugin directory.' );

wp_agent_runtime_root_diag_cleanup();

echo wp_json_encode( array(
    'success'       => true,
    'relative_code' => $relative['code'],
    'traversal_code' => $traversal['code'],
    'public_code'   => $public['code'],
    'settings_root' => $settings_private,
    'private_root'  => $private['normalized'],
    'active_root'   => $diagnostics['storage']['runtime_root'] ?? '',
    'active_source' => $diagnostics['storage']['active_source'] ?? '',
) ) . "\n";
