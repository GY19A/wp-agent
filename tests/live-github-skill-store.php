<?php
/**
 * Live GitHub Skill Store acceptance.
 *
 * This test uses the real GitHub Contents API and may access a private
 * repository when a GitHub token is configured. Run only when explicitly
 * enabled:
 *
 * WP_AGENT_LIVE_GITHUB_SKILLS=1 \
 * WP_AGENT_LIVE_GITHUB_REPOSITORY=owner/repo \
 * WP_AGENT_LIVE_GITHUB_SKILL_PATH=skills/example \
 * wp eval-file wp-content/plugins/wp-agent/tests/live-github-skill-store.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This live GitHub Skill Store script must run through WP-CLI.\n" );
	exit( 1 );
}

function wp_agent_live_github_skills_fail( $message ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	exit( 1 );
}

function wp_agent_live_github_skills_assert( $condition, $message ) {
	if ( ! $condition ) {
		wp_agent_live_github_skills_fail( $message );
	}
}

function wp_agent_live_github_skills_path_starts_with( $path, $parent ) {
	$path   = trailingslashit( wp_normalize_path( (string) $path ) );
	$parent = trailingslashit( wp_normalize_path( (string) $parent ) );
	return 0 === strpos( $path, $parent );
}

function wp_agent_live_github_skills_env( $key, $default = '' ) {
	$value = getenv( $key );
	if ( false === $value || '' === trim( (string) $value ) ) {
		return $default;
	}
	return trim( (string) $value );
}

if ( '1' !== (string) getenv( 'WP_AGENT_LIVE_GITHUB_SKILLS' ) ) {
	echo wp_json_encode( array(
		'skipped' => true,
		'reason'  => 'Set WP_AGENT_LIVE_GITHUB_SKILLS=1 and provide repository/path through environment variables or WP Agent Skills Store defaults to run live GitHub Skill Store acceptance.',
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	return;
}

$store      = WPAgent_Skills::github_store_defaults();
$repository = WPAgent_Skills::normalize_github_repository_value(
	wp_agent_live_github_skills_env( 'WP_AGENT_LIVE_GITHUB_REPOSITORY', $store['repository'] ?? '' )
);
$skill_path = WPAgent_Skills::normalize_skill_package_path(
	wp_agent_live_github_skills_env( 'WP_AGENT_LIVE_GITHUB_SKILL_PATH', $store['skill_path'] ?? '' )
);
$ref        = WPAgent_Skills::sanitize_git_ref_value(
	wp_agent_live_github_skills_env( 'WP_AGENT_LIVE_GITHUB_REF', $store['ref'] ?? 'main' )
);
$token      = wp_agent_live_github_skills_env( 'WP_AGENT_LIVE_GITHUB_TOKEN' );
$review_policy_env = wp_agent_live_github_skills_env( 'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY' );
$stored_review_policy = get_option( 'wp_agent_github_activation_policy', false );
$allowed_review_policies = WPAgent_Skills::github_activation_policies();
$policy = '';
if ( '' !== $review_policy_env ) {
	$review_policy_env = sanitize_key( $review_policy_env );
	if ( in_array( $review_policy_env, $allowed_review_policies, true ) ) {
		$policy = $review_policy_env;
	}
} elseif ( false !== $stored_review_policy ) {
	$policy = $store['activation_policy'] ?? 'quarantine';
}
$activate_env = getenv( 'WP_AGENT_LIVE_GITHUB_ACTIVATE' );
$pin_env      = getenv( 'WP_AGENT_LIVE_GITHUB_PIN' );
$activate   = false === $activate_env
	? in_array( $policy, array( 'activate', 'activate_pin' ), true )
	: '1' === (string) $activate_env;
$pin        = false === $pin_env
	? 'activate_pin' === $policy
	: '1' === (string) $pin_env;

wp_agent_live_github_skills_assert( '' !== $repository, 'WP_AGENT_LIVE_GITHUB_REPOSITORY or a configured default Skills Store repository is required.' );
wp_agent_live_github_skills_assert( '' !== $skill_path, 'WP_AGENT_LIVE_GITHUB_SKILL_PATH or a configured default Skills Store path is required.' );
wp_agent_live_github_skills_assert( '' !== $policy, 'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY or a configured Skills Store review policy is required. Allowed values: quarantine, activate, activate_pin.' );
$placeholder_reason = WPAgent_Skills::github_store_placeholder_reason( $repository, $skill_path );
wp_agent_live_github_skills_assert( '' === $placeholder_reason, 'Replace placeholder GitHub repository/path with official Skill Store coordinates before live GitHub acceptance: ' . $placeholder_reason . '.' );

$owner_id = 1;
$owner    = get_user_by( 'id', $owner_id );
wp_agent_live_github_skills_assert( $owner instanceof WP_User, 'Owner user #1 is required for live GitHub Skill Store acceptance.' );

$configured_token = WPAgent::get_option( 'github_token', '' );
$has_token        = '' !== $token || '' !== (string) $configured_token;

$install_args = array(
	'repository' => $repository,
	'skill_path' => $skill_path,
	'ref'        => $ref,
);
if ( '' !== $token ) {
	$install_args['github_token'] = $token;
}

$installed = WPAgent_Skills::install_from_github( $owner_id, $install_args );
unset( $token, $install_args );
wp_agent_live_github_skills_assert( ! is_wp_error( $installed ), is_wp_error( $installed ) ? $installed->get_error_message() : 'Live GitHub install failed.' );
wp_agent_live_github_skills_assert( ! empty( $installed['success'] ), 'Live GitHub install should succeed.' );
wp_agent_live_github_skills_assert( ! empty( $installed['quarantine_id'] ), 'Live GitHub install should return a quarantine id.' );

$summary = $installed['summary'] ?? array();
wp_agent_live_github_skills_assert( 'quarantined' === (string) ( $summary['status'] ?? '' ), 'Live GitHub package should remain quarantined by default.' );
wp_agent_live_github_skills_assert( '' !== (string) ( $summary['slug'] ?? '' ), 'Live GitHub package summary should include a slug.' );
wp_agent_live_github_skills_assert( '' !== (string) ( $summary['name'] ?? '' ), 'Live GitHub package summary should include a name.' );
wp_agent_live_github_skills_assert( 'github' === (string) ( $summary['source']['type'] ?? '' ), 'Live GitHub package source type should be github.' );
wp_agent_live_github_skills_assert( '' !== (string) ( $summary['source']['file_sha'] ?? '' ), 'Live GitHub package source should include the GitHub file SHA.' );
wp_agent_live_github_skills_assert( empty( $summary['source']['github_token'] ), 'GitHub token must not be returned in source summary.' );

$runtime_root = WPAgent_Sandbox::runtime_root();
wp_agent_live_github_skills_assert( is_string( $runtime_root ) && '' !== $runtime_root, 'Runtime root should be available.' );
wp_agent_live_github_skills_assert( wp_agent_live_github_skills_path_starts_with( $installed['lock_file'] ?? '', $runtime_root ), 'Quarantine lock file should live under private runtime root.' );
wp_agent_live_github_skills_assert( ! wp_agent_live_github_skills_path_starts_with( $installed['lock_file'] ?? '', WP_AGENT_PLUGIN_DIR ), 'Quarantine lock file must not live under the plugin directory.' );

$quarantined = WPAgent_Skills::get_quarantined( $installed['quarantine_id'] );
wp_agent_live_github_skills_assert( ! is_wp_error( $quarantined ), is_wp_error( $quarantined ) ? $quarantined->get_error_message() : 'Quarantined live GitHub package should be readable.' );
wp_agent_live_github_skills_assert( ! empty( $quarantined['skill_md'] ), 'Quarantined live GitHub package should include SKILL.md.' );
wp_agent_live_github_skills_assert( false === strpos( wp_json_encode( $quarantined['lock'] ?? array() ), 'github_token' ), 'Quarantine lock must not persist a GitHub token key.' );

$activated = null;
$pinned    = null;
if ( $activate ) {
	$activated = WPAgent_Skills::activate_quarantined( $owner_id, $installed['quarantine_id'], true );
	wp_agent_live_github_skills_assert( ! is_wp_error( $activated ), is_wp_error( $activated ) ? $activated->get_error_message() : 'Live GitHub quarantine activation failed.' );
	wp_agent_live_github_skills_assert( ! empty( $activated['success'] ), 'Live GitHub activation should succeed.' );
	wp_agent_live_github_skills_assert( ( $summary['slug'] ?? '' ) === ( $activated['skill']['slug'] ?? '' ), 'Activated live GitHub Skill slug should match the quarantined package.' );
	wp_agent_live_github_skills_assert( wp_agent_live_github_skills_path_starts_with( $activated['installed_dir'] ?? '', $runtime_root ), 'Activated live package should live under private runtime root.' );
	wp_agent_live_github_skills_assert( ! wp_agent_live_github_skills_path_starts_with( $activated['installed_dir'] ?? '', WP_AGENT_PLUGIN_DIR ), 'Activated live package must not live under the plugin directory.' );

	$update = WPAgent_Skills::check_package_update( $activated['skill']['slug'] ?? '' );
	wp_agent_live_github_skills_assert( ! is_wp_error( $update ), is_wp_error( $update ) ? $update->get_error_message() : 'Live GitHub update check failed.' );
	wp_agent_live_github_skills_assert( isset( $update['has_update'] ), 'Live GitHub update check should report has_update state.' );

	if ( $pin ) {
		$pinned = WPAgent_Skills::pin_package( $owner_id, $activated['skill']['slug'] ?? '', true );
		wp_agent_live_github_skills_assert( ! is_wp_error( $pinned ), is_wp_error( $pinned ) ? $pinned->get_error_message() : 'Live GitHub package pin failed.' );
		wp_agent_live_github_skills_assert( ! empty( $pinned['pinned'] ), 'Live GitHub package should be pinned when requested.' );
	}
}

echo wp_json_encode( array(
	'success'          => true,
	'repository'       => $summary['source']['repository'] ?? $repository,
	'ref'              => $summary['source']['ref'] ?? $ref,
	'skill_path'       => $summary['source']['path'] ?? $skill_path,
	'quarantine_id'    => $installed['quarantine_id'],
	'slug'             => $summary['slug'] ?? '',
	'name'             => $summary['name'] ?? '',
	'version'          => $summary['version'] ?? '',
	'review_policy'    => $policy,
	'warnings'         => $summary['warnings'] ?? array(),
	'file_count'       => count( $quarantined['lock']['files'] ?? array() ),
	'has_token'        => $has_token,
	'token_disclosed'  => false,
	'activated'        => null !== $activated,
	'activated_skill'  => null !== $activated ? array(
		'id'      => (int) ( $activated['skill']['id'] ?? 0 ),
		'slug'    => $activated['skill']['slug'] ?? '',
		'version' => (int) ( $activated['skill']['version'] ?? 0 ),
	) : null,
	'pinned'           => null !== $pinned ? ! empty( $pinned['pinned'] ) : false,
	'lock_under_runtime_root' => true,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
