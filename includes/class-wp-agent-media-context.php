<?php
/**
 * Expand chat attachment markers into multimodal message content.
 *
 * Chat messages are stored as plain text (with a human-readable media list and
 * a machine marker `[wp-agent-media id=N kind=...]`). Before a message is sent
 * to the AI provider, this helper rewrites any user message that references an
 * image attachment into the OpenAI-style multimodal content array, embedding
 * the image as a base64 data URI so a vision-capable model can actually see it.
 *
 * Audio/video/other files keep their textual note (and URL); inline audio/video
 * understanding depends on the provider/model and is left to the model + URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Media_Context {

    /** Max bytes of a single image to inline as base64 (keeps payloads sane). */
    const MAX_IMAGE_BYTES = 5242880; // 5 MB

    /** Marker pattern: [wp-agent-media id=123 kind=image] */
    const MARKER_PATTERN = '/^\s*\[wp-agent-media id=(\d+) kind=([a-z]+)\]\s*$/mi';

    /**
     * Image MIME types most OpenAI-compatible vision endpoints accept inline.
     *
     * @return string[]
     */
    private static function inline_image_mimes() {
        return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
    }

    /**
     * Expand media markers across a list of context messages.
     *
     * @param array $messages Context messages (role/content/...).
     * @return array
     */
    public static function expand_messages( array $messages ) {
        foreach ( $messages as $i => $msg ) {
            if ( ( $msg['role'] ?? '' ) !== 'user' ) {
                continue;
            }
            if ( ! is_string( $msg['content'] ?? null ) ) {
                continue;
            }
            if ( false === strpos( $msg['content'], '[wp-agent-media' ) ) {
                continue;
            }
            $messages[ $i ]['content'] = self::expand_content( $msg['content'] );
        }
        return $messages;
    }

    /**
     * Turn a single text body containing media markers into either the original
     * string (no inlinable images) or a multimodal content array.
     *
     * @param string $text Message text with markers.
     * @return string|array
     */
    public static function expand_content( $text ) {
        $image_parts = array();

        if ( preg_match_all( self::MARKER_PATTERN, $text, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $id   = (int) $m[1];
                $kind = strtolower( $m[2] );
                if ( 'image' !== $kind ) {
                    continue;
                }
                $part = self::image_part( $id );
                if ( null !== $part ) {
                    $image_parts[] = $part;
                }
            }
        }

        // Always strip the machine markers from the human-visible text part.
        $clean_text = trim( (string) preg_replace( self::MARKER_PATTERN, '', $text ) );
        // Collapse the blank lines the removed markers may leave behind.
        $clean_text = (string) preg_replace( "/\n{3,}/", "\n\n", $clean_text );

        if ( empty( $image_parts ) ) {
            return $clean_text;
        }

        $content = array();
        if ( '' !== $clean_text ) {
            $content[] = array( 'type' => 'text', 'text' => $clean_text );
        }
        foreach ( $image_parts as $part ) {
            $content[] = $part;
        }
        return $content;
    }

    /**
     * Build an OpenAI-style image content part for an attachment, as a base64
     * data URI. Returns null when the attachment is missing, unreadable, too
     * large, or not an inlinable image type.
     *
     * @param int $attachment_id Attachment ID.
     * @return array|null
     */
    private static function image_part( $attachment_id ) {
        $post = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return null;
        }

        $mime = strtolower( (string) get_post_mime_type( $attachment_id ) );
        if ( ! in_array( $mime, self::inline_image_mimes(), true ) ) {
            return null;
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! is_readable( $file ) || ! self::is_safe_upload_path( $file ) ) {
            return null;
        }

        $size = (int) @filesize( $file );
        if ( $size <= 0 || $size > self::MAX_IMAGE_BYTES ) {
            return null;
        }

        $bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $bytes || '' === $bytes ) {
            return null;
        }

        $data_uri = 'data:' . $mime . ';base64,' . base64_encode( $bytes );

        return array(
            'type'      => 'image_url',
            'image_url' => array( 'url' => $data_uri ),
        );
    }

    /**
     * Confirm a file path is inside the WordPress uploads directory.
     *
     * @param string $file Absolute file path.
     * @return bool
     */
    private static function is_safe_upload_path( $file ) {
        $uploads = wp_get_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return false;
        }
        $base = wp_normalize_path( realpath( $uploads['basedir'] ) ?: $uploads['basedir'] );
        $real = wp_normalize_path( realpath( $file ) ?: $file );
        return '' !== $base && 0 === strpos( $real, trailingslashit( $base ) );
    }
}
