<?php
/**
 * Host-side final no-live acceptance contract.
 *
 * Runs the local gates that must pass before any live GitHub/API or multi-hour
 * daemon soak is approved. This script does not call GitHub or the AI gateway.
 *
 * Run from the host:
 * php tests/final-no-live-acceptance-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final no-live acceptance contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_no_live_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_no_live_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_no_live_contract_fail( $message, $details );
	}
}

function wp_agent_no_live_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );

	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_no_live_contract_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_no_live_contract_run_case( $name, $args ) {
	$result = wp_agent_no_live_contract_command( $args );
	wp_agent_no_live_contract_assert( 0 === $result['status'], $name . ' failed.', array(
		'status' => $result['status'],
		'output' => $result['output'],
	) );

	$json = wp_agent_no_live_contract_json( $result['output'] );
	wp_agent_no_live_contract_assert( is_array( $json ), $name . ' should print a JSON result.', array(
		'output' => $result['output'],
	) );
	wp_agent_no_live_contract_assert( true === (bool) ( $json['success'] ?? false ), $name . ' JSON result should report success=true.', array(
		'json' => $json,
	) );

	return $json;
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_no_live_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$php = PHP_BINARY;
$cases = array();

$git_hygiene = wp_agent_no_live_contract_run_case( 'git hygiene contract', array(
	$php,
	$plugin_dir . '/tests/git-hygiene-contract.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $git_hygiene['remote_push'] ?? true ), 'Git hygiene contract must report remote_push=false.', $git_hygiene );
wp_agent_no_live_contract_assert( true === (bool) ( $git_hygiene['remote_push_disabled'] ?? false ), 'Git hygiene contract must disable remote push URLs.', $git_hygiene );
wp_agent_no_live_contract_assert( false === (bool) ( $git_hygiene['remote_credentials'] ?? true ), 'Git hygiene contract must not expose embedded remote credentials.', $git_hygiene );
$cases['git_hygiene_contract'] = array(
	'remote_count'         => (int) ( $git_hygiene['remote_count'] ?? 0 ),
	'remote_push_disabled' => (bool) ( $git_hygiene['remote_push_disabled'] ?? false ),
	'remote_urls_redacted' => (bool) ( $git_hygiene['remote_urls_redacted'] ?? false ),
	'upstream'             => (string) ( $git_hygiene['upstream'] ?? '' ),
	'ahead_count'          => (int) ( $git_hygiene['ahead_count'] ?? 0 ),
);

$source = wp_agent_no_live_contract_run_case( 'source research inventory', array(
	$php,
	$plugin_dir . '/tests/source-research-inventory.php',
) );
wp_agent_no_live_contract_assert( (int) ( $source['repo_count'] ?? 0 ) >= 10, 'Source research inventory should include required external framework clones.', $source );
$cases['source_research_inventory'] = array(
	'repo_count' => (int) ( $source['repo_count'] ?? 0 ),
);

$source_notes = wp_agent_no_live_contract_run_case( 'source research notes contract', array(
	$php,
	$plugin_dir . '/tests/source-research-notes-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $source_notes['repo_count'] ?? 0 ) >= 10, 'Source research notes contract should cover all reference clones.', $source_notes );
wp_agent_no_live_contract_assert( false === (bool) ( $source_notes['github_calls'] ?? true ), 'Source research notes contract must not call GitHub.', $source_notes );
$cases['source_research_notes_contract'] = array(
	'repo_count'    => (int) ( $source_notes['repo_count'] ?? 0 ),
	'notes_checked' => (int) ( $source_notes['notes_checked'] ?? 0 ),
);

$ui_evidence = wp_agent_no_live_contract_run_case( 'UI Playwright evidence contract', array(
	$php,
	$plugin_dir . '/tests/ui-playwright-evidence-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $ui_evidence['logs_checked'] ?? 0 ) >= 6, 'UI Playwright evidence contract should cover the responsive, daemon, Chat Stop, UX quality, queue status, and Stop availability logs.', $ui_evidence );
wp_agent_no_live_contract_assert( (int) ( $ui_evidence['screenshots_checked'] ?? 0 ) >= 26, 'UI Playwright evidence contract should cover desktop, mobile, Chat Stop, UX gate, queue status, and Stop availability screenshots.', $ui_evidence );
wp_agent_no_live_contract_assert( (int) ( $ui_evidence['desktop_mobile_pairs'] ?? 0 ) >= 13, 'UI Playwright evidence contract should cover all required desktop/mobile evidence pairs.', $ui_evidence );
wp_agent_no_live_contract_assert( true === (bool) ( $ui_evidence['overflow_guard'] ?? false ), 'UI Playwright evidence contract should keep overflow evidence visible.', $ui_evidence );
wp_agent_no_live_contract_assert( true === (bool) ( $ui_evidence['console_guard'] ?? false ), 'UI Playwright evidence contract should keep console evidence visible.', $ui_evidence );
wp_agent_no_live_contract_assert( true === (bool) ( $ui_evidence['chat_stop_playwright'] ?? false ), 'UI Playwright evidence contract should keep Chat Stop Playwright evidence visible.', $ui_evidence );
wp_agent_no_live_contract_assert( true === (bool) ( $ui_evidence['chat_stop_availability_playwright'] ?? false ), 'UI Playwright evidence contract should keep Chat Stop availability Playwright evidence visible.', $ui_evidence );
wp_agent_no_live_contract_assert( true === (bool) ( $ui_evidence['composer_unlocked_guard'] ?? false ), 'UI Playwright evidence contract should prove the composer remains usable.', $ui_evidence );
$cases['ui_playwright_evidence_contract'] = array(
	'logs_checked'        => (int) ( $ui_evidence['logs_checked'] ?? 0 ),
	'screenshots_checked' => (int) ( $ui_evidence['screenshots_checked'] ?? 0 ),
	'desktop_mobile_pairs' => (int) ( $ui_evidence['desktop_mobile_pairs'] ?? 0 ),
	'chat_stop_playwright' => (bool) ( $ui_evidence['chat_stop_playwright'] ?? false ),
	'chat_ux_quality_gate' => (bool) ( $ui_evidence['chat_ux_quality_gate'] ?? false ),
	'chat_queue_status_playwright' => (bool) ( $ui_evidence['chat_queue_status_playwright'] ?? false ),
	'chat_stop_availability_playwright' => (bool) ( $ui_evidence['chat_stop_availability_playwright'] ?? false ),
);

$chat_queue = wp_agent_no_live_contract_run_case( 'chat background queue Stop contract', array(
	$php,
	$plugin_dir . '/tests/chat-background-queue-stop-contract.php',
) );
wp_agent_no_live_contract_assert( true === (bool) ( $chat_queue['coverage']['input_not_poll_locked'] ?? false ), 'Chat queue contract must keep input usable while agent runs are active.', $chat_queue );
wp_agent_no_live_contract_assert( true === (bool) ( $chat_queue['coverage']['stop_visible_label'] ?? false ), 'Chat queue contract must keep the Stop control visibly labeled.', $chat_queue );
wp_agent_no_live_contract_assert( true === (bool) ( $chat_queue['coverage']['cancel_route'] ?? false ), 'Chat queue contract must expose a cancel route.', $chat_queue );
wp_agent_no_live_contract_assert( true === (bool) ( $chat_queue['coverage']['daemon_wake_on_send'] ?? false ), 'Chat queue contract must wake the background daemon on send.', $chat_queue );
wp_agent_no_live_contract_assert( true === (bool) ( $chat_queue['coverage']['queue_summary_rest'] ?? false ), 'Chat queue contract must expose REST queue summaries.', $chat_queue );
wp_agent_no_live_contract_assert( true === (bool) ( $chat_queue['coverage']['queue_position_status'] ?? false ), 'Chat queue contract must preserve queue position status copy.', $chat_queue );
wp_agent_no_live_contract_assert( true === (bool) ( $chat_queue['coverage']['js_status_clarity'] ?? false ), 'Chat queue contract must preserve clear status copy.', $chat_queue );
wp_agent_no_live_contract_assert( false === (bool) ( $chat_queue['ai_gateway_calls'] ?? true ), 'Chat queue contract must not call the AI gateway.', $chat_queue );
wp_agent_no_live_contract_assert( false === (bool) ( $chat_queue['github_calls'] ?? true ), 'Chat queue contract must not call GitHub.', $chat_queue );
$cases['chat_background_queue_stop_contract'] = array(
	'coverage' => $chat_queue['coverage'] ?? array(),
);

$ux_product = wp_agent_no_live_contract_run_case( 'UX product usability contract', array(
	$php,
	$plugin_dir . '/tests/ux-product-usability-contract.php',
) );
wp_agent_no_live_contract_assert( true === (bool) ( $ux_product['product_ux_gate'] ?? false ), 'UX product usability contract must keep UX as a blocking product gate.', $ux_product );
wp_agent_no_live_contract_assert( true === (bool) ( $ux_product['status_transparency'] ?? false ), 'UX product usability contract must require status transparency.', $ux_product );
wp_agent_no_live_contract_assert( true === (bool) ( $ux_product['interruptibility'] ?? false ), 'UX product usability contract must require interruptible active runs.', $ux_product );
wp_agent_no_live_contract_assert( true === (bool) ( $ux_product['queue_continuity'] ?? false ), 'UX product usability contract must require queued follow-up continuity.', $ux_product );
wp_agent_no_live_contract_assert( true === (bool) ( $ux_product['recovery_copy'] ?? false ), 'UX product usability contract must require recovery and failure copy.', $ux_product );
wp_agent_no_live_contract_assert( true === (bool) ( $ux_product['accessibility_affordance'] ?? false ), 'UX product usability contract must preserve accessibility affordances.', $ux_product );
wp_agent_no_live_contract_assert( true === (bool) ( $ux_product['responsive_overflow_guard'] ?? false ), 'UX product usability contract must preserve responsive overflow guards.', $ux_product );
wp_agent_no_live_contract_assert( true === (bool) ( $ux_product['composer_unlocked_guard'] ?? false ), 'UX product usability contract must prove the composer remains unlocked during background work.', $ux_product );
wp_agent_no_live_contract_assert( false === (bool) ( $ux_product['ai_gateway_calls'] ?? true ), 'UX product usability contract must not call the AI gateway.', $ux_product );
wp_agent_no_live_contract_assert( false === (bool) ( $ux_product['github_calls'] ?? true ), 'UX product usability contract must not call GitHub.', $ux_product );
$cases['ux_product_usability_contract'] = array(
	'marker_counts'             => $ux_product['marker_counts'] ?? array(),
	'product_ux_gate'           => (bool) ( $ux_product['product_ux_gate'] ?? false ),
	'status_transparency'       => (bool) ( $ux_product['status_transparency'] ?? false ),
	'interruptibility'          => (bool) ( $ux_product['interruptibility'] ?? false ),
	'queue_continuity'          => (bool) ( $ux_product['queue_continuity'] ?? false ),
	'recovery_copy'             => (bool) ( $ux_product['recovery_copy'] ?? false ),
	'accessibility_affordance'  => (bool) ( $ux_product['accessibility_affordance'] ?? false ),
	'responsive_overflow_guard' => (bool) ( $ux_product['responsive_overflow_guard'] ?? false ),
	'composer_unlocked_guard'   => (bool) ( $ux_product['composer_unlocked_guard'] ?? false ),
);

$plugin_independence = wp_agent_no_live_contract_run_case( 'plugin independence contract', array(
	$php,
	$plugin_dir . '/tests/plugin-independence-contract.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $plugin_independence['core_webserver_theme_modifications'] ?? true ), 'Plugin independence contract must reject core/webserver/theme modifications.', $plugin_independence );
wp_agent_no_live_contract_assert( false === (bool) ( $plugin_independence['custom_image_required'] ?? true ), 'Plugin independence contract must reject custom image requirements.', $plugin_independence );
wp_agent_no_live_contract_assert( false === (bool) ( $plugin_independence['sidecar_required'] ?? true ), 'Plugin independence contract must reject sidecar requirements.', $plugin_independence );
$cases['plugin_independence_contract'] = array(
	'root_violations'     => (int) ( $plugin_independence['root_violations'] ?? 0 ),
	'activation_hook'     => (bool) ( $plugin_independence['activation_hook'] ?? false ),
	'deactivation_hook'   => (bool) ( $plugin_independence['deactivation_hook'] ?? false ),
	'uninstall_coverage'  => (bool) ( $plugin_independence['uninstall_coverage'] ?? false ),
);

$security_boundary = wp_agent_no_live_contract_run_case( 'security boundary evidence contract', array(
	$php,
	$plugin_dir . '/tests/security-boundary-evidence-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $security_boundary['evidence_scripts_checked'] ?? 0 ) >= 8, 'Security boundary evidence contract should inspect required security regression scripts.', $security_boundary );
wp_agent_no_live_contract_assert( (int) ( $security_boundary['implementation_files_checked'] ?? 0 ) >= 8, 'Security boundary evidence contract should inspect required security implementation files.', $security_boundary );
wp_agent_no_live_contract_assert( true === (bool) ( $security_boundary['coverage']['code_execution_default_off'] ?? false ), 'Security boundary evidence contract must prove code execution is default-off.', $security_boundary );
wp_agent_no_live_contract_assert( true === (bool) ( $security_boundary['coverage']['live_gate_fail_closed'] ?? false ), 'Security boundary evidence contract must prove live gates fail closed.', $security_boundary );
wp_agent_no_live_contract_assert( false === (bool) ( $security_boundary['ai_gateway_calls'] ?? true ), 'Security boundary evidence contract must not call the AI gateway.', $security_boundary );
wp_agent_no_live_contract_assert( false === (bool) ( $security_boundary['github_calls'] ?? true ), 'Security boundary evidence contract must not call GitHub.', $security_boundary );
$cases['security_boundary_evidence_contract'] = array(
	'evidence_scripts_checked'     => (int) ( $security_boundary['evidence_scripts_checked'] ?? 0 ),
	'implementation_files_checked' => (int) ( $security_boundary['implementation_files_checked'] ?? 0 ),
	'required_markers'             => (int) ( $security_boundary['required_markers'] ?? 0 ),
);

$daemon_lifecycle = wp_agent_no_live_contract_run_case( 'daemon lifecycle evidence contract', array(
	$php,
	$plugin_dir . '/tests/daemon-lifecycle-evidence-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $daemon_lifecycle['deterministic_tests_checked'] ?? 0 ) >= 8, 'Daemon lifecycle evidence contract should inspect required deterministic daemon tests.', $daemon_lifecycle );
wp_agent_no_live_contract_assert( (int) ( $daemon_lifecycle['live_harnesses_checked'] ?? 0 ) >= 2, 'Daemon lifecycle evidence contract should inspect required live daemon harnesses.', $daemon_lifecycle );
wp_agent_no_live_contract_assert( true === (bool) ( $daemon_lifecycle['coverage']['memory_rotation'] ?? false ), 'Daemon lifecycle evidence contract must prove memory rotation evidence.', $daemon_lifecycle );
wp_agent_no_live_contract_assert( true === (bool) ( $daemon_lifecycle['coverage']['watchdog_recovery'] ?? false ), 'Daemon lifecycle evidence contract must prove watchdog recovery evidence.', $daemon_lifecycle );
wp_agent_no_live_contract_assert( false === (bool) ( $daemon_lifecycle['daemon_process_started'] ?? true ), 'Daemon lifecycle evidence contract must not start daemon processes.', $daemon_lifecycle );
wp_agent_no_live_contract_assert( false === (bool) ( $daemon_lifecycle['ai_gateway_calls'] ?? true ), 'Daemon lifecycle evidence contract must not call the AI gateway.', $daemon_lifecycle );
wp_agent_no_live_contract_assert( false === (bool) ( $daemon_lifecycle['github_calls'] ?? true ), 'Daemon lifecycle evidence contract must not call GitHub.', $daemon_lifecycle );
$cases['daemon_lifecycle_evidence_contract'] = array(
	'deterministic_tests_checked' => (int) ( $daemon_lifecycle['deterministic_tests_checked'] ?? 0 ),
	'live_harnesses_checked'      => (int) ( $daemon_lifecycle['live_harnesses_checked'] ?? 0 ),
	'required_markers'            => (int) ( $daemon_lifecycle['required_markers'] ?? 0 ),
);

$skills_store = wp_agent_no_live_contract_run_case( 'Skills Store lifecycle evidence contract', array(
	$php,
	$plugin_dir . '/tests/skills-store-lifecycle-evidence-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $skills_store['implementation_files_checked'] ?? 0 ) >= 7, 'Skills Store lifecycle evidence contract should inspect required implementation files.', $skills_store );
wp_agent_no_live_contract_assert( (int) ( $skills_store['deterministic_tests_checked'] ?? 0 ) >= 8, 'Skills Store lifecycle evidence contract should inspect required deterministic tests.', $skills_store );
wp_agent_no_live_contract_assert( (int) ( $skills_store['live_harnesses_checked'] ?? 0 ) >= 5, 'Skills Store lifecycle evidence contract should inspect live GitHub harness evidence.', $skills_store );
wp_agent_no_live_contract_assert( true === (bool) ( $skills_store['coverage']['placeholder_fail_closed'] ?? false ), 'Skills Store lifecycle evidence contract must prove placeholder fail-closed behavior.', $skills_store );
wp_agent_no_live_contract_assert( true === (bool) ( $skills_store['coverage']['token_redaction'] ?? false ), 'Skills Store lifecycle evidence contract must prove GitHub token redaction evidence.', $skills_store );
wp_agent_no_live_contract_assert( true === (bool) ( $skills_store['coverage']['rollback_recovery'] ?? false ), 'Skills Store lifecycle evidence contract must prove rollback recovery evidence.', $skills_store );
wp_agent_no_live_contract_assert( false === (bool) ( $skills_store['ai_gateway_calls'] ?? true ), 'Skills Store lifecycle evidence contract must not call the AI gateway.', $skills_store );
wp_agent_no_live_contract_assert( false === (bool) ( $skills_store['github_calls'] ?? true ), 'Skills Store lifecycle evidence contract must not call GitHub.', $skills_store );
$cases['skills_store_lifecycle_evidence_contract'] = array(
	'implementation_files_checked' => (int) ( $skills_store['implementation_files_checked'] ?? 0 ),
	'deterministic_tests_checked'  => (int) ( $skills_store['deterministic_tests_checked'] ?? 0 ),
	'live_harnesses_checked'       => (int) ( $skills_store['live_harnesses_checked'] ?? 0 ),
	'required_markers'             => (int) ( $skills_store['required_markers'] ?? 0 ),
);

$database_persistence = wp_agent_no_live_contract_run_case( 'database persistence contract', array(
	$php,
	$plugin_dir . '/tests/database-persistence-contract.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $database_persistence['plugin_database_dir'] ?? true ), 'Database persistence contract must keep database directories out of the plugin repository.', $database_persistence );
wp_agent_no_live_contract_assert( false === (bool) ( $database_persistence['ai_gateway_calls'] ?? true ), 'Database persistence contract must not call the AI gateway.', $database_persistence );
wp_agent_no_live_contract_assert( false === (bool) ( $database_persistence['github_calls'] ?? true ), 'Database persistence contract must not call GitHub.', $database_persistence );
$cases['database_persistence_contract'] = array(
	'dev_db_dir'        => (string) ( $database_persistence['dev_db_dir'] ?? '' ),
	'official_db_dir'   => (string) ( $database_persistence['official_db_dir'] ?? '' ),
	'dumps_dir'         => (string) ( $database_persistence['dumps_dir'] ?? '' ),
	'dev_db_files'      => (int) ( $database_persistence['dev_db_files'] ?? 0 ),
	'official_db_files' => (int) ( $database_persistence['official_db_files'] ?? 0 ),
	'dump_files'        => (int) ( $database_persistence['dump_files'] ?? 0 ),
);

$goals_evidence = wp_agent_no_live_contract_run_case( 'goals evidence contract', array(
	$php,
	$plugin_dir . '/tests/goals-evidence-contract.php',
) );
wp_agent_no_live_contract_assert( array( 6, 9 ) === array_values( $goals_evidence['partial_rows'] ?? array() ), 'Goals evidence contract should report only #6 and #9 as partial.', $goals_evidence );
$cases['goals_evidence_contract'] = array(
	'test_script_refs'     => (int) ( $goals_evidence['test_script_refs'] ?? 0 ),
	'design_evidence_refs' => (int) ( $goals_evidence['design_evidence_refs'] ?? 0 ),
	'partial_rows'         => $goals_evidence['partial_rows'] ?? array(),
);

$official = wp_agent_no_live_contract_run_case( 'official container contract', array(
	$php,
	$plugin_dir . '/tests/official-container-contract.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $official['agentd_sidecar'] ?? true ), 'Official stack must remain sidecar-free.', $official );
wp_agent_no_live_contract_assert( 'wordpress:php8.3-apache' === (string) ( $official['wordpress_image'] ?? '' ), 'Official WordPress image drifted.', $official );
wp_agent_no_live_contract_assert( 'wordpress:cli-php8.3' === (string) ( $official['wpcli_image'] ?? '' ), 'Official WP-CLI image drifted.', $official );
$cases['official_container_contract'] = array(
	'wordpress_image' => (string) ( $official['wordpress_image'] ?? '' ),
	'wpcli_image'     => (string) ( $official['wpcli_image'] ?? '' ),
	'db_bind_mount'   => (string) ( $official['db_bind_mount'] ?? '' ),
);

$live_gates = wp_agent_no_live_contract_run_case( 'live script gates contract', array(
	$php,
	$plugin_dir . '/tests/live-script-gates-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $live_gates['checked_count'] ?? 0 ) >= 8, 'Live script gate contract should cover all protected live/import scripts.', $live_gates );
wp_agent_no_live_contract_assert( false === (bool) ( $live_gates['ai_gateway'] ?? true ), 'Live script gate contract must not call the AI gateway.', $live_gates );
wp_agent_no_live_contract_assert( false === (bool) ( $live_gates['github_request'] ?? true ), 'Live script gate contract must not call GitHub.', $live_gates );
$cases['live_script_gates_contract'] = array(
	'checked_count' => (int) ( $live_gates['checked_count'] ?? 0 ),
);

$readiness = wp_agent_no_live_contract_run_case( 'official live readiness contract', array(
	$php,
	$plugin_dir . '/tests/official-live-readiness-contract.php',
) );
wp_agent_no_live_contract_assert( true === (bool) ( $readiness['official_live_readiness'] ?? false ), 'Official live readiness contract should report readiness for external live inputs.', $readiness );
wp_agent_no_live_contract_assert( false === (bool) ( $readiness['secret_disclosed'] ?? true ), 'Official live readiness contract must not disclose secrets.', $readiness );
wp_agent_no_live_contract_assert( false === (bool) ( $readiness['ai_gateway_calls'] ?? true ), 'Official live readiness contract must not call the AI gateway.', $readiness );
wp_agent_no_live_contract_assert( false === (bool) ( $readiness['github_calls'] ?? true ), 'Official live readiness contract must not call GitHub.', $readiness );
$cases['official_live_readiness_contract'] = array(
	'api_key_state' => (string) ( $readiness['ai']['api_key_state'] ?? '' ),
	'model'         => (string) ( $readiness['ai']['model'] ?? '' ),
	'image_model'   => (string) ( $readiness['ai']['image_model'] ?? '' ),
	'base_url_host' => (string) ( $readiness['ai']['base_url_host'] ?? '' ),
);

$preflight = wp_agent_no_live_contract_run_case( 'final preflight contract', array(
	$php,
	$plugin_dir . '/tests/final-preflight-contract.php',
) );
foreach ( array(
	'default_report_mode',
	'github_placeholder_strict_failure',
	'github_env_token_redaction',
	'invalid_scope_strict_failure',
	'soak_private_source_url_strict_failure',
	'soak_upper_bound_strict_failure',
	'soak_nondefault_db_strict_failure',
	'soak_nondefault_db_allowed_path_gate',
) as $required_case ) {
	wp_agent_no_live_contract_assert( in_array( $required_case, $preflight['cases'] ?? array(), true ), 'Final preflight contract missed a required case.', array(
		'required_case' => $required_case,
		'cases'         => $preflight['cases'] ?? array(),
	) );
}
wp_agent_no_live_contract_assert( false === (bool) ( $preflight['ai_gateway_calls'] ?? true ), 'Final preflight contract must not call the AI gateway.', $preflight );
wp_agent_no_live_contract_assert( false === (bool) ( $preflight['github_calls'] ?? true ), 'Final preflight contract must not call GitHub.', $preflight );
$cases['final_preflight_contract'] = array(
	'cases' => $preflight['cases'] ?? array(),
);

$runbook = wp_agent_no_live_contract_run_case( 'final runbook contract', array(
	$php,
	$plugin_dir . '/tests/final-runbook-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $runbook['documents_checked'] ?? 0 ) >= 2, 'Final runbook contract should inspect README.md and goals.md.', $runbook );
wp_agent_no_live_contract_assert( (int) ( $runbook['strict_preflight_markers'] ?? 0 ) >= 20, 'Final runbook contract should inspect strict preflight command block env markers.', $runbook );
wp_agent_no_live_contract_assert( (int) ( $runbook['strict_preflight_env_flags_checked'] ?? 0 ) >= 38, 'Final runbook contract should inspect strict preflight env flag uniqueness in README.md and goals.md.', $runbook );
wp_agent_no_live_contract_assert( false === (bool) ( $runbook['duplicate_env_flags'] ?? true ), 'Final runbook contract must reject duplicate strict preflight env flags.', $runbook );
wp_agent_no_live_contract_assert( (int) ( $runbook['regression_commands_checked'] ?? 0 ) >= 45, 'Final runbook contract should inspect final regression command uniqueness in README.md and goals.md.', $runbook );
wp_agent_no_live_contract_assert( false === (bool) ( $runbook['duplicate_regression_commands'] ?? true ), 'Final runbook contract must reject duplicate final regression commands.', $runbook );
wp_agent_no_live_contract_assert( false === (bool) ( $runbook['secret_assignments'] ?? true ), 'Final runbook contract must reject inline secret assignments.', $runbook );
wp_agent_no_live_contract_assert( false === (bool) ( $runbook['ai_gateway_calls'] ?? true ), 'Final runbook contract must not call the AI gateway.', $runbook );
wp_agent_no_live_contract_assert( false === (bool) ( $runbook['github_calls'] ?? true ), 'Final runbook contract must not call GitHub.', $runbook );
$cases['final_runbook_contract'] = array(
	'documents_checked' => (int) ( $runbook['documents_checked'] ?? 0 ),
	'required_markers'  => (int) ( $runbook['required_markers'] ?? 0 ),
	'strict_preflight_markers' => (int) ( $runbook['strict_preflight_markers'] ?? 0 ),
	'strict_preflight_env_flags_checked' => (int) ( $runbook['strict_preflight_env_flags_checked'] ?? 0 ),
	'duplicate_env_flags' => (bool) ( $runbook['duplicate_env_flags'] ?? true ),
	'regression_commands_checked' => (int) ( $runbook['regression_commands_checked'] ?? 0 ),
	'duplicate_regression_commands' => (bool) ( $runbook['duplicate_regression_commands'] ?? true ),
);

$live_evidence = wp_agent_no_live_contract_run_case( 'final live evidence contract', array(
	$php,
	$plugin_dir . '/tests/final-live-evidence-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $live_evidence['harnesses_checked'] ?? 0 ) >= 2, 'Final live evidence contract should inspect the GitHub and editorial soak harnesses.', $live_evidence );
wp_agent_no_live_contract_assert( false === (bool) ( $live_evidence['ai_gateway_calls'] ?? true ), 'Final live evidence contract must not call the AI gateway.', $live_evidence );
wp_agent_no_live_contract_assert( false === (bool) ( $live_evidence['github_calls'] ?? true ), 'Final live evidence contract must not call GitHub.', $live_evidence );
$cases['final_live_evidence_contract'] = array(
	'harnesses_checked'       => (int) ( $live_evidence['harnesses_checked'] ?? 0 ),
	'github_evidence_markers' => (int) ( $live_evidence['github_evidence_markers'] ?? 0 ),
	'soak_evidence_markers'   => (int) ( $live_evidence['soak_evidence_markers'] ?? 0 ),
);

$command_plan = wp_agent_no_live_contract_run_case( 'final live command plan contract', array(
	$php,
	$plugin_dir . '/tests/final-live-command-plan-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $command_plan['commands_checked'] ?? 0 ) >= 14, 'Final live command plan contract should inspect the full command sequence including acceptance summary and UX evidence validation.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['placeholder_rejected'] ?? false ), 'Final live command plan contract should reject placeholder GitHub coordinates.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['approval_phrase_rejected'] ?? false ), 'Final live command plan contract should reject placeholder approval phrases.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['invalid_reviewed_inputs_rejected'] ?? false ), 'Final live command plan contract should reject reviewed-looking but invalid inputs.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['source_url_rejected'] ?? false ), 'Final live command plan contract should reject localhost/private source URLs.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['official_db_rejected'] ?? false ), 'Final live command plan contract should reject non-official DB paths.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['cost_budget_rejected'] ?? false ), 'Final live command plan contract should reject non-positive cost budgets.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['artifact_policy_rejected'] ?? false ), 'Final live command plan contract should reject invalid artifact policies.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['soak_bounds_rejected'] ?? false ), 'Final live command plan contract should reject out-of-bounds soak parameters.', $command_plan );
wp_agent_no_live_contract_assert( false === (bool) ( $command_plan['commands_executable'] ?? true ), 'Final live command plan should not mark example-template commands executable.', $command_plan );
wp_agent_no_live_contract_assert( false === (bool) ( $command_plan['ready_for_live_execution'] ?? true ), 'Final live command plan should not be ready for live execution while the review packet is not ready.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['valid_review_packet_env_consistent'] ?? false ), 'Final live command plan contract should prove a matching reviewed env and review packet can be consistent.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['valid_ready_for_live_execution'] ?? false ), 'Final live command plan contract should prove matching reviewed inputs can reach live readiness.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['mismatched_packet_env_rejected'] ?? false ), 'Final live command plan contract should reject mismatched reviewed env and review packet fields.', $command_plan );
wp_agent_no_live_contract_assert( false === (bool) ( $command_plan['secret_assignments'] ?? true ), 'Final live command plan contract must reject inline secret assignments.', $command_plan );
wp_agent_no_live_contract_assert( false === (bool) ( $command_plan['ai_gateway_calls'] ?? true ), 'Final live command plan contract must not call the AI gateway.', $command_plan );
wp_agent_no_live_contract_assert( false === (bool) ( $command_plan['github_calls'] ?? true ), 'Final live command plan contract must not call GitHub.', $command_plan );
wp_agent_no_live_contract_assert( (int) ( $command_plan['archive_targets'] ?? 0 ) >= 7, 'Final live command plan should list all archive targets including command plan, UX, and redaction evidence.', $command_plan );
wp_agent_no_live_contract_assert( false === (bool) ( $command_plan['review_packet_ready'] ?? true ), 'Final live command plan should keep the template review packet not ready.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['review_packet_before_command_plan'] ?? false ), 'Final live command plan should check the review packet before regenerating the plan.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['review_packet_before_live'] ?? false ), 'Final live command plan should check the review packet before live steps.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['ux_validation_before_manifest'] ?? false ), 'Final live command plan should validate UX evidence before building the manifest.', $command_plan );
wp_agent_no_live_contract_assert( true === (bool) ( $command_plan['summary_before_manifest'] ?? false ), 'Final live command plan should write the acceptance summary before building the manifest.', $command_plan );
$cases['final_live_command_plan_contract'] = array(
	'commands_checked'          => (int) ( $command_plan['commands_checked'] ?? 0 ),
	'placeholder_rejected'      => (bool) ( $command_plan['placeholder_rejected'] ?? false ),
	'approval_phrase_rejected'  => (bool) ( $command_plan['approval_phrase_rejected'] ?? false ),
	'invalid_reviewed_inputs_rejected' => (bool) ( $command_plan['invalid_reviewed_inputs_rejected'] ?? false ),
	'source_url_rejected'       => (bool) ( $command_plan['source_url_rejected'] ?? false ),
	'official_db_rejected'      => (bool) ( $command_plan['official_db_rejected'] ?? false ),
	'cost_budget_rejected'      => (bool) ( $command_plan['cost_budget_rejected'] ?? false ),
	'artifact_policy_rejected'  => (bool) ( $command_plan['artifact_policy_rejected'] ?? false ),
	'soak_bounds_rejected'      => (bool) ( $command_plan['soak_bounds_rejected'] ?? false ),
	'commands_executable'       => (bool) ( $command_plan['commands_executable'] ?? true ),
	'ready_for_live_execution'  => (bool) ( $command_plan['ready_for_live_execution'] ?? true ),
	'valid_review_packet_env_consistent' => (bool) ( $command_plan['valid_review_packet_env_consistent'] ?? false ),
	'valid_ready_for_live_execution' => (bool) ( $command_plan['valid_ready_for_live_execution'] ?? false ),
	'mismatched_packet_env_rejected' => (bool) ( $command_plan['mismatched_packet_env_rejected'] ?? false ),
	'archive_targets'           => (int) ( $command_plan['archive_targets'] ?? 0 ),
	'review_packet_ready'       => (bool) ( $command_plan['review_packet_ready'] ?? true ),
	'review_packet_before_command_plan' => (bool) ( $command_plan['review_packet_before_command_plan'] ?? false ),
	'review_packet_before_live' => (bool) ( $command_plan['review_packet_before_live'] ?? false ),
	'ux_validation_before_manifest' => (bool) ( $command_plan['ux_validation_before_manifest'] ?? false ),
	'summary_before_manifest'   => (bool) ( $command_plan['summary_before_manifest'] ?? false ),
);

$live_report = wp_agent_no_live_contract_run_case( 'final live report artifact contract', array(
	$php,
	$plugin_dir . '/tests/final-live-report-artifact-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $live_report['files_checked'] ?? 0 ) >= 6, 'Final live report artifact contract should inspect the template, docs, inputs, and live harnesses.', $live_report );
wp_agent_no_live_contract_assert( in_array( 'final-live-github-skill-store-YYYYMMDD.json', $live_report['archive_targets'] ?? array(), true ), 'Final live report artifact contract should require archived GitHub Skill Store evidence.', $live_report );
wp_agent_no_live_contract_assert( in_array( 'final-live-editorial-daemon-soak-YYYYMMDD.json', $live_report['archive_targets'] ?? array(), true ), 'Final live report artifact contract should require archived daemon soak evidence.', $live_report );
wp_agent_no_live_contract_assert( in_array( 'final-live-command-plan-YYYYMMDD.json', $live_report['archive_targets'] ?? array(), true ), 'Final live report artifact contract should require archived command plan evidence.', $live_report );
wp_agent_no_live_contract_assert( in_array( 'ui-playwright-evidence-contract-YYYYMMDD.md', $live_report['archive_targets'] ?? array(), true ), 'Final live report artifact contract should require archived UX evidence.', $live_report );
wp_agent_no_live_contract_assert( in_array( 'final-live-archive-redaction-YYYYMMDD.md', $live_report['archive_targets'] ?? array(), true ), 'Final live report artifact contract should require archived redaction report evidence.', $live_report );
wp_agent_no_live_contract_assert( true === (bool) ( $live_report['report_ux_order_recorded'] ?? false ), 'Final live report artifact contract should expose the report-level UX order marker.', $live_report );
wp_agent_no_live_contract_assert( true === (bool) ( $live_report['report_summary_order_recorded'] ?? false ), 'Final live report artifact contract should expose the report-level acceptance summary order marker.', $live_report );
wp_agent_no_live_contract_assert( false === (bool) ( $live_report['secret_assignments'] ?? true ), 'Final live report artifact contract must reject inline secret assignments.', $live_report );
wp_agent_no_live_contract_assert( false === (bool) ( $live_report['ai_gateway_calls'] ?? true ), 'Final live report artifact contract must not call the AI gateway.', $live_report );
wp_agent_no_live_contract_assert( false === (bool) ( $live_report['github_calls'] ?? true ), 'Final live report artifact contract must not call GitHub.', $live_report );
$cases['final_live_report_artifact_contract'] = array(
	'files_checked'    => (int) ( $live_report['files_checked'] ?? 0 ),
	'required_markers' => (int) ( $live_report['required_markers'] ?? 0 ),
	'report_ux_order_recorded' => (bool) ( $live_report['report_ux_order_recorded'] ?? false ),
	'report_summary_order_recorded' => (bool) ( $live_report['report_summary_order_recorded'] ?? false ),
	'archive_targets'  => $live_report['archive_targets'] ?? array(),
);

$artifact_manifest = wp_agent_no_live_contract_run_case( 'final live artifact manifest contract', array(
	$php,
	$plugin_dir . '/tests/final-live-artifact-manifest-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $artifact_manifest['artifacts_required'] ?? 0 ) >= 9, 'Final live artifact manifest contract should require the full final artifact inventory including command plan and UX evidence.', $artifact_manifest );
wp_agent_no_live_contract_assert( 'final-live-artifact-manifest-YYYYMMDD.json' === (string) ( $artifact_manifest['archive_target'] ?? '' ), 'Final live artifact manifest contract should publish the final manifest archive target.', $artifact_manifest );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest['secret_assignments'] ?? true ), 'Final live artifact manifest contract must reject inline secret assignments.', $artifact_manifest );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest['ai_gateway_calls'] ?? true ), 'Final live artifact manifest contract must not call the AI gateway.', $artifact_manifest );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest['github_calls'] ?? true ), 'Final live artifact manifest contract must not call GitHub.', $artifact_manifest );
$cases['final_live_artifact_manifest_contract'] = array(
	'artifacts_required'      => (int) ( $artifact_manifest['artifacts_required'] ?? 0 ),
	'required_markers'        => (int) ( $artifact_manifest['required_markers'] ?? 0 ),
	'actual_manifest_present' => (bool) ( $artifact_manifest['actual_manifest_present'] ?? false ),
	'archive_target'          => (string) ( $artifact_manifest['archive_target'] ?? '' ),
);

$artifact_manifest_fixture = wp_agent_no_live_contract_run_case( 'final live artifact manifest fixture contract', array(
	$php,
	$plugin_dir . '/tests/final-live-artifact-manifest-fixture-contract.php',
) );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_fixture['valid_manifest_ready'] ?? false ), 'Final live artifact manifest fixture contract should accept a valid manifest fixture.', $artifact_manifest_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest_fixture['invalid_manifest_ready'] ?? true ), 'Final live artifact manifest fixture contract should reject an invalid manifest fixture.', $artifact_manifest_fixture );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_fixture['invalid_review_packet_source_rejected'] ?? false ), 'Final live artifact manifest fixture contract should reject template review packet sources.', $artifact_manifest_fixture );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_fixture['invalid_command_plan_ready_rejected'] ?? false ), 'Final live artifact manifest fixture contract should reject command plans that are not live-ready.', $artifact_manifest_fixture );
wp_agent_no_live_contract_assert( (int) ( $artifact_manifest_fixture['valid_artifacts_checked'] ?? 0 ) >= 9, 'Final live artifact manifest fixture contract should cover the required artifact inventory including command plan and UX evidence.', $artifact_manifest_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest_fixture['secret_assignments'] ?? true ), 'Final live artifact manifest fixture contract must reject inline secret assignments.', $artifact_manifest_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest_fixture['ai_gateway_calls'] ?? true ), 'Final live artifact manifest fixture contract must not call the AI gateway.', $artifact_manifest_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest_fixture['github_calls'] ?? true ), 'Final live artifact manifest fixture contract must not call GitHub.', $artifact_manifest_fixture );
$cases['final_live_artifact_manifest_fixture_contract'] = array(
	'valid_manifest_ready'    => (bool) ( $artifact_manifest_fixture['valid_manifest_ready'] ?? false ),
	'invalid_manifest_ready'  => (bool) ( $artifact_manifest_fixture['invalid_manifest_ready'] ?? true ),
	'invalid_status'          => (int) ( $artifact_manifest_fixture['invalid_status'] ?? 0 ),
	'invalid_review_packet_source_rejected' => (bool) ( $artifact_manifest_fixture['invalid_review_packet_source_rejected'] ?? false ),
	'invalid_command_plan_ready_rejected' => (bool) ( $artifact_manifest_fixture['invalid_command_plan_ready_rejected'] ?? false ),
	'valid_artifacts_checked' => (int) ( $artifact_manifest_fixture['valid_artifacts_checked'] ?? 0 ),
);

$artifact_manifest_build = wp_agent_no_live_contract_run_case( 'final live artifact manifest build contract', array(
	$php,
	$plugin_dir . '/tests/final-live-artifact-manifest-build-contract.php',
) );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['valid_manifest_ready'] ?? false ), 'Final live artifact manifest builder should create a valid manifest fixture.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['generated_manifest_valid'] ?? false ), 'Final live artifact manifest builder output should pass the manifest contract.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['command_plan_artifact_recorded'] ?? false ), 'Final live artifact manifest builder should record the archived command plan artifact.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['manifest_ux_order_recorded'] ?? false ), 'Final live artifact manifest builder should record UX validation order in the manifest.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['manifest_summary_order_recorded'] ?? false ), 'Final live artifact manifest builder should record acceptance summary order in the manifest.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['summary_order_guard'] ?? false ), 'Final live artifact manifest builder should fail closed when summary order is missing from the command plan.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['placeholder_rejected'] ?? false ), 'Final live artifact manifest builder should reject placeholder input.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['approval_phrase_rejected'] ?? false ), 'Final live artifact manifest builder should reject placeholder approval phrases.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['approval_confirmation_rejected'] ?? false ), 'Final live artifact manifest builder should reject soak artifacts missing approval confirmation.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['missing_artifact_rejected'] ?? false ), 'Final live artifact manifest builder should reject missing artifact sets.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['missing_ux_evidence_rejected'] ?? false ), 'Final live artifact manifest builder should reject missing UX evidence artifacts.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['token_disclosure_rejected'] ?? false ), 'Final live artifact manifest builder should reject token disclosure artifacts.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( true === (bool) ( $artifact_manifest_build['mismatched_packet_env_rejected'] ?? false ), 'Final live artifact manifest builder should reject mismatched review packet/env inputs.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest_build['secret_assignments'] ?? true ), 'Final live artifact manifest builder contract must reject inline secret assignments.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest_build['ai_gateway_calls'] ?? true ), 'Final live artifact manifest builder contract must not call the AI gateway.', $artifact_manifest_build );
wp_agent_no_live_contract_assert( false === (bool) ( $artifact_manifest_build['github_calls'] ?? true ), 'Final live artifact manifest builder contract must not call GitHub.', $artifact_manifest_build );
$cases['final_live_artifact_manifest_build_contract'] = array(
	'valid_manifest_ready'      => (bool) ( $artifact_manifest_build['valid_manifest_ready'] ?? false ),
	'valid_manifest_written'    => (bool) ( $artifact_manifest_build['valid_manifest_written'] ?? false ),
	'valid_artifacts_checked'   => (int) ( $artifact_manifest_build['valid_artifacts_checked'] ?? 0 ),
	'generated_manifest_valid'  => (bool) ( $artifact_manifest_build['generated_manifest_valid'] ?? false ),
	'command_plan_artifact_recorded' => (bool) ( $artifact_manifest_build['command_plan_artifact_recorded'] ?? false ),
	'manifest_ux_order_recorded' => (bool) ( $artifact_manifest_build['manifest_ux_order_recorded'] ?? false ),
	'manifest_summary_order_recorded' => (bool) ( $artifact_manifest_build['manifest_summary_order_recorded'] ?? false ),
	'summary_order_guard'       => (bool) ( $artifact_manifest_build['summary_order_guard'] ?? false ),
	'placeholder_rejected'      => (bool) ( $artifact_manifest_build['placeholder_rejected'] ?? false ),
	'approval_phrase_rejected'  => (bool) ( $artifact_manifest_build['approval_phrase_rejected'] ?? false ),
	'approval_confirmation_rejected' => (bool) ( $artifact_manifest_build['approval_confirmation_rejected'] ?? false ),
	'missing_artifact_rejected' => (bool) ( $artifact_manifest_build['missing_artifact_rejected'] ?? false ),
	'missing_ux_evidence_rejected' => (bool) ( $artifact_manifest_build['missing_ux_evidence_rejected'] ?? false ),
	'token_disclosure_rejected' => (bool) ( $artifact_manifest_build['token_disclosure_rejected'] ?? false ),
	'mismatched_packet_env_rejected' => (bool) ( $artifact_manifest_build['mismatched_packet_env_rejected'] ?? false ),
);

$archive_redaction = wp_agent_no_live_contract_run_case( 'final live archive redaction contract', array(
	$php,
	$plugin_dir . '/tests/final-live-archive-redaction-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $archive_redaction['docs_checked'] ?? 0 ) >= 5, 'Final live archive redaction contract should inspect final live docs and templates.', $archive_redaction );
wp_agent_no_live_contract_assert( 0 === (int) ( $archive_redaction['raw_secret_hits'] ?? 1 ), 'Final live archive redaction contract should report no raw secret hits.', $archive_redaction );
wp_agent_no_live_contract_assert( false === (bool) ( $archive_redaction['secret_assignments'] ?? true ), 'Final live archive redaction contract must reject inline secret assignments.', $archive_redaction );
wp_agent_no_live_contract_assert( false === (bool) ( $archive_redaction['ai_gateway_calls'] ?? true ), 'Final live archive redaction contract must not call the AI gateway.', $archive_redaction );
wp_agent_no_live_contract_assert( false === (bool) ( $archive_redaction['github_calls'] ?? true ), 'Final live archive redaction contract must not call GitHub.', $archive_redaction );
$cases['final_live_archive_redaction_contract'] = array(
	'docs_checked'          => (int) ( $archive_redaction['docs_checked'] ?? 0 ),
	'archive_files_scanned' => (int) ( $archive_redaction['archive_files_scanned'] ?? 0 ),
	'token_flag_files'      => (int) ( $archive_redaction['token_flag_files'] ?? 0 ),
	'raw_secret_hits'       => (int) ( $archive_redaction['raw_secret_hits'] ?? 0 ),
);

$archive_redaction_fixture = wp_agent_no_live_contract_run_case( 'final live archive redaction fixture contract', array(
	$php,
	$plugin_dir . '/tests/final-live-archive-redaction-fixture-contract.php',
) );
wp_agent_no_live_contract_assert( true === (bool) ( $archive_redaction_fixture['valid_redaction_ready'] ?? false ), 'Final live archive redaction fixture contract should accept clean final artifacts.', $archive_redaction_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $archive_redaction_fixture['invalid_redaction_ready'] ?? true ), 'Final live archive redaction fixture contract should reject raw-token artifacts.', $archive_redaction_fixture );
wp_agent_no_live_contract_assert( (int) ( $archive_redaction_fixture['valid_files_scanned'] ?? 0 ) >= 5, 'Final live archive redaction fixture contract should scan all final artifact types including UX evidence.', $archive_redaction_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $archive_redaction_fixture['ai_gateway_calls'] ?? true ), 'Final live archive redaction fixture contract must not call the AI gateway.', $archive_redaction_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $archive_redaction_fixture['github_calls'] ?? true ), 'Final live archive redaction fixture contract must not call GitHub.', $archive_redaction_fixture );
$cases['final_live_archive_redaction_fixture_contract'] = array(
	'valid_redaction_ready'   => (bool) ( $archive_redaction_fixture['valid_redaction_ready'] ?? false ),
	'invalid_redaction_ready' => (bool) ( $archive_redaction_fixture['invalid_redaction_ready'] ?? true ),
	'invalid_status'          => (int) ( $archive_redaction_fixture['invalid_status'] ?? 0 ),
	'valid_files_scanned'     => (int) ( $archive_redaction_fixture['valid_files_scanned'] ?? 0 ),
);

$completion_gate = wp_agent_no_live_contract_run_case( 'final live completion gate contract', array(
	$php,
	$plugin_dir . '/tests/final-live-completion-gate-contract.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $completion_gate['completion_ready'] ?? true ), 'Final live completion gate should remain not-ready before real live artifacts are archived.', $completion_gate );
wp_agent_no_live_contract_assert( array( 6, 9 ) === array_values( $completion_gate['partial_rows'] ?? array() ), 'Final live completion gate should keep only #6 and #9 partial before live artifacts exist.', $completion_gate );
wp_agent_no_live_contract_assert( in_array( 'official_skills_github_repository', $completion_gate['external_blockers'] ?? array(), true ), 'Final live completion gate should retain the GitHub external blocker before live artifacts exist.', $completion_gate );
wp_agent_no_live_contract_assert( in_array( 'multi_hour_live_soak_budget_and_approval', $completion_gate['external_blockers'] ?? array(), true ), 'Final live completion gate should retain the multi-hour soak blocker before live artifacts exist.', $completion_gate );
wp_agent_no_live_contract_assert( false === (bool) ( $completion_gate['secret_assignments'] ?? true ), 'Final live completion gate must reject inline secret assignments.', $completion_gate );
wp_agent_no_live_contract_assert( false === (bool) ( $completion_gate['ai_gateway_calls'] ?? true ), 'Final live completion gate must not call the AI gateway.', $completion_gate );
wp_agent_no_live_contract_assert( false === (bool) ( $completion_gate['github_calls'] ?? true ), 'Final live completion gate must not call GitHub.', $completion_gate );
$cases['final_live_completion_gate_contract'] = array(
	'completion_ready'  => (bool) ( $completion_gate['completion_ready'] ?? false ),
	'goals_status'      => (string) ( $completion_gate['goals_status'] ?? '' ),
	'partial_rows'      => $completion_gate['partial_rows'] ?? array(),
	'external_blockers' => $completion_gate['external_blockers'] ?? array(),
);

$completion_fixture = wp_agent_no_live_contract_run_case( 'final live completion gate fixture contract', array(
	$php,
	$plugin_dir . '/tests/final-live-completion-gate-fixture-contract.php',
) );
wp_agent_no_live_contract_assert( true === (bool) ( $completion_fixture['valid_completion_ready'] ?? false ), 'Completion gate fixture contract should accept a schema-valid final live artifact set.', $completion_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $completion_fixture['invalid_completion_ready'] ?? true ), 'Completion gate fixture contract should reject an invalid final live artifact set.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'github_repository_missing_or_placeholder', $completion_fixture['invalid_github_errors'] ?? array(), true ), 'Completion gate fixture contract should reject placeholder GitHub repository artifacts.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'soak_source_url_not_public_http', $completion_fixture['invalid_soak_errors'] ?? array(), true ), 'Completion gate fixture contract should reject non-public soak source URL artifacts.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'manifest_review_packet_source_missing', $completion_fixture['invalid_manifest_errors'] ?? array(), true ), 'Completion gate fixture contract should reject manifests missing approved review packet source.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'manifest_soak_approval_not_confirmed', $completion_fixture['invalid_manifest_errors'] ?? array(), true ), 'Completion gate fixture contract should reject manifests missing soak approval confirmation.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'manifest_command_plan_not_executable', $completion_fixture['invalid_manifest_errors'] ?? array(), true ), 'Completion gate fixture contract should reject manifests with non-executable command plans.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'manifest_command_plan_not_ready_for_live_execution', $completion_fixture['invalid_manifest_errors'] ?? array(), true ), 'Completion gate fixture contract should reject manifests whose command plan is not live-ready.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'manifest_review_packet_not_ready', $completion_fixture['invalid_manifest_errors'] ?? array(), true ), 'Completion gate fixture contract should reject manifests with unready review packets.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'manifest_review_packet_env_not_consistent', $completion_fixture['invalid_manifest_errors'] ?? array(), true ), 'Completion gate fixture contract should reject manifests with packet/env inconsistency.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'manifest_artifact_hash_mismatch:github_skill_store', $completion_fixture['invalid_manifest_errors'] ?? array(), true ), 'Completion gate fixture contract should reject manifest hash mismatches.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'manifest_missing_artifact:ux_evidence', $completion_fixture['invalid_manifest_errors'] ?? array(), true ), 'Completion gate fixture contract should require UX evidence in the final manifest.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'summary_missing_marker:completion_ready=true', $completion_fixture['invalid_summary_errors'] ?? array(), true ), 'Completion gate fixture contract should reject acceptance summaries missing completion_ready=true.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'summary_missing_marker:chat_queue_status_playwright=true', $completion_fixture['invalid_summary_errors'] ?? array(), true ), 'Completion gate fixture contract should reject acceptance summaries missing chat_queue_status_playwright=true.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'summary_missing_marker:chat_stop_availability_playwright=true', $completion_fixture['invalid_summary_errors'] ?? array(), true ), 'Completion gate fixture contract should reject acceptance summaries missing chat_stop_availability_playwright=true.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'summary_missing_marker:composer_unlocked_guard=true', $completion_fixture['invalid_summary_errors'] ?? array(), true ), 'Completion gate fixture contract should reject acceptance summaries missing composer_unlocked_guard=true.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'ux_evidence_missing', $completion_fixture['invalid_ux_errors'] ?? array(), true ), 'Completion gate fixture contract should reject missing UX evidence.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'redaction_token_disclosed_true:github', $completion_fixture['invalid_redaction_errors'] ?? array(), true ), 'Completion gate fixture contract should reject token disclosure during archive redaction.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'redaction_missing_artifact:command_plan', $completion_fixture['invalid_redaction_errors'] ?? array(), true ), 'Completion gate fixture contract should reject missing command plan redaction coverage.', $completion_fixture );
wp_agent_no_live_contract_assert( in_array( 'redaction_missing_artifact:redaction_report', $completion_fixture['invalid_redaction_errors'] ?? array(), true ), 'Completion gate fixture contract should reject missing redaction report self-check.', $completion_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $completion_fixture['ai_gateway_calls'] ?? true ), 'Completion gate fixture contract must not call the AI gateway.', $completion_fixture );
wp_agent_no_live_contract_assert( false === (bool) ( $completion_fixture['github_calls'] ?? true ), 'Completion gate fixture contract must not call GitHub.', $completion_fixture );
$cases['final_live_completion_gate_fixture_contract'] = array(
	'valid_completion_ready'   => (bool) ( $completion_fixture['valid_completion_ready'] ?? false ),
	'invalid_completion_ready' => (bool) ( $completion_fixture['invalid_completion_ready'] ?? true ),
	'invalid_github_errors'    => $completion_fixture['invalid_github_errors'] ?? array(),
	'invalid_soak_errors'      => $completion_fixture['invalid_soak_errors'] ?? array(),
	'invalid_summary_errors'   => $completion_fixture['invalid_summary_errors'] ?? array(),
	'invalid_ux_errors'        => $completion_fixture['invalid_ux_errors'] ?? array(),
	'invalid_manifest_errors'  => $completion_fixture['invalid_manifest_errors'] ?? array(),
	'invalid_redaction_errors' => $completion_fixture['invalid_redaction_errors'] ?? array(),
);

$external_gates = wp_agent_no_live_contract_run_case( 'final external gates evidence contract', array(
	$php,
	$plugin_dir . '/tests/final-external-gates-evidence-contract.php',
) );
wp_agent_no_live_contract_assert( array( 6, 9 ) === array_values( $external_gates['partial_rows'] ?? array() ), 'Final external gates evidence contract should report only #6 and #9 as partial.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['github_external_inputs'] ?? false ), 'Final external gates evidence contract must prove GitHub external input evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['soak_approval_gate'] ?? false ), 'Final external gates evidence contract must prove soak approval evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['official_db_guard'] ?? false ), 'Final external gates evidence contract must prove official database guard evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['command_plan_input_guards'] ?? false ), 'Final external gates evidence contract must prove command-plan invalid input guards.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['strict_preflight_runbook_guard'] ?? false ), 'Final external gates evidence contract must prove strict preflight runbook evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['artifact_manifest_build_guard'] ?? false ), 'Final external gates evidence contract must prove artifact manifest builder fail-closed evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['final_completion_ux_guard'] ?? false ), 'Final external gates evidence contract must prove final completion UX evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['live_input_template_guard'] ?? false ), 'Final external gates evidence contract must prove final live input template evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['live_input_template_preflight_guard'] ?? false ), 'Final external gates evidence contract must prove final live input template preflight evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['user_input_status_gate'] ?? false ), 'Final external gates evidence contract must prove user input status evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['reviewed_env_status_gate'] ?? false ), 'Final external gates evidence contract must prove reviewed env status evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['blocker_status_next_actions_gate'] ?? false ), 'Final external gates evidence contract must prove blocker status next-actions evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['official_live_readiness_guard'] ?? false ), 'Final external gates evidence contract must prove official live readiness evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['database_persistence_guard'] ?? false ), 'Final external gates evidence contract must prove database persistence evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['official_container_guard'] ?? false ), 'Final external gates evidence contract must prove official container evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['live_script_gates_guard'] ?? false ), 'Final external gates evidence contract must prove live script fail-closed evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['git_hygiene_guard'] ?? false ), 'Final external gates evidence contract must prove local Git hygiene evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['ux_playwright_gate'] ?? false ), 'Final external gates evidence contract must prove UI Playwright UX gate evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['ux_operability_gate'] ?? false ), 'Final external gates evidence contract must prove UX operability evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['chat_stop_queue_gate'] ?? false ), 'Final external gates evidence contract must prove Chat Stop/queue UX evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['ux_product_usability_gate'] ?? false ), 'Final external gates evidence contract must prove product UX usability evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['ux_nonnegotiable_gate'] ?? false ), 'Final external gates evidence contract must prove non-negotiable UX quality evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['final_review_packet_guard'] ?? false ), 'Final external gates evidence contract must prove the final live review packet evidence.', $external_gates );
wp_agent_no_live_contract_assert( true === (bool) ( $external_gates['coverage']['review_packet_status_gate'] ?? false ), 'Final external gates evidence contract must prove review packet status evidence.', $external_gates );
wp_agent_no_live_contract_assert( false === (bool) ( $external_gates['ai_gateway_calls'] ?? true ), 'Final external gates evidence contract must not call the AI gateway.', $external_gates );
wp_agent_no_live_contract_assert( false === (bool) ( $external_gates['github_calls'] ?? true ), 'Final external gates evidence contract must not call GitHub.', $external_gates );
$cases['final_external_gates_evidence_contract'] = array(
	'files_checked'      => (int) ( $external_gates['files_checked'] ?? 0 ),
	'required_markers'   => (int) ( $external_gates['required_markers'] ?? 0 ),
	'partial_rows'       => $external_gates['partial_rows'] ?? array(),
	'external_blockers'  => $external_gates['external_blockers'] ?? array(),
	'command_plan_input_guards' => (bool) ( $external_gates['coverage']['command_plan_input_guards'] ?? false ),
	'strict_preflight_runbook_guard' => (bool) ( $external_gates['coverage']['strict_preflight_runbook_guard'] ?? false ),
	'artifact_manifest_build_guard' => (bool) ( $external_gates['coverage']['artifact_manifest_build_guard'] ?? false ),
	'final_completion_ux_guard' => (bool) ( $external_gates['coverage']['final_completion_ux_guard'] ?? false ),
	'live_input_template_guard' => (bool) ( $external_gates['coverage']['live_input_template_guard'] ?? false ),
	'live_input_template_preflight_guard' => (bool) ( $external_gates['coverage']['live_input_template_preflight_guard'] ?? false ),
	'user_input_status_gate' => (bool) ( $external_gates['coverage']['user_input_status_gate'] ?? false ),
	'reviewed_env_status_gate' => (bool) ( $external_gates['coverage']['reviewed_env_status_gate'] ?? false ),
	'blocker_status_next_actions_gate' => (bool) ( $external_gates['coverage']['blocker_status_next_actions_gate'] ?? false ),
	'official_live_readiness_guard' => (bool) ( $external_gates['coverage']['official_live_readiness_guard'] ?? false ),
	'database_persistence_guard' => (bool) ( $external_gates['coverage']['database_persistence_guard'] ?? false ),
	'official_container_guard' => (bool) ( $external_gates['coverage']['official_container_guard'] ?? false ),
	'live_script_gates_guard' => (bool) ( $external_gates['coverage']['live_script_gates_guard'] ?? false ),
	'git_hygiene_guard' => (bool) ( $external_gates['coverage']['git_hygiene_guard'] ?? false ),
	'ux_playwright_gate' => (bool) ( $external_gates['coverage']['ux_playwright_gate'] ?? false ),
	'ux_operability_gate' => (bool) ( $external_gates['coverage']['ux_operability_gate'] ?? false ),
	'chat_stop_queue_gate' => (bool) ( $external_gates['coverage']['chat_stop_queue_gate'] ?? false ),
	'ux_product_usability_gate' => (bool) ( $external_gates['coverage']['ux_product_usability_gate'] ?? false ),
	'ux_nonnegotiable_gate' => (bool) ( $external_gates['coverage']['ux_nonnegotiable_gate'] ?? false ),
	'final_review_packet_guard' => (bool) ( $external_gates['coverage']['final_review_packet_guard'] ?? false ),
	'review_packet_status_gate' => (bool) ( $external_gates['coverage']['review_packet_status_gate'] ?? false ),
);

$review_packet = wp_agent_no_live_contract_run_case( 'final live review packet contract', array(
	$php,
	$plugin_dir . '/tests/final-live-review-packet-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $review_packet['required_markers'] ?? 0 ) >= 40, 'Final live review packet contract should inspect all human approval packet markers.', $review_packet );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet['review_packet_template'] ?? false ), 'Final live review packet contract should validate the packet template.', $review_packet );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet['gitignore_review_inputs'] ?? false ), 'Final live review packet contract should verify local approval packet ignore rules.', $review_packet );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet['secret_assignments'] ?? true ), 'Final live review packet contract must reject secret assignments.', $review_packet );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet['ai_gateway_calls'] ?? true ), 'Final live review packet contract must not call the AI gateway.', $review_packet );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet['github_calls'] ?? true ), 'Final live review packet contract must not call GitHub.', $review_packet );
$cases['final_live_review_packet_contract'] = array(
	'required_markers'       => (int) ( $review_packet['required_markers'] ?? 0 ),
	'review_packet_template' => (bool) ( $review_packet['review_packet_template'] ?? false ),
	'gitignore_review_inputs' => (bool) ( $review_packet['gitignore_review_inputs'] ?? false ),
	'secret_assignments'     => (bool) ( $review_packet['secret_assignments'] ?? true ),
);

$review_packet_status = wp_agent_no_live_contract_run_case( 'final live review packet status', array(
	$php,
	$plugin_dir . '/tests/final-live-review-packet-status.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet_status['packet_ready'] ?? true ), 'Review packet status should reject the template as not completed.', $review_packet_status );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet_status['path_is_template'] ?? false ), 'Review packet status should identify the template path.', $review_packet_status );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet_status['path_ignored_by_git'] ?? true ), 'Review packet status should require an ignored completed packet.', $review_packet_status );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet_status['path_tracked_by_git'] ?? false ), 'Review packet status should expose that the template is tracked.', $review_packet_status );
wp_agent_no_live_contract_assert( in_array( 'Repository', $review_packet_status['missing_fields'] ?? array(), true ), 'Review packet status should list missing completed fields.', $review_packet_status );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet_status['ai_gateway_calls'] ?? true ), 'Review packet status must not call the AI gateway.', $review_packet_status );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet_status['github_calls'] ?? true ), 'Review packet status must not call GitHub.', $review_packet_status );
$cases['final_live_review_packet_status'] = array(
	'packet_ready'        => (bool) ( $review_packet_status['packet_ready'] ?? true ),
	'path_is_template'    => (bool) ( $review_packet_status['path_is_template'] ?? false ),
	'path_ignored_by_git' => (bool) ( $review_packet_status['path_ignored_by_git'] ?? true ),
	'path_tracked_by_git' => (bool) ( $review_packet_status['path_tracked_by_git'] ?? false ),
);

$review_packet_status_contract = wp_agent_no_live_contract_run_case( 'final live review packet status contract', array(
	$php,
	$plugin_dir . '/tests/final-live-review-packet-status-contract.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet_status_contract['default_packet_ready'] ?? true ), 'Review packet status contract should reject the template.', $review_packet_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet_status_contract['valid_packet_ready'] ?? false ), 'Review packet status contract should accept a safe ignored completed packet.', $review_packet_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet_status_contract['valid_path_ignored_by_git'] ?? false ), 'Review packet status contract should prove valid packets are ignored by Git.', $review_packet_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet_status_contract['invalid_command_plan_rejected'] ?? false ), 'Review packet status contract should reject missing command plan artifact evidence.', $review_packet_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet_status_contract['invalid_redaction_report_rejected'] ?? false ), 'Review packet status contract should reject non-archive redaction report values.', $review_packet_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet_status_contract['invalid_source_rejected'] ?? false ), 'Review packet status contract should reject localhost/private source URLs.', $review_packet_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $review_packet_status_contract['secret_assignment_rejected'] ?? false ), 'Review packet status contract should reject inline token assignments.', $review_packet_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet_status_contract['ai_gateway_calls'] ?? true ), 'Review packet status contract must not call the AI gateway.', $review_packet_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $review_packet_status_contract['github_calls'] ?? true ), 'Review packet status contract must not call GitHub.', $review_packet_status_contract );
$cases['final_live_review_packet_status_contract'] = array(
	'default_packet_ready'      => (bool) ( $review_packet_status_contract['default_packet_ready'] ?? true ),
	'valid_packet_ready'        => (bool) ( $review_packet_status_contract['valid_packet_ready'] ?? false ),
	'invalid_command_plan_rejected' => (bool) ( $review_packet_status_contract['invalid_command_plan_rejected'] ?? false ),
	'invalid_redaction_report_rejected' => (bool) ( $review_packet_status_contract['invalid_redaction_report_rejected'] ?? false ),
	'invalid_source_rejected'   => (bool) ( $review_packet_status_contract['invalid_source_rejected'] ?? false ),
	'secret_assignment_rejected' => (bool) ( $review_packet_status_contract['secret_assignment_rejected'] ?? false ),
);

$live_input_template = wp_agent_no_live_contract_run_case( 'final live input template contract', array(
	$php,
	$plugin_dir . '/tests/final-live-input-template-contract.php',
) );
wp_agent_no_live_contract_assert( (int) ( $live_input_template['keys_checked'] ?? 0 ) >= 18, 'Final live input template contract should inspect all required live input keys.', $live_input_template );
wp_agent_no_live_contract_assert( 'owner/repo' === (string) ( $live_input_template['github_placeholder']['repository'] ?? '' ), 'Final live input template should keep the GitHub repository placeholder obvious.', $live_input_template );
wp_agent_no_live_contract_assert( 'skills/example' === (string) ( $live_input_template['github_placeholder']['skill_path'] ?? '' ), 'Final live input template should keep the GitHub Skill path placeholder obvious.', $live_input_template );
wp_agent_no_live_contract_assert( false === (bool) ( $live_input_template['secret_assignments'] ?? true ), 'Final live input template must not contain secret assignments.', $live_input_template );
wp_agent_no_live_contract_assert( false === (bool) ( $live_input_template['ai_gateway_calls'] ?? true ), 'Final live input template contract must not call the AI gateway.', $live_input_template );
wp_agent_no_live_contract_assert( false === (bool) ( $live_input_template['github_calls'] ?? true ), 'Final live input template contract must not call GitHub.', $live_input_template );
$cases['final_live_input_template_contract'] = array(
	'keys_checked'       => (int) ( $live_input_template['keys_checked'] ?? 0 ),
	'github_placeholder' => $live_input_template['github_placeholder'] ?? array(),
	'soak_bounds'        => $live_input_template['soak_bounds'] ?? array(),
	'secret_assignments' => (bool) ( $live_input_template['secret_assignments'] ?? true ),
);

$live_input_preflight = wp_agent_no_live_contract_run_case( 'final live input template preflight contract', array(
	$php,
	$plugin_dir . '/tests/final-live-input-template-preflight-contract.php',
) );
wp_agent_no_live_contract_assert( true === (bool) ( $live_input_preflight['placeholder_rejected'] ?? false ), 'Final live input template preflight should reject placeholder GitHub coordinates.', $live_input_preflight );
wp_agent_no_live_contract_assert( false === (bool) ( $live_input_preflight['json_ready'] ?? true ), 'Final live input template preflight should report ready=false.', $live_input_preflight );
wp_agent_no_live_contract_assert( false === (bool) ( $live_input_preflight['token_disclosed'] ?? true ), 'Final live input template preflight must keep token_disclosed=false.', $live_input_preflight );
wp_agent_no_live_contract_assert( false === (bool) ( $live_input_preflight['ai_gateway_calls'] ?? true ), 'Final live input template preflight contract must not call the AI gateway.', $live_input_preflight );
wp_agent_no_live_contract_assert( false === (bool) ( $live_input_preflight['github_calls'] ?? true ), 'Final live input template preflight contract must not call GitHub.', $live_input_preflight );
$cases['final_live_input_template_preflight_contract'] = array(
	'preflight_status'    => (int) ( $live_input_preflight['preflight_status'] ?? 0 ),
	'placeholder_rejected' => (bool) ( $live_input_preflight['placeholder_rejected'] ?? false ),
	'json_ready'          => (bool) ( $live_input_preflight['json_ready'] ?? true ),
	'token_disclosed'     => (bool) ( $live_input_preflight['token_disclosed'] ?? true ),
);

$user_input_status = wp_agent_no_live_contract_run_case( 'final live user input status', array(
	$php,
	$plugin_dir . '/tests/final-live-user-input-status.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $user_input_status['user_input_ready'] ?? true ), 'User input status should reject the example template.', $user_input_status );
wp_agent_no_live_contract_assert( false === (bool) ( $user_input_status['github_inputs_ready'] ?? true ), 'User input status should expose missing official GitHub coordinates.', $user_input_status );
wp_agent_no_live_contract_assert( false === (bool) ( $user_input_status['soak_inputs_ready'] ?? true ), 'User input status should expose missing live soak approval review.', $user_input_status );
wp_agent_no_live_contract_assert( in_array( 'official_skill_store_repository', $user_input_status['pending_user_inputs'] ?? array(), true ), 'User input status should list the pending official repository.', $user_input_status );
wp_agent_no_live_contract_assert( in_array( 'multi_hour_soak_approval_phrase', $user_input_status['pending_review_items'] ?? array(), true ), 'User input status should list the pending live soak approval phrase.', $user_input_status );
wp_agent_no_live_contract_assert( false === (bool) ( $user_input_status['ai_gateway_calls'] ?? true ), 'User input status must not call the AI gateway.', $user_input_status );
wp_agent_no_live_contract_assert( false === (bool) ( $user_input_status['github_calls'] ?? true ), 'User input status must not call GitHub.', $user_input_status );
$cases['final_live_user_input_status'] = array(
	'user_input_ready'    => (bool) ( $user_input_status['user_input_ready'] ?? true ),
	'github_inputs_ready' => (bool) ( $user_input_status['github_inputs_ready'] ?? true ),
	'soak_inputs_ready'   => (bool) ( $user_input_status['soak_inputs_ready'] ?? true ),
	'pending_user_inputs' => $user_input_status['pending_user_inputs'] ?? array(),
	'pending_review_items' => $user_input_status['pending_review_items'] ?? array(),
);

$user_input_contract = wp_agent_no_live_contract_run_case( 'final live user input status contract', array(
	$php,
	$plugin_dir . '/tests/final-live-user-input-status-contract.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $user_input_contract['default_user_input_ready'] ?? true ), 'User input status contract should reject the example template.', $user_input_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $user_input_contract['valid_user_input_ready'] ?? false ), 'User input status contract should accept a safe ignored fixture.', $user_input_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $user_input_contract['valid_reviewed_env_ready'] ?? false ), 'User input status contract should prove the valid fixture is reviewed-env ready.', $user_input_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $user_input_contract['invalid_source_rejected'] ?? false ), 'User input status contract should reject localhost/private source URLs.', $user_input_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $user_input_contract['secret_assignment_rejected'] ?? false ), 'User input status contract should reject inline token assignments.', $user_input_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $user_input_contract['ai_gateway_calls'] ?? true ), 'User input status contract must not call the AI gateway.', $user_input_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $user_input_contract['github_calls'] ?? true ), 'User input status contract must not call GitHub.', $user_input_contract );
$cases['final_live_user_input_status_contract'] = array(
	'default_user_input_ready' => (bool) ( $user_input_contract['default_user_input_ready'] ?? true ),
	'valid_user_input_ready'   => (bool) ( $user_input_contract['valid_user_input_ready'] ?? false ),
	'invalid_source_rejected'  => (bool) ( $user_input_contract['invalid_source_rejected'] ?? false ),
	'secret_assignment_rejected' => (bool) ( $user_input_contract['secret_assignment_rejected'] ?? false ),
);

$reviewed_env_status = wp_agent_no_live_contract_run_case( 'final live reviewed env status', array(
	$php,
	$plugin_dir . '/tests/final-live-reviewed-env-status.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $reviewed_env_status['reviewed_env_ready'] ?? true ), 'Reviewed env status should reject the example template.', $reviewed_env_status );
wp_agent_no_live_contract_assert( true === (bool) ( $reviewed_env_status['path_is_example'] ?? false ), 'Reviewed env status should identify the example template.', $reviewed_env_status );
wp_agent_no_live_contract_assert( false === (bool) ( $reviewed_env_status['path_ignored_by_git'] ?? true ), 'Reviewed env status should require an ignored reviewed env path.', $reviewed_env_status );
wp_agent_no_live_contract_assert( true === (bool) ( $reviewed_env_status['path_tracked_by_git'] ?? false ), 'Reviewed env status should expose that the example template is tracked.', $reviewed_env_status );
wp_agent_no_live_contract_assert( false === (bool) ( $reviewed_env_status['secret_assignments'] ?? true ), 'Reviewed env status should not report secrets in the example template.', $reviewed_env_status );
wp_agent_no_live_contract_assert( false === (bool) ( $reviewed_env_status['ai_gateway_calls'] ?? true ), 'Reviewed env status must not call the AI gateway.', $reviewed_env_status );
wp_agent_no_live_contract_assert( false === (bool) ( $reviewed_env_status['github_calls'] ?? true ), 'Reviewed env status must not call GitHub.', $reviewed_env_status );
$cases['final_live_reviewed_env_status'] = array(
	'reviewed_env_ready'  => (bool) ( $reviewed_env_status['reviewed_env_ready'] ?? true ),
	'path_is_example'     => (bool) ( $reviewed_env_status['path_is_example'] ?? false ),
	'path_ignored_by_git' => (bool) ( $reviewed_env_status['path_ignored_by_git'] ?? true ),
	'path_tracked_by_git' => (bool) ( $reviewed_env_status['path_tracked_by_git'] ?? false ),
	'commands_executable' => (bool) ( $reviewed_env_status['commands_executable'] ?? true ),
);

$reviewed_env_contract = wp_agent_no_live_contract_run_case( 'final live reviewed env status contract', array(
	$php,
	$plugin_dir . '/tests/final-live-reviewed-env-status-contract.php',
) );
wp_agent_no_live_contract_assert( false === (bool) ( $reviewed_env_contract['default_reviewed_env_ready'] ?? true ), 'Reviewed env status contract should reject the example template.', $reviewed_env_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $reviewed_env_contract['valid_reviewed_fixture_ready'] ?? false ), 'Reviewed env status contract should accept a safe ignored fixture.', $reviewed_env_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $reviewed_env_contract['valid_path_ignored_by_git'] ?? false ), 'Reviewed env status contract should prove valid fixtures are ignored by Git.', $reviewed_env_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $reviewed_env_contract['outside_path_rejected'] ?? false ), 'Reviewed env status contract should reject outside-repo env files.', $reviewed_env_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $reviewed_env_contract['invalid_source_url_rejected'] ?? false ), 'Reviewed env status contract should reject invalid source URLs.', $reviewed_env_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $reviewed_env_contract['secret_assignment_rejected'] ?? false ), 'Reviewed env status contract should reject inline token assignments.', $reviewed_env_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $reviewed_env_contract['ai_gateway_calls'] ?? true ), 'Reviewed env status contract must not call the AI gateway.', $reviewed_env_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $reviewed_env_contract['github_calls'] ?? true ), 'Reviewed env status contract must not call GitHub.', $reviewed_env_contract );
$cases['final_live_reviewed_env_status_contract'] = array(
	'default_reviewed_env_ready'   => (bool) ( $reviewed_env_contract['default_reviewed_env_ready'] ?? true ),
	'valid_reviewed_fixture_ready' => (bool) ( $reviewed_env_contract['valid_reviewed_fixture_ready'] ?? false ),
	'outside_path_rejected'        => (bool) ( $reviewed_env_contract['outside_path_rejected'] ?? false ),
	'invalid_source_url_rejected'  => (bool) ( $reviewed_env_contract['invalid_source_url_rejected'] ?? false ),
	'secret_assignment_rejected'   => (bool) ( $reviewed_env_contract['secret_assignment_rejected'] ?? false ),
);

$blocker_status = wp_agent_no_live_contract_run_case( 'final live blocker status', array(
	$php,
	$plugin_dir . '/tests/final-live-blocker-status.php',
) );
wp_agent_no_live_contract_assert( array( 6, 9 ) === array_values( $blocker_status['partial_rows'] ?? array() ), 'Final live blocker status should report only #6 and #9 as partial.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['ready_for_live_execution'] ?? true ), 'Final live blocker status should not mark example inputs executable.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['ready_to_mark_complete'] ?? true ), 'Final live blocker status should not mark goals complete before final artifacts exist.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['review_packet']['packet_ready'] ?? true ), 'Final live blocker status should keep review packet template not ready.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['review_packet']['path_ignored_by_git'] ?? true ), 'Final live blocker status should expose ignored review packet requirement.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['user_input']['user_input_ready'] ?? true ), 'Final live blocker status should keep example user inputs not ready.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['user_input']['github_inputs_ready'] ?? true ), 'Final live blocker status should expose missing GitHub inputs.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['user_input']['soak_inputs_ready'] ?? true ), 'Final live blocker status should expose missing soak approval review.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['reviewed_env']['reviewed_env_ready'] ?? true ), 'Final live blocker status should keep example reviewed env not ready.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['reviewed_env']['path_ignored_by_git'] ?? true ), 'Final live blocker status should expose ignored reviewed env requirement.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['git_hygiene']['remote_push'] ?? true ), 'Final live blocker status should expose remote_push=false.', $blocker_status );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status['git_hygiene']['remote_push_disabled'] ?? false ), 'Final live blocker status should expose disabled remote push URLs.', $blocker_status );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status['command_plan']['placeholder_rejected'] ?? false ), 'Final live blocker status should preserve placeholder rejection.', $blocker_status );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status['command_plan']['approval_phrase_rejected'] ?? false ), 'Final live blocker status should preserve approval phrase rejection.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['command_plan']['ready_for_live_execution'] ?? true ), 'Final live blocker command plan should not mark live execution ready.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['command_plan']['review_packet_ready'] ?? true ), 'Final live blocker command plan should keep review packet not ready.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['command_plan']['review_packet_env_consistent'] ?? true ), 'Final live blocker command plan should expose packet/env mismatch for default templates.', $blocker_status );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status['command_plan']['review_packet_before_live'] ?? false ), 'Final live blocker command plan should preserve review-packet-before-live ordering.', $blocker_status );
wp_agent_no_live_contract_assert( false !== strpos( implode( "\n", $blocker_status['next_actions'] ?? array() ), 'ready_for_live_execution=true' ) && false !== strpos( implode( "\n", $blocker_status['next_actions'] ?? array() ), 'review_packet_env_consistent=true' ), 'Final live blocker status should include command-plan readiness and packet/env consistency in next actions.', $blocker_status );
wp_agent_no_live_contract_assert( false !== strpos( implode( "\n", $blocker_status['operator_init_commands'] ?? array() ), 'git check-ignore -q final-live-inputs.' ) && false !== strpos( implode( "\n", $blocker_status['operator_init_commands'] ?? array() ), 'git check-ignore -q final-live-review-packet-' ), 'Final live blocker status should expose safe local init commands for ignored reviewed inputs.', $blocker_status );
wp_agent_no_live_contract_assert( false !== strpos( (string) ( $blocker_status['operator_secret_rule'] ?? '' ), 'WP_AGENT_LIVE_GITHUB_TOKEN' ), 'Final live blocker status should expose the no-secret operator rule.', $blocker_status );
wp_agent_no_live_contract_assert( in_array( 'official_skills_github_repository', $blocker_status['external_blockers'] ?? array(), true ), 'Final live blocker status should retain the official Skills GitHub blocker.', $blocker_status );
wp_agent_no_live_contract_assert( in_array( 'multi_hour_live_soak_budget_and_approval', $blocker_status['external_blockers'] ?? array(), true ), 'Final live blocker status should retain the multi-hour soak blocker.', $blocker_status );
wp_agent_no_live_contract_assert( in_array( 'github', $blocker_status['completion_gate']['missing_or_invalid_artifacts'] ?? array(), true ), 'Final live blocker status should report the missing GitHub artifact.', $blocker_status );
wp_agent_no_live_contract_assert( in_array( 'soak', $blocker_status['completion_gate']['missing_or_invalid_artifacts'] ?? array(), true ), 'Final live blocker status should report the missing soak artifact.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['ai_gateway_calls'] ?? true ), 'Final live blocker status must not call the AI gateway.', $blocker_status );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status['github_calls'] ?? true ), 'Final live blocker status must not call GitHub.', $blocker_status );
$cases['final_live_blocker_status'] = array(
	'partial_rows'             => $blocker_status['partial_rows'] ?? array(),
	'external_blockers'        => $blocker_status['external_blockers'] ?? array(),
	'ready_for_live_execution' => (bool) ( $blocker_status['ready_for_live_execution'] ?? true ),
	'command_plan_ready_for_live_execution' => (bool) ( $blocker_status['command_plan']['ready_for_live_execution'] ?? true ),
	'command_plan_review_packet_ready' => (bool) ( $blocker_status['command_plan']['review_packet_ready'] ?? true ),
	'command_plan_review_packet_env_consistent' => (bool) ( $blocker_status['command_plan']['review_packet_env_consistent'] ?? true ),
	'command_plan_review_packet_before_live' => (bool) ( $blocker_status['command_plan']['review_packet_before_live'] ?? false ),
	'next_actions_command_plan_ready' => false !== strpos( implode( "\n", $blocker_status['next_actions'] ?? array() ), 'ready_for_live_execution=true' ),
	'operator_init_commands' => false !== strpos( implode( "\n", $blocker_status['operator_init_commands'] ?? array() ), 'git check-ignore -q final-live-inputs.' ),
	'operator_secret_rule' => false !== strpos( (string) ( $blocker_status['operator_secret_rule'] ?? '' ), 'WP_AGENT_LIVE_GITHUB_TOKEN' ),
	'next_actions_summary_markers' => false !== strpos( implode( "\n", $blocker_status['next_actions'] ?? array() ), 'final-live-acceptance-summary-YYYYMMDD.md' ) && false !== strpos( implode( "\n", $blocker_status['next_actions'] ?? array() ), 'packet_ready=true' ) && false !== strpos( implode( "\n", $blocker_status['next_actions'] ?? array() ), 'review_packet_ready=true' ) && false !== strpos( implode( "\n", $blocker_status['next_actions'] ?? array() ), 'review_packet_env_consistent=true' ),
	'summary_required_markers' => $blocker_status['summary_required_markers'] ?? array(),
	'ready_to_mark_complete'   => (bool) ( $blocker_status['ready_to_mark_complete'] ?? true ),
	'review_packet_ready'      => (bool) ( $blocker_status['review_packet']['packet_ready'] ?? true ),
	'review_packet_ignored'    => (bool) ( $blocker_status['review_packet']['path_ignored_by_git'] ?? true ),
	'user_input_ready'         => (bool) ( $blocker_status['user_input']['user_input_ready'] ?? true ),
	'github_inputs_ready'      => (bool) ( $blocker_status['user_input']['github_inputs_ready'] ?? true ),
	'soak_inputs_ready'        => (bool) ( $blocker_status['user_input']['soak_inputs_ready'] ?? true ),
	'reviewed_env_ready'       => (bool) ( $blocker_status['reviewed_env']['reviewed_env_ready'] ?? true ),
	'reviewed_env_ignored'     => (bool) ( $blocker_status['reviewed_env']['path_ignored_by_git'] ?? true ),
	'remote_push'              => (bool) ( $blocker_status['git_hygiene']['remote_push'] ?? true ),
	'remote_push_disabled'     => (bool) ( $blocker_status['git_hygiene']['remote_push_disabled'] ?? false ),
	'missing_or_invalid_artifacts' => $blocker_status['completion_gate']['missing_or_invalid_artifacts'] ?? array(),
);

$blocker_status_contract = wp_agent_no_live_contract_run_case( 'final live blocker status contract', array(
	$php,
	$plugin_dir . '/tests/final-live-blocker-status-contract.php',
) );
wp_agent_no_live_contract_assert( array( 6, 9 ) === array_values( $blocker_status_contract['default_partial_rows'] ?? array() ), 'Final live blocker status contract should report only #6 and #9 as partial.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_ready_for_live_execution'] ?? true ), 'Final live blocker status contract should keep default inputs non-executable.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_command_plan_ready_for_live_execution'] ?? true ), 'Final live blocker status contract should keep default command plan non-executable.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_ready_to_mark_complete'] ?? true ), 'Final live blocker status contract should keep completion blocked.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['default_next_actions_command_plan'] ?? false ), 'Final live blocker status contract should validate command-plan next actions.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['default_operator_init_commands'] ?? false ), 'Final live blocker status contract should validate local init commands.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['default_operator_init_files_ignored'] ?? false ), 'Final live blocker status contract should verify operator init files are ignored and untracked.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['default_operator_secret_rule'] ?? false ), 'Final live blocker status contract should validate the no-secret operator rule.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['default_next_actions_summary_markers'] ?? false ), 'Final live blocker status contract should validate acceptance-summary marker next actions.', $blocker_status_contract );
wp_agent_no_live_contract_assert( in_array( 'review_packet_env_consistent=true', $blocker_status_contract['summary_required_markers'] ?? array(), true ), 'Final live blocker status contract should expose summary required markers.', $blocker_status_contract );
wp_agent_no_live_contract_assert( in_array( 'chat_stop_availability_playwright=true', $blocker_status_contract['summary_required_markers'] ?? array(), true ), 'Final live blocker status contract should expose Stop availability summary marker.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_review_packet_ready'] ?? true ), 'Final live blocker status contract should keep default review packet not ready.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_command_plan_review_packet_env_consistent'] ?? true ), 'Final live blocker status contract should keep default packet/env consistency false.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_review_packet_ignored'] ?? true ), 'Final live blocker status contract should expose ignored review packet requirement.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_user_input_ready'] ?? true ), 'Final live blocker status contract should keep default user input not ready.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_github_inputs_ready'] ?? true ), 'Final live blocker status contract should expose default GitHub input blockers.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_soak_inputs_ready'] ?? true ), 'Final live blocker status contract should expose default soak input blockers.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_reviewed_env_ready'] ?? true ), 'Final live blocker status contract should keep default reviewed env not ready.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_reviewed_env_ignored'] ?? true ), 'Final live blocker status contract should expose ignored env requirement.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['default_remote_push'] ?? true ), 'Final live blocker status contract should keep remote_push=false.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['default_remote_push_disabled'] ?? false ), 'Final live blocker status contract should keep remote push disabled.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['invalid_reviewed_inputs_rejected'] ?? false ), 'Final live blocker status contract should reject reviewed-looking invalid inputs.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['invalid_user_input_rejected'] ?? false ), 'Final live blocker status contract should reject invalid user inputs.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['invalid_reviewed_env_rejected'] ?? false ), 'Final live blocker status contract should reject invalid reviewed env status.', $blocker_status_contract );
wp_agent_no_live_contract_assert( true === (bool) ( $blocker_status_contract['invalid_source_url_rejected'] ?? false ), 'Final live blocker status contract should expose unsafe source URL blockers.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['secret_assignments'] ?? true ), 'Final live blocker status contract must not expose secret assignments.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['ai_gateway_calls'] ?? true ), 'Final live blocker status contract must not call the AI gateway.', $blocker_status_contract );
wp_agent_no_live_contract_assert( false === (bool) ( $blocker_status_contract['github_calls'] ?? true ), 'Final live blocker status contract must not call GitHub.', $blocker_status_contract );
$cases['final_live_blocker_status_contract'] = array(
	'default_partial_rows'             => $blocker_status_contract['default_partial_rows'] ?? array(),
	'default_ready_for_live_execution' => (bool) ( $blocker_status_contract['default_ready_for_live_execution'] ?? true ),
	'default_command_plan_ready_for_live_execution' => (bool) ( $blocker_status_contract['default_command_plan_ready_for_live_execution'] ?? true ),
	'default_next_actions_command_plan' => (bool) ( $blocker_status_contract['default_next_actions_command_plan'] ?? false ),
	'default_operator_init_commands' => (bool) ( $blocker_status_contract['default_operator_init_commands'] ?? false ),
	'default_operator_init_files_ignored' => (bool) ( $blocker_status_contract['default_operator_init_files_ignored'] ?? false ),
	'default_operator_secret_rule' => (bool) ( $blocker_status_contract['default_operator_secret_rule'] ?? false ),
	'default_next_actions_summary_markers' => (bool) ( $blocker_status_contract['default_next_actions_summary_markers'] ?? false ),
	'default_ready_to_mark_complete'   => (bool) ( $blocker_status_contract['default_ready_to_mark_complete'] ?? true ),
	'default_review_packet_ready'      => (bool) ( $blocker_status_contract['default_review_packet_ready'] ?? true ),
	'default_command_plan_review_packet_env_consistent' => (bool) ( $blocker_status_contract['default_command_plan_review_packet_env_consistent'] ?? true ),
	'default_review_packet_ignored'    => (bool) ( $blocker_status_contract['default_review_packet_ignored'] ?? true ),
	'default_user_input_ready'         => (bool) ( $blocker_status_contract['default_user_input_ready'] ?? true ),
	'default_github_inputs_ready'      => (bool) ( $blocker_status_contract['default_github_inputs_ready'] ?? true ),
	'default_soak_inputs_ready'        => (bool) ( $blocker_status_contract['default_soak_inputs_ready'] ?? true ),
	'default_reviewed_env_ready'       => (bool) ( $blocker_status_contract['default_reviewed_env_ready'] ?? true ),
	'default_reviewed_env_ignored'     => (bool) ( $blocker_status_contract['default_reviewed_env_ignored'] ?? true ),
	'default_remote_push'              => (bool) ( $blocker_status_contract['default_remote_push'] ?? true ),
	'default_remote_push_disabled'     => (bool) ( $blocker_status_contract['default_remote_push_disabled'] ?? false ),
	'invalid_reviewed_inputs_rejected' => (bool) ( $blocker_status_contract['invalid_reviewed_inputs_rejected'] ?? false ),
	'invalid_user_input_rejected'      => (bool) ( $blocker_status_contract['invalid_user_input_rejected'] ?? false ),
	'invalid_reviewed_env_rejected'    => (bool) ( $blocker_status_contract['invalid_reviewed_env_rejected'] ?? false ),
	'invalid_source_url_rejected'      => (bool) ( $blocker_status_contract['invalid_source_url_rejected'] ?? false ),
);

$isolation = wp_agent_no_live_contract_run_case( 'official test isolation audit', array(
	'docker',
	'compose',
	'-p',
	'wp-agent-official',
	'-f',
	$plugin_dir . '/docker-compose.official.yml',
	'--profile',
	'cli',
	'run',
	'--rm',
	'-T',
	'wpcli',
	'wp',
	'eval-file',
	'wp-content/plugins/wp-agent/tests/test-isolation-audit.php',
	'--allow-root',
) );
wp_agent_no_live_contract_assert( (int) ( $isolation['mutating_count'] ?? 0 ) >= 1, 'Isolation audit should inspect deterministic mutating tests.', $isolation );
$cases['test_isolation_audit'] = array(
	'audited_count'  => (int) ( $isolation['audited_count'] ?? 0 ),
	'mutating_count' => (int) ( $isolation['mutating_count'] ?? 0 ),
	'skipped_count'  => (int) ( $isolation['skipped_count'] ?? 0 ),
);

echo json_encode( array(
	'success'            => true,
	'contract'           => 'final_no_live_acceptance',
	'cases'              => $cases,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
	'remote_push'        => (bool) ( $git_hygiene['remote_push'] ?? true ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
