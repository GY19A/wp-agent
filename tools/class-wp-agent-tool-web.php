<?php
/**
 * Web tool — gives the agent public internet access.
 *
 * action "search" runs a keyless web search (DuckDuckGo HTML) and returns
 * result titles/URLs/snippets; action "fetch" downloads a public web page and
 * returns its readable text. All fetches are SSRF-guarded: only http(s), and
 * private/reserved/loopback addresses are refused.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Web extends WPAgent_Tool {

    /** @var int Max bytes to download when fetching a page. */
    const MAX_FETCH_BYTES = 2000000;

    /** @var int Max characters of extracted text returned to the model. */
    const MAX_TEXT_CHARS = 12000;

    /** @var string User-Agent used for outbound requests. */
    const USER_AGENT = 'Mozilla/5.0 (compatible; WPAgent/1.0; +https://wordpress.org/)';

    public function get_name() {
        return 'web';
    }

    public function get_description() {
        return 'Access the public internet. action "search" runs a web search and returns result titles, URLs and snippets; action "fetch" downloads a web page and returns its readable text. Use this to research current events, news and facts (then cite the URLs) before writing content.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'search', 'fetch' ),
                    'description' => 'search the web, or fetch a specific URL.',
                ),
                'query' => array(
                    'type'        => 'string',
                    'description' => 'Search query (for action=search).',
                ),
                'url' => array(
                    'type'        => 'string',
                    'description' => 'Absolute http(s) URL to download (for action=fetch).',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum search results (default 6, max 10).',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        switch ( $params['action'] ?? '' ) {
            case 'search':
                return $this->search( $params );
            case 'fetch':
                return $this->fetch( $params );
            default:
                return array( 'error' => 'Unknown action. Use "search" or "fetch".' );
        }
    }

    /**
     * Run a web search via the DuckDuckGo HTML endpoint (no API key needed).
     */
    private function search( $params ) {
        $query = trim( (string) ( $params['query'] ?? '' ) );
        if ( '' === $query ) {
            return array( 'error' => 'query is required for action=search.' );
        }
        $limit = max( 1, min( (int) ( $params['limit'] ?? 6 ), 10 ) );

        $response = wp_remote_post( 'https://html.duckduckgo.com/html/', array(
            'timeout' => 20,
            'headers' => array(
                'User-Agent'   => self::USER_AGENT,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body'    => array( 'q' => $query ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => 'Search failed: ' . $response->get_error_message() );
        }

        $results = $this->parse_ddg( wp_remote_retrieve_body( $response ), $limit );
        if ( empty( $results ) ) {
            return array(
                'error' => 'No results returned (the search provider may be temporarily blocking automated requests). Try fetching a specific URL instead.',
                'query' => $query,
            );
        }

        $tracker = new WPAgent_Cost_Tracker();
        $tracker->record_web( $this->owner_id(), 'search', 1 );

        return array(
            'success'        => true,
            'query'          => $query,
            'results'        => $results,
            'usage_recorded' => true,
            'usage_model'    => WPAgent_Cost_Tracker::web_usage_model( 'search' ),
        );
    }

    /**
     * Parse DuckDuckGo HTML results into title/url/snippet rows.
     */
    private function parse_ddg( $html, $limit ) {
        $results = array();
        if ( empty( $html ) ) {
            return $results;
        }

        if ( ! preg_match_all( '#<a[^>]*class="[^"]*result__a[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
            return $results;
        }

        preg_match_all( '#class="[^"]*result__snippet[^"]*"[^>]*>(.*?)</a>#is', $html, $snippets );

        foreach ( $matches as $i => $match ) {
            if ( count( $results ) >= $limit ) {
                break;
            }
            $href = html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' );

            // DuckDuckGo wraps targets in a redirect: //duckduckgo.com/l/?uddg=<encoded>
            if ( preg_match( '#[?&]uddg=([^&]+)#', $href, $um ) ) {
                $href = urldecode( $um[1] );
            }
            $href = esc_url_raw( $href );
            if ( ! $href ) {
                continue;
            }

            $title   = trim( wp_strip_all_tags( html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ) ) );
            $snippet = isset( $snippets[1][ $i ] )
                ? trim( wp_strip_all_tags( html_entity_decode( $snippets[1][ $i ], ENT_QUOTES, 'UTF-8' ) ) )
                : '';

            $results[] = array(
                'title'   => $title,
                'url'     => $href,
                'snippet' => mb_substr( $snippet, 0, 300 ),
            );
        }

        return $results;
    }

    /**
     * Download a public URL and return its readable text.
     */
    private function fetch( $params ) {
        $url   = trim( (string) ( $params['url'] ?? '' ) );
        $valid = $this->validate_public_url( $url );
        if ( is_wp_error( $valid ) ) {
            return array( 'error' => $valid->get_error_message() );
        }

        $response = wp_remote_get( $url, array(
            'timeout'             => 25,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_FETCH_BYTES,
            'headers'             => array(
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'text/html,application/xhtml+xml,text/plain,application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => 'Fetch failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $text = $this->html_to_text( wp_remote_retrieve_body( $response ) );
        $full = mb_strlen( $text );
        $text = mb_substr( $text, 0, self::MAX_TEXT_CHARS );

        $tracker = new WPAgent_Cost_Tracker();
        $tracker->record_web( $this->owner_id(), 'fetch', 1 );

        return array(
            'success'        => true,
            'url'            => $url,
            'status'         => $code,
            'content_type'   => wp_remote_retrieve_header( $response, 'content-type' ),
            'truncated'      => $full > self::MAX_TEXT_CHARS,
            'text'           => $text,
            'usage_recorded' => true,
            'usage_model'    => WPAgent_Cost_Tracker::web_usage_model( 'fetch' ),
        );
    }

    /**
     * Convert an HTML document into readable plain text.
     */
    private function html_to_text( $html ) {
        if ( '' === trim( (string) $html ) ) {
            return '';
        }
        $html = preg_replace( '#<(script|style|noscript|svg|head|nav|footer)\b[^>]*>.*?</\1>#is', ' ', $html );
        $html = preg_replace( '#<br\s*/?>#i', "\n", $html );
        $html = preg_replace( '#</(p|div|h[1-6]|li|tr|section|article)>#i', "\n", $html );
        $text = wp_strip_all_tags( (string) $html );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( "/[ \t]+/", ' ', $text );
        $text = preg_replace( "/\n[ \t]*\n[ \t]*\n+/", "\n\n", $text );
        return trim( $text );
    }

    /**
     * SSRF guard: only allow public http(s) hosts; refuse private/reserved IPs.
     *
     * @return true|WP_Error
     */
    private function validate_public_url( $url ) {
        if ( '' === trim( (string) $url ) ) {
            return new WP_Error( 'web_url', 'url is required for action=fetch.' );
        }

        $valid = WPAgent_URL_Safety::validate_public_http_url( $url, 'url' );
        if ( is_wp_error( $valid ) ) {
            return new WP_Error( 'web_url', $valid->get_error_message() );
        }

        return true;
    }
}
