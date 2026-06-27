<?php
/**
 * Post-approval syndication.
 *
 * After a moderated post is approved and published, broadcast a short
 * announcement (title + permalink) to the configured external targets:
 * Telegram, Discord, X (Twitter), and Reddit. Each target is checked for
 * its enable flag and required credentials; unconfigured targets are
 * skipped, transient/network failures are recorded as errors, and an
 * attempt row is written to the syndication log per target. This must
 * never fatal when something is unconfigured or unreachable.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Syndication {

    /**
     * User agent used for outbound syndication requests.
     */
    const USER_AGENT = 'WPAgent/1.0';

    /**
     * Syndicate a published post to all enabled targets.
     *
     * @param int $post_id Post ID.
     * @return array List of per-target results: ['target','status','remote_id','error'].
     */
    public static function syndicate( $post_id ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );

        if ( ! $post ) {
            return array();
        }

        $title = get_the_title( $post_id );
        $link  = get_permalink( $post_id );
        $msg   = $title . "\n" . $link;

        $results = array();

        $targets = array(
            'telegram' => 'send_telegram',
            'discord'  => 'send_discord',
            'x'        => 'send_x',
            'reddit'   => 'send_reddit',
        );

        foreach ( $targets as $target => $method ) {
            try {
                $outcome = self::$method( $post_id, $msg );
            } catch ( Exception $e ) {
                $outcome = array(
                    'status'    => 'error',
                    'remote_id' => null,
                    'error'     => $e->getMessage(),
                );
            }

            $status    = isset( $outcome['status'] ) ? (string) $outcome['status'] : 'error';
            $remote_id = isset( $outcome['remote_id'] ) ? $outcome['remote_id'] : null;
            $error     = isset( $outcome['error'] ) ? $outcome['error'] : null;

            self::log( $post_id, $target, $status, $remote_id, $error );

            $results[] = array(
                'target'    => $target,
                'status'    => $status,
                'remote_id' => $remote_id,
                'error'     => $error,
            );
        }

        return $results;
    }

    /**
     * Write a syndication attempt to the log table.
     *
     * @param int         $post_id   Post ID.
     * @param string      $target    Target slug.
     * @param string      $status    Outcome status.
     * @param string|null $remote_id Remote object ID, if any.
     * @param string|null $error     Error message, if any.
     */
    private static function log( $post_id, $target, $status, $remote_id = null, $error = null ) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'wp_agent_syndication_log',
            array(
                'object_id'  => (int) $post_id,
                'target'     => substr( (string) $target, 0, 20 ),
                'status'     => substr( (string) $status, 0, 16 ),
                'remote_id'  => null !== $remote_id ? substr( (string) $remote_id, 0, 190 ) : null,
                'error'      => null !== $error ? (string) $error : null,
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Send to Telegram.
     *
     * @param int    $post_id Post ID.
     * @param string $msg     Announcement text.
     * @return array ['status','remote_id','error']
     */
    private static function send_telegram( $post_id, $msg ) {
        if ( ! WPAgent::get_option( 'syndicate_telegram' ) ) {
            return array( 'status' => 'skipped', 'remote_id' => null, 'error' => null );
        }

        $token = WPAgent::get_option( 'telegram_bot_token' );
        $chat  = WPAgent::get_option( 'syndicate_telegram_chat' );

        if ( empty( $token ) || empty( $chat ) ) {
            return array( 'status' => 'skipped', 'remote_id' => null, 'error' => null );
        }

        try {
            $channel = new WPAgent_Channel_Telegram( WPAgent::decrypt( $token ) );
            $sent    = $channel->send_message( $chat, $msg );
        } catch ( Exception $e ) {
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $e->getMessage() );
        }

        if ( ! $sent ) {
            return array( 'status' => 'error', 'remote_id' => null, 'error' => 'Telegram send failed.' );
        }

        return array( 'status' => 'ok', 'remote_id' => null, 'error' => null );
    }

    /**
     * Send to Discord.
     *
     * @param int    $post_id Post ID.
     * @param string $msg     Announcement text.
     * @return array ['status','remote_id','error']
     */
    private static function send_discord( $post_id, $msg ) {
        if ( ! WPAgent::get_option( 'syndicate_discord' ) ) {
            return array( 'status' => 'skipped', 'remote_id' => null, 'error' => null );
        }

        $token   = WPAgent::get_option( 'discord_bot_token' );
        $channel_id = WPAgent::get_option( 'syndicate_discord_channel' );

        if ( empty( $token ) || empty( $channel_id ) ) {
            return array( 'status' => 'skipped', 'remote_id' => null, 'error' => null );
        }

        $app_id = WPAgent::get_option( 'discord_application_id' );

        try {
            $channel = new WPAgent_Channel_Discord( WPAgent::decrypt( $token ), $app_id );
            $result  = $channel->send_message( $channel_id, $msg );
        } catch ( Exception $e ) {
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $e->getMessage() );
        }

        if ( is_wp_error( $result ) ) {
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $result->get_error_message() );
        }

        $remote_id = isset( $result['id'] ) ? (string) $result['id'] : null;

        return array( 'status' => 'ok', 'remote_id' => $remote_id, 'error' => null );
    }

    /**
     * Send to X (Twitter).
     *
     * @param int    $post_id Post ID.
     * @param string $msg     Announcement text.
     * @return array ['status','remote_id','error']
     */
    private static function send_x( $post_id, $msg ) {
        if ( ! WPAgent::get_option( 'syndicate_x' ) ) {
            return array( 'status' => 'skipped', 'remote_id' => null, 'error' => null );
        }

        $token = WPAgent::get_option( 'x_access_token' );

        if ( empty( $token ) ) {
            return array( 'status' => 'skipped', 'remote_id' => null, 'error' => null );
        }

        $response = wp_remote_post( 'https://api.twitter.com/2/tweets', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . WPAgent::decrypt( $token ),
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'text' => substr( $msg, 0, 275 ),
            ) ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 201 !== $code ) {
            $error = isset( $body['detail'] ) ? $body['detail'] : ( isset( $body['title'] ) ? $body['title'] : 'X API error (HTTP ' . $code . ')' );
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $error );
        }

        $remote_id = isset( $body['data']['id'] ) ? (string) $body['data']['id'] : null;

        return array( 'status' => 'ok', 'remote_id' => $remote_id, 'error' => null );
    }

    /**
     * Send to Reddit (link submission via OAuth2 password grant).
     *
     * @param int    $post_id Post ID.
     * @param string $msg     Announcement text (unused; Reddit uses title + url).
     * @return array ['status','remote_id','error']
     */
    private static function send_reddit( $post_id, $msg ) {
        if ( ! WPAgent::get_option( 'syndicate_reddit' ) ) {
            return array( 'status' => 'skipped', 'remote_id' => null, 'error' => null );
        }

        $client_id     = WPAgent::get_option( 'reddit_client_id' );
        $client_secret = WPAgent::get_option( 'reddit_client_secret' );
        $username      = WPAgent::get_option( 'reddit_username' );
        $password      = WPAgent::get_option( 'reddit_password' );
        $subreddit     = WPAgent::get_option( 'reddit_subreddit' );

        if ( empty( $client_id ) || empty( $client_secret ) || empty( $username ) || empty( $password ) ) {
            return array( 'status' => 'skipped', 'remote_id' => null, 'error' => null );
        }

        $secret_plain   = WPAgent::decrypt( $client_secret );
        $password_plain = WPAgent::decrypt( $password );

        // Obtain an access token via the password grant.
        $token_response = wp_remote_post( 'https://www.reddit.com/api/v1/access_token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret_plain ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header.
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'User-Agent'    => self::USER_AGENT,
            ),
            'body'    => array(
                'grant_type' => 'password',
                'username'   => $username,
                'password'   => $password_plain,
            ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $token_response ) ) {
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $token_response->get_error_message() );
        }

        $token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );

        if ( empty( $token_body['access_token'] ) ) {
            $error = isset( $token_body['error'] ) ? (string) $token_body['error'] : 'Reddit auth failed.';
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $error );
        }

        $access_token = (string) $token_body['access_token'];
        $title        = get_the_title( $post_id );
        $permalink    = get_permalink( $post_id );

        $submit_response = wp_remote_post( 'https://oauth.reddit.com/api/submit', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'User-Agent'    => self::USER_AGENT,
            ),
            'body'    => array(
                'sr'       => $subreddit,
                'kind'     => 'link',
                'title'    => $title,
                'url'      => $permalink,
                'api_type' => 'json',
            ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $submit_response ) ) {
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $submit_response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $submit_response );
        $body = json_decode( wp_remote_retrieve_body( $submit_response ), true );

        if ( 200 !== $code ) {
            return array( 'status' => 'error', 'remote_id' => null, 'error' => 'Reddit submit error (HTTP ' . $code . ')' );
        }

        // Reddit reports errors in json.errors even on a 200 response.
        if ( ! empty( $body['json']['errors'] ) ) {
            $first = $body['json']['errors'][0];
            $error = is_array( $first ) ? implode( ': ', array_map( 'strval', $first ) ) : (string) $first;
            return array( 'status' => 'error', 'remote_id' => null, 'error' => $error );
        }

        $remote_id = null;
        if ( isset( $body['json']['data']['id'] ) ) {
            $remote_id = (string) $body['json']['data']['id'];
        } elseif ( isset( $body['json']['data']['name'] ) ) {
            $remote_id = (string) $body['json']['data']['name'];
        }

        return array( 'status' => 'ok', 'remote_id' => $remote_id, 'error' => null );
    }
}
