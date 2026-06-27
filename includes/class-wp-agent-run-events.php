<?php
/**
 * Append-only run event log.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Run_Events {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wp_agent_run_events';
    }

    /**
     * Add an event to a run timeline.
     *
     * @param int    $run_id
     * @param int    $user_id
     * @param string $event_type
     * @param string $message
     * @param array  $metadata
     * @return int
     */
    public static function add( $run_id, $user_id, $event_type, $message = '', $metadata = array() ) {
        global $wpdb;

        $event_type = sanitize_key( $event_type );
        if ( '' === $event_type ) {
            $event_type = 'event';
        }

        $wpdb->insert(
            self::table(),
            array(
                'run_id'     => (int) $run_id,
                'user_id'    => (int) $user_id,
                'event_type' => $event_type,
                'message'    => sanitize_textarea_field( (string) $message ),
                'metadata'   => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get recent events for a run.
     *
     * @param int $run_id
     * @param int $limit
     * @return array
     */
    public static function recent( $run_id, $limit = 50 ) {
        global $wpdb;

        $limit = max( 1, min( (int) $limit, 200 ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, run_id, user_id, event_type, message, metadata, created_at
             FROM " . self::table() . "
             WHERE run_id = %d
             ORDER BY id DESC
             LIMIT %d",
            (int) $run_id,
            $limit
        ), ARRAY_A );
    }
}
