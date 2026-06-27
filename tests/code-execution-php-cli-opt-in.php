<?php
/**
 * WP Agent restricted PHP CLI code execution checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/code-execution-php-cli-opt-in.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This PHP CLI execution script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_php_cli_exec_fail( $message ) {
    if ( function_exists( 'wp_agent_php_cli_exec_cleanup' ) ) {
        wp_agent_php_cli_exec_cleanup();
    }
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_php_cli_exec_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_php_cli_exec_fail( $message );
    }
}

$GLOBALS['wp_agent_php_cli_exec_previous'] = array(
    'sentinel' => '__wp_agent_php_cli_exec_missing__',
    'value'    => get_option( 'wp_agent_enable_php_cli_execution', '__wp_agent_php_cli_exec_missing__' ),
);
$GLOBALS['wp_agent_php_cli_exec_restored'] = false;

function wp_agent_php_cli_exec_cleanup() {
    if ( ! empty( $GLOBALS['wp_agent_php_cli_exec_restored'] ) ) {
        return;
    }
    $previous = $GLOBALS['wp_agent_php_cli_exec_previous'];
    if ( $previous['sentinel'] === $previous['value'] ) {
        delete_option( 'wp_agent_enable_php_cli_execution' );
    } else {
        update_option( 'wp_agent_enable_php_cli_execution', $previous['value'], false );
    }
    $GLOBALS['wp_agent_php_cli_exec_restored'] = true;
}

register_shutdown_function( 'wp_agent_php_cli_exec_cleanup' );

update_option( 'wp_agent_enable_php_cli_execution', true, false );

$broker = new WPAgent_Sandbox_Broker();
$status = $broker->status();

wp_agent_php_cli_exec_assert( ! empty( $status['policy']['php_cli_opt_in'] ), 'Restricted PHP CLI backend should be explicitly opted in.' );
wp_agent_php_cli_exec_assert( 'php_cli' === ( $status['selected'] ?? '' ), 'Restricted PHP CLI backend should be selected in the official container when opted in.' );
wp_agent_php_cli_exec_assert( 'enabled' === ( $status['execution'] ?? '' ), 'Code execution should be enabled after explicit PHP CLI opt-in.' );
wp_agent_php_cli_exec_assert( ! empty( $status['backends']['php_cli']['available'] ), 'Restricted PHP CLI backend should be available after opt-in.' );

$tool = new WPAgent_Tool_Code_Execution();
$tool->set_context( 1, 'wpcli', 771923, 1, 0 );
wp_agent_php_cli_exec_assert( $tool->is_available(), 'execute_code should be model-visible when the opted-in backend is available.' );

$workspace = $broker->workspace( 771923, 1 );
$write = $workspace->write( 'input.txt', 'private-input-ok' );
wp_agent_php_cli_exec_assert( ! is_wp_error( $write ), is_wp_error( $write ) ? $write->get_error_message() : 'Workspace input fixture should be written.' );

$run = $tool->execute( array(
    'action'        => 'run',
    'language'      => 'php',
    'timeout'       => 5,
    'max_output'    => 8192,
    'output_prefix' => 'php-cli',
    'code'          => <<<'PHP'
echo 'input=' . trim(file_get_contents(WP_AGENT_WORKSPACE_INPUT . '/input.txt')) . "\n";
echo 'proc_open=' . (function_exists('proc_open') ? 'available' : 'disabled') . "\n";
echo 'allow_url_fopen=' . ini_get('allow_url_fopen') . "\n";
$escape = @file_put_contents(dirname(WP_AGENT_WORKSPACE) . '/escape.txt', 'bad');
echo 'escape=' . (false === $escape ? 'blocked' : 'wrote') . "\n";
file_put_contents(WP_AGENT_WORKSPACE_OUTPUT . '/result.txt', 'result-ok');
file_put_contents(WP_AGENT_WORKSPACE_OUTPUT . '/blocked.php', 'bad executable extension');
PHP,
) );

wp_agent_php_cli_exec_assert( is_array( $run ) && ! empty( $run['success'] ), 'Restricted PHP CLI run should succeed.' );
wp_agent_php_cli_exec_assert( 'php_cli' === ( $run['backend'] ?? '' ), 'Run should report the php_cli backend.' );
wp_agent_php_cli_exec_assert( false !== strpos( $run['stdout'], 'input=private-input-ok' ), 'Run should read the input snapshot.' );
wp_agent_php_cli_exec_assert( false !== strpos( $run['stdout'], 'proc_open=disabled' ), 'Dangerous process functions should be disabled inside the snippet.' );
wp_agent_php_cli_exec_assert( false !== strpos( $run['stdout'], 'allow_url_fopen=0' ), 'URL fopen should be disabled inside the snippet.' );
wp_agent_php_cli_exec_assert( false !== strpos( $run['stdout'], 'escape=blocked' ), 'Snippet should not write outside the ephemeral workspace.' );
wp_agent_php_cli_exec_assert( 1 === count( $run['outputs']['imported'] ?? array() ), 'Exactly one allowed output should be imported.' );

$imported = $workspace->read( 'php-cli/result.txt' );
wp_agent_php_cli_exec_assert( 'result-ok' === $imported, 'Allowed output should be imported into the persistent workspace.' );

$blocked = $workspace->read( 'php-cli/blocked.php' );
wp_agent_php_cli_exec_assert( is_wp_error( $blocked ), 'Disallowed executable output should not be imported.' );

$timeout = $tool->execute( array(
    'action'     => 'run',
    'language'   => 'php',
    'timeout'    => 1,
    'max_output' => 2048,
    'code'       => 'while (true) { }',
) );
wp_agent_php_cli_exec_assert( is_array( $timeout ) && ! empty( $timeout['timed_out'] ), 'Long-running snippets should time out.' );
wp_agent_php_cli_exec_assert( 124 === (int) ( $timeout['exit_code'] ?? 0 ), 'Timed-out snippets should report exit code 124.' );

wp_agent_php_cli_exec_cleanup();

echo wp_json_encode( array(
    'success'       => true,
    'selected'      => $status['selected'],
    'execution'     => $status['execution'],
    'backend_reason' => $status['backends']['php_cli']['reason'] ?? '',
    'stdout'        => $run['stdout'],
    'outputs'       => $run['outputs'],
    'timeout'       => array(
        'exit_code' => (int) $timeout['exit_code'],
        'timed_out' => ! empty( $timeout['timed_out'] ),
    ),
) ) . "\n";
