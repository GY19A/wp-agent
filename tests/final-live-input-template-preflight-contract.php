<?php
/**
 * Host-side final live input template preflight contract.
 *
 * Runs the official-container final preflight with the example live input
 * template and expects it to fail closed before any live GitHub/API work. This
 * proves the example file cannot be used as a real live configuration while it
 * still contains placeholder GitHub coordinates.
 *
 * Run from the host:
 * php tests/final-live-input-template-preflight-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live input template preflight contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_template_preflight_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_template_preflight_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_template_preflight_fail( $message, $details );
	}
}

function wp_agent_template_preflight_read( $path ) {
	wp_agent_template_preflight_assert( is_file( $path ), 'Required file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_template_preflight_assert( is_string( $text ) && '' !== $text, 'Required file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_template_preflight_parse_env( $text ) {
	$values = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
			continue;
		}
		list( $key, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
		$values[ $key ] = trim( $value, "\"'" );
	}
	return $values;
}

function wp_agent_template_preflight_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_template_preflight_json( $output ) {
	$start = strpos( $output, '{' );
	if ( false === $start ) {
		return null;
	}
	$data = json_decode( substr( $output, $start ), true );
	return is_array( $data ) ? $data : null;
}

function wp_agent_template_preflight_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_template_preflight_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_template_preflight_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$compose_file  = $plugin_dir . '/docker-compose.official.yml';
$template_path = $plugin_dir . '/tests/final-live-inputs.example.env';
wp_agent_template_preflight_assert( is_file( $compose_file ), 'docker-compose.official.yml is missing.' );

$template = wp_agent_template_preflight_read( $template_path );
wp_agent_template_preflight_assert_no_raw_secrets( 'final-live-inputs.example.env', $template );
$values = wp_agent_template_preflight_parse_env( $template );

foreach ( array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE',
	'WP_AGENT_LIVE_GITHUB_SKILLS',
	'WP_AGENT_LIVE_GITHUB_REPOSITORY',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
	'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
	'WP_AGENT_OFFICIAL_DB_DIR',
) as $required_key ) {
	wp_agent_template_preflight_assert( array_key_exists( $required_key, $values ), 'Template is missing a required preflight key.', array(
		'key' => $required_key,
	) );
}

wp_agent_template_preflight_assert( 'owner/repo' === ( $values['WP_AGENT_LIVE_GITHUB_REPOSITORY'] ?? '' ), 'Template repository should remain a placeholder.' );
wp_agent_template_preflight_assert( 'skills/example' === ( $values['WP_AGENT_LIVE_GITHUB_SKILL_PATH'] ?? '' ), 'Template Skill path should remain a placeholder.' );
wp_agent_template_preflight_assert( 'replace-after-review' === ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE'] ?? '' ), 'Template approval phrase should remain a placeholder.' );

$args = array(
	'docker',
	'compose',
	'-p',
	'wp-agent-official',
	'-f',
	$compose_file,
	'--profile',
	'cli',
	'run',
	'--rm',
	'-T',
);
foreach ( $values as $key => $value ) {
	$args[] = '-e';
	$args[] = $key . '=' . $value;
}
$args[] = 'wpcli';
$args[] = 'wp';
$args[] = 'eval-file';
$args[] = 'wp-content/plugins/wp-agent/tests/final-acceptance-preflight.php';
$args[] = '--allow-root';

$result = wp_agent_template_preflight_command( $args );
wp_agent_template_preflight_assert( 0 !== $result['status'], 'Strict final preflight should fail with placeholder template values.', array(
	'output' => $result['output'],
) );
wp_agent_template_preflight_assert( false !== strpos( $result['output'], 'replace placeholder GitHub repository/path with official Skill Store coordinates' ), 'Strict final preflight should reject placeholder GitHub coordinates.', array(
	'output' => $result['output'],
) );
wp_agent_template_preflight_assert( false !== strpos( $result['output'], 'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak' ), 'Strict final preflight should reject the placeholder soak approval phrase.', array(
	'output' => $result['output'],
) );
wp_agent_template_preflight_assert( false === stripos( $result['output'], 'Bearer ' ), 'Strict final preflight output must not include bearer tokens.' );
wp_agent_template_preflight_assert( false === strpos( $result['output'], 'github_pat_' ), 'Strict final preflight output must not include GitHub PAT values.' );

$json = wp_agent_template_preflight_json( $result['output'] );
wp_agent_template_preflight_assert( is_array( $json ), 'Strict final preflight output should include JSON.', array(
	'output' => $result['output'],
) );
wp_agent_template_preflight_assert( false === (bool) ( $json['success'] ?? true ), 'Strict final preflight JSON should report success=false for placeholder inputs.', $json );
wp_agent_template_preflight_assert( false === (bool) ( $json['ready'] ?? true ), 'Strict final preflight JSON should report ready=false for placeholder inputs.', $json );
wp_agent_template_preflight_assert( in_array( 'replace placeholder GitHub repository/path with official Skill Store coordinates', $json['gates']['github_skill_store']['missing'] ?? array(), true ), 'Strict preflight JSON should list the placeholder replacement requirement.', $json['gates']['github_skill_store'] ?? array() );
wp_agent_template_preflight_assert( false === (bool) ( $json['gates']['github_skill_store']['token_disclosed'] ?? true ), 'Strict preflight JSON must keep token_disclosed=false.', $json['gates']['github_skill_store'] ?? array() );

echo json_encode( array(
	'success'            => true,
	'contract'           => 'final_live_input_template_preflight_contract',
	'template'           => $template_path,
	'preflight_status'   => (int) $result['status'],
	'placeholder_rejected' => true,
	'json_ready'         => (bool) ( $json['ready'] ?? false ),
	'token_disclosed'    => false,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
