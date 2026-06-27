<?php
/**
 * Host-side final preflight contract check.
 *
 * Runs read-only final acceptance preflight scenarios through the official
 * WP-CLI container. This script does not call GitHub or the AI gateway.
 *
 * Run from the host:
 * php tests/final-preflight-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final preflight contract check must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_preflight_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_preflight_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_preflight_contract_fail( $message, $details );
	}
}

function wp_agent_preflight_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_preflight_contract_run( $env = array() ) {
	$plugin_dir = realpath( dirname( __DIR__ ) );
	wp_agent_preflight_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

	$args = array(
		'docker',
		'compose',
		'-p',
		'wp-agent-official',
		'-f',
		$plugin_dir . DIRECTORY_SEPARATOR . 'docker-compose.official.yml',
		'--profile',
		'cli',
		'run',
		'--rm',
		'-T',
	);
	foreach ( $env as $key => $value ) {
		$args[] = '-e';
		$args[] = $key . '=' . $value;
	}
	$args[] = 'wpcli';
	$args[] = 'wp';
	$args[] = 'eval-file';
	$args[] = 'wp-content/plugins/wp-agent/tests/final-acceptance-preflight.php';
	$args[] = '--allow-root';

	return wp_agent_preflight_contract_command( $args );
}

function wp_agent_preflight_contract_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$json = substr( $output, $start );
	$data = json_decode( $json, true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_preflight_contract_expect_failure( $name, $env, $required_fragments ) {
	$result = wp_agent_preflight_contract_run( $env );
	wp_agent_preflight_contract_assert( 0 !== $result['status'], $name . ' should fail.', array(
		'output' => $result['output'],
	) );
	foreach ( $required_fragments as $fragment ) {
		wp_agent_preflight_contract_assert( false !== strpos( $result['output'], $fragment ), $name . ' did not report expected fragment.', array(
			'fragment' => $fragment,
			'output'   => $result['output'],
		) );
	}
	return $result;
}

$cases = array();

$default = wp_agent_preflight_contract_run();
wp_agent_preflight_contract_assert( 0 === $default['status'], 'Default final preflight report mode should exit successfully.', array(
	'output' => $default['output'],
) );
$default_json = wp_agent_preflight_contract_json( $default['output'] );
wp_agent_preflight_contract_assert( is_array( $default_json ), 'Default final preflight output should include JSON.', array(
	'output' => $default['output'],
) );
wp_agent_preflight_contract_assert( false === (bool) ( $default_json['ready'] ?? true ), 'Default final preflight should report not ready until live inputs are provided.' );
wp_agent_preflight_contract_assert( false === (bool) ( $default_json['all_ready'] ?? true ), 'Default final preflight should report all_ready=false until live inputs are provided.' );
wp_agent_preflight_contract_assert( false === (bool) ( $default_json['gates']['github_skill_store']['token_disclosed'] ?? true ), 'Default final preflight must not disclose GitHub tokens.' );
wp_agent_preflight_contract_assert( true === (bool) ( $default_json['gates']['official_runtime']['database_persistence_declared'] ?? false ), 'Default final preflight should report official database persistence.' );
wp_agent_preflight_contract_assert( true === (bool) ( $default_json['gates']['official_runtime']['official_db_dir_is_default'] ?? false ), 'Default final preflight should report the default official database directory.' );
$cases[] = 'default_report_mode';

wp_agent_preflight_contract_expect_failure( 'GitHub placeholder strict preflight', array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT'       => '1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE'        => 'github',
	'WP_AGENT_LIVE_GITHUB_SKILLS'           => '1',
	'WP_AGENT_LIVE_GITHUB_REPOSITORY'       => 'owner/repo',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH'       => 'skills/example',
	'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY'    => 'quarantine',
), array(
	'replace placeholder GitHub repository/path with official Skill Store coordinates',
) );
$cases[] = 'github_placeholder_strict_failure';

$token_secret = 'wp-agent-contract-secret-token-should-not-print';
$token_report = wp_agent_preflight_contract_run( array(
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE'     => 'github',
	'WP_AGENT_LIVE_GITHUB_TOKEN'         => $token_secret,
) );
wp_agent_preflight_contract_assert( 0 === $token_report['status'], 'GitHub token report mode should exit successfully.', array(
	'output' => $token_report['output'],
) );
wp_agent_preflight_contract_assert( false === strpos( $token_report['output'], $token_secret ), 'Final preflight must not print the GitHub token value.', array(
	'output' => $token_report['output'],
) );
$token_json = wp_agent_preflight_contract_json( $token_report['output'] );
wp_agent_preflight_contract_assert( is_array( $token_json ), 'GitHub token report mode should include JSON.', array(
	'output' => $token_report['output'],
) );
wp_agent_preflight_contract_assert( 'env_present' === (string) ( $token_json['gates']['github_skill_store']['token_state'] ?? '' ), 'GitHub token report mode should expose only env_present token state.' );
wp_agent_preflight_contract_assert( false === (bool) ( $token_json['gates']['github_skill_store']['token_disclosed'] ?? true ), 'GitHub token report mode must mark token_disclosed=false.' );
$cases[] = 'github_env_token_redaction';

wp_agent_preflight_contract_expect_failure( 'Invalid scope strict preflight', array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT' => '1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE'  => 'bogus_scope',
), array(
	'invalid_scope',
	'no_valid_scope',
	'bogus_scope',
) );
$cases[] = 'invalid_scope_strict_failure';

wp_agent_preflight_contract_expect_failure( 'Soak private source URL strict preflight', array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT'              => '1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE'               => 'soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON'               => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED'      => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' => 'approve-multi-hour-soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' => 'drafts_journal_usage',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'          => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'       => '7200',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'  => '7200',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS' => '8',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL'    => 'http://127.0.0.1/news/',
), array(
	'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
) );
$cases[] = 'soak_private_source_url_strict_failure';

wp_agent_preflight_contract_expect_failure( 'Soak upper bound strict preflight', array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT'              => '1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE'               => 'soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON'               => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED'      => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' => 'approve-multi-hour-soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' => 'drafts_journal_usage',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'          => '13',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'       => '28801',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'  => '28801',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS' => '104',
), array(
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS <= 12',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT <= 28800',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS <= 28800',
) );
$cases[] = 'soak_upper_bound_strict_failure';

wp_agent_preflight_contract_expect_failure( 'Soak approval phrase strict preflight', array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT'              => '1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE'               => 'soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON'               => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED'      => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' => 'replace-after-review',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' => 'drafts_journal_usage',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'          => '1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'       => '7200',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'  => '7200',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS' => '8',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL'    => 'https://wordpress.org/news/',
), array(
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak',
) );
$cases[] = 'soak_approval_phrase_strict_failure';

wp_agent_preflight_contract_expect_failure( 'Soak nondefault DB strict preflight', array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT'              => '1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE'               => 'soak',
	'WP_AGENT_OFFICIAL_DB_DIR'                     => '/path/to/wp-agent/database/throwaway-final',
), array(
	'WP_AGENT_OFFICIAL_DB_DIR must use official-mysql for final acceptance',
) );
$cases[] = 'soak_nondefault_db_strict_failure';

$allowed_throwaway = wp_agent_preflight_contract_run( array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT'              => '1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE'               => 'soak',
	'WP_AGENT_OFFICIAL_DB_DIR'                     => '/path/to/wp-agent/database/throwaway-final',
	'WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR' => '1',
) );
wp_agent_preflight_contract_assert( 0 !== $allowed_throwaway['status'], 'Allowed throwaway preflight should still fail on missing live soak inputs.', array(
	'output' => $allowed_throwaway['output'],
) );
wp_agent_preflight_contract_assert( false === strpos( $allowed_throwaway['output'], 'WP_AGENT_OFFICIAL_DB_DIR must use official-mysql for final acceptance' ), 'Allowed throwaway preflight should not report the default database path failure.', array(
	'output' => $allowed_throwaway['output'],
) );
$cases[] = 'soak_nondefault_db_allowed_path_gate';

echo json_encode( array(
	'success' => true,
	'cases'   => $cases,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
