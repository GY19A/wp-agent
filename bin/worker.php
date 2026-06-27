#!/usr/bin/env php
<?php
/**
 * Native PHP CLI worker entrypoint for WP Agent.
 */

if ( 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "WP Agent worker must run from PHP CLI.\n" );
    exit( 1 );
}

$opts = array(
    'max_seconds' => 300,
    'sleep'       => 2,
    'batch'       => 1,
    'once'        => false,
    'wp_load'     => '',
);

foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( '--once' === $arg ) {
        $opts['once'] = true;
        continue;
    }
    if ( 0 === strpos( $arg, '--max-seconds=' ) ) {
        $opts['max_seconds'] = max( 1, (int) substr( $arg, 14 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--sleep=' ) ) {
        $opts['sleep'] = max( 0, (int) substr( $arg, 8 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--batch=' ) ) {
        $opts['batch'] = max( 1, (int) substr( $arg, 8 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--wp-load=' ) ) {
        $opts['wp_load'] = substr( $arg, 10 );
        continue;
    }
    if ( '--help' === $arg || '-h' === $arg ) {
        echo "Usage: php bin/worker.php [--once] [--max-seconds=300] [--sleep=2] [--batch=1] [--wp-load=/path/to/wp-load.php]\n";
        exit( 0 );
    }
    fwrite( STDERR, "Unknown argument: {$arg}\n" );
    exit( 1 );
}

if ( '' === $opts['wp_load'] && getenv( 'WPA_WP_LOAD' ) ) {
    $opts['wp_load'] = getenv( 'WPA_WP_LOAD' );
}

if ( '' === $opts['wp_load'] ) {
    $dir = __DIR__;
    for ( $i = 0; $i < 8; $i++ ) {
        $candidate = $dir . '/wp-load.php';
        if ( is_file( $candidate ) ) {
            $opts['wp_load'] = $candidate;
            break;
        }
        $parent = dirname( $dir );
        if ( $parent === $dir ) {
            break;
        }
        $dir = $parent;
    }
}

if ( '' === $opts['wp_load'] || ! is_file( $opts['wp_load'] ) ) {
    fwrite( STDERR, "Could not locate wp-load.php. Pass --wp-load=/absolute/path/wp-load.php or set WPA_WP_LOAD.\n" );
    exit( 1 );
}

define( 'WP_USE_THEMES', false );
require_once $opts['wp_load'];

if ( ! class_exists( 'WPAgent_Worker' ) ) {
    fwrite( STDERR, "WP Agent is not active or did not load.\n" );
    exit( 1 );
}

WPAgent_Worker::run_loop(
    $opts,
    function( $line ) {
        fwrite( STDOUT, $line . "\n" );
    }
);
