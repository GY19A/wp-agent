<?php
/**
 * Build guarded skill.php wrappers for built-in Skill templates.
 *
 * The plugin ships built-in Skill prompts as guarded PHP files
 * (includes/data/skills/<slug>/skill.php) instead of raw SKILL.md, so the
 * prompts cannot be downloaded directly over HTTP on any web server. The
 * editable source of truth for these prompts lives in the public Skill Store
 * repository (one SKILL.md per skill).
 *
 * Usage:
 *   # From SKILL.md files placed next to each skill.php (e.g. synced from the
 *   # store repo), regenerate the guarded wrappers:
 *   php bin/build-skill-php.php [path-to-store-skills-dir]
 *
 * If a source dir is given, each <slug>/SKILL.md under it is wrapped into
 * includes/data/skills/<slug>/skill.php. Otherwise any SKILL.md found next to
 * an existing skill.php (or skill dir) under includes/data/skills is wrapped.
 */

$plugin_dir   = dirname( __DIR__ );
$target_base  = $plugin_dir . '/includes/data/skills';
$source_base  = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : $target_base;

if ( ! is_dir( $source_base ) ) {
    fwrite( STDERR, "Source skills dir not found: {$source_base}\n" );
    exit( 1 );
}

// Only the plugin's built-in templates are shipped as guarded skill.php files.
// Other skills in the source (e.g. store-only skills) are ignored.
$built_in = array(
    'news-site-operator',
    'image-to-article',
    'title-to-article',
    'research-article',
    'paper-to-article',
    'expand-categories',
    'skill-creator',
);

$count = 0;
foreach ( $built_in as $slug ) {
    $dir = $source_base . '/' . $slug;
    $md  = $dir . '/SKILL.md';
    if ( ! is_file( $md ) ) {
        fwrite( STDERR, "Skipped {$slug}: SKILL.md not found in source.\n" );
        continue;
    }
    $content = (string) file_get_contents( $md );
    if ( '' === trim( $content ) ) {
        continue;
    }

    $target_dir = $target_base . '/' . $slug;
    if ( ! is_dir( $target_dir ) ) {
        mkdir( $target_dir, 0755, true );
    }

    $php = "<?php\n"
        . "/**\n"
        . " * Built-in Skill template: {$slug}\n"
        . " *\n"
        . " * Guarded PHP wrapper around the SKILL.md prompt so the built-in\n"
        . " * template cannot be downloaded directly over HTTP on any web server.\n"
        . " * A direct request executes PHP and returns nothing; the plugin loads\n"
        . " * the prompt via include from the filesystem. The body is the verbatim\n"
        . " * SKILL.md content (base64 to preserve it exactly). Editable source of\n"
        . " * truth lives in the public Skill Store repo. Regenerate with\n"
        . " * bin/build-skill-php.php.\n"
        . " */\n\n"
        . "if ( ! defined( 'ABSPATH' ) ) { exit; }\n\n"
        . "return base64_decode( '" . base64_encode( $content ) . "' );\n";

    file_put_contents( $target_dir . '/skill.php', $php );
    $count++;
}

fwrite( STDERR, "Wrote skill.php for {$count} built-in templates into {$target_base}\n" );
