<?php
/**
 * Host-side final live input template contract.
 *
 * Verifies the final live acceptance environment template stays actionable and
 * safe: all required external inputs are present as placeholders/examples,
 * secrets are not embedded, and run bounds match the live preflight contract.
 * This script reads local files only and does not call GitHub, Docker,
 * WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-live-input-template-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final live input template contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_live_input_template_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_live_input_template_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_live_input_template_fail( $message, $details );
	}
}

function wp_agent_live_input_template_read( $path ) {
	wp_agent_live_input_template_assert( is_file( $path ), 'Required live input template file is missing.', array(
		'path' => $path,
	) );
	$text = file_get_contents( $path );
	wp_agent_live_input_template_assert( is_string( $text ) && '' !== $text, 'Required live input template file could not be read.', array(
		'path' => $path,
	) );
	return $text;
}

function wp_agent_live_input_template_parse_env( $text ) {
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

function wp_agent_live_input_template_require_keys( $values, $keys ) {
	$missing = array();
	foreach ( $keys as $key ) {
		if ( ! array_key_exists( $key, $values ) ) {
			$missing[] = $key;
		}
	}
	wp_agent_live_input_template_assert( empty( $missing ), 'Live input template is missing required keys.', array(
		'missing' => $missing,
	) );
}

function wp_agent_live_input_template_assert_no_raw_secrets( $name, $text ) {
	foreach ( array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`#\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/github_pat_[A-Za-z0-9_]{20,}/',
	) as $pattern ) {
		wp_agent_live_input_template_assert( 1 !== preg_match( $pattern, $text ), $name . ' appears to contain a raw token or inline secret assignment.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_live_input_template_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$template_path = $plugin_dir . '/tests/final-live-inputs.example.env';
$template      = wp_agent_live_input_template_read( $template_path );
$readme        = wp_agent_live_input_template_read( $plugin_dir . '/README.md' );
$goals         = wp_agent_live_input_template_read( $plugin_dir . '/goals.md' );
$preflight     = wp_agent_live_input_template_read( $plugin_dir . '/tests/final-acceptance-preflight.php' );
$live_soak     = wp_agent_live_input_template_read( $plugin_dir . '/tests/live-editorial-daemon-soak.php' );

wp_agent_live_input_template_assert_no_raw_secrets( 'final-live-inputs.example.env', $template );
wp_agent_live_input_template_assert_no_raw_secrets( 'README.md', $readme );
wp_agent_live_input_template_assert_no_raw_secrets( 'goals.md', $goals );

$values = wp_agent_live_input_template_parse_env( $template );

$required_keys = array(
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE',
	'WP_AGENT_FINAL_PREFLIGHT_STRICT',
	'WP_AGENT_LIVE_GITHUB_SKILLS',
	'WP_AGENT_LIVE_GITHUB_REPOSITORY',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
	'WP_AGENT_LIVE_GITHUB_REF',
	'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
	'WP_AGENT_OFFICIAL_DB_DIR',
);
wp_agent_live_input_template_require_keys( $values, $required_keys );

wp_agent_live_input_template_assert( 'all' === ( $values['WP_AGENT_FINAL_PREFLIGHT_SCOPE'] ?? '' ), 'Template should default final preflight scope to all.' );
wp_agent_live_input_template_assert( '1' === ( $values['WP_AGENT_FINAL_PREFLIGHT_STRICT'] ?? '' ), 'Template should enable strict final preflight.' );
wp_agent_live_input_template_assert( 'owner/repo' === ( $values['WP_AGENT_LIVE_GITHUB_REPOSITORY'] ?? '' ), 'GitHub repository must remain an obvious placeholder.' );
wp_agent_live_input_template_assert( 'skills/example' === ( $values['WP_AGENT_LIVE_GITHUB_SKILL_PATH'] ?? '' ), 'GitHub Skill path must remain an obvious placeholder.' );
wp_agent_live_input_template_assert( in_array( $values['WP_AGENT_LIVE_GITHUB_REVIEW_POLICY'] ?? '', array( 'quarantine', 'activate', 'activate_pin' ), true ), 'Review policy must use an allowed value.' );
wp_agent_live_input_template_assert( ! array_key_exists( 'WP_AGENT_LIVE_GITHUB_TOKEN', $values ), 'Template must not define a GitHub token assignment.' );
wp_agent_live_input_template_assert( 'replace-after-review' === ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE'] ?? '' ), 'Template approval phrase must remain a safe placeholder.' );
wp_agent_live_input_template_assert( false !== strpos( $template, 'approve-multi-hour-soak' ), 'Template should document the required final soak approval phrase.' );
wp_agent_live_input_template_assert( in_array( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY'] ?? '', array( 'drafts_journal_usage', 'drafts_journal_usage_media' ), true ), 'Artifact policy must use an allowed value.' );
wp_agent_live_input_template_assert( (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'] ?? 0 ) <= 12, 'Template run count must stay within live harness bounds.' );
wp_agent_live_input_template_assert( (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'] ?? 0 ) <= 28800, 'Template timeout must stay within live harness bounds.' );
wp_agent_live_input_template_assert( (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'] ?? 0 ) <= 28800, 'Template soak seconds must stay within live harness bounds.' );
wp_agent_live_input_template_assert( (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'] ?? 0 ) >= 7200, 'Template soak seconds should satisfy final preflight minimum.' );
wp_agent_live_input_template_assert( 0 === strpos( $values['WP_AGENT_OFFICIAL_DB_DIR'] ?? '', '/path/to/wp-agent/database/' ), 'Template DB dir must remain under the approved database root.' );
wp_agent_live_input_template_assert( '/path/to/wp-agent/database/official-mysql' === ( $values['WP_AGENT_OFFICIAL_DB_DIR'] ?? '' ), 'Template should default to the official persistent DB directory.' );
wp_agent_live_input_template_assert( false !== strpos( $template, 'WP_AGENT_LIVE_GITHUB_TOKEN is intentionally omitted' ), 'Template should explain why the GitHub token is omitted.' );
wp_agent_live_input_template_assert( false !== strpos( $template, 'WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR=1' ), 'Template should document the explicit throwaway DB override.' );

$preflight_keys = array_values( array_diff( $required_keys, array(
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL',
) ) );
foreach ( $preflight_keys as $key ) {
	wp_agent_live_input_template_assert( false !== strpos( $preflight, $key ), 'Final preflight should consume the template key.', array(
		'key' => $key,
	) );
}
wp_agent_live_input_template_assert(
	false !== strpos( $readme, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL' )
	&& false !== strpos( $goals, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL' )
	&& false !== strpos( $live_soak, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL' ),
	'Sample interval should remain documented and consumed by the live soak harness.'
);
wp_agent_live_input_template_assert( false !== strpos( $readme, 'tests/final-live-inputs.example.env' ), 'README should reference the final live input template.' );
wp_agent_live_input_template_assert( false !== strpos( $goals, 'tests/final-live-inputs.example.env' ), 'goals.md should reference the final live input template.' );

echo json_encode( array(
	'success'            => true,
	'contract'           => 'final_live_input_template_contract',
	'template'           => $template_path,
	'keys_checked'       => count( $required_keys ),
	'github_placeholder' => array(
		'repository' => $values['WP_AGENT_LIVE_GITHUB_REPOSITORY'] ?? '',
		'skill_path' => $values['WP_AGENT_LIVE_GITHUB_SKILL_PATH'] ?? '',
	),
	'soak_bounds'        => array(
		'runs'         => (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS'] ?? 0 ),
		'timeout'      => (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT'] ?? 0 ),
		'soak_seconds' => (int) ( $values['WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS'] ?? 0 ),
	),
	'secret_assignments' => false,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
