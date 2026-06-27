<?php
/**
 * WP Agent local/package Skill slug conflict checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skill-conflict-policy.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This Skill conflict policy script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_skill_conflict_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_skill_conflict_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_skill_conflict_fail( $message );
    }
}

function wp_agent_skill_conflict_private_call( $method, $args = array() ) {
    $ref = new ReflectionMethod( 'WPAgent_Skills', $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( null, $args );
}

function wp_agent_skill_conflict_expect_slug_error( $result, $label ) {
    wp_agent_skill_conflict_assert( is_wp_error( $result ), $label . ' should return a WP_Error.' );
    wp_agent_skill_conflict_assert( 'wp_agent_skill_slug_conflict' === $result->get_error_code(), $label . ' should return the slug conflict code.' );
}

function wp_agent_skill_conflict_md( $slug, $name, $body ) {
    return "---\n"
        . "name: " . $name . "\n"
        . "slug: " . $slug . "\n"
        . "version: 1.0.0\n"
        . "description: Skill conflict policy fixture.\n"
        . "permissions:\n"
        . "  tools: [manage_posts]\n"
        . "  network: false\n"
        . "  code_execution: false\n"
        . "---\n"
        . $body;
}

function wp_agent_skill_conflict_package( $slug, $name, $body ) {
    return array(
        'source' => array(
            'type'       => 'github',
            'repository' => 'example/wp-agent-skills',
            'owner'      => 'example',
            'repo'       => 'wp-agent-skills',
            'ref'        => 'main',
            'path'       => 'skills/' . $slug,
            'skill_file' => 'skills/' . $slug . '/SKILL.md',
            'file_sha'   => 'conflict-' . substr( hash( 'sha256', $body ), 0, 12 ),
        ),
        'files'  => array(
            'SKILL.md' => wp_agent_skill_conflict_md( $slug, $name, $body ),
        ),
        'warnings' => array(),
    );
}

$user_id       = 1;
$local_slug    = 'skill-conflict-local-' . strtolower( wp_generate_password( 6, false, false ) );
$package_slug  = 'skill-conflict-package-' . strtolower( wp_generate_password( 6, false, false ) );
$summary       = array(
    'local_blocks_package' => 0,
    'package_blocks_local' => 0,
    'package_activation'   => 0,
);

$local = WPAgent_Skills::save( $user_id, array(
    'name'        => 'Local Conflict Fixture',
    'slug'        => $local_slug,
    'description' => 'Local Skill that must not be overwritten by a package.',
    'body'        => "## Local Workflow\n\nKeep the local playbook intact.\n",
    'visibility'  => 'private',
) );
wp_agent_skill_conflict_assert( ! is_wp_error( $local ), is_wp_error( $local ) ? $local->get_error_message() : 'Local Skill fixture should save.' );

$local_package = wp_agent_skill_conflict_private_call( 'quarantine_package', array(
    $user_id,
    wp_agent_skill_conflict_package( $local_slug, 'Package Same Slug Fixture', "## Package Workflow\n\nThis package must not replace the local playbook.\n" ),
) );
wp_agent_skill_conflict_assert( is_array( $local_package ) && ! empty( $local_package['quarantine_id'] ), 'Same-slug package should enter quarantine for review.' );

$blocked_activation = WPAgent_Skills::activate_quarantined( $user_id, $local_package['quarantine_id'] );
wp_agent_skill_conflict_expect_slug_error( $blocked_activation, 'Package activation over local Skill' );
$resolved_local = WPAgent_Skills::get_by_slug( $user_id, $local_slug );
wp_agent_skill_conflict_assert( false !== strpos( $resolved_local['body'] ?? '', 'Keep the local playbook intact' ), 'Local Skill body should remain intact after blocked package activation.' );
wp_agent_skill_conflict_assert( 'local' === ( $resolved_local['runtime_source']['type'] ?? '' ), 'Local Skill should still resolve from its local runtime mirror.' );
$summary['local_blocks_package'] += 3;

$package = wp_agent_skill_conflict_private_call( 'quarantine_package', array(
    $user_id,
    wp_agent_skill_conflict_package( $package_slug, 'Installed Package Fixture', "## Package Workflow\n\nKeep the installed package playbook intact.\n" ),
) );
wp_agent_skill_conflict_assert( is_array( $package ) && ! empty( $package['quarantine_id'] ), 'Package fixture should enter quarantine.' );
$activated = WPAgent_Skills::activate_quarantined( $user_id, $package['quarantine_id'] );
wp_agent_skill_conflict_assert( is_array( $activated ) && ! empty( $activated['success'] ), 'Package fixture should activate.' );
wp_agent_skill_conflict_assert( 'active' === ( $activated['lock']['status'] ?? '' ), 'Activated package lock should be active.' );
$summary['package_activation'] += 2;

$blocked_local_save = WPAgent_Skills::save( $user_id, array(
    'name'        => 'Local Same Slug Fixture',
    'slug'        => $package_slug,
    'description' => 'Local Skill must not overwrite installed package.',
    'body'        => "## Local Workflow\n\nThis local body must not replace the package.\n",
    'visibility'  => 'private',
) );
wp_agent_skill_conflict_expect_slug_error( $blocked_local_save, 'Local Skill save over installed package' );
$resolved_package = WPAgent_Skills::get_by_slug( $user_id, $package_slug );
wp_agent_skill_conflict_assert( false !== strpos( $resolved_package['body'] ?? '', 'Keep the installed package playbook intact' ), 'Installed package Skill body should remain intact after blocked local save.' );
$local_manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $package_slug );
wp_agent_skill_conflict_assert( is_wp_error( $local_manifest ), 'Blocked local save should not create a same-slug local runtime mirror.' );
$summary['package_blocks_local'] += 3;

echo wp_json_encode( array(
    'success'      => true,
    'summary'      => $summary,
    'local_slug'   => $local_slug,
    'package_slug' => $package_slug,
) ) . "\n";
