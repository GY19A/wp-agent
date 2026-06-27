<?php
/**
 * WP Agent Skill runtime catalog discovery checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skill-runtime-catalog-discovery.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This Skill runtime catalog discovery script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_skill_catalog_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_skill_catalog_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_skill_catalog_fail( $message );
    }
}

function wp_agent_skill_catalog_private_call( $method, $args = array() ) {
    $ref = new ReflectionMethod( 'WPAgent_Skills', $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( null, $args );
}

function wp_agent_skill_catalog_md( $slug, $name, $body, $trigger ) {
    return "---\n"
        . "name: " . $name . "\n"
        . "slug: " . $slug . "\n"
        . "version: 1.0.0\n"
        . "description: Runtime catalog discovery fixture.\n"
        . "permissions:\n"
        . "  tools: [manage_posts]\n"
        . "  network: false\n"
        . "  code_execution: false\n"
        . "schedule_templates:\n"
        . "  - " . $trigger . "\n"
        . "---\n"
        . $body;
}

function wp_agent_skill_catalog_package( $slug, $body, $trigger ) {
    return array(
        'source' => array(
            'type'       => 'github',
            'repository' => 'example/wp-agent-skills',
            'owner'      => 'example',
            'repo'       => 'wp-agent-skills',
            'ref'        => 'main',
            'path'       => 'skills/' . $slug,
            'skill_file' => 'skills/' . $slug . '/SKILL.md',
            'file_sha'   => 'runtime-catalog-' . substr( hash( 'sha256', $body ), 0, 10 ),
        ),
        'files'  => array(
            'SKILL.md' => wp_agent_skill_catalog_md( $slug, 'Runtime Catalog Package Fixture', $body, $trigger ),
        ),
        'warnings' => array(),
    );
}

global $wpdb;

$user_id       = 1;
$local_slug    = 'runtime-catalog-local-' . strtolower( wp_generate_password( 6, false, false ) );
$package_slug  = 'runtime-catalog-package-' . strtolower( wp_generate_password( 6, false, false ) );
$local_trigger = 'catalog-local-trigger-' . strtolower( wp_generate_password( 4, false, false ) );
$package_query = 'catalog-package-query-' . strtolower( wp_generate_password( 4, false, false ) );

$local = WPAgent_Skills::save( $user_id, array(
    'name'        => 'Runtime Catalog Local Fixture',
    'slug'        => $local_slug,
    'description' => 'Local runtime catalog discovery fixture.',
    'triggers'    => array( $local_trigger ),
    'body'        => "## Workflow\n\nRecover this local catalog playbook without an explicit sync-index command.\n",
    'visibility'  => 'private',
) );
wp_agent_skill_catalog_assert( ! is_wp_error( $local ), is_wp_error( $local ) ? $local->get_error_message() : 'Local catalog fixture should save.' );

$deleted_local = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) $local['id'] ),
    array( '%d' )
);
wp_agent_skill_catalog_assert( 1 === (int) $deleted_local, 'Local catalog DB row should be removable.' );
wp_agent_skill_catalog_assert( null === WPAgent_Skills::get_by_slug( $user_id, $local_slug, false ), 'Raw DB lookup should miss deleted local catalog Skill.' );

$listed = WPAgent_Skills::all( $user_id, 100 );
wp_agent_skill_catalog_assert( in_array( $local_slug, wp_list_pluck( $listed, 'slug' ), true ), 'Skill list should auto-discover missing local runtime Skill.' );
$local_after_list = WPAgent_Skills::get_by_slug( $user_id, $local_slug, false );
wp_agent_skill_catalog_assert( is_array( $local_after_list ) && (int) $local_after_list['id'] > 0, 'List discovery should restore the local DB row.' );
$local_manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $local_slug );
wp_agent_skill_catalog_assert( ! empty( $local_manifest['lock']['auto_recovered_at'] ), 'List discovery should record local auto recovery time.' );

$package_body = "## Workflow\n\nRecover this installed package with the search term " . $package_query . " without sync-index.\n";
$quarantine = wp_agent_skill_catalog_private_call( 'quarantine_package', array(
    $user_id,
    wp_agent_skill_catalog_package( $package_slug, $package_body, $package_query ),
) );
wp_agent_skill_catalog_assert( is_array( $quarantine ) && ! empty( $quarantine['quarantine_id'] ), 'Package catalog fixture should enter quarantine.' );

$activated = WPAgent_Skills::activate_quarantined( $user_id, $quarantine['quarantine_id'] );
wp_agent_skill_catalog_assert( is_array( $activated ) && ! empty( $activated['success'] ), 'Package catalog fixture should activate.' );

$deleted_package = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) $activated['skill']['id'] ),
    array( '%d' )
);
wp_agent_skill_catalog_assert( 1 === (int) $deleted_package, 'Package catalog DB row should be removable.' );
wp_agent_skill_catalog_assert( null === WPAgent_Skills::get_by_slug( $user_id, $package_slug, false ), 'Raw DB lookup should miss deleted package catalog Skill.' );

$searched = WPAgent_Skills::search( $user_id, $package_query, 100 );
wp_agent_skill_catalog_assert( in_array( $package_slug, wp_list_pluck( $searched, 'slug' ), true ), 'Skill search should auto-discover missing installed package Skill.' );
$package_after_search = WPAgent_Skills::get_by_slug( $user_id, $package_slug, false );
wp_agent_skill_catalog_assert( is_array( $package_after_search ) && (int) $package_after_search['id'] > 0, 'Search discovery should restore the package DB row.' );

$package_lock = wp_agent_skill_catalog_private_call( 'installed_lock', array( $package_slug ) );
wp_agent_skill_catalog_assert( is_array( $package_lock ), 'Package catalog discovery should keep a readable installed lock.' );
wp_agent_skill_catalog_assert( ! empty( $package_lock['auto_recovered_at'] ), 'Search discovery should record package auto recovery time.' );

echo wp_json_encode( array(
    'success'       => true,
    'local_slug'    => $local_slug,
    'package_slug'  => $package_slug,
    'local_id'      => (int) $local_after_list['id'],
    'package_id'    => (int) $package_after_search['id'],
    'package_query' => $package_query,
) ) . "\n";
