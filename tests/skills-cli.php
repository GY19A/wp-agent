<?php
/**
 * WP Agent Skills WP-CLI checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skills-cli.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "This Skills CLI script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_skills_cli_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_skills_cli_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_skills_cli_fail( $message );
    }
}

function wp_agent_skills_cli_run_json( $command ) {
    $output = WP_CLI::runcommand( $command, array( 'return' => true ) );
    $data   = json_decode( trim( (string) $output ), true );
    if ( ! is_array( $data ) ) {
        wp_agent_skills_cli_fail( 'Command did not return JSON: ' . $command . ' :: ' . $output );
    }
    return $data;
}

function wp_agent_skills_cli_private_call( $method, $args = array() ) {
    $ref = new ReflectionMethod( 'WPAgent_Skills', $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( null, $args );
}

function wp_agent_skills_cli_fixture_md( $slug = 'cli-package-fixture' ) {
    return "---\n"
        . "name: CLI Package Fixture\n"
        . "slug: " . $slug . "\n"
        . "version: 1.0.0\n"
        . "description: CLI package fixture.\n"
        . "schedule_templates:\n"
        . "  - cli-fixture\n"
        . "---\n"
        . "## Workflow\n\nUse approved tools from the packaged Skill restored through WP-CLI.\n";
}

function wp_agent_skills_cli_fixture_package( $slug = 'cli-package-fixture' ) {
    return array(
        'source' => array(
            'type'       => 'github',
            'repository' => 'example/wp-agent-skills',
            'owner'      => 'example',
            'repo'       => 'wp-agent-skills',
            'ref'        => 'main',
            'path'       => 'skills/' . $slug,
            'skill_file' => 'skills/' . $slug . '/SKILL.md',
            'file_sha'   => 'cli-fixture-sha',
        ),
        'files'  => array(
            'SKILL.md'            => wp_agent_skills_cli_fixture_md( $slug ),
            'references/notes.md' => "CLI package notes stay private.\n",
        ),
        'warnings' => array(),
    );
}

global $wpdb;
$owner = 1;

$local_slug = 'cli-local-runtime-' . strtolower( wp_generate_password( 6, false, false ) );
$local = WPAgent_Skills::save( $owner, array(
    'name'        => 'CLI Local Runtime Fixture',
    'slug'        => $local_slug,
    'description' => 'CLI local runtime fixture.',
    'triggers'    => array( 'cli local runtime' ),
    'body'        => "## Workflow\n\nRestore this local Skill through the wp-agent skills CLI command.\n",
    'visibility'  => 'private',
) );
wp_agent_skills_cli_assert( ! is_wp_error( $local ), is_wp_error( $local ) ? $local->get_error_message() : 'Local Skill save failed.' );

$deleted_local = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) $local['id'] ),
    array( '%d' )
);
wp_agent_skills_cli_assert( 1 === (int) $deleted_local, 'Local Skill DB row should be deleted for CLI recovery.' );

$local_sync = wp_agent_skills_cli_run_json( 'wp-agent skills sync-index --owner=1 --local --format=json' );
wp_agent_skills_cli_assert( ! empty( $local_sync['success'] ), 'Local CLI sync should report success.' );
wp_agent_skills_cli_assert( (int) ( $local_sync['local']['restored'] ?? 0 ) >= 1, 'Local CLI sync should restore at least one Skill.' );

$local_get = wp_agent_skills_cli_run_json( 'wp-agent skills get --owner=1 --slug=' . escapeshellarg( $local_slug ) . ' --format=json' );
wp_agent_skills_cli_assert( $local_slug === ( $local_get['skill']['slug'] ?? '' ), 'CLI get should return the restored local Skill.' );
$local_list = wp_agent_skills_cli_run_json( 'wp-agent skills list --owner=1 --format=json' );
wp_agent_skills_cli_assert( in_array( $local_slug, wp_list_pluck( $local_list['skills'] ?? array(), 'slug' ), true ), 'CLI list should include the restored local Skill.' );

$quarantine = wp_agent_skills_cli_private_call( 'quarantine_package', array( $owner, wp_agent_skills_cli_fixture_package() ) );
wp_agent_skills_cli_assert( is_array( $quarantine ) && ! empty( $quarantine['success'] ), 'CLI fixture package should enter quarantine.' );
$activated = WPAgent_Skills::activate_quarantined( $owner, $quarantine['quarantine_id'] );
wp_agent_skills_cli_assert( is_array( $activated ) && ! empty( $activated['success'] ), 'CLI fixture package should activate.' );

$deleted_package = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) ( $activated['skill']['id'] ?? 0 ) ),
    array( '%d' )
);
wp_agent_skills_cli_assert( 1 === (int) $deleted_package, 'Package Skill DB row should be deleted for CLI recovery.' );

$package_sync = wp_agent_skills_cli_run_json( 'wp-agent skills sync-index --owner=1 --packages --format=json' );
wp_agent_skills_cli_assert( ! empty( $package_sync['success'] ), 'Package CLI sync should report success.' );
wp_agent_skills_cli_assert( (int) ( $package_sync['packages']['restored'] ?? 0 ) >= 1, 'Package CLI sync should restore at least one Skill.' );

$package_get = wp_agent_skills_cli_run_json( 'wp-agent skills get --owner=1 --slug=cli-package-fixture --format=json' );
wp_agent_skills_cli_assert( 'cli-package-fixture' === ( $package_get['skill']['slug'] ?? '' ), 'CLI get should return the restored package Skill.' );
$installed = wp_agent_skills_cli_run_json( 'wp-agent skills installed --format=json' );
wp_agent_skills_cli_assert( in_array( 'cli-package-fixture', wp_list_pluck( $installed['packages'] ?? array(), 'slug' ), true ), 'CLI installed should include the activated package.' );
$search = wp_agent_skills_cli_run_json( 'wp-agent skills search --owner=1 --query=cli-fixture --format=json' );
wp_agent_skills_cli_assert( in_array( 'cli-package-fixture', wp_list_pluck( $search['skills'] ?? array(), 'slug' ), true ), 'CLI search should find the restored package Skill.' );

echo wp_json_encode( array(
    'success'          => true,
    'local_slug'       => $local_slug,
    'package_slug'     => 'cli-package-fixture',
    'local_restored'   => (int) ( $local_sync['local']['restored'] ?? 0 ),
    'package_restored' => (int) ( $package_sync['packages']['restored'] ?? 0 ),
) ) . "\n";
