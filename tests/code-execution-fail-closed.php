<?php
/**
 * WP Agent code execution fail-closed checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/code-execution-fail-closed.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This fail-closed script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_code_fail_closed_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_code_fail_closed_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_code_fail_closed_fail( $message );
    }
}

function wp_agent_code_fail_closed_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

$broker = new WPAgent_Sandbox_Broker();
$status = $broker->status();

wp_agent_code_fail_closed_assert( 'disabled' === ( $status['selected'] ?? '' ), 'Sandbox backend should be disabled in the official container.' );
wp_agent_code_fail_closed_assert( 'disabled' === ( $status['execution'] ?? '' ), 'Code execution should be disabled without a hardened backend.' );
wp_agent_code_fail_closed_assert( false === ( $status['security']['raw_process_fallback'] ?? true ), 'Raw process fallback must be disabled.' );
wp_agent_code_fail_closed_assert( false === ( $status['policy']['php_cli_opt_in'] ?? true ), 'Restricted PHP CLI backend must not be opted in by default.' );
wp_agent_code_fail_closed_assert( false === ( $status['policy']['microvm_default'] ?? true ), 'microVM must not be a default backend.' );
wp_agent_code_fail_closed_assert( false === ( $status['policy']['microvm_opt_in'] ?? true ), 'microVM must not be opted in by default.' );
wp_agent_code_fail_closed_assert( true === ( $status['policy']['microvm_removed'] ?? false ), 'microVM support should be removed from the plugin runtime path.' );
wp_agent_code_fail_closed_assert( ! isset( $status['backends']['microvm'] ), 'microVM backend should not be registered.' );
wp_agent_code_fail_closed_assert( empty( $status['backends']['namespace']['available'] ), 'Namespace backend should not be available in the official container.' );
wp_agent_code_fail_closed_assert( empty( $status['backends']['wasm']['available'] ), 'WASM backend should not be available in the official container.' );
wp_agent_code_fail_closed_assert( empty( $status['backends']['php_cli']['available'] ), 'Restricted PHP CLI backend should be disabled until explicitly enabled.' );

$tool = new WPAgent_Tool_Code_Execution();
$tool->set_context( 1, 'wpcli', 991622, 1, 0 );
wp_agent_code_fail_closed_assert( ! $tool->is_available(), 'execute_code tool should not be model-visible when unavailable.' );

$tool_status = $tool->execute( array( 'action' => 'status' ) );
wp_agent_code_fail_closed_assert( ! empty( $tool_status['success'] ), 'execute_code status should remain inspectable.' );
wp_agent_code_fail_closed_assert( 'disabled' === ( $tool_status['runtime']['execution'] ?? '' ), 'execute_code status should report disabled execution.' );

$run = $tool->execute( array(
    'action'   => 'run',
    'language' => 'php',
    'code'     => 'echo "unsafe";',
) );
wp_agent_code_fail_closed_assert( is_array( $run ) && ! empty( $run['error'] ), 'execute_code run should fail closed without a hardened backend.' );
wp_agent_code_fail_closed_assert( false !== strpos( $run['error'], 'No hardened sandbox execution backend' ), 'execute_code run should explain hardened backend requirement.' );

$registry = new WPAgent_Tools();
$definitions = $registry->get_definitions_for_user( 1 );
$tool_names = array();
foreach ( $definitions as $definition ) {
    $tool_names[] = $definition['name'] ?? '';
}
wp_agent_code_fail_closed_assert( ! in_array( 'execute_code', $tool_names, true ), 'execute_code should not be exposed in tool definitions while unavailable.' );

$permissions = new WPAgent_Permissions();
wp_agent_code_fail_closed_assert( $permissions->requires_confirmation( 'execute_code', array( 'action' => 'run' ) ), 'execute_code run should require confirmation.' );
wp_agent_code_fail_closed_assert( ! $permissions->requires_confirmation( 'execute_code', array( 'action' => 'status' ) ), 'execute_code status should not require confirmation.' );

$workspace = $broker->workspace( 991622, 1 );
$root = $workspace->root();
wp_agent_code_fail_closed_assert( ! is_wp_error( $root ), is_wp_error( $root ) ? $root->get_error_message() : 'Workspace root should resolve.' );
$runtime_root = WPAgent_Sandbox::runtime_root();
wp_agent_code_fail_closed_assert( wp_agent_code_fail_closed_path_starts_with( $root, $runtime_root ), 'Workspace root should live under runtime root.' );
wp_agent_code_fail_closed_assert( ! wp_agent_code_fail_closed_path_starts_with( $root, WP_AGENT_PLUGIN_DIR ), 'Workspace root must not live under plugin directory.' );

echo wp_json_encode( array(
    'success'      => true,
    'selected'     => $status['selected'],
    'execution'    => $status['execution'],
    'backends'     => array_keys( $status['backends'] ?? array() ),
    'tool_visible' => in_array( 'execute_code', $tool_names, true ),
    'runtime_root' => $runtime_root,
    'workspace'    => $root,
) ) . "\n";
