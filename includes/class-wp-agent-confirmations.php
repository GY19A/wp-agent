<?php
/**
 * Human confirmations for sensitive tool calls.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Confirmations {

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_EXECUTING = 'executing';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXECUTED = 'executed';
    const STATUS_EXPIRED  = 'expired';

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wp_agent_confirmations';
    }

    /**
     * Create or return an existing pending confirmation for an operation.
     *
     * @param array $data
     * @return array|WP_Error
     */
    public static function create( $data ) {
        global $wpdb;

        $params = isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array();
        $hash   = self::operation_hash( $data['tool_name'] ?? '', $params );
        $run_id = (int) ( $data['run_id'] ?? 0 );

        if ( $run_id <= 0 ) {
            return new WP_Error( 'wp_agent_confirmation_no_run', 'A run is required for human confirmation.' );
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE run_id = %d AND operation_hash = %s
             ORDER BY id DESC
             LIMIT 1",
            $run_id,
            $hash
        ), ARRAY_A );
        if ( $existing ) {
            $existing = self::hydrate( $existing );
            if ( self::STATUS_PENDING === $existing['status'] ) {
                if ( strtotime( $existing['expires_at'] ) < time() ) {
                    self::set_status( (int) $existing['id'], self::STATUS_EXPIRED );
                    return new WP_Error( 'wp_agent_confirmation_expired', 'Confirmation expired.' );
                }
                return $existing;
            }
            return new WP_Error( 'wp_agent_confirmation_duplicate', 'This operation has already been decided for the current run.' );
        }

        $expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

        $encoded_params = self::encode_payload( $params );
        if ( is_wp_error( $encoded_params ) ) {
            return $encoded_params;
        }

        $wpdb->insert(
            self::table(),
            array(
                'run_id'          => $run_id,
                'conversation_id' => (int) ( $data['conversation_id'] ?? 0 ),
                'user_id'         => (int) ( $data['user_id'] ?? 0 ),
                'actor_id'        => (int) ( $data['actor_id'] ?? 0 ),
                'channel'         => sanitize_key( $data['channel'] ?? 'webchat' ),
                'tool_name'       => sanitize_key( $data['tool_name'] ?? '' ),
                'tool_call_id'    => sanitize_text_field( $data['tool_call_id'] ?? '' ),
                'action'          => sanitize_text_field( $params['action'] ?? '' ),
                'operation_hash'  => $hash,
                'params'          => $encoded_params,
                'status'          => self::STATUS_PENDING,
                'expires_at'      => $expires,
                'created_at'      => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $wpdb->insert_id ) {
            return new WP_Error( 'wp_agent_confirmation_create', 'Could not create confirmation.' );
        }

        return self::get( (int) $wpdb->insert_id );
    }

    /**
     * Fetch a confirmation by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function get( $id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            (int) $id
        ), ARRAY_A );

        return $row ? self::hydrate( $row ) : null;
    }

    /**
     * Get newest pending confirmation for a run.
     *
     * @param int $run_id
     * @return array|null
     */
    public static function pending_for_run( $run_id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE run_id = %d AND status = %s
             ORDER BY id DESC
             LIMIT 1",
            (int) $run_id,
            self::STATUS_PENDING
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        if ( strtotime( $row['expires_at'] ) < time() ) {
            self::set_status( (int) $row['id'], self::STATUS_EXPIRED );
            return null;
        }

        return self::hydrate( $row );
    }

    /**
     * Approve or reject a pending confirmation.
     *
     * @param int    $id
     * @param int    $decider_id
     * @param string $decision approved|rejected.
     * @return array|WP_Error
     */
    public static function decide( $id, $decider_id, $decision ) {
        global $wpdb;

        $row = self::get( $id );
        if ( ! $row ) {
            return new WP_Error( 'wp_agent_confirmation_missing', 'Confirmation not found.' );
        }

        if ( (int) $row['user_id'] !== (int) $decider_id && ! user_can( $decider_id, 'manage_options' ) ) {
            return new WP_Error( 'wp_agent_confirmation_forbidden', 'You cannot decide this confirmation.' );
        }

        if ( self::STATUS_PENDING !== $row['status'] ) {
            return new WP_Error( 'wp_agent_confirmation_closed', 'Confirmation is no longer pending.' );
        }

        if ( strtotime( $row['expires_at'] ) < time() ) {
            self::set_status( $id, self::STATUS_EXPIRED, $decider_id );
            return new WP_Error( 'wp_agent_confirmation_expired', 'Confirmation expired.' );
        }

        $status = 'approved' === $decision ? self::STATUS_APPROVED : self::STATUS_REJECTED;
        $updated = $wpdb->update(
            self::table(),
            array(
                'status'     => $status,
                'decided_by' => (int) $decider_id,
                'decided_at' => current_time( 'mysql', true ),
            ),
            array(
                'id'     => (int) $id,
                'status' => self::STATUS_PENDING,
            ),
            array( '%s', '%d', '%s' ),
            array( '%d', '%s' )
        );

        if ( 1 !== (int) $updated ) {
            return new WP_Error( 'wp_agent_confirmation_closed', 'Confirmation is no longer pending.' );
        }

        return self::get( $id );
    }

    /**
     * Reject all pending confirmations for a canceled run.
     *
     * @param int $run_id     Run ID.
     * @param int $decider_id User requesting cancellation.
     * @return int Number of confirmations closed.
     */
    public static function reject_pending_for_run( $run_id, $decider_id = 0 ) {
        global $wpdb;

        $updated = $wpdb->update(
            self::table(),
            array(
                'status'     => self::STATUS_REJECTED,
                'decided_by' => $decider_id ? (int) $decider_id : null,
                'decided_at' => current_time( 'mysql', true ),
            ),
            array(
                'run_id' => (int) $run_id,
                'status' => self::STATUS_PENDING,
            ),
            array( '%s', '%d', '%s' ),
            array( '%d', '%s' )
        );

        return max( 0, (int) $updated );
    }

    /**
     * Atomically reserve an approved confirmation for execution.
     *
     * @param int $id
     * @return array|WP_Error
     */
    public static function begin_execution( $id ) {
        global $wpdb;

        $updated = $wpdb->update(
            self::table(),
            array( 'status' => self::STATUS_EXECUTING ),
            array(
                'id'     => (int) $id,
                'status' => self::STATUS_APPROVED,
            ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        if ( 1 !== (int) $updated ) {
            return new WP_Error( 'wp_agent_confirmation_not_executable', 'Confirmation is not ready for execution.' );
        }

        return self::get( $id );
    }

    /**
     * Mark confirmation executed and store the tool result.
     *
     * @param int   $id
     * @param mixed $result
     * @return void
     */
    public static function mark_executed( $id, $result ) {
        global $wpdb;

        $encoded_result = self::encode_payload( $result );
        if ( is_wp_error( $encoded_result ) ) {
            $encoded_result = '';
        }

        $wpdb->update(
            self::table(),
            array(
                'status'     => self::STATUS_EXECUTED,
                'result'     => $encoded_result,
                'decided_at' => current_time( 'mysql', true ),
            ),
            array(
                'id'     => (int) $id,
                'status' => self::STATUS_EXECUTING,
            ),
            array( '%s', '%s', '%s' ),
            array( '%d', '%s' )
        );
    }

    private static function set_status( $id, $status, $decider_id = 0 ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'     => sanitize_key( $status ),
                'decided_by' => $decider_id ? (int) $decider_id : null,
                'decided_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%d', '%s' ),
            array( '%d' )
        );
    }

    private static function operation_hash( $tool_name, $params ) {
        $params = self::sort_recursive( $params );
        return hash( 'sha256', sanitize_key( $tool_name ) . '|' . wp_json_encode( $params ) );
    }

    private static function sort_recursive( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        ksort( $value );
        foreach ( $value as $key => $child ) {
            $value[ $key ] = self::sort_recursive( $child );
        }
        return $value;
    }

    private static function hydrate( $row ) {
        $row['id']              = (int) $row['id'];
        $row['run_id']          = (int) $row['run_id'];
        $row['conversation_id'] = (int) $row['conversation_id'];
        $row['user_id']         = (int) $row['user_id'];
        $row['actor_id']        = (int) $row['actor_id'];
        $row['decided_by']      = $row['decided_by'] ? (int) $row['decided_by'] : null;
        $row['params']          = self::decode_payload( $row['params'] ?? '', array() );
        if ( ! is_array( $row['params'] ) ) {
            $row['params'] = array();
        }
        $row['result'] = self::decode_payload( $row['result'] ?? '', null );
        return $row;
    }

    private static function encode_payload( $value ) {
        $json = wp_json_encode( $value );
        if ( false === $json ) {
            return new WP_Error( 'wp_agent_confirmation_encode', 'Could not encode confirmation payload.' );
        }

        $encrypted = WPAgent::encrypt( $json );
        if ( '' === $encrypted && '' !== $json ) {
            return new WP_Error( 'wp_agent_confirmation_encrypt', 'Could not encrypt confirmation payload.' );
        }

        return $encrypted;
    }

    private static function decode_payload( $stored, $default ) {
        if ( '' === (string) $stored ) {
            return $default;
        }

        $raw = WPAgent::decrypt( (string) $stored );
        if ( '' === $raw ) {
            // Legacy rows before P10 encryption stored raw JSON.
            $raw = (string) $stored;
        }

        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE === json_last_error() ) {
            return $decoded;
        }

        return $default;
    }
}
