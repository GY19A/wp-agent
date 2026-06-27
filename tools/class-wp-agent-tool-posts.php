<?php
/**
 * Posts tool — create, edit, list, schedule, and delete posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Posts extends WPAgent_Tool {

    public function get_name() {
        return 'manage_posts';
    }

    public function get_description() {
        return 'Create, edit, list, search, schedule, or delete WordPress posts. Use action parameter to specify the operation.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'create', 'edit', 'list', 'search', 'schedule', 'delete', 'get' ),
                    'description' => 'The operation to perform.',
                ),
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'Post ID (required for edit, delete, get).',
                ),
                'title' => array(
                    'type'        => 'string',
                    'description' => 'Post title.',
                ),
                'content' => array(
                    'type'        => 'string',
                    'description' => 'Post content (HTML).',
                ),
                'excerpt' => array(
                    'type'        => 'string',
                    'description' => 'Post excerpt.',
                ),
                'status' => array(
                    'type'        => 'string',
                    'enum'        => array( 'draft', 'publish', 'pending', 'private', 'future' ),
                    'description' => 'Post status. Default: draft.',
                ),
                'categories' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => 'Category names to assign.',
                ),
                'tags' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => 'Tag names to assign.',
                ),
                'source_urls' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => 'Public source URLs retained with the post for audit, citation, and rewrite provenance.',
                ),
                'source_notes' => array(
                    'type'        => 'string',
                    'description' => 'Short factual/source notes retained with the post.',
                ),
                'featured_image_id' => array(
                    'type'        => 'integer',
                    'description' => 'Media Library image attachment ID to set as the post featured image.',
                ),
                'meta_title' => array(
                    'type'        => 'string',
                    'description' => 'SEO meta title (≤ 60 chars). Saved with the post on create/edit — no separate manage_seo call needed.',
                ),
                'meta_description' => array(
                    'type'        => 'string',
                    'description' => 'SEO meta description, 120–155 characters. Saved with the post on create/edit.',
                ),
                'focus_keyword' => array(
                    'type'        => 'string',
                    'description' => 'SEO focus keyword. Saved with the post on create/edit.',
                ),
                'date' => array(
                    'type'        => 'string',
                    'description' => 'Publication date (ISO 8601 format, for scheduling).',
                ),
                'search' => array(
                    'type'        => 'string',
                    'description' => 'Search query (for list/search action).',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Number of posts to return (default 10, max 50).',
                ),
                'post_status_filter' => array(
                    'type'        => 'string',
                    'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private', 'trash' ),
                    'description' => 'Filter posts by status (for list action). Default: any.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        $action = $params['action'];

        switch ( $action ) {
            case 'create':
                return $this->create_post( $params );
            case 'edit':
                return $this->edit_post( $params );
            case 'list':
            case 'search':
                return $this->list_posts( $params );
            case 'schedule':
                return $this->schedule_post( $params );
            case 'delete':
                return $this->delete_post( $params );
            case 'get':
                return $this->get_post( $params );
            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    /** @var array Allowed post statuses. */
    private static $allowed_statuses = array( 'draft', 'publish', 'pending', 'private', 'future' );

    private function create_post( $params ) {
        $status = $params['status'] ?? 'draft';
        if ( ! in_array( $status, self::$allowed_statuses, true ) ) {
            $status = 'draft';
        }

        // Publish funnel: downgrade to pending when the actor lacks the publish cap.
        $note = '';
        if ( in_array( $status, array( 'publish', 'future' ), true ) && ! user_can( $this->user_id, 'publish_posts' ) ) {
            $status = 'pending';
            $note   = 'Publishing requires approval in author mode — use request_approval.';
        }

        $metadata = $this->prepare_content_metadata( $params );
        if ( is_wp_error( $metadata ) ) {
            return array( 'error' => $metadata->get_error_message() );
        }

        $post_data = array(
            'post_title'   => sanitize_text_field( $params['title'] ?? 'Untitled' ),
            'post_content' => wp_kses_post( $params['content'] ?? '' ),
            'post_excerpt' => sanitize_text_field( $params['excerpt'] ?? '' ),
            'post_status'  => $status,
            'post_type'    => 'post',
        );

        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            return array( 'error' => $post_id->get_error_message() );
        }

        $assigned_terms = $this->assign_terms( $post_id, $params );
        if ( is_wp_error( $assigned_terms ) ) {
            return array( 'error' => 'Post created, but term assignment failed: ' . $assigned_terms->get_error_message(), 'post_id' => $post_id );
        }
        $metadata_result = $this->apply_prepared_content_metadata( $post_id, $metadata );
        if ( is_wp_error( $metadata_result ) ) {
            return array( 'error' => 'Post created, but metadata assignment failed: ' . $metadata_result->get_error_message(), 'post_id' => $post_id );
        }

        $result = array(
            'success'     => true,
            'post_id'     => $post_id,
            'title'       => $post_data['post_title'],
            'status'      => $post_data['post_status'],
            'url'         => get_permalink( $post_id ),
            'preview_url' => ( 'publish' === $post_data['post_status'] ) ? get_permalink( $post_id ) : get_preview_post_link( $post_id ),
            'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
            'terms'       => $assigned_terms,
            'metadata'    => $metadata_result,
        );
        if ( '' !== $note ) {
            $result['note'] = $note;
        }
        $gate = $this->quality_gate( $post_id );
        if ( ! empty( $gate ) ) {
            $result['quality_gate'] = $gate;
        }
        return $result;
    }

    private function edit_post( $params ) {
        if ( empty( $params['post_id'] ) ) {
            return array( 'error' => 'post_id is required for edit action.' );
        }

        $post = get_post( $params['post_id'] );
        if ( ! $post ) {
            return array( 'error' => 'Post not found.' );
        }

        $metadata = $this->prepare_content_metadata( $params );
        if ( is_wp_error( $metadata ) ) {
            return array( 'error' => $metadata->get_error_message() );
        }

        $update = array( 'ID' => $params['post_id'] );

        if ( isset( $params['title'] ) )   $update['post_title']   = sanitize_text_field( $params['title'] );
        if ( isset( $params['content'] ) ) $update['post_content'] = wp_kses_post( $params['content'] );
        if ( isset( $params['excerpt'] ) ) $update['post_excerpt'] = sanitize_text_field( $params['excerpt'] );

        $note = '';
        if ( isset( $params['status'] ) ) {
            $new_status = in_array( $params['status'], self::$allowed_statuses, true ) ? $params['status'] : $post->post_status;
            // Publish funnel: downgrade to pending when the actor lacks the publish cap.
            if ( in_array( $new_status, array( 'publish', 'future' ), true ) && ! user_can( $this->user_id, 'publish_posts' ) ) {
                $new_status = 'pending';
                $note       = 'Publishing requires approval in author mode — use request_approval.';
            }
            $update['post_status'] = $new_status;
        }

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            return array( 'error' => $result->get_error_message() );
        }

        $assigned_terms = $this->assign_terms( $params['post_id'], $params );
        if ( is_wp_error( $assigned_terms ) ) {
            return array( 'error' => 'Post updated, but term assignment failed: ' . $assigned_terms->get_error_message(), 'post_id' => (int) $params['post_id'] );
        }
        $metadata_result = $this->apply_prepared_content_metadata( $params['post_id'], $metadata );
        if ( is_wp_error( $metadata_result ) ) {
            return array( 'error' => 'Post updated, but metadata assignment failed: ' . $metadata_result->get_error_message(), 'post_id' => (int) $params['post_id'] );
        }

        $current_status = get_post_status( $params['post_id'] );

        $response = array(
            'success'     => true,
            'post_id'     => $params['post_id'],
            'message'     => 'Post updated successfully.',
            'url'         => get_permalink( $params['post_id'] ),
            'preview_url' => ( 'publish' === $current_status ) ? get_permalink( $params['post_id'] ) : get_preview_post_link( $params['post_id'] ),
            'edit_url'    => get_edit_post_link( $params['post_id'], 'raw' ),
            'terms'       => $assigned_terms,
            'metadata'    => $metadata_result,
        );
        if ( '' !== $note ) {
            $response['note'] = $note;
        }
        $gate = $this->quality_gate( (int) $params['post_id'] );
        if ( ! empty( $gate ) ) {
            $response['quality_gate'] = $gate;
        }
        return $response;
    }

    /**
     * Run the automatic post-save quality gate. Returns issues the agent must
     * fix (short length, missing image, missing/short SEO) so professional SEO
     * articles are enforced in code, not only via the skill prompt.
     *
     * @param int $post_id
     * @return array
     */
    private function quality_gate( $post_id ) {
        if ( ! class_exists( 'WPAgent_Tool_Content_Quality' ) ) {
            return array();
        }
        // Only gate full articles (posts), not pages or revisions.
        if ( 'post' !== get_post_type( $post_id ) ) {
            return array();
        }

        // Auto-cover: guarantee every article has a cover image even if the
        // model skipped generate_image. We generate one only when the post has
        // no featured image and no in-body image, the AI key is configured, and
        // it is within budget. Failure is non-fatal — the gate still reports.
        $auto = $this->maybe_autogenerate_cover( $post_id );

        // Guarantee a search-friendly meta description length even when the
        // model set a short one in an earlier edit and did not resend it. This
        // backfills from the excerpt/body so the SEO description stays 120–160.
        $this->ensure_stored_meta_description_length( $post_id );

        $gate = WPAgent_Tool_Content_Quality::gate_for_post( $post_id );
        if ( ! empty( $auto ) ) {
            $gate['auto_cover'] = $auto;
        }
        if ( empty( $gate ) || empty( $gate['must_fix'] ) ) {
            return $gate; // passing gate still returned (status/score) but no must_fix.
        }
        $gate['action_required'] = 'This article does not yet meet the quality bar. Fix the items in must_fix (e.g. expand length, generate an image, set/lengthen SEO meta via manage_seo), then edit the post again.';
        return $gate;
    }

    /**
     * Guarantee a cover image: if an article has neither a featured image nor an
     * in-body image, generate one via the image tool and set it as featured. So
     * every article ends up illustrated even when the model skips generate_image.
     *
     * @param int $post_id
     * @return array|null { generated:bool, attachment_id, reason }
     */
    private function maybe_autogenerate_cover( $post_id ) {
        // Opt-out hook/constant for environments that should not auto-spend on images.
        if ( defined( 'WP_AGENT_DISABLE_AUTO_COVER' ) && WP_AGENT_DISABLE_AUTO_COVER ) {
            return null;
        }
        if ( ! apply_filters( 'wp_agent_auto_cover_enabled', true, $post_id ) ) {
            return null;
        }
        if ( ! class_exists( 'WPAgent_Tool_Images' ) ) {
            return null;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return null;
        }

        $has_featured  = (int) get_post_thumbnail_id( $post_id ) > 0;
        $has_body_image = (bool) preg_match( '#<img\b#i', (string) $post->post_content );

        // Already fully illustrated (featured + in-body image) — nothing to do.
        if ( $has_featured && $has_body_image ) {
            return null;
        }

        // Need a configured AI key to generate (only when we must create one).
        $api_configured = '' !== (string) WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' ) );

        $title   = (string) $post->post_title;
        $excerpt = (string) $post->post_excerpt;

        // Resolve an attachment to use: an existing featured image if present,
        // otherwise generate a new cover image.
        $attachment_id = (int) get_post_thumbnail_id( $post_id );
        $generated     = false;
        if ( $attachment_id <= 0 ) {
            if ( ! $api_configured ) {
                return array( 'generated' => false, 'reason' => 'ai_key_not_configured' );
            }
            $prompt = $this->build_cover_image_prompt( $post );

            $images = new WPAgent_Tool_Images();
            $images->set_context( $this->user_id, $this->channel, $this->conversation_id, $this->requester_id, $this->run_id );
            $result = $images->execute( array(
                'prompt'   => $prompt,
                'title'    => $title !== '' ? $title : 'Article cover',
                'alt_text' => $title !== '' ? $title : 'Article cover image',
                'size'     => '1792x1024',
            ) );

            if ( empty( $result['success'] ) || empty( $result['attachment_id'] ) ) {
                return array(
                    'generated' => false,
                    'reason'    => isset( $result['error'] ) ? sanitize_text_field( $result['error'] ) : 'image_generation_failed',
                );
            }
            $attachment_id = (int) $result['attachment_id'];
            $generated     = true;
        }

        // Ensure a featured image is set.
        $set_featured = false;
        if ( ! $has_featured && $attachment_id > 0 ) {
            if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
                update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
            }
            $set_featured = true;
        }

        // Guarantee at least one in-body image so the article is visually
        // illustrated in its content, not only via the featured-image slot.
        $inserted_in_body = false;
        if ( ! $has_body_image && $attachment_id > 0 ) {
            $inserted_in_body = $this->prepend_body_image( $post_id, $attachment_id );
        }

        if ( ! $set_featured && ! $inserted_in_body && ! $generated ) {
            return null;
        }

        return array(
            'generated'        => $generated,
            'attachment_id'    => $attachment_id,
            'url'              => wp_get_attachment_url( $attachment_id ),
            'set_featured'     => $set_featured,
            'inserted_in_body' => $inserted_in_body,
            'note'             => 'An illustration was ensured for this article (featured image and/or an in-body image) because it had none.',
        );
    }

    /**
     * Build a topic-specific cover image prompt so the auto-generated cover is
     * clearly about the article's subject (not a generic abstract graphic). It
     * combines the title, focus keyword, excerpt, and the opening of the body to
     * describe the actual subject, and asks for a clean editorial illustration
     * style suitable as a blog cover.
     *
     * @param WP_Post $post
     * @return string
     */
    private function build_cover_image_prompt( $post ) {
        $title   = trim( (string) $post->post_title );
        $excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
        $keyword = trim( (string) get_post_meta( $post->ID, '_wp_agent_focus_keyword', true ) );
        if ( '' === $keyword ) {
            $keyword = trim( (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true ) );
        }

        // First ~200 characters of real body prose to anchor the subject.
        $body = trim( wp_strip_all_tags( (string) $post->post_content ) );
        $body = preg_replace( '/\s+/u', ' ', (string) $body );
        $body_opening = '' !== $body ? mb_substr( $body, 0, 220 ) : '';

        // Assemble a concrete subject description from what we actually know.
        $subject_parts = array();
        if ( '' !== $title ) {
            $subject_parts[] = 'titled "' . $title . '"';
        }
        if ( '' !== $keyword ) {
            $subject_parts[] = 'main subject/keyword: ' . $keyword;
        }
        if ( '' !== $excerpt ) {
            $subject_parts[] = 'summary: ' . $excerpt;
        } elseif ( '' !== $body_opening ) {
            $subject_parts[] = 'opening: ' . $body_opening;
        }
        $subject = implode( '. ', $subject_parts );

        return 'Create a cover image that is specifically and recognizably about this article '
            . $subject . '. '
            . 'The image MUST depict the actual subject matter and its real-world context — show concrete, '
            . 'relevant scenes, objects, settings, or people tied to the topic, not a generic abstract graphic. '
            . 'Style: clean, modern editorial illustration with a cohesive professional color palette, '
            . 'good depth and composition, soft realistic lighting, magazine-cover quality. '
            . 'Landscape framing, balanced composition, no text, no watermarks, no logos. '
            . 'It should read at a glance as an on-topic, high-quality featured image for this specific article.';
    }

    /**
     * Insert an image at the top of the post body so the article has at least
     * one in-body image. Uses the attachment's alt text and caption when set.
     *
     * @param int $post_id
     * @param int $attachment_id
     * @return bool True when the body was updated.
     */
    private function prepend_body_image( $post_id, $attachment_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }

        // Guarantee alt + caption on the attachment for accessibility/SEO.
        $alt = '';
        $caption = '';
        if ( class_exists( 'WPAgent_Media_Meta' ) ) {
            $meta    = WPAgent_Media_Meta::ensure( $attachment_id, '', '', (string) $post->post_title );
            $alt     = $meta['alt'];
            $caption = $meta['caption'];
        } else {
            $alt     = (string) get_post_meta( $attachment_id, '_wp_attachment_alt_text', true );
            $caption = (string) get_post( $attachment_id )->post_excerpt;
        }

        $img_block = $this->build_image_block( $attachment_id, $alt, $caption );
        if ( '' === $img_block ) {
            return false;
        }

        $new_content = $img_block . "\n\n" . (string) $post->post_content;
        $result = wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $new_content,
        ), true );

        return ! is_wp_error( $result );
    }

    /**
     * Build a Gutenberg image block (with caption) for an attachment.
     *
     * @param int    $attachment_id
     * @param string $alt
     * @param string $caption
     * @return string
     */
    private function build_image_block( $attachment_id, $alt, $caption ) {
        $url = wp_get_attachment_image_url( $attachment_id, 'large' );
        if ( ! $url ) {
            $url = wp_get_attachment_url( $attachment_id );
        }
        if ( ! $url ) {
            return '';
        }

        $alt_attr     = esc_attr( $alt );
        $caption_html = '' !== trim( (string) $caption )
            ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>'
            : '';

        return sprintf(
            '<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
            . '<figure class="wp-block-image size-large"><img src="%2$s" alt="%3$s" class="wp-image-%1$d"/>%4$s</figure>' . "\n"
            . '<!-- /wp:image -->',
            (int) $attachment_id,
            esc_url( $url ),
            $alt_attr,
            $caption_html
        );
    }

    private function list_posts( $params ) {
        $limit = min( (int) ( $params['limit'] ?? 10 ), 50 );

        $args = array(
            'post_type'      => 'post',
            'posts_per_page' => $limit,
            'post_status'    => $params['post_status_filter'] ?? 'any',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        if ( ! empty( $params['search'] ) ) {
            $args['s'] = $params['search'];
        }

        $query = new WP_Query( $args );
        $posts = array();

        foreach ( $query->posts as $post ) {
            $posts[] = array(
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'status'     => $post->post_status,
                'date'       => $post->post_date,
                'excerpt'    => wp_trim_words( $post->post_content, 25 ),
                'url'        => get_permalink( $post ),
                'categories' => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
            );
        }

        return array(
            'total' => $query->found_posts,
            'count' => count( $posts ),
            'posts' => $posts,
        );
    }

    private function schedule_post( $params ) {
        if ( empty( $params['date'] ) ) {
            return array( 'error' => 'date is required for schedule action (ISO 8601 format).' );
        }

        // Validate date to prevent unexpected behavior.
        $timestamp = strtotime( sanitize_text_field( $params['date'] ) );
        if ( false === $timestamp || $timestamp <= 0 ) {
            return array( 'error' => 'Invalid date format. Please use ISO 8601 (e.g., 2025-12-25T10:00:00).' );
        }

        // Ensure the date is in the future.
        if ( $timestamp <= time() ) {
            return array( 'error' => 'Scheduled date must be in the future.' );
        }

        // Publish funnel: scheduling implies a future publish, so it needs the publish cap.
        $note   = '';
        $status = 'future';
        if ( ! user_can( $this->user_id, 'publish_posts' ) ) {
            $status = 'pending';
            $note   = 'Publishing requires approval in author mode — use request_approval.';
        }

        $metadata = $this->prepare_content_metadata( $params );
        if ( is_wp_error( $metadata ) ) {
            return array( 'error' => $metadata->get_error_message() );
        }

        $post_data = array(
            'post_title'   => sanitize_text_field( $params['title'] ?? 'Untitled' ),
            'post_content' => wp_kses_post( $params['content'] ?? '' ),
            'post_status'  => $status,
            'post_date'    => gmdate( 'Y-m-d H:i:s', $timestamp ),
            'post_type'    => 'post',
        );

        $post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $post_id ) ) {
            return array( 'error' => $post_id->get_error_message() );
        }

        $assigned_terms = $this->assign_terms( $post_id, $params );
        if ( is_wp_error( $assigned_terms ) ) {
            return array( 'error' => 'Post scheduled, but term assignment failed: ' . $assigned_terms->get_error_message(), 'post_id' => $post_id );
        }
        $metadata_result = $this->apply_prepared_content_metadata( $post_id, $metadata );
        if ( is_wp_error( $metadata_result ) ) {
            return array( 'error' => 'Post scheduled, but metadata assignment failed: ' . $metadata_result->get_error_message(), 'post_id' => $post_id );
        }

        $result = array(
            'success'        => true,
            'post_id'        => $post_id,
            'title'          => $post_data['post_title'],
            'status'         => $post_data['post_status'],
            'scheduled_date' => $post_data['post_date'],
            'url'            => get_permalink( $post_id ),
            'preview_url'    => ( 'publish' === $post_data['post_status'] ) ? get_permalink( $post_id ) : get_preview_post_link( $post_id ),
            'edit_url'       => get_edit_post_link( $post_id, 'raw' ),
            'terms'          => $assigned_terms,
            'metadata'       => $metadata_result,
        );
        if ( '' !== $note ) {
            $result['note'] = $note;
        }
        return $result;
    }

    private function delete_post( $params ) {
        if ( empty( $params['post_id'] ) ) {
            return array( 'error' => 'post_id is required for delete action.' );
        }

        $post = get_post( $params['post_id'] );
        if ( ! $post ) {
            return array( 'error' => 'Post not found.' );
        }

        // Move to trash (not permanent delete).
        $result = wp_trash_post( $params['post_id'] );
        if ( ! $result ) {
            return array( 'error' => 'Failed to delete post.' );
        }

        return array(
            'success' => true,
            'message' => sprintf( 'Post "%s" moved to trash.', $post->post_title ),
        );
    }

    private function get_post( $params ) {
        if ( empty( $params['post_id'] ) ) {
            return array( 'error' => 'post_id is required for get action.' );
        }

        $post = get_post( $params['post_id'] );
        if ( ! $post ) {
            return array( 'error' => 'Post not found.' );
        }

        return array(
            'id'         => $post->ID,
            'title'      => $post->post_title,
            'content'    => $post->post_content,
            'excerpt'    => $post->post_excerpt,
            'status'     => $post->post_status,
            'date'       => $post->post_date,
            'modified'   => $post->post_modified,
            'author'     => get_the_author_meta( 'display_name', $post->post_author ),
            'url'        => get_permalink( $post ),
            'categories' => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
            'tags'       => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
            'metadata'   => $this->current_content_metadata( $post->ID ),
        );
    }

    private function assign_terms( $post_id, $params ) {
        $assigned = array(
            'categories' => wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ),
            'tags'       => wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ),
        );

        if ( ! empty( $params['categories'] ) && is_array( $params['categories'] ) ) {
            $category_ids = $this->resolve_category_ids( $params['categories'] );
            if ( is_wp_error( $category_ids ) ) {
                return $category_ids;
            }
            $result = wp_set_post_terms( $post_id, $category_ids, 'category', false );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $assigned['categories'] = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
        }

        if ( ! empty( $params['tags'] ) && is_array( $params['tags'] ) ) {
            $tags = array();
            foreach ( $params['tags'] as $tag ) {
                $tag = sanitize_text_field( (string) $tag );
                if ( '' !== $tag ) {
                    $tags[] = $tag;
                }
            }
            $result = wp_set_post_tags( $post_id, array_values( array_unique( $tags ) ), false );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $assigned['tags'] = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
        }

        return $assigned;
    }

    private function resolve_category_ids( $categories ) {
        $ids = array();

        foreach ( $categories as $category ) {
            if ( is_int( $category ) || ( is_string( $category ) && preg_match( '/^\d+$/', trim( $category ) ) ) ) {
                $term = get_term( (int) $category, 'category' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $ids[] = (int) $term->term_id;
                    continue;
                }
            }

            $name = sanitize_text_field( (string) $category );
            if ( '' === $name ) {
                continue;
            }

            $existing = term_exists( $name, 'category' );
            if ( $existing ) {
                $ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
                continue;
            }

            $created = wp_insert_term( $name, 'category' );
            if ( is_wp_error( $created ) ) {
                return $created;
            }
            $ids[] = (int) $created['term_id'];
        }

        return array_values( array_unique( array_filter( $ids ) ) );
    }

    private function prepare_content_metadata( $params ) {
        $prepared = array(
            'source_urls_set'       => false,
            'source_urls'           => array(),
            'source_notes_set'      => false,
            'source_notes'          => '',
            'featured_image_set'    => false,
            'featured_image_id'     => 0,
            'seo_set'               => false,
            'seo'                   => array(),
        );

        // Accept SEO fields directly on create/edit so the agent can save SEO in
        // one step without a separate, order-dependent manage_seo call.
        $seo = array();
        if ( array_key_exists( 'meta_title', $params ) ) {
            $seo['meta_title'] = sanitize_text_field( (string) $params['meta_title'] );
        }
        if ( array_key_exists( 'meta_description', $params ) ) {
            $seo['meta_description'] = sanitize_textarea_field( (string) $params['meta_description'] );
        }
        if ( array_key_exists( 'focus_keyword', $params ) ) {
            $seo['focus_keyword'] = sanitize_text_field( (string) $params['focus_keyword'] );
        }
        if ( ! empty( $seo ) ) {
            $prepared['seo_set'] = true;
            $prepared['seo']     = $seo;
        }

        if ( array_key_exists( 'source_urls', $params ) ) {
            $urls = $this->normalize_source_urls( $params['source_urls'] );
            if ( is_wp_error( $urls ) ) {
                return $urls;
            }
            $prepared['source_urls_set'] = true;
            $prepared['source_urls']     = $urls;
        }

        if ( array_key_exists( 'source_notes', $params ) ) {
            $prepared['source_notes_set'] = true;
            $prepared['source_notes']     = sanitize_textarea_field( (string) $params['source_notes'] );
        }

        if ( array_key_exists( 'featured_image_id', $params ) ) {
            $image_id = (int) $params['featured_image_id'];
            if ( $image_id > 0 ) {
                $attachment = get_post( $image_id );
                $mime       = $attachment ? (string) get_post_mime_type( $image_id ) : '';
                if ( ! $attachment || 'attachment' !== $attachment->post_type || 0 !== strpos( $mime, 'image/' ) ) {
                    return new WP_Error( 'wp_agent_featured_image', 'featured_image_id must be an image attachment.' );
                }
            }

            $prepared['featured_image_set'] = true;
            $prepared['featured_image_id']  = $image_id;
        }

        return $prepared;
    }

    private function apply_prepared_content_metadata( $post_id, $metadata ) {
        if ( ! is_array( $metadata ) ) {
            return $this->current_content_metadata( $post_id );
        }

        if ( ! empty( $metadata['source_urls_set'] ) ) {
            if ( empty( $metadata['source_urls'] ) ) {
                delete_post_meta( $post_id, '_wp_agent_source_urls' );
            } else {
                update_post_meta( $post_id, '_wp_agent_source_urls', wp_json_encode( $metadata['source_urls'] ) );
            }
        }

        if ( ! empty( $metadata['source_notes_set'] ) ) {
            if ( '' === $metadata['source_notes'] ) {
                delete_post_meta( $post_id, '_wp_agent_source_notes' );
            } else {
                update_post_meta( $post_id, '_wp_agent_source_notes', $metadata['source_notes'] );
            }
        }

        if ( ! empty( $metadata['featured_image_set'] ) ) {
            $image_id = (int) $metadata['featured_image_id'];
            if ( $image_id <= 0 ) {
                delete_post_thumbnail( $post_id );
            } else {
                if ( ! set_post_thumbnail( $post_id, $image_id ) ) {
                    update_post_meta( $post_id, '_thumbnail_id', $image_id );
                }
            }
        }

        if ( ! empty( $metadata['seo_set'] ) ) {
            $seo   = $metadata['seo'];
            $yoast = defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
            if ( array_key_exists( 'meta_title', $seo ) ) {
                update_post_meta( $post_id, '_wp_agent_meta_title', $seo['meta_title'] );
                if ( $yoast ) {
                    update_post_meta( $post_id, '_yoast_wpseo_title', $seo['meta_title'] );
                }
            }
            if ( array_key_exists( 'meta_description', $seo ) ) {
                $desc = $this->ensure_meta_description_length( (string) $seo['meta_description'], $post_id );
                update_post_meta( $post_id, '_wp_agent_meta_description', $desc );
                if ( $yoast ) {
                    update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
                }
            }
            if ( array_key_exists( 'focus_keyword', $seo ) ) {
                update_post_meta( $post_id, '_wp_agent_focus_keyword', $seo['focus_keyword'] );
                if ( $yoast ) {
                    update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo['focus_keyword'] );
                }
            }
        }

        return $this->current_content_metadata( $post_id );
    }

    /**
     * Backfill a too-short stored SEO meta description from the post's excerpt
     * and body so it reaches the 120–160 character window. Used by the quality
     * gate so the description stays compliant even when the model set a short
     * one in an earlier edit and did not resend it on a later edit.
     *
     * @param int $post_id
     * @return void
     */
    private function ensure_stored_meta_description_length( $post_id ) {
        $current = (string) get_post_meta( $post_id, '_wp_agent_meta_description', true );
        // Only repair a non-empty but short description; leave empty ones to the
        // agent/gate to author so we don't fabricate SEO from nothing.
        if ( '' === trim( $current ) || $this->str_len( $current ) >= 120 ) {
            return;
        }
        $fixed = $this->ensure_meta_description_length( $current, $post_id );
        if ( $fixed !== $current && '' !== $fixed ) {
            update_post_meta( $post_id, '_wp_agent_meta_description', $fixed );
            if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
                update_post_meta( $post_id, '_yoast_wpseo_metadesc', $fixed );
            }
        }
    }

    /**
     * Guarantee a search-friendly meta description length. When the supplied
     * description is non-empty but too short (< 120 chars), extend it with
     * sentence-aware text drawn from the post excerpt and body so it lands in
     * the SEO-friendly 120–160 character window. CJK characters count as one.
     *
     * @param string $description Agent-supplied meta description.
     * @param int    $post_id
     * @return string
     */
    private function ensure_meta_description_length( $description, $post_id ) {
        $min = 120;
        $max = 160;
        $description = trim( (string) $description );

        // Empty descriptions are left to the quality gate / agent to fill; we
        // only repair short-but-present descriptions here.
        if ( '' === $description ) {
            return $description;
        }
        if ( $this->str_len( $description ) >= $min ) {
            return $this->clip_to_length( $description, $max );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return $this->clip_to_length( $description, $max );
        }

        // Build a pool of supplementary text: excerpt first, then body prose.
        $pool = trim( (string) $post->post_excerpt );
        $body = trim( wp_strip_all_tags( (string) $post->post_content ) );
        $body = preg_replace( '/\s+/u', ' ', $body );
        if ( '' !== $body ) {
            $pool = '' !== $pool ? $pool . ' ' . $body : $body;
        }
        $pool = preg_replace( '/\s+/u', ' ', (string) $pool );

        // Append sentence fragments from the pool until we reach the minimum,
        // avoiding duplicating text the description already contains.
        $sentences = preg_split( '/(?<=[。！？.!?])\s*/u', (string) $pool, -1, PREG_SPLIT_NO_EMPTY );
        $result = $description;
        foreach ( (array) $sentences as $sentence ) {
            $sentence = trim( $sentence );
            if ( '' === $sentence ) {
                continue;
            }
            if ( false !== mb_stripos( $result, $sentence ) ) {
                continue; // already covered
            }
            $result = trim( $result . ' ' . $sentence );
            if ( $this->str_len( $result ) >= $min ) {
                break;
            }
        }

        // If sentence-aware joining still falls short (e.g. very repetitive or
        // sparse prose), fall back to appending raw body text so the SEO
        // description reliably reaches the minimum length.
        if ( $this->str_len( $result ) < $min && '' !== $body ) {
            $needed = $min - $this->str_len( $result );
            $extra  = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, $needed + 40 ) : substr( $body, 0, $needed + 40 );
            $result = trim( $result . ' ' . $extra );
        }

        return $this->clip_to_length( trim( $result ), $max );
    }

    private function str_len( $s ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s ) : strlen( (string) $s );
    }

    /**
     * Clip text to a maximum character length on a word/character boundary,
     * trimming trailing punctuation/space.
     */
    private function clip_to_length( $s, $max ) {
        $s = trim( (string) $s );
        if ( $this->str_len( $s ) <= $max ) {
            return $s;
        }
        $clipped = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
        // Avoid cutting in the middle of a Latin word when possible.
        if ( preg_match( '/[A-Za-z0-9]$/', $clipped ) ) {
            $space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $clipped, ' ' ) : strrpos( $clipped, ' ' );
            if ( false !== $space && $space > (int) ( $max * 0.6 ) ) {
                $clipped = function_exists( 'mb_substr' ) ? mb_substr( $clipped, 0, $space ) : substr( $clipped, 0, $space );
            }
        }
        // UTF-8-safe trailing trim: never use byte-wise rtrim() with multibyte
        // punctuation, which can split a multibyte character and corrupt UTF-8.
        $clipped = preg_replace( '/[\s,，、;；]+$/u', '', $clipped );
        return null === $clipped ? '' : $clipped;
    }

    private function current_content_metadata( $post_id ) {
        $image_id = (int) get_post_thumbnail_id( $post_id );
        return array(
            'source_urls'       => $this->stored_source_urls( $post_id ),
            'source_notes'      => (string) get_post_meta( $post_id, '_wp_agent_source_notes', true ),
            'featured_image_id' => $image_id,
            'featured_image_url' => $image_id > 0 ? wp_get_attachment_url( $image_id ) : '',
        );
    }

    private function normalize_source_urls( $value ) {
        if ( ! is_array( $value ) ) {
            return new WP_Error( 'wp_agent_source_urls', 'source_urls must be an array of URLs.' );
        }

        $urls = array();
        foreach ( $value as $url ) {
            $raw = trim( (string) $url );
            if ( '' === $raw ) {
                continue;
            }
            $url = esc_url_raw( $raw );
            if ( '' === $url ) {
                return new WP_Error( 'wp_agent_source_urls', 'source_urls may contain only public absolute http(s) URLs.' );
            }
            $valid = WPAgent_URL_Safety::validate_public_http_url( $url, 'source URL' );
            if ( is_wp_error( $valid ) ) {
                return new WP_Error( 'wp_agent_source_urls', $valid->get_error_message() );
            }
            $urls[] = $url;
        }

        return array_values( array_unique( array_slice( $urls, 0, 20 ) ) );
    }

    private function stored_source_urls( $post_id ) {
        $raw = get_post_meta( $post_id, '_wp_agent_source_urls', true );
        if ( '' === $raw ) {
            return array();
        }

        $decoded = json_decode( (string) $raw, true );
        return is_array( $decoded ) ? array_values( array_filter( array_map( 'esc_url_raw', $decoded ) ) ) : array();
    }
}
