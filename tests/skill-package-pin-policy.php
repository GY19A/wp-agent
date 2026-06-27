<?php
/**
 * WP Agent packaged Skill pin policy checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skill-package-pin-policy.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This Skill package pin policy script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_skill_pin_slug']       = 'package-pin-fixture';
$GLOBALS['wp_agent_skill_pin_user_id']    = 1;
$GLOBALS['wp_agent_skill_pin_version']    = '1.0.0';
$GLOBALS['wp_agent_skill_pin_body_extra'] = 'Initial pinned workflow body.';

function wp_agent_skill_pin_fail( $message ) {
    wp_agent_skill_pin_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_skill_pin_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_skill_pin_fail( $message );
    }
}

function wp_agent_skill_pin_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_skill_pin_delete_dir( $dir ) {
    $dir = wp_normalize_path( (string) $dir );
    if ( '' === $dir || ! is_dir( $dir ) ) {
        return;
    }

    $skills_root = wp_normalize_path( WPAgent_Sandbox::runtime_area_dir( 'skills' ) );
    if ( ! wp_agent_skill_pin_path_starts_with( $dir, $skills_root ) ) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $it as $item ) {
        $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
    }
    @rmdir( $dir );
}

function wp_agent_skill_pin_cleanup() {
    global $wpdb;

    $slug = $GLOBALS['wp_agent_skill_pin_slug'];
    $wpdb->delete(
        $wpdb->prefix . 'wp_agent_skills',
        array(
            'user_id' => (int) $GLOBALS['wp_agent_skill_pin_user_id'],
            'slug'    => $slug,
        ),
        array( '%d', '%s' )
    );

    $skills_root = WPAgent_Sandbox::runtime_area_dir( 'skills' );
    foreach ( array(
        $skills_root . '/installed/' . $slug,
        $skills_root . '/rollback/' . $slug,
    ) as $dir ) {
        wp_agent_skill_pin_delete_dir( $dir );
    }

    if ( is_dir( $skills_root . '/quarantine' ) ) {
        foreach ( scandir( $skills_root . '/quarantine' ) as $entry ) {
            if ( false === strpos( $entry, $slug ) ) {
                continue;
            }
            wp_agent_skill_pin_delete_dir( $skills_root . '/quarantine/' . $entry );
        }
    }
}

function wp_agent_skill_pin_markdown() {
    return "---\n"
        . "name: Package Pin Fixture\n"
        . "slug: package-pin-fixture\n"
        . "version: " . $GLOBALS['wp_agent_skill_pin_version'] . "\n"
        . "description: Fake package pin fixture.\n"
        . "permissions:\n"
        . "  tools: [journal]\n"
        . "  network: false\n"
        . "  code_execution: false\n"
        . "schedule_templates:\n"
        . "  - package-pin-daily\n"
        . "---\n"
        . "## Workflow\n\nUse approved WordPress tools only. " . $GLOBALS['wp_agent_skill_pin_body_extra'] . "\n";
}

function wp_agent_skill_pin_content_response( $path, $body, $sha ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'type'    => 'file',
            'name'    => basename( $path ),
            'path'    => $path,
            'sha'     => $sha,
            'size'    => strlen( $body ),
            'content' => chunk_split( base64_encode( $body ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub contents API fixture.
        ) ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
    );
}

function wp_agent_skill_pin_dir_response() {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array() ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
    );
}

register_shutdown_function( 'wp_agent_skill_pin_cleanup' );
wp_agent_skill_pin_cleanup();

add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) {
        unset( $parsed_args );
        if ( false === strpos( (string) $url, 'https://api.github.com/repos/example/wp-agent-skills/contents/' ) ) {
            return $preempt;
        }

        $path = parse_url( (string) $url, PHP_URL_PATH );
        $path = preg_replace( '#^/repos/example/wp-agent-skills/contents/#', '', (string) $path );
        $path = rawurldecode( $path );

        if ( 'skills/package-pin-fixture/SKILL.md' === $path ) {
            $body = wp_agent_skill_pin_markdown();
            $sha  = 'pin-fixture-' . str_replace( '.', '-', $GLOBALS['wp_agent_skill_pin_version'] ) . '-' . substr( hash( 'sha256', $GLOBALS['wp_agent_skill_pin_body_extra'] ), 0, 8 );
            return wp_agent_skill_pin_content_response( $path, $body, $sha );
        }

        return wp_agent_skill_pin_dir_response();
    },
    10,
    3
);

$user_id = (int) $GLOBALS['wp_agent_skill_pin_user_id'];

$install_v1 = WPAgent_Skills::install_from_github( $user_id, array(
    'repository' => 'example/wp-agent-skills',
    'skill_path' => 'skills/package-pin-fixture',
    'ref'        => 'main',
) );
wp_agent_skill_pin_assert( ! is_wp_error( $install_v1 ) && ! empty( $install_v1['quarantine_id'] ), 'Initial fake GitHub install should quarantine the package.' );

$activate_v1 = WPAgent_Skills::activate_quarantined( $user_id, $install_v1['quarantine_id'] );
wp_agent_skill_pin_assert( ! is_wp_error( $activate_v1 ) && ! empty( $activate_v1['success'] ), 'Initial quarantine activation should succeed.' );
wp_agent_skill_pin_assert( empty( $activate_v1['lock']['pinned'] ), 'Package should not start pinned.' );

$pin = WPAgent_Skills::pin_package( $user_id, 'package-pin-fixture', true );
wp_agent_skill_pin_assert( ! is_wp_error( $pin ) && ! empty( $pin['pinned'] ), 'Pinning the installed package should succeed.' );
wp_agent_skill_pin_assert( ! empty( $pin['lock']['pinned_at'] ) && (int) $pin['lock']['pinned_by'] === $user_id, 'Pinned lock summary should include pin metadata.' );

$installed_after_pin = WPAgent_Skills::installed_packages( 20 );
$pinned_summary = null;
foreach ( $installed_after_pin as $package ) {
    if ( 'package-pin-fixture' === ( $package['slug'] ?? '' ) ) {
        $pinned_summary = $package;
        break;
    }
}
wp_agent_skill_pin_assert( ! empty( $pinned_summary['pinned'] ), 'Installed package list should expose pinned state.' );

$update_check = WPAgent_Skills::check_package_update( 'package-pin-fixture' );
wp_agent_skill_pin_assert( ! is_wp_error( $update_check ) && ! empty( $update_check['pinned'] ), 'Update check should expose pinned state.' );

$GLOBALS['wp_agent_skill_pin_version']    = '2.0.0';
$GLOBALS['wp_agent_skill_pin_body_extra'] = 'Updated body that should not activate while pinned.';

$install_v2 = WPAgent_Skills::install_from_github( $user_id, array(
    'repository' => 'example/wp-agent-skills',
    'skill_path' => 'skills/package-pin-fixture',
    'ref'        => 'main',
) );
wp_agent_skill_pin_assert( ! is_wp_error( $install_v2 ) && ! empty( $install_v2['quarantine_id'] ), 'Updated fake GitHub install should quarantine the package.' );

$blocked_activation = WPAgent_Skills::activate_quarantined( $user_id, $install_v2['quarantine_id'] );
wp_agent_skill_pin_assert( is_wp_error( $blocked_activation ) && 'wp_agent_skill_package_pinned' === $blocked_activation->get_error_code(), 'Pinned package should block normal activation of an updated quarantine package.' );

$forced_activation = WPAgent_Skills::activate_quarantined( $user_id, $install_v2['quarantine_id'], true );
wp_agent_skill_pin_assert( ! is_wp_error( $forced_activation ) && ! empty( $forced_activation['success'] ), 'Forced activation should explicitly bypass the pin guard.' );
wp_agent_skill_pin_assert( ! empty( $forced_activation['lock']['pinned'] ), 'Forced activation should preserve pinned state.' );
wp_agent_skill_pin_assert( 2 === (int) ( $forced_activation['skill']['version'] ?? 0 ), 'Forced activation should update the DB Skill version.' );

$rollbacks = WPAgent_Skills::package_rollbacks( 'package-pin-fixture', 10 );
wp_agent_skill_pin_assert( ! empty( $rollbacks ), 'Forced activation should create a rollback snapshot.' );

$blocked_rollback = WPAgent_Skills::rollback_package( $user_id, 'package-pin-fixture' );
wp_agent_skill_pin_assert( is_wp_error( $blocked_rollback ) && 'wp_agent_skill_package_pinned' === $blocked_rollback->get_error_code(), 'Pinned package should block normal rollback.' );

$unpin = WPAgent_Skills::pin_package( $user_id, 'package-pin-fixture', false );
wp_agent_skill_pin_assert( ! is_wp_error( $unpin ) && empty( $unpin['pinned'] ), 'Unpinning the package should succeed.' );
wp_agent_skill_pin_assert( ! empty( $unpin['lock']['unpinned_at'] ) && (int) $unpin['lock']['unpinned_by'] === $user_id, 'Unpinned lock summary should include unpin metadata.' );

$rollback = WPAgent_Skills::rollback_package( $user_id, 'package-pin-fixture' );
wp_agent_skill_pin_assert( ! is_wp_error( $rollback ) && ! empty( $rollback['success'] ), 'Unpinned package should allow rollback.' );
wp_agent_skill_pin_assert( empty( $rollback['lock']['pinned'] ), 'Rollback after unpin should keep the package unpinned.' );

$permissions = new WPAgent_Permissions();
foreach ( array( 'pin_package', 'unpin_package' ) as $action ) {
    wp_agent_skill_pin_assert( $permissions->requires_confirmation( 'manage_skills', array( 'action' => $action ) ), 'Pin state changes should require human confirmation: ' . $action );
}

$result = array(
    'success'                 => true,
    'slug'                    => 'package-pin-fixture',
    'initial_quarantine_id'   => $install_v1['quarantine_id'],
    'updated_quarantine_id'   => $install_v2['quarantine_id'],
    'blocked_activation_code' => $blocked_activation->get_error_code(),
    'blocked_rollback_code'   => $blocked_rollback->get_error_code(),
    'rollback_count'          => count( $rollbacks ),
    'forced_version'          => (int) ( $forced_activation['skill']['version'] ?? 0 ),
    'rollback_id'             => $rollback['rollback_id'] ?? '',
);

wp_agent_skill_pin_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
