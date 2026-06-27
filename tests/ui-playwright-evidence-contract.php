<?php
/**
 * Host-side UI and Playwright evidence contract.
 *
 * Verifies the Notion-style UI design reference and Playwright evidence logs
 * remain present and reviewable. This script reads local files only. It does
 * not start Docker, WordPress, or a browser.
 *
 * Run from the host:
 * php tests/ui-playwright-evidence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This UI Playwright evidence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_ui_evidence_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_ui_evidence_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_ui_evidence_fail( $message, $details );
	}
}

function wp_agent_ui_evidence_read( $path ) {
	wp_agent_ui_evidence_assert( is_file( $path ), 'Required UI evidence file is missing.', array(
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_ui_evidence_assert( is_string( $text ) && '' !== $text, 'Required UI evidence file could not be read.', array(
		'path' => $path,
	) );

	return $text;
}

function wp_agent_ui_evidence_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_ui_evidence_assert( empty( $missing ), $name . ' is missing required UI evidence markers.', array(
		'missing' => $missing,
	) );
}

function wp_agent_ui_evidence_image_summary( $path ) {
	wp_agent_ui_evidence_assert( is_file( $path ), 'Required Playwright screenshot is missing.', array(
		'path' => $path,
	) );
	wp_agent_ui_evidence_assert( filesize( $path ) > 1000, 'Required Playwright screenshot is unexpectedly small.', array(
		'path' => $path,
		'size' => filesize( $path ),
	) );

	$size = @getimagesize( $path );
	wp_agent_ui_evidence_assert( is_array( $size ), 'Required Playwright screenshot is not a readable image.', array(
		'path' => $path,
	) );
	wp_agent_ui_evidence_assert( (int) $size[0] >= 300 && (int) $size[1] >= 300, 'Required Playwright screenshot dimensions are too small for review.', array(
		'path'   => $path,
		'width'  => (int) $size[0],
		'height' => (int) $size[1],
	) );

	return array(
		'path'   => $path,
		'width'  => (int) $size[0],
		'height' => (int) $size[1],
		'bytes'  => filesize( $path ),
	);
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_ui_evidence_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$design_md = wp_agent_ui_evidence_read( $plugin_dir . '/DESIGN.md' );
wp_agent_ui_evidence_require_markers( 'DESIGN.md', $design_md, array(
	'name: Notion Analysis',
	'canvas-soft',
	'ink-secondary',
	'typography:',
	'components:',
	'text-input',
	'data-table',
) );

$admin_css = wp_agent_ui_evidence_read( $plugin_dir . '/assets/css/admin.css' );
$chat_css  = wp_agent_ui_evidence_read( $plugin_dir . '/assets/css/chat.css' );
$chat_js   = wp_agent_ui_evidence_read( $plugin_dir . '/assets/js/chat.js' );
$chat_view = wp_agent_ui_evidence_read( $plugin_dir . '/admin/views/chat.php' );

wp_agent_ui_evidence_assert(
	! preg_match( '/letter-spacing\s*:\s*-\d/i', $admin_css . "\n" . $chat_css ),
	'WP Agent UI CSS should not use negative letter-spacing.',
	array(
		'files' => array( 'assets/css/admin.css', 'assets/css/chat.css' ),
	)
);

wp_agent_ui_evidence_require_markers( 'Chat UX source', $chat_js . "\n" . $chat_view . "\n" . $chat_css, array(
	'id="wpa-stop"',
	'Stop active agent run',
	'wpa-stop-label',
	'wpa-status--error',
	'renderEmptyState',
	'Lost connection:',
	'Could not resume the active agent session:',
	'Queued in background',
	'Composer remains available',
	'Stop available',
	'Stop current agent run; queued work continues',
	'position ' . "' + position + '" . ' of ' . "' + total",
	'input && !posting',
	"stopBtn.setAttribute('aria-describedby', 'wpa-status')",
	'function poll(runId, initialQueue)',
	'poll(run.id, data.queue)',
	'wpa-chat .wpa-stop',
) );

$test_log_dir = '/path/to/wp-agent/design/test-logs';
$ui_log        = wp_agent_ui_evidence_read( $test_log_dir . '/notion-ui-responsive-pass-20260621.md' );
$daemon_log    = wp_agent_ui_evidence_read( $test_log_dir . '/daemon-liveness-source-20260622.md' );
$chat_stop_log = wp_agent_ui_evidence_read( $test_log_dir . '/chat-stop-playwright-20260622.md' );
$ux_gate_log   = wp_agent_ui_evidence_read( $test_log_dir . '/ux-quality-gate-contract-20260622.md' );
$queue_status_log = wp_agent_ui_evidence_read( $test_log_dir . '/chat-queue-status-ux-20260622.md' );
$stop_availability_log = wp_agent_ui_evidence_read( $test_log_dir . '/chat-stop-availability-ux-20260622.md' );

wp_agent_ui_evidence_require_markers( 'notion-ui-responsive-pass-20260621.md', $ui_log, array(
	'official WordPress container',
	'Playwright Evidence',
	'"pages":8',
	'"desktop"',
	'"mobile"',
	'"overflowX":0',
	'"outOfViewport":0',
	'"buttonOverflow":0',
	'"consoleEvents":0',
) );
wp_agent_ui_evidence_require_markers( 'daemon-liveness-source-20260622.md', $daemon_log, array(
	'Playwright Dashboard desktop and mobile checks passed',
	'Mobile width `390px` had no horizontal overflow',
	'daemon-liveness-dashboard-desktop-20260622.png',
	'daemon-liveness-dashboard-mobile-20260622.png',
) );
wp_agent_ui_evidence_require_markers( 'chat-stop-playwright-20260622.md', $chat_stop_log, array(
	'Desktop viewport: 1440 x 900',
	'Mobile viewport: 390 x 844',
	'#wpa-input.disabled === false',
	'documentElement.scrollWidth === documentElement.clientWidth',
	'did not overlap',
	'0 warnings and 0 errors',
	'chat-stop-desktop-20260622.png',
	'chat-stop-mobile-20260622.png',
) );
wp_agent_ui_evidence_require_markers( 'ux-quality-gate-contract-20260622.md', $ux_gate_log, array(
	'ux_quality_gate=true',
	'Playwright desktop `1440x960`: passed',
	'Playwright mobile `390x844`: passed',
	'overflowX=0',
	'buttonOverflowCount=0',
	'negativeLetterSpacing=0',
	'input enabled',
	'Stop control present',
	'0 warnings, 0 errors',
	'chat-ux-quality-gate-desktop-20260622.png',
	'chat-ux-quality-gate-mobile-20260622.png',
) );
wp_agent_ui_evidence_require_markers( 'chat-queue-status-ux-20260622.md', $queue_status_log, array(
	'Playwright queue status fixture desktop `1440x960`: passed',
	'Playwright queue status fixture mobile `390x844`: passed',
	'Queued in background · position 2 of 3 · Composer remains available',
	'stopHidden=false',
	'inputDisabled=false',
	'overflowX=0',
	'buttonOverflowCount=0',
	'Console evidence: `warnings=0`, `errors=0`',
	'chat-queue-status-desktop-20260622.png',
	'chat-queue-status-mobile-20260622.png',
	'Temporary admin user deleted after Playwright capture.',
) );
wp_agent_ui_evidence_require_markers( 'chat-stop-availability-ux-20260622.md', $stop_availability_log, array(
	'Playwright desktop fixture passed with status text: `Queued in background · position 2 of 3 · Composer remains available · Stop available`',
	'Playwright mobile fixture passed with the same status text.',
	'stopHidden=false',
	'stopDisabled=false',
	'stopAria=Stop current agent run; queued work continues',
	'stopDescribedBy=wpa-status',
	'inputDisabled=false',
	'overflowX=0',
	'buttonOverflowCount=0',
	'Queue summary is now preserved',
	'chat-stop-availability-desktop-20260622.png',
	'chat-stop-availability-mobile-20260622.png',
	'Temporary admin user `wpa_ux_stop_20260622` was deleted after Playwright capture.',
) );

$required_screenshots = array(
	'ui-final2-dashboard-desktop-20260621.png',
	'ui-final2-dashboard-mobile-20260621.png',
	'ui-final2-chat-desktop-20260621.png',
	'ui-final2-chat-mobile-20260621.png',
	'ui-final2-settings-desktop-20260621.png',
	'ui-final2-settings-mobile-20260621.png',
	'ui-final2-logs-desktop-20260621.png',
	'ui-final2-logs-mobile-20260621.png',
	'ui-final2-skills-desktop-20260621.png',
	'ui-final2-skills-mobile-20260621.png',
	'ui-final2-schedules-desktop-20260621.png',
	'ui-final2-schedules-mobile-20260621.png',
	'ui-final2-costs-desktop-20260621.png',
	'ui-final2-costs-mobile-20260621.png',
	'ui-final2-audit-log-desktop-20260621.png',
	'ui-final2-audit-log-mobile-20260621.png',
	'daemon-liveness-dashboard-desktop-20260622.png',
	'daemon-liveness-dashboard-mobile-20260622.png',
	'chat-stop-desktop-20260622.png',
	'chat-stop-mobile-20260622.png',
	'chat-ux-quality-gate-desktop-20260622.png',
	'chat-ux-quality-gate-mobile-20260622.png',
	'chat-queue-status-desktop-20260622.png',
	'chat-queue-status-mobile-20260622.png',
	'chat-stop-availability-desktop-20260622.png',
	'chat-stop-availability-mobile-20260622.png',
);

$images = array();
foreach ( $required_screenshots as $screenshot ) {
	$images[] = wp_agent_ui_evidence_image_summary( $test_log_dir . '/' . $screenshot );
}

echo json_encode( array(
	'success'             => true,
	'contract'            => 'ui_playwright_evidence_contract',
	'design_reference'    => 'DESIGN.md',
	'ux_quality_gate'     => true,
	'chat_stop_playwright' => true,
	'chat_ux_quality_gate' => true,
	'chat_queue_status_playwright' => true,
	'chat_stop_availability_playwright' => true,
	'composer_unlocked_guard' => true,
	'stop_control_guard'  => true,
	'negative_letter_spacing' => false,
	'logs_checked'        => 6,
	'screenshots_checked' => count( $images ),
	'desktop_mobile_pairs' => 13,
	'overflow_guard'      => true,
	'console_guard'       => true,
	'live_network_calls'  => false,
	'ai_gateway_calls'    => false,
	'github_calls'        => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
