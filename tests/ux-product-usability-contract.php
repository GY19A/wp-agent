<?php
/**
 * Host-side product UX usability contract.
 *
 * Verifies that WP Agent's UX rules are not only documented, but connected to
 * reviewable Chat source, status/cancel/recovery affordances, and archived UI
 * evidence. This script reads local files only.
 *
 * Run from the host:
 * php tests/ux-product-usability-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This product UX usability contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_ux_product_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_ux_product_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_ux_product_fail( $message, $details );
	}
}

function wp_agent_ux_product_read( $path ) {
	wp_agent_ux_product_assert( is_file( $path ), 'Required UX product evidence file is missing.', array(
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_ux_product_assert( is_string( $text ) && '' !== $text, 'Required UX product evidence file could not be read.', array(
		'path' => $path,
	) );

	return $text;
}

function wp_agent_ux_product_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_ux_product_assert( empty( $missing ), $name . ' is missing required UX product markers.', array(
		'missing' => $missing,
	) );

	return count( $markers );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_ux_product_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$goals     = wp_agent_ux_product_read( $plugin_dir . '/goals.md' );
$view      = wp_agent_ux_product_read( $plugin_dir . '/admin/views/chat.php' );
$chat_js   = wp_agent_ux_product_read( $plugin_dir . '/assets/js/chat.js' );
$chat_css  = wp_agent_ux_product_read( $plugin_dir . '/assets/css/chat.css' );
$handler   = wp_agent_ux_product_read( $plugin_dir . '/includes/class-wp-agent-webhook-handler.php' );
$runs      = wp_agent_ux_product_read( $plugin_dir . '/includes/class-wp-agent-runs.php' );
$ui_gate   = wp_agent_ux_product_read( $plugin_dir . '/tests/ui-playwright-evidence-contract.php' );
$queue_gate = wp_agent_ux_product_read( $plugin_dir . '/tests/chat-background-queue-stop-contract.php' );
$final_gate = wp_agent_ux_product_read( $plugin_dir . '/tests/final-external-gates-evidence-contract.php' );

$test_log_dir = '/path/to/wp-agent/design/test-logs';
$logs = array(
	'ux_quality'       => wp_agent_ux_product_read( $test_log_dir . '/ux-quality-gate-contract-20260622.md' ),
	'ux_principle'    => wp_agent_ux_product_read( $test_log_dir . '/ux-product-quality-principle-20260622.md' ),
	'ux_nonnegotiable' => wp_agent_ux_product_read( $test_log_dir . '/ux-nonnegotiable-gate-20260622.md' ),
	'chat_stop'       => wp_agent_ux_product_read( $test_log_dir . '/chat-stop-playwright-20260622.md' ),
	'chat_queue_status' => wp_agent_ux_product_read( $test_log_dir . '/chat-queue-status-ux-20260622.md' ),
	'chat_stop_availability' => wp_agent_ux_product_read( $test_log_dir . '/chat-stop-availability-ux-20260622.md' ),
);

$marker_counts = array();
$marker_counts['goals_product_gate'] = wp_agent_ux_product_require_markers( 'goals.md product UX gate', $goals, array(
	'用户体验一定要好',
	'用户体验不是装饰性要求',
	'功能完整不能抵消体验失败',
	'UX 是阻断式验收门',
	'用户旅程',
	'可中断点',
	'后台队列状态',
	'失败恢复文案',
	'该迭代仍判定为 UX 失败',
	'不得用“后台已执行”“测试已通过”替代真实可理解、可控制、可恢复的用户体验',
) );

$marker_counts['chat_accessibility'] = wp_agent_ux_product_require_markers( 'Chat accessibility affordances', $view, array(
	'role="log" aria-live="polite"',
	'role="status" aria-live="polite"',
	'id="wpa-stop"',
	'Stop active agent run',
	'wpa-stop-label',
	'Send message',
	'screen-reader-text',
) );

$marker_counts['chat_operability'] = wp_agent_ux_product_require_markers( 'Chat operability source', $chat_js, array(
	'function setStatus',
	'function setThinking',
	'function runStatusLabel',
	'function formatQueueStatus',
	'function isInterruptibleStatus',
	'function setQueueStatus',
	'function activeRunCount',
	'Queued in background',
	'Awaiting approval',
	'Composer remains available',
	'Stop available',
	'Stop current agent run; queued work continues',
	'Stopping agent run...',
	'Stopped current run. Remaining queued work will continue.',
	'Could not stop:',
	'Lost connection:',
	'Could not resume the active agent session:',
	'Failed to send:',
	'Uploading media...',
	'function renderEmptyState',
) );

$marker_counts['background_queue'] = wp_agent_ux_product_require_markers( 'Background queue UX source', $chat_js, array(
	'var pollingRuns = {}',
	'var activeRunIds = {}',
	'function selectCancelableRunId',
	"chat/runs/' + encodeURIComponent(runId) + '/cancel",
	"stopBtn.setAttribute('aria-describedby', 'wpa-status')",
	'function poll(runId, initialQueue)',
	'poll(resp.run_id, resp.queue)',
	'poll(run.id, data.queue)',
	'currentConversationId !== conversationForRun',
	'window.setTimeout(tick, 1100)',
	'setRunActive(normalizedRunId, true',
	'position ' . "' + position + '" . ' of ' . "' + total",
) );

$marker_counts['queue_observability_backend'] = wp_agent_ux_product_require_markers( 'Queue observability backend', $handler . "\n" . $runs, array(
	'queue_summary_for_run',
	'queue_summary_for_conversation',
	'unfinished_position_for_run',
	"'queue'           => WPAgent_Runs::queue_summary_for_run",
) );

$marker_counts['responsive_css'] = wp_agent_ux_product_require_markers( 'Responsive Chat CSS', $chat_css, array(
	'.wpa-main',
	'min-width: 0',
	'overflow-wrap: anywhere',
	'.wpa-composer-row',
	'.wpa-status',
	'.wpa-chat .wpa-stop[hidden]',
	'.wpa-stop-label',
	'white-space: nowrap',
	'@media (max-width: 900px)',
	'.wpa-bubble { max-width: 92%; }',
) );

$marker_counts['contract_chain'] = wp_agent_ux_product_require_markers( 'UX contract chain', $ui_gate . "\n" . $queue_gate . "\n" . $final_gate, array(
	'ui_playwright_evidence_contract',
	'chat_background_queue_stop',
	'ux_quality_gate',
	'chat_stop_playwright',
	'composer_unlocked_guard',
	'input_not_poll_locked',
	'ux_nonnegotiable_gate',
	'体验不得妥协',
	'用户不需要猜测',
) );

$marker_counts['archived_evidence'] = wp_agent_ux_product_require_markers( 'Archived UX evidence', implode( "\n", $logs ), array(
	'ux_quality_gate=true',
	'Playwright desktop `1440x960`: passed',
	'Playwright mobile `390x844`: passed',
	'overflowX=0',
	'buttonOverflowCount=0',
	'input enabled',
	'Stop control present',
	'Queued in background · position 2 of 3 · Composer remains available',
	'Playwright queue status fixture desktop `1440x960`: passed',
	'Playwright queue status fixture mobile `390x844`: passed',
	'inputDisabled=false',
	'chat-queue-status-desktop-20260622.png',
	'chat-queue-status-mobile-20260622.png',
	'Queued in background · position 2 of 3 · Composer remains available · Stop available',
	'stopAria=Stop current agent run; queued work continues',
	'stopDescribedBy=wpa-status',
	'Queue summary is now preserved',
	'chat-stop-availability-desktop-20260622.png',
	'chat-stop-availability-mobile-20260622.png',
	'#wpa-input.disabled === false',
	'documentElement.scrollWidth === documentElement.clientWidth',
	'feature completeness cannot compensate for UX failure',
	'用户不需要猜测',
) );

wp_agent_ux_product_assert(
	false === strpos( $chat_js, 'input.disabled = disabled' )
	&& false === strpos( $chat_js, '|| polling || uploading' )
	&& false === strpos( $chat_js, 'sendBtn.disabled = isUploading || polling' ),
	'Chat input and Send button must not be locked by polling/background run state.'
);

wp_agent_ux_product_assert(
	! preg_match( '/letter-spacing\s*:\s*-\d/i', $chat_css ),
	'Chat CSS must not use negative letter-spacing.'
);

echo json_encode( array(
	'success'                 => true,
	'contract'                => 'ux_product_usability_contract',
	'marker_counts'           => $marker_counts,
	'product_ux_gate'         => true,
	'status_transparency'     => true,
	'interruptibility'        => true,
	'queue_continuity'        => true,
	'recovery_copy'           => true,
	'accessibility_affordance' => true,
	'responsive_overflow_guard' => true,
	'composer_unlocked_guard' => true,
	'live_network_calls'      => false,
	'ai_gateway_calls'        => false,
	'github_calls'            => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
