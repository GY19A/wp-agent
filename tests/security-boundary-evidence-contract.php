<?php
/**
 * Host-side security boundary evidence contract.
 *
 * Verifies the no-VM security model remains backed by auditable source and
 * test evidence. This script only reads local files; it does not start Docker,
 * call GitHub, call the AI gateway, or mutate WordPress state.
 *
 * Run from the host:
 * php tests/security-boundary-evidence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This security boundary evidence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_security_boundary_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_security_boundary_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_security_boundary_fail( $message, $details );
	}
}

function wp_agent_security_boundary_read( $plugin_dir, $relative_path ) {
	$path = $plugin_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
	wp_agent_security_boundary_assert( is_file( $path ), 'Required security evidence file is missing.', array(
		'file' => $relative_path,
	) );

	$text = file_get_contents( $path );
	wp_agent_security_boundary_assert( is_string( $text ) && '' !== $text, 'Required security evidence file could not be read.', array(
		'file' => $relative_path,
	) );

	return $text;
}

function wp_agent_security_boundary_require_markers( $file, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_security_boundary_assert( empty( $missing ), $file . ' is missing required security markers.', array(
		'missing' => $missing,
	) );

	return count( $markers );
}

function wp_agent_security_boundary_assert_no_raw_secrets( $file, $text ) {
	foreach ( array(
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/https?:\/\/[^\/\s:@]+:[^\/\s@]+@/i',
	) as $pattern ) {
		wp_agent_security_boundary_assert( 1 !== preg_match( $pattern, $text ), $file . ' appears to contain a raw secret or credentialed URL.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_security_boundary_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$evidence_scripts = array(
	'tests/security-regression.php' => array(
		'SSRF guard',
		'http://169.254.169.254/latest/meta-data/',
		'file:///etc/passwd',
		'Workspace write guard',
		'../escape.txt',
		'security/bad.php',
		'Executable Skill body should be rejected.',
		'Shell execution Skill body should be rejected.',
		'Publishing should require confirmation.',
		'Secret write response should not echo the secret.',
		'Secret read should return only set/not set state.',
		'Runtime root must not sit under public WordPress paths',
		'Invalid source URL metadata must fail before creating a post',
		'Non-image featured attachment must fail before creating a post.',
	),
	'tests/runtime-root-diagnostics.php' => array(
		'Relative runtime root should be rejected.',
		'Traversal runtime root should be rejected.',
		'Plugin-directory runtime root should be rejected.',
		'Settings sanitizer should preserve previous root when public path is rejected.',
		'Runtime diagnostics should include candidate statuses.',
		'Active fallback runtime root must not live under plugin directory.',
		'register_shutdown_function',
	),
	'tests/code-execution-fail-closed.php' => array(
		'Sandbox backend should be disabled in the official container.',
		'Code execution should be disabled without a hardened backend.',
		'Raw process fallback must be disabled.',
		'Restricted PHP CLI backend must not be opted in by default.',
		'microVM support should be removed from the plugin runtime path.',
		'execute_code tool should not be model-visible when unavailable.',
		'No hardened sandbox execution backend',
		'execute_code should not be exposed in tool definitions while unavailable.',
		'Workspace root must not live under plugin directory.',
	),
	'tests/code-execution-php-cli-opt-in.php' => array(
		'wp_agent_enable_php_cli_execution',
		'Restricted PHP CLI backend should be explicitly opted in.',
		'Dangerous process functions should be disabled inside the snippet.',
		'URL fopen should be disabled inside the snippet.',
		'Snippet should not write outside the ephemeral workspace.',
		'Disallowed executable output should not be imported.',
		'Long-running snippets should time out.',
		'register_shutdown_function',
	),
	'tests/skill-package-security.php' => array(
		'Bounded agent user third-party package action',
		'Skill package mutation should require confirmation',
		'Valid fixture package should enter quarantine.',
		'Quarantine lock file must live under runtime root.',
		'Quarantine lock file must not live under plugin directory.',
		'Executable packaged Skill body',
		'Invalid packaged Skill file path',
		'Oversized packaged Skill body',
		'Installed package must live under runtime root.',
		'Installed package must not live under plugin directory.',
		'Copy from outside Skills runtime root',
		'Copy to outside Skills runtime root',
	),
	'tests/skill-permission-tool-gate.php' => array(
		"'code_execution' => false",
		'skill_permission_denied',
		'Do not create categories, fetch the web, generate images, or run code.',
		'permissions_for_skill should retain code_execution=false.',
		'permissions_for_skill',
		'register_shutdown_function',
	),
	'tests/final-preflight-contract.php' => array(
		'github_env_token_redaction',
		'GitHub token report mode must mark token_disclosed=false.',
		'soak_private_source_url_strict_failure',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
		'soak_upper_bound_strict_failure',
		'soak_nondefault_db_strict_failure',
	),
	'tests/live-script-gates-contract.php' => array(
		'gate must appear before credential, network, import, or model work.',
		'WP_AGENT_LIVE_GITHUB_SKILLS',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON',
		'WP_AGENT_IMPORT_LIVE_AI_SETTINGS',
		'github_request',
		'ai_gateway',
	),
);

$implementation_files = array(
	'includes/class-wp-agent-url-safety.php' => array(
		'validate_public_http_url',
		'is_localhost_name',
		'resolve_host_ips',
		'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE',
		'dns_get_record',
		'gethostbyname',
	),
	'includes/class-wp-agent-sandbox.php' => array(
		'const ALLOWED_EXT',
		'const MAX_BYTES',
		'const MAX_FILES',
		'runtime_root_status',
		'not_absolute',
		'traversal',
		'public_path',
		'is_allowed_runtime_root',
		'Path escapes sandbox.',
		'is_private_rel',
	),
	'includes/class-wp-agent-sandbox-broker.php' => array(
		'raw_process_fallback',
		'WP_AGENT_ENABLE_PHP_CLI_EXECUTION',
		'microvm_removed',
		'open_basedir',
		'disable_functions',
		'allow_url_fopen',
		'MAX_IMPORTED_FILES',
		'MAX_IMPORTED_BYTES',
		'proc_open( $cmd, $descriptors, $pipes, $cwd, array() )',
	),
	'includes/class-wp-agent-skills.php' => array(
		'github_store_placeholder_reason',
		'validate_body',
		'Skills are instructions only; executable code patterns are not allowed.',
		'normalize_package_path',
		'MAX_PACKAGE_FILE_BYTES',
		'MAX_PACKAGE_TOTAL_BYTES',
		'quarantine_package',
		'runtime_path_within_skills_dir',
		'WPAgent::decrypt( $configured )',
	),
	'includes/class-wp-agent-permissions.php' => array(
		'requires_confirmation',
		'execute_code',
		'install_github',
		'activate_quarantine',
		'pin_package',
		'rollback_package',
		'RATE_LIMIT_PER_HOUR',
	),
	'includes/class-wp-agent-agent.php' => array(
		'No confirmation token is exposed to the model.',
		'redact_params',
		'password',
		'api_key',
		'token',
		'secret',
		'code',
	),
	'includes/class-wp-agent-diagnostics.php' => array(
		'Bearer [redacted]',
		'[redacted-key]',
		'queue_error_summary',
	),
	'includes/class-wp-agent-daemon.php' => array(
		'Bearer [redacted]',
		'[redacted-key]',
		'error_summary',
	),
);

$documentation_files = array(
	'README.md' => array(
		'Code execution is disabled unless an explicitly enabled restricted backend passes runtime self-checks',
		'there is no raw process fallback',
		'open_basedir',
		'disabled process/network functions',
		'Secrets are encrypted at rest and never returned to the model or tool results.',
		'localhost, private, loopback, link-local, and reserved source hosts are rejected before model work.',
	),
	'goals.md' => array(
		'fail-closed',
		'PHP 代码执行默认关闭',
		'open_basedir',
		'它不是 VM 级安全边界',
		'高风险不可信代码应继续拒绝',
		'路径穿越、SSRF、MIME、secret leakage 防护测试',
	),
);

$marker_count = 0;
$files_checked = array();
foreach ( array(
	'evidence_scripts'     => $evidence_scripts,
	'implementation_files' => $implementation_files,
	'documentation_files'  => $documentation_files,
) as $group => $files ) {
	foreach ( $files as $relative_path => $markers ) {
		$text = wp_agent_security_boundary_read( $plugin_dir, $relative_path );
		wp_agent_security_boundary_assert_no_raw_secrets( $relative_path, $text );
		$marker_count += wp_agent_security_boundary_require_markers( $relative_path, $text, $markers );
		$files_checked[ $group ][] = $relative_path;
	}
}

$all_text = '';
foreach ( array_merge( array_keys( $evidence_scripts ), array_keys( $implementation_files ), array_keys( $documentation_files ) ) as $relative_path ) {
	$all_text .= "\n" . wp_agent_security_boundary_read( $plugin_dir, $relative_path );
}

$coverage = array(
	'ssrf_guard'                 => false !== strpos( $all_text, 'SSRF guard' ) && false !== strpos( $all_text, 'validate_public_http_url' ),
	'workspace_path_guard'       => false !== strpos( $all_text, 'Path escapes sandbox.' ) && false !== strpos( $all_text, '../escape.txt' ),
	'runtime_root_private'       => false !== strpos( $all_text, 'public_path' ) && false !== strpos( $all_text, 'must not live under plugin directory' ),
	'code_execution_default_off' => false !== strpos( $all_text, 'Restricted PHP CLI backend must not be opted in by default.' ) && false !== strpos( $all_text, 'microvm_removed' ),
	'php_cli_restricted_opt_in'  => false !== strpos( $all_text, 'WP_AGENT_ENABLE_PHP_CLI_EXECUTION' ) && false !== strpos( $all_text, 'open_basedir' ),
	'skill_quarantine'          => false !== strpos( $all_text, 'quarantine_package' ) && false !== strpos( $all_text, 'runtime_path_within_skills_dir' ),
	'human_confirmation'        => false !== strpos( $all_text, 'requires_confirmation' ) && false !== strpos( $all_text, 'activate_quarantine' ),
	'secret_redaction'          => false !== strpos( $all_text, 'token_disclosed=false' ) && false !== strpos( $all_text, 'Bearer [redacted]' ),
	'live_gate_fail_closed'     => false !== strpos( $all_text, 'gate must appear before credential, network, import, or model work.' ),
);

$missing_coverage = array();
foreach ( $coverage as $name => $ok ) {
	if ( ! $ok ) {
		$missing_coverage[] = $name;
	}
}
wp_agent_security_boundary_assert( empty( $missing_coverage ), 'Security boundary coverage map has gaps.', array(
	'missing' => $missing_coverage,
) );

echo json_encode( array(
	'success'                      => true,
	'contract'                     => 'security_boundary_evidence_contract',
	'evidence_scripts_checked'     => count( $evidence_scripts ),
	'implementation_files_checked' => count( $implementation_files ),
	'documentation_files_checked'  => count( $documentation_files ),
	'required_markers'             => $marker_count,
	'coverage'                     => $coverage,
	'live_network_calls'           => false,
	'ai_gateway_calls'             => false,
	'github_calls'                 => false,
	'docker_calls'                 => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
