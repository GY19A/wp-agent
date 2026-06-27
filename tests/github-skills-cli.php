<?php
/**
 * WP Agent GitHub Skills Store CLI checks with a fake GitHub API.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/github-skills-cli.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "This GitHub Skills CLI script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_github_skills_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_github_skills_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_github_skills_fail( $message );
    }
}

function wp_agent_github_skills_run_json( $command ) {
    $result = WP_CLI::runcommand( $command, array( 'return' => 'all', 'launch' => false, 'exit_error' => false ) );
    if ( is_object( $result ) ) {
        $output      = $result->stdout ?? '';
        $stderr      = $result->stderr ?? '';
        $return_code = $result->return_code ?? 0;
        if ( 0 !== (int) $return_code || ( '' === trim( (string) $output ) && '' !== trim( (string) $stderr ) ) ) {
            wp_agent_github_skills_fail( 'Command failed: ' . $command . ' :: ' . $stderr );
        }
    } elseif ( is_array( $result ) ) {
        $output = $result['stdout'] ?? '';
        if ( 0 !== (int) ( $result['return_code'] ?? 0 ) || ( '' === trim( (string) $output ) && ! empty( $result['stderr'] ) ) ) {
            wp_agent_github_skills_fail( 'Command failed: ' . $command . ' :: ' . $result['stderr'] );
        }
    } else {
        $output = $result;
    }
    $data   = json_decode( trim( (string) $output ), true );
    if ( ! is_array( $data ) ) {
        wp_agent_github_skills_fail( 'Command did not return JSON: ' . $command . ' :: ' . $output );
    }
    return $data;
}

function wp_agent_github_skills_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_github_skills_markdown( $version, $body_suffix = '' ) {
    return "---\n"
        . "name: CLI GitHub Fixture\n"
        . "slug: cli-github-fixture\n"
        . "version: " . $version . "\n"
        . "description: Fake GitHub CLI fixture.\n"
        . "permissions:\n"
        . "  tools: [web.search, manage_posts]\n"
        . "  network: true\n"
        . "  code_execution: false\n"
        . "schedule_templates:\n"
        . "  - cli-github-daily\n"
        . "---\n"
        . "## Workflow\n\nUse approved WordPress tools from the fake GitHub Skill package." . $body_suffix . "\n";
}

function wp_agent_github_skills_content_response( $path, $body, $sha ) {
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

function wp_agent_github_skills_dir_response( $items ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( $items ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
    );
}

$remote_version = '1.0.0';
$remote_suffix  = '';
$http_calls     = array();

add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) use ( &$remote_version, &$remote_suffix, &$http_calls ) {
        if ( false === strpos( (string) $url, 'https://api.github.com/repos/example/wp-agent-skills/contents/' ) ) {
            return $preempt;
        }

        $path = parse_url( (string) $url, PHP_URL_PATH );
        $path = preg_replace( '#^/repos/example/wp-agent-skills/contents/#', '', (string) $path );
        $path = rawurldecode( $path );
        $http_calls[] = array(
            'path'          => $path,
            'authorization' => $parsed_args['headers']['Authorization'] ?? '',
        );

        $skill_root = 'skills/cli-github-fixture';
        $skill_md   = wp_agent_github_skills_markdown( $remote_version, $remote_suffix );
        $sha_suffix = str_replace( '.', '-', $remote_version ) . '-' . substr( hash( 'sha256', $remote_suffix ), 0, 8 );

        if ( $skill_root . '/SKILL.md' === $path ) {
            return wp_agent_github_skills_content_response( $path, $skill_md, 'skill-sha-' . $sha_suffix );
        }

        if ( $skill_root . '/references' === $path ) {
            return wp_agent_github_skills_dir_response( array(
                array(
                    'type' => 'file',
                    'name' => 'notes.md',
                    'path' => $skill_root . '/references/notes.md',
                    'sha'  => 'notes-sha',
                ),
            ) );
        }

        if ( $skill_root . '/references/notes.md' === $path ) {
            return wp_agent_github_skills_content_response( $path, "Fake GitHub reference notes.\n", 'notes-sha' );
        }

        if ( $skill_root . '/templates' === $path ) {
            return wp_agent_github_skills_dir_response( array(
                array(
                    'type' => 'file',
                    'name' => 'post.md',
                    'path' => $skill_root . '/templates/post.md',
                    'sha'  => 'template-sha',
                ),
            ) );
        }

        if ( $skill_root . '/templates/post.md' === $path ) {
            return wp_agent_github_skills_content_response( $path, "Fake GitHub post template.\n", 'template-sha' );
        }

        if ( $skill_root . '/assets' === $path ) {
            return wp_agent_github_skills_dir_response( array() );
        }

        if ( $skill_root . '/scripts' === $path ) {
            return wp_agent_github_skills_dir_response( array() );
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

$installed = wp_agent_github_skills_run_json(
    'wp-agent skills install-github --owner=1 --repository=example/wp-agent-skills --skill-path=skills/cli-github-fixture --ref=main --github-token=fixture-token --format=json'
);
wp_agent_github_skills_assert( ! empty( $installed['success'] ), 'GitHub CLI install should succeed.' );
wp_agent_github_skills_assert( 'cli-github-fixture' === ( $installed['summary']['slug'] ?? '' ), 'GitHub CLI install should return the expected package slug.' );
wp_agent_github_skills_assert( ! empty( $installed['quarantine_id'] ), 'GitHub CLI install should return a quarantine id.' );
wp_agent_github_skills_assert( ! empty( $http_calls[0]['authorization'] ) && 'Bearer fixture-token' === $http_calls[0]['authorization'], 'GitHub CLI install should send the one-shot token to GitHub.' );
wp_agent_github_skills_assert( empty( $installed['summary']['source']['github_token'] ), 'GitHub token must not be persisted in package source metadata.' );

$quarantine = wp_agent_github_skills_run_json( 'wp-agent skills quarantine --format=json' );
wp_agent_github_skills_assert( in_array( $installed['quarantine_id'], wp_list_pluck( $quarantine['packages'] ?? array(), 'id' ), true ), 'CLI quarantine list should include the fake GitHub package.' );

$activated = wp_agent_github_skills_run_json(
    'wp-agent skills activate-quarantine --owner=1 --quarantine-id=' . escapeshellarg( $installed['quarantine_id'] ) . ' --format=json'
);
wp_agent_github_skills_assert( ! empty( $activated['success'] ), 'CLI quarantine activation should succeed.' );
wp_agent_github_skills_assert( 'cli-github-fixture' === ( $activated['skill']['slug'] ?? '' ), 'Activated fake GitHub Skill slug should match.' );
wp_agent_github_skills_assert( wp_agent_github_skills_path_starts_with( $activated['installed_dir'] ?? '', WPAgent_Sandbox::runtime_root() ), 'Activated package should live under runtime root.' );
wp_agent_github_skills_assert( ! wp_agent_github_skills_path_starts_with( $activated['installed_dir'] ?? '', WP_AGENT_PLUGIN_DIR ), 'Activated package must not live under the plugin directory.' );
$activated_version = (int) ( $activated['skill']['version'] ?? 0 );
wp_agent_github_skills_assert( $activated_version > 0, 'Activated fake GitHub Skill should have a positive DB version.' );

$installed_packages = wp_agent_github_skills_run_json( 'wp-agent skills installed --format=json' );
wp_agent_github_skills_assert( in_array( 'cli-github-fixture', wp_list_pluck( $installed_packages['packages'] ?? array(), 'slug' ), true ), 'CLI installed list should include activated fake GitHub package.' );

$no_update = wp_agent_github_skills_run_json( 'wp-agent skills check-package-update --slug=cli-github-fixture --format=json' );
wp_agent_github_skills_assert( empty( $no_update['has_update'] ), 'Initial update check should report no update.' );

$remote_version = '1.1.0';
$remote_suffix  = "\n\nUpdated workflow body from fake GitHub.";
$has_update = wp_agent_github_skills_run_json( 'wp-agent skills check-package-update --slug=cli-github-fixture --format=json' );
wp_agent_github_skills_assert( ! empty( $has_update['has_update'] ), 'Changed fake GitHub package should report an update.' );

$refresh = wp_agent_github_skills_run_json( 'wp-agent skills refresh-package --owner=1 --slug=cli-github-fixture --format=json' );
wp_agent_github_skills_assert( ! empty( $refresh['success'] ), 'CLI package refresh should download an updated quarantine package.' );
wp_agent_github_skills_assert( ! empty( $refresh['quarantine_id'] ) && $refresh['quarantine_id'] !== $installed['quarantine_id'], 'Updated fake package should use a new quarantine id.' );

$reactivated = wp_agent_github_skills_run_json(
    'wp-agent skills activate-quarantine --owner=1 --quarantine-id=' . escapeshellarg( $refresh['quarantine_id'] ) . ' --format=json'
);
wp_agent_github_skills_assert( ! empty( $reactivated['success'] ), 'CLI activation of refreshed package should succeed.' );
wp_agent_github_skills_assert( (int) ( $reactivated['skill']['version'] ?? 0 ) > $activated_version, 'Refreshed package activation should increment DB Skill version.' );
wp_agent_github_skills_assert( false !== strpos( $reactivated['skill']['body'] ?? '', 'Updated workflow body' ), 'Refreshed package Skill should expose the updated body.' );

$rollbacks = wp_agent_github_skills_run_json( 'wp-agent skills rollbacks --slug=cli-github-fixture --format=json' );
wp_agent_github_skills_assert( ! empty( $rollbacks['rollbacks'] ), 'Refreshing an installed package should create a rollback snapshot.' );

echo wp_json_encode( array(
    'success'        => true,
    'quarantine_id'  => $installed['quarantine_id'],
    'refresh_id'     => $refresh['quarantine_id'],
    'http_calls'     => count( $http_calls ),
    'rollback_count' => count( $rollbacks['rollbacks'] ?? array() ),
) ) . "\n";
