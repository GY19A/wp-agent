<?php
/**
 * Host-side final external gates evidence contract.
 *
 * Verifies the only remaining acceptance gaps are explicit external live gates:
 * official GitHub Skills Store coordinates and user-approved multi-hour daemon
 * soak inputs. This script only reads local files. It does not call GitHub,
 * Docker, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-external-gates-evidence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final external gates evidence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_external_gates_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_external_gates_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_external_gates_fail( $message, $details );
	}
}

function wp_agent_external_gates_read( $plugin_dir, $rel_path ) {
	$path = $plugin_dir . '/' . $rel_path;
	wp_agent_external_gates_assert( is_file( $path ), 'Required external-gate evidence file is missing.', array(
		'path' => $rel_path,
	) );

	$text = file_get_contents( $path );
	wp_agent_external_gates_assert( is_string( $text ) && '' !== $text, 'Required external-gate evidence file could not be read.', array(
		'path' => $rel_path,
	) );

	return $text;
}

function wp_agent_external_gates_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_external_gates_assert( empty( $missing ), $name . ' is missing required external-gate markers.', array(
		'missing' => $missing,
	) );

	return count( $markers );
}

function wp_agent_external_gates_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_external_gates_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

function wp_agent_external_gates_partial_rows( $goals ) {
	$rows = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $goals ) as $line ) {
		if ( 0 !== strpos( $line, '|' ) ) {
			continue;
		}
		$cells = array_map( 'trim', explode( '|', trim( $line, " \t|" ) ) );
		if ( count( $cells ) >= 3 && ctype_digit( $cells[0] ) && '部分' === $cells[2] ) {
			$rows[ (int) $cells[0] ] = $cells;
		}
	}
	ksort( $rows );
	return $rows;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_external_gates_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$files = array(
	'goals.md',
	'README.md',
	'tests/final-acceptance-preflight.php',
	'tests/live-github-skill-store.php',
	'tests/live-editorial-daemon-soak.php',
	'tests/final-preflight-contract.php',
	'tests/final-runbook-contract.php',
	'tests/final-live-evidence-contract.php',
	'tests/final-live-command-plan-contract.php',
	'tests/final-live-command-plan.php',
	'tests/final-live-report-artifact-contract.php',
	'tests/final-live-artifact-manifest-contract.php',
	'tests/final-live-artifact-manifest-fixture-contract.php',
	'tests/final-live-artifact-manifest-build.php',
	'tests/final-live-artifact-manifest-build-contract.php',
	'tests/final-live-archive-redaction-contract.php',
	'tests/final-live-archive-redaction-fixture-contract.php',
	'tests/final-live-artifact-manifest-template.json',
	'tests/final-live-completion-gate-contract.php',
	'tests/final-live-completion-gate-fixture-contract.php',
	'tests/final-live-report-template.md',
	'tests/official-live-readiness-contract.php',
	'tests/database-persistence-contract.php',
	'tests/official-container-contract.php',
	'tests/live-script-gates-contract.php',
	'tests/final-live-inputs.example.env',
	'tests/final-live-review-packet-template.md',
	'tests/final-live-review-packet-contract.php',
	'tests/final-live-review-packet-status.php',
	'tests/final-live-review-packet-status-contract.php',
	'tests/final-live-input-template-contract.php',
	'tests/final-live-input-template-preflight-contract.php',
	'tests/final-live-user-input-status.php',
	'tests/final-live-user-input-status-contract.php',
	'tests/final-live-reviewed-env-status.php',
	'tests/final-live-reviewed-env-status-contract.php',
	'tests/final-live-blocker-status.php',
	'tests/final-live-blocker-status-contract.php',
	'tests/git-hygiene-contract.php',
	'tests/ui-playwright-evidence-contract.php',
	'tests/chat-background-queue-stop-contract.php',
	'tests/ux-product-usability-contract.php',
	'tests/goals-evidence-contract.php',
	'tests/final-no-live-acceptance-contract.php',
	'docker-compose.official.yml',
	'.gitignore',
);

$texts = array();
foreach ( $files as $file ) {
	$texts[ $file ] = wp_agent_external_gates_read( $plugin_dir, $file );
	wp_agent_external_gates_assert_no_raw_secrets( $file, $texts[ $file ] );
}

$goals = $texts['goals.md'];
$partial_rows = wp_agent_external_gates_partial_rows( $goals );
wp_agent_external_gates_assert( array( 6, 9 ) === array_keys( $partial_rows ), 'Only acceptance rows #6 and #9 may remain partial.', array(
	'partial_rows' => array_keys( $partial_rows ),
) );
wp_agent_external_gates_assert( false !== strpos( $partial_rows[6][3] ?? '', '仍需用户指定官方仓库/ref/path/policy' ), 'Row #6 must explicitly require user-provided official GitHub coordinates.', array(
	'row' => $partial_rows[6] ?? array(),
) );
wp_agent_external_gates_assert( false !== strpos( $partial_rows[9][3] ?? '', '实际多小时 live soak 仍需时间/成本批准' ), 'Row #9 must explicitly require user approval for multi-hour live soak.', array(
	'row' => $partial_rows[9] ?? array(),
) );

$required_markers = 0;

$required_markers += wp_agent_external_gates_require_markers(
	'goals.md',
	$goals,
	array(
		'状态：实施中',
		'官方 Skills GitHub 仓库',
		'长时间 live soak 成本/时间预算',
		'不得把这些步骤伪装成已完成',
		'WP_AGENT_LIVE_GITHUB_REPOSITORY',
		'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
		'WP_AGENT_LIVE_GITHUB_REF',
		'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
		'WP_AGENT_LIVE_GITHUB_TOKEN',
		'不得写入日志或 lockfile',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
		'WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR=1',
		'/path/to/wp-agent/database/official-mysql',
		'owner/repo',
		'skills/example',
		'final-live-artifact-manifest-YYYYMMDD.json',
		'final-live-archive-redaction-YYYYMMDD.md',
		'最终用户输入与审批包',
		'User-approved official Skill Store coordinates',
		'cost_budget_usd',
		'cleanup/rollback policy',
	)
);

$required_markers += wp_agent_external_gates_require_markers(
	'README.md',
	$texts['README.md'],
	array(
		'Optional Live Acceptance',
		'These checks use the configured gateway and may incur API cost',
		'Replace `owner/repo` and `skills/example` with official Skill Store coordinates',
		'WP_AGENT_LIVE_GITHUB_SKILLS=1',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS',
		'docker exec -u www-data -d wp-agent-official-wordpress-1',
		'wp wp-agent daemon stop',
		'php tests/final-live-artifact-manifest-contract.php',
		'php tests/final-live-artifact-manifest-fixture-contract.php',
		'php tests/final-live-archive-redaction-contract.php',
		'php tests/final-live-archive-redaction-fixture-contract.php',
		'final-live-artifact-manifest-YYYYMMDD.json',
		'final-live-archive-redaction-YYYYMMDD.md',
	)
);

$required_markers += wp_agent_external_gates_require_markers(
	'final-acceptance-preflight.php',
	$texts['tests/final-acceptance-preflight.php'],
	array(
		'This script is read-only. It does not call GitHub or the AI gateway.',
		'WP_AGENT_FINAL_PREFLIGHT_STRICT',
		'WP_AGENT_FINAL_PREFLIGHT_SCOPE',
		'github_skill_store',
		'multi_hour_editorial_daemon_soak',
		'WP_AGENT_LIVE_GITHUB_SKILLS',
		'WP_AGENT_LIVE_GITHUB_REPOSITORY',
		'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
		'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY or configured Skills Store review policy',
		'replace placeholder GitHub repository/path with official Skill Store coordinates',
		'token_disclosed',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
		'WPAgent_URL_Safety::validate_public_http_url',
		'resident daemon running in the official WordPress container',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS <= ',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT <= ',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS <= ',
		'WP_AGENT_OFFICIAL_DB_DIR must use official-mysql for final acceptance',
		'WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR',
	)
);

$required_markers += wp_agent_external_gates_require_markers(
	'live GitHub and soak harnesses',
	$texts['tests/live-github-skill-store.php'] . "\n" . $texts['tests/live-editorial-daemon-soak.php'],
	array(
		'Set WP_AGENT_LIVE_GITHUB_SKILLS=1',
		'Replace placeholder GitHub repository/path with official Skill Store coordinates',
		'GitHub token must not be returned in source summary.',
		'Quarantine lock must not persist a GitHub token key.',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak',
		'approved API cost budget',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
		'WPAgent_URL_Safety::validate_public_http_url',
		'usage-row guard',
		'Live editorial daemon memory usage delta exceeded WP_AGENT_LIVE_EDITORIAL_DAEMON_MEMORY_DELTA_MAX',
		'WPAgent_Schedules::set_status( $schedule_id, \'paused\' )',
		'WPAgent_Skills::archive( $requester_id, $skill_slug )',
	)
);

$required_markers += wp_agent_external_gates_require_markers(
	'contract guard scripts',
	$texts['tests/final-preflight-contract.php']
		. "\n" . $texts['tests/final-runbook-contract.php']
		. "\n" . $texts['tests/final-live-evidence-contract.php']
		. "\n" . $texts['tests/final-live-command-plan-contract.php']
		. "\n" . $texts['tests/final-live-command-plan.php']
		. "\n" . $texts['tests/final-live-report-artifact-contract.php']
		. "\n" . $texts['tests/final-live-artifact-manifest-contract.php']
		. "\n" . $texts['tests/final-live-artifact-manifest-fixture-contract.php']
		. "\n" . $texts['tests/final-live-artifact-manifest-build.php']
		. "\n" . $texts['tests/final-live-artifact-manifest-build-contract.php']
		. "\n" . $texts['tests/final-live-archive-redaction-contract.php']
		. "\n" . $texts['tests/final-live-archive-redaction-fixture-contract.php']
		. "\n" . $texts['tests/final-live-completion-gate-contract.php']
		. "\n" . $texts['tests/final-live-completion-gate-fixture-contract.php']
		. "\n" . $texts['tests/final-live-report-template.md']
		. "\n" . $texts['tests/official-live-readiness-contract.php']
		. "\n" . $texts['tests/database-persistence-contract.php']
		. "\n" . $texts['tests/official-container-contract.php']
		. "\n" . $texts['tests/live-script-gates-contract.php']
		. "\n" . $texts['tests/final-live-inputs.example.env']
		. "\n" . $texts['tests/final-live-review-packet-template.md']
		. "\n" . $texts['tests/final-live-review-packet-contract.php']
		. "\n" . $texts['tests/final-live-input-template-contract.php']
		. "\n" . $texts['tests/final-live-input-template-preflight-contract.php']
		. "\n" . $texts['tests/git-hygiene-contract.php']
		. "\n" . $texts['tests/goals-evidence-contract.php']
		. "\n" . $texts['tests/final-no-live-acceptance-contract.php']
		. "\n" . $texts['docker-compose.official.yml']
		. "\n" . $texts['.gitignore'],
	array(
		'github_placeholder_strict_failure',
		'github_env_token_redaction',
		'soak_private_source_url_strict_failure',
		'soak_upper_bound_strict_failure',
		'soak_nondefault_db_strict_failure',
		'secret_assignments',
		'final_live_evidence_contract',
		'GitHub repository should remain an explicit external input before live acceptance.',
		'Default report should remain not-ready until live inputs are supplied.',
		'Only acceptance rows #6 and #9 should remain partial.',
		'official_skills_github_repository',
		'multi_hour_live_soak_budget_and_approval',
		'final_preflight_contract',
		'final_runbook_contract',
		'strict_preflight_markers',
		'final_live_evidence_contract',
		'final_live_command_plan_contract',
		'commands_executable',
		'archive_targets',
		'final-live-archive-redaction-YYYYMMDD.md',
		'ux_evidence_validation',
		'ux_validation_before_manifest',
		'acceptance_summary',
		'summary_before_manifest',
		'php tests/ui-playwright-evidence-contract.php',
		'invalid_reviewed_inputs_rejected',
		'source_url_rejected',
		'official_db_rejected',
		'cost_budget_rejected',
		'artifact_policy_rejected',
		'soak_bounds_rejected',
		'final_live_report_artifact_contract',
		'report_ux_order_recorded',
		'final_live_artifact_manifest_contract',
		'final_live_artifact_manifest_fixture_contract',
		'valid_manifest_ready',
		'invalid_manifest_ready',
		'final_live_artifact_manifest_build',
		'final_live_artifact_manifest_build_contract',
		'generated_manifest_valid',
		'manifest_ux_order_recorded',
		'approval_confirmation_rejected',
		'missing_artifact_rejected',
		'missing_ux_evidence_rejected',
		'token_disclosure_rejected',
		'final_live_archive_redaction_contract',
		'final_live_archive_redaction_fixture_contract',
		'valid_redaction_ready',
		'invalid_redaction_ready',
		'ui-playwright-evidence-contract-*.md',
		'Valid redaction fixture should scan all final live and UX archive files.',
		'final_live_completion_gate_contract',
		'final_live_completion_gate_fixture_contract',
		'valid_completion_ready',
		'invalid_completion_ready',
		'invalid_summary_errors',
		'summary_missing_marker:completion_ready=true',
		'ux_evidence',
		'ui-playwright-evidence-contract-YYYYMMDD.md',
		'ux_evidence_missing',
		'manifest_missing_artifact:ux_evidence',
		'redaction_missing_artifact:ux',
		'ui_playwright_evidence_contract',
		'chat_background_queue_stop',
		'ux_quality_gate',
		'chat_stop_playwright',
		'chat_queue_status_playwright',
		'chat_stop_availability_playwright',
		'composer_unlocked_guard',
		'overflow_guard',
		'console_guard',
		'input_not_poll_locked',
		'daemon_wake_on_send',
		'cancel_route',
		'invalid_manifest_errors',
		'invalid_redaction_errors',
		'manifest_artifact_hash_mismatch:github_skill_store',
		'redaction_token_disclosed_true:github',
		'completion_ready',
		'final_live_input_template_contract',
		'final_live_input_template_preflight_contract',
		'final_live_review_packet_contract',
		'final-live-review-packet-template.md',
		'review_packet_template',
		'final-live-inputs.example.env',
		'keys_checked',
		'WP_AGENT_FINAL_PREFLIGHT_SCOPE=all',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=replace-after-review',
		'Strict final preflight JSON should report ready=false for placeholder inputs.',
		'final-live-github-skill-store-YYYYMMDD.json',
		'final-live-editorial-daemon-soak-YYYYMMDD.json',
		'final-live-command-plan-YYYYMMDD.json',
		'official_live_readiness_contract',
		'official_live_readiness',
		'api_key_state',
		'decryptable',
		'image_model',
		'base_url_host',
		'official_db_dir_is_default',
		'Official runtime root must not live inside the plugin directory.',
		'database_persistence_contract',
		'plugin_database_dir',
		'/path/to/wp-agent/database/mysql',
		'/path/to/wp-agent/database/official-mysql',
		'/path/to/wp-agent/database/dumps',
		'*.sql.gz',
		'database/',
		'不得提交或公开数据库目录',
		'official-container-contract.php',
		'wordpress:php8.3-apache',
		'wordpress:cli-php8.3',
		'mysql:8.0',
		'agentd_sidecar',
		'privileged_services',
		'must not use custom builds',
		'live-script-gates-contract.php',
		'WP_AGENT_LIVE_GITHUB_SKILLS',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON',
		'checked_count',
		'skipped',
		'live_network',
		'ai_gateway',
		'github_request',
		'live flag gate must appear before credential',
		'git_hygiene_contract',
		'remote_push_disabled',
		'remote_credentials',
		'remote_push',
		'Current HEAD should remain local and not be contained in upstream.',
	)
);

$all_text = implode( "\n", $texts );
$coverage = array(
	'partial_rows_guard'       => array( 6, 9 ) === array_keys( $partial_rows ),
	'github_external_inputs'   => false !== strpos( $all_text, 'WP_AGENT_LIVE_GITHUB_REPOSITORY' ) && false !== strpos( $all_text, 'official Skill Store coordinates' ),
	'placeholder_fail_closed'  => false !== strpos( $all_text, 'replace placeholder GitHub repository/path with official Skill Store coordinates' ),
	'github_token_redaction'   => false !== strpos( $all_text, 'token_disclosed' ) && false !== strpos( $all_text, 'GitHub token must not be returned in source summary.'),
	'soak_approval_gate'       => false !== strpos( $all_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1' ) && false !== strpos( $all_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak' ) && false !== strpos( $all_text, 'approved API cost budget' ),
	'soak_cost_budget_gate'    => false !== strpos( $all_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' ) && false !== strpos( $all_text, 'cost_budget_usd' ),
	'artifact_policy_gate'     => false !== strpos( $all_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' ) && false !== strpos( $all_text, 'drafts_journal_usage' ),
	'public_source_url_gate'   => false !== strpos( $all_text, 'WPAgent_URL_Safety::validate_public_http_url' ) && false !== strpos( $all_text, 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL' ),
	'soak_upper_bounds'        => false !== strpos( $all_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS <= ' ) && false !== strpos( $all_text, '28800' ),
	'official_db_guard'        => false !== strpos( $all_text, 'WP_AGENT_OFFICIAL_DB_DIR must use official-mysql for final acceptance' ) && false !== strpos( $all_text, '/path/to/wp-agent/database/official-mysql' ),
	'live_evidence_ready'      => false !== strpos( $all_text, 'final_live_evidence_contract' ) && false !== strpos( $all_text, 'harnesses_checked' ),
	'command_plan_dry_run'     => false !== strpos( $all_text, 'final_live_command_plan_contract' ) && false !== strpos( $all_text, 'commands_executable' ) && false !== strpos( $all_text, 'ready_for_live_execution' ) && false !== strpos( $all_text, 'placeholder_rejected' ) && false !== strpos( $all_text, 'review_packet_status' ) && false !== strpos( $all_text, 'review_packet_ready' ) && false !== strpos( $all_text, 'review_packet_env_consistent' ) && false !== strpos( $all_text, 'review_packet_env_mismatches' ) && false !== strpos( $all_text, 'review_packet_before_live' ) && false !== strpos( $all_text, 'ux_evidence_validation' ) && false !== strpos( $all_text, 'ux_validation_before_manifest' ) && false !== strpos( $all_text, 'acceptance_summary' ) && false !== strpos( $all_text, 'summary_before_manifest' ) && false !== strpos( $all_text, 'php tests/ui-playwright-evidence-contract.php' ) && false !== strpos( $all_text, 'tee /path/to/wp-agent/design/test-logs/final-live-command-plan-$(date +%Y%m%d).json' ) && false !== strpos( $all_text, 'tee /path/to/wp-agent/design/test-logs/final-live-github-skill-store-$(date +%Y%m%d).json' ) && false !== strpos( $all_text, 'tee /path/to/wp-agent/design/test-logs/final-live-editorial-daemon-soak-$(date +%Y%m%d).json' ) && false !== strpos( $all_text, 'tee /path/to/wp-agent/design/test-logs/ui-playwright-evidence-contract-$(date +%Y%m%d).md' ) && false !== strpos( $all_text, 'tee /path/to/wp-agent/design/test-logs/final-live-archive-redaction-$(date +%Y%m%d).md' ),
	'command_plan_input_guards' => false !== strpos( $all_text, 'invalid_reviewed_inputs_rejected' ) && false !== strpos( $all_text, 'source_url_rejected' ) && false !== strpos( $all_text, 'official_db_rejected' ) && false !== strpos( $all_text, 'cost_budget_rejected' ) && false !== strpos( $all_text, 'artifact_policy_rejected' ) && false !== strpos( $all_text, 'soak_bounds_rejected' ) && false !== strpos( $all_text, 'valid_review_packet_env_consistent' ) && false !== strpos( $all_text, 'mismatched_packet_env_rejected' ) && false !== strpos( $all_text, 'archive_targets' ) && false !== strpos( $all_text, 'ui-playwright-evidence-contract-YYYYMMDD.md' ) && false !== strpos( $all_text, 'final-live-archive-redaction-YYYYMMDD.md' ),
	'strict_preflight_runbook_guard' => false !== strpos( $all_text, 'final_runbook_contract' ) && false !== strpos( $all_text, 'strict_preflight_markers' ) && false !== strpos( $all_text, 'strict_preflight_env_flags_checked' ) && false !== strpos( $all_text, 'duplicate_env_flags' ) && false !== strpos( $all_text, 'regression_commands_checked' ) && false !== strpos( $all_text, 'duplicate_regression_commands' ) && false !== strpos( $all_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL' ) && false !== strpos( $all_text, 'WP_AGENT_OFFICIAL_DB_DIR' ),
	'live_report_artifacts'    => false !== strpos( $all_text, 'final_live_report_artifact_contract' ) && false !== strpos( $all_text, 'final-live-command-plan-YYYYMMDD.json' ) && false !== strpos( $all_text, 'final-live-github-skill-store-YYYYMMDD.json' ) && false !== strpos( $all_text, 'final-live-editorial-daemon-soak-YYYYMMDD.json' ) && false !== strpos( $all_text, 'final-live-archive-redaction-YYYYMMDD.md' ) && false !== strpos( $all_text, 'report_ux_order_recorded' ) && false !== strpos( $all_text, 'report_summary_order_recorded' ),
	'artifact_manifest_guard'  => false !== strpos( $all_text, 'final_live_artifact_manifest_contract' ) && false !== strpos( $all_text, 'final-live-artifact-manifest-YYYYMMDD.json' ) && false !== strpos( $all_text, 'final-live-command-plan-YYYYMMDD.json' ) && false !== strpos( $all_text, 'final-live-archive-redaction-YYYYMMDD.md' ) && false !== strpos( $all_text, 'sha256' ),
	'artifact_manifest_fixture_guard' => false !== strpos( $all_text, 'final_live_artifact_manifest_fixture_contract' ) && false !== strpos( $all_text, 'valid_manifest_ready' ) && false !== strpos( $all_text, 'invalid_manifest_ready' ) && false !== strpos( $all_text, 'invalid_review_packet_source_rejected' ) && false !== strpos( $all_text, 'invalid_command_plan_ready_rejected' ),
	'artifact_manifest_build_guard' => false !== strpos( $all_text, 'final_live_artifact_manifest_build_contract' ) && false !== strpos( $all_text, 'generated_manifest_valid' ) && false !== strpos( $all_text, 'command_plan_artifact_recorded' ) && false !== strpos( $all_text, 'manifest_ux_order_recorded' ) && false !== strpos( $all_text, 'manifest_summary_order_recorded' ) && false !== strpos( $all_text, 'summary_order_guard' ) && false !== strpos( $all_text, 'approval_confirmation_rejected' ) && false !== strpos( $all_text, 'missing_artifact_rejected' ) && false !== strpos( $all_text, 'missing_ux_evidence_rejected' ) && false !== strpos( $all_text, 'mismatched_packet_env_rejected' ) && false !== strpos( $all_text, 'ux_validation_before_manifest' ) && false !== strpos( $all_text, 'summary_before_manifest' ) && false !== strpos( $all_text, 'token_disclosure_rejected' ),
	'archive_redaction_guard'  => false !== strpos( $all_text, 'final_live_archive_redaction_contract' ) && false !== strpos( $all_text, 'ui-playwright-evidence-contract-*.md' ) && false !== strpos( $all_text, 'final-live-archive-redaction-[0-9]*.md' ) && false !== strpos( $all_text, 'raw_secret_hits' ) && false !== strpos( $all_text, 'token_disclosed=false' ),
	'archive_redaction_fixture_guard' => false !== strpos( $all_text, 'final_live_archive_redaction_fixture_contract' ) && false !== strpos( $all_text, 'valid_redaction_ready' ) && false !== strpos( $all_text, 'invalid_redaction_ready' ) && false !== strpos( $all_text, 'Valid redaction fixture should scan all final live and UX archive files.' ),
	'completion_gate_guard'    => false !== strpos( $all_text, 'final_live_completion_gate_contract' ) && false !== strpos( $all_text, 'completion_ready' ) && false !== strpos( $all_text, 'manifest_valid' ) && false !== strpos( $all_text, 'redaction_valid' ) && false !== strpos( $all_text, 'goals.md claims completion without valid final live artifacts' ),
	'completion_fixture_guard'  => false !== strpos( $all_text, 'final_live_completion_gate_fixture_contract' ) && false !== strpos( $all_text, 'valid_completion_ready' ) && false !== strpos( $all_text, 'invalid_completion_ready' ) && false !== strpos( $all_text, 'invalid_summary_errors' ) && false !== strpos( $all_text, 'summary_missing_marker:completion_ready=true' ) && false !== strpos( $all_text, 'summary_missing_marker:packet_ready=true' ) && false !== strpos( $all_text, 'summary_missing_marker:ready_for_live_execution=true' ) && false !== strpos( $all_text, 'summary_missing_marker:review_packet_ready=true' ) && false !== strpos( $all_text, 'summary_missing_marker:review_packet_env_consistent=true' ) && false !== strpos( $all_text, 'summary_missing_marker:chat_stop_availability_playwright=true' ) && false !== strpos( $all_text, 'summary_missing_marker:composer_unlocked_guard=true' ) && false !== strpos( $all_text, 'invalid_manifest_errors' ) && false !== strpos( $all_text, 'manifest_review_packet_source_missing' ) && false !== strpos( $all_text, 'manifest_command_plan_not_ready_for_live_execution' ) && false !== strpos( $all_text, 'manifest_review_packet_env_not_consistent' ) && false !== strpos( $all_text, 'invalid_redaction_errors' ),
	'final_completion_ux_guard' => false !== strpos( $all_text, 'ux_evidence' ) && false !== strpos( $all_text, 'ui-playwright-evidence-contract-YYYYMMDD.md' ) && false !== strpos( $all_text, 'ux_evidence_missing' ) && false !== strpos( $all_text, 'manifest_missing_artifact:ux_evidence' ) && false !== strpos( $all_text, 'redaction_missing_artifact:ux' ),
	'live_input_template_guard' => false !== strpos( $all_text, 'final_live_input_template_contract' ) && false !== strpos( $all_text, 'final-live-inputs.example.env' ) && false !== strpos( $all_text, 'keys_checked' ) && false !== strpos( $all_text, 'WP_AGENT_FINAL_PREFLIGHT_SCOPE=all' ) && false !== strpos( $all_text, 'WP_AGENT_OFFICIAL_DB_DIR=/path/to/wp-agent/database/official-mysql' ),
	'live_input_template_preflight_guard' => false !== strpos( $all_text, 'final_live_input_template_preflight_contract' ) && false !== strpos( $all_text, 'Strict final preflight JSON should report ready=false for placeholder inputs.' ) && false !== strpos( $all_text, 'placeholder_rejected' ) && false !== strpos( $all_text, 'token_disclosed' ),
	'user_input_status_gate' => false !== strpos( $all_text, 'final_live_user_input_status' ) && false !== strpos( $all_text, 'user_input_ready' ) && false !== strpos( $all_text, 'github_inputs_ready' ) && false !== strpos( $all_text, 'soak_inputs_ready' ) && false !== strpos( $all_text, 'pending_user_inputs' ) && false !== strpos( $all_text, 'pending_review_items' ) && false !== strpos( $all_text, 'valid_user_input_ready' ) && false !== strpos( $all_text, 'invalid_source_rejected' ),
	'reviewed_env_status_gate' => false !== strpos( $all_text, 'final_live_reviewed_env_status' ) && false !== strpos( $all_text, 'reviewed_env_ready' ) && false !== strpos( $all_text, 'path_ignored_by_git' ) && false !== strpos( $all_text, 'path_tracked_by_git' ) && false !== strpos( $all_text, 'valid_reviewed_fixture_ready' ) && false !== strpos( $all_text, 'outside_path_rejected' ) && false !== strpos( $all_text, 'secret_assignment_rejected' ),
		'blocker_status_next_actions_gate' => false !== strpos( $all_text, 'final_live_blocker_status' ) && false !== strpos( $all_text, 'next_actions' ) && false !== strpos( $all_text, 'operator_init_commands' ) && false !== strpos( $all_text, 'operator_secret_rule' ) && false !== strpos( $all_text, 'git check-ignore -q final-live-inputs.' ) && false !== strpos( $all_text, 'git check-ignore -q final-live-review-packet-' ) && false !== strpos( $all_text, 'php tests/final-live-command-plan.php path/to/reviewed.env path/to/final-live-review-packet-YYYYMMDD.md' ) && false !== strpos( $all_text, 'review_packet_env_consistent=true' ) && false !== strpos( $all_text, 'ready_for_live_execution=true' ) && false !== strpos( $all_text, 'final-live-acceptance-summary-YYYYMMDD.md' ) && false !== strpos( $all_text, 'summary_required_markers' ) && false !== strpos( $all_text, 'default_next_actions_command_plan' ) && false !== strpos( $all_text, 'default_operator_init_commands' ) && false !== strpos( $all_text, 'default_next_actions_summary_markers' ),
	'official_live_readiness_guard' => false !== strpos( $all_text, 'official_live_readiness_contract' ) && false !== strpos( $all_text, 'official_live_readiness' ) && false !== strpos( $all_text, 'api_key_state' ) && false !== strpos( $all_text, 'decryptable' ) && false !== strpos( $all_text, 'image_model' ) && false !== strpos( $all_text, 'base_url_host' ) && false !== strpos( $all_text, 'official_db_dir_is_default' ) && false !== strpos( $all_text, 'Official runtime root must not live inside the plugin directory.' ),
	'database_persistence_guard' => false !== strpos( $all_text, 'database_persistence_contract' ) && false !== strpos( $all_text, 'plugin_database_dir' ) && false !== strpos( $all_text, '/path/to/wp-agent/database/mysql' ) && false !== strpos( $all_text, '/path/to/wp-agent/database/official-mysql' ) && false !== strpos( $all_text, '/path/to/wp-agent/database/dumps' ) && false !== strpos( $all_text, '*.sql.gz' ) && false !== strpos( $all_text, '不得提交或公开数据库目录' ),
	'official_container_guard' => false !== strpos( $all_text, 'official-container-contract.php' ) && false !== strpos( $all_text, 'wordpress:php8.3-apache' ) && false !== strpos( $all_text, 'wordpress:cli-php8.3' ) && false !== strpos( $all_text, 'mysql:8.0' ) && false !== strpos( $all_text, 'agentd_sidecar' ) && false !== strpos( $all_text, 'privileged_services' ) && false !== strpos( $all_text, 'must not use custom builds' ),
	'live_script_gates_guard' => false !== strpos( $all_text, 'live-script-gates-contract.php' ) && false !== strpos( $all_text, 'WP_AGENT_LIVE_GITHUB_SKILLS' ) && false !== strpos( $all_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON' ) && false !== strpos( $all_text, 'checked_count' ) && false !== strpos( $all_text, 'skipped' ) && false !== strpos( $all_text, 'live_network' ) && false !== strpos( $all_text, 'ai_gateway' ) && false !== strpos( $all_text, 'github_request' ) && false !== strpos( $all_text, 'live flag gate must appear before credential' ),
	'git_hygiene_guard'       => false !== strpos( $all_text, 'git_hygiene_contract' ) && false !== strpos( $all_text, 'remote_push_disabled' ) && false !== strpos( $all_text, 'remote_credentials' ) && false !== strpos( $all_text, 'remote_push' ) && false !== strpos( $all_text, 'Current HEAD should remain local and not be contained in upstream.' ),
	'ux_playwright_gate'        => false !== strpos( $all_text, 'ui_playwright_evidence_contract' ) && false !== strpos( $all_text, 'ux_quality_gate' ) && false !== strpos( $all_text, 'chat_stop_playwright' ) && false !== strpos( $all_text, 'chat_queue_status_playwright' ) && false !== strpos( $all_text, 'chat_stop_availability_playwright' ) && false !== strpos( $all_text, 'desktop_mobile_pairs' ),
	'ux_operability_gate'       => false !== strpos( $all_text, 'composer_unlocked_guard' ) && false !== strpos( $all_text, 'overflow_guard' ) && false !== strpos( $all_text, 'console_guard' ) && false !== strpos( $all_text, 'negative_letter_spacing' ),
		'chat_stop_queue_gate'      => false !== strpos( $all_text, 'chat_background_queue_stop' ) && false !== strpos( $all_text, 'input_not_poll_locked' ) && false !== strpos( $all_text, 'stop_visible_label' ) && false !== strpos( $all_text, 'daemon_wake_on_send' ) && false !== strpos( $all_text, 'cancel_route' ) && false !== strpos( $all_text, 'queue_summary_rest' ) && false !== strpos( $all_text, 'queue_position_status' ) && false !== strpos( $all_text, 'queue_summary_preserved' ) && false !== strpos( $all_text, 'js_stop_availability' ) && false !== strpos( $all_text, 'js_status_clarity' ),
	'ux_product_usability_gate' => false !== strpos( $all_text, 'ux_product_usability_contract' ) && false !== strpos( $all_text, 'status_transparency' ) && false !== strpos( $all_text, 'interruptibility' ) && false !== strpos( $all_text, 'queue_continuity' ) && false !== strpos( $all_text, 'recovery_copy' ) && false !== strpos( $all_text, 'accessibility_affordance' ) && false !== strpos( $all_text, 'responsive_overflow_guard' ),
	'ux_nonnegotiable_gate'    => false !== strpos( $all_text, '用户体验一定要好' ) && false !== strpos( $all_text, '体验不得妥协' ) && false !== strpos( $all_text, '用户不需要猜测' ) && false !== strpos( $all_text, '无后台任务黑盒等待' ) && false !== strpos( $all_text, 'Playwright 桌面/移动证据' ) && false !== strpos( $all_text, '用户体验不是装饰性要求' ) && false !== strpos( $all_text, '功能完整不能抵消体验失败' ),
	'final_review_packet_guard' => false !== strpos( $all_text, '最终用户输入与审批包' ) && false !== strpos( $all_text, '当前仍需用户提供的最小输入包' ) && false !== strpos( $all_text, 'final_live_review_packet_contract' ) && false !== strpos( $all_text, 'final-live-review-packet-template.md' ) && false !== strpos( $all_text, 'review_packet_template' ) && false !== strpos( $all_text, 'gitignore_review_inputs' ) && false !== strpos( $all_text, '/final-live-review-packet-*.md' ) && false !== strpos( $all_text, '!tests/final-live-review-packet-template.md' ) && false !== strpos( $all_text, 'tests/final-live-inputs.*.env' ) && false !== strpos( $all_text, '!tests/final-live-inputs.example.env' ) && false !== strpos( $all_text, 'User-approved official Skill Store coordinates' ) && false !== strpos( $all_text, '只能通过 shell、WordPress Settings 或 ignored env 注入' ) && false !== strpos( $all_text, 'commands_executable=true' ) && false !== strpos( $all_text, '缺少任一 artifact 时 `completion_ready` 必须保持 `false`' ) && false !== strpos( $all_text, 'source URL 公网 HTTP(S)' ) && false !== strpos( $all_text, 'cleanup/rollback policy' ) && false !== strpos( $all_text, '执行顺序必须是：更新 reviewed inputs' ),
	'review_packet_status_gate' => false !== strpos( $all_text, 'final_live_review_packet_status' ) && false !== strpos( $all_text, 'packet_ready' ) && false !== strpos( $all_text, 'valid_packet_ready' ) && false !== strpos( $all_text, 'path_is_template' ) && false !== strpos( $all_text, 'path_ignored_by_git' ) && false !== strpos( $all_text, 'missing_fields' ) && false !== strpos( $all_text, 'invalid_redaction_report_rejected' ) && false !== strpos( $all_text, 'invalid_source_rejected' ) && false !== strpos( $all_text, 'secret_assignment_rejected' ),
	'no_live_aggregate_ready'  => false !== strpos( $all_text, 'final_no_live_acceptance' ) && false !== strpos( $all_text, 'live_network_calls' ),
);

foreach ( $coverage as $name => $covered ) {
	wp_agent_external_gates_assert( true === $covered, 'Final external gate coverage is incomplete.', array(
		'coverage' => $name,
	) );
}

echo json_encode( array(
	'success'            => true,
	'contract'           => 'final_external_gates_evidence_contract',
	'files_checked'      => count( $files ),
	'required_markers'   => $required_markers,
	'partial_rows'       => array_keys( $partial_rows ),
	'external_blockers'  => array(
		'official_skills_github_repository',
		'multi_hour_live_soak_budget_and_approval',
	),
	'coverage'           => $coverage,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
