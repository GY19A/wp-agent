<?php
/**
 * Isolated code execution tool.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Code_Execution extends WPAgent_Tool {

    public function get_name() {
        return 'execute_code';
    }

    public function get_description() {
        return 'Execute a small PHP snippet inside a private isolated sandbox. Read input through WP_AGENT_WORKSPACE_INPUT (or /workspace/input on namespace backends), write generated artifacts through WP_AGENT_WORKSPACE_OUTPUT (or /workspace/output on namespace backends) for controlled import. Available only when an explicitly enabled restricted backend is usable; no raw process fallback exists.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'run', 'status' ),
                    'description' => 'Use status to inspect availability or run to execute code.',
                ),
                'language' => array(
                    'type'        => 'string',
                    'enum'        => array( 'php' ),
                    'description' => 'Only php is supported.',
                ),
                'code' => array(
                    'type'        => 'string',
                    'description' => 'PHP snippet without opening or closing PHP tags. Maximum 64 KB. Read files from WP_AGENT_WORKSPACE_INPUT, write allowed artifacts to WP_AGENT_WORKSPACE_OUTPUT, and echo summary results to stdout.',
                ),
                'timeout' => array(
                    'type'        => 'integer',
                    'description' => 'Execution timeout in seconds, 1-15. Default 5.',
                ),
                'max_output' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum combined stdout/stderr bytes, 1024-65536. Default 32768.',
                ),
                'import_outputs' => array(
                    'type'        => 'boolean',
                    'description' => 'Whether to import allowed files from /workspace/output into the persistent workspace. Default true.',
                ),
                'output_prefix' => array(
                    'type'        => 'string',
                    'description' => 'Relative destination prefix for imported output files. Default runs/run-<id>.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'manage_options';
    }

    /**
     * Whether this tool should be exposed to the model.
     */
    public function is_available() {
        $status = ( new WPAgent_Sandbox_Broker() )->status();
        return ! empty( $status['execution'] ) && 'enabled' === $status['execution'];
    }

    public function execute( array $params ) {
        $broker = new WPAgent_Sandbox_Broker();
        $status = $broker->status();

        if ( 'status' === ( $params['action'] ?? '' ) ) {
            return array(
                'success' => true,
                'runtime' => $status,
            );
        }

        if ( 'run' !== ( $params['action'] ?? '' ) ) {
            return array( 'error' => 'Unknown action: ' . ( $params['action'] ?? '' ) );
        }

        if ( empty( $status['execution'] ) || 'enabled' !== $status['execution'] ) {
            return array(
                'error'   => 'No hardened sandbox execution backend is available.',
                'runtime' => $status,
            );
        }

        $workspace = $broker->workspace( $this->conversation_id, $this->owner_id(), $this->run_id );
        $request = array(
            'language'   => $params['language'] ?? 'php',
            'code'       => (string) ( $params['code'] ?? '' ),
            'timeout'    => $params['timeout'] ?? 5,
            'max_output' => $params['max_output'] ?? 32768,
        );
        if ( array_key_exists( 'import_outputs', $params ) ) {
            $request['import_outputs'] = $params['import_outputs'];
        }
        if ( array_key_exists( 'output_prefix', $params ) ) {
            $request['output_prefix'] = $params['output_prefix'];
        }

        $result = $broker->execute( $workspace, $request );

        if ( is_wp_error( $result ) ) {
            return array(
                'error'   => $result->get_error_message(),
                'runtime' => $status,
            );
        }

        $metadata = array(
            'backend'          => $result['backend'] ?? '',
            'language'         => $result['language'] ?? 'php',
            'exit_code'        => (int) ( $result['exit_code'] ?? -1 ),
            'timed_out'        => ! empty( $result['timed_out'] ),
            'output_truncated' => ! empty( $result['output_truncated'] ),
            'imported_outputs' => isset( $result['outputs']['imported'] ) ? count( $result['outputs']['imported'] ) : 0,
            'code_hash'        => hash( 'sha256', (string) ( $params['code'] ?? '' ) ),
        );

        if ( class_exists( 'WPAgent_Run_Events' ) && $this->run_id > 0 ) {
            WPAgent_Run_Events::add( $this->run_id, $this->owner_id(), 'sandbox_execution', 'Isolated PHP snippet executed.', $metadata );
        }
        WPAgent::audit_log( $this->owner_id(), 'sandbox_execution', $metadata, $this->channel );

        return $result;
    }
}
