<?php
/**
 * Host-side final live artifact manifest builder.
 *
 * Builds the reviewed final live artifact manifest from already archived local
 * evidence. It reads the reviewed final live input env and the design/test-logs
 * artifact directory, computes sha256/size metadata, and refuses placeholders,
 * raw secrets, missing artifacts, and token_disclosed=true records. It does not
 * call Docker, GitHub, WordPress, or the AI gateway.
 *
 * Run from the host after the approved live artifacts are archived:
 * WP_AGENT_FINAL_LIVE_MANIFEST_WRITE=1 php tests/final-live-artifact-manifest-build.php path/to/reviewed.env /path/to/wp-agent/design/test-logs path/to/final-live-review-packet-YYYYMMDD.md
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live artifact manifest builder must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_manifest_build_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_manifest_build_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_manifest_build_fail( $message, $details );
	}
}

function wp_agent_manifest_build_read( $path ) {
	wp_agent_manifest_build_assert( is_file( $path ), 'Required file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_manifest_build_assert( is_string( $text ) && '' !== $text, 'Required file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_manifest_build_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_manifest_build_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_manifest_build_parse_env( $text ) {
	$values = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
			continue;
		}
		list( $key, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
		$values[ $key ] = trim( $value, "\"'" );
	}
	return $values;
}

function wp_agent_manifest_build_latest( $dir, $pattern ) {
	$files = glob( rtrim( $dir, '/' ) . '/' . $pattern );
	if ( ! is_array( $files ) || empty( $files ) ) {
		return '';
	}
	sort( $files );
	return (string) end( $files );
}

function wp_agent_manifest_build_json( $name, $text ) {
	$data = json_decode( $text, true );
	wp_agent_manifest_build_assert( is_array( $data ), $name . ' should be valid JSON.', array(
		'json_error' => json_last_error_msg(),
	) );
	return $data;
}

function wp_agent_manifest_build_json_fragment( $text ) {
	$start = strpos( $text, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $text, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_manifest_build_is_placeholder( $value, $placeholders ) {
	$value = trim( (string) $value );
	if ( '' === $value || false !== stripos( $value, 'REPLACE_WITH_' ) ) {
		return true;
	}
	return in_array( strtolower( $value ), array_map( 'strtolower', $placeholders ), true );
}

function wp_agent_manifest_build_require_value( $values, $key ) {
	wp_agent_manifest_build_assert( array_key_exists( $key, $values ) && '' !== trim( (string) $values[ $key ] ), 'Reviewed final live input is missing a required key.', array(
		'key' => $key,
	) );
	return (string) $values[ $key ];
}

function wp_agent_manifest_build_public_http_url( $url ) {
	return 1 === preg_match( '#^https?://#i', $url )
		&& 1 !== preg_match( '#^https?://(?:localhost|127\.|10\.|172\.(?:1[6-9]|2[0-9]|3[0-1])\.|192\.168\.|\[?::1)#i', $url );
}

function wp_agent_manifest_build_shell_json( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	$text = implode( "\n", $output );
	wp_agent_manifest_build_assert( 0 === $status, 'Required local helper command failed.', array(
		'command' => implode( ' ', $args ),
		'status'  => $status,
		'output'  => $text,
	) );
	$data = wp_agent_manifest_build_json_fragment( $text );
	wp_agent_manifest_build_assert( is_array( $data ), 'Required local helper command did not print JSON.', array(
		'command' => implode( ' ', $args ),
		'output'  => $text,
	) );
	return array( $text, $data );
}

function wp_agent_manifest_build_git( $plugin_dir, $args ) {
	$command = array_merge( array( 'git', '-C', $plugin_dir ), $args );
	$shell   = implode( ' ', array_map( 'escapeshellarg', $command ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $shell, $output, $status );
	wp_agent_manifest_build_assert( 0 === $status, 'Git metadata command failed.', array(
		'args'   => $args,
		'status' => $status,
		'output' => implode( "\n", $output ),
	) );
	return trim( implode( "\n", $output ) );
}

function wp_agent_manifest_build_artifact( $kind, $path, $validator ) {
	wp_agent_manifest_build_assert( is_file( $path ), 'Required archived final live artifact is missing.', array(
		'kind' => $kind,
		'path' => $path,
	) );
	$text = wp_agent_manifest_build_read( $path );
	wp_agent_manifest_build_assert_no_raw_secrets( basename( $path ), $text );
	if ( false !== strpos( preg_replace( '/\s+/', '', $text ), '"token_disclosed":true' ) ) {
		wp_agent_manifest_build_fail( 'Archived final live artifact records token_disclosed=true.', array(
			'kind' => $kind,
			'path' => $path,
		) );
	}
	return array(
		'kind'            => $kind,
		'path'            => $path,
		'sha256'          => hash_file( 'sha256', $path ),
		'size_bytes'      => filesize( $path ),
		'validated_by'    => $validator,
		'token_disclosed' => false,
		'_text'           => $text,
	);
}

function wp_agent_manifest_build_date_suffix( $artifacts ) {
	$suffixes = array();
	foreach ( $artifacts as $artifact ) {
		if ( preg_match( '/-(\d{8})\.(?:json|md)$/', (string) $artifact['path'], $matches ) ) {
			$suffixes[ $matches[1] ] = true;
		}
	}
	if ( 1 === count( $suffixes ) ) {
		return (string) array_key_first( $suffixes );
	}
	return gmdate( 'Ymd' );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_manifest_build_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$input_path = $argv[1] ?? ( $plugin_dir . '/tests/final-live-inputs.example.env' );
$input_path = realpath( $input_path ) ?: $input_path;
$input_text = wp_agent_manifest_build_read( $input_path );
wp_agent_manifest_build_assert_no_raw_secrets( basename( $input_path ), $input_text );
$values = wp_agent_manifest_build_parse_env( $input_text );

$artifact_dir = $argv[2] ?? ( dirname( $plugin_dir ) . '/design/test-logs' );
$artifact_dir = realpath( $artifact_dir ) ?: $artifact_dir;
wp_agent_manifest_build_assert( is_dir( $artifact_dir ), 'Final live artifact directory is missing.', array(
	'artifact_dir' => $artifact_dir,
) );
$artifact_dir = rtrim( $artifact_dir, '/' );

$packet_path = $argv[3] ?? ( $plugin_dir . '/tests/final-live-review-packet-template.md' );
$packet_path = realpath( $packet_path ) ?: $packet_path;
$packet_text = wp_agent_manifest_build_read( $packet_path );
wp_agent_manifest_build_assert_no_raw_secrets( basename( $packet_path ), $packet_text );

$repository = wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_GITHUB_REPOSITORY' );
$skill_path = wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_GITHUB_SKILL_PATH' );
$github_ref = wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_GITHUB_REF' );
$review     = wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY' );
$source_url = wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL' );
$approval_phrase = wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' );
$db_dir     = wp_agent_manifest_build_require_value( $values, 'WP_AGENT_OFFICIAL_DB_DIR' );

wp_agent_manifest_build_assert( ! wp_agent_manifest_build_is_placeholder( $repository, array( 'owner/repo', 'example/repo' ) ), 'Reviewed input still contains a placeholder GitHub repository.', array(
	'repository' => $repository,
) );
wp_agent_manifest_build_assert( ! wp_agent_manifest_build_is_placeholder( $skill_path, array( 'skills/example', 'skills/default-store-fixture' ) ), 'Reviewed input still contains a placeholder Skill path.', array(
	'skill_path' => $skill_path,
) );
wp_agent_manifest_build_assert( in_array( $review, array( 'quarantine', 'activate', 'activate_pin' ), true ), 'Reviewed input has an invalid GitHub review policy.', array(
	'review_policy' => $review,
) );
wp_agent_manifest_build_assert( '/path/to/wp-agent/database/official-mysql' === $db_dir, 'Final manifest build must use the official persistent database directory.', array(
	'db_dir' => $db_dir,
) );
wp_agent_manifest_build_assert( wp_agent_manifest_build_public_http_url( $source_url ), 'Reviewed soak source URL must be public HTTP(S).', array(
	'source_url' => $source_url,
) );
wp_agent_manifest_build_assert( 'approve-multi-hour-soak' === $approval_phrase, 'Reviewed input must include the exact live soak approval phrase.', array(
	'key' => 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
) );

list( $command_plan_output, $command_plan ) = wp_agent_manifest_build_shell_json( array(
	PHP_BINARY,
	$plugin_dir . '/tests/final-live-command-plan.php',
	$input_path,
	$packet_path,
) );
wp_agent_manifest_build_assert( true === (bool) ( $command_plan['commands_executable'] ?? false ), 'Final command plan must be executable after placeholder replacement.', $command_plan );
wp_agent_manifest_build_assert( true === (bool) ( $command_plan['ready_for_live_execution'] ?? false ), 'Final command plan must be ready for live execution after review packet approval.', $command_plan );
wp_agent_manifest_build_assert( true === (bool) ( $command_plan['review_packet_ready'] ?? false ), 'Final command plan must use a completed review packet before manifest build.', $command_plan );
wp_agent_manifest_build_assert( true === (bool) ( $command_plan['review_packet_env_consistent'] ?? false ), 'Final command plan review packet must match the reviewed env before manifest build.', $command_plan );
wp_agent_manifest_build_assert( empty( $command_plan['review_packet_env_mismatches'] ?? array() ), 'Final command plan review packet/env mismatches must be empty before manifest build.', $command_plan );
wp_agent_manifest_build_assert( false === (bool) ( $command_plan['placeholder_rejected'] ?? true ), 'Final command plan still rejects placeholders.', $command_plan );
wp_agent_manifest_build_assert( true === (bool) ( $command_plan['ux_validation_before_manifest'] ?? false ), 'Final command plan must validate UX evidence before building the manifest.', $command_plan );
wp_agent_manifest_build_assert( true === (bool) ( $command_plan['summary_before_manifest'] ?? false ), 'Final command plan must write the acceptance summary before building the manifest.', $command_plan );
wp_agent_manifest_build_assert( false === (bool) ( $command_plan['secret_assignments'] ?? true ), 'Final command plan input contains secret assignments.', $command_plan );

$artifact_specs = array(
	'no_live_acceptance'    => array( 'final-no-live-acceptance-contract-*.md', 'tests/final-no-live-acceptance-contract.php' ),
	'strict_preflight'      => array( 'final-acceptance-preflight-*.json', 'tests/final-acceptance-preflight.php' ),
	'command_plan'          => array( 'final-live-command-plan-*.json', 'tests/final-live-command-plan.php' ),
	'github_skill_store'    => array( 'final-live-github-skill-store-*.json', 'tests/final-live-completion-gate-contract.php' ),
	'editorial_daemon_soak' => array( 'final-live-editorial-daemon-soak-*.json', 'tests/final-live-completion-gate-contract.php' ),
	'git_hygiene'           => array( 'git-hygiene-contract-*.md', 'tests/git-hygiene-contract.php' ),
	'ux_evidence'           => array( 'ui-playwright-evidence-contract-*.md', 'tests/ui-playwright-evidence-contract.php' ),
	'acceptance_summary'    => array( 'final-live-acceptance-summary-*.md', 'tests/final-live-completion-gate-contract.php' ),
	'archive_redaction_report' => array( 'final-live-archive-redaction-[0-9]*.md', 'tests/final-live-archive-redaction-contract.php' ),
);

$artifacts = array();
foreach ( $artifact_specs as $kind => $spec ) {
	$path = wp_agent_manifest_build_latest( $artifact_dir, $spec[0] );
	wp_agent_manifest_build_assert( '' !== $path, 'Required archived final live artifact pattern is missing.', array(
		'kind'    => $kind,
		'pattern' => $spec[0],
	) );
	$artifacts[ $kind ] = wp_agent_manifest_build_artifact( $kind, $path, $spec[1] );
}

$no_live_json = wp_agent_manifest_build_json_fragment( $artifacts['no_live_acceptance']['_text'] );
wp_agent_manifest_build_assert( is_array( $no_live_json ) && true === (bool) ( $no_live_json['success'] ?? false ), 'No-live acceptance artifact must record success=true.', array(
	'path' => $artifacts['no_live_acceptance']['path'],
) );

$preflight_json = wp_agent_manifest_build_json( basename( $artifacts['strict_preflight']['path'] ), $artifacts['strict_preflight']['_text'] );
wp_agent_manifest_build_assert( true === (bool) ( $preflight_json['ready'] ?? false ), 'Strict preflight artifact must record ready=true.', $preflight_json );
wp_agent_manifest_build_assert( false === (bool) ( $preflight_json['token_disclosed'] ?? true ), 'Strict preflight artifact must record token_disclosed=false.', $preflight_json );

$command_plan_json = wp_agent_manifest_build_json( basename( $artifacts['command_plan']['path'] ), $artifacts['command_plan']['_text'] );
wp_agent_manifest_build_assert( hash( 'sha256', $command_plan_output ) === $artifacts['command_plan']['sha256'], 'Archived command plan artifact must match the regenerated reviewed command plan output.', array(
	'archived_command_plan' => $artifacts['command_plan']['path'],
	'archived_sha256'      => $artifacts['command_plan']['sha256'],
	'regenerated_sha256'   => hash( 'sha256', $command_plan_output ),
) );
foreach ( array(
	'commands_executable',
	'ready_for_live_execution',
	'review_packet_ready',
	'review_packet_env_consistent',
	'ux_validation_before_manifest',
	'summary_before_manifest',
) as $key ) {
	wp_agent_manifest_build_assert( true === (bool) ( $command_plan_json[ $key ] ?? false ), 'Archived command plan artifact must record ' . $key . '=true.', $command_plan_json );
}
wp_agent_manifest_build_assert( false === (bool) ( $command_plan_json['placeholder_rejected'] ?? true ), 'Archived command plan artifact must record placeholder_rejected=false.', $command_plan_json );
wp_agent_manifest_build_assert( false === (bool) ( $command_plan_json['secret_assignments'] ?? true ), 'Archived command plan artifact must record secret_assignments=false.', $command_plan_json );
wp_agent_manifest_build_assert( empty( $command_plan_json['review_packet_env_mismatches'] ?? array() ), 'Archived command plan artifact must have no review packet/env mismatches.', $command_plan_json );

$github_json = wp_agent_manifest_build_json( basename( $artifacts['github_skill_store']['path'] ), $artifacts['github_skill_store']['_text'] );
wp_agent_manifest_build_assert( true === (bool) ( $github_json['success'] ?? false ), 'GitHub Skill Store artifact must record success=true.', $github_json );
wp_agent_manifest_build_assert( false === (bool) ( $github_json['token_disclosed'] ?? true ), 'GitHub Skill Store artifact must record token_disclosed=false.', $github_json );
wp_agent_manifest_build_assert( true === (bool) ( $github_json['lock_under_runtime_root'] ?? false ), 'GitHub Skill Store artifact must record lock_under_runtime_root=true.', $github_json );

$soak_json = wp_agent_manifest_build_json( basename( $artifacts['editorial_daemon_soak']['path'] ), $artifacts['editorial_daemon_soak']['_text'] );
wp_agent_manifest_build_assert( true === (bool) ( $soak_json['success'] ?? false ), 'Editorial daemon soak artifact must record success=true.', $soak_json );
wp_agent_manifest_build_assert( true === (bool) ( $soak_json['soak_completed'] ?? false ), 'Editorial daemon soak artifact must record soak_completed=true.', $soak_json );
wp_agent_manifest_build_assert( true === (bool) ( $soak_json['approval_phrase_confirmed'] ?? false ), 'Editorial daemon soak artifact must record approval_phrase_confirmed=true.', $soak_json );
wp_agent_manifest_build_assert( (int) ( $soak_json['elapsed_seconds'] ?? 0 ) >= (int) ( $soak_json['soak_seconds'] ?? 1 ), 'Editorial daemon soak artifact elapsed time must cover requested soak seconds.', $soak_json );

$git_text = $artifacts['git_hygiene']['_text'];
wp_agent_manifest_build_assert( false !== strpos( $git_text, 'remote_push=false' ) || false !== strpos( preg_replace( '/\s+/', '', $git_text ), '"remote_push":false' ), 'Git hygiene artifact must record remote_push=false.', array(
	'path' => $artifacts['git_hygiene']['path'],
) );

$ux_text = $artifacts['ux_evidence']['_text'];
foreach ( array(
	'ui_playwright_evidence_contract',
	'ux_quality_gate',
	'chat_stop_playwright',
	'chat_queue_status_playwright',
	'chat_stop_availability_playwright',
	'composer_unlocked_guard',
	'overflow_guard=true',
	'console_guard=true',
	'desktop_mobile_pairs',
	'screenshots_checked',
) as $marker ) {
	wp_agent_manifest_build_assert( false !== strpos( $ux_text, $marker ), 'UX evidence artifact is missing a required marker.', array(
		'marker' => $marker,
		'path'   => $artifacts['ux_evidence']['path'],
	) );
}

$summary_text = $artifacts['acceptance_summary']['_text'];
foreach ( array(
	'/path/to/wp-agent/database/official-mysql',
	'remote_push=false',
	'token_disclosed=false',
	'completion_ready=true',
	'packet_ready=true',
	'ready_for_live_execution=true',
	'review_packet_ready=true',
	'review_packet_env_consistent=true',
	'ui-playwright-evidence-contract',
	'final-live-command-plan',
	'final-live-github-skill-store',
	'final-live-editorial-daemon-soak',
	'final-live-archive-redaction',
	'#6',
	'#9',
) as $marker ) {
	wp_agent_manifest_build_assert( false !== strpos( $summary_text, $marker ), 'Acceptance summary is missing a required marker.', array(
		'marker' => $marker,
		'path'   => $artifacts['acceptance_summary']['path'],
	) );
}

$date_suffix  = wp_agent_manifest_build_date_suffix( $artifacts );
$manifest_path = $artifact_dir . '/final-live-artifact-manifest-' . $date_suffix . '.json';
$git_head     = wp_agent_manifest_build_git( $plugin_dir, array( 'rev-parse', 'HEAD' ) );
$git_status   = wp_agent_manifest_build_git( $plugin_dir, array( 'status', '--short' ) );

$public_artifacts = array();
foreach ( $artifacts as $artifact ) {
	unset( $artifact['_text'] );
	$public_artifacts[] = $artifact;
}

$manifest = array(
	'schema_version' => 1,
	'manifest_type'  => 'wp-agent-final-live-artifact-manifest',
	'status'         => 'reviewed_live_complete',
	'created_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
	'created_by'     => getenv( 'USER' ) ?: 'wp-agent-operator',
	'self_archive'   => array(
		'path'             => $manifest_path,
		'self_hash_policy' => 'do_not_embed_self_hash; verify with sha256sum after archive and record in the final acceptance summary',
	),
	'official_stack' => array(
		'compose_file'    => 'docker-compose.official.yml',
		'project'         => 'wp-agent-official',
		'wordpress_image' => 'wordpress:php8.3-apache',
		'wpcli_image'     => 'wordpress:cli-php8.3',
		'wordpress_url'   => 'http://localhost:12910',
		'database_dir'    => '/path/to/wp-agent/database/official-mysql',
	),
	'git'            => array(
		'head'                => $git_head,
		'remote_push'         => false,
		'status_short'        => '' === $git_status ? 'clean' : $git_status,
		'git_hygiene_command' => 'php tests/git-hygiene-contract.php',
	),
	'inputs'         => array(
		'reviewed_input_source' => $input_path,
		'review_packet_source'  => $packet_path,
		'github_repository'     => $repository,
		'github_ref'            => $github_ref,
		'github_skill_path'     => $skill_path,
		'github_review_policy'  => $review,
		'soak_source_url'       => $source_url,
		'soak_approval_phrase'  => $approval_phrase,
		'soak_approval_confirmed' => true,
		'soak_seconds'          => (int) wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS' ),
		'cost_budget_usd'       => (float) wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' ),
		'artifact_policy'       => wp_agent_manifest_build_require_value( $values, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' ),
		'token_disclosed'       => false,
	),
	'command_plan'   => array(
		'command'              => 'php tests/final-live-command-plan.php path/to/reviewed.env path/to/final-live-review-packet-YYYYMMDD.md',
		'artifact_path'        => $artifacts['command_plan']['path'],
		'commands_executable'  => true,
		'ready_for_live_execution' => true,
		'review_packet_ready'  => true,
		'review_packet_env_consistent' => true,
		'placeholder_rejected' => false,
		'ux_validation_before_manifest' => true,
		'summary_before_manifest' => true,
		'output_sha256'        => $artifacts['command_plan']['sha256'],
	),
	'contracts'      => array(
		'no_live_acceptance' => array(
			'command'       => 'php tests/final-no-live-acceptance-contract.php',
			'success'       => true,
			'output_sha256' => $artifacts['no_live_acceptance']['sha256'],
		),
		'completion_gate'    => array(
			'command'          => 'php tests/final-live-completion-gate-contract.php',
			'completion_ready' => true,
			'output_sha256'    => $artifacts['acceptance_summary']['sha256'],
		),
		'artifact_manifest' => array(
			'command' => 'php tests/final-live-artifact-manifest-contract.php',
			'success' => true,
		),
	),
	'artifacts'      => $public_artifacts,
	'external_gates' => array(
		'row_6_skills_store' => 'passed',
		'row_9_daemon_soak'  => 'passed',
	),
	'security'       => array(
		'secret_scan'               => 'passed',
		'token_disclosed'           => false,
		'official_db_dir_confirmed' => true,
		'raw_secret_assignments'    => false,
	),
	'review'         => array(
		'reviewer' => getenv( 'USER' ) ?: 'wp-agent-operator',
		'decision' => 'approved',
		'notes'    => 'Generated from archived final live artifacts; rerun final manifest, completion gate, archive redaction, and git hygiene contracts after writing.',
	),
);

$write_enabled = '1' === (string) getenv( 'WP_AGENT_FINAL_LIVE_MANIFEST_WRITE' );
$manifest_json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( $write_enabled ) {
	$result = file_put_contents( $manifest_path, $manifest_json, LOCK_EX );
	wp_agent_manifest_build_assert( false !== $result, 'Could not write final live artifact manifest.', array(
		'manifest_path' => $manifest_path,
	) );
}

echo json_encode( array(
	'success'            => true,
	'contract'           => 'final_live_artifact_manifest_build',
	'manifest_ready'     => true,
	'write_enabled'      => $write_enabled,
	'written'            => $write_enabled,
	'manifest_path'      => $manifest_path,
	'artifact_dir'       => $artifact_dir,
	'artifact_count'     => count( $public_artifacts ),
	'command_plan_sha256' => $artifacts['command_plan']['sha256'],
	'token_disclosed'    => false,
	'secret_assignments' => false,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
	'manifest'           => $manifest,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
