<?php
/**
 * Media tool — list, search, and manage the WordPress media library.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Media extends WPAgent_Tool {

    public function get_name() {
        return 'manage_media';
    }

    public function get_description() {
        return 'List, search, and get details about items in the WordPress media library, update alt text and captions, or import a real image from a public http(s) URL (e.g. a relevant photo from the web or a figure from a paper/source) into the media library with alt text and a caption.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type' => 'string',
                    'enum' => array( 'list', 'search', 'get', 'update', 'delete', 'import' ),
                    'description' => 'The operation to perform. Use "import" to download a real image from a public URL into the media library.',
                ),
                'url' => array(
                    'type' => 'string',
                    'description' => 'Public http(s) image URL to download and import (for action=import). Must point to an image file.',
                ),
                'attachment_id' => array(
                    'type' => 'integer',
                    'description' => 'Attachment ID (for get, update, delete).',
                ),
                'search' => array(
                    'type' => 'string',
                    'description' => 'Search query.',
                ),
                'mime_type' => array(
                    'type' => 'string',
                    'description' => 'Filter by MIME type (e.g., image, video, application/pdf).',
                ),
                'alt_text' => array(
                    'type' => 'string',
                    'description' => 'Alt text to set (for update).',
                ),
                'caption' => array(
                    'type' => 'string',
                    'description' => 'Caption to set (for update).',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Title to set (for update).',
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Number of items to return (default 10).',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'upload_files';
    }

    public function execute( array $params ) {
        switch ( $params['action'] ) {
            case 'list':
            case 'search':
                $args = array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => min( (int) ( $params['limit'] ?? 10 ), 50 ),
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                );
                if ( ! empty( $params['search'] ) ) $args['s'] = $params['search'];
                if ( ! empty( $params['mime_type'] ) ) $args['post_mime_type'] = sanitize_mime_type( $params['mime_type'] );
                $query = new WP_Query( $args );
                $items = array();
                foreach ( $query->posts as $att ) {
                    $items[] = $this->format_attachment( $att );
                }
                return array( 'total' => $query->found_posts, 'items' => $items );

            case 'get':
                if ( empty( $params['attachment_id'] ) ) return array( 'error' => 'attachment_id required.' );
                $att = get_post( $params['attachment_id'] );
                if ( ! $att || 'attachment' !== $att->post_type ) return array( 'error' => 'Attachment not found.' );
                return $this->format_attachment( $att, true );

            case 'update':
                if ( empty( $params['attachment_id'] ) ) return array( 'error' => 'attachment_id required.' );
                $att = get_post( $params['attachment_id'] );
                if ( ! $att || 'attachment' !== $att->post_type ) return array( 'error' => 'Attachment not found.' );
                if ( isset( $params['title'] ) ) {
                    wp_update_post( array( 'ID' => $params['attachment_id'], 'post_title' => sanitize_text_field( $params['title'] ) ) );
                }
                // Set what the agent provided, then guarantee non-empty alt + caption.
                $ensured = array( 'alt' => '', 'caption' => '' );
                if ( class_exists( 'WPAgent_Media_Meta' ) ) {
                    $ensured = WPAgent_Media_Meta::ensure(
                        (int) $params['attachment_id'],
                        (string) ( $params['alt_text'] ?? '' ),
                        (string) ( $params['caption'] ?? '' )
                    );
                } else {
                    if ( isset( $params['alt_text'] ) ) {
                        update_post_meta( $params['attachment_id'], '_wp_attachment_alt_text', sanitize_text_field( $params['alt_text'] ) );
                    }
                    if ( isset( $params['caption'] ) ) {
                        wp_update_post( array( 'ID' => $params['attachment_id'], 'post_excerpt' => sanitize_text_field( $params['caption'] ) ) );
                    }
                }
                return array( 'success' => true, 'message' => 'Attachment updated.', 'alt_text' => $ensured['alt'], 'caption' => $ensured['caption'] );

            case 'delete':
                if ( empty( $params['attachment_id'] ) ) return array( 'error' => 'attachment_id required.' );
                // Move to trash instead of permanent deletion (force_delete = false).
                $result = wp_delete_attachment( $params['attachment_id'], false );
                if ( ! $result ) return array( 'error' => 'Failed to delete attachment.' );
                return array( 'success' => true, 'message' => 'Attachment moved to trash.' );

            case 'import':
                return $this->import_from_url( $params );

            default:
                return array( 'error' => 'Unknown action.' );
        }
    }

    /**
     * Download a real image from a public http(s) URL and import it into the
     * media library, with guaranteed alt text and a caption. SSRF-guarded, image
     * MIME validated, and size-capped. Lets the agent use real photos from the
     * web or figures from a paper/source instead of only AI-generated images.
     *
     * @param array $params { url, alt_text, caption, title }
     * @return array
     */
    private function import_from_url( $params ) {
        $url = trim( (string) ( $params['url'] ?? '' ) );
        if ( '' === $url ) {
            return array( 'error' => 'url is required for import.' );
        }

        // SSRF guard: only public http(s) hosts.
        if ( class_exists( 'WPAgent_URL_Safety' ) ) {
            $valid = WPAgent_URL_Safety::validate_public_http_url( $url, 'url' );
            if ( is_wp_error( $valid ) ) {
                return array( 'error' => $valid->get_error_message() );
            }
            $url = is_string( $valid ) ? $valid : $url;
        } elseif ( ! preg_match( '#^https?://#i', $url ) ) {
            return array( 'error' => 'Only public http(s) image URLs can be imported.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download the bytes with a real User-Agent (some hosts reject blank UA),
        // bounded in size, then write to a temp file for sideloading.
        $max_bytes = 12 * 1024 * 1024;
        $response  = wp_remote_get( $url, array(
            'timeout'             => 30,
            'redirection'         => 3,
            'limit_response_size' => $max_bytes,
            'headers'             => array(
                'User-Agent' => 'WP-Agent-Media/1.0 (+https://wordpress.org)',
                'Accept'     => 'image/avif,image/webp,image/png,image/jpeg,image/*,*/*;q=0.8',
            ),
        ) );
        if ( is_wp_error( $response ) ) {
            return array( 'error' => 'Could not download the image: ' . $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return array( 'error' => 'Could not download the image: HTTP ' . $code . '.' );
        }
        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return array( 'error' => 'The downloaded image was empty.' );
        }
        if ( strlen( $body ) > $max_bytes ) {
            return array( 'error' => 'The image exceeds the size limit.' );
        }

        $tmp = wp_tempnam( 'wp-agent-import' );
        if ( ! $tmp ) {
            return array( 'error' => 'Could not create a temporary file for the image.' );
        }
        if ( false === file_put_contents( $tmp, $body ) ) {
            @unlink( $tmp );
            return array( 'error' => 'Could not write the downloaded image.' );
        }

        // Validate it is really an image.
        $info = @getimagesize( $tmp );
        if ( false === $info || empty( $info['mime'] ) || 0 !== strpos( (string) $info['mime'], 'image/' ) ) {
            @unlink( $tmp );
            return array( 'error' => 'The URL did not resolve to a valid image file.' );
        }

        // Derive a filename with a correct extension from the MIME type.
        $ext_map = array(
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/avif' => 'avif',
        );
        $ext  = $ext_map[ $info['mime'] ] ?? 'jpg';
        $base = sanitize_file_name( wp_basename( parse_url( $url, PHP_URL_PATH ) ?: 'imported-image' ) );
        $base = preg_replace( '/\.[A-Za-z0-9]+$/', '', $base );
        if ( '' === $base ) {
            $base = 'imported-image';
        }
        $file_array = array(
            'name'     => $base . '.' . $ext,
            'tmp_name' => $tmp,
        );

        $title = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
        $attachment_id = media_handle_sideload( $file_array, 0, $title !== '' ? $title : null );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return array( 'error' => 'Import failed: ' . $attachment_id->get_error_message() );
        }

        // Guarantee alt text + caption for accessibility and SEO.
        $meta = array( 'alt' => '', 'caption' => '' );
        if ( class_exists( 'WPAgent_Media_Meta' ) ) {
            $meta = WPAgent_Media_Meta::ensure(
                (int) $attachment_id,
                (string) ( $params['alt_text'] ?? '' ),
                (string) ( $params['caption'] ?? '' ),
                $title
            );
        }

        return array(
            'success'       => true,
            'attachment_id' => (int) $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
            'source_url'    => $url,
            'mime_type'     => $info['mime'],
            'width'         => (int) ( $info[0] ?? 0 ),
            'height'        => (int) ( $info[1] ?? 0 ),
            'alt_text'      => $meta['alt'],
            'caption'       => $meta['caption'],
            'message'       => 'Imported a real image from the web into the media library.',
        );
    }

    private function format_attachment( $att, $detailed = false ) {
        $data = array(
            'id'        => $att->ID,
            'title'     => $att->post_title,
            'filename'  => basename( get_attached_file( $att->ID ) ),
            'mime_type' => $att->post_mime_type,
            'url'       => wp_get_attachment_url( $att->ID ),
            'alt_text'  => get_post_meta( $att->ID, '_wp_attachment_alt_text', true ),
            'caption'   => $att->post_excerpt,
            'date'      => $att->post_date,
        );

        if ( $detailed ) {
            $meta = wp_get_attachment_metadata( $att->ID );
            if ( $meta ) {
                $data['width']  = $meta['width'] ?? null;
                $data['height'] = $meta['height'] ?? null;

                // Safely get file size — validate path is within uploads directory.
                $file_path = get_attached_file( $att->ID );
                if ( $file_path && file_exists( $file_path ) ) {
                    $upload_dir = wp_upload_dir();
                    $real_path  = realpath( $file_path );
                    $real_base  = realpath( $upload_dir['basedir'] );

                    // Ensure the file is within the uploads directory (prevent path traversal).
                    if ( $real_path && $real_base && 0 === strpos( $real_path, $real_base ) ) {
                        $data['file_size'] = size_format( filesize( $real_path ) );
                    }
                }
            }
        }

        return $data;
    }
}
