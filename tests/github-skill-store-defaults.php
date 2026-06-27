<?php
/**
 * WP Agent GitHub Skills Store configured defaults checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/github-skill-store-defaults.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "This GitHub Skills Store defaults script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_github_defaults_previous'] = array();
$GLOBALS['wp_agent_github_defaults_dirs']     = array();

function wp_agent_github_defaults_capture_option( $name ) {
    $GLOBALS['wp_agent_github_defaults_previous'][ $name ] = array(
        'exists' => false !== get_option( $name, false ),
        'value'  => get_option( $name, null ),
    );
}

function wp_agent_github_defaults_restore() {
    foreach ( array_reverse( $GLOBALS['wp_agent_github_defaults_dirs'] ) as $dir ) {
        wp_agent_github_defaults_delete_dir( $dir );
    }

    foreach ( $GLOBALS['wp_agent_github_defaults_previous'] as $name => $previous ) {
        if ( ! empty( $previous['exists'] ) ) {
            update_option( $name, $previous['value'] );
        } else {
            delete_option( $name );
        }
    }
}

function wp_agent_github_defaults_delete_dir( $dir ) {
    $dir = wp_normalize_path( (string) $dir );
    if ( '' === $dir || ! is_dir( $dir ) ) {
        return;
    }
    $runtime_root = trailingslashit( wp_normalize_path( WPAgent_Sandbox::runtime_root() ) );
    if ( 0 !== strpos( trailingslashit( $dir ), $runtime_root ) ) {
        return;
    }
    $items = scandir( $dir );
    if ( ! is_array( $items ) ) {
        return;
    }
    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }
        $path = $dir . '/' . $item;
        if ( is_dir( $path ) ) {
            wp_agent_github_defaults_delete_dir( $path );
        } else {
            @unlink( $path );
        }
    }
    @rmdir( $dir );
}

function wp_agent_github_defaults_fail( $message ) {
    wp_agent_github_defaults_restore();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_github_defaults_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_github_defaults_fail( $message );
    }
}

function wp_agent_github_defaults_content_response( $path, $body, $sha ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'type'    => 'file',
            'name'    => basename( $path ),
            'path'    => $path,
            'sha'     => $sha,
            'size'    => strlen( $body ),
            'content' => chunk_split( base64_encode( $body ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub contents API fixture.
        ) ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
    );
}

function wp_agent_github_defaults_dir_response() {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array() ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
    );
}

function wp_agent_github_defaults_run_json( $command ) {
    $result = WP_CLI::runcommand( $command, array( 'return' => 'all', 'launch' => false, 'exit_error' => false ) );
    $output = is_object( $result ) ? ( $result->stdout ?? '' ) : ( is_array( $result ) ? ( $result['stdout'] ?? '' ) : $result );
    $code   = is_object( $result ) ? (int) ( $result->return_code ?? 0 ) : ( is_array( $result ) ? (int) ( $result['return_code'] ?? 0 ) : 0 );
    $stderr = is_object( $result ) ? ( $result->stderr ?? '' ) : ( is_array( $result ) ? ( $result['stderr'] ?? '' ) : '' );
    if ( 0 !== $code ) {
        wp_agent_github_defaults_fail( 'Command failed: ' . $command . ' :: ' . $stderr );
    }
    $data = json_decode( trim( (string) $output ), true );
    if ( ! is_array( $data ) ) {
        wp_agent_github_defaults_fail( 'Command did not return JSON: ' . $command . ' :: ' . $output );
    }
    return $data;
}

register_shutdown_function( 'wp_agent_github_defaults_restore' );

foreach ( array(
    'wp_agent_github_default_repository',
    'wp_agent_github_default_ref',
    'wp_agent_github_default_skill_path',
    'wp_agent_github_activation_policy',
    'wp_agent_github_token',
) as $option_name ) {
    wp_agent_github_defaults_capture_option( $option_name );
}

$admin = new WPAgent_Admin();
wp_agent_github_defaults_assert( 'acme/wp-agent-store-fixtures' === $admin->sanitize_github_repository( 'https://github.com/acme/wp-agent-store-fixtures.git' ), 'Repository sanitizer should normalize GitHub URLs.' );
wp_agent_github_defaults_assert( 'skills/default-store-fixture' === $admin->sanitize_github_skill_path( 'skills//default-store-fixture/' ), 'Skill path sanitizer should normalize package paths.' );
wp_agent_github_defaults_assert( 'release/v1' === $admin->sanitize_github_ref( ' release/v1 ' ), 'Ref sanitizer should preserve safe branch paths.' );
wp_agent_github_defaults_assert( 'activate_pin' === $admin->sanitize_github_activation_policy( 'activate_pin' ), 'Activation policy sanitizer should accept activate_pin.' );

update_option( 'wp_agent_github_default_repository', 'acme/wp-agent-store-fixtures' );
update_option( 'wp_agent_github_default_ref', 'release/v1' );
update_option( 'wp_agent_github_default_skill_path', 'skills/default-store-fixture' );
update_option( 'wp_agent_github_activation_policy', 'activate_pin' );
WPAgent::update_option( 'github_token', WPAgent::encrypt( 'configured-store-token' ) );

$defaults = WPAgent_Skills::github_store_defaults();
wp_agent_github_defaults_assert( ! empty( $defaults['configured'] ), 'Configured store should be marked configured.' );
wp_agent_github_defaults_assert( 'acme/wp-agent-store-fixtures' === $defaults['repository'], 'Configured repository should be normalized.' );
wp_agent_github_defaults_assert( 'release/v1' === $defaults['ref'], 'Configured ref should be returned.' );
wp_agent_github_defaults_assert( 'skills/default-store-fixture' === $defaults['skill_path'], 'Configured Skill path should be returned.' );
wp_agent_github_defaults_assert( 'activate_pin' === $defaults['activation_policy'], 'Configured activation policy should be returned.' );

$http_calls = array();
add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) use ( &$http_calls ) {
        if ( false === strpos( (string) $url, 'https://api.github.com/repos/acme/wp-agent-store-fixtures/contents/' ) ) {
            return $preempt;
        }

        $path = parse_url( (string) $url, PHP_URL_PATH );
        $path = preg_replace( '#^/repos/acme/wp-agent-store-fixtures/contents/#', '', (string) $path );
        $path = rawurldecode( $path );
        $query = array();
        parse_str( (string) parse_url( (string) $url, PHP_URL_QUERY ), $query );
        $http_calls[] = array(
            'path'          => $path,
            'ref'           => $query['ref'] ?? '',
            'authorization' => $parsed_args['headers']['Authorization'] ?? '',
        );

        $skill_root = 'skills/default-store-fixture';
        if ( $skill_root . '/SKILL.md' === $path ) {
            $body = "---\n"
                . "name: Default Store Fixture\n"
                . "slug: default-store-fixture\n"
                . "version: 1.0.0\n"
                . "description: Fake configured GitHub Skill Store fixture.\n"
                . "permissions:\n"
                . "  tools: [runtime]\n"
                . "  network: false\n"
                . "  code_execution: false\n"
                . "---\n"
                . "## Workflow\n\nUse the runtime tool to inspect current status.\n";
            return wp_agent_github_defaults_content_response( $path, $body, 'default-store-sha' );
        }

        if ( in_array( $path, array( $skill_root . '/references', $skill_root . '/templates', $skill_root . '/assets', $skill_root . '/scripts' ), true ) ) {
            return wp_agent_github_defaults_dir_response();
        }

        return array(
            'headers'  => array(),
            'body'     => wp_json_encode( array( 'message' => 'Not Found' ) ),
            'response' => array( 'code' => 404, 'message' => 'Not Found' ),
            'cookies'  => array(),
        );
    },
    10,
    3
);

$direct = WPAgent_Skills::install_from_github( 1, array() );
wp_agent_github_defaults_assert( ! is_wp_error( $direct ), is_wp_error( $direct ) ? $direct->get_error_message() : 'Direct default install failed.' );
wp_agent_github_defaults_assert( ! empty( $direct['success'] ), 'Direct install should succeed with configured defaults.' );
wp_agent_github_defaults_assert( 'default-store-fixture' === ( $direct['summary']['slug'] ?? '' ), 'Direct install should return the default Skill slug.' );
wp_agent_github_defaults_assert( 'acme/wp-agent-store-fixtures' === ( $direct['summary']['source']['repository'] ?? '' ), 'Direct install should use the configured repository.' );
wp_agent_github_defaults_assert( 'release/v1' === ( $direct['summary']['source']['ref'] ?? '' ), 'Direct install should use the configured ref.' );
wp_agent_github_defaults_assert( 'skills/default-store-fixture' === ( $direct['summary']['source']['path'] ?? '' ), 'Direct install should use the configured Skill path.' );
wp_agent_github_defaults_assert( empty( $direct['summary']['source']['github_token'] ), 'Configured GitHub token must not be returned in direct install source summary.' );
$GLOBALS['wp_agent_github_defaults_dirs'][] = dirname( $direct['lock_file'] );

$quarantined = WPAgent_Skills::get_quarantined( $direct['quarantine_id'] );
wp_agent_github_defaults_assert( ! is_wp_error( $quarantined ), 'Direct quarantined package should be readable.' );
wp_agent_github_defaults_assert( false === strpos( wp_json_encode( $quarantined['lock'] ?? array() ), 'configured-store-token' ), 'Configured GitHub token plaintext must not be persisted in quarantine lock.' );

$cli = wp_agent_github_defaults_run_json( 'wp-agent skills install-github --owner=1 --format=json' );
wp_agent_github_defaults_assert( ! empty( $cli['success'] ), 'CLI install should succeed with configured defaults.' );
wp_agent_github_defaults_assert( 'default-store-fixture' === ( $cli['summary']['slug'] ?? '' ), 'CLI default install should return the default Skill slug.' );
wp_agent_github_defaults_assert( 'acme/wp-agent-store-fixtures' === ( $cli['summary']['source']['repository'] ?? '' ), 'CLI default install should use the configured repository.' );
wp_agent_github_defaults_assert( 'release/v1' === ( $cli['summary']['source']['ref'] ?? '' ), 'CLI default install should use the configured ref.' );
wp_agent_github_defaults_assert( 'skills/default-store-fixture' === ( $cli['summary']['source']['path'] ?? '' ), 'CLI default install should use the configured Skill path.' );
$GLOBALS['wp_agent_github_defaults_dirs'][] = dirname( $cli['lock_file'] );

wp_agent_github_defaults_assert( count( $http_calls ) >= 10, 'Direct and CLI installs should call the fake GitHub API.' );
foreach ( $http_calls as $call ) {
    wp_agent_github_defaults_assert( 'release/v1' === $call['ref'], 'Every fake GitHub request should use the configured ref.' );
    wp_agent_github_defaults_assert( 'Bearer configured-store-token' === $call['authorization'], 'Every fake GitHub request should use the configured token.' );
}

$result = array(
    'success'             => true,
    'repository'          => $defaults['repository'],
    'ref'                 => $defaults['ref'],
    'skill_path'          => $defaults['skill_path'],
    'activation_policy'   => $defaults['activation_policy'],
    'direct_quarantine_id' => $direct['quarantine_id'],
    'cli_quarantine_id'   => $cli['quarantine_id'],
    'http_calls'          => count( $http_calls ),
    'token_disclosed'     => false,
);

wp_agent_github_defaults_restore();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
