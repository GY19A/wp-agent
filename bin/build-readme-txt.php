<?php
/**
 * Generate WordPress-compliant readme.txt from README.md.
 *
 * README.md is the human-facing GitHub document (images, badges, Mermaid). It
 * embeds machine-readable markers so this script can deterministically extract
 * the WordPress.org readme.txt fields and sections:
 *
 *   <!-- wporg:meta ... /wporg:meta -->          header fields (key: value)
 *   <!-- wporg:description --> ... <!-- /wporg:description -->
 *   <!-- wporg:section:Title --> ... <!-- /wporg:section -->   (== Title ==)
 *   <!-- wporg:faq --> ... <!-- /wporg:faq -->
 *   <!-- wporg:changelog --> ... <!-- /wporg:changelog -->
 *   <!-- wporg:upgrade_notice --> ... <!-- /wporg:upgrade_notice -->
 *
 * Markdown is converted to readme.txt conventions:
 *   - "## X" / "### X" headings inside FAQ/Changelog become "= X =".
 *   - Fenced code blocks become indented `code` lines (back-tick wrapped lines
 *     are left as-is; multi-line blocks are converted to inline backticks per
 *     WordPress readme style where short, otherwise kept as plain lines).
 *   - Images and HTML blocks are stripped from the description.
 *
 * Usage:
 *   php bin/build-readme-txt.php [README.md] [readme.txt]
 */

$plugin_dir = dirname( __DIR__ );
$src  = isset( $argv[1] ) ? $argv[1] : $plugin_dir . '/README.md';
$dest = isset( $argv[2] ) ? $argv[2] : $plugin_dir . '/readme.txt';

if ( ! is_file( $src ) ) {
    fwrite( STDERR, "Source not found: {$src}\n" );
    exit( 1 );
}

$md = file_get_contents( $src );

/** Extract a single block delimited by <!-- wporg:NAME --> ... <!-- /wporg:NAME --> */
function wpa_block( $md, $name ) {
    $pattern = '/<!--\s*wporg:' . preg_quote( $name, '/' ) . '\s*-->(.*?)<!--\s*\/wporg:' . preg_quote( $name, '/' ) . '\s*-->/s';
    if ( preg_match( $pattern, $md, $m ) ) {
        return trim( $m[1] );
    }
    return '';
}

/** Extract a named section block: <!-- wporg:section:Title --> ... <!-- /wporg:section --> */
function wpa_sections( $md ) {
    $out = array();
    if ( preg_match_all( '/<!--\s*wporg:section:(.+?)\s*-->(.*?)<!--\s*\/wporg:section\s*-->/s', $md, $matches, PREG_SET_ORDER ) ) {
        foreach ( $matches as $m ) {
            $out[] = array( 'title' => trim( $m[1] ), 'body' => trim( $m[2] ) );
        }
    }
    return $out;
}

/** Parse the wporg:meta key: value header block. */
function wpa_meta( $md ) {
    // The meta block lives inside a single HTML comment:
    //   <!--\n wporg:meta\n key: value\n ... \n /wporg:meta\n -->
    $raw = '';
    if ( preg_match( '/wporg:meta\s*(.*?)\s*\/wporg:meta/s', $md, $m ) ) {
        $raw = $m[1];
    }
    $meta = array();
    foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
        if ( preg_match( '/^([a-z_]+):\s*(.*)$/i', trim( $line ), $m ) ) {
            $meta[ strtolower( $m[1] ) ] = trim( $m[2] );
        }
    }
    return $meta;
}

/**
 * Convert a Markdown body to readme.txt body conventions.
 *
 * - Drop the leading "## Heading" (the section title is emitted separately).
 * - Convert remaining "## X" / "### X" to "= X =".
 * - Strip HTML blocks (e.g. <p align>...</p>, <img ...>) and Mermaid/code fences
 *   in description context; keep fenced shell/code as indented or inline code.
 * - Collapse 3+ blank lines to a single blank line.
 *
 * @param string $body
 * @param bool   $drop_first_heading
 * @return string
 */
function wpa_body_to_txt( $body, $drop_first_heading = true ) {
    // Remove HTML comment markers if any slipped in.
    $body = preg_replace( '/<!--.*?-->/s', '', $body );

    // Strip block-level HTML (centered images, badges, etc.).
    $body = preg_replace( '/<p[^>]*>.*?<\/p>/s', '', $body );
    $body = preg_replace( '/<img[^>]*>/s', '', $body );
    $body = preg_replace( '/<h\d[^>]*>.*?<\/h\d>/s', '', $body );

    // Convert fenced code blocks to indented lines (WordPress shows monospace
    // for lines that are wrapped in backticks; we convert short single-command
    // blocks to inline `code`, and keep mermaid/diagram blocks out).
    $body = preg_replace_callback( '/```([a-zA-Z]*)\n(.*?)```/s', function ( $m ) {
        $lang = strtolower( trim( $m[1] ) );
        $code = rtrim( $m[2] );
        if ( 'mermaid' === $lang ) {
            return ''; // diagrams are GitHub-only
        }
        $lines = preg_split( '/\r?\n/', $code );
        if ( count( $lines ) === 1 ) {
            return '`' . trim( $lines[0] ) . '`';
        }
        // Multi-line: wrap each non-empty line in backticks (readme renders monospace).
        $out = array();
        foreach ( $lines as $l ) {
            $out[] = ( '' === trim( $l ) ) ? '' : '`' . $l . '`';
        }
        return implode( "\n", $out );
    }, $body );

    $lines = preg_split( '/\r?\n/', $body );
    $result = array();
    $seen_heading = false;
    foreach ( $lines as $line ) {
        if ( preg_match( '/^(#{2,4})\s+(.*)$/', $line, $m ) ) {
            if ( $drop_first_heading && ! $seen_heading ) {
                $seen_heading = true;
                continue; // skip the section's own H2 title
            }
            $seen_heading = true;
            $result[] = '= ' . trim( $m[2] ) . ' =';
            continue;
        }
        $seen_heading = true; // any non-heading content counts
        $result[] = $line;
    }

    $txt = implode( "\n", $result );
    $txt = preg_replace( "/\n{3,}/", "\n\n", $txt );
    return trim( $txt );
}

$meta     = wpa_meta( $md );
$desc     = wpa_body_to_txt( wpa_block( $md, 'description' ), true );
$faq      = wpa_body_to_txt( wpa_block( $md, 'faq' ), true );
$changelog = wpa_body_to_txt( wpa_block( $md, 'changelog' ), true );
$upgrade  = wpa_body_to_txt( wpa_block( $md, 'upgrade_notice' ), true );
$sections = wpa_sections( $md );

// Validate required meta.
$required = array( 'contributors', 'tags', 'requires_at_least', 'tested_up_to', 'requires_php', 'stable_tag', 'license', 'license_uri', 'short_description' );
$missing  = array();
foreach ( $required as $key ) {
    if ( empty( $meta[ $key ] ) ) {
        $missing[] = $key;
    }
}
if ( $missing ) {
    fwrite( STDERR, "Missing required wporg:meta fields: " . implode( ', ', $missing ) . "\n" );
    exit( 1 );
}
if ( '' === $desc ) {
    fwrite( STDERR, "Missing wporg:description block.\n" );
    exit( 1 );
}

// WordPress.org limits the short description to 150 chars.
$short = $meta['short_description'];
if ( strlen( $short ) > 150 ) {
    $short = rtrim( substr( $short, 0, 147 ) ) . '...';
}

$plugin_name = 'WP Agent';

// Build readme.txt.
$out  = "=== {$plugin_name} ===\n";
$out .= "Contributors: {$meta['contributors']}\n";
$out .= "Tags: {$meta['tags']}\n";
$out .= "Requires at least: {$meta['requires_at_least']}\n";
$out .= "Tested up to: {$meta['tested_up_to']}\n";
$out .= "Requires PHP: {$meta['requires_php']}\n";
$out .= "Stable tag: {$meta['stable_tag']}\n";
$out .= "License: {$meta['license']}\n";
$out .= "License URI: {$meta['license_uri']}\n\n";
$out .= $short . "\n\n";

$out .= "== Description ==\n\n" . $desc . "\n\n";

// Ordered custom sections (Background Worker, External Services, Installation, ...).
foreach ( $sections as $section ) {
    $out .= '== ' . $section['title'] . " ==\n\n" . wpa_body_to_txt( $section['body'], true ) . "\n\n";
}

if ( '' !== $faq ) {
    $out .= "== Frequently Asked Questions ==\n\n" . $faq . "\n\n";
}

// Screenshots (static; describe the admin surfaces).
$out .= "== Screenshots ==\n\n";
$out .= "1. Full-page WP Agent Chat.\n";
$out .= "2. Settings page with AI provider, model token limits, mode, channels, moderation, syndication, and MCP options.\n";
$out .= "3. Scheduled Tasks page.\n";
$out .= "4. Usage and audit views.\n\n";

if ( '' !== $changelog ) {
    $out .= "== Changelog ==\n\n" . $changelog . "\n\n";
}

if ( '' !== $upgrade ) {
    $out .= "== Upgrade Notice ==\n\n" . $upgrade . "\n";
}

file_put_contents( $dest, rtrim( $out ) . "\n" );
fwrite( STDERR, "Wrote {$dest} (stable tag {$meta['stable_tag']}).\n" );
