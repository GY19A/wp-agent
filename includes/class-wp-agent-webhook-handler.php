<?php
/**
 * Webhook handler — REST API route registration.
 *
 * Registers all WP Agent REST endpoints and routes
 * incoming requests to the appropriate handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Webhook_Handler {

    const NAMESPACE = 'wp-agent/v1';

    /**
     * Register all REST API routes.
     */
    public function register_routes() {
        // Synchronous chat endpoint used by external integrations.
        register_rest_route( self::NAMESPACE, '/chat', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_chat' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'message'         => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'conversation_id' => array( 'required' => false, 'type' => 'integer' ),
                'channel'         => array( 'required' => false, 'type' => 'string', 'default' => 'webchat' ),
            ),
        ) );

        // Full-page chat: queue a run for an async, poll-driven agent loop.
        register_rest_route( self::NAMESPACE, '/chat/send', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_chat_send' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'message'         => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
                'conversation_id' => array( 'required' => false, 'type' => 'integer' ),
                'attachments'     => array( 'required' => false, 'type' => 'array' ),
            ),
        ) );

        // Full-page chat: upload multimedia attachments before sending a run.
        register_rest_route( self::NAMESPACE, '/chat/upload', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_chat_upload' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        // Full-page chat: poll a queued run, advancing it one loop iteration per call.
        register_rest_route( self::NAMESPACE, '/chat/poll', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_chat_poll' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'run_id'          => array( 'required' => true, 'type' => 'integer' ),
                'conversation_id' => array( 'required' => true, 'type' => 'integer' ),
                'after'           => array( 'required' => false, 'type' => 'integer', 'default' => 0 ),
            ),
        ) );

        // Full-page chat: fetch the full message history for a conversation.
        register_rest_route( self::NAMESPACE, '/chat/history', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_chat_history' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'conversation_id' => array( 'required' => true, 'type' => 'integer' ),
            ),
        ) );

        // Full-page chat: find an unfinished run for a conversation after reload/navigation.
        register_rest_route( self::NAMESPACE, '/chat/active-run', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_chat_active_run' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'conversation_id' => array( 'required' => true, 'type' => 'integer' ),
            ),
        ) );

        // Full-page chat: cooperatively stop a queued/running run.
        register_rest_route( self::NAMESPACE, '/chat/runs/(?P<id>[0-9]+)/cancel', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_chat_run_cancel' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'id'              => array( 'required' => true, 'type' => 'integer' ),
                'conversation_id' => array( 'required' => false, 'type' => 'integer' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/confirmations/(?P<id>[0-9]+)/approve', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_confirmation_approve' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        register_rest_route( self::NAMESPACE, '/confirmations/(?P<id>[0-9]+)/reject', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_confirmation_reject' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        // Telegram webhook.
        register_rest_route( self::NAMESPACE, '/telegram', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_telegram' ),
            'permission_callback' => '__return_true', // Telegram verifies via secret token.
        ) );

        // Slack webhook.
        register_rest_route( self::NAMESPACE, '/slack', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_slack' ),
            'permission_callback' => '__return_true',
        ) );

        // Discord interactions.
        register_rest_route( self::NAMESPACE, '/discord', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_discord' ),
            'permission_callback' => '__return_true',
        ) );

        // Pairing endpoint.
        register_rest_route( self::NAMESPACE, '/pair', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_pair' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'code' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // Usage data.
        register_rest_route( self::NAMESPACE, '/usage', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_usage' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'period' => array( 'required' => false, 'type' => 'string', 'default' => 'month' ),
            ),
        ) );

        // Conversations list.
        register_rest_route( self::NAMESPACE, '/conversations', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_conversations' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'search' => array( 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // Skills: search a GitHub repository for installable skills.
        register_rest_route( self::NAMESPACE, '/skills/search-github', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_skills_search_github' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'query'      => array( 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'repository' => array( 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'ref'        => array( 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        // Scheduled tasks: list all schedules.
        register_rest_route( self::NAMESPACE, '/schedules', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_schedules_list' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        // Scheduled tasks: run a schedule immediately.
        register_rest_route( self::NAMESPACE, '/schedules/(?P<id>[0-9]+)/run', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_schedule_run' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        // Scheduled tasks: pause a schedule.
        register_rest_route( self::NAMESPACE, '/schedules/(?P<id>[0-9]+)/pause', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_schedule_pause' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        // Scheduled tasks: resume a schedule.
        register_rest_route( self::NAMESPACE, '/schedules/(?P<id>[0-9]+)/resume', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_schedule_resume' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        // Scheduled tasks: delete a schedule.
        register_rest_route( self::NAMESPACE, '/schedules/(?P<id>[0-9]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'handle_schedule_delete' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        // Available AI models (fetched from the gateway).
        register_rest_route( self::NAMESPACE, '/models', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_list_models' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        // MCP server management.
        register_rest_route( self::NAMESPACE, '/mcp-servers', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_mcp_add_server' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'name'        => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'transport'   => array( 'required' => false, 'type' => 'string', 'default' => 'http' ),
                'endpoint'    => array( 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ),
                'auth_type'   => array( 'required' => false, 'type' => 'string', 'default' => 'none' ),
                'credentials' => array( 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'command'     => array( 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/mcp-servers/(?P<id>[a-z0-9_-]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'handle_mcp_remove_server' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        register_rest_route( self::NAMESPACE, '/mcp-servers/(?P<id>[a-z0-9_-]+)/discover', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_mcp_discover' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
        ) );

        register_rest_route( self::NAMESPACE, '/mcp-servers/(?P<id>[a-z0-9_-]+)/toggle', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_mcp_toggle' ),
            'permission_callback' => array( $this, 'check_admin_auth' ),
            'args'                => array(
                'enabled' => array( 'required' => true, 'type' => 'boolean' ),
            ),
        ) );

        // Moderation: approve a pending post via unguessable token.
        register_rest_route( self::NAMESPACE, '/moderate/approve', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_moderate_approve' ),
            'permission_callback' => '__return_true', // The unguessable token is the auth.
            'args'                => array(
                'token' => array( 'required' => true, 'type' => 'string' ),
            ),
        ) );

        // Moderation: reject a pending post via unguessable token.
        register_rest_route( self::NAMESPACE, '/moderate/reject', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_moderate_reject' ),
            'permission_callback' => '__return_true', // The unguessable token is the auth.
            'args'                => array(
                'token' => array( 'required' => true, 'type' => 'string' ),
            ),
        ) );

        // Health check.
        register_rest_route( self::NAMESPACE, '/health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_health' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Handle synchronous webchat messages.
     */
    public function handle_chat( WP_REST_Request $request ) {
        $message         = $request->get_param( 'message' );
        $conversation_id = $request->get_param( 'conversation_id' );
        $user_id         = get_current_user_id();

        try {
            $agent  = WPAgent::get_agent();
            $result = $agent->handle_message( $message, $user_id, 'webchat', 'admin-sidebar', $conversation_id );
            return new WP_REST_Response( $result, 200 );
        } catch ( Exception $e ) {
            // Log the full error for debugging, but return a safe message to the client.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WP Agent] Chat error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return new WP_REST_Response( array(
                'response' => __( 'Something went wrong processing your message. Please try again.', 'wp-agent' ),
                'error'    => true,
            ), 500 );
        }
    }

    /**
     * Queue a chat run for the full-page async chat.
     *
     * Creates (or reuses) a conversation, records the user's message, and
     * enqueues a run. A resident PHP CLI daemon is nudged to process the queue;
     * browser polling remains a bounded shared-hosting fallback and live status
     * channel, not the primary execution path.
     */
    public function handle_chat_send( WP_REST_Request $request ) {
        $message         = trim( (string) $request->get_param( 'message' ) );
        $conversation_id = (int) $request->get_param( 'conversation_id' );
        $attachments     = $this->sanitize_chat_attachments( $request->get_param( 'attachments' ) );
        $user_id         = get_current_user_id();

        if ( '' === $message && empty( $attachments ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Message or attachment required.', 'wp-agent' ) ), 400 );
        }

        $conversation = new WPAgent_Conversation();

        // Reuse the supplied conversation only if it belongs to this user.
        if ( $conversation_id && $this->conversation_owner( $conversation_id ) === $user_id ) {
            $conv = $conversation_id;
        } else {
            $conv = $conversation->start_new( $user_id, 'webchat', 'studio' );
        }

        $stored_message = $this->compose_chat_message_with_attachments( $message, $attachments );
        $message_id     = $conversation->add_message( $conv, 'user', $stored_message );
        $run_id     = WPAgent_Runs::create( $conv, $user_id, $message_id, 'webchat' );

        if ( class_exists( 'WPAgent_Journal' ) ) {
            WPAgent_Journal::add(
                $user_id,
                'goal',
                'Queued chat task',
                mb_substr( $stored_message, 0, 1000 ),
                array( 'message_id' => $message_id, 'attachments' => $attachments ),
                $conv,
                $run_id
            );
        }

        $daemon = $this->wake_background_agent( $run_id, $user_id );

        return new WP_REST_Response( array(
            'run_id'          => $run_id,
            'conversation_id' => $conv,
            'queued'          => true,
            'daemon'          => $daemon,
            'queue'           => WPAgent_Runs::queue_summary_for_run( $run_id ),
            'message'         => array(
                'id'      => $message_id,
                'role'    => 'user',
                'content' => $stored_message,
            ),
        ), 200 );
    }

    /**
     * Upload one chat attachment into the WordPress Media Library.
     */
    public function handle_chat_upload( WP_REST_Request $request ) {
        if ( ! current_user_can( 'upload_files' ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Permission denied.', 'wp-agent' ) ), 403 );
        }

        $files = $request->get_file_params();
        if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
            return new WP_REST_Response( array( 'error' => __( 'No file uploaded.', 'wp-agent' ) ), 400 );
        }

        $file = $files['file'];
        if ( ! empty( $file['size'] ) && (int) $file['size'] > 26214400 ) {
            return new WP_REST_Response( array( 'error' => __( 'Attachment exceeds the 25 MB limit.', 'wp-agent' ) ), 400 );
        }

        $allowed = array( 'image/', 'audio/', 'video/', 'application/pdf', 'text/plain', 'text/csv', 'application/json' );
        $type    = isset( $file['type'] ) ? sanitize_mime_type( $file['type'] ) : '';
        $ok      = false;
        foreach ( $allowed as $prefix ) {
            if ( 0 === strpos( $type, $prefix ) ) {
                $ok = true;
                break;
            }
        }
        if ( ! $ok ) {
            return new WP_REST_Response( array( 'error' => __( 'Attachment type is not supported.', 'wp-agent' ) ), 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
        if ( isset( $upload['error'] ) ) {
            return new WP_REST_Response( array( 'error' => $upload['error'] ), 400 );
        }

        $attachment_id = wp_insert_attachment( array(
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ), $upload['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            return new WP_REST_Response( array( 'error' => $attachment_id->get_error_message() ), 400 );
        }

        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        // Guarantee alt text + caption for accessibility and SEO on uploads too.
        if ( class_exists( 'WPAgent_Media_Meta' ) ) {
            WPAgent_Media_Meta::ensure( (int) $attachment_id );
        }

        return new WP_REST_Response( array(
            'attachment' => $this->format_chat_attachment( (int) $attachment_id ),
        ), 200 );
    }

    private function sanitize_chat_attachments( $attachments ) {
        if ( ! is_array( $attachments ) ) {
            return array();
        }

        $clean = array();
        foreach ( array_slice( $attachments, 0, 8 ) as $item ) {
            if ( ! is_array( $item ) || empty( $item['id'] ) ) {
                continue;
            }
            $attachment = $this->format_chat_attachment( (int) $item['id'] );
            if ( $attachment ) {
                $clean[] = $attachment;
            }
        }
        return $clean;
    }

    private function compose_chat_message_with_attachments( $message, $attachments ) {
        if ( empty( $attachments ) ) {
            return $message;
        }

        $lines = array( $message, '', 'Attached media:' );
        foreach ( $attachments as $attachment ) {
            $mime = (string) $attachment['mime_type'];
            $lines[] = sprintf(
                '- #%d %s (%s): %s',
                (int) $attachment['id'],
                $attachment['filename'],
                $mime,
                $attachment['url']
            );

            // Machine-readable marker so the agent can expand the attachment into
            // a multimodal content part (image) or attach inspection notes
            // (audio/video) before sending to the model. Kept on its own line and
            // stripped from the multimodal text part.
            $kind = $this->attachment_kind( $mime );
            $lines[] = sprintf( '  [wp-agent-media id=%d kind=%s]', (int) $attachment['id'], $kind );

            $content = $this->read_chat_attachment_text( (int) $attachment['id'] );
            if ( '' !== $content ) {
                $lines[] = '  Extracted content:';
                foreach ( explode( "\n", $content ) as $content_line ) {
                    $lines[] = '  ' . $content_line;
                }
            } elseif ( 'image' === $kind ) {
                $lines[] = '  The image itself is attached for you to view directly.';
            } elseif ( 'audio' === $kind ) {
                $lines[] = '  Audio file attached. Describe or transcribe it from the attached audio when the model supports audio, otherwise use the URL.';
            } elseif ( 'video' === $kind ) {
                $lines[] = '  Video file attached. Inspect available frames/metadata; full video understanding may require a capable model or the URL.';
            } else {
                $lines[] = '  Extracted content: Not available for this file type. Use the URL or media tools if inspection is needed.';
            }
        }
        return trim( implode( "\n", $lines ) );
    }

    /**
     * Classify an attachment MIME type into a coarse media kind.
     *
     * @param string $mime MIME type.
     * @return string image|audio|video|file
     */
    private function attachment_kind( $mime ) {
        $mime = strtolower( (string) $mime );
        if ( 0 === strpos( $mime, 'image/' ) ) {
            return 'image';
        }
        if ( 0 === strpos( $mime, 'audio/' ) ) {
            return 'audio';
        }
        if ( 0 === strpos( $mime, 'video/' ) ) {
            return 'video';
        }
        return 'file';
    }

    private function read_chat_attachment_text( $attachment_id ) {
        $post = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return '';
        }

        $mime_type = (string) get_post_mime_type( $attachment_id );
        $readable  = array(
            'text/plain',
            'text/csv',
            'text/markdown',
            'application/json',
        );
        if ( ! in_array( $mime_type, $readable, true ) ) {
            return '';
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! is_readable( $file ) || ! $this->is_safe_upload_path( $file ) ) {
            return '';
        }

        $max_bytes = 24000;
        $contents  = file_get_contents( $file, false, null, 0, $max_bytes + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $contents || '' === $contents ) {
            return '';
        }

        $truncated = strlen( $contents ) > $max_bytes;
        if ( $truncated ) {
            $contents = substr( $contents, 0, $max_bytes );
        }

        $contents = wp_check_invalid_utf8( $contents, true );
        $contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );
        $contents = preg_replace( "/\n{4,}/", "\n\n\n", $contents );
        $contents = trim( $contents );
        if ( '' === $contents ) {
            return '';
        }

        if ( $truncated ) {
            $contents .= "\n\n[Attachment content truncated after 24000 bytes.]";
        }

        return $contents;
    }

    private function is_safe_upload_path( $file ) {
        $uploads = wp_get_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return false;
        }

        $real_file = realpath( $file );
        $real_base = realpath( $uploads['basedir'] );
        if ( ! $real_file || ! $real_base ) {
            return false;
        }

        return 0 === strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $real_base ) ) );
    }

    private function format_chat_attachment( $attachment_id ) {
        $post = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return null;
        }

        $url  = wp_get_attachment_url( $attachment_id );
        $file = get_attached_file( $attachment_id );
        if ( ! $url || ! $file ) {
            return null;
        }

        return array(
            'id'        => (int) $attachment_id,
            'filename'  => basename( $file ),
            'mime_type' => $post->post_mime_type,
            'url'       => $url,
            'title'     => get_the_title( $attachment_id ),
        );
    }

    /**
     * Build the Agent-workspace activity payload for a run: a curated event
     * timeline plus the sub-agent tree (when the run delegated).
     *
     * @param int $run_id
     * @return array{events: array, subagents: array}
     */
    private function run_activity_payload( $run_id ) {
        $events_out = array();
        $labels     = array();

        if ( class_exists( 'WPAgent_Run_Events' ) ) {
            $timeline_types = array(
                'queued', 'claimed', 'model_call', 'tool_call', 'awaiting_confirmation',
                'confirmation_executed', 'subagent_started', 'subagent_complete',
                'subagent_group_started', 'subagent_group_complete', 'awaiting_subagents',
                'iteration_limit_summary', 'retry_scheduled', 'done', 'error',
            );
            foreach ( WPAgent_Run_Events::recent( $run_id, 60 ) as $event ) {
                $type = (string) ( $event['event_type'] ?? '' );
                $meta = json_decode( (string) ( $event['metadata'] ?? '' ), true );
                $meta = is_array( $meta ) ? $meta : array();

                if ( 'subagent_started' === $type && isset( $meta['child_run_id'] ) ) {
                    $labels[ (int) $meta['child_run_id'] ] = (string) ( $meta['label'] ?? '' );
                }

                if ( in_array( $type, $timeline_types, true ) ) {
                    $events_out[] = array(
                        'type'    => $type,
                        'message' => (string) ( $event['message'] ?? '' ),
                        'tool'    => isset( $meta['tool'] ) ? (string) $meta['tool'] : '',
                        'status'  => isset( $meta['status'] ) ? (string) $meta['status'] : '',
                        'time'    => (string) ( $event['created_at'] ?? '' ),
                    );
                }
            }
            $events_out = array_reverse( $events_out ); // chronological (oldest first)
        }

        $subagents = array();
        foreach ( WPAgent_Runs::children_of( $run_id ) as $child ) {
            $cid = (int) $child->id;
            $subagents[] = array(
                'run_id'  => $cid,
                'label'   => ( isset( $labels[ $cid ] ) && '' !== $labels[ $cid ] ) ? $labels[ $cid ] : ( 'Sub-agent #' . $cid ),
                'status'  => (string) $child->status,
                'summary' => mb_substr( (string) $child->result_summary, 0, 600 ),
            );
        }

        return array( 'events' => $events_out, 'subagents' => $subagents );
    }

    /**
     * Poll a queued run, advancing the agent loop by one iteration.
     *
     * Atomically claims the run, runs a single step (which may add assistant
     * and tool messages), and releases the claim. Returns the run status and
     * any new messages since the supplied cursor.
     */
    public function handle_chat_poll( WP_REST_Request $request ) {
        $run_id          = (int) $request->get_param( 'run_id' );
        $conversation_id = (int) $request->get_param( 'conversation_id' );
        $after           = (int) $request->get_param( 'after' );
        $user_id         = get_current_user_id();

        $run = WPAgent_Runs::get( $run_id );

        if ( ! $run ) {
            return new WP_REST_Response( array( 'error' => __( 'Run not found.', 'wp-agent' ) ), 404 );
        }

        if ( (int) $run->user_id !== $user_id || (int) $run->conversation_id !== $conversation_id ) {
            return new WP_REST_Response( array( 'error' => __( 'Forbidden.', 'wp-agent' ) ), 403 );
        }

        // Lightweight fallback nudge. The authoritative execution path is the
        // worker, so browser polling is no longer required for autonomy.
        if ( in_array( $run->status, array( 'queued', 'running', 'awaiting_subagents' ), true ) ) {
            WPAgent_Worker::poll_advance( $run_id );
            $run = WPAgent_Runs::get( $run_id );
        }

        $conversation = new WPAgent_Conversation();
        $status       = $run ? $run->status : 'error';
        $confirmation = 'awaiting_confirmation' === $status ? WPAgent_Confirmations::pending_for_run( $run_id ) : null;
        if ( 'awaiting_confirmation' === $status && ! $confirmation ) {
            WPAgent_Runs::set_error( $run_id, __( 'Confirmation expired before the action was approved.', 'wp-agent' ) );
            if ( class_exists( 'WPAgent_Run_Events' ) ) {
                WPAgent_Run_Events::add( $run_id, $user_id, 'confirmation_expired', 'Pending confirmation expired before approval.' );
            }
            $run    = WPAgent_Runs::get( $run_id );
            $status = $run ? $run->status : 'error';
        }

        return new WP_REST_Response( array(
            'status'             => $status,
            'done'               => in_array( $status, array( 'done', 'error', 'canceled' ), true ),
            'queue'              => WPAgent_Runs::queue_summary_for_run( $run_id ),
            'needs_confirmation' => (bool) $confirmation,
            'confirmation'       => $confirmation ? $this->confirmation_payload( $confirmation ) : null,
            'error'              => $run ? $run->error : null,
            'messages'           => $conversation->get_messages_for_display( $conversation_id, $after ),
            'activity'           => $this->run_activity_payload( $run_id ),
        ), 200 );
    }

    /**
     * Return the full message history for a conversation owned by the caller.
     */
    public function handle_chat_history( WP_REST_Request $request ) {
        $conversation_id = (int) $request->get_param( 'conversation_id' );
        $user_id         = get_current_user_id();

        if ( $this->conversation_owner( $conversation_id ) !== $user_id ) {
            return new WP_REST_Response( array( 'error' => __( 'Forbidden.', 'wp-agent' ) ), 403 );
        }

        $conversation = new WPAgent_Conversation();

        return new WP_REST_Response( array(
            'messages' => $conversation->get_messages_for_display( $conversation_id, 0 ),
        ), 200 );
    }

    /**
     * Return the newest unfinished run for a conversation, if any.
     */
    public function handle_chat_active_run( WP_REST_Request $request ) {
        $conversation_id = (int) $request->get_param( 'conversation_id' );
        $user_id         = get_current_user_id();

        if ( $this->conversation_owner( $conversation_id ) !== $user_id ) {
            return new WP_REST_Response( array( 'error' => __( 'Forbidden.', 'wp-agent' ) ), 403 );
        }

        $run = WPAgent_Runs::active_for_conversation( $conversation_id, $user_id );
        if ( $run && 'awaiting_confirmation' === $run->status && ! WPAgent_Confirmations::pending_for_run( (int) $run->id ) ) {
            WPAgent_Runs::set_error( (int) $run->id, __( 'Confirmation expired before the action was approved.', 'wp-agent' ) );
            if ( class_exists( 'WPAgent_Run_Events' ) ) {
                WPAgent_Run_Events::add( (int) $run->id, $user_id, 'confirmation_expired', 'Pending confirmation expired before approval.' );
            }
            $run = null;
        }

        return new WP_REST_Response( array(
            'run' => $run ? array(
                'id'         => (int) $run->id,
                'status'     => $run->status,
                'loop_count' => (int) $run->loop_count,
                'updated_at' => $run->updated_at,
            ) : null,
            'activity' => $run ? $this->run_activity_payload( (int) $run->id ) : array( 'events' => array(), 'subagents' => array() ),
            'queue' => $run
                ? WPAgent_Runs::queue_summary_for_run( (int) $run->id )
                : WPAgent_Runs::queue_summary_for_conversation( $conversation_id, $user_id ),
        ), 200 );
    }

    /**
     * Cancel a queued/running chat run owned by the current user.
     */
    public function handle_chat_run_cancel( WP_REST_Request $request ) {
        $run_id          = (int) $request['id'];
        $conversation_id = (int) $request->get_param( 'conversation_id' );
        $user_id         = get_current_user_id();

        $run = WPAgent_Runs::get( $run_id );
        if ( ! $run ) {
            return new WP_REST_Response( array( 'error' => __( 'Run not found.', 'wp-agent' ) ), 404 );
        }

        if ( (int) $run->user_id !== $user_id ) {
            return new WP_REST_Response( array( 'error' => __( 'Forbidden.', 'wp-agent' ) ), 403 );
        }

        if ( $conversation_id > 0 && (int) $run->conversation_id !== $conversation_id ) {
            return new WP_REST_Response( array( 'error' => __( 'Forbidden.', 'wp-agent' ) ), 403 );
        }

        $closed_confirmations = 0;
        if ( ! WPAgent_Runs::is_terminal_status( (string) $run->status ) ) {
            $closed_confirmations = class_exists( 'WPAgent_Confirmations' )
                ? WPAgent_Confirmations::reject_pending_for_run( $run_id, $user_id )
                : 0;
            $canceled = WPAgent_Runs::cancel_if_active( $run_id, __( 'Canceled by user.', 'wp-agent' ) );
            WPAgent_Runs::propagate_cancel( $run_id, __( 'Parent run canceled by user.', 'wp-agent' ) );
            if ( $canceled && class_exists( 'WPAgent_Run_Events' ) ) {
                WPAgent_Run_Events::add(
                    $run_id,
                    $user_id,
                    'canceled',
                    'Run canceled by user.',
                    array( 'closed_confirmations' => $closed_confirmations )
                );
            }
            if ( class_exists( 'WPAgent_Schedules' ) ) {
                WPAgent_Schedules::sync_by_run( $run_id );
            }
        }

        $run = WPAgent_Runs::get( $run_id );

        return new WP_REST_Response( array(
            'run_id'               => $run_id,
            'status'               => $run ? (string) $run->status : 'canceled',
            'done'                 => ! $run || WPAgent_Runs::is_terminal_status( (string) $run->status ),
            'queue'                => $run
                ? WPAgent_Runs::queue_summary_for_run( $run_id )
                : WPAgent_Runs::queue_summary_for_conversation( $conversation_id, $user_id ),
            'canceled'             => $run && 'canceled' === (string) $run->status,
            'closed_confirmations' => $closed_confirmations,
            'error'                => $run ? $run->error : null,
        ), 200 );
    }

    /**
     * Nudge the resident background agent after queueing chat work.
     *
     * @param int $run_id  Run ID.
     * @param int $user_id User ID.
     * @return array
     */
    private function wake_background_agent( $run_id, $user_id ) {
        if ( ! apply_filters( 'wp_agent_chat_send_wake_daemon', true, (int) $run_id, (int) $user_id ) ) {
            return array( 'attempted' => false, 'reason' => 'disabled_by_filter' );
        }

        if ( ! class_exists( 'WPAgent_Daemon' ) ) {
            return array( 'attempted' => false, 'reason' => 'daemon_unavailable' );
        }

        $result = WPAgent_Daemon::watchdog( array( 'source' => 'chat_send' ) );
        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'WPAgent_Run_Events' ) ) {
                WPAgent_Run_Events::add(
                    (int) $run_id,
                    (int) $user_id,
                    'daemon_wake_failed',
                    $result->get_error_message()
                );
            }

            return array(
                'attempted' => true,
                'ok'        => false,
                'error'     => $result->get_error_code(),
            );
        }

        return array(
            'attempted' => true,
            'ok'        => true,
            'action'    => (string) ( $result['action'] ?? '' ),
            'started'   => ! empty( $result['started'] ),
        );
    }

    /**
     * Approve a pending human confirmation.
     */
    public function handle_confirmation_approve( WP_REST_Request $request ) {
        $id      = (int) $request['id'];
        $user_id = get_current_user_id();

        $confirmation = WPAgent_Confirmations::decide( $id, $user_id, 'approved' );
        if ( is_wp_error( $confirmation ) ) {
            return new WP_REST_Response( array( 'error' => $confirmation->get_error_message() ), 400 );
        }

        $result = WPAgent::get_agent()->execute_confirmed_tool( $id );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 400 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'status'  => 'approved',
            'result'  => $result,
        ), 200 );
    }

    /**
     * Reject a pending human confirmation.
     */
    public function handle_confirmation_reject( WP_REST_Request $request ) {
        $id      = (int) $request['id'];
        $user_id = get_current_user_id();

        $confirmation = WPAgent_Confirmations::decide( $id, $user_id, 'rejected' );
        if ( is_wp_error( $confirmation ) ) {
            return new WP_REST_Response( array( 'error' => $confirmation->get_error_message() ), 400 );
        }

        $conversation = new WPAgent_Conversation();
        $result = array(
            'human_rejected' => true,
            'message'        => 'The requesting user rejected this action. Do not retry it unless the user asks again.',
        );
        $conversation->add_message( (int) $confirmation['conversation_id'], 'tool', wp_json_encode( $result ), array(
            'tool_results' => array( 'tool_call_id' => $confirmation['tool_call_id'] ),
        ) );

        WPAgent_Runs::set_queued( (int) $confirmation['run_id'] );
        if ( class_exists( 'WPAgent_Schedules' ) ) {
            WPAgent_Schedules::sync_by_run( (int) $confirmation['run_id'] );
        }
        WPAgent_Run_Events::add(
            (int) $confirmation['run_id'],
            (int) $confirmation['user_id'],
            'confirmation_rejected',
            'Human rejected pending action.',
            array( 'confirmation_id' => (int) $confirmation['id'], 'tool' => $confirmation['tool_name'] )
        );
        WPAgent::audit_log( (int) $confirmation['user_id'], 'confirmed_tool_rejected', array(
            'confirmation_id' => (int) $confirmation['id'],
            'tool'            => $confirmation['tool_name'],
            'decided_by'      => $user_id,
        ), $confirmation['channel'] );

        return new WP_REST_Response( array(
            'success' => true,
            'status'  => 'rejected',
        ), 200 );
    }

    /**
     * Public-safe confirmation payload for the owner UI.
     */
    private function confirmation_payload( $confirmation ) {
        $params = is_array( $confirmation['params'] ) ? $confirmation['params'] : array();
        $params = $this->redact_confirmation_params( $params );

        return array(
            'id'         => (int) $confirmation['id'],
            'run_id'     => (int) $confirmation['run_id'],
            'tool'       => $confirmation['tool_name'],
            'action'     => $confirmation['action'],
            'params'     => $params,
            'expires_at' => $confirmation['expires_at'],
        );
    }

    /**
     * Redact sensitive confirmation parameters before exposing them to the UI.
     */
    private function redact_confirmation_params( $params ) {
        $sensitive = array( 'password', 'pass', 'api_key', 'apikey', 'key', 'token', 'secret', 'authorization', 'content', 'body', 'code' );
        foreach ( $params as $key => $value ) {
            $normalized = strtolower( (string) $key );
            if ( in_array( $normalized, $sensitive, true ) || false !== strpos( $normalized, 'token' ) || false !== strpos( $normalized, 'secret' ) ) {
                $params[ $key ] = '[redacted]';
            } elseif ( is_array( $value ) ) {
                $params[ $key ] = $this->redact_confirmation_params( $value );
            } elseif ( is_string( $value ) && strlen( $value ) > 180 ) {
                $params[ $key ] = mb_substr( $value, 0, 180 ) . '...';
            }
        }
        return $params;
    }

    /**
     * Look up the owner (user_id) of a conversation.
     *
     * @param int $conversation_id
     * @return int Owner user ID, or 0 if the conversation does not exist.
     */
    protected function conversation_owner( $conversation_id ) {
        global $wpdb;

        $owner = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}wp_agent_conversations WHERE id = %d",
            $conversation_id
        ) );

        return (int) $owner;
    }

    /**
     * Return a stored secret as plaintext, preserving raw legacy values.
     *
     * @param string $stored Stored option value.
     * @return string
     */
    private function secret_plaintext( $stored ) {
        if ( empty( $stored ) ) {
            return '';
        }

        $decrypted = WPAgent::decrypt( $stored );
        return '' !== $decrypted ? $decrypted : (string) $stored;
    }

    /**
     * Decode strict hexadecimal input without warnings or type errors.
     *
     * @param string $value Hex-encoded value.
     * @return string|false
     */
    private function hex_to_binary( $value ) {
        $value = preg_replace( '/\s+/', '', (string) $value );
        if ( '' === $value || 0 !== strlen( $value ) % 2 || ! ctype_xdigit( $value ) ) {
            return false;
        }
        return hex2bin( $value );
    }

    /**
     * Handle Telegram webhook.
     */
    public function handle_telegram( WP_REST_Request $request ) {
        // Verify the secret token — reject ALL requests if secret is not configured.
        $secret = WPAgent::get_option( 'telegram_webhook_secret' );
        $header = $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );

        if ( empty( $secret ) || empty( $header ) || ! hash_equals( $secret, $header ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
        }

        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            return new WP_REST_Response( array( 'ok' => true ), 200 );
        }

        // Route to the Telegram channel handler.
        $telegram_token = WPAgent::get_option( 'telegram_bot_token' );
        if ( empty( $telegram_token ) ) {
            return new WP_REST_Response( array( 'error' => 'Telegram not configured' ), 500 );
        }

        $channel = new WPAgent_Channel_Telegram( WPAgent::decrypt( $telegram_token ) );
        $channel->handle_incoming( $request );

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    /**
     * Handle Slack webhook.
     */
    public function handle_slack( WP_REST_Request $request ) {
        // Verify Slack request signature.
        $signing_secret = WPAgent::get_option( 'slack_signing_secret' );
        if ( empty( $signing_secret ) ) {
            return new WP_REST_Response( array( 'error' => 'Slack not configured' ), 500 );
        }

        $timestamp = $request->get_header( 'X-Slack-Request-Timestamp' );
        $signature = $request->get_header( 'X-Slack-Signature' );

        // Reject requests older than 5 minutes to prevent replay attacks.
        if ( empty( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
        }

        $raw_body  = $request->get_body();
        $sig_base  = 'v0:' . $timestamp . ':' . $raw_body;
        $computed  = 'v0=' . hash_hmac( 'sha256', $sig_base, $this->secret_plaintext( $signing_secret ) );

        if ( empty( $signature ) || ! hash_equals( $computed, $signature ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
        }

        // URL verification challenge.
        $body = $request->get_json_params();
        if ( isset( $body['type'] ) && 'url_verification' === $body['type'] ) {
            return new WP_REST_Response( array( 'challenge' => sanitize_text_field( $body['challenge'] ?? '' ) ), 200 );
        }

        /**
         * Route to Slack channel handler.
         *
         * @see WPAgent_Channel_Slack
         */
        do_action( 'wp_agent_slack_webhook', $request );

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    /**
     * Handle Discord interactions.
     */
    public function handle_discord( WP_REST_Request $request ) {
        // Verify Discord Ed25519 signature.
        $public_key = WPAgent::get_option( 'discord_public_key' );
        if ( empty( $public_key ) ) {
            return new WP_REST_Response( array( 'error' => 'Discord not configured' ), 500 );
        }

        $signature = $request->get_header( 'X-Signature-Ed25519' );
        $timestamp = $request->get_header( 'X-Signature-Timestamp' );
        $raw_body  = $request->get_body();

        if ( empty( $signature ) || empty( $timestamp ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
        }

        $signature_binary = $this->hex_to_binary( $signature );
        $public_key_binary = $this->hex_to_binary( $public_key );
        if ( false === $signature_binary || false === $public_key_binary || 64 !== strlen( $signature_binary ) || 32 !== strlen( $public_key_binary ) ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
        }

        try {
            $verified = sodium_crypto_sign_verify_detached(
                $signature_binary,
                $timestamp . $raw_body,
                $public_key_binary
            );
        } catch ( \Throwable $e ) {
            $verified = false;
        }

        if ( ! $verified ) {
            return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
        }

        $body = $request->get_json_params();

        // Discord ping verification.
        if ( isset( $body['type'] ) && 1 === (int) $body['type'] ) {
            return new WP_REST_Response( array( 'type' => 1 ), 200 );
        }

        /**
         * Route to Discord channel handler.
         *
         * @see WPAgent_Channel_Discord
         */
        do_action( 'wp_agent_discord_webhook', $request );

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    /**
     * Handle pairing code submission.
     */
    public function handle_pair( WP_REST_Request $request ) {
        $code    = $request->get_param( 'code' );
        $user_id = get_current_user_id();

        $permissions = new WPAgent_Permissions();
        $result      = $permissions->complete_pairing( $user_id, $code );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 400 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'channel' => $result['channel'],
            'message' => sprintf( __( 'Successfully paired with %s!', 'wp-agent' ), ucfirst( $result['channel'] ) ),
        ), 200 );
    }

    /**
     * Handle usage data request.
     */
    public function handle_usage( WP_REST_Request $request ) {
        $period  = $request->get_param( 'period' );
        $user_id = get_current_user_id();
        $tracker = new WPAgent_Cost_Tracker();

        return new WP_REST_Response( array(
            'summary'   => $tracker->get_usage_summary( $user_id, $period ),
            'daily'     => $tracker->get_daily_breakdown( $user_id ),
            'by_model'  => $tracker->get_model_breakdown( $user_id, $period ),
        ), 200 );
    }

    /**
     * Handle conversations list request.
     */
    public function handle_conversations( WP_REST_Request $request ) {
        $user_id      = get_current_user_id();
        $search       = trim( (string) $request->get_param( 'search' ) );
        $conversation = new WPAgent_Conversation();
        // Exclude internal sub-agent delegation conversations so the user-facing
        // history shows only real conversations, not delegated background work.
        // When searching, reach into message content so the term matches text
        // produced inside conversations, not only their titles. A larger limit
        // is used for search so deep matches are not truncated by recency.
        $limit = '' !== $search ? 50 : 20;
        $list  = $conversation->list_conversations( $user_id, '', $limit, 0, true, $search );

        // Only surface user-facing channels in the Studio history. Internal
        // channels such as `wpcli` (scripts/tests) are operational, not chats.
        $hidden_channels = apply_filters( 'wp_agent_hidden_history_channels', array( 'wpcli' ) );
        if ( ! empty( $hidden_channels ) ) {
            $list = array_values( array_filter( $list, static function ( $row ) use ( $hidden_channels ) {
                return ! in_array( isset( $row['channel'] ) ? $row['channel'] : '', $hidden_channels, true );
            } ) );
        }

        // When searching, attach a short snippet of the matching message so the
        // user sees why a conversation matched, even if the term is not in the
        // title.
        if ( '' !== $search ) {
            $list = array_map( function ( $row ) use ( $search ) {
                $row['match_snippet'] = $this->conversation_match_snippet( (int) $row['id'], $search );
                return $row;
            }, $list );
        }

        return new WP_REST_Response( array( 'conversations' => $list ), 200 );
    }

    /**
     * Build a short, plain-text snippet around the first occurrence of the
     * search term inside a conversation's messages, for display in the history
     * search results.
     *
     * @param int    $conversation_id
     * @param string $search
     * @return string
     */
    private function conversation_match_snippet( $conversation_id, $search ) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $content = $wpdb->get_var( $wpdb->prepare(
            "SELECT content FROM {$wpdb->prefix}wp_agent_messages
             WHERE conversation_id = %d AND content LIKE %s
             ORDER BY created_at ASC LIMIT 1",
            $conversation_id,
            $like
        ) );
        if ( ! $content ) {
            return '';
        }
        // Strip stored attachment markers / control text and collapse whitespace.
        $text = wp_strip_all_tags( (string) $content );
        $text = preg_replace( '/\[wp-agent-media[^\]]*\]/', '', $text );
        // Strip common Markdown emphasis/heading markers so the snippet reads
        // as clean plain text instead of showing raw ** and ## symbols.
        $text = preg_replace( '/[*_`#>]+/', '', (string) $text );
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );

        $pos = function_exists( 'mb_stripos' ) ? mb_stripos( $text, $search ) : stripos( $text, $search );
        if ( false === $pos ) {
            return mb_substr( $text, 0, 140 );
        }
        $start   = max( 0, $pos - 40 );
        $snippet = mb_substr( $text, $start, 160 );
        if ( $start > 0 ) {
            $snippet = '…' . $snippet;
        }
        return $snippet;
    }

    /**
     * Search a GitHub repository for installable skills.
     */
    public function handle_skills_search_github( WP_REST_Request $request ) {
        $result = WPAgent_Skills::search_github( array(
            'query'      => (string) $request->get_param( 'query' ),
            'repository' => (string) $request->get_param( 'repository' ),
            'ref'        => (string) $request->get_param( 'ref' ),
        ) );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 400 );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * List all scheduled tasks.
     */
    public function handle_schedules_list( WP_REST_Request $request ) {
        return new WP_REST_Response( array( 'schedules' => WPAgent_Schedules::all() ), 200 );
    }

    /**
     * Run a scheduled task immediately.
     */
    public function handle_schedule_run( WP_REST_Request $request ) {
        $id     = (int) $request->get_param( 'id' );
        $result = WPAgent_Schedules::run( $id );

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Pause a scheduled task.
     */
    public function handle_schedule_pause( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        WPAgent_Schedules::set_status( $id, 'paused' );

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Resume a paused scheduled task.
     */
    public function handle_schedule_resume( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        WPAgent_Schedules::set_status( $id, 'active' );

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Delete a scheduled task.
     */
    public function handle_schedule_delete( WP_REST_Request $request ) {
        $id = (int) $request->get_param( 'id' );
        WPAgent_Schedules::delete( $id );

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Return the list of available AI models from the gateway.
     */
    public function handle_list_models( WP_REST_Request $request ) {
        $provider = WPAgent::get_ai_provider();
        if ( ! $provider->is_configured() ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Enter and save your API key first.', 'wp-agent' ) ), 200 );
        }
        if ( ! method_exists( $provider, 'list_models' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Provider does not support model listing.', 'wp-agent' ) ), 200 );
        }
        $models = $provider->list_models();
        if ( is_wp_error( $models ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => $models->get_error_message() ), 200 );
        }
        return new WP_REST_Response( array( 'success' => true, 'models' => array_values( $models ) ), 200 );
    }

    /**
     * Health check endpoint.
     */
    public function handle_health( WP_REST_Request $request ) {
        return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
    }

    /**
     * Approve a pending post via its moderation token.
     *
     * Authenticated solely by the unguessable token (no WP login required), so
     * the link works from an IM client. Publishes the post, syndicates it, and
     * renders a minimal HTML confirmation page directly to the browser.
     */
    public function handle_moderate_approve( WP_REST_Request $request ) {
        $token = (string) $request->get_param( 'token' );
        $res   = WPAgent_Moderation::approve( $token );

        if ( empty( $res['success'] ) ) {
            $this->render_moderation_page(
                __( 'Approval failed', 'wp-agent' ),
                isset( $res['error'] ) ? $res['error'] : __( 'Unknown error.', 'wp-agent' ),
                ''
            );
        }

        $permalink = isset( $res['permalink'] ) ? $res['permalink'] : '';

        $this->render_moderation_page(
            __( 'Post published', 'wp-agent' ),
            __( 'The post has been approved, published, and syndicated.', 'wp-agent' ),
            $permalink
        );
    }

    /**
     * Reject a pending post via its moderation token.
     *
     * Authenticated solely by the unguessable token. Leaves the post as a draft
     * and renders a minimal HTML confirmation page directly to the browser.
     */
    public function handle_moderate_reject( WP_REST_Request $request ) {
        $token = (string) $request->get_param( 'token' );
        $res   = WPAgent_Moderation::reject( $token );

        if ( empty( $res['success'] ) ) {
            $this->render_moderation_page(
                __( 'Rejection failed', 'wp-agent' ),
                isset( $res['error'] ) ? $res['error'] : __( 'Unknown error.', 'wp-agent' ),
                ''
            );
        }

        $this->render_moderation_page(
            __( 'Post rejected', 'wp-agent' ),
            __( 'The post has been rejected and left as a draft.', 'wp-agent' ),
            ''
        );
    }

    /**
     * Render a minimal HTML confirmation page for a moderation decision and exit.
     *
     * Produces a full, no-index HTML document for direct browser viewing (the
     * moderation links are opened from IM clients), so it echoes and exits
     * rather than returning a WP_REST_Response.
     *
     * @param string $heading   Page heading / title.
     * @param string $message   Human-readable status message.
     * @param string $permalink Optional permalink to surface as a link.
     */
    protected function render_moderation_page( $heading, $message, $permalink ) {
        $title = esc_html( $heading );

        $html  = '<!DOCTYPE html>' . "\n";
        $html .= '<html><head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<meta name="robots" content="noindex">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . $title . '</title>';
        $html .= '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;line-height:1.6;color:#1e1e1e;text-align:center;}a{color:#2271b1;}</style>';
        $html .= '</head><body>';
        $html .= '<h1>' . $title . '</h1>';
        $html .= '<p>' . esc_html( $message ) . '</p>';

        if ( ! empty( $permalink ) ) {
            $html .= '<p><a href="' . esc_url( $permalink ) . '">' . esc_html( $permalink ) . '</a></p>';
        }

        $html .= '</body></html>';

        header( 'Content-Type: text/html; charset=utf-8' );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All dynamic values escaped above.
        exit;
    }

    /**
     * Render a minimal HTML preview from Markdown source.
     *
     * Intentionally tiny: escapes the source first, then applies a few inline
     * transforms (headings, bold, italic, inline code) and wraps blank-line
     * separated blocks in paragraphs. Not a full Markdown parser.
     *
     * @param string $markdown Raw markdown content.
     * @return string Safe HTML.
     */
    protected function render_markdown_preview( $markdown ) {
        // Escape everything up front so no raw HTML can leak through.
        $escaped = esc_html( $markdown );

        $lines  = preg_split( '/\r\n|\r|\n/', $escaped );
        $out    = array();
        $in_par = false;

        $close_par = function() use ( &$out, &$in_par ) {
            if ( $in_par ) {
                $out[]  = '</p>';
                $in_par = false;
            }
        };

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            if ( '' === $trimmed ) {
                $close_par();
                continue;
            }

            // Headings (#, ##, ... ######).
            if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $m ) ) {
                $close_par();
                $level = strlen( $m[1] );
                $out[] = '<h' . $level . '>' . $this->markdown_inline( $m[2] ) . '</h' . $level . '>';
                continue;
            }

            if ( ! $in_par ) {
                $out[]  = '<p>';
                $in_par = true;
            } else {
                $out[] = '<br>';
            }
            $out[] = $this->markdown_inline( $trimmed );
        }

        $close_par();

        return implode( "\n", $out );
    }

    /**
     * Apply inline Markdown transforms to already-escaped text.
     *
     * @param string $text Already HTML-escaped text.
     * @return string
     */
    protected function markdown_inline( $text ) {
        // Inline code.
        $text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
        // Bold.
        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
        // Italic.
        $text = preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', $text );

        return $text;
    }

    /**
     * Handle adding a new MCP server.
     */
    public function handle_mcp_add_server( WP_REST_Request $request ) {
        $name        = $request->get_param( 'name' );
        $transport   = $request->get_param( 'transport' );
        $endpoint    = $request->get_param( 'endpoint' );
        $auth_type   = $request->get_param( 'auth_type' );
        $credentials = $request->get_param( 'credentials' );
        $command     = $request->get_param( 'command' );

        // Validate transport-specific requirements.
        if ( 'stdio' === $transport ) {
            if ( empty( $command ) ) {
                return new WP_REST_Response( array( 'error' => 'Command is required for stdio transport.' ), 400 );
            }
        } else {
            if ( empty( $endpoint ) ) {
                return new WP_REST_Response( array( 'error' => 'Endpoint URL is required for HTTP transport.' ), 400 );
            }
        }

        // Generate a slug from the name.
        $id = sanitize_key( str_replace( ' ', '-', strtolower( $name ) ) );
        if ( empty( $id ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid server name.' ), 400 );
        }

        $registry = new WPAgent_MCP_Registry();

        // Check for duplicates.
        if ( $registry->get_server( $id ) ) {
            return new WP_REST_Response( array( 'error' => 'Server with this ID already exists.' ), 400 );
        }

        $server = $registry->add_server( $id, $name, $endpoint, $auth_type, $credentials, $transport, $command );

        // Auto-discover tools on add.
        $tools = $registry->discover_tools( $id );
        $tool_count = is_wp_error( $tools ) ? 0 : count( $tools );
        $discover_error = is_wp_error( $tools ) ? $tools->get_error_message() : null;

        // Refresh server data after discovery.
        $server = $registry->get_server( $id );

        return new WP_REST_Response( array(
            'success'        => true,
            'server'         => array(
                'id'       => $server['id'],
                'name'     => $server['name'],
                'endpoint' => $server['endpoint'],
                'tools'    => $tool_count,
            ),
            'discover_error' => $discover_error,
        ), 201 );
    }

    /**
     * Handle removing an MCP server.
     */
    public function handle_mcp_remove_server( WP_REST_Request $request ) {
        $id       = sanitize_key( $request->get_param( 'id' ) );
        $registry = new WPAgent_MCP_Registry();

        if ( $registry->is_builtin( $id ) ) {
            return new WP_REST_Response( array( 'error' => 'Built-in servers cannot be removed.' ), 400 );
        }

        if ( ! $registry->remove_server( $id ) ) {
            return new WP_REST_Response( array( 'error' => 'Server not found.' ), 404 );
        }

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Handle toggling a built-in MCP server on or off.
     */
    public function handle_mcp_toggle( WP_REST_Request $request ) {
        $id      = sanitize_key( $request->get_param( 'id' ) );
        $enabled = (bool) $request->get_param( 'enabled' );

        $registry = new WPAgent_MCP_Registry();

        if ( ! $registry->is_builtin( $id ) ) {
            return new WP_REST_Response( array( 'error' => 'Only built-in servers can be toggled.' ), 400 );
        }

        $registry->toggle_builtin( $id, $enabled );

        // Auto-discover if enabling and no tools cached yet.
        if ( $enabled ) {
            $server = $registry->get_server( $id );
            if ( empty( $server['tools'] ) ) {
                $registry->discover_tools( $id );
            }
        }

        return new WP_REST_Response( array( 'success' => true, 'enabled' => $enabled ), 200 );
    }

    /**
     * Handle re-discovering tools from an MCP server.
     */
    public function handle_mcp_discover( WP_REST_Request $request ) {
        $id       = sanitize_key( $request->get_param( 'id' ) );
        $registry = new WPAgent_MCP_Registry();

        $tools = $registry->discover_tools( $id );
        if ( is_wp_error( $tools ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => $tools->get_error_message() ), 200 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'tools'   => count( $tools ),
            'names'   => array_map( function( $t ) { return $t['name'] ?? ''; }, $tools ),
        ), 200 );
    }

    /**
     * Permission callback: require authenticated admin user.
     */
    public function check_admin_auth( WP_REST_Request $request ) {
        return current_user_can( 'manage_options' );
    }
}
