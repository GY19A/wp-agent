<?php
/**
 * Durable agent work journal.
 *
 * Stores what the agent planned, changed, generated, failed, and handed off.
 * This complements preference/fact memory with an operational timeline that can
 * be recalled into the system prompt and queried through the journal tool.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Journal {

    const TYPES = array( 'note', 'goal', 'plan', 'action', 'asset', 'schedule', 'decision', 'error', 'handoff' );

    /**
     * Add a journal entry.
     *
     * @param int    $user_id         Owner/requesting user.
     * @param string $entry_type      Entry type.
     * @param string $title           Short title.
     * @param string $body            Markdown-friendly body.
     * @param array  $metadata        Optional structured metadata.
     * @param int    $conversation_id Optional conversation id.
     * @param int    $run_id          Optional async run id.
     * @return int Insert id, or 0 on failure.
     */
    public static function add( $user_id, $entry_type, $title, $body, $metadata = array(), $conversation_id = null, $run_id = null ) {
        global $wpdb;

        $entry_type = sanitize_key( $entry_type );
        if ( ! in_array( $entry_type, self::TYPES, true ) ) {
            $entry_type = 'note';
        }

        $title = sanitize_text_field( (string) $title );
        $body  = sanitize_textarea_field( (string) $body );

        if ( '' === $title || '' === $body ) {
            return 0;
        }

        $wpdb->insert(
            $wpdb->prefix . 'wp_agent_journal',
            array(
                'user_id'         => (int) $user_id,
                'conversation_id' => $conversation_id ? (int) $conversation_id : null,
                'run_id'          => $run_id ? (int) $run_id : null,
                'entry_type'      => $entry_type,
                'title'           => mb_substr( $title, 0, 190 ),
                'body'            => $body,
                'metadata'        => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
                'created_at'      => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get recent entries for a user.
     *
     * @param int    $user_id Owner user id.
     * @param int    $limit   Result limit.
     * @param string $type    Optional entry type.
     * @return array<int,array<string,mixed>>
     */
    public static function recent( $user_id, $limit = 20, $type = '' ) {
        global $wpdb;

        $limit = max( 1, min( (int) $limit, 50 ) );
        $type  = sanitize_key( $type );

        if ( $type && in_array( $type, self::TYPES, true ) ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT id, user_id, conversation_id, run_id, entry_type, title, body, metadata, created_at
                 FROM {$wpdb->prefix}wp_agent_journal
                 WHERE user_id = %d AND entry_type = %s
                 ORDER BY id DESC
                 LIMIT %d",
                (int) $user_id,
                $type,
                $limit
            ), ARRAY_A );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, conversation_id, run_id, entry_type, title, body, metadata, created_at
             FROM {$wpdb->prefix}wp_agent_journal
             WHERE user_id = %d
             ORDER BY id DESC
             LIMIT %d",
            (int) $user_id,
            $limit
        ), ARRAY_A );
    }

    /**
     * Search entries by keyword.
     *
     * @param int    $user_id Owner user id.
     * @param string $query   Search text.
     * @param int    $limit   Result limit.
     * @return array<int,array<string,mixed>>
     */
    public static function search( $user_id, $query, $limit = 20 ) {
        global $wpdb;

        $query = trim( (string) $query );
        $limit = max( 1, min( (int) $limit, 50 ) );

        if ( '' === $query ) {
            return self::recent( $user_id, $limit );
        }

        $like = '%' . $wpdb->esc_like( $query ) . '%';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, conversation_id, run_id, entry_type, title, body, metadata, created_at
             FROM {$wpdb->prefix}wp_agent_journal
             WHERE user_id = %d AND (title LIKE %s OR body LIKE %s)
             ORDER BY id DESC
             LIMIT %d",
            (int) $user_id,
            $like,
            $like,
            $limit
        ), ARRAY_A );
    }
}