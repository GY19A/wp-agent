<?php
/**
 * Sandbox Broker M0 — session workspaces and execution-backend discovery.
 *
 * The broker is the single boundary between the agent brain and isolated
 * execution. M0 intentionally does not execute code unless a hardened backend
 * is installed in the PHP runtime environment.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface WPAgent_Sandbox_Backend {
    public function name();
    public function available();
    public function status();
}

class WPAgent_Sandbox_Broker {

    /** @var int Max files imported from one isolated execution. */
    const MAX_IMPORTED_FILES = 20;

    /** @var int Max combined bytes imported from one isolated execution. */
    const MAX_IMPORTED_BYTES = 2097152;

    /** @var int Cache backend discovery briefly; sandbox self-tests can be expensive. */
    const STATUS_CACHE_SECONDS = 300;

    /**
     * Return a persistent workspace scoped to one conversation/session.
     */
    public function workspace( $conversation_id, $user_id, $run_id = 0 ) {
        if ( (int) $run_id > 0 ) {
            $run_base = trailingslashit( WPAgent_Sandbox::runtime_area_dir( 'runs' ) ) . 'run-' . (int) $run_id;
            return new WPAgent_Sandbox( 'workspace', $run_base );
        }

        $scope = (int) $conversation_id > 0 ? 'c' . (int) $conversation_id : 'u' . (int) $user_id;
        return new WPAgent_Sandbox( $scope );
    }

    /**
     * Report runtime isolation capabilities without spawning untrusted code.
     */
    public function status( $verify = true ) {
        if ( ! $verify ) {
            return $this->light_status();
        }

        $cached = get_transient( $this->status_cache_key() );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $backends = $this->backends();
        $selected = $this->select_backend( $backends );

        $status = array(
            'selected'  => $selected,
            'execution' => 'disabled' !== $selected ? 'enabled' : 'disabled',
            'backends'  => $backends,
            'policy'    => $this->backend_policy(),
            'security'  => array(
                'credentials_in_sandbox' => false,
                'network_default'        => 'deny',
                'workspace_mount'        => 'read-only input snapshot exposed as WP_AGENT_WORKSPACE_INPUT or /workspace/input',
                'output_mount'           => 'ephemeral writable output exposed as WP_AGENT_WORKSPACE_OUTPUT or /workspace/output with controlled import',
                'persistent_writes'      => 'control-plane tools only',
                'raw_process_fallback'   => false,
            ),
        );

        set_transient( $this->status_cache_key(), $status, self::STATUS_CACHE_SECONDS );
        return $status;
    }

    public function light_status() {
        $namespace = $this->bwrap_light_status();
        $backends = array(
            'wasm'      => $this->binary_status( 'wasmtime', array( '/usr/local/bin/wasmtime', '/usr/bin/wasmtime' ) ),
            'namespace' => $namespace,
            'php_cli'   => $this->php_cli_light_status(),
        );

        $selected = $this->select_backend( $backends );

        return array(
            'selected'  => $selected,
            'execution' => 'disabled' !== $selected ? 'enabled' : 'disabled',
            'backends'  => $backends,
            'policy'    => $this->backend_policy(),
            'security'  => array(
                'credentials_in_sandbox' => false,
                'network_default'        => 'deny',
                'workspace_mount'        => 'read-only input snapshot exposed as WP_AGENT_WORKSPACE_INPUT or /workspace/input',
                'output_mount'           => 'ephemeral writable output exposed as WP_AGENT_WORKSPACE_OUTPUT or /workspace/output with controlled import',
                'persistent_writes'      => 'control-plane tools only',
                'raw_process_fallback'   => false,
            ),
        );
    }

    /**
     * Execute a PHP snippet inside the selected hardened backend.
     *
     * @param WPAgent_Sandbox $workspace
     * @param array           $request
     * @return array|WP_Error
     */
    public function execute( WPAgent_Sandbox $workspace, $request ) {
        $status = $this->status();
        if ( 'disabled' === $status['selected'] ) {
            return new WP_Error( 'wp_agent_code_execution_unavailable', 'No hardened sandbox execution backend is available.' );
        }

        $language = sanitize_key( $request['language'] ?? 'php' );
        if ( 'php' !== $language ) {
            return new WP_Error( 'wp_agent_code_language', 'Only PHP execution is supported.' );
        }

        $code = (string) ( $request['code'] ?? '' );
        if ( '' === trim( $code ) ) {
            return new WP_Error( 'wp_agent_code_empty', 'Code is required.' );
        }
        if ( false !== strpos( $code, '<?' ) || false !== strpos( $code, '?>' ) ) {
            return new WP_Error( 'wp_agent_code_tags', 'Provide a PHP snippet without opening or closing PHP tags.' );
        }
        if ( strlen( $code ) > 65536 ) {
            return new WP_Error( 'wp_agent_code_size', 'Code exceeds the 64 KB limit.' );
        }

        $root = $workspace->root();
        if ( is_wp_error( $root ) ) {
            return $root;
        }

        $id         = substr( hash( 'sha256', $code . microtime( true ) . wp_generate_password( 12, false ) ), 0, 16 );
        $timeout    = isset( $request['timeout'] ) ? max( 1, min( (int) $request['timeout'], 15 ) ) : 5;
        $max_output = isset( $request['max_output'] ) ? max( 1024, min( (int) $request['max_output'], 65536 ) ) : 32768;
        $import     = ! array_key_exists( 'import_outputs', $request ) || (bool) $request['import_outputs'];
        $prefix     = $this->sanitize_output_prefix( $request['output_prefix'] ?? 'runs/run-' . $id );
        if ( 'php_cli' === $status['selected'] ) {
            $backend = $status['backends']['php_cli'];
            return $this->execute_php_cli( $backend['path'], $workspace, $root, $code, $id, $timeout, $max_output, $import, $prefix );
        }

        $backend = $status['backends']['namespace'];

        return $this->execute_bwrap_php( $backend['path'], $workspace, $root, $code, $id, $timeout, $max_output, $import, $prefix );
    }

    private function backends() {
        $bwrap = $this->bwrap_status();
        $backends = array(
            'wasm'      => $this->binary_status( 'wasmtime', array( '/usr/local/bin/wasmtime', '/usr/bin/wasmtime' ) ),
            'namespace' => $bwrap,
            'php_cli'   => $this->php_cli_status(),
        );
        return $backends;
    }

    private function status_cache_key() {
        return 'wp_agent_sandbox_status_' . md5( wp_json_encode( array(
            'runtime_root'   => WPAgent_Sandbox::runtime_root(),
            'php_cli_opt_in' => $this->php_cli_opt_in() ? '1' : '0',
            'no_microvm'     => '1',
            'php'            => defined( 'PHP_BINARY' ) ? PHP_BINARY : '',
        ) ) );
    }

    private function select_backend( $backends ) {
        if ( ! empty( $backends['namespace']['available'] ) ) {
            return 'namespace';
        }

        if ( $this->php_cli_opt_in() && ! empty( $backends['php_cli']['available'] ) ) {
            return 'php_cli';
        }

        return 'disabled';
    }

    private function backend_policy() {
        return array(
            'default_preference' => array( 'namespace', 'disabled' ),
            'php_cli_opt_in'     => $this->php_cli_opt_in(),
            'php_cli_note'       => 'Restricted PHP CLI execution is native and default-off; it is not a VM boundary and must be explicitly enabled by the site owner.',
            'microvm_default'    => false,
            'microvm_opt_in'     => false,
            'microvm_removed'    => true,
            'microvm_note'       => 'microVM/QEMU/KVM execution has been removed from the plugin runtime path.',
        );
    }

    private function php_cli_opt_in() {
        if ( defined( 'WP_AGENT_ENABLE_PHP_CLI_EXECUTION' ) ) {
            return (bool) WP_AGENT_ENABLE_PHP_CLI_EXECUTION;
        }

        $env = getenv( 'WP_AGENT_ENABLE_PHP_CLI_EXECUTION' );
        if ( false !== $env ) {
            return in_array( strtolower( trim( (string) $env ) ), array( '1', 'true', 'yes', 'on' ), true );
        }

        return (bool) get_option( 'wp_agent_enable_php_cli_execution', false );
    }

    private function bwrap_status() {
        $binary = $this->binary_status( 'bwrap', array( '/usr/bin/bwrap', '/usr/local/bin/bwrap' ) );
        if ( empty( $binary['available'] ) ) {
            return $binary;
        }
        if ( ! function_exists( 'proc_open' ) ) {
            return array(
                'available' => false,
                'path'      => $binary['path'],
                'reason'    => 'proc_open() is disabled, so bwrap cannot be launched.',
            );
        }

        $test = $this->bwrap_self_test( $binary['path'] );
        if ( is_wp_error( $test ) ) {
            return array(
                'available' => false,
                'path'      => $binary['path'],
                'reason'    => $test->get_error_message(),
            );
        }

        return array(
            'available' => true,
            'path'      => $binary['path'],
            'reason'    => 'bwrap namespace backend is available with network isolation.',
        );
    }

    private function bwrap_light_status() {
        $binary = $this->binary_status( 'bwrap', array( '/usr/bin/bwrap', '/usr/local/bin/bwrap' ) );
        if ( empty( $binary['available'] ) ) {
            return $binary;
        }
        if ( ! function_exists( 'proc_open' ) ) {
            return array(
                'available' => false,
                'path'      => $binary['path'],
                'reason'    => 'proc_open() is disabled, so bwrap cannot be launched.',
            );
        }

        return array(
            'available' => true,
            'path'      => $binary['path'],
            'reason'    => 'bwrap namespace backend is configured; isolated self-test runs on first code execution.',
        );
    }

    private function binary_status( $name, $paths ) {
        foreach ( $paths as $path ) {
            if ( is_file( $path ) && is_executable( $path ) ) {
                return array( 'available' => true, 'path' => $path, 'reason' => $name . ' is installed.' );
            }
        }
        return array( 'available' => false, 'path' => null, 'reason' => $name . ' is not installed in the PHP runtime.' );
    }

    private function php_cli_status() {
        if ( ! $this->php_cli_opt_in() ) {
            return array(
                'available' => false,
                'path'      => null,
                'reason'    => 'Restricted PHP CLI execution is disabled by default.',
            );
        }
        if ( ! function_exists( 'proc_open' ) ) {
            return array(
                'available' => false,
                'path'      => null,
                'reason'    => 'proc_open() is disabled, so PHP CLI execution cannot be launched.',
            );
        }

        $binary = $this->php_cli_binary_status();
        if ( empty( $binary['available'] ) ) {
            return $binary;
        }

        $test = $this->php_cli_self_test( $binary['path'] );
        if ( is_wp_error( $test ) ) {
            return array(
                'available' => false,
                'path'      => $binary['path'],
                'reason'    => $test->get_error_message(),
            );
        }

        return array(
            'available' => true,
            'path'      => $binary['path'],
            'reason'    => 'Restricted PHP CLI backend is explicitly enabled and passed the self-test.',
        );
    }

    private function php_cli_light_status() {
        if ( ! $this->php_cli_opt_in() ) {
            return array(
                'available' => false,
                'path'      => null,
                'reason'    => 'Restricted PHP CLI execution is disabled by default.',
            );
        }
        if ( ! function_exists( 'proc_open' ) ) {
            return array(
                'available' => false,
                'path'      => null,
                'reason'    => 'proc_open() is disabled, so PHP CLI execution cannot be launched.',
            );
        }

        $binary = $this->php_cli_binary_status();
        if ( empty( $binary['available'] ) ) {
            return $binary;
        }

        return array(
            'available' => true,
            'path'      => $binary['path'],
            'reason'    => 'Restricted PHP CLI backend is explicitly enabled; self-test runs on first code execution.',
        );
    }

    private function php_cli_binary_status() {
        $binary = $this->php_binary();
        if ( 'php' === $binary || '' === $binary || ! is_file( $binary ) || ! is_executable( $binary ) || ! $this->binary_is_cli_php( $binary ) ) {
            return array(
                'available' => false,
                'path'      => null,
                'reason'    => 'No verified PHP CLI binary is available.',
            );
        }

        return array(
            'available' => true,
            'path'      => $binary,
            'reason'    => 'Verified PHP CLI binary is available.',
        );
    }

    private function bwrap_self_test( $bwrap ) {
        static $cached = null;
        if ( null !== $cached ) {
            return true === $cached ? true : new WP_Error( 'wp_agent_bwrap_unusable', $cached );
        }

        $layout = $this->create_execution_layout();
        if ( is_wp_error( $layout ) ) {
            $cached = $layout->get_error_message();
            return new WP_Error( 'wp_agent_bwrap_unusable', $cached );
        }

        $input = $layout['root'] . '/input';
        wp_mkdir_p( $input );
        @chmod( $input, 0700 );

        $script = $layout['root'] . '/self-test.php';
        if ( false === file_put_contents( $script, "<?php file_put_contents('/workspace/output/self-test.txt', 'ok'); file_put_contents('/tmp/wp-agent-self-test', 'ok'); echo is_file('/workspace/output/self-test.txt') && is_file('/tmp/wp-agent-self-test') ? 'wp-agent-ok' : 'wp-agent-fail';\n" ) ) {
            $this->rrmdir( $layout['root'] );
            $cached = 'Could not write the bwrap PHP self-test script.';
            return new WP_Error( 'wp_agent_bwrap_unusable', $cached );
        }
        @chmod( $script, 0600 );

        $cmd = $this->bwrap_php_command( $bwrap, $input, $script, 3 );
        $result = $this->run_process( $cmd, 3, 2048 );
        $this->rrmdir( $layout['root'] );

        if ( is_wp_error( $result ) ) {
            $cached = $result->get_error_message();
            return new WP_Error( 'wp_agent_bwrap_unusable', $cached );
        }
        if ( 0 !== (int) $result['exit_code'] ) {
            $cached = trim( $result['stderr'] ) ? trim( $result['stderr'] ) : 'bwrap self-test failed.';
            return new WP_Error( 'wp_agent_bwrap_unusable', $cached );
        }
        if ( 'wp-agent-ok' !== trim( $result['stdout'] ) ) {
            $cached = 'bwrap self-test could not launch the isolated PHP runtime.';
            return new WP_Error( 'wp_agent_bwrap_unusable', $cached );
        }

        $cached = true;
        return true;
    }

    private function execute_bwrap_php( $bwrap, WPAgent_Sandbox $workspace, $workspace_root, $code, $run_id, $timeout, $max_output, $import_outputs, $output_prefix ) {
        $layout = $this->create_execution_layout();
        if ( is_wp_error( $layout ) ) {
            return $layout;
        }

        $script = $layout['root'] . '/runner.php';
        if ( false === file_put_contents( $script, "<?php\n" . $code . "\n" ) ) {
            $this->rrmdir( $layout['root'] );
            return new WP_Error( 'wp_agent_exec_script', 'Could not write the isolated PHP runner.' );
        }
        @chmod( $script, 0600 );

        $started = microtime( true );
        $cmd     = $this->bwrap_php_command( $bwrap, $workspace_root, $script, $timeout );
        $result  = $this->run_process( $cmd, $timeout, $max_output );

        if ( is_wp_error( $result ) ) {
            $this->rrmdir( $layout['root'] );
            return $result;
        }

        $outputs = array(
            'enabled'     => (bool) $import_outputs,
            'prefix'      => $output_prefix,
            'imported'    => array(),
            'skipped'     => array(),
            'limits'      => array(
                'max_files' => self::MAX_IMPORTED_FILES,
                'max_bytes' => self::MAX_IMPORTED_BYTES,
                'extensions' => WPAgent_Sandbox::ALLOWED_EXT,
            ),
        );
        if ( $import_outputs && 0 === (int) $result['exit_code'] && empty( $result['timed_out'] ) ) {
            $outputs = $this->import_outputs( $workspace, $layout['root'] . '/workspace/output', $output_prefix );
        } elseif ( $import_outputs ) {
            $outputs['skipped'][] = array(
                'reason' => 'execution_not_successful',
            );
        }

        $this->rrmdir( $layout['root'] );

        return array(
            'success'          => 0 === (int) $result['exit_code'],
            'backend'          => 'bwrap',
            'language'         => 'php',
            'run_id'           => $run_id,
            'exit_code'        => (int) $result['exit_code'],
            'stdout'           => $result['stdout'],
            'stderr'           => $result['stderr'],
            'timed_out'        => ! empty( $result['timed_out'] ),
            'output_truncated' => ! empty( $result['output_truncated'] ),
            'duration_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
            'filesystem'       => array(
                'input'             => '/workspace/input',
                'output'            => '/workspace/output',
                'persistent_writes' => false,
            ),
            'outputs'          => $outputs,
        );
    }

    private function php_cli_self_test( $php_binary ) {
        static $cached = array();
        if ( array_key_exists( $php_binary, $cached ) ) {
            return true === $cached[ $php_binary ] ? true : new WP_Error( 'wp_agent_php_cli_unusable', $cached[ $php_binary ] );
        }

        $layout = $this->create_execution_layout();
        if ( is_wp_error( $layout ) ) {
            $cached[ $php_binary ] = $layout->get_error_message();
            return new WP_Error( 'wp_agent_php_cli_unusable', $cached[ $php_binary ] );
        }

        @chmod( $layout['root'] . '/workspace/input', 0700 );
        file_put_contents( $layout['root'] . '/workspace/input/self-test.txt', 'ok' );
        @chmod( $layout['root'] . '/workspace/input/self-test.txt', 0400 );
        $script = $layout['root'] . '/workspace/runner.php';
        @chmod( $script, 0600 );
        $body   = "<?php\n"
            . "define('WP_AGENT_WORKSPACE', __DIR__);\n"
            . "define('WP_AGENT_WORKSPACE_INPUT', __DIR__ . '/input');\n"
            . "define('WP_AGENT_WORKSPACE_OUTPUT', __DIR__ . '/output');\n"
            . "chdir(__DIR__);\n"
            . "file_put_contents(WP_AGENT_WORKSPACE_OUTPUT . '/self-test.txt', file_get_contents(WP_AGENT_WORKSPACE_INPUT . '/self-test.txt'));\n"
            . "echo is_file(WP_AGENT_WORKSPACE_OUTPUT . '/self-test.txt') ? 'wp-agent-ok' : 'wp-agent-fail';\n";
        if ( false === file_put_contents( $script, $body ) ) {
            $this->rrmdir( $layout['root'] );
            $cached[ $php_binary ] = 'Could not write the PHP CLI self-test script.';
            return new WP_Error( 'wp_agent_php_cli_unusable', $cached[ $php_binary ] );
        }
        @chmod( $script, 0400 );

        $result = $this->run_process_in_cwd(
            $this->php_cli_command( $php_binary, $script, 3, $layout['root'] ),
            $layout['root'] . '/workspace',
            3,
            2048
        );
        $this->rrmdir( $layout['root'] );

        if ( is_wp_error( $result ) ) {
            $cached[ $php_binary ] = $result->get_error_message();
            return new WP_Error( 'wp_agent_php_cli_unusable', $cached[ $php_binary ] );
        }
        if ( 0 !== (int) $result['exit_code'] ) {
            $cached[ $php_binary ] = trim( $result['stderr'] ) ? trim( $result['stderr'] ) : 'PHP CLI self-test failed.';
            return new WP_Error( 'wp_agent_php_cli_unusable', $cached[ $php_binary ] );
        }
        if ( 'wp-agent-ok' !== trim( $result['stdout'] ) ) {
            $cached[ $php_binary ] = 'PHP CLI self-test could not launch the restricted runtime.';
            return new WP_Error( 'wp_agent_php_cli_unusable', $cached[ $php_binary ] );
        }

        $cached[ $php_binary ] = true;
        return true;
    }

    private function execute_php_cli( $php_binary, WPAgent_Sandbox $workspace, $workspace_root, $code, $run_id, $timeout, $max_output, $import_outputs, $output_prefix ) {
        $layout = $this->create_execution_layout();
        if ( is_wp_error( $layout ) ) {
            return $layout;
        }

        $snapshot = $this->copy_workspace_snapshot( $workspace, $layout['root'] . '/workspace/input' );
        if ( is_wp_error( $snapshot ) ) {
            $this->rrmdir( $layout['root'] );
            return $snapshot;
        }

        $script = $layout['root'] . '/workspace/runner.php';
        @chmod( $script, 0600 );
        $body   = "<?php\n"
            . "define('WP_AGENT_WORKSPACE', __DIR__);\n"
            . "define('WP_AGENT_WORKSPACE_INPUT', __DIR__ . '/input');\n"
            . "define('WP_AGENT_WORKSPACE_OUTPUT', __DIR__ . '/output');\n"
            . "chdir(__DIR__);\n"
            . $code . "\n";
        if ( false === file_put_contents( $script, $body ) ) {
            $this->rrmdir( $layout['root'] );
            return new WP_Error( 'wp_agent_exec_script', 'Could not write the restricted PHP CLI runner.' );
        }
        @chmod( $script, 0400 );

        $started = microtime( true );
        $result  = $this->run_process_in_cwd(
            $this->php_cli_command( $php_binary, $script, $timeout, $layout['root'] ),
            $layout['root'] . '/workspace',
            $timeout,
            $max_output
        );

        if ( is_wp_error( $result ) ) {
            $this->rrmdir( $layout['root'] );
            return $result;
        }

        $outputs = array(
            'enabled'  => (bool) $import_outputs,
            'prefix'   => $output_prefix,
            'imported' => array(),
            'skipped'  => array(),
            'limits'   => array(
                'max_files'  => self::MAX_IMPORTED_FILES,
                'max_bytes'  => self::MAX_IMPORTED_BYTES,
                'extensions' => WPAgent_Sandbox::ALLOWED_EXT,
            ),
        );
        if ( $import_outputs && 0 === (int) $result['exit_code'] && empty( $result['timed_out'] ) ) {
            $outputs = $this->import_outputs( $workspace, $layout['root'] . '/workspace/output', $output_prefix );
        } elseif ( $import_outputs ) {
            $outputs['skipped'][] = array(
                'reason' => 'execution_not_successful',
            );
        }

        $this->rrmdir( $layout['root'] );

        return array(
            'success'          => 0 === (int) $result['exit_code'],
            'backend'          => 'php_cli',
            'language'         => 'php',
            'run_id'           => $run_id,
            'exit_code'        => (int) $result['exit_code'],
            'stdout'           => $result['stdout'],
            'stderr'           => $result['stderr'],
            'timed_out'        => ! empty( $result['timed_out'] ),
            'output_truncated' => ! empty( $result['output_truncated'] ),
            'duration_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
            'filesystem'       => array(
                'cwd'               => 'private ephemeral workspace',
                'input'             => 'WP_AGENT_WORKSPACE_INPUT',
                'output'            => 'WP_AGENT_WORKSPACE_OUTPUT',
                'persistent_writes' => false,
                'snapshot'          => $snapshot,
            ),
            'outputs'          => $outputs,
        );
    }

    private function copy_workspace_snapshot( WPAgent_Sandbox $workspace, $input_root ) {
        if ( ! is_dir( $input_root ) ) {
            wp_mkdir_p( $input_root );
        }
        @chmod( $input_root, 0700 );

        $files = $workspace->list();
        if ( is_wp_error( $files ) ) {
            return $files;
        }

        $copied = 0;
        $bytes  = 0;
        foreach ( $files as $file ) {
            if ( $copied >= self::MAX_IMPORTED_FILES ) {
                break;
            }

            $rel = $this->sanitize_output_rel( $file['rel'] ?? '' );
            if ( '' === $rel ) {
                continue;
            }
            $size = (int) ( $file['bytes'] ?? 0 );
            if ( $size > WPAgent_Sandbox::MAX_BYTES || $bytes + $size > self::MAX_IMPORTED_BYTES ) {
                continue;
            }

            $content = $workspace->read( $rel );
            if ( is_wp_error( $content ) ) {
                continue;
            }

            $dest = trailingslashit( $input_root ) . $rel;
            $dir  = dirname( $dest );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            if ( false !== file_put_contents( $dest, (string) $content ) ) {
                @chmod( $dest, 0400 );
                $copied++;
                $bytes += strlen( (string) $content );
            }
        }

        $this->chmod_tree_read_only( $input_root );

        return array(
            'files' => $copied,
            'bytes' => $bytes,
        );
    }

    private function chmod_tree_read_only( $root ) {
        if ( ! is_dir( $root ) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $file ) {
            @chmod( $file->getPathname(), $file->isDir() ? 0500 : 0400 );
        }
        @chmod( $root, 0500 );
    }

    private function bwrap_php_command( $bwrap, $workspace_root, $script_path, $timeout ) {
        $layout_root = dirname( $script_path );
        $workspace   = $layout_root . '/workspace';
        $placeholder = $workspace . '/runner.php';
        if ( ! is_dir( $workspace . '/input' ) ) {
            wp_mkdir_p( $workspace . '/input' );
        }
        if ( ! is_dir( $workspace . '/output' ) ) {
            wp_mkdir_p( $workspace . '/output' );
        }
        if ( ! is_file( $placeholder ) ) {
            @chmod( $workspace, 0755 );
            file_put_contents( $placeholder, '' );
        }
        @chmod( $workspace, 0700 );
        @chmod( $workspace . '/input', 0555 );
        @chmod( $workspace . '/output', 0700 );
        @chmod( $placeholder, 0444 );

        $php_binary = $this->php_binary();

        $cmd = array(
            $bwrap,
            '--die-with-parent',
            '--new-session',
            '--unshare-user',
            '--unshare-ipc',
            '--unshare-pid',
            '--unshare-net',
            '--unshare-uts',
            '--clearenv',
            '--setenv', 'PATH', '/usr/local/bin:/usr/bin:/bin',
            '--setenv', 'HOME', '/tmp',
            '--setenv', 'TMPDIR', '/tmp',
        );

        foreach ( array( '/usr', '/bin', '/lib', '/lib64', '/etc/alternatives', '/etc/ssl' ) as $path ) {
            if ( file_exists( $path ) ) {
                $cmd[] = '--ro-bind';
                $cmd[] = $path;
                $cmd[] = $path;
            }
        }

        $cmd[] = '--dev';
        $cmd[] = '/dev';
        $cmd[] = '--proc';
        $cmd[] = '/proc';
        $cmd[] = '--tmpfs';
        $cmd[] = '/tmp';
        $cmd[] = '--bind';
        $cmd[] = $workspace;
        $cmd[] = '/workspace';
        $cmd[] = '--ro-bind';
        $cmd[] = $workspace_root;
        $cmd[] = '/workspace/input';
        $cmd[] = '--ro-bind';
        $cmd[] = $script_path;
        $cmd[] = '/workspace/runner.php';
        $cmd[] = '--chdir';
        $cmd[] = '/tmp';
        $cmd[] = $php_binary;
        $cmd[] = '-n';

        foreach ( $this->php_ini_overrides( $timeout ) as $key => $value ) {
            $cmd[] = '-d';
            $cmd[] = $key . '=' . $value;
        }

        $cmd[] = '/workspace/runner.php';

        return $cmd;
    }

    private function php_cli_command( $php_binary, $script_path, $timeout, $layout_root ) {
        $workspace = $layout_root . '/workspace';
        $tmp       = $layout_root . '/tmp';
        if ( ! is_dir( $tmp ) ) {
            wp_mkdir_p( $tmp );
        }
        @chmod( $workspace, 0700 );
        @chmod( $workspace . '/input', 0500 );
        @chmod( $workspace . '/output', 0700 );
        @chmod( $tmp, 0700 );

        $cmd = array(
            $php_binary,
            '-n',
        );

        foreach ( $this->php_ini_overrides( $timeout, $workspace, $tmp ) as $key => $value ) {
            $cmd[] = '-d';
            $cmd[] = $key . '=' . $value;
        }

        $cmd[] = $script_path;

        return $cmd;
    }

    private function php_binary() {
        $candidates = array();
        if ( defined( 'PHP_BINARY' ) && '' !== PHP_BINARY ) {
            $candidates[] = PHP_BINARY;
        }
        if ( defined( 'PHP_BINDIR' ) && '' !== PHP_BINDIR ) {
            $candidates[] = trailingslashit( PHP_BINDIR ) . 'php';
        }
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/bin/php';

        foreach ( array_unique( $candidates ) as $candidate ) {
            if ( is_file( $candidate ) && is_executable( $candidate ) && $this->binary_is_cli_php( $candidate ) ) {
                return $candidate;
            }
        }

        return 'php';
    }

    private function binary_is_cli_php( $binary ) {
        if ( ! function_exists( 'proc_open' ) ) {
            return 'cli' === PHP_SAPI && defined( 'PHP_BINARY' ) && PHP_BINARY === $binary;
        }

        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );
        $process = @proc_open( array( $binary, '-r', 'echo PHP_SAPI;' ), $descriptors, $pipes, null, null ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Verifies the selected sandbox binary is PHP CLI.
        if ( ! is_resource( $process ) ) {
            return false;
        }

        fclose( $pipes[0] );
        $out = stream_get_contents( $pipes[1] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exit_code = proc_close( $process );

        return 0 === (int) $exit_code && 'cli' === trim( $out );
    }

    private function php_ini_overrides( $timeout, $open_basedir = '/workspace:/tmp', $tmp_dir = '/tmp' ) {
        return array(
            'memory_limit'      => '64M',
            'max_execution_time' => max( 1, (int) $timeout ),
            'max_input_time'    => 1,
            'open_basedir'      => $open_basedir,
            'sys_temp_dir'      => $tmp_dir,
            'disable_functions' => implode( ',', $this->disabled_php_functions() ),
            'disable_classes'   => 'FFI',
            'ffi.enable'        => 0,
            'enable_dl'         => 0,
            'allow_url_fopen'   => 0,
            'allow_url_include' => 0,
            'expose_php'        => 0,
            'display_errors'    => 'stderr',
            'log_errors'        => 0,
        );
    }

    private function disabled_php_functions() {
        return array(
            'exec',
            'shell_exec',
            'system',
            'passthru',
            'proc_open',
            'popen',
            'pcntl_alarm',
            'pcntl_async_signals',
            'pcntl_exec',
            'pcntl_fork',
            'pcntl_get_last_error',
            'pcntl_getpriority',
            'pcntl_setpriority',
            'pcntl_signal',
            'pcntl_signal_dispatch',
            'pcntl_sigprocmask',
            'pcntl_sigtimedwait',
            'pcntl_sigwaitinfo',
            'pcntl_strerror',
            'pcntl_wait',
            'pcntl_waitpid',
            'pcntl_wexitstatus',
            'pcntl_wifexited',
            'pcntl_wifsignaled',
            'pcntl_wifstopped',
            'pcntl_wstopsig',
            'pcntl_wtermsig',
            'dl',
            'ini_alter',
            'ini_restore',
            'ini_set',
            'putenv',
            'set_time_limit',
            'ignore_user_abort',
            'mail',
            'curl_exec',
            'curl_multi_exec',
            'fsockopen',
            'pfsockopen',
            'stream_socket_client',
            'socket_create',
            'socket_connect',
            'socket_create_listen',
            'socket_create_pair',
            'socket_import_stream',
            'socket_send',
            'socket_sendmsg',
            'socket_sendto',
            'socket_recv',
            'socket_recvmsg',
            'socket_recvfrom',
        );
    }

    private function create_execution_layout() {
        $base = WPAgent_Sandbox::runtime_area_dir( 'exec' );
        if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
            return new WP_Error( 'wp_agent_exec_root', 'Could not create the sandbox execution directory.' );
        }
        @chmod( $base, 0700 );

        for ( $i = 0; $i < 5; $i++ ) {
            $id   = substr( hash( 'sha256', microtime( true ) . wp_generate_password( 24, true, true ) ), 0, 16 );
            $root = untrailingslashit( $base ) . '/run-' . $id;
            if ( file_exists( $root ) ) {
                continue;
            }
            if ( wp_mkdir_p( $root . '/workspace/input' ) ) {
                wp_mkdir_p( $root . '/workspace/output' );
                wp_mkdir_p( $root . '/tmp' );
                file_put_contents( $root . '/workspace/runner.php', '' );
                @chmod( $root, 0700 );
                @chmod( $root . '/workspace', 0700 );
                @chmod( $root . '/workspace/input', 0555 );
                @chmod( $root . '/workspace/output', 0700 );
                @chmod( $root . '/tmp', 0700 );
                @chmod( $root . '/workspace/runner.php', 0444 );
                return array( 'root' => $root );
            }
        }

        return new WP_Error( 'wp_agent_exec_root', 'Could not allocate an isolated execution workspace.' );
    }

    private function import_outputs( WPAgent_Sandbox $workspace, $output_root, $prefix ) {
        $summary = array(
            'enabled'  => true,
            'prefix'   => $prefix,
            'imported' => array(),
            'skipped'  => array(),
            'limits'   => array(
                'max_files'  => self::MAX_IMPORTED_FILES,
                'max_bytes'  => self::MAX_IMPORTED_BYTES,
                'extensions' => WPAgent_Sandbox::ALLOWED_EXT,
            ),
        );

        if ( ! is_dir( $output_root ) ) {
            return $summary;
        }

        $real_root = realpath( $output_root );
        if ( false === $real_root ) {
            $summary['skipped'][] = array( 'reason' => 'output_root_unavailable' );
            return $summary;
        }

        $count = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $output_root, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() || $file->isLink() ) {
                continue;
            }

            $real = realpath( $file->getPathname() );
            if ( false === $real || 0 !== strpos( $real, $real_root . DIRECTORY_SEPARATOR ) ) {
                $summary['skipped'][] = array(
                    'path'   => $file->getFilename(),
                    'reason' => 'path_escape',
                );
                continue;
            }

            $rel = ltrim( str_replace( '\\', '/', substr( $real, strlen( $real_root ) ) ), '/' );
            $rel = $this->sanitize_output_rel( $rel );
            if ( '' === $rel ) {
                $summary['skipped'][] = array(
                    'path'   => $file->getFilename(),
                    'reason' => 'invalid_path',
                );
                continue;
            }

            $ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, WPAgent_Sandbox::ALLOWED_EXT, true ) ) {
                $summary['skipped'][] = array(
                    'path'   => $rel,
                    'reason' => 'extension_not_allowed',
                );
                continue;
            }

            $size = (int) $file->getSize();
            if ( $size > WPAgent_Sandbox::MAX_BYTES ) {
                $summary['skipped'][] = array(
                    'path'   => $rel,
                    'reason' => 'file_too_large',
                    'bytes'  => $size,
                );
                continue;
            }
            if ( $count >= self::MAX_IMPORTED_FILES ) {
                $summary['skipped'][] = array(
                    'path'   => $rel,
                    'reason' => 'too_many_files',
                );
                continue;
            }
            if ( $bytes + $size > self::MAX_IMPORTED_BYTES ) {
                $summary['skipped'][] = array(
                    'path'   => $rel,
                    'reason' => 'total_bytes_limit',
                    'bytes'  => $size,
                );
                continue;
            }

            $content = file_get_contents( $real );
            if ( false === $content ) {
                $summary['skipped'][] = array(
                    'path'   => $rel,
                    'reason' => 'read_failed',
                );
                continue;
            }

            $dest = '' !== $prefix ? trailingslashit( $prefix ) . $rel : $rel;
            $written = $workspace->write( $dest, $content );
            if ( is_wp_error( $written ) ) {
                $summary['skipped'][] = array(
                    'path'   => $rel,
                    'dest'   => $dest,
                    'reason' => $written->get_error_message(),
                );
                continue;
            }

            $count++;
            $bytes += $size;
            $summary['imported'][] = array(
                'path'  => $written['rel'],
                'bytes' => (int) $written['bytes'],
            );
        }

        return $summary;
    }

    private function sanitize_output_prefix( $prefix ) {
        $prefix = trim( str_replace( '\\', '/', (string) $prefix ), '/' );
        if ( '' === $prefix ) {
            return '';
        }
        return $this->sanitize_output_rel( $prefix );
    }

    private function sanitize_output_rel( $rel ) {
        $rel = trim( str_replace( '\\', '/', (string) $rel ), '/' );
        if ( '' === $rel ) {
            return '';
        }

        $safe = array();
        foreach ( explode( '/', $rel ) as $segment ) {
            $segment = trim( $segment );
            if ( '' === $segment || '.' === $segment || '..' === $segment ) {
                return '';
            }
            $segment = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $segment );
            $segment = trim( $segment, '.-' );
            if ( '' === $segment || '..' === $segment ) {
                return '';
            }
            $safe[] = $segment;
        }

        return implode( '/', $safe );
    }

    private function run_process( $cmd, $timeout, $max_output ) {
        return $this->run_process_with_input( $cmd, '', $timeout, $max_output );
    }

    private function run_process_in_cwd( $cmd, $cwd, $timeout, $max_output ) {
        return $this->run_process_with_input( $cmd, '', $timeout, $max_output, $cwd );
    }

    private function run_process_with_input( $cmd, $stdin, $timeout, $max_output, $cwd = null ) {
        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );

        $process = proc_open( $cmd, $descriptors, $pipes, $cwd, array() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Hardened sandbox backend.
        if ( ! is_resource( $process ) ) {
            return new WP_Error( 'wp_agent_exec_spawn', 'Failed to start sandbox process.' );
        }

        if ( '' !== $stdin ) {
            fwrite( $pipes[0], $stdin );
        }
        fclose( $pipes[0] );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );

        $stdout = '';
        $stderr = '';
        $deadline = microtime( true ) + $timeout;
        $timed_out = false;
        $output_truncated = false;
        $observed_exit_code = null;

        while ( true ) {
            $status = proc_get_status( $process );
            $stdout .= stream_get_contents( $pipes[1] );
            $stderr .= stream_get_contents( $pipes[2] );

            if ( strlen( $stdout ) + strlen( $stderr ) > $max_output ) {
                $output_truncated = true;
                $stderr .= "\n[wp-agent] output truncated";
                $this->terminate_process( $process );
                break;
            }

            if ( ! $status['running'] ) {
                if ( isset( $status['exitcode'] ) && -1 !== (int) $status['exitcode'] ) {
                    $observed_exit_code = (int) $status['exitcode'];
                }
                break;
            }
            if ( microtime( true ) >= $deadline ) {
                $timed_out = true;
                $this->terminate_process( $process );
                break;
            }

            usleep( 50000 );
        }

        $stdout .= stream_get_contents( $pipes[1] );
        $stderr .= stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $closed_exit = proc_close( $process );
        $exit = null !== $observed_exit_code ? $observed_exit_code : (int) $closed_exit;

        return array(
            'exit_code'        => $timed_out ? 124 : (int) $exit,
            'stdout'           => substr( $stdout, 0, $max_output ),
            'stderr'           => substr( $stderr, 0, $max_output ),
            'timed_out'        => $timed_out,
            'output_truncated' => $output_truncated,
        );
    }

    private function terminate_process( $process ) {
        proc_terminate( $process );
        $deadline = microtime( true ) + 0.5;
        do {
            usleep( 50000 );
            $status = proc_get_status( $process );
            if ( empty( $status['running'] ) ) {
                return;
            }
        } while ( microtime( true ) < $deadline );

        proc_terminate( $process, 9 );
    }

    private function rrmdir( $path ) {
        if ( ! is_dir( $path ) ) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $path );
    }
}
