<?php
/**
 * Host-side Skills Store lifecycle evidence contract.
 *
 * Statically verifies the GitHub Skills Store lifecycle remains reviewable
 * before a user-approved live GitHub acceptance run. This script only reads
 * local files. It does not call GitHub, Docker, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/skills-store-lifecycle-evidence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This Skills Store lifecycle evidence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_skills_store_evidence_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_skills_store_evidence_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_skills_store_evidence_fail( $message, $details );
	}
}

function wp_agent_skills_store_evidence_read( $plugin_dir, $rel_path ) {
	$path = $plugin_dir . '/' . $rel_path;
	wp_agent_skills_store_evidence_assert( is_file( $path ), 'Required Skills Store evidence file is missing.', array(
		'path' => $rel_path,
	) );

	$text = file_get_contents( $path );
	wp_agent_skills_store_evidence_assert( is_string( $text ) && '' !== $text, 'Required Skills Store evidence file could not be read.', array(
		'path' => $rel_path,
	) );

	return $text;
}

function wp_agent_skills_store_evidence_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_skills_store_evidence_assert( empty( $missing ), $name . ' is missing required evidence markers.', array(
		'missing' => $missing,
	) );

	return count( $markers );
}

function wp_agent_skills_store_evidence_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_skills_store_evidence_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_skills_store_evidence_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$implementation_files = array(
	'includes/class-wp-agent-skills.php',
	'includes/class-wp-agent-cli.php',
	'includes/class-wp-agent-permissions.php',
	'includes/class-wp-agent-diagnostics.php',
	'admin/class-wp-agent-admin.php',
	'admin/views/settings.php',
	'admin/views/skills.php',
);

$deterministic_tests = array(
	'tests/github-skills-cli.php',
	'tests/github-skill-store-defaults.php',
	'tests/github-skill-store-readiness.php',
	'tests/skill-package-security.php',
	'tests/skill-package-pin-policy.php',
	'tests/skill-rollback-recovery.php',
	'tests/security-regression.php',
	'tests/performance-diagnostics.php',
);

$live_harnesses = array(
	'tests/live-github-skill-store.php',
	'tests/final-acceptance-preflight.php',
	'tests/final-preflight-contract.php',
	'tests/final-live-evidence-contract.php',
	'tests/final-runbook-contract.php',
);

$texts = array();
foreach ( array_merge( $implementation_files, $deterministic_tests, $live_harnesses, array( 'README.md', 'goals.md' ) ) as $rel_path ) {
	$texts[ $rel_path ] = wp_agent_skills_store_evidence_read( $plugin_dir, $rel_path );
	wp_agent_skills_store_evidence_assert_no_raw_secrets( $rel_path, $texts[ $rel_path ] );
}

$required_markers = 0;

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'class-wp-agent-skills.php',
	$texts['includes/class-wp-agent-skills.php'],
	array(
		'const MAX_PACKAGE_FILES',
		'const MAX_PACKAGE_FILE_BYTES',
		'const MAX_PACKAGE_TOTAL_BYTES',
		'github_store_defaults',
		'github_store_readiness',
		'github_store_placeholder_reason',
		'github_activation_policies',
		"return array( 'quarantine', 'activate', 'activate_pin' );",
		'install_from_github',
		'github_fetch_file',
		'github_collect_dir',
		'github_api_get',
		"'Authorization'] = 'Bearer ' . \$token",
		'Package contains scripts. Scripts remain quarantined files and are not executable skills.',
		'quarantine_package',
		'normalize_package_files',
		'parse_github_repository',
		'sanitize_git_ref',
		'github_token',
		'activate_quarantined',
		'Activate a quarantined package as a non-executable DB skill',
		'copy_runtime_dir',
		'delete_runtime_dir',
		'runtime_path_within_skills_dir',
		'skill_package_quarantined',
		'skill_package_activated',
		'pin_package',
		'pinned_package_error',
		'preserve_pin_state',
		'installed_packages',
		'check_package_update',
		'refresh_package_from_source',
		'package_rollbacks',
		'rollback_package',
		'sync_installed_package_index',
		'discover_installed_package_catalog_index',
		'Package requests code_execution permission. Activation still stores only a non-executable Markdown skill.',
	)
);

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'class-wp-agent-cli.php',
	$texts['includes/class-wp-agent-cli.php'],
	array(
		'install-github',
		'activate-quarantine',
		'check-package-update',
		'refresh-package',
		'pin-package',
		'unpin-package',
		'rollbacks',
		'rollback-package',
		'--force',
		'github-token',
		'WPAgent_Skills::install_from_github',
		'WPAgent_Skills::activate_quarantined',
		'WPAgent_Skills::check_package_update',
		'WPAgent_Skills::refresh_package_from_source',
		'WPAgent_Skills::pin_package',
		'WPAgent_Skills::package_rollbacks',
		'WPAgent_Skills::rollback_package',
	)
);

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'class-wp-agent-permissions.php',
	$texts['includes/class-wp-agent-permissions.php'],
	array(
		'manage_skills',
		'install_github',
		'activate_quarantine',
		'refresh_package',
		'pin_package',
		'unpin_package',
		'rollback_package',
		'return true;',
	)
);

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'class-wp-agent-diagnostics.php',
	$texts['includes/class-wp-agent-diagnostics.php'],
	array(
		"'github_store' => class_exists( 'WPAgent_Skills' ) ? WPAgent_Skills::github_store_readiness()",
		'api[_ -]?key',
		'token',
		'secret',
		'[redacted]',
	)
);

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'class-wp-agent-admin.php',
	$texts['admin/class-wp-agent-admin.php'],
	array(
		"register_setting( 'wp_agent_settings', 'wp_agent_github_token'",
		"register_setting( 'wp_agent_settings', 'wp_agent_github_default_repository'",
		"register_setting( 'wp_agent_settings', 'wp_agent_github_default_ref'",
		"register_setting( 'wp_agent_settings', 'wp_agent_github_default_skill_path'",
		"register_setting( 'wp_agent_settings', 'wp_agent_github_activation_policy'",
		'sanitize_github_repository',
		'sanitize_github_skill_path',
		'sanitize_github_ref',
		'sanitize_github_activation_policy',
		'sanitize_api_key',
		'Return the existing stored value unchanged.',
		'WPAgent::encrypt',
	)
);

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'admin Skills Store views',
	$texts['admin/views/settings.php'] . "\n" . $texts['admin/views/skills.php'],
	array(
		'Skills Store',
		'Stored encrypted and never written to package lockfiles.',
		'Default Repository',
		'Default Skill Path',
		'Review Policy',
		'Install From GitHub',
		'Download to Quarantine',
		'Packages are downloaded to private quarantine first. They are not active until reviewed.',
		'Used only for this request; not stored or shown in lockfiles.',
	)
);

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'deterministic Skills Store tests',
	implode( "\n", array_intersect_key( $texts, array_flip( $deterministic_tests ) ) ),
	array(
		'pre_http_request',
		'GitHub CLI install should succeed.',
		'GitHub token must not be persisted in package source metadata.',
		'Activated package must not live under the plugin directory.',
		'CLI package refresh should download an updated quarantine package.',
		'Refreshing an installed package should create a rollback snapshot.',
		'Configured store should be marked configured.',
		'Configured GitHub token plaintext must not be persisted in quarantine lock.',
		'Placeholder store defaults should not be ready.',
		'Unreadable stored token should block readiness until re-saved.',
		'Runtime diagnostics must not disclose GitHub token plaintext.',
		'Bounded agent user third-party package action',
		'Skill package mutation should require confirmation',
		'Quarantine lock file must not live under plugin directory.',
		'Executable packaged Skill body',
		'Installed package must not live under plugin directory.',
		'Copy from outside Skills runtime root',
		'Pinned package should block normal activation of an updated quarantine package.',
		'Forced activation should explicitly bypass the pin guard.',
		'Pinned package should block normal rollback.',
		'Unpinned package should allow rollback.',
		'Rollback recovery should succeed without the installed package directory or DB row.',
	)
);

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'live GitHub Skills Store harnesses',
	implode( "\n", array_intersect_key( $texts, array_flip( $live_harnesses ) ) ),
	array(
		'WP_AGENT_LIVE_GITHUB_SKILLS',
		'WP_AGENT_LIVE_GITHUB_REPOSITORY',
		'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
		'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
		'WPAgent_Skills::github_store_placeholder_reason',
		'Replace placeholder GitHub repository/path with official Skill Store coordinates',
		'Live GitHub install should return a quarantine id.',
		'GitHub token must not be returned in source summary.',
		'Quarantine lock must not persist a GitHub token key.',
		'Quarantine lock file must not live under the plugin directory',
		'Activated live package must not live under the plugin directory.',
		"'token_disclosed'  => false",
		"'lock_under_runtime_root' => true",
		'github_placeholder_strict_failure',
		'github_env_token_redaction',
		'GitHub token report mode should expose only env_present token state.',
	)
);

$required_markers += wp_agent_skills_store_evidence_require_markers(
	'README and goals Skills Store evidence',
	$texts['README.md'] . "\n" . $texts['goals.md'],
	array(
		'GitHub Skill Store defaults can also be used by normal installs',
		'wp wp-agent skills install-github --owner=1 --format=json',
		'Replace `owner/repo` and `skills/example` with official Skill Store coordinates',
		'官方 Skills GitHub 仓库',
		'WP_AGENT_LIVE_GITHUB_REPOSITORY',
		'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
		'不得把这些步骤伪装成已完成',
	)
);

$all_text = implode( "\n", $texts );
$coverage = array(
	'configured_defaults'      => false !== strpos( $all_text, 'github_store_defaults' ) && false !== strpos( $all_text, 'Configured store should be marked configured.' ),
	'readiness_diagnostics'    => false !== strpos( $all_text, 'github_store_readiness' ) && false !== strpos( $all_text, 'Runtime diagnostics must not disclose GitHub token plaintext.' ),
	'placeholder_fail_closed'  => false !== strpos( $all_text, 'github_store_placeholder_reason' ) && false !== strpos( $all_text, 'Replace placeholder GitHub repository/path with official Skill Store coordinates' ),
	'token_redaction'          => false !== strpos( $all_text, 'token_disclosed' ) && false !== strpos( $all_text, 'GitHub token must not be returned in source summary.' ),
	'package_size_limits'      => false !== strpos( $all_text, 'MAX_PACKAGE_FILES' ) && false !== strpos( $all_text, 'MAX_PACKAGE_TOTAL_BYTES' ),
	'quarantine_private_root'  => false !== strpos( $all_text, 'Quarantine lock file must not live under plugin directory.' ) && false !== strpos( $all_text, 'runtime_path_within_skills_dir' ),
	'non_executable_markdown'  => false !== strpos( $all_text, 'non-executable Markdown skill' ) && false !== strpos( $all_text, 'Executable packaged Skill body' ),
	'admin_confirmation'       => false !== strpos( $all_text, 'Skill package mutation should require confirmation' ) && false !== strpos( $all_text, 'install_github' ),
	'cli_lifecycle'            => false !== strpos( $all_text, 'CLI package refresh should download an updated quarantine package.' ) && false !== strpos( $all_text, 'rollbacks' ),
	'pin_update_guard'         => false !== strpos( $all_text, 'Pinned package should block normal activation' ) && false !== strpos( $all_text, 'Forced activation should explicitly bypass the pin guard.' ),
	'rollback_recovery'        => false !== strpos( $all_text, 'Rollback recovery should succeed without the installed package directory or DB row.' ),
	'live_harness_ready'       => false !== strpos( $all_text, 'WP_AGENT_LIVE_GITHUB_SKILLS' ) && false !== strpos( $all_text, 'lock_under_runtime_root' ),
);

foreach ( $coverage as $name => $covered ) {
	wp_agent_skills_store_evidence_assert( true === $covered, 'Skills Store evidence coverage is incomplete.', array(
		'coverage' => $name,
	) );
}

echo json_encode( array(
	'success'                      => true,
	'contract'                     => 'skills_store_lifecycle_evidence_contract',
	'implementation_files_checked' => count( $implementation_files ),
	'deterministic_tests_checked'  => count( $deterministic_tests ),
	'live_harnesses_checked'       => count( $live_harnesses ),
	'required_markers'             => $required_markers,
	'coverage'                     => $coverage,
	'live_network_calls'           => false,
	'ai_gateway_calls'             => false,
	'github_calls'                 => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
