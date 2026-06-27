#!/usr/bin/env php
<?php
/**
 * Native PHP daemon entrypoint for WP Agent.
 */

if ( 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "WP Agent daemon must run from PHP CLI.\n" );
    exit( 1 );
}

$opts = array(
    'max_children' => 3,
    'idle_sleep'   => 2,
    'max_seconds'  => 0,
    'max_jobs'     => 0,
    'max_lifetime' => 0,
    'max_idle_time' => 0,
    'memory_soft_limit' => 192,
    'memory_hard_limit' => 256,
    'once'         => false,
    'wp_load'      => '',
    'daemon_token' => '',
);

foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( '--once' === $arg ) {
        $opts['once'] = true;
        continue;
    }
    if ( 0 === strpos( $arg, '--max-children=' ) ) {
        $opts['max_children'] = max( 1, (int) substr( $arg, 15 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--idle-sleep=' ) ) {
        $opts['idle_sleep'] = max( 0, (int) substr( $arg, 13 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--max-seconds=' ) ) {
        $opts['max_seconds'] = max( 0, (int) substr( $arg, 14 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--max-jobs=' ) ) {
        $opts['max_jobs'] = max( 0, (int) substr( $arg, 11 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--max-lifetime=' ) ) {
        $opts['max_lifetime'] = max( 0, (int) substr( $arg, 15 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--max-idle-time=' ) ) {
        $opts['max_idle_time'] = max( 0, (int) substr( $arg, 16 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--memory-soft-limit=' ) ) {
        $opts['memory_soft_limit'] = max( 0, (int) substr( $arg, 20 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--memory-hard-limit=' ) ) {
        $opts['memory_hard_limit'] = max( 0, (int) substr( $arg, 20 ) );
        continue;
    }
    if ( 0 === strpos( $arg, '--wp-load=' ) ) {
        $opts['wp_load'] = substr( $arg, 10 );
        continue;
    }
    if ( 0 === strpos( $arg, '--daemon-token=' ) ) {
        $opts['daemon_token'] = substr( $arg, 15 );
        continue;
    }
    if ( '--help' === $arg || '-h' === $arg ) {
        echo "Usage: php bin/agentd.php [--once] [--max-children=3] [--idle-sleep=2] [--max-seconds=0] [--max-jobs=0] [--max-lifetime=0] [--max-idle-time=0] [--memory-soft-limit=192] [--memory-hard-limit=256] [--wp-load=/path/to/wp-load.php]\n";
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

if ( ! class_exists( 'WPAgent_Daemon' ) ) {
    fwrite( STDERR, "WP Agent is not active or did not load.\n" );
    exit( 1 );
}

$result = WPAgent_Daemon::run(
    $opts,
    function( $line ) {
        fwrite( STDOUT, gmdate( 'c' ) . ' ' . $line . "\n" );
    }
);

if ( empty( $result['ok'] ) ) {
    fwrite( STDERR, ( $result['error'] ?? 'Agent daemon failed.' ) . "\n" );
    exit( 1 );
}
