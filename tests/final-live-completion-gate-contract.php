<?php
/**
 * Host-side final live completion gate contract.
 *
 * Verifies goals.md cannot be treated as complete until the real final live
 * command plan, GitHub Skill Store artifact, multi-hour daemon soak artifact,
 * summary report, artifact manifest, and archive redaction evidence are archived and
 * schema-valid. Missing artifacts are allowed while the acceptance matrix still
 * leaves #6 and #9 partial. Set
 * WP_AGENT_FINAL_LIVE_ARTIFACT_DIR only for fixture validation. This script
 * reads local files only. It does not call Docker, GitHub, WordPress, or the AI
 * gateway.
 *
 * Run from the host:
 * php tests/final-live-completion-gate-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live completion gate contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_completion_gate_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_completion_gate_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_completion_gate_fail( $message, $details );
	}
}

function wp_agent_completion_gate_read( $path ) {
	wp_agent_completion_gate_assert( is_file( $path ), 'Required file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_completion_gate_assert( is_string( $text ) && '' !== $text, 'Required file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_completion_gate_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_completion_gate_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_completion_gate_partial_rows( $goals ) {
	$rows = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $goals ) as $line ) {
		if ( 0 !== strpos( $line, '|' ) ) {
			continue;
		}
		$cells = array_map( 'trim', explode( '|', trim( $line, " \t|" ) ) );
		if ( count( $cells ) >= 3 && ctype_digit( $cells[0] ) && '部分' === $cells[2] ) {
			$rows[] = (int) $cells[0];
		}
	}
	sort( $rows );
	return $rows;
}

function wp_agent_completion_gate_latest( $dir, $pattern ) {
	$files = glob( $dir . '/' . $pattern );
	if ( ! is_array( $files ) || empty( $files ) ) {
		return '';
	}
	sort( $files );
	return (string) end( $files );
}

function wp_agent_completion_gate_json( $path, &$errors ) {
	if ( '' === $path || ! is_file( $path ) ) {
		$errors[] = 'missing_json_artifact';
		return null;
	}

	$text = wp_agent_completion_gate_read( $path );
	wp_agent_completion_gate_assert_no_raw_secrets( basename( $path ), $text );
	$data = json_decode( $text, true );
	if ( ! is_array( $data ) ) {
		$errors[] = 'invalid_json';
		return null;
	}
	return $data;
}

function wp_agent_completion_gate_non_placeholder( $value, $placeholders ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return false;
	}
	return ! in_array( strtolower( $value ), array_map( 'strtolower', $placeholders ), true );
}

function wp_agent_completion_gate_validate_github( $path ) {
	$errors = array();
	$data   = wp_agent_completion_gate_json( $path, $errors );
	if ( ! is_array( $data ) ) {
		return array( false, $errors );
	}

	$repository = (string) ( $data['repository'] ?? '' );
	$skill_path = (string) ( $data['skill_path'] ?? '' );
	$review     = (string) ( $data['review_policy'] ?? '' );

	if ( true !== (bool) ( $data['success'] ?? false ) ) {
		$errors[] = 'github_success_not_true';
	}
	if ( ! preg_match( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository ) || ! wp_agent_completion_gate_non_placeholder( $repository, array( 'owner/repo', 'example/repo' ) ) ) {
		$errors[] = 'github_repository_missing_or_placeholder';
	}
	if ( ! wp_agent_completion_gate_non_placeholder( $skill_path, array( 'skills/example', 'skills/default-store-fixture' ) ) ) {
		$errors[] = 'github_skill_path_missing_or_placeholder';
	}
	if ( '' === trim( (string) ( $data['ref'] ?? '' ) ) ) {
		$errors[] = 'github_ref_missing';
	}
	if ( ! in_array( $review, array( 'quarantine', 'activate', 'activate_pin' ), true ) ) {
		$errors[] = 'github_review_policy_invalid';
	}
	if ( '' === trim( (string) ( $data['quarantine_id'] ?? '' ) ) ) {
		$errors[] = 'github_quarantine_id_missing';
	}
	if ( '' === trim( (string) ( $data['slug'] ?? '' ) ) || '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
		$errors[] = 'github_skill_identity_missing';
	}
	if ( (int) ( $data['file_count'] ?? 0 ) < 1 ) {
		$errors[] = 'github_file_count_missing';
	}
	if ( false !== (bool) ( $data['token_disclosed'] ?? true ) ) {
		$errors[] = 'github_token_disclosed';
	}
	if ( true !== (bool) ( $data['lock_under_runtime_root'] ?? false ) ) {
		$errors[] = 'github_lock_not_under_runtime_root';
	}

	$errors = array_values( array_unique( $errors ) );
	return array( empty( $errors ), $errors );
}

function wp_agent_completion_gate_validate_soak( $path ) {
	$errors = array();
	$data   = wp_agent_completion_gate_json( $path, $errors );
	if ( ! is_array( $data ) ) {
		return array( false, $errors );
	}

	$cost_budget   = (float) ( $data['cost_budget_usd'] ?? 0 );
	$cost_added    = (float) ( $data['cost_usd_added'] ?? 0 );
	$usage_added   = (int) ( $data['usage_rows_added'] ?? -1 );
	$max_usage     = (int) ( $data['max_usage_rows'] ?? 0 );
	$soak_seconds  = (int) ( $data['soak_seconds'] ?? 0 );
	$elapsed       = (int) ( $data['elapsed_seconds'] ?? 0 );
	$source_url    = (string) ( $data['source_url'] ?? '' );
	$artifact      = (string) ( $data['artifact_policy'] ?? '' );

	if ( true !== (bool) ( $data['success'] ?? false ) ) {
		$errors[] = 'soak_success_not_true';
	}
	if ( true !== (bool) ( $data['soak_completed'] ?? false ) ) {
		$errors[] = 'soak_completed_not_true';
	}
	if ( true !== (bool) ( $data['approval_phrase_confirmed'] ?? false ) ) {
		$errors[] = 'soak_approval_phrase_not_confirmed';
	}
	if ( $soak_seconds < 7200 ) {
		$errors[] = 'soak_seconds_below_multi_hour_minimum';
	}
	if ( $elapsed < $soak_seconds ) {
		$errors[] = 'soak_elapsed_below_requested_soak_seconds';
	}
	if ( (int) ( $data['run_count'] ?? 0 ) < 1 || (int) ( $data['requested_run_count'] ?? 0 ) < 1 ) {
		$errors[] = 'soak_run_count_missing';
	}
	if ( $cost_budget <= 0 ) {
		$errors[] = 'soak_cost_budget_missing';
	}
	if ( $cost_added > $cost_budget ) {
		$errors[] = 'soak_cost_over_budget';
	}
	if ( $usage_added < 0 || $max_usage < 1 || $usage_added > $max_usage ) {
		$errors[] = 'soak_usage_rows_over_guard';
	}
	if ( ! in_array( $artifact, array( 'drafts_journal_usage', 'drafts_journal_usage_media' ), true ) ) {
		$errors[] = 'soak_artifact_policy_invalid';
	}
	if ( ! preg_match( '#^https?://#i', $source_url ) || preg_match( '#^https?://(?:localhost|127\.|10\.|172\.(?:1[6-9]|2[0-9]|3[0-1])\.|192\.168\.|\[?::1)#i', $source_url ) ) {
		$errors[] = 'soak_source_url_not_public_http';
	}
	if ( ! is_array( $data['memory_summary'] ?? null ) || empty( $data['memory_summary'] ) ) {
		$errors[] = 'soak_memory_summary_missing';
	}
	if ( ! is_array( $data['daemon_before'] ?? null ) || ! is_array( $data['daemon_after'] ?? null ) ) {
		$errors[] = 'soak_daemon_snapshots_missing';
	}
	if ( 'paused' !== (string) ( $data['schedule_status'] ?? '' ) ) {
		$errors[] = 'soak_schedule_not_paused_after_test';
	}
	if ( true !== (bool) ( $data['skill_archived'] ?? false ) ) {
		$errors[] = 'soak_skill_not_archived_after_test';
	}

	return array( empty( $errors ), $errors );
}

function wp_agent_completion_gate_validate_summary( $path ) {
	$errors = array();
	if ( '' === $path || ! is_file( $path ) ) {
		return array( false, array( 'summary_missing' ) );
	}

	$text = wp_agent_completion_gate_read( $path );
	wp_agent_completion_gate_assert_no_raw_secrets( basename( $path ), $text );
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
		'chat_queue_status_playwright=true',
		'chat_stop_availability_playwright=true',
		'composer_unlocked_guard=true',
		'final-live-command-plan',
		'final-live-github-skill-store',
		'final-live-editorial-daemon-soak',
		'final-live-archive-redaction',
		'#6',
		'#9',
	) as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$errors[] = 'summary_missing_marker:' . $marker;
		}
	}

	return array( empty( $errors ), $errors );
}

function wp_agent_completion_gate_validate_ux_evidence( $path ) {
	$errors = array();
	if ( '' === $path || ! is_file( $path ) ) {
		return array( false, array( 'ux_evidence_missing' ) );
	}

	$text = wp_agent_completion_gate_read( $path );
	wp_agent_completion_gate_assert_no_raw_secrets( basename( $path ), $text );
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
		if ( false === strpos( $text, $marker ) ) {
			$errors[] = 'ux_evidence_missing_marker:' . $marker;
		}
	}

	return array( empty( $errors ), $errors );
}

function wp_agent_completion_gate_path_under( $path, $root ) {
	$path = str_replace( '\\', '/', (string) $path );
	$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' ) . '/';
	return 0 === strpos( $path, $root );
}

function wp_agent_completion_gate_artifacts_by_kind( $manifest ) {
	$artifacts = $manifest['artifacts'] ?? array();
	if ( ! is_array( $artifacts ) ) {
		return array();
	}
	$by_kind = array();
	foreach ( $artifacts as $artifact ) {
		if ( is_array( $artifact ) && ! empty( $artifact['kind'] ) ) {
			$by_kind[ (string) $artifact['kind'] ] = $artifact;
		}
	}
	return $by_kind;
}

function wp_agent_completion_gate_validate_manifest( $path, $artifact_dir ) {
	$errors = array();
	$data   = wp_agent_completion_gate_json( $path, $errors );
	if ( ! is_array( $data ) ) {
		return array( false, $errors );
	}

	if ( 'wp-agent-final-live-artifact-manifest' !== (string) ( $data['manifest_type'] ?? '' ) ) {
		$errors[] = 'manifest_type_invalid';
	}
	if ( 1 !== (int) ( $data['schema_version'] ?? 0 ) ) {
		$errors[] = 'manifest_schema_version_invalid';
	}
	if ( '/path/to/wp-agent/database/official-mysql' !== (string) ( $data['official_stack']['database_dir'] ?? '' ) ) {
		$errors[] = 'manifest_official_db_dir_invalid';
	}
	if ( false !== (bool) ( $data['git']['remote_push'] ?? true ) ) {
		$errors[] = 'manifest_remote_push_not_false';
	}
	$review_packet_source = trim( (string) ( $data['inputs']['review_packet_source'] ?? '' ) );
	if ( '' === $review_packet_source || false !== stripos( $review_packet_source, 'REPLACE_WITH_' ) || false !== stripos( $review_packet_source, 'template' ) ) {
		$errors[] = 'manifest_review_packet_source_missing';
	}
	if ( true !== (bool) ( $data['inputs']['soak_approval_confirmed'] ?? false ) ) {
		$errors[] = 'manifest_soak_approval_not_confirmed';
	}
	if ( true !== (bool) ( $data['command_plan']['commands_executable'] ?? false ) ) {
		$errors[] = 'manifest_command_plan_not_executable';
	}
	if ( true !== (bool) ( $data['command_plan']['ready_for_live_execution'] ?? false ) ) {
		$errors[] = 'manifest_command_plan_not_ready_for_live_execution';
	}
	if ( true !== (bool) ( $data['command_plan']['review_packet_ready'] ?? false ) ) {
		$errors[] = 'manifest_review_packet_not_ready';
	}
	if ( true !== (bool) ( $data['command_plan']['review_packet_env_consistent'] ?? false ) ) {
		$errors[] = 'manifest_review_packet_env_not_consistent';
	}
	if ( false !== (bool) ( $data['security']['token_disclosed'] ?? true ) ) {
		$errors[] = 'manifest_token_disclosed';
	}
	if ( false !== (bool) ( $data['security']['raw_secret_assignments'] ?? true ) ) {
		$errors[] = 'manifest_raw_secret_assignments';
	}
	if ( true !== (bool) ( $data['security']['official_db_dir_confirmed'] ?? false ) ) {
		$errors[] = 'manifest_official_db_not_confirmed';
	}

	$artifacts = wp_agent_completion_gate_artifacts_by_kind( $data );
	foreach ( array(
		'github_skill_store',
		'editorial_daemon_soak',
		'acceptance_summary',
		'no_live_acceptance',
		'strict_preflight',
		'command_plan',
		'ux_evidence',
		'git_hygiene',
		'archive_redaction_report',
	) as $kind ) {
		if ( empty( $artifacts[ $kind ] ) ) {
			$errors[] = 'manifest_missing_artifact:' . $kind;
			continue;
		}
		$artifact_path = (string) ( $artifacts[ $kind ]['path'] ?? '' );
		if ( ! wp_agent_completion_gate_path_under( $artifact_path, $artifact_dir ) ) {
			$errors[] = 'manifest_artifact_outside_dir:' . $kind;
			continue;
		}
		if ( ! is_file( $artifact_path ) ) {
			$errors[] = 'manifest_artifact_missing:' . $kind;
			continue;
		}
		wp_agent_completion_gate_assert_no_raw_secrets( basename( $artifact_path ), wp_agent_completion_gate_read( $artifact_path ) );
		$sha256 = (string) ( $artifacts[ $kind ]['sha256'] ?? '' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || hash_file( 'sha256', $artifact_path ) !== $sha256 ) {
			$errors[] = 'manifest_artifact_hash_mismatch:' . $kind;
		}
		if ( (int) ( $artifacts[ $kind ]['size_bytes'] ?? -1 ) !== filesize( $artifact_path ) ) {
			$errors[] = 'manifest_artifact_size_mismatch:' . $kind;
		}
		if ( false !== (bool) ( $artifacts[ $kind ]['token_disclosed'] ?? true ) ) {
			$errors[] = 'manifest_artifact_token_disclosed:' . $kind;
		}
	}

	return array( empty( $errors ), $errors );
}

function wp_agent_completion_gate_validate_redaction( $paths ) {
	$errors = array();
	foreach ( $paths as $label => $path ) {
		if ( '' === $path || ! is_file( $path ) ) {
			$errors[] = 'redaction_missing_artifact:' . $label;
			continue;
		}
		$text = wp_agent_completion_gate_read( $path );
		wp_agent_completion_gate_assert_no_raw_secrets( basename( $path ), $text );
		$compact = preg_replace( '/\s+/', '', $text );
		if ( false !== strpos( (string) $compact, 'token_disclosed=true' ) || false !== strpos( (string) $compact, '"token_disclosed":true' ) ) {
			$errors[] = 'redaction_token_disclosed_true:' . $label;
		}
		if ( '.json' === substr( $path, -5 ) ) {
			$data = json_decode( $text, true );
			if ( is_array( $data ) ) {
				$encoded = json_encode( $data, JSON_UNESCAPED_SLASHES );
				if ( false !== strpos( (string) $encoded, '"token_disclosed":true' ) ) {
					$errors[] = 'redaction_token_disclosed_true:' . $label;
				}
			}
		}
	}
	$errors = array_values( array_unique( $errors ) );
	return array( empty( $errors ), $errors );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_completion_gate_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$artifact_dir_override = getenv( 'WP_AGENT_FINAL_LIVE_ARTIFACT_DIR' );
$artifact_dir          = is_string( $artifact_dir_override ) && '' !== trim( $artifact_dir_override )
	? trim( $artifact_dir_override )
	: dirname( $plugin_dir ) . '/design/test-logs';
$design_logs = realpath( $artifact_dir );
wp_agent_completion_gate_assert( $design_logs && is_dir( $design_logs ), 'Design test log directory is missing.' );

$goals_path = $plugin_dir . '/goals.md';
$goals      = wp_agent_completion_gate_read( $goals_path );
wp_agent_completion_gate_assert_no_raw_secrets( 'goals.md', $goals );

$partial_rows = wp_agent_completion_gate_partial_rows( $goals );
$goals_status_in_progress = false !== strpos( $goals, '状态：实施中' );
$goals_claims_complete    = false !== strpos( $goals, '状态：完成' ) || empty( $partial_rows );

$github_path  = wp_agent_completion_gate_latest( $design_logs, 'final-live-github-skill-store-*.json' );
$soak_path    = wp_agent_completion_gate_latest( $design_logs, 'final-live-editorial-daemon-soak-*.json' );
$command_plan_path = wp_agent_completion_gate_latest( $design_logs, 'final-live-command-plan-*.json' );
$summary_path = wp_agent_completion_gate_latest( $design_logs, 'final-live-acceptance-summary-*.md' );
$manifest_path = wp_agent_completion_gate_latest( $design_logs, 'final-live-artifact-manifest-*.json' );
$ux_path      = wp_agent_completion_gate_latest( $design_logs, 'ui-playwright-evidence-contract-*.md' );
$redaction_report_path = wp_agent_completion_gate_latest( $design_logs, 'final-live-archive-redaction-[0-9]*.md' );

list( $github_valid, $github_errors )   = wp_agent_completion_gate_validate_github( $github_path );
list( $soak_valid, $soak_errors )       = wp_agent_completion_gate_validate_soak( $soak_path );
list( $summary_valid, $summary_errors ) = wp_agent_completion_gate_validate_summary( $summary_path );
list( $ux_valid, $ux_errors )           = wp_agent_completion_gate_validate_ux_evidence( $ux_path );
list( $manifest_valid, $manifest_errors ) = wp_agent_completion_gate_validate_manifest( $manifest_path, $design_logs );
list( $redaction_valid, $redaction_errors ) = wp_agent_completion_gate_validate_redaction( array(
	'github'           => $github_path,
	'soak'             => $soak_path,
	'command_plan'     => $command_plan_path,
	'summary'          => $summary_path,
	'ux'               => $ux_path,
	'manifest'         => $manifest_path,
	'redaction_report' => $redaction_report_path,
) );

$completion_ready = $github_valid && $soak_valid && $summary_valid && $ux_valid && $manifest_valid && $redaction_valid;

if ( ! $completion_ready ) {
	wp_agent_completion_gate_assert( $goals_status_in_progress, 'goals.md must remain in progress until final live artifacts are valid.', array(
		'partial_rows' => $partial_rows,
	) );
	wp_agent_completion_gate_assert( array( 6, 9 ) === $partial_rows, 'Only #6 and #9 may remain partial while final live artifacts are missing or invalid.', array(
		'partial_rows' => $partial_rows,
	) );
}
wp_agent_completion_gate_assert( ! ( $goals_claims_complete && ! $completion_ready ), 'goals.md claims completion without valid final live artifacts.', array(
	'completion_ready' => $completion_ready,
	'partial_rows'     => $partial_rows,
) );

echo json_encode( array(
	'success'            => true,
	'contract'           => 'final_live_completion_gate_contract',
	'completion_ready'   => $completion_ready,
	'artifact_dir'       => $design_logs,
	'artifact_dir_source' => is_string( $artifact_dir_override ) && '' !== trim( $artifact_dir_override ) ? 'env' : 'default',
	'goals_status'       => $goals_status_in_progress ? 'in_progress' : ( $goals_claims_complete ? 'complete_claimed' : 'unknown' ),
	'partial_rows'       => $partial_rows,
	'artifacts'          => array(
		'github'  => array(
			'path'   => $github_path,
			'valid'  => $github_valid,
			'errors' => $github_errors,
		),
		'soak'    => array(
			'path'   => $soak_path,
			'valid'  => $soak_valid,
			'errors' => $soak_errors,
		),
		'summary' => array(
			'path'   => $summary_path,
			'valid'  => $summary_valid,
			'errors' => $summary_errors,
		),
		'command_plan' => array(
			'path'   => $command_plan_path,
			'valid'  => '' !== $command_plan_path && is_file( $command_plan_path ),
			'errors' => '' !== $command_plan_path && is_file( $command_plan_path ) ? array() : array( 'missing_json_artifact' ),
		),
		'ux'      => array(
			'path'   => $ux_path,
			'valid'  => $ux_valid,
			'errors' => $ux_errors,
		),
		'manifest' => array(
			'path'   => $manifest_path,
			'valid'  => $manifest_valid,
			'errors' => $manifest_errors,
		),
		'redaction' => array(
			'path'   => $redaction_report_path,
			'valid'  => $redaction_valid,
			'errors' => $redaction_errors,
		),
	),
	'external_blockers'  => $completion_ready ? array() : array(
		'official_skills_github_repository',
		'multi_hour_live_soak_budget_and_approval',
	),
	'secret_assignments' => false,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
