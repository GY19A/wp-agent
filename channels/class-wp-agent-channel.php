<?php
/**
 * Abstract channel base class.
 *
 * All messaging channels (Telegram, Slack, Discord, Webchat)
 * extend this class to provide a unified interface.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class WPAgent_Channel {

    /**
     * Get the channel identifier.
     *
     * @return string
     */
    abstract public function get_name();

    /**
     * Initialize the channel (register hooks, etc.).
     */
    public function init() {
        // Override in subclasses if needed.
    }

    /**
     * Handle an incoming webhook request.
     *
     * @param WP_REST_Request $request
     * @return void
     */
    abstract public function handle_incoming( WP_REST_Request $request );

    /**
     * Send a text message to a channel chat.
     *
     * @param string $chat_id Channel-specific chat identifier.
     * @param string $text    Message text.
     * @return bool Success.
     */
    abstract public function send_message( $chat_id, $text );

    /**
     * Process a message through the agent and send the response.
     *
     * @param string $text            User's message.
     * @param int    $user_id         WordPress user ID.
     * @param string $channel_chat_id Channel-specific chat ID.
     */
    protected function process_and_respond( $text, $user_id, $channel_chat_id ) {
        try {
            $conversation = new WPAgent_Conversation();
            $conversation_id = $conversation->get_or_create( $user_id, $this->get_name(), $channel_chat_id );
            $message_id = $conversation->add_message( $conversation_id, 'user', $text );
            $run_id = WPAgent_Runs::create( $conversation_id, $user_id, $message_id, $this->get_name() );

            $this->send_message(
                $channel_chat_id,
                sprintf(
                    /* translators: %d: queued agent run id. */
                    __( 'Queued WP Agent run #%d. I will send the result here when it finishes.', 'wp-agent' ),
                    $run_id
                )
            );

            // Nudge the worker for low-traffic installs; CLI/Cron remain the
            // authoritative background execution paths.
            WPAgent_Worker::run_once( $run_id );
        } catch ( Exception $e ) {
            // Log the full error for debugging, but send a generic message to the channel user.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WP Agent] Channel error (' . $this->get_name() . '): ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            $this->send_message(
                $channel_chat_id,
                __( 'Sorry, something went wrong processing your message. Please try again.', 'wp-agent' )
            );
        }
    }
}
