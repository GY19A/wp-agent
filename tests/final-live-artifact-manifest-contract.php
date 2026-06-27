<?php
/**
 * Host-side final live artifact manifest contract.
 *
 * Verifies the final live artifact manifest template and runbook require a
 * reviewable inventory of archived evidence, hashes, official-container state,
 * local Git state, and completion-gate output. If a real
 * final-live-artifact-manifest-YYYYMMDD.json exists in design/test-logs, it is
 * validated without calling Docker, GitHub, WordPress, or the AI gateway. Set
 * WP_AGENT_FINAL_LIVE_MANIFEST_DIR only for fixture validation.
 *
 * Run from the host:
 * php tests/final-live-artifact-manifest-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live artifact manifest contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_manifest_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_manifest_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_manifest_fail( $message, $details );
	}
}

function wp_agent_manifest_read( $path ) {
	wp_agent_manifest_assert( is_file( $path ), 'Required artifact manifest file is missing.', array(
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_manifest_assert( is_string( $text ) && '' !== $text, 'Required artifact manifest file could not be read.', array(
		'path' => $path,
	) );

	return $text;
}

function wp_agent_manifest_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_manifest_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_manifest_decode_json( $name, $text ) {
	$data = json_decode( $text, true );
	wp_agent_manifest_assert( is_array( $data ), $name . ' should be valid JSON.', array(
		'json_error' => json_last_error_msg(),
	) );
	return $data;
}

function wp_agent_manifest_value( $data, $path ) {
	$current = $data;
	foreach ( explode( '.', $path ) as $part ) {
		if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
			return null;
		}
		$current = $current[ $part ];
	}
	return $current;
}

function wp_agent_manifest_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_manifest_assert( empty( $missing ), $name . ' is missing required artifact manifest markers.', array(
		'missing' => $missing,
	) );

	return count( $markers );
}

function wp_agent_manifest_latest( $dir, $pattern ) {
	$files = glob( $dir . '/' . $pattern );
	if ( ! is_array( $files ) || empty( $files ) ) {
		return '';
	}
	sort( $files );
	return (string) end( $files );
}

function wp_agent_manifest_is_placeholder( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return true;
	}
	if ( false !== stripos( $value, 'REPLACE_WITH_' ) ) {
		return true;
	}
	return in_array( strtolower( $value ), array( 'owner/repo', 'example/repo', 'skills/example', 'skills/default-store-fixture', 'yyyy-mm-ddthh:mm:ssz' ), true );
}

function wp_agent_manifest_artifacts_by_kind( $manifest ) {
	$artifacts = $manifest['artifacts'] ?? array();
	wp_agent_manifest_assert( is_array( $artifacts ), 'Manifest artifacts should be an array.' );

	$by_kind = array();
	foreach ( $artifacts as $artifact ) {
		wp_agent_manifest_assert( is_array( $artifact ), 'Manifest artifact entries should be objects.' );
		$kind = (string) ( $artifact['kind'] ?? '' );
		wp_agent_manifest_assert( '' !== $kind, 'Manifest artifact entry is missing kind.', $artifact );
		$by_kind[ $kind ] = $artifact;
	}
	return $by_kind;
}

function wp_agent_manifest_validate_common_schema( $name, $manifest, $artifact_root = '/path/to/wp-agent/design/test-logs' ) {
	wp_agent_manifest_assert( 1 === (int) ( $manifest['schema_version'] ?? 0 ), $name . ' schema_version should be 1.', $manifest );
	wp_agent_manifest_assert( 'wp-agent-final-live-artifact-manifest' === (string) ( $manifest['manifest_type'] ?? '' ), $name . ' manifest_type drifted.', $manifest );
	wp_agent_manifest_assert( 'docker-compose.official.yml' === (string) wp_agent_manifest_value( $manifest, 'official_stack.compose_file' ), $name . ' should keep the official compose file.', $manifest );
	wp_agent_manifest_assert( 'wp-agent-official' === (string) wp_agent_manifest_value( $manifest, 'official_stack.project' ), $name . ' should keep the official compose project.', $manifest );
	wp_agent_manifest_assert( 'wordpress:php8.3-apache' === (string) wp_agent_manifest_value( $manifest, 'official_stack.wordpress_image' ), $name . ' should keep the official WordPress image.', $manifest );
	wp_agent_manifest_assert( 'wordpress:cli-php8.3' === (string) wp_agent_manifest_value( $manifest, 'official_stack.wpcli_image' ), $name . ' should keep the official WP-CLI image.', $manifest );
	wp_agent_manifest_assert( '/path/to/wp-agent/database/official-mysql' === (string) wp_agent_manifest_value( $manifest, 'official_stack.database_dir' ), $name . ' should keep the official database directory.', $manifest );
	wp_agent_manifest_assert( false === (bool) wp_agent_manifest_value( $manifest, 'git.remote_push' ), $name . ' should record remote_push=false.', $manifest );
	wp_agent_manifest_assert( false === (bool) wp_agent_manifest_value( $manifest, 'inputs.token_disclosed' ), $name . ' should record token_disclosed=false for inputs.', $manifest );
	$review_packet_source = (string) wp_agent_manifest_value( $manifest, 'inputs.review_packet_source' );
	wp_agent_manifest_assert( ! wp_agent_manifest_is_placeholder( $review_packet_source ), $name . ' should record the approved review packet source.', $manifest );
	wp_agent_manifest_assert( 1 === preg_match( '/final-live-review-packet-(?:YYYYMMDD|\d{8})\.md$/', $review_packet_source ), $name . ' should record a completed review packet source, not the template.', $manifest );
	wp_agent_manifest_assert( 'approve-multi-hour-soak' === (string) wp_agent_manifest_value( $manifest, 'inputs.soak_approval_phrase' ), $name . ' should record the required soak approval phrase.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'inputs.soak_approval_confirmed' ), $name . ' should record soak_approval_confirmed=true.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'command_plan.commands_executable' ), $name . ' should record executable reviewed commands after placeholders are replaced.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'command_plan.ready_for_live_execution' ), $name . ' should record ready_for_live_execution=true after packet/env review.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'command_plan.review_packet_ready' ), $name . ' should record review_packet_ready=true.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'command_plan.review_packet_env_consistent' ), $name . ' should record review_packet_env_consistent=true.', $manifest );
	wp_agent_manifest_assert( false === (bool) wp_agent_manifest_value( $manifest, 'command_plan.placeholder_rejected' ), $name . ' should record placeholder_rejected=false after final input review.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'command_plan.ux_validation_before_manifest' ), $name . ' should record ux_validation_before_manifest=true.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'command_plan.summary_before_manifest' ), $name . ' should record summary_before_manifest=true.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'contracts.no_live_acceptance.success' ), $name . ' should record no-live aggregate success.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'contracts.completion_gate.completion_ready' ), $name . ' should record completion_ready=true only after final artifacts pass.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'contracts.artifact_manifest.success' ), $name . ' should record artifact manifest contract success.', $manifest );
	wp_agent_manifest_assert( 'passed' === (string) wp_agent_manifest_value( $manifest, 'external_gates.row_6_skills_store' ), $name . ' should record #6 as passed after live review.', $manifest );
	wp_agent_manifest_assert( 'passed' === (string) wp_agent_manifest_value( $manifest, 'external_gates.row_9_daemon_soak' ), $name . ' should record #9 as passed after live review.', $manifest );
	wp_agent_manifest_assert( 'passed' === (string) wp_agent_manifest_value( $manifest, 'security.secret_scan' ), $name . ' should record secret scan success.', $manifest );
	wp_agent_manifest_assert( false === (bool) wp_agent_manifest_value( $manifest, 'security.token_disclosed' ), $name . ' should record token_disclosed=false for security.', $manifest );
	wp_agent_manifest_assert( false === (bool) wp_agent_manifest_value( $manifest, 'security.raw_secret_assignments' ), $name . ' should record raw_secret_assignments=false.', $manifest );
	wp_agent_manifest_assert( true === (bool) wp_agent_manifest_value( $manifest, 'security.official_db_dir_confirmed' ), $name . ' should confirm the official DB dir.', $manifest );

	$required_artifacts = array(
		'no_live_acceptance'     => '/final-no-live-acceptance-contract-(?:YYYYMMDD|\d{8})\.md$/',
	'strict_preflight'       => '/final-acceptance-preflight-(?:YYYYMMDD|\d{8})\.json$/',
	'command_plan'           => '/final-live-command-plan-(?:YYYYMMDD|\d{8})\.json$/',
	'github_skill_store'     => '/final-live-github-skill-store-(?:YYYYMMDD|\d{8})\.json$/',
		'editorial_daemon_soak'  => '/final-live-editorial-daemon-soak-(?:YYYYMMDD|\d{8})\.json$/',
		'git_hygiene'            => '/git-hygiene-contract-(?:YYYYMMDD|\d{8})\.md$/',
		'ux_evidence'            => '/ui-playwright-evidence-contract-(?:YYYYMMDD|\d{8})\.md$/',
		'acceptance_summary'     => '/final-live-acceptance-summary-(?:YYYYMMDD|\d{8})\.md$/',
		'archive_redaction_report' => '/final-live-archive-redaction-(?:YYYYMMDD|\d{8})\.md$/',
	);
	$artifacts = wp_agent_manifest_artifacts_by_kind( $manifest );
	foreach ( $required_artifacts as $kind => $pattern ) {
		wp_agent_manifest_assert( isset( $artifacts[ $kind ] ), $name . ' is missing a required artifact kind.', array(
			'kind' => $kind,
		) );
		$path = (string) ( $artifacts[ $kind ]['path'] ?? '' );
		$artifact_root = rtrim( (string) $artifact_root, '/' ) . '/';
		wp_agent_manifest_assert( 0 === strpos( $path, $artifact_root ), $name . ' artifact path should live in the expected final artifact directory.', array(
			'kind'          => $kind,
			'path'          => $path,
			'artifact_root' => $artifact_root,
		) );
		wp_agent_manifest_assert( 1 === preg_match( $pattern, $path ), $name . ' artifact path should keep the expected filename pattern.', array(
			'kind'     => $kind,
			'path'     => $path,
			'pattern'  => $pattern,
		) );
		wp_agent_manifest_assert( false === (bool) ( $artifacts[ $kind ]['token_disclosed'] ?? true ), $name . ' artifact should record token_disclosed=false.', array(
			'kind' => $kind,
		) );
		wp_agent_manifest_assert( '' !== trim( (string) ( $artifacts[ $kind ]['validated_by'] ?? '' ) ), $name . ' artifact should record validator.', array(
			'kind' => $kind,
		) );
	}

	return count( $required_artifacts );
}

function wp_agent_manifest_validate_actual_archive( $path, $manifest ) {
	wp_agent_manifest_assert( ! wp_agent_manifest_is_placeholder( $manifest['created_at'] ?? '' ), 'Actual artifact manifest should have a concrete created_at value.', $manifest );
	wp_agent_manifest_assert( ! wp_agent_manifest_is_placeholder( wp_agent_manifest_value( $manifest, 'git.head' ) ), 'Actual artifact manifest should record a concrete Git HEAD.', $manifest );
	wp_agent_manifest_assert( ! wp_agent_manifest_is_placeholder( wp_agent_manifest_value( $manifest, 'inputs.github_repository' ) ), 'Actual artifact manifest should record the official GitHub repository.', $manifest );
	wp_agent_manifest_assert( ! wp_agent_manifest_is_placeholder( wp_agent_manifest_value( $manifest, 'inputs.github_skill_path' ) ), 'Actual artifact manifest should record the official Skill path.', $manifest );
	wp_agent_manifest_assert( ! wp_agent_manifest_is_placeholder( wp_agent_manifest_value( $manifest, 'inputs.github_ref' ) ), 'Actual artifact manifest should record the official GitHub ref.', $manifest );
	wp_agent_manifest_assert( ! wp_agent_manifest_is_placeholder( wp_agent_manifest_value( $manifest, 'inputs.soak_source_url' ) ), 'Actual artifact manifest should record the public soak source URL.', $manifest );

	$artifacts = wp_agent_manifest_artifacts_by_kind( $manifest );
	foreach ( $artifacts as $kind => $artifact ) {
		$artifact_path = (string) ( $artifact['path'] ?? '' );
		$sha256        = (string) ( $artifact['sha256'] ?? '' );
		wp_agent_manifest_assert( is_file( $artifact_path ), 'Actual manifest references a missing artifact.', array(
			'kind' => $kind,
			'path' => $artifact_path,
		) );
		wp_agent_manifest_assert( 1 === preg_match( '/^[a-f0-9]{64}$/', $sha256 ), 'Actual manifest artifact sha256 should be a lowercase hex digest.', array(
			'kind'   => $kind,
			'sha256' => $sha256,
		) );
		wp_agent_manifest_assert( hash_file( 'sha256', $artifact_path ) === $sha256, 'Actual manifest artifact sha256 does not match the file on disk.', array(
			'kind' => $kind,
			'path' => $artifact_path,
		) );
		wp_agent_manifest_assert( (int) ( $artifact['size_bytes'] ?? 0 ) === filesize( $artifact_path ), 'Actual manifest artifact size does not match the file on disk.', array(
			'kind' => $kind,
			'path' => $artifact_path,
		) );
	}

	$command_plan_sha = (string) wp_agent_manifest_value( $manifest, 'command_plan.output_sha256' );
	if ( isset( $artifacts['command_plan'] ) && '' !== trim( $command_plan_sha ) && false === wp_agent_manifest_is_placeholder( $command_plan_sha ) ) {
		wp_agent_manifest_assert( $command_plan_sha === (string) ( $artifacts['command_plan']['sha256'] ?? '' ), 'Actual manifest command_plan.output_sha256 should match the archived command plan artifact.', $manifest );
	}

	return realpath( $path );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_manifest_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$manifest_dir_override = getenv( 'WP_AGENT_FINAL_LIVE_MANIFEST_DIR' );
$manifest_dir          = is_string( $manifest_dir_override ) && '' !== trim( $manifest_dir_override )
	? trim( $manifest_dir_override )
	: dirname( $plugin_dir ) . '/design/test-logs';
$design_logs = realpath( $manifest_dir );
wp_agent_manifest_assert( $design_logs && is_dir( $design_logs ), 'Design test log directory is missing.' );

$files = array(
	'manifest_template' => $plugin_dir . '/tests/final-live-artifact-manifest-template.json',
	'README.md'         => $plugin_dir . '/README.md',
	'goals.md'          => $plugin_dir . '/goals.md',
	'report_template'   => $plugin_dir . '/tests/final-live-report-template.md',
	'command_plan'      => $plugin_dir . '/tests/final-live-command-plan.php',
	'manifest_builder'  => $plugin_dir . '/tests/final-live-artifact-manifest-build.php',
);

$texts = array();
foreach ( $files as $name => $path ) {
	$texts[ $name ] = wp_agent_manifest_read( $path );
	wp_agent_manifest_assert_no_raw_secrets( $name, $texts[ $name ] );
}

wp_agent_manifest_assert( false === strpos( $texts['manifest_template'], 'owner/repo' ), 'Artifact manifest template must not reuse the executable GitHub placeholder.', array(
	'template' => $files['manifest_template'],
) );
wp_agent_manifest_assert( false === strpos( $texts['manifest_template'], 'skills/example' ), 'Artifact manifest template must not reuse the executable Skill path placeholder.', array(
	'template' => $files['manifest_template'],
) );

$template_manifest = wp_agent_manifest_decode_json( 'final-live-artifact-manifest-template.json', $texts['manifest_template'] );
wp_agent_manifest_assert( 'template_pending_live' === (string) ( $template_manifest['status'] ?? '' ), 'Artifact manifest template should remain explicitly pending live.', $template_manifest );
$artifact_count = wp_agent_manifest_validate_common_schema( 'final-live-artifact-manifest-template.json', $template_manifest );

$required_markers = 0;
$required_markers += wp_agent_manifest_require_markers( 'README.md', $texts['README.md'], array(
	'php tests/final-live-artifact-manifest-contract.php',
	'php tests/final-live-artifact-manifest-build.php',
	'php tests/final-live-artifact-manifest-build-contract.php',
	'tests/final-live-artifact-manifest-template.json',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'final-live-command-plan-YYYYMMDD.json',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'final-live-archive-redaction-YYYYMMDD.md',
	'summary_before_manifest',
	'review_packet_source',
	'review_packet_env_consistent',
) );
$required_markers += wp_agent_manifest_require_markers( 'goals.md', $texts['goals.md'], array(
	'tests/final-live-artifact-manifest-contract.php',
	'tests/final-live-artifact-manifest-build.php',
	'tests/final-live-artifact-manifest-build-contract.php',
	'tests/final-live-artifact-manifest-template.json',
	'最终 live artifact manifest',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'final-live-command-plan-YYYYMMDD.json',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'final-live-archive-redaction-YYYYMMDD.md',
	'summary_before_manifest',
	'review_packet_source',
	'review_packet_env_consistent',
) );
$required_markers += wp_agent_manifest_require_markers( 'final-live-report-template.md', $texts['report_template'], array(
	'tests/final-live-artifact-manifest-template.json',
	'php tests/final-live-artifact-manifest-contract.php',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'final-live-command-plan-YYYYMMDD.json',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'final-live-archive-redaction-YYYYMMDD.md',
	'summary_before_manifest',
	'path/to/final-live-review-packet-YYYYMMDD.md',
	'review_packet_env_consistent',
) );
$required_markers += wp_agent_manifest_require_markers( 'final-live-command-plan.php', $texts['command_plan'], array(
	'artifact_manifest_build',
	'php tests/final-live-artifact-manifest-build.php',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
	'ux_evidence_validation',
	'acceptance_summary',
	'summary_before_manifest',
	'php tests/ui-playwright-evidence-contract.php',
	'artifact_manifest',
	'php tests/final-live-artifact-manifest-contract.php',
	'ui-playwright-evidence-contract-YYYYMMDD.md',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'final-live-archive-redaction-YYYYMMDD.md',
	'final-live-review-packet-YYYYMMDD.md',
) );
$required_markers += wp_agent_manifest_require_markers( 'final-live-artifact-manifest-build.php', $texts['manifest_builder'], array(
	'WP_AGENT_FINAL_LIVE_MANIFEST_WRITE',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
	'final-live-github-skill-store-*.json',
	'final-live-command-plan-*.json',
	'final-live-editorial-daemon-soak-*.json',
	'final-live-acceptance-summary-*.md',
	'final-live-archive-redaction-[0-9]*.md',
	'summary_before_manifest',
	'git-hygiene-contract-*.md',
	'ui-playwright-evidence-contract-*.md',
	'token_disclosed=true',
	'review_packet_env_consistent',
) );

$actual_manifest_path = wp_agent_manifest_latest( $design_logs, 'final-live-artifact-manifest-*.json' );
$actual_manifest_real = '';
if ( '' !== $actual_manifest_path ) {
	$actual_text = wp_agent_manifest_read( $actual_manifest_path );
	wp_agent_manifest_assert_no_raw_secrets( basename( $actual_manifest_path ), $actual_text );
	$actual_manifest = wp_agent_manifest_decode_json( basename( $actual_manifest_path ), $actual_text );
	wp_agent_manifest_assert( 'template_pending_live' !== (string) ( $actual_manifest['status'] ?? '' ), 'Actual artifact manifest must not keep the template status.', $actual_manifest );
	wp_agent_manifest_validate_common_schema( basename( $actual_manifest_path ), $actual_manifest, $design_logs );
	$actual_manifest_real = wp_agent_manifest_validate_actual_archive( $actual_manifest_path, $actual_manifest );
}

echo json_encode( array(
	'success'                 => true,
	'contract'                => 'final_live_artifact_manifest_contract',
	'template'                => $files['manifest_template'],
	'artifacts_required'      => $artifact_count,
	'required_markers'        => $required_markers,
	'actual_manifest_present' => '' !== $actual_manifest_path,
	'actual_manifest_path'    => $actual_manifest_real,
	'artifact_dir'            => $design_logs,
	'artifact_dir_source'     => is_string( $manifest_dir_override ) && '' !== trim( $manifest_dir_override ) ? 'env' : 'default',
	'archive_target'          => 'final-live-artifact-manifest-YYYYMMDD.json',
	'secret_assignments'      => false,
	'live_network_calls'      => false,
	'ai_gateway_calls'        => false,
	'github_calls'            => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
