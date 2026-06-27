<?php
/**
 * WP Agent packaged Skill security checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skill-package-security.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This regression script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_skill_pkg_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_skill_pkg_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_skill_pkg_fail( $message );
    }
}

function wp_agent_skill_pkg_expect_error( $result, $label ) {
    wp_agent_skill_pkg_assert( is_wp_error( $result ) || ( is_array( $result ) && ! empty( $result['error'] ) ), $label . ' should return an error.' );
}

function wp_agent_skill_pkg_private_call( $method, $args = array() ) {
    $ref = new ReflectionMethod( 'WPAgent_Skills', $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( null, $args );
}

function wp_agent_skill_pkg_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_skill_pkg_fixture_md( $slug = 'package-security-fixture', $body = '' ) {
    if ( '' === $body ) {
        $body = "## Workflow\n\nUse approved WordPress and web tools to draft content. Do not execute code.\n";
    }

    return "---\n"
        . "name: Package Security Fixture\n"
        . "slug: " . $slug . "\n"
        . "version: 1.0.0\n"
        . "description: Deterministic package security fixture.\n"
        . "permissions:\n"
        . "  tools: [web.search, manage_posts]\n"
        . "  network: true\n"
        . "  code_execution: true\n"
        . "schedule_templates:\n"
        . "  - daily-fixture\n"
        . "---\n"
        . $body;
}

function wp_agent_skill_pkg_fixture_package( $slug = 'package-security-fixture', $body = '' ) {
    return array(
        'source' => array(
            'type'       => 'github',
            'repository' => 'example/wp-agent-skills',
            'owner'      => 'example',
            'repo'       => 'wp-agent-skills',
            'ref'        => 'main',
            'path'       => 'skills/' . $slug,
            'skill_file' => 'skills/' . $slug . '/SKILL.md',
            'file_sha'   => 'fixture-sha',
        ),
        'files'  => array(
            'SKILL.md'              => wp_agent_skill_pkg_fixture_md( $slug, $body ),
            'references/notes.md'   => "Reference notes stay private.\n",
            'templates/post.md'     => "Draft template.\n",
            'scripts/helper.php'    => "<?php echo 'not executed';\n",
        ),
        'warnings' => array(),
    );
}

WPAgent_Roles::ensure();
$agent_user_id = WPAgent_Roles::get_user_id();
wp_agent_skill_pkg_assert( $agent_user_id > 0, 'Bounded agent user should exist.' );

$subscriber_id = wp_insert_user( array(
    'user_login' => 'wp-agent-skill-security-' . wp_generate_password( 6, false ),
    'user_pass'  => wp_generate_password( 20, true ),
    'user_email' => 'skill-security-' . wp_generate_password( 6, false ) . '@example.com',
    'role'       => 'subscriber',
) );
wp_agent_skill_pkg_assert( ! is_wp_error( $subscriber_id ) && $subscriber_id > 0, 'Subscriber fixture user should be created.' );

$summary = array(
    'admin_gate'     => 0,
    'confirmations'  => 0,
    'quarantine'     => 0,
    'activation'     => 0,
    'index_sync'     => 0,
    'runtime_bounds' => 0,
);

$agent_tool = new WPAgent_Tool_Skills();
$agent_tool->set_context( $agent_user_id, 'wpcli', 0, $agent_user_id, 0 );
foreach ( array( 'install_github', 'list_quarantine', 'activate_quarantine', 'list_installed_packages', 'refresh_package', 'pin_package', 'unpin_package', 'rollback_package' ) as $action ) {
    wp_agent_skill_pkg_expect_error( $agent_tool->execute( array(
        'action'        => $action,
        'repository'    => 'example/wp-agent-skills',
        'skill_path'    => 'skills/package-security-fixture',
        'quarantine_id' => 'missing',
        'slug'          => 'package-security-fixture',
    ) ), 'Bounded agent user third-party package action: ' . $action );
    $summary['admin_gate']++;
}

$subscriber_tool = new WPAgent_Tool_Skills();
$subscriber_tool->set_context( $subscriber_id, 'wpcli', 0, $subscriber_id, 0 );
wp_agent_skill_pkg_expect_error( $subscriber_tool->execute( array(
    'action'     => 'install_github',
    'repository' => 'example/wp-agent-skills',
    'skill_path' => 'skills/package-security-fixture',
) ), 'Subscriber GitHub Skill install' );
$summary['admin_gate']++;

$permissions = new WPAgent_Permissions();
foreach ( array( 'install_github', 'activate_quarantine', 'refresh_package', 'pin_package', 'unpin_package', 'rollback_package' ) as $action ) {
    wp_agent_skill_pkg_assert( $permissions->requires_confirmation( 'manage_skills', array( 'action' => $action ) ), 'Skill package mutation should require confirmation: ' . $action );
    $summary['confirmations']++;
}
wp_agent_skill_pkg_assert( ! $permissions->requires_confirmation( 'manage_skills', array( 'action' => 'list_quarantine' ) ), 'Quarantine listing should not require confirmation.' );
$summary['confirmations']++;

$package = wp_agent_skill_pkg_fixture_package();
$quarantine = wp_agent_skill_pkg_private_call( 'quarantine_package', array( 1, $package ) );
wp_agent_skill_pkg_assert( is_array( $quarantine ) && ! empty( $quarantine['success'] ), 'Valid fixture package should enter quarantine.' );
wp_agent_skill_pkg_assert( ! empty( $quarantine['quarantine_id'] ), 'Quarantine id should be returned.' );
wp_agent_skill_pkg_assert( ! empty( $quarantine['summary']['warnings'] ), 'Permission/script warnings should be retained.' );
$runtime_root = WPAgent_Sandbox::runtime_root();
wp_agent_skill_pkg_assert( wp_agent_skill_pkg_path_starts_with( dirname( $quarantine['lock_file'] ), $runtime_root ), 'Quarantine lock file must live under runtime root.' );
wp_agent_skill_pkg_assert( ! wp_agent_skill_pkg_path_starts_with( dirname( $quarantine['lock_file'] ), WP_AGENT_PLUGIN_DIR ), 'Quarantine lock file must not live under plugin directory.' );
$summary['quarantine'] += 5;

$dangerous = wp_agent_skill_pkg_private_call( 'quarantine_package', array( 1, wp_agent_skill_pkg_fixture_package( 'dangerous-package-fixture', "## Bad\n\nRun shell_exec(\"id\").\n" ) ) );
wp_agent_skill_pkg_expect_error( $dangerous, 'Executable packaged Skill body' );
$summary['quarantine']++;

$invalid_path_package = wp_agent_skill_pkg_fixture_package( 'invalid-path-package-fixture' );
$invalid_path_package['files']['../escape.txt'] = 'escape';
$invalid_path = wp_agent_skill_pkg_private_call( 'quarantine_package', array( 1, $invalid_path_package ) );
wp_agent_skill_pkg_expect_error( $invalid_path, 'Invalid packaged Skill file path' );
$summary['quarantine']++;

$oversize_body = "## Oversized\n\n" . str_repeat( 'x', WPAgent_Skills::MAX_BODY_BYTES + 1 );
$oversize = wp_agent_skill_pkg_private_call( 'quarantine_package', array( 1, wp_agent_skill_pkg_fixture_package( 'oversized-package-fixture', $oversize_body ) ) );
wp_agent_skill_pkg_expect_error( $oversize, 'Oversized packaged Skill body' );
$summary['quarantine']++;

$activated = WPAgent_Skills::activate_quarantined( 1, $quarantine['quarantine_id'] );
wp_agent_skill_pkg_assert( is_array( $activated ) && ! empty( $activated['success'] ), 'Quarantined package should activate.' );
wp_agent_skill_pkg_assert( 'package-security-fixture' === ( $activated['skill']['slug'] ?? '' ), 'Activated Skill slug should match metadata.' );
wp_agent_skill_pkg_assert( wp_agent_skill_pkg_path_starts_with( $activated['installed_dir'], $runtime_root ), 'Installed package must live under runtime root.' );
wp_agent_skill_pkg_assert( ! wp_agent_skill_pkg_path_starts_with( $activated['installed_dir'], WP_AGENT_PLUGIN_DIR ), 'Installed package must not live under plugin directory.' );
wp_agent_skill_pkg_assert( 'active' === ( $activated['lock']['status'] ?? '' ), 'Activated lock summary should be active.' );
$summary['activation'] += 5;

$repeat = WPAgent_Skills::activate_quarantined( 1, $quarantine['quarantine_id'] );
wp_agent_skill_pkg_expect_error( $repeat, 'Repeated quarantine activation' );
$summary['activation']++;

global $wpdb;
$deleted = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) ( $activated['skill']['id'] ?? 0 ) ),
    array( '%d' )
);
wp_agent_skill_pkg_assert( 1 === (int) $deleted, 'Activated package DB Skill row should be removable for runtime recovery test.' );
wp_agent_skill_pkg_assert( null === WPAgent_Skills::get_by_slug( 1, 'package-security-fixture', false ), 'Deleted package Skill should not be readable from the raw DB index before installed package sync.' );

$sync = WPAgent_Skills::sync_installed_package_index( 1 );
wp_agent_skill_pkg_assert( ! is_wp_error( $sync ) && ! empty( $sync['success'] ), is_wp_error( $sync ) ? $sync->get_error_message() : 'Installed package runtime sync should succeed.' );
wp_agent_skill_pkg_assert( (int) ( $sync['restored'] ?? 0 ) >= 1, 'Installed package runtime sync should restore a missing DB Skill row.' );
$restored = WPAgent_Skills::get_by_slug( 1, 'package-security-fixture' );
wp_agent_skill_pkg_assert( is_array( $restored ) && (int) $restored['id'] > 0, 'Runtime-synced package Skill should be readable from DB index.' );
wp_agent_skill_pkg_assert( false !== strpos( $restored['body'] ?? '', 'approved WordPress and web tools' ), 'Runtime-synced package Skill should retain the package playbook body.' );
$listed_skills = WPAgent_Skills::all( 1, 20 );
wp_agent_skill_pkg_assert( in_array( 'package-security-fixture', wp_list_pluck( $listed_skills, 'slug' ), true ), 'Runtime-synced package Skill should appear in normal Skill listing.' );
$searched_skills = WPAgent_Skills::search( 1, 'daily-fixture', 20 );
wp_agent_skill_pkg_assert( in_array( 'package-security-fixture', wp_list_pluck( $searched_skills, 'slug' ), true ), 'Runtime-synced package Skill should appear in normal Skill search.' );
$restored_lock = wp_agent_skill_pkg_private_call( 'installed_lock', array( 'package-security-fixture' ) );
wp_agent_skill_pkg_assert( is_array( $restored_lock ) && (int) ( $restored_lock['wp_skill_id'] ?? 0 ) === (int) $restored['id'], 'Installed package sync should refresh the lock with the restored DB Skill id.' );
$activated['skill'] = $restored;
$summary['index_sync'] += 8;

$quarantine_dir = dirname( $quarantine['lock_file'] );
$outside_dir = trailingslashit( sys_get_temp_dir() ) . 'wp-agent-skill-package-outside-' . wp_generate_password( 8, false );
wp_mkdir_p( $outside_dir );
file_put_contents( trailingslashit( $outside_dir ) . 'leak.txt', 'outside' );

$copy_from_outside = wp_agent_skill_pkg_private_call( 'copy_runtime_dir', array(
    $outside_dir,
    trailingslashit( $runtime_root ) . 'skills/installed/outside-copy',
) );
wp_agent_skill_pkg_expect_error( $copy_from_outside, 'Copy from outside Skills runtime root' );
$summary['runtime_bounds']++;

$copy_to_outside = wp_agent_skill_pkg_private_call( 'copy_runtime_dir', array(
    $quarantine_dir,
    $outside_dir . '-dst',
) );
wp_agent_skill_pkg_expect_error( $copy_to_outside, 'Copy to outside Skills runtime root' );
$summary['runtime_bounds']++;

@unlink( trailingslashit( $outside_dir ) . 'leak.txt' );
@rmdir( $outside_dir );

echo wp_json_encode( array(
    'success'       => true,
    'summary'       => $summary,
    'quarantine_id' => $quarantine['quarantine_id'],
    'skill_id'      => (int) ( $activated['skill']['id'] ?? 0 ),
    'runtime_root'  => $runtime_root,
) ) . "\n";
