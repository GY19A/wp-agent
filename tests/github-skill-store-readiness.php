<?php
/**
 * WP Agent GitHub Skills Store readiness checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/github-skill-store-readiness.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "This GitHub Skills Store readiness script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_github_readiness_previous'] = array();

function wp_agent_github_readiness_capture_option( $name ) {
    $GLOBALS['wp_agent_github_readiness_previous'][ $name ] = array(
        'exists' => false !== get_option( $name, false ),
        'value'  => get_option( $name, null ),
    );
}

function wp_agent_github_readiness_restore() {
    foreach ( $GLOBALS['wp_agent_github_readiness_previous'] as $name => $previous ) {
        if ( ! empty( $previous['exists'] ) ) {
            update_option( $name, $previous['value'] );
        } else {
            delete_option( $name );
        }
    }
}

function wp_agent_github_readiness_fail( $message ) {
    wp_agent_github_readiness_restore();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_github_readiness_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_github_readiness_fail( $message );
    }
}

register_shutdown_function( 'wp_agent_github_readiness_restore' );

foreach ( array(
    'wp_agent_github_default_repository',
    'wp_agent_github_default_ref',
    'wp_agent_github_default_skill_path',
    'wp_agent_github_activation_policy',
    'wp_agent_github_token',
) as $option_name ) {
    wp_agent_github_readiness_capture_option( $option_name );
    delete_option( $option_name );
}

$missing = WPAgent_Skills::github_store_readiness();
wp_agent_github_readiness_assert( empty( $missing['ready'] ), 'Unconfigured store should not be ready.' );
wp_agent_github_readiness_assert( in_array( 'repository', $missing['missing'], true ), 'Unconfigured store should report missing repository.' );
wp_agent_github_readiness_assert( in_array( 'skill_path', $missing['missing'], true ), 'Unconfigured store should report missing Skill path.' );
wp_agent_github_readiness_assert( 'not_configured' === ( $missing['token_state'] ?? '' ), 'Unconfigured store should report no token.' );

update_option( 'wp_agent_github_default_repository', 'owner/repo' );
update_option( 'wp_agent_github_default_ref', 'main' );
update_option( 'wp_agent_github_default_skill_path', 'skills/example' );
update_option( 'wp_agent_github_activation_policy', 'quarantine' );

$placeholder = WPAgent_Skills::github_store_readiness();
wp_agent_github_readiness_assert( empty( $placeholder['ready'] ), 'Placeholder store defaults should not be ready.' );
wp_agent_github_readiness_assert( empty( $placeholder['live_acceptance_ready'] ), 'Placeholder store defaults should not be live-acceptance ready.' );
wp_agent_github_readiness_assert( in_array( 'official_coordinates', $placeholder['missing'], true ), 'Placeholder store should request official coordinates.' );
wp_agent_github_readiness_assert( in_array( 'github_store_placeholder', $placeholder['warnings'], true ), 'Placeholder store should report a placeholder warning.' );
wp_agent_github_readiness_assert( 'repository uses a documented placeholder value' === ( $placeholder['placeholder_reason'] ?? '' ), 'Placeholder store should expose the exact reason.' );

update_option( 'wp_agent_github_default_repository', 'acme/wp-agent-store-fixtures' );
update_option( 'wp_agent_github_default_ref', 'release/v1' );
update_option( 'wp_agent_github_default_skill_path', 'skills/default-store-fixture' );
update_option( 'wp_agent_github_activation_policy', 'quarantine' );

$public_ready = WPAgent_Skills::github_store_readiness();
wp_agent_github_readiness_assert( ! empty( $public_ready['ready'] ), 'Public store defaults should be ready without a token.' );
wp_agent_github_readiness_assert( ! empty( $public_ready['live_acceptance_ready'] ), 'Live acceptance should be ready when required defaults exist.' );
wp_agent_github_readiness_assert( 'ready_public' === ( $public_ready['next_action'] ?? '' ), 'Public-ready defaults should report ready_public.' );
wp_agent_github_readiness_assert( 'not_configured' === ( $public_ready['token_state'] ?? '' ), 'Public-ready defaults should report no token.' );
wp_agent_github_readiness_assert( 'acme/wp-agent-store-fixtures' === ( $public_ready['repository'] ?? '' ), 'Readiness should expose normalized repository.' );
wp_agent_github_readiness_assert( 'skills/default-store-fixture' === ( $public_ready['skill_path'] ?? '' ), 'Readiness should expose normalized Skill path.' );
wp_agent_github_readiness_assert( 'release/v1' === ( $public_ready['ref'] ?? '' ), 'Readiness should expose configured ref.' );
wp_agent_github_readiness_assert( '' === ( $public_ready['placeholder_reason'] ?? '' ), 'Public-ready defaults should not report a placeholder reason.' );

update_option( 'wp_agent_github_token', 'not-an-encrypted-token' );
$bad_token = WPAgent_Skills::github_store_readiness();
wp_agent_github_readiness_assert( empty( $bad_token['ready'] ), 'Unreadable stored token should block readiness until re-saved.' );
wp_agent_github_readiness_assert( 'unreadable' === ( $bad_token['token_state'] ?? '' ), 'Unreadable stored token should be reported.' );
wp_agent_github_readiness_assert( in_array( 'github_token_unreadable', $bad_token['warnings'], true ), 'Unreadable token warning should be present.' );
wp_agent_github_readiness_assert( 'resave_github_token' === ( $bad_token['next_action'] ?? '' ), 'Unreadable token should request re-save.' );

WPAgent::update_option( 'github_token', WPAgent::encrypt( 'github-readiness-token' ) );
$private_ready = WPAgent_Skills::github_store_readiness();
wp_agent_github_readiness_assert( ! empty( $private_ready['ready'] ), 'Encrypted token and defaults should be ready.' );
wp_agent_github_readiness_assert( ! empty( $private_ready['token_configured'] ), 'Encrypted token should be marked configured.' );
wp_agent_github_readiness_assert( ! empty( $private_ready['token_usable'] ), 'Encrypted token should be marked usable.' );
wp_agent_github_readiness_assert( 'encrypted' === ( $private_ready['token_state'] ?? '' ), 'Encrypted token state should be reported.' );
wp_agent_github_readiness_assert( 'ready_private_or_public' === ( $private_ready['next_action'] ?? '' ), 'Encrypted token should allow private or public store workflows.' );

$diagnostics = WPAgent_Diagnostics::runtime();
$diag_store  = $diagnostics['skills']['github_store'] ?? array();
wp_agent_github_readiness_assert( ! empty( $diag_store['ready'] ), 'Runtime diagnostics should expose ready GitHub Skills Store state.' );
wp_agent_github_readiness_assert( 'encrypted' === ( $diag_store['token_state'] ?? '' ), 'Runtime diagnostics should expose token state without token plaintext.' );
wp_agent_github_readiness_assert( false === strpos( wp_json_encode( $diag_store ), 'github-readiness-token' ), 'Runtime diagnostics must not disclose GitHub token plaintext.' );

$cli_output = WP_CLI::runcommand( 'wp-agent diagnostics', array(
    'return'     => true,
    'parse'      => 'json',
    'launch'     => false,
    'exit_error' => false,
) );
wp_agent_github_readiness_assert( is_array( $cli_output ), 'WP-CLI diagnostics should return parsed JSON.' );
$cli_store = $cli_output['skills']['github_store'] ?? array();
wp_agent_github_readiness_assert( ! empty( $cli_store['ready'] ), 'WP-CLI diagnostics should expose ready GitHub Skills Store state.' );
wp_agent_github_readiness_assert( 'encrypted' === ( $cli_store['token_state'] ?? '' ), 'WP-CLI diagnostics should expose encrypted token state.' );
wp_agent_github_readiness_assert( false === strpos( wp_json_encode( $cli_store ), 'github-readiness-token' ), 'WP-CLI diagnostics must not disclose GitHub token plaintext.' );

$result = array(
    'success'              => true,
    'missing_state'        => $missing['missing'],
    'public_next_action'   => $public_ready['next_action'],
    'bad_token_state'      => $bad_token['token_state'],
    'private_next_action'  => $private_ready['next_action'],
    'repository'           => $private_ready['repository'],
    'ref'                  => $private_ready['ref'],
    'skill_path'           => $private_ready['skill_path'],
    'token_disclosed'      => false,
);

wp_agent_github_readiness_restore();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
