<?php
/**
 * One-off generator: parse QuantumNous/new-api model ratio tables and emit a
 * full per-million-token USD price table for WP Agent's cost tracker.
 *
 * Usage: php bin/gen-pricing.php /path/to/new-api/setting/ratio_setting/model_ratio.go
 *
 * Conversion: input$/1M = modelRatio * 2 ; output$/1M = input$ * completionRatio
 */

if ( $argc < 2 ) {
    fwrite( STDERR, "Usage: php gen-pricing.php <model_ratio.go>\n" );
    exit( 1 );
}

$src = file_get_contents( $argv[1] );
if ( false === $src ) {
    fwrite( STDERR, "Cannot read source.\n" );
    exit( 1 );
}

/** Extract a `name: number` map between a var declaration and its closing brace. */
function extract_map( $src, $var ) {
    if ( ! preg_match( '/var\s+' . preg_quote( $var, '/' ) . '\s*=\s*map\[string\]float64\{(.*?)\n\}/s', $src, $m ) ) {
        return array();
    }
    $body = $m[1];
    $out  = array();
    foreach ( preg_split( '/\r?\n/', $body ) as $line ) {
        // Strip line comments.
        $line = preg_replace( '#//.*$#', '', $line );
        if ( ! preg_match( '/"([^"]+)"\s*:\s*([0-9]+(?:\.[0-9]+)?)/', $line, $mm ) ) {
            continue;
        }
        $out[ $mm[1] ] = (float) $mm[2];
    }
    return $out;
}

$model_ratio      = extract_map( $src, 'defaultModelRatio' );
$model_price      = extract_map( $src, 'defaultModelPrice' );       // per-call models to exclude
$completion_ratio = extract_map( $src, 'defaultCompletionRatio' );  // explicit overrides

// Prompt-cache discount ratios live in a sibling file (cache_ratio.go).
$cache_src   = file_get_contents( dirname( $argv[1] ) . '/cache_ratio.go' );
$cache_ratio = $cache_src ? extract_map( $cache_src, 'defaultCacheRatio' ) : array();

/**
 * Replicate new-api getHardcodedCompletionModelRatio() prefix logic so models
 * without an explicit completion ratio still get the correct output multiplier.
 */
function hardcoded_completion_ratio( $name ) {
    $has = fn( $p ) => 0 === strpos( $name, $p );
    $ends = fn( $s ) => $s === substr( $name, -strlen( $s ) );
    $contains = fn( $s ) => false !== strpos( $name, $s );

    if ( $ends( '-all' ) || $ends( '-gizmo-*' ) ) return 2.0;

    if ( $has( 'gpt-' ) ) {
        if ( $has( 'gpt-oss-' ) ) return 1.0;
        if ( $has( 'gpt-4o' ) ) {
            if ( 'gpt-4o-2024-05-13' === $name ) return 3.0;
            if ( $has( 'gpt-4o-mini-tts' ) ) return 20.0;
            return 4.0;
        }
        if ( $has( 'gpt-5' ) ) {
            if ( $has( 'gpt-5.5' ) ) return 6.0;
            if ( $has( 'gpt-5.4' ) ) {
                if ( $has( 'gpt-5.4-nano' ) ) return 6.25;
                return 6.0;
            }
            return 8.0;
        }
        if ( $has( 'gpt-4.5-preview' ) ) return 2.0;
        if ( $has( 'gpt-4-turbo' ) || $ends( 'gpt-4-1106' ) || $ends( 'gpt-4-1105' ) ) return 3.0;
        return 2.0;
    }
    if ( $has( 'o1' ) || $has( 'o3' ) ) return 4.0;
    if ( 'chatgpt-4o-latest' === $name ) return 3.0;
    if ( $contains( 'claude-3' ) ) return 5.0;
    if ( $contains( 'claude-sonnet-4' ) || $contains( 'claude-opus-4' ) || $contains( 'claude-haiku-4' ) ) return 5.0;
    if ( $has( 'gpt-3.5' ) ) {
        if ( 'gpt-3.5-turbo' === $name || $ends( '0125' ) ) return 3.0;
        if ( $ends( '1106' ) ) return 2.0;
        return 4.0 / 3.0;
    }
    if ( $has( 'mistral-' ) ) return 3.0;
    if ( $has( 'gemini-' ) ) {
        if ( $has( 'gemini-1.5' ) ) return 4.0;
        if ( $has( 'gemini-2.0' ) ) return 4.0;
        if ( $has( 'gemini-3.5-flash' ) ) return 6.0;
        if ( $has( 'gemini-3.1-flash-image' ) ) return 120.0;
        if ( $has( 'gemini-3.1-flash-lite' ) ) return 6.0;
        if ( $has( 'gemini-3.1-pro' ) ) return $ends( '-high' ) ? 4.5 : 6.0;
        if ( $has( 'gemini-2.5-pro' ) ) return 8.0;
        if ( $has( 'gemini-2.5-flash' ) ) {
            if ( $has( 'gemini-2.5-flash-preview' ) ) return $ends( '-nothinking' ) ? 4.0 : ( 3.5 / 0.15 );
            if ( $has( 'gemini-2.5-flash-lite' ) ) return 4.0;
            return 2.5 / 0.3;
        }
        if ( $has( 'gemini-robotics-er-1.5' ) ) return 2.5 / 0.3;
        if ( $has( 'gemini-3-pro' ) ) return $has( 'gemini-3-pro-image' ) ? 60.0 : 6.0;
        if ( $has( 'gemini-3-flash' ) ) return 6.0;
        return 4.0;
    }
    if ( $has( 'command' ) ) {
        switch ( $name ) {
            case 'command-r': return 3.0;
            case 'command-r-plus': return 5.0;
            case 'command-r-08-2024': return 4.0;
            case 'command-r-plus-08-2024': return 4.0;
            default: return 4.0;
        }
    }
    if ( $has( 'ERNIE-Speed-' ) || $has( 'ERNIE-Lite-' ) || $has( 'ERNIE-Character' ) || $has( 'ERNIE-Functions' ) ) return 2.0;
    switch ( $name ) {
        case 'llama2-70b-4096': return 0.8 / 0.64;
        case 'llama3-8b-8192': return 2.0;
        case 'llama3-70b-8192': return 0.79 / 0.59;
    }
    return 1.0;
}

// Models priced per-call (image/tts/video/music) are not token-billed — skip them.
$skip_prefixes = array( 'mj_', 'suno_', 'dall-e', 'imagen', 'veo-', 'sora-', 'tts-', 'whisper', 'omni-moderation', 'text-embedding', 'text-moderation', 'babbage', 'davinci', 'text-ada', 'text-babbage', 'text-curie', 'text-davinci', 'code-davinci', 'gpt-4o-mini-tts', 'black-forest' );

$rows = array();
foreach ( $model_ratio as $name => $ratio ) {
    foreach ( $skip_prefixes as $sp ) {
        if ( 0 === strpos( $name, $sp ) ) { continue 2; }
    }
    if ( isset( $model_price[ $name ] ) ) { continue; } // per-call
    if ( false !== strpos( $name, '*' ) ) { continue; } // wildcard reserved

    $input  = round( $ratio * 2.0, 4 );
    $comp   = $completion_ratio[ $name ] ?? hardcoded_completion_ratio( $name );
    $output = round( $input * $comp, 4 );
    $rows[ strtolower( $name ) ] = array( 'input' => $input, 'output' => $output );
}

/**
 * Add common bare/undated aliases for Anthropic Claude and Google Gemini, whose
 * gateway IDs are frequently used without the date suffix. Prices are derived
 * from new-api's own completion-ratio prefix logic so they stay consistent.
 *   input$ = modelRatio * 2 ; output$ = input$ * hardcoded_completion_ratio()
 */
$alias_ratios = array(
    // Anthropic Claude (modelRatio in new-api units; *2 = input $/1M).
    'claude-3-haiku'      => 0.125,  // $0.25 in
    'claude-3-5-haiku'    => 0.5,    // $1 in
    'claude-3-5-sonnet'   => 1.5,    // $3 in
    'claude-3-7-sonnet'   => 1.5,    // $3 in
    'claude-3-sonnet'     => 1.5,    // $3 in
    'claude-3-opus'       => 7.5,    // $15 in
    'claude-sonnet-4'     => 1.5,    // $3 in
    'claude-sonnet-4-5'   => 1.5,    // $3 in
    'claude-haiku-4-5'    => 0.5,    // $1 in
    'claude-opus-4'       => 7.5,    // $15 in
    'claude-opus-4-1'     => 7.5,    // $15 in
    // Google Gemini.
    'gemini-1.5-flash'    => 0.075,  // $0.15 in
    'gemini-1.5-pro'      => 1.25,   // $2.5 in
    'gemini-2.0-flash'    => 0.05,   // $0.1 in
    'gemini-2.5-flash'    => 0.15,   // $0.3 in
    'gemini-2.5-pro'      => 0.625,  // $1.25 in
);
foreach ( $alias_ratios as $alias => $ratio ) {
    if ( isset( $rows[ $alias ] ) ) { continue; }
    $input  = round( $ratio * 2.0, 4 );
    $output = round( $input * hardcoded_completion_ratio( $alias ), 4 );
    $rows[ $alias ] = array( 'input' => $input, 'output' => $output );
}

/**
 * Newer models with explicit input/output prices not present (or outdated) in
 * new-api's ratio table. Prices are USD per 1M tokens from the vendor.
 */
$explicit_prices = array(
    // xAI Grok (current model line, in $ / out $ per 1M tokens).
    'grok-4'            => array( 'input' => 3.00, 'output' => 15.00 ),
    'grok-4-fast'       => array( 'input' => 0.20, 'output' => 0.50 ),
    'grok-3'            => array( 'input' => 3.00, 'output' => 15.00 ),
    'grok-3-mini'       => array( 'input' => 0.30, 'output' => 0.50 ),
    'grok-code-fast-1'  => array( 'input' => 0.20, 'output' => 1.50 ),
    'grok-code-fast'    => array( 'input' => 0.20, 'output' => 1.50 ),
);
foreach ( $explicit_prices as $name => $price ) {
    if ( isset( $rows[ $name ] ) ) { continue; }
    $rows[ $name ] = $price;
}

// Longest key first so prefix matching prefers the most specific model.
uksort( $rows, fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

// Normalize cache ratios to lowercase keys and add a few common bare aliases.
$cache_ratios = array();
foreach ( $cache_ratio as $name => $ratio ) {
    $cache_ratios[ strtolower( $name ) ] = $ratio;
}
$cache_alias = array(
    'gpt-5' => 0.1, 'gpt-5.4' => 0.1, 'gpt-5.5' => 0.1, 'gpt-4.1' => 0.25,
    'gpt-4o' => 0.5, 'o1' => 0.5, 'o3-mini' => 0.5,
    'claude-3' => 0.1, 'claude-sonnet-4' => 0.1, 'claude-opus-4' => 0.1, 'claude-haiku-4' => 0.1,
    'deepseek-chat' => 0.25, 'deepseek-reasoner' => 0.25, 'deepseek-coder' => 0.25,
);
foreach ( $cache_alias as $k => $v ) {
    if ( ! isset( $cache_ratios[ $k ] ) ) {
        $cache_ratios[ $k ] = $v;
    }
}
uksort( $cache_ratios, fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

$payload = array(
    'source'    => 'QuantumNous/new-api setting/ratio_setting/model_ratio.go + cache_ratio.go',
    'generated' => gmdate( 'c' ),
    'note'      => 'USD per 1M tokens. input$=modelRatio*2; output$=input$*completionRatio. cache_ratios = cached-input discount multiplier (cached_tokens billed at input$*ratio). Update by editing this file or re-running bin/gen-pricing.php.',
    'fallback'  => array( 'input' => 3.0, 'output' => 15.0 ),
    'models'    => $rows,
    'cache_ratios' => $cache_ratios,
);

$out_dir  = dirname( __DIR__ ) . '/includes/data';
if ( ! is_dir( $out_dir ) ) {
    mkdir( $out_dir, 0755, true );
}

// Ship the data as a guarded PHP file (model-pricing.php) so it cannot be
// downloaded directly over HTTP on any web server (Apache, nginx, Caddy, ...).
// A direct request executes PHP and returns nothing; the plugin loads it via
// include from the filesystem.
$php_file = $out_dir . '/model-pricing.php';
$php_out  = "<?php\n"
    . "/**\n"
    . " * Model pricing data (USD per 1M tokens).\n"
    . " *\n"
    . " * Guarded PHP data file so it cannot be downloaded over HTTP on any web\n"
    . " * server. Auto-generated by bin/gen-pricing.php — do not edit by hand.\n"
    . " */\n\n"
    . "if ( ! defined( 'ABSPATH' ) ) { exit; }\n\n"
    . 'return ' . var_export( $payload, true ) . ";\n";
file_put_contents( $php_file, $php_out );
fwrite( STDERR, 'Wrote ' . count( $rows ) . ' models and ' . count( $cache_ratios ) . " cache ratios to {$php_file}\n" );

