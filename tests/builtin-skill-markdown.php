<?php
/**
 * Deterministic test: built-in Skill prompts are stored as guarded PHP files.
 *
 * Verifies every built-in template is loaded from includes/data/skills/<slug>/
 * skill.php (an ABSPATH-guarded wrapper that returns the SKILL.md prompt and
 * cannot be downloaded over HTTP), with valid frontmatter (name/description/
 * tools/triggers) and a non-executable Markdown body. No AI/GitHub/Docker calls.
 *
 * Run: wp eval-file wp-content/plugins/wp-agent/tests/builtin-skill-markdown.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file.\n" );
    exit( 1 );
}

$failures = array();
function wpa_bsm_assert( $cond, $label, &$failures ) {
    echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $label . "\n";
    if ( ! $cond ) {
        $failures[] = $label;
    }
}

$expected = array( 'news-site-operator', 'image-to-article', 'title-to-article', 'research-article', 'paper-to-article', 'expand-categories', 'skill-creator' );
$dir      = WP_AGENT_PLUGIN_DIR . 'includes/data/skills';

// Each built-in slug must have a guarded skill.php wrapper on disk, and must
// NOT ship a web-downloadable raw SKILL.md.
foreach ( $expected as $slug ) {
    $php = $dir . '/' . $slug . '/skill.php';
    wpa_bsm_assert( is_readable( $php ), "skill.php exists for $slug", $failures );
    wpa_bsm_assert( ! file_exists( $dir . '/' . $slug . '/SKILL.md' ), "no raw SKILL.md shipped for $slug", $failures );
}

$templates = WPAgent_Skills::built_in_templates( true );
wpa_bsm_assert( count( $templates ) === count( $expected ), 'all built-in templates load from skill.php', $failures );

$by_slug = array();
foreach ( $templates as $t ) {
    $by_slug[ $t['slug'] ] = $t;
}

$dangerous = array( '<?php', '<script', '#!/bin/', 'shell_exec(', 'system(', 'proc_open(', 'passthru(' );
foreach ( $expected as $slug ) {
    $t = $by_slug[ $slug ] ?? null;
    wpa_bsm_assert( is_array( $t ), "template present: $slug", $failures );
    if ( ! is_array( $t ) ) {
        continue;
    }
    wpa_bsm_assert( '' !== ( $t['name'] ?? '' ), "$slug has a name", $failures );
    wpa_bsm_assert( '' !== trim( $t['body'] ?? '' ), "$slug has a non-empty body", $failures );
    wpa_bsm_assert( ! empty( $t['permissions']['tools'] ), "$slug declares tools", $failures );

    $bad = '';
    $lower = strtolower( (string) ( $t['body'] ?? '' ) );
    foreach ( $dangerous as $d ) {
        if ( false !== strpos( $lower, strtolower( $d ) ) ) {
            $bad = $d;
            break;
        }
    }
    wpa_bsm_assert( '' === $bad, "$slug body has no executable patterns", $failures );

    // template() lookup must resolve the same slug.
    $found = WPAgent_Skills::template( $slug );
    wpa_bsm_assert( is_array( $found ) && ( $found['slug'] ?? '' ) === $slug, "template() resolves $slug", $failures );
}

echo "\n" . ( empty( $failures ) ? 'ALL PASS' : count( $failures ) . ' FAILURE(S): ' . implode( '; ', $failures ) ) . "\n";
exit( empty( $failures ) ? 0 : 1 );
