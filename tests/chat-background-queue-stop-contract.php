<?php
/**
 * Chat background queue and Stop UX contract.
 *
 * Verifies that Chat keeps input usable while background PHP agent runs are
 * active, exposes a Stop control, and wires cancellation through durable run
 * state. This script only reads local source files.
 *
 * Run from the host:
 * php tests/chat-background-queue-stop-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This chat background queue contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_chat_queue_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_chat_queue_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_chat_queue_fail( $message, $details );
	}
}

function wp_agent_chat_queue_read( $plugin_dir, $relative ) {
	$path = $plugin_dir . '/' . $relative;
	wp_agent_chat_queue_assert( is_file( $path ), 'Required source file is missing.', array( 'file' => $relative ) );
	$text = file_get_contents( $path );
	wp_agent_chat_queue_assert( is_string( $text ) && '' !== $text, 'Required source file is unreadable.', array( 'file' => $relative ) );
	return $text;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_chat_queue_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$files = array(
	'view'          => wp_agent_chat_queue_read( $plugin_dir, 'admin/views/chat.php' ),
	'js'            => wp_agent_chat_queue_read( $plugin_dir, 'assets/js/chat.js' ),
	'css'           => wp_agent_chat_queue_read( $plugin_dir, 'assets/css/chat.css' ),
	'handler'       => wp_agent_chat_queue_read( $plugin_dir, 'includes/class-wp-agent-webhook-handler.php' ),
	'runs'          => wp_agent_chat_queue_read( $plugin_dir, 'includes/class-wp-agent-runs.php' ),
	'worker'        => wp_agent_chat_queue_read( $plugin_dir, 'includes/class-wp-agent-worker.php' ),
	'confirmations' => wp_agent_chat_queue_read( $plugin_dir, 'includes/class-wp-agent-confirmations.php' ),
);

$coverage = array(
	'stop_button_view'        => false !== strpos( $files['view'], 'id="wpa-stop"' ) && false !== strpos( $files['view'], 'Stop active agent run' ),
	'stop_visible_label'      => false !== strpos( $files['view'], 'wpa-stop-label' ) && false !== strpos( $files['view'], "'Stop', 'wp-agent'" ) && false !== strpos( $files['css'], '.wpa-stop-label' ),
	'stop_button_styles'      => false !== strpos( $files['css'], '.wpa-chat .wpa-stop' ) && false !== strpos( $files['css'], '.wpa-chat .wpa-stop[hidden]' ),
	'cancel_route'            => false !== strpos( $files['handler'], "/chat/runs/(?P<id>[0-9]+)/cancel" ) && false !== strpos( $files['handler'], 'handle_chat_run_cancel' ),
	'daemon_wake_on_send'     => false !== strpos( $files['handler'], 'wake_background_agent' ) && false !== strpos( $files['handler'], 'WPAgent_Daemon::watchdog' ),
	'confirmation_closed'     => false !== strpos( $files['handler'], 'reject_pending_for_run' ) && false !== strpos( $files['confirmations'], 'reject_pending_for_run' ),
	'durable_cancel_state'    => false !== strpos( $files['runs'], 'cancel_if_active' ) && false !== strpos( $files['runs'], 'is_terminal_status' ),
	'conversation_fifo_claim' => false !== strpos( $files['runs'], 'has_earlier_active_in_conversation' ) && false !== strpos( $files['runs'], 'prior.status IN' ),
	'queue_summary_rest'      => false !== strpos( $files['runs'], 'queue_summary_for_run' ) && false !== strpos( $files['handler'], "'queue'           => WPAgent_Runs::queue_summary_for_run" ),
	'queue_position_status'   => false !== strpos( $files['runs'], 'unfinished_position_for_run' ) && false !== strpos( $files['js'], 'position ' . "' + position + '" . ' of ' . "' + total" ),
	'queue_summary_preserved' => false !== strpos( $files['js'], 'function poll(runId, initialQueue)' ) && false !== strpos( $files['js'], 'setQueueStatus(runStatuses[normalizedRunId] || ' . "'queued'" . ', initialQueue)' ) && false !== strpos( $files['js'], 'poll(resp.run_id, resp.queue)' ) && false !== strpos( $files['js'], 'poll(run.id, data.queue)' ),
	'worker_cancel_boundary'  => false !== strpos( $files['worker'], 'WPAgent_Runs::is_canceled' ) && false !== strpos( $files['worker'], 'canceled_observed' ),
	'js_stop_wiring'          => false !== strpos( $files['js'], 'var stopBtn = document.getElementById' ) && false !== strpos( $files['js'], "chat/runs/' + encodeURIComponent(runId) + '/cancel" ),
	'js_run_tracking'         => false !== strpos( $files['js'], 'var pollingRuns = {}' ) && false !== strpos( $files['js'], 'var activeRunIds = {}' ) && false !== strpos( $files['js'], 'selectCancelableRunId' ),
	'js_status_clarity'       => false !== strpos( $files['js'], 'function formatQueueStatus' ) && false !== strpos( $files['js'], 'Queued in background' ) && false !== strpos( $files['js'], 'Composer remains available' ),
	'js_stop_availability'    => false !== strpos( $files['js'], 'function isInterruptibleStatus' ) && false !== strpos( $files['js'], 'Stop available' ) && false !== strpos( $files['js'], 'Stop current agent run; queued work continues' ) && false !== strpos( $files['js'], "stopBtn.setAttribute('aria-describedby', 'wpa-status')" ),
	'js_posting_separate'     => false !== strpos( $files['js'], 'var posting = false' ) && false !== strpos( $files['js'], 'posting || uploading' ),
	'input_not_poll_locked'   => false === strpos( $files['js'], '|| polling || uploading' ) && false === strpos( $files['js'], 'input.disabled = disabled' ),
	'send_not_poll_locked'    => false === strpos( $files['js'], 'sendBtn.disabled = isUploading || polling' ) && false === strpos( $files['js'], 'setSendDisabled(true);\n                poll' ),
);

foreach ( $coverage as $name => $ok ) {
	wp_agent_chat_queue_assert( $ok, 'Chat background queue contract marker is missing.', array( 'marker' => $name ) );
}

echo json_encode( array(
	'success'            => true,
	'contract'           => 'chat_background_queue_stop',
	'coverage'           => $coverage,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
