<?php
/**
 * Content moderation: human approve/reject of draft content.
 *
 * Stores pending moderation requests keyed by an unguessable token. On
 * approval the underlying post is published and handed to the syndication
 * pipeline; on rejection it is left as a draft.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Moderation {

    /** @var int Number of days a moderation request stays valid. */
    const EXPIRY_DAYS = 7;

    /**
     * Table name helper.
     *
     * @return string
     */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wp_agent_moderation';
    }

    /**
     * Create a new pending moderation request.
     *
     * @param string $object_type Object type (e.g. 'post').
     * @param int    $object_id   Object ID.
     * @param int    $user_id     Requesting WordPress user ID.
     * @param string $channel     Originating channel.
     * @return string Unguessable token.
     */
    public static function create_request( $object_type, $object_id, $user_id, $channel ) {
        global $wpdb;

        $token = wp_generate_password( 48, false, false );

        $wpdb->insert(
            self::table(),
            array(
                'object_type'  => $object_type,
                'object_id'    => $object_id,
                'token'        => $token,
                'status'       => 'pending',
                'requested_by' => $user_id,
                'channel'      => $channel,
                'created_at'   => current_time( 'mysql', true ),
            ),
            array( '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        return $token;
    }

    /**
     * Fetch a moderation request by its token.
     *
     * @param string $token
     * @return object|null
     */
    public static function get_by_token( $token ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE token = %s",
            $token
        ) );

        return $row ? $row : null;
    }

    /**
     * Approve a pending moderation request.
     *
     * Publishes the post, marks the request approved, and runs syndication.
     *
     * @param string $token
     * @return array
     */
    public static function approve( $token ) {
        global $wpdb;

        $request = self::find_pending( $token );
        if ( is_wp_error( $request ) ) {
            return array( 'success' => false, 'error' => $request->get_error_message() );
        }

        $object_id = (int) $request->object_id;

        $result = wp_update_post( array(
            'ID'          => $object_id,
            'post_status' => 'publish',
        ), true );

        if ( is_wp_error( $result ) ) {
            return array( 'success' => false, 'error' => $result->get_error_message() );
        }

        $wpdb->update(
            self::table(),
            array(
                'status'     => 'approved',
                'decided_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $request->id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        $syndication = WPAgent_Syndication::syndicate( $object_id );

        return array(
            'success'     => true,
            'post_id'     => $object_id,
            'permalink'   => get_permalink( $object_id ),
            'syndication' => $syndication,
        );
    }

    /**
     * Reject a pending moderation request.
     *
     * Leaves the post as a draft and marks the request rejected.
     *
     * @param string $token
     * @return array
     */
    public static function reject( $token ) {
        global $wpdb;

        $request = self::find_pending( $token );
        if ( is_wp_error( $request ) ) {
            return array( 'success' => false, 'error' => $request->get_error_message() );
        }

        $object_id = (int) $request->object_id;

        wp_update_post( array(
            'ID'          => $object_id,
            'post_status' => 'draft',
        ) );

        $wpdb->update(
            self::table(),
            array(
                'status'     => 'rejected',
                'decided_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => $request->id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return array(
            'success' => true,
            'post_id' => $object_id,
        );
    }

    /**
     * Build the REST URL to approve a request.
     *
     * @param string $token
     * @return string
     */
    public static function approve_url( $token ) {
        return add_query_arg( 'token', $token, rest_url( 'wp-agent/v1/moderate/approve' ) );
    }

    /**
     * Build the REST URL to reject a request.
     *
     * @param string $token
     * @return string
     */
    public static function reject_url( $token ) {
        return add_query_arg( 'token', $token, rest_url( 'wp-agent/v1/moderate/reject' ) );
    }

    /**
     * Resolve a token to a pending, non-expired request.
     *
     * @param string $token
     * @return object|WP_Error
     */
    private static function find_pending( $token ) {
        $request = self::get_by_token( $token );

        if ( ! $request ) {
            return new WP_Error( 'invalid_token', __( 'Invalid moderation token.', 'wp-agent' ) );
        }

        if ( 'pending' !== $request->status ) {
            return new WP_Error( 'token_used', __( 'This moderation request has already been decided.', 'wp-agent' ) );
        }

        $created = strtotime( $request->created_at . ' UTC' );
        if ( $created && ( $created + ( self::EXPIRY_DAYS * DAY_IN_SECONDS ) ) < time() ) {
            return new WP_Error( 'token_expired', __( 'This moderation request has expired.', 'wp-agent' ) );
        }

        return $request;
    }
}
