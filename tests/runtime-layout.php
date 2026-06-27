<?php
/**
 * WP Agent private runtime layout checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/runtime-layout.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This runtime layout script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_runtime_layout_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_runtime_layout_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_runtime_layout_fail( $message );
    }
}

function wp_agent_runtime_layout_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_runtime_layout_private_dir( $path, $label ) {
    wp_agent_runtime_layout_assert( is_dir( $path ), $label . ' should exist.' );
    wp_agent_runtime_layout_assert( wp_is_writable( $path ), $label . ' should be writable.' );
    $perms = fileperms( $path );
    if ( false !== $perms ) {
        wp_agent_runtime_layout_assert( 0 === ( $perms & 0007 ), $label . ' should not be world-accessible.' );
    }
}

$runtime_root = WPAgent_Sandbox::runtime_root();
$site_hash    = WPAgent_Sandbox::site_hash();
$site_root    = WPAgent_Sandbox::site_runtime_root();
$workspaces   = WPAgent_Sandbox::base_dir();
$runs         = WPAgent_Sandbox::runtime_area_dir( 'runs' );
$runtime      = WPAgent_Sandbox::runtime_area_dir( 'runtime' );
$exec         = WPAgent_Sandbox::runtime_area_dir( 'exec' );
$skills       = WPAgent_Sandbox::runtime_area_dir( 'skills' );

wp_agent_runtime_layout_assert( 16 === strlen( $site_hash ), 'Site hash should be a stable 16-character identifier.' );
wp_agent_runtime_layout_assert( wp_agent_runtime_layout_path_starts_with( $site_root, $runtime_root ), 'Site root should live under runtime root.' );
wp_agent_runtime_layout_assert( false !== strpos( wp_normalize_path( $site_root ), '/sites/' . $site_hash ), 'Site root should use sites/<site-hash> layout.' );

foreach ( array(
    'workspaces' => $workspaces,
    'runs'       => $runs,
    'runtime'    => $runtime,
    'exec'       => $exec,
    'skills'     => $skills,
) as $label => $path ) {
    wp_agent_runtime_layout_assert( wp_agent_runtime_layout_path_starts_with( $path, $site_root ), $label . ' should live under site runtime root.' );
    wp_agent_runtime_layout_private_dir( $path, $label );
}

foreach ( array( ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, wp_get_upload_dir()['basedir'] ) as $public_path ) {
    wp_agent_runtime_layout_assert( ! wp_agent_runtime_layout_path_starts_with( $site_root, $public_path ), 'Site runtime root must not live under public WordPress paths: ' . $public_path );
}

$broker = new WPAgent_Sandbox_Broker();

$conversation_workspace = $broker->workspace( 991701, 1 );
$conversation_root      = $conversation_workspace->root();
wp_agent_runtime_layout_assert( ! is_wp_error( $conversation_root ), is_wp_error( $conversation_root ) ? $conversation_root->get_error_message() : 'Conversation workspace should resolve.' );
wp_agent_runtime_layout_assert( wp_agent_runtime_layout_path_starts_with( $conversation_root, $workspaces ), 'Conversation workspace should live under site workspaces.' );

$run_workspace = $broker->workspace( 991701, 1, 991702 );
$run_root      = $run_workspace->root();
wp_agent_runtime_layout_assert( ! is_wp_error( $run_root ), is_wp_error( $run_root ) ? $run_root->get_error_message() : 'Run workspace should resolve.' );
wp_agent_runtime_layout_assert( wp_agent_runtime_layout_path_starts_with( $run_root, $runs . '/run-991702' ), 'Run workspace should live under sites/<site-hash>/runs/run-<id>.' );
wp_agent_runtime_layout_assert( 'workspace' === basename( $run_root ), 'Run workspace leaf should be named workspace.' );

$write = $run_workspace->write( 'notes/layout.txt', 'private runtime layout probe' );
wp_agent_runtime_layout_assert( ! is_wp_error( $write ) && ! empty( $write['path'] ), 'Run workspace write should succeed.' );
wp_agent_runtime_layout_assert( is_file( $run_root . '/notes/layout.txt' ), 'Run workspace file should be stored in the run directory.' );

wp_agent_runtime_layout_assert( wp_agent_runtime_layout_path_starts_with( WPAgent_Daemon::runtime_dir(), $runtime ), 'Daemon runtime directory should live under site runtime area.' );

echo wp_json_encode( array(
    'success'       => true,
    'runtime_root'  => $runtime_root,
    'site_hash'     => $site_hash,
    'site_root'     => $site_root,
    'workspaces'    => $workspaces,
    'runs'          => $runs,
    'run_workspace' => $run_root,
    'daemon_runtime' => WPAgent_Daemon::runtime_dir(),
) ) . "\n";
