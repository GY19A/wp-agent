<?php
/**
 * WP Agent packaged Skill rollback recovery checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skill-rollback-recovery.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This Skill rollback recovery script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_skill_rollback_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_skill_rollback_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_skill_rollback_fail( $message );
    }
}

function wp_agent_skill_rollback_private_call( $method, $args = array() ) {
    $ref = new ReflectionMethod( 'WPAgent_Skills', $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( null, $args );
}

function wp_agent_skill_rollback_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_skill_rollback_md( $slug, $version, $body ) {
    return "---\n"
        . "name: Rollback Recovery Fixture\n"
        . "slug: " . $slug . "\n"
        . "version: " . $version . "\n"
        . "description: Deterministic rollback recovery fixture.\n"
        . "permissions:\n"
        . "  tools: [manage_posts]\n"
        . "  network: false\n"
        . "  code_execution: false\n"
        . "schedule_templates:\n"
        . "  - rollback-recovery-daily\n"
        . "---\n"
        . $body;
}

function wp_agent_skill_rollback_package( $slug, $version, $body ) {
    return array(
        'source' => array(
            'type'       => 'github',
            'repository' => 'example/wp-agent-skills',
            'owner'      => 'example',
            'repo'       => 'wp-agent-skills',
            'ref'        => 'main',
            'path'       => 'skills/' . $slug,
            'skill_file' => 'skills/' . $slug . '/SKILL.md',
            'file_sha'   => 'rollback-' . str_replace( '.', '-', $version ) . '-' . substr( hash( 'sha256', $body ), 0, 10 ),
        ),
        'files'  => array(
            'SKILL.md'            => wp_agent_skill_rollback_md( $slug, $version, $body ),
            'references/notes.md' => "Rollback recovery reference notes for version " . $version . ".\n",
        ),
        'warnings' => array(),
    );
}

global $wpdb;

$user_id      = 1;
$slug         = 'rollback-recovery-' . strtolower( wp_generate_password( 6, false, false ) );
$runtime_root = WPAgent_Sandbox::runtime_root();

$v1_body = "## Workflow\n\nVersion one is the recoverable rollback snapshot.\n";
$v2_body = "## Workflow\n\nVersion two is the active package before simulated loss.\n";

$v1_quarantine = wp_agent_skill_rollback_private_call( 'quarantine_package', array(
    $user_id,
    wp_agent_skill_rollback_package( $slug, '1.0.0', $v1_body ),
) );
wp_agent_skill_rollback_assert( is_array( $v1_quarantine ) && ! empty( $v1_quarantine['quarantine_id'] ), 'Version one should enter quarantine.' );

$v1 = WPAgent_Skills::activate_quarantined( $user_id, $v1_quarantine['quarantine_id'] );
wp_agent_skill_rollback_assert( is_array( $v1 ) && ! empty( $v1['success'] ), 'Version one should activate.' );
wp_agent_skill_rollback_assert( false !== strpos( $v1['skill']['body'] ?? '', 'Version one' ), 'Version one Skill body should be active.' );

$v2_quarantine = wp_agent_skill_rollback_private_call( 'quarantine_package', array(
    $user_id,
    wp_agent_skill_rollback_package( $slug, '2.0.0', $v2_body ),
) );
wp_agent_skill_rollback_assert( is_array( $v2_quarantine ) && ! empty( $v2_quarantine['quarantine_id'] ), 'Version two should enter quarantine.' );

$v2 = WPAgent_Skills::activate_quarantined( $user_id, $v2_quarantine['quarantine_id'] );
wp_agent_skill_rollback_assert( is_array( $v2 ) && ! empty( $v2['success'] ), 'Version two should activate.' );
wp_agent_skill_rollback_assert( false !== strpos( $v2['skill']['body'] ?? '', 'Version two' ), 'Version two Skill body should be active before simulated loss.' );

$rollbacks = WPAgent_Skills::package_rollbacks( $slug, 10 );
wp_agent_skill_rollback_assert( ! empty( $rollbacks ), 'Activating version two should create a rollback snapshot.' );
$rollback_id = $rollbacks[0]['rollback_id'] ?? '';
wp_agent_skill_rollback_assert( '' !== $rollback_id, 'Rollback snapshot should expose an id.' );

$installed_dir = $v2['installed_dir'] ?? '';
wp_agent_skill_rollback_assert( is_dir( $installed_dir ), 'Installed package directory should exist before simulated loss.' );
wp_agent_skill_rollback_assert( wp_agent_skill_rollback_path_starts_with( $installed_dir, $runtime_root ), 'Installed package should live under runtime root.' );
wp_agent_skill_rollback_assert( ! wp_agent_skill_rollback_path_starts_with( $installed_dir, WP_AGENT_PLUGIN_DIR ), 'Installed package should not live under plugin directory.' );

$deleted_runtime = wp_agent_skill_rollback_private_call( 'delete_runtime_dir', array( $installed_dir ) );
wp_agent_skill_rollback_assert( ! is_wp_error( $deleted_runtime ), is_wp_error( $deleted_runtime ) ? $deleted_runtime->get_error_message() : 'Installed runtime directory should be removable for recovery test.' );
wp_agent_skill_rollback_assert( ! is_dir( $installed_dir ), 'Installed runtime directory should be absent before rollback recovery.' );

$deleted_rows = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) ( $v2['skill']['id'] ?? 0 ) ),
    array( '%d' )
);
wp_agent_skill_rollback_assert( 1 === (int) $deleted_rows, 'Installed package DB row should be removable for recovery test.' );
wp_agent_skill_rollback_assert( null === WPAgent_Skills::get_by_slug( $user_id, $slug ), 'Package Skill should be unavailable before rollback recovery.' );

$recovered = WPAgent_Skills::rollback_package( $user_id, $slug );
wp_agent_skill_rollback_assert( is_array( $recovered ) && ! empty( $recovered['success'] ), 'Rollback recovery should succeed without the installed package directory or DB row.' );
wp_agent_skill_rollback_assert( $rollback_id === ( $recovered['rollback_id'] ?? '' ), 'Rollback recovery should use the latest snapshot by default.' );
wp_agent_skill_rollback_assert( false !== strpos( $recovered['skill']['body'] ?? '', 'Version one' ), 'Recovered Skill should restore version one body.' );
wp_agent_skill_rollback_assert( false === strpos( $recovered['skill']['body'] ?? '', 'Version two' ), 'Recovered Skill should not retain the lost version two body.' );

$restored = WPAgent_Skills::get_by_slug( $user_id, $slug );
wp_agent_skill_rollback_assert( is_array( $restored ) && (int) $restored['id'] > 0, 'Recovered Skill should be readable from the DB index.' );
wp_agent_skill_rollback_assert( false !== strpos( $restored['body'] ?? '', 'Version one' ), 'Recovered DB Skill should retain rollback snapshot body.' );

$lock = wp_agent_skill_rollback_private_call( 'installed_lock', array( $slug ) );
wp_agent_skill_rollback_assert( is_array( $lock ), 'Recovered package should write an active installed lock.' );
wp_agent_skill_rollback_assert( 'active' === ( $lock['status'] ?? '' ), 'Recovered installed lock should be active.' );
wp_agent_skill_rollback_assert( $rollback_id === ( $lock['rollback_from'] ?? '' ), 'Recovered installed lock should record rollback source.' );
wp_agent_skill_rollback_assert( (int) ( $lock['wp_skill_id'] ?? 0 ) === (int) $restored['id'], 'Recovered installed lock should reference the restored DB Skill id.' );

$restored_dir = dirname( $installed_dir ) . '/' . $slug;
wp_agent_skill_rollback_assert( is_readable( $restored_dir . '/SKILL.md' ), 'Recovered package should restore SKILL.md under installed runtime.' );
wp_agent_skill_rollback_assert( false !== strpos( (string) file_get_contents( $restored_dir . '/SKILL.md' ), 'Version one' ), 'Recovered installed SKILL.md should contain version one body.' );

echo wp_json_encode( array(
    'success'      => true,
    'slug'         => $slug,
    'rollback_id'  => $rollback_id,
    'skill_id'     => (int) $restored['id'],
    'runtime_root' => $runtime_root,
    'restored_dir' => $restored_dir,
) ) . "\n";
