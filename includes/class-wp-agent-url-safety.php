<?php
/**
 * Shared URL safety checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_URL_Safety {

    /**
     * Validate that a URL targets a public HTTP(S) host.
     *
     * @param string $url   URL to validate.
     * @param string $label Human-readable field label for errors.
     * @return true|WP_Error
     */
    public static function validate_public_http_url( $url, $label = 'URL' ) {
        $label = sanitize_text_field( (string) $label );
        if ( '' === $label ) {
            $label = 'URL';
        }

        $url = trim( (string) $url );
        if ( '' === $url ) {
            return new WP_Error( 'wp_agent_url_required', $label . ' is required.' );
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return new WP_Error( 'wp_agent_url_scheme', $label . ' must use http or https.' );
        }

        $host = isset( $parts['host'] ) ? strtolower( trim( (string) $parts['host'], "[] \t\n\r\0\x0B" ) ) : '';
        if ( '' === $host ) {
            return new WP_Error( 'wp_agent_url_host', $label . ' must include a valid host.' );
        }

        if ( self::is_localhost_name( $host ) ) {
            return new WP_Error( 'wp_agent_url_localhost', 'Refusing localhost URL.' );
        }

        $ips = self::resolve_host_ips( $host );
        if ( empty( $ips ) ) {
            return new WP_Error( 'wp_agent_url_resolve', 'Could not resolve the URL host.' );
        }

        foreach ( $ips as $ip ) {
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return new WP_Error( 'wp_agent_url_private', 'Refusing private, loopback, or reserved URL host.' );
            }
        }

        return true;
    }

    private static function is_localhost_name( $host ) {
        return in_array( $host, array( 'localhost', 'localhost.localdomain', 'ip6-localhost' ), true )
            || '.localhost' === substr( $host, -10 );
    }

    private static function resolve_host_ips( $host ) {
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return array( $host );
        }

        $ips     = array();
        $records = @dns_get_record( $host, DNS_A | DNS_AAAA );
        if ( is_array( $records ) ) {
            foreach ( $records as $record ) {
                if ( ! empty( $record['ip'] ) ) {
                    $ips[] = $record['ip'];
                }
                if ( ! empty( $record['ipv6'] ) ) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ( empty( $ips ) ) {
            $resolved = gethostbyname( $host );
            if ( $resolved && $resolved !== $host ) {
                $ips[] = $resolved;
            }
        }

        return array_values( array_unique( $ips ) );
    }
}
