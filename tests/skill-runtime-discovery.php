<?php
/**
 * WP Agent targeted Skill runtime discovery checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skill-runtime-discovery.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This Skill runtime discovery script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_skill_discovery_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_skill_discovery_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_skill_discovery_fail( $message );
    }
}

function wp_agent_skill_discovery_private_call( $method, $args = array() ) {
    $ref = new ReflectionMethod( 'WPAgent_Skills', $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( null, $args );
}

function wp_agent_skill_discovery_md( $slug, $name, $body ) {
    return "---\n"
        . "name: " . $name . "\n"
        . "slug: " . $slug . "\n"
        . "version: 1.0.0\n"
        . "description: Runtime discovery fixture.\n"
        . "permissions:\n"
        . "  tools: [manage_posts]\n"
        . "  network: false\n"
        . "  code_execution: false\n"
        . "schedule_templates:\n"
        . "  - runtime-discovery-daily\n"
        . "---\n"
        . $body;
}

function wp_agent_skill_discovery_package( $slug, $body ) {
    return array(
        'source' => array(
            'type'       => 'github',
            'repository' => 'example/wp-agent-skills',
            'owner'      => 'example',
            'repo'       => 'wp-agent-skills',
            'ref'        => 'main',
            'path'       => 'skills/' . $slug,
            'skill_file' => 'skills/' . $slug . '/SKILL.md',
            'file_sha'   => 'runtime-discovery-' . substr( hash( 'sha256', $body ), 0, 10 ),
        ),
        'files'  => array(
            'SKILL.md' => wp_agent_skill_discovery_md( $slug, 'Runtime Discovery Package Fixture', $body ),
        ),
        'warnings' => array(),
    );
}

global $wpdb;

$user_id      = 1;
$local_slug   = 'runtime-discovery-local-' . strtolower( wp_generate_password( 6, false, false ) );
$package_slug = 'runtime-discovery-package-' . strtolower( wp_generate_password( 6, false, false ) );

$local = WPAgent_Skills::save( $user_id, array(
    'name'        => 'Runtime Discovery Local Fixture',
    'slug'        => $local_slug,
    'description' => 'Local runtime discovery fixture.',
    'body'        => "## Workflow\n\nRestore this local playbook from its private runtime mirror.\n",
    'visibility'  => 'private',
) );
wp_agent_skill_discovery_assert( ! is_wp_error( $local ), is_wp_error( $local ) ? $local->get_error_message() : 'Local discovery fixture should save.' );

$deleted_local = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) $local['id'] ),
    array( '%d' )
);
wp_agent_skill_discovery_assert( 1 === (int) $deleted_local, 'Local discovery DB row should be removable.' );
wp_agent_skill_discovery_assert( null === WPAgent_Skills::get_by_slug( $user_id, $local_slug, false ), 'Raw DB lookup should miss deleted local Skill.' );

$auto_local = WPAgent_Skills::get_by_slug( $user_id, $local_slug );
wp_agent_skill_discovery_assert( is_array( $auto_local ) && (int) $auto_local['id'] > 0, 'Default local Skill lookup should recover from runtime files.' );
wp_agent_skill_discovery_assert( false !== strpos( $auto_local['body'] ?? '', 'Restore this local playbook' ), 'Recovered local Skill should retain runtime body.' );
$local_manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $local_slug );
wp_agent_skill_discovery_assert( (int) ( $local_manifest['lock']['wp_skill_id'] ?? 0 ) === (int) $auto_local['id'], 'Local auto recovery should refresh lock DB id.' );
wp_agent_skill_discovery_assert( ! empty( $local_manifest['lock']['auto_recovered_at'] ), 'Local auto recovery should record lock recovery time.' );

$package_body = "## Workflow\n\nRestore this package playbook from the installed runtime directory.\n";
$quarantine = wp_agent_skill_discovery_private_call( 'quarantine_package', array(
    $user_id,
    wp_agent_skill_discovery_package( $package_slug, $package_body ),
) );
wp_agent_skill_discovery_assert( is_array( $quarantine ) && ! empty( $quarantine['quarantine_id'] ), 'Package discovery fixture should enter quarantine.' );

$activated = WPAgent_Skills::activate_quarantined( $user_id, $quarantine['quarantine_id'] );
wp_agent_skill_discovery_assert( is_array( $activated ) && ! empty( $activated['success'] ), 'Package discovery fixture should activate.' );

$deleted_package = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) $activated['skill']['id'] ),
    array( '%d' )
);
wp_agent_skill_discovery_assert( 1 === (int) $deleted_package, 'Package discovery DB row should be removable.' );
wp_agent_skill_discovery_assert( null === WPAgent_Skills::get_by_slug( $user_id, $package_slug, false ), 'Raw DB lookup should miss deleted package Skill.' );

$auto_package = WPAgent_Skills::get_by_slug( $user_id, $package_slug );
wp_agent_skill_discovery_assert( is_array( $auto_package ) && (int) $auto_package['id'] > 0, 'Default package Skill lookup should recover from installed runtime files.' );
wp_agent_skill_discovery_assert( false !== strpos( $auto_package['body'] ?? '', 'Restore this package playbook' ), 'Recovered package Skill should retain installed package body.' );

$package_lock = wp_agent_skill_discovery_private_call( 'installed_lock', array( $package_slug ) );
wp_agent_skill_discovery_assert( is_array( $package_lock ), 'Package auto recovery should keep a readable installed lock.' );
wp_agent_skill_discovery_assert( (int) ( $package_lock['wp_skill_id'] ?? 0 ) === (int) $auto_package['id'], 'Package auto recovery should refresh lock DB id.' );
wp_agent_skill_discovery_assert( ! empty( $package_lock['auto_recovered_at'] ), 'Package auto recovery should record lock recovery time.' );

echo wp_json_encode( array(
    'success'      => true,
    'local_slug'   => $local_slug,
    'package_slug' => $package_slug,
    'local_id'     => (int) $auto_local['id'],
    'package_id'   => (int) $auto_package['id'],
) ) . "\n";
