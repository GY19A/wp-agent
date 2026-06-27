<?php
/**
 * WP Agent Skill-bound schedule policy diagnostics checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/schedule-skill-policy-diagnostics.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This Skill schedule diagnostics script must run through WP-CLI.\n" );
	exit( 1 );
}

global $wpdb;

$GLOBALS['wp_agent_skill_policy_diag'] = array(
	'schedule_id'     => 0,
	'run_id'          => 0,
	'conversation_id' => 0,
	'skill_slug'      => '',
	'runtime_dir'     => '',
	'cleaned'         => false,
);

function wp_agent_skill_policy_diag_fail( $message ) {
	wp_agent_skill_policy_diag_cleanup();
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	exit( 1 );
}

function wp_agent_skill_policy_diag_assert( $condition, $message ) {
	if ( ! $condition ) {
		wp_agent_skill_policy_diag_fail( $message );
	}
}

function wp_agent_skill_policy_diag_admin_id() {
	$admin = get_user_by( 'login', 'admin' );
	return $admin ? (int) $admin->ID : 1;
}

function wp_agent_skill_policy_diag_private_call( $method, $args = array() ) {
	$ref = new ReflectionMethod( 'WPAgent_Skills', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

function wp_agent_skill_policy_diag_cleanup() {
	global $wpdb;

	if ( ! empty( $GLOBALS['wp_agent_skill_policy_diag']['cleaned'] ) ) {
		return;
	}
	$GLOBALS['wp_agent_skill_policy_diag']['cleaned'] = true;

	$schedule_id = (int) $GLOBALS['wp_agent_skill_policy_diag']['schedule_id'];
	$run_id      = (int) $GLOBALS['wp_agent_skill_policy_diag']['run_id'];
	$conv_id     = (int) $GLOBALS['wp_agent_skill_policy_diag']['conversation_id'];
	$slug        = (string) $GLOBALS['wp_agent_skill_policy_diag']['skill_slug'];
	$runtime_dir = (string) $GLOBALS['wp_agent_skill_policy_diag']['runtime_dir'];

	if ( $schedule_id > 0 ) {
		WPAgent_Schedules::delete( $schedule_id );
	}
	if ( $run_id > 0 ) {
		$wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => $run_id ), array( '%d' ) );
	}
	if ( $conv_id > 0 ) {
		$wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => $conv_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => $conv_id ), array( '%d' ) );
	}
	if ( '' !== $slug ) {
		$wpdb->delete(
			$wpdb->prefix . 'wp_agent_skills',
			array(
				'user_id' => wp_agent_skill_policy_diag_admin_id(),
				'slug'    => $slug,
			),
			array( '%d', '%s' )
		);
	}
	if ( '' !== $runtime_dir ) {
		wp_agent_skill_policy_diag_private_call( 'delete_runtime_dir', array( $runtime_dir ) );
	}
}

function wp_agent_skill_policy_diag_find_recent_run( array $runs, $run_id ) {
	foreach ( $runs as $run ) {
		if ( (int) ( $run['run_id'] ?? 0 ) === (int) $run_id ) {
			return $run;
		}
	}
	return null;
}

register_shutdown_function( 'wp_agent_skill_policy_diag_cleanup' );

$user_id = wp_agent_skill_policy_diag_admin_id();
wp_set_current_user( $user_id );

$stamp = strtolower( wp_generate_password( 6, false, false ) );
$slug  = 'schedule-policy-diag-' . $stamp;
$GLOBALS['wp_agent_skill_policy_diag']['skill_slug'] = $slug;

$skill = WPAgent_Skills::save( $user_id, array(
	'name'        => 'Schedule Policy Diagnostics Fixture',
	'slug'        => $slug,
	'description' => 'Fixture Skill for schedule policy diagnostics.',
	'permissions' => array(
		'tools'          => array( 'posts.create', 'web.search' ),
		'network'        => false,
		'code_execution' => false,
	),
	'body'        => "## Workflow\n\nCreate one draft post. Do not fetch the network or execute code.\n",
	'visibility'  => 'private',
) );
wp_agent_skill_policy_diag_assert( ! is_wp_error( $skill ), is_wp_error( $skill ) ? $skill->get_error_message() : 'Fixture Skill should save.' );

$manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $slug );
wp_agent_skill_policy_diag_assert( ! is_wp_error( $manifest ), is_wp_error( $manifest ) ? $manifest->get_error_message() : 'Fixture Skill runtime manifest should exist.' );
$GLOBALS['wp_agent_skill_policy_diag']['runtime_dir'] = (string) $manifest['dir'];

wp_agent_skill_policy_diag_assert(
	'manage_posts' === WPAgent_Skills::tool_name_from_permission_spec( 'posts.create' ),
	'Permission helper should resolve posts.create to manage_posts.'
);
wp_agent_skill_policy_diag_assert(
	'create' === WPAgent_Skills::action_from_permission_spec( 'posts.create' ),
	'Permission helper should resolve the create action.'
);

$schedule_id = WPAgent_Schedules::create(
	$user_id,
	'Create one draft post through the schedule policy diagnostics fixture.',
	'minutes',
	null,
	null,
	5,
	$slug
);
wp_agent_skill_policy_diag_assert( $schedule_id > 0, 'Schedule should be created with the bound Skill.' );
$GLOBALS['wp_agent_skill_policy_diag']['schedule_id'] = (int) $schedule_id;

$queued = WPAgent_Schedules::run( $schedule_id );
wp_agent_skill_policy_diag_assert( ! empty( $queued['ok'] ) && ! empty( $queued['run_id'] ), 'Schedule should queue a durable run.' );
$run_id = (int) $queued['run_id'];
$GLOBALS['wp_agent_skill_policy_diag']['run_id'] = $run_id;

$run = WPAgent_Runs::get( $run_id );
wp_agent_skill_policy_diag_assert( $run, 'Queued run should exist.' );
$GLOBALS['wp_agent_skill_policy_diag']['conversation_id'] = (int) $run->conversation_id;

$policy = WPAgent_Schedules::skill_policy_for_run( $run_id );
wp_agent_skill_policy_diag_assert( ! empty( $policy['bound'] ), 'Run policy should show a bound Skill.' );
wp_agent_skill_policy_diag_assert( ! empty( $policy['restricted'] ), 'Run policy should be restricted by declared permissions.' );
wp_agent_skill_policy_diag_assert( $slug === ( $policy['skill_slug'] ?? '' ), 'Run policy should expose the Skill slug.' );
wp_agent_skill_policy_diag_assert( (int) $schedule_id === (int) ( $policy['schedule_id'] ?? 0 ), 'Run policy should expose the schedule ID.' );
wp_agent_skill_policy_diag_assert( in_array( 'posts.create', $policy['allowed_tools'] ?? array(), true ), 'Run policy should expose normalized allowed tool specs.' );
wp_agent_skill_policy_diag_assert( false === ( $policy['network'] ?? true ), 'Run policy should expose network=false.' );
wp_agent_skill_policy_diag_assert( false === ( $policy['code_execution'] ?? true ), 'Run policy should expose code_execution=false.' );

wp_agent_skill_policy_diag_private_call( 'delete_runtime_dir', array( $GLOBALS['wp_agent_skill_policy_diag']['runtime_dir'] ) );
$GLOBALS['wp_agent_skill_policy_diag']['runtime_dir'] = '';

$db_permissions = WPAgent_Skills::permissions_for_skill( $user_id, $slug );
wp_agent_skill_policy_diag_assert( in_array( 'posts.create', $db_permissions['tools'] ?? array(), true ), 'DB-indexed permissions should survive local runtime mirror loss.' );
wp_agent_skill_policy_diag_assert( false === ( $db_permissions['network'] ?? true ), 'DB-indexed permissions should retain network=false.' );
wp_agent_skill_policy_diag_assert( false === ( $db_permissions['code_execution'] ?? true ), 'DB-indexed permissions should retain code_execution=false.' );

$db_policy = WPAgent_Schedules::skill_policy_for_run( $run_id );
wp_agent_skill_policy_diag_assert( ! empty( $db_policy['restricted'] ), 'Run policy should remain restricted after runtime mirror loss.' );
wp_agent_skill_policy_diag_assert( ! empty( $db_policy['permissions_found'] ), 'Run policy should report permissions found from the DB index after runtime mirror loss.' );
wp_agent_skill_policy_diag_assert( in_array( 'posts.create', $db_policy['allowed_tools'] ?? array(), true ), 'Run policy should retain allowed tool specs after runtime mirror loss.' );

$diagnostics = WPAgent_Diagnostics::runtime();
$schedules   = $diagnostics['schedules'] ?? array();
$recent      = $schedules['recent_bound_skill_runs'] ?? array();
$entry       = wp_agent_skill_policy_diag_find_recent_run( is_array( $recent ) ? $recent : array(), $run_id );

wp_agent_skill_policy_diag_assert( (int) ( $schedules['skill_bound_count'] ?? 0 ) >= 1, 'Diagnostics should count Skill-bound schedules.' );
wp_agent_skill_policy_diag_assert( (int) ( $schedules['skill_bound_recent_checked'] ?? 0 ) >= 1, 'Diagnostics should inspect recent Skill-bound schedules.' );
wp_agent_skill_policy_diag_assert( is_array( $entry ), 'Diagnostics should include the queued Skill-bound run.' );
wp_agent_skill_policy_diag_assert( $slug === ( $entry['skill_slug'] ?? '' ), 'Diagnostics should expose the bound Skill slug.' );
wp_agent_skill_policy_diag_assert( ! empty( $entry['skill_found'] ), 'Diagnostics should confirm the bound Skill exists.' );
wp_agent_skill_policy_diag_assert( ! empty( $entry['permissions_found'] ), 'Diagnostics should confirm permissions were loaded.' );
wp_agent_skill_policy_diag_assert( ! empty( $entry['restricted'] ), 'Diagnostics should mark the bound Skill policy as restricted.' );
wp_agent_skill_policy_diag_assert( in_array( 'posts.create', $entry['allowed_tools'] ?? array(), true ), 'Diagnostics should expose allowed tool specs.' );
wp_agent_skill_policy_diag_assert( false === ( $entry['network'] ?? true ), 'Diagnostics should expose network=false.' );
wp_agent_skill_policy_diag_assert( false === ( $entry['code_execution'] ?? true ), 'Diagnostics should expose code_execution=false.' );
wp_agent_skill_policy_diag_assert( ! array_key_exists( 'body', $entry ), 'Diagnostics must not expose Skill body content.' );

$result = array(
	'success'       => true,
	'skill_slug'    => $slug,
	'schedule_id'   => (int) $schedule_id,
	'run_id'        => $run_id,
	'allowed_tools' => $entry['allowed_tools'],
	'network'       => $entry['network'],
	'code_execution' => $entry['code_execution'],
	'db_fallback'   => true,
);

wp_agent_skill_policy_diag_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
