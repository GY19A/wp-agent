<?php
/**
 * WP Agent Skill permission tool gate checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skill-permission-tool-gate.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This Skill permission gate script must run through WP-CLI.\n" );
	exit( 1 );
}

global $wpdb;

$GLOBALS['wp_agent_skill_gate_previous'] = array(
	'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
	'model'          => WPAgent::get_option( 'meowl_model', '' ),
	'mode'           => get_option( 'wp_agent_mode', 'author' ),
	'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
	'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$GLOBALS['wp_agent_skill_gate_filter']           = null;
$GLOBALS['wp_agent_skill_gate_schedule_id']      = 0;
$GLOBALS['wp_agent_skill_gate_run_id']           = 0;
$GLOBALS['wp_agent_skill_gate_conversation_id']  = 0;
$GLOBALS['wp_agent_skill_gate_post_id']          = 0;
$GLOBALS['wp_agent_skill_gate_skill_slug']       = '';
$GLOBALS['wp_agent_skill_gate_runtime_dir']      = '';
$GLOBALS['wp_agent_skill_gate_http_calls']       = 0;
$GLOBALS['wp_agent_skill_gate_tool_names']       = array();
$GLOBALS['wp_agent_skill_gate_manage_posts_enum'] = array();
$GLOBALS['wp_agent_skill_gate_cleaned']          = false;

function wp_agent_skill_gate_fail( $message ) {
	wp_agent_skill_gate_cleanup();
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	exit( 1 );
}

function wp_agent_skill_gate_assert( $condition, $message ) {
	if ( ! $condition ) {
		wp_agent_skill_gate_fail( $message );
	}
}

function wp_agent_skill_gate_admin_id() {
	$admin = get_user_by( 'login', 'admin' );
	return $admin ? (int) $admin->ID : 1;
}

function wp_agent_skill_gate_private_call( $method, $args = array() ) {
	$ref = new ReflectionMethod( 'WPAgent_Skills', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

function wp_agent_skill_gate_response( $message, $finish_reason = 'stop' ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( array(
			'id'      => 'chatcmpl-wp-agent-skill-gate',
			'object'  => 'chat.completion',
			'created' => time(),
			'model'   => 'wp-agent-skill-gate-model',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => $message,
					'finish_reason' => $finish_reason,
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 13,
				'completion_tokens' => 7,
				'total_tokens'      => 20,
			),
		) ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
}

function wp_agent_skill_gate_tool_call( $id, $name, $arguments ) {
	return array(
		'id'       => $id,
		'type'     => 'function',
		'function' => array(
			'name'      => $name,
			'arguments' => wp_json_encode( $arguments ),
		),
	);
}

function wp_agent_skill_gate_tool_message( $conversation_id, $tool_call_id ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wp_agent_messages
			 WHERE conversation_id = %d
			   AND role = 'tool'
			   AND tool_results LIKE %s
			 ORDER BY id DESC
			 LIMIT 1",
			(int) $conversation_id,
			'%' . $wpdb->esc_like( $tool_call_id ) . '%'
		),
		ARRAY_A
	);
}

function wp_agent_skill_gate_recent_tool_events( $run_id ) {
	$events = WPAgent_Run_Events::recent( $run_id, 50 );
	$out    = array();
	foreach ( $events as $event ) {
		if ( 'tool_call' !== ( $event['event_type'] ?? '' ) ) {
			continue;
		}
		$metadata = json_decode( (string) ( $event['metadata'] ?? '' ), true );
		$out[]    = is_array( $metadata ) ? $metadata : array();
	}
	return array_reverse( $out );
}

function wp_agent_skill_gate_cleanup() {
	global $wpdb;

	if ( ! empty( $GLOBALS['wp_agent_skill_gate_cleaned'] ) ) {
		return;
	}
	$GLOBALS['wp_agent_skill_gate_cleaned'] = true;

	if ( null !== $GLOBALS['wp_agent_skill_gate_filter'] ) {
		remove_filter( 'pre_http_request', $GLOBALS['wp_agent_skill_gate_filter'], 10 );
		$GLOBALS['wp_agent_skill_gate_filter'] = null;
	}

	if ( (int) $GLOBALS['wp_agent_skill_gate_post_id'] > 0 && get_post( (int) $GLOBALS['wp_agent_skill_gate_post_id'] ) ) {
		wp_delete_post( (int) $GLOBALS['wp_agent_skill_gate_post_id'], true );
	}

	if ( (int) $GLOBALS['wp_agent_skill_gate_schedule_id'] > 0 ) {
		WPAgent_Schedules::delete( (int) $GLOBALS['wp_agent_skill_gate_schedule_id'] );
	}

	if ( (int) $GLOBALS['wp_agent_skill_gate_run_id'] > 0 ) {
		$wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => (int) $GLOBALS['wp_agent_skill_gate_run_id'] ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => (int) $GLOBALS['wp_agent_skill_gate_run_id'] ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => (int) $GLOBALS['wp_agent_skill_gate_run_id'] ), array( '%d' ) );
	}

	if ( (int) $GLOBALS['wp_agent_skill_gate_conversation_id'] > 0 ) {
		$wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => (int) $GLOBALS['wp_agent_skill_gate_conversation_id'] ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => (int) $GLOBALS['wp_agent_skill_gate_conversation_id'] ), array( '%d' ) );
	}

	if ( '' !== $GLOBALS['wp_agent_skill_gate_skill_slug'] ) {
		$wpdb->delete(
			$wpdb->prefix . 'wp_agent_skills',
			array(
				'user_id' => 1,
				'slug'    => $GLOBALS['wp_agent_skill_gate_skill_slug'],
			),
			array( '%d', '%s' )
		);
	}
	if ( '' !== $GLOBALS['wp_agent_skill_gate_runtime_dir'] ) {
		wp_agent_skill_gate_private_call( 'delete_runtime_dir', array( $GLOBALS['wp_agent_skill_gate_runtime_dir'] ) );
	}

	$wpdb->delete( $wpdb->prefix . 'wp_agent_usage', array( 'model' => 'wp-agent-skill-gate-model' ), array( '%s' ) );

	$previous = $GLOBALS['wp_agent_skill_gate_previous'];
	update_option( 'wp_agent_mode', $previous['mode'] );
	WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
	WPAgent::update_option( 'meowl_model', $previous['model'] );
	if ( $previous['budget_exists'] ) {
		update_option( 'wp_agent_monthly_budget', $previous['monthly_budget'] );
	} else {
		delete_option( 'wp_agent_monthly_budget' );
	}
	WPAgent_Roles::ensure();
}

register_shutdown_function( 'wp_agent_skill_gate_cleanup' );

$user_id = wp_agent_skill_gate_admin_id();
wp_set_current_user( $user_id );
update_option( 'wp_agent_mode', 'administrator' );
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-skill-gate-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-skill-gate-model' );
WPAgent::update_option( 'monthly_budget', 0 );
WPAgent_Roles::ensure();

$stamp = strtolower( wp_generate_password( 6, false, false ) );
$slug  = 'skill-permission-gate-' . $stamp;
$GLOBALS['wp_agent_skill_gate_skill_slug'] = $slug;

$skill = WPAgent_Skills::save( $user_id, array(
	'name'        => 'Skill Permission Gate Fixture',
	'slug'        => $slug,
	'description' => 'Fixture Skill that only allows post creation.',
	'permissions' => array(
		'tools'          => array( 'manage_posts.create' ),
		'network'        => false,
		'code_execution' => false,
	),
	'body'        => "## Workflow\n\nCreate exactly one draft post. Do not create categories, fetch the web, generate images, or run code.\n",
	'visibility'  => 'private',
) );
wp_agent_skill_gate_assert( ! is_wp_error( $skill ), is_wp_error( $skill ) ? $skill->get_error_message() : 'Fixture Skill should save.' );

$manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $slug );
wp_agent_skill_gate_assert( ! is_wp_error( $manifest ), is_wp_error( $manifest ) ? $manifest->get_error_message() : 'Fixture Skill runtime manifest should exist.' );
$GLOBALS['wp_agent_skill_gate_runtime_dir'] = $manifest['dir'];
wp_agent_skill_gate_assert( in_array( 'manage_posts.create', $manifest['lock']['permissions']['tools'] ?? array(), true ), 'Runtime lock should persist declared tool permissions.' );
wp_agent_skill_gate_assert( false !== strpos( $manifest['skill_md'], 'permissions:' ), 'Runtime SKILL.md should persist permissions frontmatter.' );

$permissions = WPAgent_Skills::permissions_for_skill( $user_id, $slug );
wp_agent_skill_gate_assert( in_array( 'manage_posts.create', $permissions['tools'] ?? array(), true ), 'permissions_for_skill should return the declared action-level tool.' );
wp_agent_skill_gate_assert( false === ( $permissions['network'] ?? true ), 'permissions_for_skill should retain network=false.' );
wp_agent_skill_gate_assert( false === ( $permissions['code_execution'] ?? true ), 'permissions_for_skill should retain code_execution=false.' );

$blocked_term_slug = 'skill-permission-blocked-' . $stamp;
$post_title        = 'Skill Permission Gate Draft ' . $stamp;

$GLOBALS['wp_agent_skill_gate_filter'] = function( $preempt, $parsed_args, $url ) use ( $blocked_term_slug, $post_title ) {
	if ( false === strpos( (string) $url, '/chat/completions' ) ) {
		return $preempt;
	}

	$GLOBALS['wp_agent_skill_gate_http_calls']++;
	$request = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
	$tools   = is_array( $request['tools'] ?? null ) ? $request['tools'] : array();
	$GLOBALS['wp_agent_skill_gate_tool_names'] = array_map(
		function( $tool ) {
			return (string) ( $tool['function']['name'] ?? '' );
		},
		$tools
	);
	foreach ( $tools as $tool ) {
		if ( 'manage_posts' === ( $tool['function']['name'] ?? '' ) ) {
			$GLOBALS['wp_agent_skill_gate_manage_posts_enum'] = (array) ( $tool['function']['parameters']['properties']['action']['enum'] ?? array() );
		}
	}

	if ( 1 === (int) $GLOBALS['wp_agent_skill_gate_http_calls'] ) {
		return wp_agent_skill_gate_response(
			array(
				'role'       => 'assistant',
				'content'    => 'Testing bound Skill tool permissions.',
				'tool_calls' => array(
					wp_agent_skill_gate_tool_call( 'call_blocked_taxonomy', 'manage_taxonomies', array(
						'action'   => 'create',
						'taxonomy' => 'category',
						'name'     => 'Blocked Skill Permission Category',
						'slug'     => $blocked_term_slug,
					) ),
					wp_agent_skill_gate_tool_call( 'call_allowed_post', 'manage_posts', array(
						'action'  => 'create',
						'title'   => $post_title,
						'content' => '<p>Draft created by the allowed manage_posts.create Skill permission.</p>',
						'status'  => 'draft',
					) ),
				),
			),
			'tool_calls'
		);
	}

	return wp_agent_skill_gate_response( array(
		'role'    => 'assistant',
		'content' => 'Skill permission gate complete.',
	) );
};
add_filter( 'pre_http_request', $GLOBALS['wp_agent_skill_gate_filter'], 10, 3 );

$schedule_id = WPAgent_Schedules::create(
	$user_id,
	'Create one draft post through the bound Skill.',
	'minutes',
	null,
	null,
	5,
	$slug
);
wp_agent_skill_gate_assert( $schedule_id > 0, 'Schedule should be created with the restricted Skill.' );
$GLOBALS['wp_agent_skill_gate_schedule_id'] = (int) $schedule_id;

$queued = WPAgent_Schedules::run( $schedule_id );
wp_agent_skill_gate_assert( ! empty( $queued['ok'] ) && ! empty( $queued['run_id'] ), 'Schedule should queue a run.' );
$run_id = (int) $queued['run_id'];
$GLOBALS['wp_agent_skill_gate_run_id'] = $run_id;

$run = WPAgent_Runs::get( $run_id );
wp_agent_skill_gate_assert( $run, 'Queued run should exist.' );
$GLOBALS['wp_agent_skill_gate_conversation_id'] = (int) $run->conversation_id;

$bound = WPAgent_Schedules::skill_for_run( $run_id );
wp_agent_skill_gate_assert( is_array( $bound ) && $slug === ( $bound['skill_slug'] ?? '' ), 'Run should resolve its bound Skill.' );

$first = WPAgent_Worker::run_once( $run_id );
wp_agent_skill_gate_assert( 'running' === ( $first['status'] ?? '' ), 'First step should process tool calls and leave the run running.' );

$second = WPAgent_Worker::run_once( $run_id );
wp_agent_skill_gate_assert( 'done' === ( $second['status'] ?? '' ), 'Second step should finish after the model sees tool results.' );

$visible_tools = $GLOBALS['wp_agent_skill_gate_tool_names'];
sort( $visible_tools );
wp_agent_skill_gate_assert( array( 'manage_posts' ) === $visible_tools, 'Model-visible tools should be reduced to manage_posts only: ' . wp_json_encode( $visible_tools ) );
wp_agent_skill_gate_assert( array( 'create' ) === array_values( $GLOBALS['wp_agent_skill_gate_manage_posts_enum'] ), 'manage_posts action enum should be reduced to create only.' );

$blocked_term = get_term_by( 'slug', $blocked_term_slug, 'category' );
wp_agent_skill_gate_assert( ! $blocked_term || is_wp_error( $blocked_term ), 'Blocked taxonomy tool call should not create a category.' );

$posts = get_posts( array(
	'post_type'      => 'post',
	'post_status'    => 'draft',
	'title'          => $post_title,
	'posts_per_page' => 1,
) );
wp_agent_skill_gate_assert( ! empty( $posts ), 'Allowed manage_posts.create call should create a draft post.' );
$GLOBALS['wp_agent_skill_gate_post_id'] = (int) $posts[0]->ID;

$blocked_message = wp_agent_skill_gate_tool_message( $GLOBALS['wp_agent_skill_gate_conversation_id'], 'call_blocked_taxonomy' );
wp_agent_skill_gate_assert( $blocked_message && false !== strpos( (string) $blocked_message['content'], 'skill_permission_denied' ), 'Blocked tool result should explain Skill permission denial.' );

$allowed_message = wp_agent_skill_gate_tool_message( $GLOBALS['wp_agent_skill_gate_conversation_id'], 'call_allowed_post' );
wp_agent_skill_gate_assert( $allowed_message && false !== strpos( (string) $allowed_message['content'], 'post_id' ), 'Allowed tool result should include post_id.' );

$tool_events = wp_agent_skill_gate_recent_tool_events( $run_id );
$denied_event = null;
foreach ( $tool_events as $event ) {
	if ( ! empty( $event['skill_permission_denied'] ) ) {
		$denied_event = $event;
		break;
	}
}
wp_agent_skill_gate_assert( $denied_event && 'manage_taxonomies' === ( $denied_event['tool'] ?? '' ), 'Run events should record the denied tool call.' );
wp_agent_skill_gate_assert( $slug === ( $denied_event['skill_slug'] ?? '' ), 'Denied run event should include the Skill slug.' );

$result = array(
	'success'             => true,
	'skill_slug'          => $slug,
	'run_id'              => $run_id,
	'visible_tools'       => $visible_tools,
	'manage_posts_actions' => array_values( $GLOBALS['wp_agent_skill_gate_manage_posts_enum'] ),
	'post_id'             => (int) $GLOBALS['wp_agent_skill_gate_post_id'],
	'blocked_tool'        => $denied_event['tool'] ?? '',
);

wp_agent_skill_gate_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
