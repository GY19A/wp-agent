<?php
/**
 * Host-side goals evidence contract.
 *
 * Verifies goals.md remains an auditable source of truth: referenced tests and
 * design evidence must exist, and only the externally blocked acceptance rows
 * may remain partial. This script does not call GitHub or the AI gateway.
 *
 * Run from the host:
 * php tests/goals-evidence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This goals evidence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_goals_evidence_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_goals_evidence_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_goals_evidence_fail( $message, $details );
	}
}

function wp_agent_goals_evidence_unique_matches( $pattern, $text ) {
	$matches = array();
	preg_match_all( $pattern, $text, $matches );
	$values = array_values( array_unique( $matches[0] ?? array() ) );
	sort( $values );
	return $values;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_goals_evidence_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$goals_path = $plugin_dir . '/goals.md';
wp_agent_goals_evidence_assert( is_file( $goals_path ), 'goals.md is missing.' );

$goals = file_get_contents( $goals_path );
wp_agent_goals_evidence_assert( is_string( $goals ) && '' !== $goals, 'goals.md could not be read.' );

wp_agent_goals_evidence_assert( false !== strpos( $goals, '状态：实施中' ), 'goals.md should remain explicitly in-progress until external live gates are complete.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '官方 Skills GitHub 仓库' ), 'goals.md should retain the official Skills GitHub repository blocker.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '长时间 live soak 成本/时间预算' ), 'goals.md should retain the multi-hour live soak approval blocker.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '不得把这些步骤伪装成已完成' ), 'goals.md should explicitly forbid pretending external live gates are complete.' );

$partial_rows = array();
$lines        = preg_split( '/\r\n|\r|\n/', $goals );
foreach ( $lines as $line ) {
	if ( 0 !== strpos( $line, '|' ) ) {
		continue;
	}
	$cells = array_map( 'trim', explode( '|', trim( $line, " \t|" ) ) );
	if ( count( $cells ) >= 3 && ctype_digit( $cells[0] ) && '部分' === $cells[2] ) {
		$partial_rows[] = (int) $cells[0];
	}
}
sort( $partial_rows );
wp_agent_goals_evidence_assert( array( 6, 9 ) === $partial_rows, 'Only acceptance rows #6 and #9 should remain partial.', array(
	'partial_rows' => $partial_rows,
) );

$required_scripts = array(
	'tests/final-no-live-acceptance-contract.php',
	'tests/git-hygiene-contract.php',
	'tests/database-persistence-contract.php',
	'tests/official-live-readiness-contract.php',
	'tests/final-preflight-contract.php',
	'tests/final-runbook-contract.php',
	'tests/final-live-evidence-contract.php',
	'tests/final-live-command-plan-contract.php',
	'tests/final-live-command-plan.php',
	'tests/final-live-report-artifact-contract.php',
	'tests/final-live-artifact-manifest-build.php',
	'tests/final-live-artifact-manifest-build-contract.php',
	'tests/final-live-artifact-manifest-contract.php',
	'tests/final-live-artifact-manifest-fixture-contract.php',
	'tests/final-live-archive-redaction-contract.php',
	'tests/final-live-archive-redaction-fixture-contract.php',
	'tests/final-live-completion-gate-contract.php',
	'tests/final-live-completion-gate-fixture-contract.php',
	'tests/final-external-gates-evidence-contract.php',
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
	'tests/live-script-gates-contract.php',
	'tests/official-container-contract.php',
	'tests/source-research-inventory.php',
	'tests/source-research-notes-contract.php',
	'tests/ui-playwright-evidence-contract.php',
	'tests/chat-background-queue-stop-contract.php',
	'tests/ux-product-usability-contract.php',
	'tests/chat-ux-reliability.php',
	'tests/plugin-independence-contract.php',
	'tests/security-boundary-evidence-contract.php',
	'tests/daemon-lifecycle-evidence-contract.php',
	'tests/skills-store-lifecycle-evidence-contract.php',
	'tests/test-isolation-audit.php',
);

$script_refs = wp_agent_goals_evidence_unique_matches( '/tests\/[A-Za-z0-9._-]+\.php/', $goals );
foreach ( $required_scripts as $required_script ) {
	wp_agent_goals_evidence_assert( in_array( $required_script, $script_refs, true ), 'goals.md should reference the required acceptance script.', array(
		'required_script' => $required_script,
	) );
}

$missing_scripts = array();
foreach ( $script_refs as $script_ref ) {
	if ( ! is_file( $plugin_dir . '/' . $script_ref ) ) {
		$missing_scripts[] = $script_ref;
	}
}
wp_agent_goals_evidence_assert( empty( $missing_scripts ), 'goals.md references missing test scripts.', array(
	'missing_scripts' => $missing_scripts,
) );

$design_refs = wp_agent_goals_evidence_unique_matches( '#/path/to/wp-agent/design/[^\s`，。)、）]+#u', $goals );
$missing_design_refs = array();
foreach ( $design_refs as $design_ref ) {
	if ( ! file_exists( $design_ref ) ) {
		$missing_design_refs[] = $design_ref;
	}
}
wp_agent_goals_evidence_assert( empty( $missing_design_refs ), 'goals.md references missing design evidence paths.', array(
	'missing_design_refs' => $missing_design_refs,
) );

$required_design_refs = array(
	'/path/to/wp-agent/design/test-logs/final-no-live-acceptance-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/official-live-readiness-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/live-script-gates-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-preflight-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/source-research-inventory-20260622.md',
	'/path/to/wp-agent/design/test-logs/skills-store-lifecycle-evidence-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-external-gates-evidence-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-input-template-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-input-template-preflight-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-report-artifact-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-artifact-manifest-build-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-artifact-manifest-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-artifact-manifest-fixture-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-archive-redaction-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-archive-redaction-fixture-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-completion-gate-manifest-redaction-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-completion-gate-fixture-manifest-redaction-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-completion-gate-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-completion-gate-fixture-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/final-live-command-plan-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/chat-background-queue-stop-contract-20260622.md',
	'/path/to/wp-agent/design/test-logs/chat-ux-reliability-20260622.md',
	'/path/to/wp-agent/design/test-logs/chat-stop-playwright-20260622.md',
	'/path/to/wp-agent/design/iterations/0146-chat-background-queue-stop.md',
	'/path/to/wp-agent/design/test-logs/ux-quality-gate-contract-20260622.md',
	'/path/to/wp-agent/design/iterations/0148-ux-quality-gate.md',
	'/path/to/wp-agent/design/test-logs/ux-product-quality-principle-20260622.md',
	'/path/to/wp-agent/design/iterations/0182-ux-nonnegotiable-user-principle.md',
	'/path/to/wp-agent/design/test-logs/ux-product-usability-contract-20260622.md',
	'/path/to/wp-agent/design/iterations/0187-ux-product-usability-contract.md',
	'/path/to/wp-agent/design/test-logs/ux-evidence-contract-hardening-20260622.md',
	'/path/to/wp-agent/design/iterations/0154-ux-evidence-contract-hardening.md',
	'/path/to/wp-agent/design/test-logs/final-command-plan-input-validation-20260622.md',
	'/path/to/wp-agent/design/iterations/0155-final-command-plan-input-validation.md',
	'/path/to/wp-agent/design/test-logs/strict-preflight-runbook-command-block-20260622.md',
	'/path/to/wp-agent/design/iterations/0156-strict-preflight-runbook-command-block.md',
	'/path/to/wp-agent/design/test-logs/final-live-review-packet-20260622.md',
	'/path/to/wp-agent/design/iterations/0179-final-live-review-packet.md',
	'/path/to/wp-agent/design/test-logs/final-live-review-packet-template-contract-20260622.md',
	'/path/to/wp-agent/design/iterations/0180-final-live-review-packet-template.md',
	'/path/to/wp-agent/design/test-logs/final-live-review-packet-gitignore-20260622.md',
	'/path/to/wp-agent/design/iterations/0181-final-live-review-packet-gitignore.md',
	'/path/to/wp-agent/design/test-logs/final-live-review-packet-status-20260622.md',
	'/path/to/wp-agent/design/iterations/0193-final-live-review-packet-status.md',
	'/path/to/wp-agent/design/test-logs/final-live-review-packet-status-contract-20260622.md',
	'/path/to/wp-agent/design/iterations/0194-final-live-review-packet-status-contract.md',
	'/path/to/wp-agent/design/test-logs/final-live-blocker-status-review-packet-20260622.md',
	'/path/to/wp-agent/design/iterations/0195-final-live-blocker-status-review-packet.md',
	'/path/to/wp-agent/design/test-logs/final-command-plan-review-packet-status-20260622.md',
	'/path/to/wp-agent/design/iterations/0196-final-command-plan-review-packet-status.md',
	'/path/to/wp-agent/design/test-logs/final-blocker-command-plan-review-packet-20260622.md',
	'/path/to/wp-agent/design/iterations/0197-final-blocker-command-plan-review-packet.md',
	'/path/to/wp-agent/design/test-logs/final-blocker-operator-next-actions-20260622.md',
	'/path/to/wp-agent/design/iterations/0198-final-blocker-operator-next-actions.md',
	'/path/to/wp-agent/design/test-logs/final-user-input-minimum-packet-20260622.md',
	'/path/to/wp-agent/design/iterations/0183-final-user-input-minimum-packet.md',
	'/path/to/wp-agent/design/test-logs/final-live-blocker-status-20260622.md',
	'/path/to/wp-agent/design/iterations/0184-final-live-blocker-status.md',
	'/path/to/wp-agent/design/test-logs/final-live-blocker-status-contract-20260622.md',
	'/path/to/wp-agent/design/iterations/0185-final-live-blocker-status-contract.md',
	'/path/to/wp-agent/design/test-logs/final-live-blocker-status-git-hygiene-20260622.md',
	'/path/to/wp-agent/design/iterations/0186-final-live-blocker-status-git-hygiene.md',
	'/path/to/wp-agent/design/test-logs/final-live-user-input-status-20260622.md',
	'/path/to/wp-agent/design/iterations/0190-final-live-user-input-status.md',
	'/path/to/wp-agent/design/test-logs/final-live-user-input-status-contract-20260622.md',
	'/path/to/wp-agent/design/iterations/0191-final-live-user-input-status-contract.md',
	'/path/to/wp-agent/design/test-logs/final-live-blocker-status-user-input-20260622.md',
	'/path/to/wp-agent/design/iterations/0192-final-live-blocker-status-user-input.md',
	'/path/to/wp-agent/design/test-logs/final-live-reviewed-env-status-20260622.md',
	'/path/to/wp-agent/design/iterations/0188-final-live-reviewed-env-status.md',
	'/path/to/wp-agent/design/test-logs/final-live-blocker-status-reviewed-env-20260622.md',
	'/path/to/wp-agent/design/iterations/0189-final-live-blocker-status-reviewed-env.md',
	'/path/to/wp-agent/design/test-logs/live-soak-approval-phrase-gate-20260622.md',
	'/path/to/wp-agent/design/iterations/0150-live-soak-approval-phrase-gate.md',
	'/path/to/wp-agent/design/test-logs/live-soak-approval-confirmation-artifact-20260622.md',
	'/path/to/wp-agent/design/iterations/0151-live-soak-approval-confirmation-artifact.md',
	'/path/to/wp-agent/design/test-logs/live-artifact-manifest-approval-confirmation-gate-20260622.md',
	'/path/to/wp-agent/design/iterations/0152-live-artifact-manifest-approval-confirmation-gate.md',
	'/path/to/wp-agent/design/test-logs/completion-gate-manifest-approval-confirmation-20260622.md',
	'/path/to/wp-agent/design/iterations/0153-completion-gate-manifest-approval-confirmation.md',
	'/path/to/wp-agent/design/test-logs/final-live-blocker-status-refresh-20260622.md',
	'/path/to/wp-agent/design/iterations/0221-final-live-blocker-status-refresh.md',
	'/path/to/wp-agent/design/test-logs/final-blocker-operator-init-ignore-20260622.md',
	'/path/to/wp-agent/design/iterations/0222-final-blocker-operator-init-ignore.md',
);
foreach ( $required_design_refs as $required_design_ref ) {
	wp_agent_goals_evidence_assert( in_array( $required_design_ref, $design_refs, true ), 'goals.md should reference the required design evidence log.', array(
		'required_design_ref' => $required_design_ref,
	) );
}

wp_agent_goals_evidence_assert( false !== strpos( $goals, 'WP_AGENT_LIVE_GITHUB_REPOSITORY' ), 'goals.md should name the GitHub repository live input.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1' ), 'goals.md should name the live soak approval flag.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak' ), 'goals.md should name the live soak approval phrase.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'approval_phrase_confirmed=true' ), 'goals.md should require archived live soak approval confirmation.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'soak_approval_confirmed=true' ), 'goals.md should require final manifest approval confirmation.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'manifest_soak_approval_not_confirmed' ), 'goals.md should document the completion gate manifest approval error.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' ), 'goals.md should name the live soak cost budget flag.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' ), 'goals.md should name the live soak artifact policy flag.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'Chat 是核心操作台' ), 'goals.md should keep Chat UX as a core product requirement.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'UX 是阻断式验收门' ), 'goals.md should keep UX as a blocking acceptance gate.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '用户体验不是装饰性要求' ), 'goals.md should keep UX as a non-decorative product requirement.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '功能完整不能抵消体验失败' ), 'goals.md should keep UX failures blocking even when features are complete.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '同一会话 FIFO claim' ), 'goals.md should require same-conversation FIFO claim behavior.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '输入框在后台 run 执行时保持可用' ), 'goals.md should require the composer to remain usable during background work.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'Chat Stop/队列证据' ), 'goals.md should require Chat Stop and queue UX evidence.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/ux-product-usability-contract.php' ), 'goals.md should reference the product UX usability contract.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '产品级 UX 可用性契约' ), 'goals.md should keep product-level UX usability visible.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '状态透明、可停止、可继续输入' ), 'goals.md should keep operational UX requirements visible.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '只要 source URL 是 localhost/private/reserved' ), 'goals.md should require final command plan to reject invalid reviewed-looking inputs.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'WP_AGENT_OFFICIAL_DB_DIR \\' ), 'goals.md strict preflight command should pass the official DB dir env.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '最终用户输入与审批包' ), 'goals.md should include the final live review packet section.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-review-packet-template.md' ), 'goals.md should reference the final live review packet template.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-review-packet-contract.php' ), 'goals.md should reference the final live review packet contract.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-review-packet-status.php' ), 'goals.md should reference the final live review packet status script.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-review-packet-status-contract.php' ), 'goals.md should reference the final live review packet status contract.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'final-live-review-packet-*.md' ), 'goals.md should require completed final live review packets to stay ignored.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'final-live-inputs.*.env' ), 'goals.md should require reviewed final live env files to stay ignored.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'User-approved official Skill Store coordinates' ), 'goals.md should require user-approved official Skill Store coordinates.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '只能通过 shell、WordPress Settings 或 ignored env 注入' ), 'goals.md should keep GitHub token handling out of reviewed files.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'cost_budget_usd' ), 'goals.md should require an explicit cost budget review value.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'cleanup/rollback policy' ), 'goals.md should require a cleanup and rollback review policy.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '执行顺序必须是：更新 reviewed inputs' ), 'goals.md should document the final live execution order from reviewed inputs through completion gate.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '当前仍需用户提供的最小输入包' ), 'goals.md should include the final user input minimum packet section.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '用户不应在聊天、`goals.md`、README、design logs、review packet 或 reviewed env 中提供任何 raw token' ), 'goals.md should forbid raw token disclosure in final review materials.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'commands_executable=true' ), 'goals.md should require command-plan executability before live work.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'php tests/final-live-command-plan.php path/to/reviewed.env path/to/final-live-review-packet-YYYYMMDD.md' ), 'goals.md should document the full final command-plan invocation with reviewed env and review packet.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'review_packet_status 必须早于 command_plan regeneration' ), 'goals.md should require review packet status before command-plan regeneration and live steps.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'review_packet_ready=false' ), 'goals.md should document the default review-packet command-plan blocker.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'review_packet_ready=true' ), 'goals.md should require review-packet readiness before full live execution.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'review_packet_env_consistent=true' ), 'goals.md should require reviewed env and review packet consistency before full live execution.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'review_packet_env_mismatches' ), 'goals.md should require packet/env mismatch evidence when final live inputs disagree.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'ready_for_live_execution=true' ), 'goals.md should require the command plan to expose full live execution readiness.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'next_actions' ), 'goals.md should require blocker status to expose actionable next actions.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'combined reviewed env + review packet' ), 'goals.md should require blocker status to name the combined reviewed env and review packet command plan.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'blocker_status_next_actions_gate' ), 'goals.md should name the final external blocker status next-actions gate.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'summary_required_markers' ), 'goals.md should require blocker status to expose final summary markers.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'default_next_actions_summary_markers' ), 'goals.md should require blocker status contract to verify final summary next actions.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'operator_init_commands' ), 'goals.md should require blocker status to expose local-only operator initialization commands.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'default_operator_init_files_ignored=true' ), 'goals.md should require blocker status contract to prove operator init files are ignored and untracked.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'operator_secret_rule' ), 'goals.md should require blocker status to expose the no-secret handoff rule.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'missing_or_invalid_artifacts=[github, soak, summary, command_plan, manifest, redaction]' ), 'goals.md should require blocker status to name every missing final artifact including command_plan.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, '缺少任一 artifact 时 `completion_ready` 必须保持 `false`' ), 'goals.md should keep completion blocked when final artifacts are missing.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-blocker-status.php' ), 'goals.md should reference the final live blocker status snapshot.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-blocker-status-contract.php' ), 'goals.md should reference the final live blocker status contract.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'packet_ready=false' ), 'goals.md should document review packet readiness as a blocker status field.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'valid_packet_ready=true' ), 'goals.md should require a valid review packet fixture proof.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-user-input-status.php' ), 'goals.md should reference the final live user input status script.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-user-input-status-contract.php' ), 'goals.md should reference the final live user input status contract.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'user_input_ready=false' ), 'goals.md should document user input readiness as a blocker status field.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'valid_user_input_ready=true' ), 'goals.md should require a valid user input fixture proof.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'pending_user_inputs' ), 'goals.md should document pending user input reporting.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'pending_review_items' ), 'goals.md should document pending review item reporting.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-reviewed-env-status.php' ), 'goals.md should reference the final live reviewed env status script.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'tests/final-live-reviewed-env-status-contract.php' ), 'goals.md should reference the final live reviewed env status contract.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'reviewed_env_ready=false' ), 'goals.md should document reviewed env readiness as a blocker status field.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'valid_reviewed_fixture_ready=true' ), 'goals.md should require a valid reviewed env fixture proof.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'path_ignored_by_git=true' ), 'goals.md should require reviewed env files to be ignored by Git.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'ready_for_live_execution=false' ), 'goals.md should document the live execution blocker status.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'ready_to_mark_complete=false' ), 'goals.md should document the completion blocker status.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'invalid reviewed inputs' ), 'goals.md should document blocker status rejection of invalid reviewed inputs.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'blocker status 必须聚合 review packet status、user input status、reviewed env status 和 Git hygiene' ), 'goals.md should require blocker status to include review packet, user input, reviewed env, and Git hygiene.' );
wp_agent_goals_evidence_assert( false !== strpos( $goals, 'remote_push=false' ), 'goals.md should keep remote_push=false visible in final gate evidence.' );

echo json_encode( array(
	'success'             => true,
	'goals_path'          => $goals_path,
	'test_script_refs'    => count( $script_refs ),
	'design_evidence_refs' => count( $design_refs ),
	'partial_rows'        => $partial_rows,
	'final_review_packet' => true,
	'external_blockers'   => array(
		'official_skills_github_repository',
		'multi_hour_live_soak_budget_and_approval',
	),
	'live_network_calls'  => false,
	'ai_gateway_calls'    => false,
	'github_calls'        => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
