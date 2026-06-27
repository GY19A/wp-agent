<?php
/**
 * WP Agent deterministic test isolation audit.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/test-isolation-audit.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This test isolation audit script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_test_isolation_fail( $message, $details = array() ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    if ( ! empty( $details ) ) {
        fwrite( STDERR, wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
    }
    exit( 1 );
}

$tests_dir = __DIR__;
$exempt    = array(
    'import-live-ai-settings.php' => 'Intentional settings import guarded by WP_AGENT_IMPORT_LIVE_AI_SETTINGS.',
    'plugin-lifecycle-cleanup.php' => 'Lifecycle cleanup intentionally exercises activation/deactivation hooks.',
    'uninstall-coverage.php'      => 'Uninstall coverage intentionally inspects plugin-owned cleanup behavior.',
    'uninstall-destructive.php'   => 'Destructive uninstall fixture intentionally removes plugin-owned state.',
);

$files       = glob( $tests_dir . '/*.php' );
$audited     = array();
$mutating    = array();
$skipped     = array();
$violations  = array();
$live_prefix = 'live-';

foreach ( $files as $file ) {
    $name = basename( $file );
    if ( 'test-isolation-audit.php' === $name ) {
        continue;
    }
    if ( 0 === strpos( $name, $live_prefix ) ) {
        $skipped[ $name ] = 'Live acceptance scripts are reviewed through explicit runbooks.';
        continue;
    }
    if ( isset( $exempt[ $name ] ) ) {
        $skipped[ $name ] = $exempt[ $name ];
        continue;
    }

    $source = (string) file_get_contents( $file );
    $audited[] = $name;

    $mutates_persistent_options = (bool) preg_match(
        '/(?:WPAgent::update_option\s*\(|(?:update_option|delete_option)\s*\(\s*[\'"]wp_agent_)/',
        $source
    );
    if ( ! $mutates_persistent_options ) {
        continue;
    }

    $mutating[] = $name;
    $has_shutdown_cleanup = (bool) preg_match(
        '/register_shutdown_function\s*\(\s*(?:[\'"][^\'"]*(?:cleanup|restore)[^\'"]*[\'"]|\$[A-Za-z0-9_]*(?:cleanup|restore|environment)[A-Za-z0-9_]*)\s*\)/i',
        $source
    );
    $has_cleanup_callable = (bool) preg_match(
        '/(?:function\s+[A-Za-z0-9_]*(?:cleanup|restore)|\$[A-Za-z0-9_]*(?:cleanup|restore|environment)[A-Za-z0-9_]*\s*=\s*function)/i',
        $source
    );
    $has_previous_state   = false !== strpos( $source, 'previous' ) || false !== strpos( $source, 'sentinel' );

    if ( ! $has_shutdown_cleanup || ! $has_cleanup_callable || ! $has_previous_state ) {
        $violations[] = array(
            'file'                 => $name,
            'has_shutdown_cleanup' => $has_shutdown_cleanup,
            'has_cleanup_callable' => $has_cleanup_callable,
            'has_previous_state'   => $has_previous_state,
        );
    }
}

if ( ! empty( $violations ) ) {
    wp_agent_test_isolation_fail( 'Deterministic tests that mutate wp_agent options must restore persisted settings through shutdown cleanup.', $violations );
}

echo wp_json_encode( array(
    'success'        => true,
    'audited_count'  => count( $audited ),
    'mutating_count' => count( $mutating ),
    'skipped_count'  => count( $skipped ),
    'mutating_files' => $mutating,
    'skipped'        => $skipped,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
