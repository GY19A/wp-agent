<?php
/**
 * Moderation tool — submit a draft for human approval via IM.
 *
 * On submission the post is moved to 'pending', a moderation request
 * with an unguessable token is created, and the requester's paired
 * channels receive approve/reject/preview links. Approval publishes
 * and syndicates the post.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Moderation extends WPAgent_Tool {

    public function get_name() {
        return 'request_approval';
    }

    public function get_description() {
        return 'Submit a draft post/page for human approval via IM; on approval it is published and syndicated.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'submit' ),
                    'description' => 'The operation to perform.',
                ),
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'ID of the draft post/page to submit for approval.',
                ),
            ),
            'required' => array( 'action', 'post_id' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        $action = $params['action'] ?? '';

        if ( 'submit' !== $action ) {
            return array( 'error' => 'Unknown action: ' . $action );
        }

        $post_id = (int) ( $params['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            return array( 'error' => 'post_id is required for submit action.' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return array( 'error' => 'Post not found.' );
        }

        $quality = $this->quality_gate( $post_id );
        if ( ! empty( $quality['error'] ) ) {
            return array( 'error' => $quality['error'] );
        }
        if ( 'revise' === ( $quality['status'] ?? '' ) ) {
            return array(
                'error'   => 'Content quality gate requires revision before approval can be requested.',
                'quality' => $quality,
            );
        }

        // Move the post into pending review.
        $result = wp_update_post(
            array(
                'ID'          => $post_id,
                'post_status' => 'pending',
            ),
            true
        );
        if ( is_wp_error( $result ) ) {
            return array( 'error' => $result->get_error_message() );
        }

        $token       = WPAgent_Moderation::create_request( 'post', $post_id, $this->owner_id(), $this->channel );
        $approve_url = WPAgent_Moderation::approve_url( $token );
        $reject_url  = WPAgent_Moderation::reject_url( $token );
        $preview_url = get_preview_post_link( $post_id );

        $this->notify_pairings( $post, $preview_url, $approve_url, $reject_url, $quality );

        return array(
            'success'     => true,
            'post_id'     => $post_id,
            'approve_url' => $approve_url,
            'reject_url'  => $reject_url,
            'preview_url' => $preview_url,
            'quality'     => $quality,
            'message'     => 'Sent for approval to your paired channels.',
        );
    }

    /**
     * Run the native content quality gate before requesting approval.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function quality_gate( $post_id ) {
        if ( ! class_exists( 'WPAgent_Tool_Content_Quality' ) ) {
            return array(
                'status' => 'review',
                'score'  => 0,
                'issues' => array( 'quality_tool_unavailable' ),
            );
        }

        $tool = new WPAgent_Tool_Content_Quality();
        $tool->set_context( $this->user_id, $this->channel, $this->conversation_id, $this->owner_id(), $this->run_id );
        $result = $tool->execute( array(
            'action'  => 'audit_post',
            'post_id' => (int) $post_id,
        ) );

        if ( ! empty( $result['error'] ) ) {
            return array( 'error' => $result['error'] );
        }

        return array(
            'status'          => (string) ( $result['status'] ?? 'review' ),
            'score'           => (int) ( $result['score'] ?? 0 ),
            'issues'          => array_values( (array) ( $result['issues'] ?? array() ) ),
            'recommendations' => array_values( (array) ( $result['recommendations'] ?? array() ) ),
            'checks'          => array(
                'provenance'  => $result['checks']['provenance'] ?? array(),
                'duplicate'   => $result['checks']['duplicate'] ?? array(),
                'seo'         => $result['checks']['seo'] ?? array(),
                'sensitive'   => $result['checks']['sensitive'] ?? array(),
                'readability' => $result['checks']['readability'] ?? array(),
                'media'       => $result['checks']['media'] ?? array(),
            ),
        );
    }

    /**
     * Notify the requester's paired channels with approve/reject links.
     *
     * @param WP_Post $post        The post submitted for approval.
     * @param string  $preview_url Preview link for reviewers.
     * @param string  $approve_url Approval link.
     * @param string  $reject_url  Rejection link.
     * @param array   $quality     Quality gate summary.
     */
    private function notify_pairings( $post, $preview_url, $approve_url, $reject_url, $quality = array() ) {
        if ( ! class_exists( 'WPAgent_Permissions' ) ) {
            return;
        }

        $permissions = new WPAgent_Permissions();
        $pairings    = $permissions->get_user_pairings( $this->owner_id() );
        if ( empty( $pairings ) || ! is_array( $pairings ) ) {
            return;
        }

        $text = sprintf(
            "Approval requested: %s\nQuality: %s (%d/100)\nPreview: %s\nApprove: %s\nReject: %s",
            $post->post_title,
            $quality['status'] ?? 'review',
            (int) ( $quality['score'] ?? 0 ),
            $preview_url,
            $approve_url,
            $reject_url
        );

        foreach ( $pairings as $pairing ) {
            $channel_name = $pairing['channel'] ?? '';
            $chat_id      = $pairing['channel_chat_id'] ?? '';
            if ( '' === $channel_name || '' === $chat_id ) {
                continue;
            }

            $channel = $this->build_channel( $channel_name );
            if ( null === $channel ) {
                continue;
            }

            $channel->send_message( $chat_id, $text );
        }
    }

    /**
     * Build a channel sender from its configured (decrypted) credentials.
     *
     * @param string $channel_name One of telegram, slack, discord.
     * @return WPAgent_Channel_Telegram|WPAgent_Channel_Slack|WPAgent_Channel_Discord|null
     */
    private function build_channel( $channel_name ) {
        switch ( $channel_name ) {
            case 'telegram':
                $token = WPAgent::get_option( 'telegram_bot_token' );
                if ( empty( $token ) || ! class_exists( 'WPAgent_Channel_Telegram' ) ) {
                    return null;
                }
                return new WPAgent_Channel_Telegram( WPAgent::decrypt( $token ) );

            case 'slack':
                $token = WPAgent::get_option( 'slack_bot_token' );
                if ( empty( $token ) || ! class_exists( 'WPAgent_Channel_Slack' ) ) {
                    return null;
                }
                return new WPAgent_Channel_Slack( WPAgent::decrypt( $token ) );

            case 'discord':
                $token = WPAgent::get_option( 'discord_bot_token' );
                if ( empty( $token ) || ! class_exists( 'WPAgent_Channel_Discord' ) ) {
                    return null;
                }
                $app_id = WPAgent::get_option( 'discord_application_id' );
                return new WPAgent_Channel_Discord( WPAgent::decrypt( $token ), $app_id );

            default:
                return null;
        }
    }
}
