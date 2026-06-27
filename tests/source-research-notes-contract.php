<?php
/**
 * Host-side source research notes contract.
 *
 * Verifies external framework clones have matching, reviewable source-reading
 * notes under /path/to/wp-agent/design/research. This script reads
 * local files only and does not fetch or contact any remote service.
 *
 * Run from the host:
 * php tests/source-research-notes-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This source research notes contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_research_notes_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_research_notes_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_research_notes_fail( $message, $details );
	}
}

function wp_agent_research_notes_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );

	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_research_notes_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_research_notes_read( $path ) {
	wp_agent_research_notes_assert( is_file( $path ), 'Research note is missing.', array(
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_research_notes_assert( is_string( $text ) && '' !== $text, 'Research note could not be read.', array(
		'path' => $path,
	) );

	return $text;
}

function wp_agent_research_notes_require_markers( $name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}
	wp_agent_research_notes_assert( empty( $missing ), $name . ' is missing required research markers.', array(
		'missing' => $missing,
	) );
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_research_notes_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$inventory_result = wp_agent_research_notes_command( array(
	PHP_BINARY,
	$plugin_dir . '/tests/source-research-inventory.php',
) );
wp_agent_research_notes_assert( 0 === $inventory_result['status'], 'Source research inventory must pass before notes can be validated.', array(
	'output' => $inventory_result['output'],
) );
$inventory = wp_agent_research_notes_json( $inventory_result['output'] );
wp_agent_research_notes_assert( is_array( $inventory ) && true === (bool) ( $inventory['success'] ?? false ), 'Source research inventory should return success JSON.', array(
	'output' => $inventory_result['output'],
) );

$design_research_dir = '/path/to/wp-agent/design/research';
$agent_note_path     = $design_research_dir . '/agent-framework-source-notes.md';
$php_note_path       = $design_research_dir . '/reactphp-memory-notes.md';
$chat_ux_note_path   = $design_research_dir . '/chat-ux-agent-framework-notes.md';
$agent_note          = wp_agent_research_notes_read( $agent_note_path );
$php_note            = wp_agent_research_notes_read( $php_note_path );
$chat_ux_note        = wp_agent_research_notes_read( $chat_ux_note_path );

$agent_projects = array(
	'OpenHands'    => '## OpenHands',
	'OpenManus'    => '## OpenManus',
	'openclaw'     => '## OpenClaw',
	'hermes-agent' => '## Hermes Agent',
);
$php_projects = array(
	'reactphp-event-loop' => '## ReactPHP',
	'amphp-amp'           => '## Amp',
	'workerman'           => '## Workerman',
	'roadrunner'          => '## RoadRunner',
	'laravel-octane'      => '## Laravel Octane',
	'swoole-src'          => '## Swoole',
);

foreach ( $inventory['repositories'] ?? array() as $repo ) {
	$name = (string) ( $repo['name'] ?? '' );
	$head = (string) ( $repo['head'] ?? '' );
	$target_note = isset( $agent_projects[ $name ] ) ? $agent_note : $php_note;
	$display_marker = $agent_projects[ $name ] ?? ( $php_projects[ $name ] ?? '' );

	wp_agent_research_notes_assert( '' !== $display_marker, 'Unexpected repository in source research inventory.', array(
		'repository' => $name,
	) );
	wp_agent_research_notes_assert( false !== strpos( $target_note, $display_marker ), 'Research note should contain a section for the repository.', array(
		'repository' => $name,
		'marker'     => $display_marker,
	) );
	wp_agent_research_notes_assert( false !== strpos( $target_note, '`' . $name . '`' ) || false !== strpos( $target_note, $name ), 'Research note should mention the local repository directory.', array(
		'repository' => $name,
	) );
	wp_agent_research_notes_assert(
		false !== strpos( $target_note, $head ) || false !== strpos( $target_note, substr( $head, 0, 7 ) ),
		'Research note should include the current local clone revision.',
		array(
			'repository' => $name,
			'head'       => $head,
		)
	);

	if ( 'openclaw' === $name || 'hermes-agent' === $name ) {
		wp_agent_research_notes_assert(
			false !== strpos( $chat_ux_note, $head ) || false !== strpos( $chat_ux_note, substr( $head, 0, 7 ) ),
			'Chat UX research note should include the current OpenClaw/Hermes clone revision.',
			array(
				'repository' => $name,
				'head'       => $head,
			)
		);
	}
}

wp_agent_research_notes_require_markers( 'agent-framework-source-notes.md', $agent_note, array(
	'## Cloned References',
	'## OpenManus',
	'## Hermes Agent',
	'## OpenClaw',
	'## OpenHands',
	'Key files:',
	'Key areas:',
	'Observed mechanism:',
	'WP Agent translation:',
	'Do not copy:',
	'tool',
	'workspace',
	'skill',
	'cron',
	'sandbox',
	'security',
) );

wp_agent_research_notes_require_markers( 'reactphp-memory-notes.md', $php_note, array(
	'## Source Revisions',
	'## ReactPHP',
	'## Amp',
	'## Laravel Octane',
	'## Workerman',
	'## RoadRunner',
	'## Swoole',
	'Observed mechanisms:',
	'WP Agent translation:',
	'## WP Agent Current Alignment',
	'## Design Rules Going Forward',
	'max_jobs',
	'max_lifetime',
	'memory',
	'signal',
	'watchdog',
) );

wp_agent_research_notes_require_markers( 'chat-ux-agent-framework-notes.md', $chat_ux_note, array(
	'## Source Revisions',
	'## OpenClaw Chat Abort And Task Runs',
	'## Hermes Agent Gateway And Kanban Runs',
	'## WP Agent Current Alignment',
	'## Design Rules Going Forward',
	'src/gateway/chat-abort.ts',
	'src/tasks/cron-task-cancel.ts',
	'src/tasks/task-registry.ts',
	'gateway/session.py',
	'gateway/run.py',
	'gateway/stream_events.py',
	'hermes_cli/kanban_db.py',
	'registerChatAbortController',
	'abortChatRunById',
	'projectSessionActive',
	'isChatStopCommandText',
	'SessionContext',
	'MessageChunk',
	'claim locks',
	'heartbeat',
	'same-conversation FIFO',
	'Frontend polling is a status/read model; PHP CLI daemon/worker is the execution model.',
	'Terminal state must be durable before UI cleanup hides active work.',
	'Aborted runs must not replay stale partial assistant text as a fresh answer.',
) );

echo json_encode( array(
	'success'             => true,
	'contract'            => 'source_research_notes_contract',
	'repo_count'          => count( $inventory['repositories'] ?? array() ),
	'notes_checked'       => 3,
	'agent_projects'      => array_keys( $agent_projects ),
	'php_runtime_projects' => array_keys( $php_projects ),
	'live_network_calls'  => false,
	'ai_gateway_calls'    => false,
	'github_calls'        => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
