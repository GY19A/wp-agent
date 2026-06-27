<?php
/**
 * WP Agent local Skill runtime mirror checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/local-skill-runtime.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This local Skill runtime script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_local_skill_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_local_skill_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_local_skill_fail( $message );
    }
}

function wp_agent_local_skill_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

$user_id = 1;
$slug    = 'local-runtime-fixture-' . strtolower( wp_generate_password( 6, false, false ) );

$created = WPAgent_Skills::save( $user_id, array(
    'name'        => 'Local Runtime Fixture',
    'slug'        => $slug,
    'description' => 'Acceptance fixture for private local Skill runtime files.',
    'triggers'    => array( 'local runtime', 'skill mirror' ),
    'body'        => "## Workflow\n\nStep one: keep the reusable playbook outside the public plugin directory.\n",
    'visibility'  => 'private',
) );
wp_agent_local_skill_assert( ! is_wp_error( $created ), is_wp_error( $created ) ? $created->get_error_message() : 'Local Skill save failed.' );

$runtime_root = WPAgent_Sandbox::runtime_root();
$manifest     = WPAgent_Skills::local_runtime_manifest( $user_id, $slug );
wp_agent_local_skill_assert( ! is_wp_error( $manifest ), is_wp_error( $manifest ) ? $manifest->get_error_message() : 'Local Skill runtime manifest missing.' );
wp_agent_local_skill_assert( wp_agent_local_skill_path_starts_with( $manifest['dir'], $runtime_root ), 'Local Skill directory must live under runtime root.' );
wp_agent_local_skill_assert( ! wp_agent_local_skill_path_starts_with( $manifest['dir'], WP_AGENT_PLUGIN_DIR ), 'Local Skill directory must not live under plugin directory.' );
wp_agent_local_skill_assert( is_readable( $manifest['skill_file'] ), 'Local SKILL.md should be readable.' );
wp_agent_local_skill_assert( is_readable( $manifest['lock_file'] ), 'Local Skill lock should be readable.' );
wp_agent_local_skill_assert( 'local' === ( $manifest['lock']['kind'] ?? '' ), 'Local Skill lock should identify the local kind.' );
wp_agent_local_skill_assert( 'local' === ( $manifest['lock']['source']['type'] ?? '' ), 'Local Skill source should be local.' );
wp_agent_local_skill_assert( (int) $created['id'] === (int) ( $manifest['lock']['wp_skill_id'] ?? 0 ), 'Local Skill lock should reference the DB skill row.' );

$parsed = WPAgent_Skills::parse_skill_markdown( $manifest['skill_md'] );
wp_agent_local_skill_assert( $slug === ( $parsed['metadata']['slug'] ?? '' ), 'Local SKILL.md frontmatter should retain the slug.' );
wp_agent_local_skill_assert( false !== strpos( $parsed['body'], 'public plugin directory' ), 'Local SKILL.md should retain the playbook body.' );

$updated = WPAgent_Skills::save( $user_id, array(
    'name'        => 'Local Runtime Fixture',
    'slug'        => $slug,
    'description' => 'Updated acceptance fixture for private local Skill runtime files.',
    'triggers'    => array( 'local runtime', 'updated mirror' ),
    'body'        => "## Workflow\n\nStep two: update the runtime mirror after DB Skill edits.\n",
    'visibility'  => 'private',
) );
wp_agent_local_skill_assert( ! is_wp_error( $updated ), is_wp_error( $updated ) ? $updated->get_error_message() : 'Local Skill update failed.' );

$updated_manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $slug );
wp_agent_local_skill_assert( ! is_wp_error( $updated_manifest ), 'Updated Local Skill runtime manifest missing.' );
wp_agent_local_skill_assert( 2 === (int) ( $updated_manifest['lock']['version'] ?? 0 ), 'Local Skill runtime lock should increment version after update.' );
wp_agent_local_skill_assert( false !== strpos( $updated_manifest['skill_md'], 'Step two' ), 'Updated Local SKILL.md should contain the new body.' );
wp_agent_local_skill_assert( ( $manifest['lock']['body_sha256'] ?? '' ) !== ( $updated_manifest['lock']['body_sha256'] ?? '' ), 'Local Skill runtime lock should refresh body hash.' );

global $wpdb;
$wpdb->update(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'body' => "## Stale DB Copy\n\nThis stale body should not win over the runtime mirror.\n" ),
    array( 'id' => (int) $updated['id'] ),
    array( '%s' ),
    array( '%d' )
);
$resolved = WPAgent_Skills::get_by_slug( $user_id, $slug );
wp_agent_local_skill_assert( false !== strpos( $resolved['body'] ?? '', 'Step two' ), 'Runtime SKILL.md body should override a stale DB body during Skill reads.' );
wp_agent_local_skill_assert( 'local' === ( $resolved['runtime_source']['type'] ?? '' ), 'Resolved local Skill should expose sanitized runtime source type.' );

$deleted = $wpdb->delete(
    $wpdb->prefix . 'wp_agent_skills',
    array( 'id' => (int) $updated['id'] ),
    array( '%d' )
);
wp_agent_local_skill_assert( 1 === (int) $deleted, 'DB Skill row should be removable for runtime recovery test.' );
wp_agent_local_skill_assert( null === WPAgent_Skills::get_by_slug( $user_id, $slug, false ), 'Deleted Skill should not be available in the raw DB index before runtime index sync.' );

$sync = WPAgent_Skills::sync_local_runtime_index( $user_id );
wp_agent_local_skill_assert( ! is_wp_error( $sync ) && ! empty( $sync['success'] ), is_wp_error( $sync ) ? $sync->get_error_message() : 'Local runtime sync should succeed.' );
wp_agent_local_skill_assert( (int) ( $sync['restored'] ?? 0 ) >= 1, 'Local runtime sync should restore a missing DB Skill row.' );
$restored = WPAgent_Skills::get_by_slug( $user_id, $slug );
wp_agent_local_skill_assert( is_array( $restored ) && (int) $restored['id'] > 0, 'Runtime-synced Skill should be readable from the DB index.' );
wp_agent_local_skill_assert( false !== strpos( $restored['body'] ?? '', 'Step two' ), 'Runtime-synced Skill should retain the runtime playbook body.' );
$listed = WPAgent_Skills::all( $user_id, 20 );
wp_agent_local_skill_assert( in_array( $slug, wp_list_pluck( $listed, 'slug' ), true ), 'Runtime-synced Skill should appear in normal Skill listing.' );
$searched = WPAgent_Skills::search( $user_id, 'updated mirror', 20 );
wp_agent_local_skill_assert( in_array( $slug, wp_list_pluck( $searched, 'slug' ), true ), 'Runtime-synced Skill should appear in normal Skill search.' );
$restored_manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $slug );
wp_agent_local_skill_assert( (int) ( $restored_manifest['lock']['wp_skill_id'] ?? 0 ) === (int) $restored['id'], 'Runtime sync should refresh the lock with the restored DB Skill id.' );

$template = WPAgent_Skills::install_template( $user_id, 'news-site-operator' );
wp_agent_local_skill_assert( ! is_wp_error( $template ), is_wp_error( $template ) ? $template->get_error_message() : 'Template Skill install failed.' );
$template_manifest = WPAgent_Skills::local_runtime_manifest( $user_id, 'news-site-operator' );
wp_agent_local_skill_assert( ! is_wp_error( $template_manifest ), is_wp_error( $template_manifest ) ? $template_manifest->get_error_message() : 'Template runtime manifest missing.' );
wp_agent_local_skill_assert( 'built_in_template' === ( $template_manifest['lock']['source']['type'] ?? '' ), 'Template Skill source should identify built-in template.' );
wp_agent_local_skill_assert( 'news-site-operator' === ( $template_manifest['lock']['source']['template_slug'] ?? '' ), 'Template Skill lock should retain template slug.' );
wp_agent_local_skill_assert( false !== strpos( $template_manifest['skill_md'], '## Quality Gate' ), 'Template runtime SKILL.md should retain the built-in playbook body.' );

$archived = WPAgent_Skills::archive( $user_id, $slug );
wp_agent_local_skill_assert( $archived, 'Local Skill archive should succeed.' );
$archived_manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $slug );
wp_agent_local_skill_assert( ! is_wp_error( $archived_manifest ), 'Archived Local Skill runtime manifest should remain available for audit.' );
wp_agent_local_skill_assert( 'archived' === ( $archived_manifest['lock']['status'] ?? '' ), 'Archived Local Skill lock should mark archived status.' );

echo wp_json_encode( array(
    'success'       => true,
    'skill_id'      => (int) $created['id'],
    'slug'          => $slug,
    'runtime_root'  => $runtime_root,
    'skill_file'    => $updated_manifest['skill_file'],
    'template_file' => $template_manifest['skill_file'],
) ) . "\n";
