<?php
/**
 * Host-side daemon lifecycle evidence contract.
 *
 * Verifies the native PHP daemon lifecycle remains backed by auditable source,
 * deterministic tests, live harnesses, and design research. This script only
 * reads local files; it does not start Docker, wake agentd, call GitHub, call
 * the AI gateway, or mutate WordPress state.
 *
 * Run from the host:
 * php tests/daemon-lifecycle-evidence-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This daemon lifecycle evidence contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_daemon_lifecycle_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_daemon_lifecycle_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_daemon_lifecycle_fail( $message, $details );
	}
}

function wp_agent_daemon_lifecycle_read_path( $path, $label ) {
	wp_agent_daemon_lifecycle_assert( is_file( $path ), 'Required daemon lifecycle evidence file is missing.', array(
		'file' => $label,
		'path' => $path,
	) );

	$text = file_get_contents( $path );
	wp_agent_daemon_lifecycle_assert( is_string( $text ) && '' !== $text, 'Required daemon lifecycle evidence file could not be read.', array(
		'file' => $label,
		'path' => $path,
	) );

	return $text;
}

function wp_agent_daemon_lifecycle_read( $plugin_dir, $relative_path ) {
	return wp_agent_daemon_lifecycle_read_path(
		$plugin_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path ),
		$relative_path
	);
}

function wp_agent_daemon_lifecycle_require_markers( $file, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_daemon_lifecycle_assert( empty( $missing ), $file . ' is missing required daemon lifecycle markers.', array(
		'missing' => $missing,
	) );

	return count( $markers );
}

function wp_agent_daemon_lifecycle_assert_no_raw_secrets( $file, $text ) {
	foreach ( array(
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
		'/https?:\/\/[^\/\s:@]+:[^\/\s@]+@/i',
	) as $pattern ) {
		wp_agent_daemon_lifecycle_assert( 1 !== preg_match( $pattern, $text ), $file . ' appears to contain a raw secret or credentialed URL.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_daemon_lifecycle_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$implementation_files = array(
	'includes/class-wp-agent-daemon.php' => array(
		'Native PHP agent daemon',
		'@set_time_limit( 0 );',
		'DEFAULT_MAX_CHILDREN',
		'DEFAULT_MEMORY_SOFT',
		'DEFAULT_MEMORY_HARD',
		'DEFAULT_WATCHDOG_STALE',
		'DEFAULT_WATCHDOG_BACKOFF',
		'MAX_WATCHDOG_BACKOFF',
		'max_jobs',
		'max_lifetime',
		'max_idle_time',
		'memory_soft_limit',
		'memory_hard_limit',
		'daemon_token',
		'write_pid_record',
		'pcntl_signal',
		'pcntl_signal_dispatch',
		'stop_requested',
		'maybe_check_schedules',
		'WPAgent_Runs::claimable_count',
		'pcntl_fork',
		'run_child_and_exit',
		'WPAgent_Worker::tick( 1, 20 )',
		'terminate_children',
		'reconnect_db',
		'memory_limit_reached',
		'gc_collect_cycles',
		'memory_hard_limit',
		'watchdog',
		'acquire_watchdog_lock',
		'watchdog_backoff_delay',
		'request_stale_stop',
		'force_stop_pid',
		'pid_cmdline_matches',
		'spawn_background',
		'proc_open',
		'memory_per_job_delta',
		'watchdog_restart_count',
		'watchdog_fallback_recommended',
	),
	'bin/agentd.php' => array(
		'WP Agent daemon must run from PHP CLI.',
		'--max-jobs=',
		'--max-lifetime=',
		'--max-idle-time=',
		'--memory-soft-limit=',
		'--memory-hard-limit=',
		'--daemon-token=',
		'--wp-load=',
		'WPAgent_Daemon::run',
	),
	'includes/class-wp-agent-cli.php' => array(
		'One of status, wake, start, stop, run, watchdog.',
		'--max-jobs=<count>',
		'--max-lifetime=<seconds>',
		'--max-idle-time=<seconds>',
		'--memory-soft-limit=<mb>',
		'--memory-hard-limit=<mb>',
		'WPAgent_Daemon::wake',
		'WPAgent_Daemon::watchdog',
		'WPAgent_Daemon::run',
		'daemon_lifecycle_args',
	),
);

$deterministic_tests = array(
	'tests/daemon-status-heartbeat.php' => array(
		'Fresh heartbeat should report running even when PID cannot be verified.',
		'Stale heartbeat with an unverifiable PID should not report running.',
		'liveness_source',
		'register_shutdown_function',
	),
	'tests/daemon-background-watchdog.php' => array(
		'Daemon wake should start a background process.',
		'Running daemon heartbeat should be fresh.',
		'Watchdog should report a freshly running daemon as healthy.',
		'Watchdog should start a stopped daemon.',
		'Daemon status should retain watchdog restart count.',
		'PID file must not live under the plugin directory.',
		'Daemon runtime files were not removed after cleanup stop',
	),
	'tests/daemon-soak.php' => array(
		'Daemon foreground run should succeed.',
		'Daemon should execute at least one loop tick.',
		'Daemon should stop because max_idle_time was reached.',
		'Daemon should report memory baseline.',
		'Daemon should report memory usage.',
		'Daemon memory peak should be at least current usage.',
		'Daemon should report non-negative memory delta.',
		'PID file should be removed after foreground daemon exits.',
	),
	'tests/daemon-queue-load.php' => array(
		'Daemon should process the queued run count.',
		'Daemon should stop at the max-jobs rotation boundary.',
		'Each queued run should complete.',
		'Completed runs should not retain locks.',
		'Each processed run should record model usage.',
		'Daemon should report per-job memory delta.',
		'Diagnostics should expose max-jobs restart reason.',
		'register_shutdown_function',
	),
	'tests/daemon-rotation-load.php' => array(
		'Each rotation cycle should process max_jobs runs.',
		'Each rotation cycle should stop at the max-jobs boundary.',
		'Each rotation cycle should drain exactly max_jobs pending runs.',
		'Daemon should report per-job memory delta after each cycle.',
		'All created runs should be drained.',
		'Each run should record a claimed event.',
		'Each run should record a done event.',
	),
	'tests/daemon-high-volume.php' => array(
		'Each cycle should process max_jobs runs while work remains.',
		'Each cycle should stop at max-jobs boundary.',
		'Each cycle should drain exactly max_jobs pending runs.',
		'All high-volume runs should be drained.',
		'Expected rotation cycle count should run.',
		'Daemon should report per-job memory delta.',
		'Diagnostics should report no queued runs after drain.',
	),
	'tests/daemon-error-diagnostics.php' => array(
		'Daemon status should retain last error context.',
		'Daemon status should redact api_key values.',
		'Daemon status should redact token values.',
		'Daemon status should redact bearer tokens.',
		'Daemon status last_error should be bounded.',
		'Diagnostics should expose watchdog_restart_count.',
		'Diagnostics should expose watchdog_last_error.',
		'register_shutdown_function',
	),
	'tests/performance-diagnostics.php' => array(
		'OPcache CLI state',
		'JIT buffer size',
		'daemon PID verification state',
		'daemon liveness source',
		'daemon restart count',
		'daemon last error',
		'watchdog last error',
		'runtime root candidate statuses',
	),
);

$live_harnesses = array(
	'tests/live-daemon-soak.php' => array(
		'Live resident daemon soak with real AI/model/tool load.',
		'WP_AGENT_LIVE_DAEMON',
		'Resident daemon must be running before live soak.',
		'Daemon ticks should advance during live soak.',
		'Daemon should report memory usage after live soak.',
		'Live daemon soak should record model usage.',
	),
	'tests/live-editorial-daemon-soak.php' => array(
		'WP_AGENT_LIVE_EDITORIAL_DAEMON',
		'approved API cost budget',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS',
		'heartbeat_max_age',
		'Resident daemon heartbeat became stale during the configured live editorial soak window.',
		'Live editorial daemon memory usage delta exceeded WP_AGENT_LIVE_EDITORIAL_DAEMON_MEMORY_DELTA_MAX',
		'usage-row guard',
		'cost_budget_usd',
		'memory_summary',
	),
);

$documentation_files = array(
	'README.md' => array(
		'Wake a native PHP `agentd` host process from WP-CLI or the Dashboard',
		'The daemon is the host process.',
		'PID/heartbeat',
		'pcntl',
		'single-process loop',
		'For official-container resident daemon acceptance',
		'--max-lifetime=600',
		'--memory-soft-limit=512',
		'--memory-hard-limit=768',
		'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS',
	),
	'goals.md' => array(
		'常驻 PHP 进程稳定性',
		'bounded iteration',
		'gc_collect_cycles()',
		'软/硬内存水位',
		'max-jobs',
		'max-lifetime',
		'max-idle-time',
		'watchdog 必须有退避和锁',
		'OPcache for CLI',
		'PHP CLI daemon 长期常驻并按内存/任务/生命周期阈值优雅轮换',
		'实际多小时 live soak 仍需时间/成本批准',
	),
);

$research_files = array(
	'/path/to/wp-agent/design/research/reactphp-memory-notes.md' => array(
		'ReactPHP',
		'Amp',
		'Workerman',
		'RoadRunner',
		'Laravel Octane',
		'Swoole',
		'Keep soft/hard memory thresholds.',
		'Keep `max_jobs`, `max_lifetime`, `max_idle_time`, soft/hard memory limits, and watchdog restart backoff.',
		'Restart/rotation is the normal memory-management strategy for long-lived PHP.',
		'Multi-hour live daemon soak with real model/tool latency and restart behavior.',
	),
);

$marker_count = 0;
$files_checked = array();
$all_text = '';
foreach ( array(
	'implementation_files' => $implementation_files,
	'deterministic_tests'  => $deterministic_tests,
	'live_harnesses'       => $live_harnesses,
	'documentation_files'  => $documentation_files,
) as $group => $files ) {
	foreach ( $files as $relative_path => $markers ) {
		$text = wp_agent_daemon_lifecycle_read( $plugin_dir, $relative_path );
		wp_agent_daemon_lifecycle_assert_no_raw_secrets( $relative_path, $text );
		$marker_count += wp_agent_daemon_lifecycle_require_markers( $relative_path, $text, $markers );
		$files_checked[ $group ][] = $relative_path;
		$all_text .= "\n" . $text;
	}
}

foreach ( $research_files as $path => $markers ) {
	$text = wp_agent_daemon_lifecycle_read_path( $path, $path );
	wp_agent_daemon_lifecycle_assert_no_raw_secrets( $path, $text );
	$marker_count += wp_agent_daemon_lifecycle_require_markers( $path, $text, $markers );
	$files_checked['research_files'][] = $path;
	$all_text .= "\n" . $text;
}

$coverage = array(
	'native_php_cli_entrypoint' => false !== strpos( $all_text, 'WP Agent daemon must run from PHP CLI.' )
		&& false !== strpos( $all_text, 'php wp-content/plugins/wp-agent/bin/agentd.php' ),
	'bounded_loop'             => false !== strpos( $all_text, 'max_jobs' )
		&& false !== strpos( $all_text, 'max_lifetime' )
		&& false !== strpos( $all_text, 'max_idle_time' ),
	'memory_rotation'          => false !== strpos( $all_text, 'memory_limit_reached' )
		&& false !== strpos( $all_text, 'gc_collect_cycles' )
		&& false !== strpos( $all_text, 'memory_hard_limit' )
		&& false !== strpos( $all_text, 'memory_per_job_delta' ),
	'heartbeat_liveness'       => false !== strpos( $all_text, 'Fresh heartbeat should report running even when PID cannot be verified.' )
		&& false !== strpos( $all_text, 'stale_heartbeat' ),
	'watchdog_recovery'        => false !== strpos( $all_text, 'acquire_watchdog_lock' )
		&& false !== strpos( $all_text, 'watchdog_backoff_delay' )
		&& false !== strpos( $all_text, 'Watchdog should start a stopped daemon.' ),
	'fork_and_fallback'         => false !== strpos( $all_text, 'pcntl_fork' )
		&& false !== strpos( $all_text, 'WPAgent_Worker::tick( 1, 20 )' )
		&& false !== strpos( $all_text, 'single-process loop' ),
	'private_runtime_files'    => false !== strpos( $all_text, 'PID file must not live under the plugin directory.' )
		&& false !== strpos( $all_text, 'runtime root candidate statuses' ),
	'diagnostics'              => false !== strpos( $all_text, 'OPcache CLI state' )
		&& false !== strpos( $all_text, 'JIT buffer size' )
		&& false !== strpos( $all_text, 'daemon restart count' ),
	'live_soak_gated'          => false !== strpos( $all_text, 'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS' )
		&& false !== strpos( $all_text, 'approved API cost budget' )
		&& false !== strpos( $all_text, '实际多小时 live soak 仍需时间/成本批准' ),
);

$missing_coverage = array();
foreach ( $coverage as $name => $ok ) {
	if ( ! $ok ) {
		$missing_coverage[] = $name;
	}
}
wp_agent_daemon_lifecycle_assert( empty( $missing_coverage ), 'Daemon lifecycle coverage map has gaps.', array(
	'missing' => $missing_coverage,
) );

echo json_encode( array(
	'success'                         => true,
	'contract'                        => 'daemon_lifecycle_evidence_contract',
	'implementation_files_checked'    => count( $implementation_files ),
	'deterministic_tests_checked'     => count( $deterministic_tests ),
	'live_harnesses_checked'          => count( $live_harnesses ),
	'documentation_files_checked'     => count( $documentation_files ),
	'research_files_checked'          => count( $research_files ),
	'required_markers'                => $marker_count,
	'coverage'                        => $coverage,
	'live_network_calls'              => false,
	'ai_gateway_calls'                => false,
	'github_calls'                    => false,
	'docker_calls'                    => false,
	'daemon_process_started'          => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
