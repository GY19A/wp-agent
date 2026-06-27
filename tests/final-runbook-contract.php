<?php
/**
 * Host-side final live runbook contract.
 *
 * Verifies README.md and goals.md keep the final live acceptance runbook
 * actionable, bounded, official-container based, and free of raw secrets.
 * This script does not call GitHub, Docker, WordPress, or the AI gateway.
 *
 * Run from the host:
 * php tests/final-runbook-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This final runbook contract must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_runbook_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_runbook_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_runbook_fail( $message, $details );
	}
}

function wp_agent_runbook_require_markers( $document_name, $text, $markers ) {
	$missing = array();
	foreach ( $markers as $marker ) {
		if ( false === strpos( $text, $marker ) ) {
			$missing[] = $marker;
		}
	}

	wp_agent_runbook_assert( empty( $missing ), $document_name . ' is missing required final-runbook markers.', array(
		'missing' => $missing,
	) );
}

function wp_agent_runbook_command_blocks( $text ) {
	$matches = array();
	preg_match_all( '/```[^\n]*\n(.*?)```/s', $text, $matches );
	return $matches[1] ?? array();
}

function wp_agent_runbook_require_strict_preflight_block( $document_name, $text, $markers ) {
	$blocks = wp_agent_runbook_command_blocks( $text );
	$matched = '';
	foreach ( $blocks as $block ) {
		if (
			false !== strpos( $block, 'WP_AGENT_FINAL_PREFLIGHT_STRICT=1' )
			&& false !== strpos( $block, 'wp-content/plugins/wp-agent/tests/final-acceptance-preflight.php' )
		) {
			$matched = $block;
			break;
		}
	}

	wp_agent_runbook_assert( '' !== $matched, $document_name . ' is missing a strict final preflight command block.', array(
		'required' => 'WP_AGENT_FINAL_PREFLIGHT_STRICT=1 with final-acceptance-preflight.php',
	) );
	wp_agent_runbook_require_markers( $document_name . ' strict final preflight command block', $matched, $markers );
	return $matched;
}

function wp_agent_runbook_require_unique_env_flags( $document_name, $command_block ) {
	$matches = array();
	preg_match_all( '/(?:^|\s)-e\s+([A-Z0-9_]+)(?:=[^\s\\\\]+)?/m', $command_block, $matches );
	$env_flags = $matches[1] ?? array();
	$counts    = array_count_values( $env_flags );
	$duplicates = array();
	foreach ( $counts as $name => $count ) {
		if ( $count > 1 ) {
			$duplicates[ $name ] = $count;
		}
	}

	wp_agent_runbook_assert( empty( $duplicates ), $document_name . ' contains duplicate strict preflight environment flags.', array(
		'duplicates' => $duplicates,
	) );

	return count( $env_flags );
}

function wp_agent_runbook_require_regression_block( $document_name, $text ) {
	$blocks = wp_agent_runbook_command_blocks( $text );
	$matched = '';
	foreach ( $blocks as $block ) {
		if (
			false !== strpos( $block, 'php tests/final-no-live-acceptance-contract.php' )
			&& false !== strpos( $block, 'php tests/final-runbook-contract.php' )
			&& false !== strpos( $block, 'php tests/final-live-completion-gate-contract.php' )
		) {
			$matched = $block;
			break;
		}
	}

	wp_agent_runbook_assert( '' !== $matched, $document_name . ' is missing a final regression command block.', array(
		'required' => 'final-no-live, final-runbook, and final-live-completion-gate commands',
	) );

	return $matched;
}

function wp_agent_runbook_require_unique_regression_commands( $document_name, $command_block ) {
	$matches = array();
	preg_match_all( '/^php tests\/[A-Za-z0-9_.-]+\.php$/m', $command_block, $matches );
	$commands = $matches[0] ?? array();
	$counts   = array_count_values( $commands );
	$duplicates = array();
	foreach ( $counts as $command => $count ) {
		if ( $count > 1 ) {
			$duplicates[ $command ] = $count;
		}
	}

	wp_agent_runbook_assert( empty( $duplicates ), $document_name . ' contains duplicate final regression commands.', array(
		'duplicates' => $duplicates,
	) );

	return count( $commands );
}

function wp_agent_runbook_assert_no_raw_secrets( $document_name, $text ) {
	$secret_patterns = array(
		'/\b(?:WP_AGENT_LIVE_GITHUB_TOKEN|GITHUB_TOKEN|GH_TOKEN)\s*=\s*["\']?[^"\'\s`\\\\]+/m',
		'/\b(?:OPENAI_API_KEY|MEOWL_API_KEY|WP_AGENT_MEOWL_API_KEY)\s*=\s*["\']?[^"\'\s`\\\\]+/m',
		'/sk-(?:proj-)?[A-Za-z0-9_-]{12,}/',
		'/gh[pousr]_[A-Za-z0-9_]{20,}/',
	);

	foreach ( $secret_patterns as $pattern ) {
		wp_agent_runbook_assert( 1 !== preg_match( $pattern, $text ), $document_name . ' appears to contain an inline secret assignment or token.', array(
			'pattern' => $pattern,
		) );
	}
}

$plugin_dir = realpath( dirname( __DIR__ ) );
wp_agent_runbook_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );

$documents = array(
	'README.md' => $plugin_dir . '/README.md',
	'goals.md' => $plugin_dir . '/goals.md',
);

$texts = array();
foreach ( $documents as $name => $path ) {
	wp_agent_runbook_assert( is_file( $path ), $name . ' is missing.' );
	$text = file_get_contents( $path );
	wp_agent_runbook_assert( is_string( $text ) && '' !== $text, $name . ' could not be read.' );
	wp_agent_runbook_assert_no_raw_secrets( $name, $text );
	$texts[ $name ] = $text;
}

$common_markers = array(
	'php tests/final-no-live-acceptance-contract.php',
	'tests/final-acceptance-preflight.php',
	'WP_AGENT_FINAL_PREFLIGHT_STRICT=1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE=all',
	'php tests/final-live-command-plan-contract.php',
	'php tests/final-live-command-plan.php',
	'commands_executable=false',
	'php tests/final-live-report-artifact-contract.php',
	'php tests/final-live-artifact-manifest-build-contract.php',
	'php tests/final-live-artifact-manifest-build.php',
	'WP_AGENT_FINAL_LIVE_MANIFEST_WRITE=1',
	'php tests/final-live-artifact-manifest-contract.php',
	'php tests/final-live-artifact-manifest-fixture-contract.php',
	'php tests/final-live-archive-redaction-contract.php',
	'php tests/final-live-archive-redaction-fixture-contract.php',
	'php tests/final-live-completion-gate-contract.php',
	'php tests/final-live-completion-gate-fixture-contract.php',
	'tests/final-live-report-template.md',
	'tests/final-live-artifact-manifest-template.json',
	'final-live-github-skill-store-YYYYMMDD.json',
	'final-live-editorial-daemon-soak-YYYYMMDD.json',
	'final-live-artifact-manifest-YYYYMMDD.json',
	'completion_ready',
	'docker compose -p wp-agent-official -f docker-compose.official.yml --profile cli run --rm -T',
	'wp-content/plugins/wp-agent/tests/final-acceptance-preflight.php',
	'WP_AGENT_LIVE_GITHUB_SKILLS=1',
	'WP_AGENT_LIVE_GITHUB_REPOSITORY',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
	'WP_AGENT_LIVE_GITHUB_REF',
	'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
	'owner/repo',
	'skills/example',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON=1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
	'docker exec -u www-data -d wp-agent-official-wordpress-1',
	'php /var/www/html/wp-content/plugins/wp-agent/bin/agentd.php',
	'--wp-load=/var/www/html/wp-load.php',
	'--max-lifetime',
	'--memory-soft-limit',
	'--memory-hard-limit',
	'wp wp-agent daemon stop',
	'28800',
);

foreach ( $texts as $document_name => $text ) {
	wp_agent_runbook_require_markers( $document_name, $text, $common_markers );
}

$strict_preflight_markers = array(
	'WP_AGENT_FINAL_PREFLIGHT_STRICT=1',
	'WP_AGENT_FINAL_PREFLIGHT_SCOPE=all',
	'WP_AGENT_LIVE_GITHUB_SKILLS=1',
	'WP_AGENT_LIVE_GITHUB_REPOSITORY',
	'WP_AGENT_LIVE_GITHUB_SKILL_PATH',
	'WP_AGENT_LIVE_GITHUB_REF',
	'WP_AGENT_LIVE_GITHUB_REVIEW_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON=1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVED=1',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_APPROVAL_PHRASE=approve-multi-hour-soak',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_COST_BUDGET_USD',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_ARTIFACT_POLICY',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_RUNS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_TIMEOUT',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOAK_SECONDS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SAMPLE_INTERVAL',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_MAX_USAGE_ROWS',
	'WP_AGENT_LIVE_EDITORIAL_DAEMON_SOURCE_URL',
	'WP_AGENT_OFFICIAL_DB_DIR',
	'wp-content/plugins/wp-agent/tests/final-acceptance-preflight.php',
);
$strict_preflight_env_flags_checked = 0;
foreach ( $texts as $document_name => $text ) {
	$strict_block = wp_agent_runbook_require_strict_preflight_block( $document_name, $text, $strict_preflight_markers );
	$strict_preflight_env_flags_checked += wp_agent_runbook_require_unique_env_flags( $document_name . ' strict final preflight command block', $strict_block );
}

$regression_commands_checked = 0;
foreach ( $texts as $document_name => $text ) {
	$regression_block = wp_agent_runbook_require_regression_block( $document_name, $text );
	$regression_commands_checked += wp_agent_runbook_require_unique_regression_commands( $document_name . ' final regression command block', $regression_block );
}

wp_agent_runbook_require_markers( 'README.md', $texts['README.md'], array(
	'These checks use the configured gateway and may incur API cost',
	'Replace `owner/repo` and `skills/example` with official Skill Store coordinates',
	'official WordPress container',
	'sidecar-free',
) );

wp_agent_runbook_require_markers( 'goals.md', $texts['goals.md'], array(
	'不得把这些步骤伪装成已完成',
	'模板中的 `owner/repo` 和 `skills/example` 只是占位示例',
	'WP_AGENT_LIVE_GITHUB_TOKEN',
	'不得写入日志或 lockfile',
	'WP_AGENT_FINAL_PREFLIGHT_ALLOW_NONDEFAULT_DB_DIR=1',
	'/path/to/wp-agent/database/official-mysql',
) );

echo json_encode( array(
	'success'            => true,
	'contract'           => 'final_runbook_contract',
	'documents_checked'  => count( $documents ),
	'required_markers'   => count( $common_markers ),
	'strict_preflight_markers' => count( $strict_preflight_markers ),
	'strict_preflight_env_flags_checked' => $strict_preflight_env_flags_checked,
	'duplicate_env_flags' => false,
	'regression_commands_checked' => $regression_commands_checked,
	'duplicate_regression_commands' => false,
	'secret_assignments' => false,
	'live_network_calls' => false,
	'ai_gateway_calls'   => false,
	'github_calls'       => false,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
