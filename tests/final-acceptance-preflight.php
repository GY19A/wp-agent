<?php
/**
 * Final live acceptance preflight.
 *
 * This script is read-only. It does not call GitHub or the AI gateway.
 *
 * Default mode reports gate readiness and exits successfully. Set
 * WP_AGENT_FINAL_PREFLIGHT_STRICT=1 before a final live run to fail when a
 * required external input or approval flag is missing.
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final acceptance preflight script must run through WP-CLI.\n" );
	exit( 1 );
}

function wp_agent_final_preflight_env( $key, $default = '' ) {
	$value = getenv( $key );
	if ( false === $value || '' === trim( (string) $value ) ) {
		return $default;
	}
	return trim( (string) $value );
}

function wp_agent_final_preflight_enabled( $key ) {
	return '1' === (string) getenv( $key );
}

function wp_agent_final_preflight_positive_int( $key, $default ) {
	$value = (int) getenv( $key );
	return $value > 0 ? $value : (int) $default;
}

function wp_agent_final_preflight_positive_number( $key ) {
	$value = wp_agent_final_preflight_env( $key );
	if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
		return null;
	}
	return (float) $value;
}

function wp_agent_final_preflight_public_source_url( $key, $default ) {
	$value = wp_agent_final_preflight_env( $key, $default );
	$valid = WPAgent_URL_Safety::validate_public_http_url( $value, $key );
	return array(
		'url'   => $value,
		'error' => is_wp_error( $valid ) ? $valid->get_error_message() : '',
	);
}

function wp_agent_final_preflight_scope() {
	$raw     = strtolower( wp_agent_final_preflight_env( 'WP_AGENT_FINAL_PREFLIGHT_SCOPE', 'all' ) );
	$aliases = array(
		'all'                              => array( 'github_skill_store', 'multi_hour_editorial_daemon_soak' ),
		'github'                           => array( 'github_skill_store' ),
		'github_skill_store'               => array( 'github_skill_store' ),
		'skill_store'                      => array( 'github_skill_store' ),
		'soak'                             => array( 'multi_hour_editorial_daemon_soak' ),
		'daemon'                           => array( 'multi_hour_editorial_daemon_soak' ),
		'editorial_daemon'                 => array( 'multi_hour_editorial_daemon_soak' ),
		'multi_hour_editorial_daemon_soak' => array( 'multi_hour_editorial_daemon_soak' ),
	);
	$parts   = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
	$parts   = empty( $parts ) ? array( 'all' ) : $parts;
	$selected = array();
	$invalid  = array();

	foreach ( $parts as $part ) {
		if ( ! isset( $aliases[ $part ] ) ) {
			$invalid[] = $part;
			continue;
		}
		foreach ( $aliases[ $part ] as $gate ) {
			$selected[ $gate ] = true;
		}
	}

	return array(
		'raw'      => $raw,
		'selected' => array_keys( $selected ),
		'invalid'  => $invalid,
	);
}

function wp_agent_final_preflight_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

$strict = wp_agent_final_preflight_enabled( 'WP_AGENT_FINAL_PREFLIGHT_STRICT' );
$scope  = wp_agent_final_preflight_scope();

$store       = WPAgent_Skills::github_store_defaults();
$store_check = WPAgent_Skills::github_store_readiness();
$repository  = WPAgent_Skills::normalize_github_repository_value(
	wp_agent_final_preflight_env( 'WP_AGENT_LIVE_GITHUB_REPOSITORY', $store['repository'] ?? '' )
);
$skill_path  = WPAgent_Skills::normalize_skill_package_path(
	wp_agent_final_preflight_env( 'WP_AGENT_LIVE_GITHUB_SKILL_PATH', $store['skill_path'] ?? '' )
);
$ref         = WPAgent_Skills::sanitize_git_ref_value(
	wp_agent_final_preflight_env( 'WP_AGENT_LIVE_GITHUB_REF', $store['ref'] ?? 'main' )
);
$review_policy_env      = wp_agent_final_preflight_env( 'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY' );
$stored_review_policy   = get_option( 'wp_agent_github_activation_policy', false );
$allowed_review_policies = WPAgent_Skills::github_activation_policies();
$policy_source          = 'missing';
$policy                 = '';
if ( '' !== $review_policy_env ) {
	$review_policy_env = sanitize_key( $review_policy_env );
	$policy_source     = 'env';
	$policy            = in_array( $review_policy_env, $allowed_review_policies, true ) ? $review_policy_env : '';
} elseif ( false !== $stored_review_policy ) {
	$policy_source = 'settings';
	$policy        = (string) ( $store['activation_policy'] ?? 'quarantine' );
}
$has_env_token = '' !== wp_agent_final_preflight_env( 'WP_AGENT_LIVE_GITHUB_TOKEN' );
$github_live_enabled = wp_agent_final_preflight_enabled( 'WP_AGENT_LIVE_GITHUB_SKILLS' );
$github_placeholder_reason = WPAgent_Skills::github_store_placeholder_reason( $repository, $skill_path );

$github_missing = array();
if ( '' === $repository ) {
	$github_missing[] = 'WP_AGENT_LIVE_GITHUB_REPOSITORY';
}
if ( '' === $skill_path ) {
	$github_missing[] = 'WP_AGENT_LIVE_GITHUB_SKILL_PATH';
}
if ( '' === $ref ) {
	$github_missing[] = 'WP_AGENT_LIVE_GITHUB_REF';
}
if ( ! $github_live_enabled ) {
	$github_missing[] = 'WP_AGENT_LIVE_GITHUB_SKILLS=1';
}
if ( '' === $policy ) {
	$github_missing[] = 'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY or configured Skills Store review policy';
}
if ( '' !== $github_placeholder_reason ) {
	$github_missing[] = 'replace placeholder GitHub repository/path with official Skill Store coordinates';
}
if ( 'unreadable' === ( $store_check['token_state'] ?? '' ) && ! $has_env_token ) {
	$github_missing[] = 'usable GitHub token or no stored unreadable token';
}

$ai      = WPAgent::ai_provider_readiness();
$daemon  = WPAgent_Daemon::status();
$diag    = WPAgent_Diagnostics::runtime( array( 'daemon' => $daemon ) );
$queue   = is_array( $diag['queue'] ?? null ) ? $diag['queue'] : array();
$queue_summary = array(
	'counts'                => $queue['counts'] ?? array(),
	'claimable_count'       => (int) ( $queue['claimable_count'] ?? 0 ),
	'retry_scheduled_count' => (int) ( $queue['retry_scheduled_count'] ?? 0 ),
	'lock_seconds'          => (int) ( $queue['lock_seconds'] ?? 0 ),
	'last_failure_age'      => $queue['last_failure_age'] ?? null,
);
$runs    = wp_agent_final_preflight_positive_int( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS', 0 );
$timeout = wp_agent_final_preflight_positive_int( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT', 0 );
$soak    = wp_agent_final_preflight_positive_int( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS', 0 );
$usage   = wp_agent_final_preflight_positive_int( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS', 0 );
$memory_delta_max = wp_agent_final_preflight_env( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_MEMORY_DELTA_MAX' );
$editorial_live_enabled = wp_agent_final_preflight_enabled( 'WP_AGENT_LIVE_EDITORIAL_DAEMON' );
$editorial_approved     = wp_agent_final_preflight_enabled( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED' );
$approval_phrase        = wp_agent_final_preflight_env( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE' );
$approval_phrase_expected = 'approve-multi-hour-soak';
$approval_phrase_confirmed = hash_equals( $approval_phrase_expected, $approval_phrase );
$cost_budget_usd        = wp_agent_final_preflight_positive_number( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD' );
$artifact_policy        = wp_agent_final_preflight_env( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY' );
$min_soak_seconds       = wp_agent_final_preflight_positive_int( 'WP_AGENT_FINAL_PREFLIGHT_MIN_SOAK_SECONDS', 7200 );
$max_runs               = 12;
$max_soak_seconds       = 28800;
$allowed_artifact_policies = array( 'drafts_journal_usage', 'drafts_journal_usage_media' );
$source_url_check       = wp_agent_final_preflight_public_source_url( 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL', 'https://wordpress.org/news/' );
$official_db_dir        = wp_agent_final_preflight_env( 'WP_AGENT_OFFICIAL_DB_DIR' );
$official_db_root       = '/path/to/wp-agent/database';
$official_db_dir_default = $official_db_root . '/official-mysql';
$official_db_dir_normalized = rtrim( str_replace( '\\', '/', $official_db_dir ), '/' );
$plugin_database_dir    = dirname( __DIR__ ) . '/database';
$database_persistence_declared = '' !== $official_db_dir;
$database_outside_plugin = ! is_dir( $plugin_database_dir );
$official_db_dir_is_absolute = '' !== $official_db_dir_normalized && 0 === strpos( $official_db_dir_normalized, '/' );
$official_db_dir_under_root = $official_db_dir_is_absolute && (
	$official_db_root === $official_db_dir_normalized ||
	0 === strpos( $official_db_dir_normalized . '/', $official_db_root . '/' )
);
$official_db_dir_is_default = $official_db_dir_normalized === $official_db_dir_default;
$allow_nondefault_db_dir = wp_agent_final_preflight_enabled( 'WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR' );

$soak_missing = array();
if ( ! $editorial_live_enabled ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON=1';
}
if ( ! $editorial_approved ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1';
}
if ( ! $approval_phrase_confirmed ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=' . $approval_phrase_expected;
}
if ( null === $cost_budget_usd ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD';
}
if ( ! in_array( $artifact_policy, $allowed_artifact_policies, true ) ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY';
}
if ( empty( $ai['content_ready'] ) ) {
	$soak_missing[] = 'AI content readiness';
}
if ( empty( $daemon['running'] ) ) {
	$soak_missing[] = 'resident daemon running in the official WordPress container';
}
if ( $runs <= 0 ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS';
}
if ( $runs > $max_runs ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS <= ' . $max_runs;
}
if ( $timeout <= 0 ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT';
}
if ( $timeout > $max_soak_seconds ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT <= ' . $max_soak_seconds;
}
if ( $soak < $min_soak_seconds ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS >= ' . $min_soak_seconds;
}
if ( $soak > $max_soak_seconds ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS <= ' . $max_soak_seconds;
}
if ( $timeout > 0 && $soak > $timeout ) {
	$soak_missing[] = 'timeout greater than or equal to soak seconds';
}
if ( $usage <= 0 ) {
	$soak_missing[] = 'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS';
}
if ( '' !== $source_url_check['error'] ) {
	$soak_missing[] = 'public WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL';
}
if ( ! $database_persistence_declared ) {
	$soak_missing[] = 'WP_AGENT_OFFICIAL_DB_DIR declared by official compose';
}
if ( $database_persistence_declared && ! $official_db_dir_is_absolute ) {
	$soak_missing[] = 'WP_AGENT_OFFICIAL_DB_DIR must be an absolute host path';
}
if ( $database_persistence_declared && ! $official_db_dir_under_root ) {
	$soak_missing[] = 'WP_AGENT_OFFICIAL_DB_DIR must stay under /path/to/wp-agent/database';
}
if ( $database_persistence_declared && ! $official_db_dir_is_default && ! $allow_nondefault_db_dir ) {
	$soak_missing[] = 'WP_AGENT_OFFICIAL_DB_DIR must use official-mysql for final acceptance, or explicitly set WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR=1 for an approved throwaway database';
}
if ( ! $database_outside_plugin ) {
	$soak_missing[] = 'database directory must not exist inside the plugin directory';
}

$github_ready = empty( $github_missing );
$soak_ready   = empty( $soak_missing );
$all_ready    = $github_ready && $soak_ready;
$gate_ready   = array(
	'github_skill_store'               => $github_ready,
	'multi_hour_editorial_daemon_soak' => $soak_ready,
);
$strict_failures = array();
foreach ( $scope['invalid'] as $invalid_scope ) {
	$strict_failures['invalid_scope'][] = $invalid_scope;
}
if ( empty( $scope['selected'] ) ) {
	$strict_failures['invalid_scope'][] = 'no_valid_scope';
}
if ( in_array( 'github_skill_store', $scope['selected'], true ) && ! $github_ready ) {
	$strict_failures['github_skill_store'] = $github_missing;
}
if ( in_array( 'multi_hour_editorial_daemon_soak', $scope['selected'], true ) && ! $soak_ready ) {
	$strict_failures['multi_hour_editorial_daemon_soak'] = $soak_missing;
}
$ready = empty( $strict_failures );
if ( ! $strict && empty( $scope['invalid'] ) ) {
	$ready = true;
	foreach ( $scope['selected'] as $selected_gate ) {
		$ready = $ready && ! empty( $gate_ready[ $selected_gate ] );
	}
}

$result = array(
	'success' => ! ( $strict && ! $ready ),
	'strict'  => $strict,
	'scope'   => array(
		'raw'      => $scope['raw'],
		'selected' => $scope['selected'],
		'invalid'  => $scope['invalid'],
	),
	'ready'   => $ready,
	'all_ready' => $all_ready,
	'gates'   => array(
		'github_skill_store' => array(
			'ready'                  => $github_ready,
			'live_flag_enabled'      => $github_live_enabled,
			'repository'             => $repository,
			'ref'                    => $ref,
			'skill_path'             => $skill_path,
			'placeholder_reason'     => $github_placeholder_reason,
			'activation_policy'      => $policy,
			'activation_policy_source' => $policy_source,
			'activation_policy_options' => $allowed_review_policies,
			'token_configured'       => ! empty( $store_check['token_configured'] ) || $has_env_token,
			'token_state'            => $has_env_token ? 'env_present' : ( $store_check['token_state'] ?? 'not_configured' ),
			'token_disclosed'        => false,
			'missing'                => $github_missing,
			'configured_default'     => ! empty( $store['configured'] ),
			'store_readiness_action' => $store_check['next_action'] ?? '',
		),
		'multi_hour_editorial_daemon_soak' => array(
			'ready'                 => $soak_ready,
			'live_flag_enabled'     => $editorial_live_enabled,
			'approval_flag_enabled' => $editorial_approved,
			'approval_phrase_confirmed' => $approval_phrase_confirmed,
			'cost_budget_usd'       => $cost_budget_usd,
			'artifact_policy'       => $artifact_policy,
			'artifact_policy_options' => $allowed_artifact_policies,
			'source_url'            => $source_url_check['url'],
			'source_url_error'      => $source_url_check['error'],
			'ai_ready'              => ! empty( $ai['ready'] ),
			'ai_content_ready'      => ! empty( $ai['content_ready'] ),
			'api_key_state'         => $ai['api_key_state'] ?? '',
			'model'                 => $ai['model'] ?? '',
			'image_model'           => $ai['image_model'] ?? '',
			'base_url_host'         => $ai['base_url_host'] ?? '',
			'daemon_running'        => ! empty( $daemon['running'] ),
			'daemon_status'         => $daemon['status'] ?? '',
			'daemon_liveness'       => $daemon['liveness_source'] ?? '',
			'heartbeat_age'         => $daemon['heartbeat_age'] ?? null,
			'configured_runs'       => $runs,
			'configured_timeout'    => $timeout,
			'configured_soak'       => $soak,
			'min_soak_seconds'      => $min_soak_seconds,
			'max_runs'              => $max_runs,
			'max_soak_seconds'      => $max_soak_seconds,
			'max_usage_rows'        => $usage,
			'memory_delta_guard'    => '' !== $memory_delta_max ? (int) $memory_delta_max : null,
			'official_db_dir'       => $official_db_dir,
			'official_db_dir_root'  => $official_db_root,
			'official_db_dir_is_absolute' => $official_db_dir_is_absolute,
			'official_db_dir_under_root' => $official_db_dir_under_root,
			'official_db_dir_is_default' => $official_db_dir_is_default,
			'allow_nondefault_db_dir' => $allow_nondefault_db_dir,
			'database_persistence_declared' => $database_persistence_declared,
			'database_directory_inside_plugin' => is_dir( $plugin_database_dir ),
			'missing'               => $soak_missing,
		),
		'official_runtime' => array(
			'home_url'              => home_url(),
			'plugin_active'         => function_exists( 'is_plugin_active' ) ? is_plugin_active( 'wp-agent/wp-agent.php' ) : true,
			'php_sapi'              => PHP_SAPI,
			'php_version'           => PHP_VERSION,
			'runtime_root'          => WPAgent_Sandbox::runtime_root(),
			'database_ok'           => ! empty( $diag['database']['ok'] ),
			'database_name'         => defined( 'DB_NAME' ) ? DB_NAME : '',
			'database_host'         => defined( 'DB_HOST' ) ? DB_HOST : '',
			'table_prefix'          => isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : '',
			'official_db_dir'       => $official_db_dir,
			'official_db_dir_root'  => $official_db_root,
			'official_db_dir_default' => $official_db_dir_default,
			'official_db_dir_is_absolute' => $official_db_dir_is_absolute,
			'official_db_dir_under_root' => $official_db_dir_under_root,
			'official_db_dir_is_default' => $official_db_dir_is_default,
			'allow_nondefault_db_dir' => $allow_nondefault_db_dir,
			'database_persistence_declared' => $database_persistence_declared,
			'database_directory_inside_plugin' => is_dir( $plugin_database_dir ),
			'queue'                 => $queue_summary,
			'opcache_cli_enabled'   => $diag['opcache']['enable_cli'] ?? null,
			'security'              => $diag['security'] ?? array(),
		),
	),
	'next_actions' => $strict_failures,
);

if ( $strict && ! $ready ) {
	wp_agent_final_preflight_fail( 'Final live acceptance preflight gates are not ready.', $result );
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
