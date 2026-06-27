<?php
/**
 * WP Agent code execution confirmation gate checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/code-execution-confirmation-gate.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This code execution confirmation gate script must run through WP-CLI.\n" );
	exit( 1 );
}

global $wpdb;

$sentinel = '__wp_agent_missing_' . wp_generate_password( 12, false, false ) . '__';
$GLOBALS['wp_agent_code_gate_previous'] = array(
	'mode'            => get_option( 'wp_agent_mode', 'author' ),
	'php_cli'         => get_option( 'wp_agent_enable_php_cli_execution', $sentinel ),
	'api_key'         => WPAgent::get_option( 'meowl_api_key', '' ),
	'model'           => WPAgent::get_option( 'meowl_model', '' ),
	'monthly_budget'  => get_option( 'wp_agent_monthly_budget', $sentinel ),
	'sentinel'        => $sentinel,
);
$GLOBALS['wp_agent_code_gate_run_id']          = 0;
$GLOBALS['wp_agent_code_gate_conversation_id'] = 0;
$GLOBALS['wp_agent_code_gate_model_calls']     = 0;
$GLOBALS['wp_agent_code_gate_tool_names']      = array();
$GLOBALS['wp_agent_code_gate_requester_id']    = 0;
$GLOBALS['wp_agent_code_gate_cleaned']         = false;

function wp_agent_code_gate_path_starts_with( $path, $parent ) {
	$path   = trailingslashit( wp_normalize_path( (string) $path ) );
	$parent = trailingslashit( wp_normalize_path( (string) $parent ) );
	return 0 === strpos( $path, $parent );
}

function wp_agent_code_gate_rrmdir( $path ) {
	$path = wp_normalize_path( (string) $path );
	if ( '' === $path || ! is_dir( $path ) ) {
		return;
	}

	$run_area = WPAgent_Sandbox::runtime_area_dir( 'runs' );
	if ( ! wp_agent_code_gate_path_starts_with( $path, $run_area ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $path );
}

function wp_agent_code_gate_cleanup() {
	global $wpdb;

	if ( ! empty( $GLOBALS['wp_agent_code_gate_cleaned'] ) ) {
		return;
	}
	$GLOBALS['wp_agent_code_gate_cleaned'] = true;

	$run_id          = (int) $GLOBALS['wp_agent_code_gate_run_id'];
	$conversation_id = (int) $GLOBALS['wp_agent_code_gate_conversation_id'];
	$requester_id    = (int) $GLOBALS['wp_agent_code_gate_requester_id'];

	if ( $run_id > 0 ) {
		$workspace = ( new WPAgent_Sandbox_Broker() )->workspace( $conversation_id, $requester_id, $run_id );
		$root      = $workspace->root();
		if ( ! is_wp_error( $root ) ) {
			wp_agent_code_gate_rrmdir( dirname( $root ) );
		}

		$wpdb->delete( $wpdb->prefix . 'wp_agent_confirmations', array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => $run_id ), array( '%d' ) );
	}

	if ( $conversation_id > 0 ) {
		$wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => $conversation_id ), array( '%d' ) );
	}

	$wpdb->delete(
		$wpdb->prefix . 'wp_agent_usage',
		array(
			'user_id' => $requester_id,
			'model'   => 'wp-agent-code-gate-model',
		),
		array( '%d', '%s' )
	);

	$previous = $GLOBALS['wp_agent_code_gate_previous'];
	update_option( 'wp_agent_mode', $previous['mode'] );
	WPAgent_Roles::ensure();

	if ( $previous['sentinel'] === $previous['php_cli'] ) {
		delete_option( 'wp_agent_enable_php_cli_execution' );
	} else {
		update_option( 'wp_agent_enable_php_cli_execution', $previous['php_cli'], false );
	}

	WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
	WPAgent::update_option( 'meowl_model', $previous['model'] );
	if ( $previous['sentinel'] === $previous['monthly_budget'] ) {
		delete_option( 'wp_agent_monthly_budget' );
	} else {
		update_option( 'wp_agent_monthly_budget', $previous['monthly_budget'] );
	}
}

function wp_agent_code_gate_fail( $message ) {
	wp_agent_code_gate_cleanup();
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	exit( 1 );
}

function wp_agent_code_gate_assert( $condition, $message ) {
	if ( ! $condition ) {
		wp_agent_code_gate_fail( $message );
	}
}

function wp_agent_code_gate_requester_id() {
	$admin = get_user_by( 'login', 'admin' );
	if ( $admin ) {
		return (int) $admin->ID;
	}
	return 1;
}

function wp_agent_code_gate_response( $message, $finish_reason = 'stop' ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( array(
			'id'      => 'chatcmpl-wp-agent-code-gate',
			'object'  => 'chat.completion',
			'created' => time(),
			'model'   => 'wp-agent-code-gate-model',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => $message,
					'finish_reason' => $finish_reason,
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 17,
				'completion_tokens' => 11,
				'total_tokens'      => 28,
			),
		) ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
}

function wp_agent_code_gate_tool_call( $id, $name, $arguments ) {
	return array(
		'id'       => $id,
		'type'     => 'function',
		'function' => array(
			'name'      => $name,
			'arguments' => wp_json_encode( $arguments ),
		),
	);
}

function wp_agent_code_gate_latest_tool_message( $conversation_id ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wp_agent_messages WHERE conversation_id = %d AND role = 'tool' ORDER BY id DESC LIMIT 1",
			(int) $conversation_id
		),
		ARRAY_A
	);
}

function wp_agent_code_gate_recent_metadata( $run_id, $event_type ) {
	$events = WPAgent_Run_Events::recent( $run_id, 50 );
	$items  = array();
	foreach ( $events as $event ) {
		if ( $event_type !== ( $event['event_type'] ?? '' ) ) {
			continue;
		}
		$metadata = json_decode( (string) ( $event['metadata'] ?? '' ), true );
		$items[]  = is_array( $metadata ) ? $metadata : array();
	}
	return array_reverse( $items );
}

register_shutdown_function( 'wp_agent_code_gate_cleanup' );

$requester_id = wp_agent_code_gate_requester_id();
$GLOBALS['wp_agent_code_gate_requester_id'] = $requester_id;
wp_set_current_user( $requester_id );

update_option( 'wp_agent_mode', 'administrator' );
WPAgent_Roles::ensure();
update_option( 'wp_agent_enable_php_cli_execution', true, false );
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-code-gate-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-code-gate-model' );
WPAgent::update_option( 'monthly_budget', 0 );

$agent_user = WPAgent_Roles::get_user_id();
wp_agent_code_gate_assert( $agent_user > 0, 'Bounded agent user is missing.' );
wp_agent_code_gate_assert( user_can( $agent_user, 'manage_options' ), 'Administrator mode should allow the agent to see manage_options tools.' );

$status = ( new WPAgent_Sandbox_Broker() )->status();
wp_agent_code_gate_assert( 'php_cli' === ( $status['selected'] ?? '' ), 'Restricted PHP CLI backend should be selected after explicit opt-in.' );
wp_agent_code_gate_assert( 'enabled' === ( $status['execution'] ?? '' ), 'Code execution backend should be enabled after explicit opt-in.' );

$marker = 'approval-gate-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
$code   = 'echo "' . $marker . '-executed\n";' . "\n"
	. 'file_put_contents(WP_AGENT_WORKSPACE_OUTPUT . "/approval-gate.txt", "' . $marker . '-approved");';

add_filter(
	'pre_http_request',
	function( $preempt, $parsed_args, $url ) use ( $marker, $code ) {
		if ( false === strpos( (string) $url, '/chat/completions' ) ) {
			return $preempt;
		}

		$GLOBALS['wp_agent_code_gate_model_calls']++;
		$request = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
		$tools   = is_array( $request['tools'] ?? null ) ? $request['tools'] : array();
		$GLOBALS['wp_agent_code_gate_tool_names'] = array_map(
			function( $tool ) {
				return (string) ( $tool['function']['name'] ?? '' );
			},
			$tools
		);

		return wp_agent_code_gate_response(
			array(
				'role'       => 'assistant',
				'content'    => 'Preparing a gated PHP CLI execution.',
				'tool_calls' => array(
					wp_agent_code_gate_tool_call(
						'call_execute_code_gate',
						'execute_code',
						array(
							'action'         => 'run',
							'language'       => 'php',
							'code'           => $code,
							'timeout'        => 5,
							'max_output'     => 8192,
							'import_outputs' => true,
							'output_prefix'  => 'approval-gate',
						)
					),
				),
			),
			'tool_calls'
		);
	},
	10,
	3
);

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( $requester_id, 'wpcli', 'code-execution-confirmation-gate-' . wp_generate_uuid4() );
wp_agent_code_gate_assert( $conversation_id > 0, 'Conversation should be created.' );
$GLOBALS['wp_agent_code_gate_conversation_id'] = (int) $conversation_id;

$message_id = $conversation->add_message( $conversation_id, 'user', 'Run a gated PHP code execution fixture.' );
wp_agent_code_gate_assert( $message_id > 0, 'User message should be created.' );

$run_id = WPAgent_Runs::create( $conversation_id, $requester_id, $message_id, 'wpcli' );
wp_agent_code_gate_assert( $run_id > 0, 'Run should be created.' );
$GLOBALS['wp_agent_code_gate_run_id'] = (int) $run_id;

$first_step = WPAgent_Worker::run_once( $run_id );
wp_agent_code_gate_assert( empty( $first_step['idle'] ) && 'awaiting_confirmation' === ( $first_step['status'] ?? '' ), 'First worker step should pause for human confirmation.' );
wp_agent_code_gate_assert( 1 === (int) $GLOBALS['wp_agent_code_gate_model_calls'], 'Gate test should make one fake model call before approval.' );
wp_agent_code_gate_assert( in_array( 'execute_code', $GLOBALS['wp_agent_code_gate_tool_names'], true ), 'execute_code should be visible to the model only after PHP CLI opt-in.' );

$run = WPAgent_Runs::get( $run_id );
wp_agent_code_gate_assert( $run && 'awaiting_confirmation' === (string) $run->status, 'Run should persist awaiting_confirmation status.' );

$confirmation = WPAgent_Confirmations::pending_for_run( $run_id );
wp_agent_code_gate_assert( $confirmation && 'execute_code' === ( $confirmation['tool_name'] ?? '' ), 'A pending execute_code confirmation should be created.' );
wp_agent_code_gate_assert( 'run' === ( $confirmation['action'] ?? '' ), 'Pending confirmation should record the run action.' );
wp_agent_code_gate_assert( false !== strpos( (string) ( $confirmation['params']['code'] ?? '' ), $marker ), 'Hydrated confirmation params should retain the code for human review.' );

$raw_params = (string) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT params FROM {$wpdb->prefix}wp_agent_confirmations WHERE id = %d",
		(int) $confirmation['id']
	)
);
wp_agent_code_gate_assert( '' !== $raw_params, 'Stored confirmation params should not be empty.' );
wp_agent_code_gate_assert( false === strpos( $raw_params, $marker ), 'Stored confirmation params should be encrypted at rest.' );
wp_agent_code_gate_assert( '{' !== substr( ltrim( $raw_params ), 0, 1 ), 'Stored confirmation params should not be raw JSON.' );

$workspace = ( new WPAgent_Sandbox_Broker() )->workspace( $conversation_id, $requester_id, $run_id );
$before_output = $workspace->read( 'approval-gate/approval-gate.txt' );
wp_agent_code_gate_assert( is_wp_error( $before_output ), 'Code output should not exist before approval.' );
wp_agent_code_gate_assert( null === wp_agent_code_gate_latest_tool_message( $conversation_id ), 'No tool result message should be appended before approval.' );

$tool_events = wp_agent_code_gate_recent_metadata( $run_id, 'tool_call' );
wp_agent_code_gate_assert( 1 === count( $tool_events ), 'Paused execution should record one tool_call event.' );
wp_agent_code_gate_assert( 'awaiting_confirmation' === ( $tool_events[0]['status'] ?? '' ), 'Pre-approval tool event should be awaiting_confirmation.' );
wp_agent_code_gate_assert( isset( $tool_events[0]['params']['code'] ) && false === strpos( (string) $tool_events[0]['params']['code'], $marker ), 'Tool telemetry should redact code content.' );

$sandbox_events_before = wp_agent_code_gate_recent_metadata( $run_id, 'sandbox_execution' );
wp_agent_code_gate_assert( 0 === count( $sandbox_events_before ), 'Sandbox execution should not occur before approval.' );

$decided = WPAgent_Confirmations::decide( (int) $confirmation['id'], $requester_id, 'approved' );
wp_agent_code_gate_assert( ! is_wp_error( $decided ) && WPAgent_Confirmations::STATUS_APPROVED === $decided['status'], 'Confirmation approval should succeed.' );

$result = WPAgent::get_agent()->execute_confirmed_tool( (int) $confirmation['id'] );
wp_agent_code_gate_assert( ! is_wp_error( $result ) && ! empty( $result['success'] ), 'Approved code execution should succeed: ' . wp_json_encode( $result ) );
wp_agent_code_gate_assert( 'php_cli' === ( $result['backend'] ?? '' ), 'Approved code execution should use the restricted PHP CLI backend.' );
wp_agent_code_gate_assert( false !== strpos( (string) ( $result['stdout'] ?? '' ), $marker . '-executed' ), 'Approved execution should produce the expected stdout marker.' );
wp_agent_code_gate_assert( 1 === count( $result['outputs']['imported'] ?? array() ), 'Approved execution should import exactly one allowed output.' );

$after_output = $workspace->read( 'approval-gate/approval-gate.txt' );
wp_agent_code_gate_assert( $marker . '-approved' === $after_output, 'Approved execution should import the output file into the persistent workspace.' );

$executed = WPAgent_Confirmations::get( (int) $confirmation['id'] );
wp_agent_code_gate_assert( $executed && WPAgent_Confirmations::STATUS_EXECUTED === $executed['status'], 'Approved confirmation should be marked executed.' );
wp_agent_code_gate_assert( ! empty( $executed['result']['success'] ), 'Executed confirmation should store the sandbox result.' );
wp_agent_code_gate_assert( 'queued' === (string) WPAgent_Runs::get( $run_id )->status, 'Run should be requeued after confirmed code execution.' );

$tool_message = wp_agent_code_gate_latest_tool_message( $conversation_id );
wp_agent_code_gate_assert( $tool_message && false !== strpos( (string) $tool_message['content'], $marker . '-executed' ), 'Confirmed execution should append the tool result message.' );
wp_agent_code_gate_assert( false !== strpos( (string) $tool_message['tool_results'], 'call_execute_code_gate' ), 'Tool result message should preserve the tool call ID.' );

$event_types = wp_list_pluck( WPAgent_Run_Events::recent( $run_id, 50 ), 'event_type' );
wp_agent_code_gate_assert( in_array( 'awaiting_confirmation', $event_types, true ), 'Run timeline should include awaiting_confirmation.' );
wp_agent_code_gate_assert( in_array( 'sandbox_execution', $event_types, true ), 'Run timeline should include sandbox_execution after approval.' );
wp_agent_code_gate_assert( in_array( 'confirmation_executed', $event_types, true ), 'Run timeline should include confirmation_executed after approval.' );

$confirmed_tool_events = wp_agent_code_gate_recent_metadata( $run_id, 'tool_call' );
$latest_tool_event     = end( $confirmed_tool_events );
wp_agent_code_gate_assert( ! empty( $latest_tool_event['confirmed'] ), 'Confirmed tool event should be marked confirmed.' );
wp_agent_code_gate_assert( 'success' === ( $latest_tool_event['status'] ?? '' ), 'Confirmed tool event should record success.' );

$summary = array(
	'success'            => true,
	'run_id'             => (int) $run_id,
	'confirmation_id'    => (int) $confirmation['id'],
	'backend'            => $result['backend'],
	'preapproval_status' => $first_step['status'],
	'postapproval_run'   => WPAgent_Runs::get( $run_id )->status,
	'event_types'        => array_values( array_unique( $event_types ) ),
);

wp_agent_code_gate_cleanup();

echo wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
