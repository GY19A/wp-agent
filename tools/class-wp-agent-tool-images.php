<?php
/**
 * Image generation tool — create media-library images through the configured AI gateway.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Images extends WPAgent_Tool {

    const MAX_IMAGE_BYTES = 8388608;

    public function get_name() {
        return 'generate_image';
    }

    public function get_description() {
        return 'Generate an image asset from a prompt through the configured OpenAI-compatible AI gateway, import it into the WordPress media library, and return attachment_id and URL for use in posts/pages.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'prompt' => array(
                    'type'        => 'string',
                    'description' => 'Detailed image prompt. Include subject, style, composition, and intended content use.',
                ),
                'title' => array(
                    'type'        => 'string',
                    'description' => 'Media title.',
                ),
                'alt_text' => array(
                    'type'        => 'string',
                    'description' => 'Accessible alt text for the generated image.',
                ),
                'caption' => array(
                    'type'        => 'string',
                    'description' => 'Caption shown under the image. A useful default is derived from the title if omitted.',
                ),
                'size' => array(
                    'type'        => 'string',
                    'enum'        => array( '1024x1024', '1024x1792', '1792x1024' ),
                    'description' => 'Image size. Defaults to 1024x1024.',
                ),
            ),
            'required' => array( 'prompt' ),
        );
    }

    public function get_required_capability() {
        return 'upload_files';
    }

    public function execute( array $params ) {
        $prompt = trim( (string) ( $params['prompt'] ?? '' ) );
        if ( '' === $prompt ) {
            return array( 'error' => 'prompt is required.' );
        }

        $api_key = WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' ) );
        if ( '' === $api_key ) {
            return array( 'error' => 'AI gateway API key is not configured.' );
        }

        $request_body = array(
            'prompt'          => $prompt,
            'n'               => 1,
            'size'            => $params['size'] ?? '1024x1024',
            'response_format' => 'b64_json',
        );

        $image_model = trim( (string) WPAgent::get_option( 'image_model', '' ) );
        if ( '' !== $image_model ) {
            $request_body['model'] = $image_model;
        }

        $tracker = new WPAgent_Cost_Tracker();
        $estimated_cost = $tracker->estimate_image_cost( $image_model, (string) $request_body['size'], 1 );
        $budget_check = $tracker->assert_within_budget( $this->owner_id(), $estimated_cost );
        if ( is_wp_error( $budget_check ) ) {
            return array( 'error' => $budget_check->get_error_message() );
        }

        $response = wp_remote_post( WPAgent_AI_Meowl::base_url() . '/images/generations', array(
            'timeout' => 90,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( $request_body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => 'Image generation failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 400 ) {
            return array( 'error' => $body['error']['message'] ?? 'Image generation failed.' );
        }

        $item = $body['data'][0] ?? array();
        if ( ! empty( $item['b64_json'] ) ) {
            $image_bytes = base64_decode( $item['b64_json'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        } elseif ( ! empty( $item['url'] ) ) {
            $image_bytes = $this->download_generated_url( $item['url'] );
        } else {
            return array( 'error' => 'Image response did not include image data.' );
        }

        if ( empty( $image_bytes ) ) {
            return array( 'error' => 'Generated image data was empty.' );
        }

        $tracker->record_image( $this->owner_id(), $image_model, (string) $request_body['size'], 1 );

        $image = $this->validate_generated_image( $image_bytes );
        if ( is_wp_error( $image ) ) {
            return array( 'error' => $image->get_error_message() );
        }

        $result = $this->store_media( $image_bytes, $params, $prompt, $image );
        if ( ! empty( $result['success'] ) ) {
            $result['usage_recorded'] = true;
            $result['usage_model']    = WPAgent_Cost_Tracker::image_usage_model( $image_model, (string) $request_body['size'] );
            $result['estimated_cost'] = $estimated_cost;
        }

        return $result;
    }

    private function download_generated_url( $url ) {
        $valid = WPAgent_URL_Safety::validate_public_http_url( $url, 'generated image URL' );
        if ( is_wp_error( $valid ) ) {
            return '';
        }

        $response = wp_remote_get( esc_url_raw( $url ), array( 'timeout' => 60, 'redirection' => 2 ) );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
            return '';
        }
        return wp_remote_retrieve_body( $response );
    }

    private function validate_generated_image( $image_bytes ) {
        if ( ! is_string( $image_bytes ) || '' === $image_bytes ) {
            return new WP_Error( 'wp_agent_generated_image_empty', 'Generated image data was empty.' );
        }

        if ( strlen( $image_bytes ) > self::MAX_IMAGE_BYTES ) {
            return new WP_Error( 'wp_agent_generated_image_size', 'Generated image exceeds the size limit.' );
        }

        if ( ! function_exists( 'getimagesizefromstring' ) ) {
            return new WP_Error( 'wp_agent_generated_image_probe', 'PHP cannot inspect generated image data.' );
        }

        $info = @getimagesizefromstring( $image_bytes );
        if ( ! is_array( $info ) || empty( $info['mime'] ) || 0 !== strpos( (string) $info['mime'], 'image/' ) ) {
            return new WP_Error( 'wp_agent_generated_image_invalid', 'Generated image data was not a valid image.' );
        }

        $mime = (string) $info['mime'];
        $extensions = array(
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        );
        if ( empty( $extensions[ $mime ] ) ) {
            return new WP_Error( 'wp_agent_generated_image_type', 'Generated image type is not supported.' );
        }

        return array(
            'mime'      => $mime,
            'extension' => $extensions[ $mime ],
            'width'     => (int) ( $info[0] ?? 0 ),
            'height'    => (int) ( $info[1] ?? 0 ),
        );
    }

    private function store_media( $image_bytes, $params, $prompt, $image ) {
        if ( ! function_exists( 'wp_upload_bits' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $title    = sanitize_text_field( $params['title'] ?? 'WP Agent generated image' );
        $filename = sanitize_file_name( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $title ) ) . '-' . time() . '.' . $image['extension'] );
        $upload   = wp_upload_bits( $filename, null, $image_bytes );

        if ( ! empty( $upload['error'] ) ) {
            return array( 'error' => 'Could not store generated image: ' . $upload['error'] );
        }

        $attachment_id = wp_insert_attachment( array(
            'post_mime_type' => $image['mime'],
            'post_title'     => $title,
            'post_content'   => 'Generated by WP Agent from prompt: ' . mb_substr( $prompt, 0, 500 ),
            'post_status'    => 'inherit',
        ), $upload['file'] );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return array( 'error' => 'Could not create media attachment.' );
        }

        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // Guarantee alt text + caption for accessibility and SEO, deriving
        // sensible defaults from the title/prompt when none were supplied.
        $media_meta = array( 'alt' => '', 'caption' => '' );
        if ( class_exists( 'WPAgent_Media_Meta' ) ) {
            $media_meta = WPAgent_Media_Meta::ensure(
                $attachment_id,
                (string) ( $params['alt_text'] ?? '' ),
                (string) ( $params['caption'] ?? '' ),
                $prompt
            );
        } elseif ( ! empty( $params['alt_text'] ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_alt_text', sanitize_text_field( $params['alt_text'] ) );
        }

        WPAgent_Journal::add( $this->owner_id(), 'asset', 'Generated image: ' . $title, $prompt, array(
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
        ), $this->conversation_id, $this->run_id );

        return array(
            'success'       => true,
            'attachment_id' => (int) $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
            'title'         => $title,
            'alt_text'      => $media_meta['alt'],
            'caption'       => $media_meta['caption'],
            'mime_type'     => $image['mime'],
            'width'         => $image['width'],
            'height'        => $image['height'],
        );
    }
}
