<?php
/**
 * Media metadata guarantees.
 *
 * Ensures every image attachment the agent creates or imports has a non-empty
 * alt text and caption, which are important for accessibility and SEO. When the
 * agent (or an upload) does not supply them, sensible defaults are derived from
 * the supplied title, the attachment title, or the file name.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Media_Meta {

    /**
     * Guarantee alt text and caption on an attachment.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $alt           Preferred alt text (optional).
     * @param string $caption       Preferred caption (optional).
     * @param string $context       Extra context (e.g. an image prompt) used to
     *                              build a caption fallback.
     * @return array { alt, caption } the values actually stored.
     */
    public static function ensure( $attachment_id, $alt = '', $caption = '', $context = '' ) {
        $attachment_id = (int) $attachment_id;
        $post          = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return array( 'alt' => '', 'caption' => '' );
        }

        // Only enforce for images; other media types do not use alt text the same way.
        $mime = (string) get_post_mime_type( $attachment_id );
        $is_image = 0 === strpos( $mime, 'image/' );

        $title    = (string) $post->post_title;
        $filename = pathinfo( (string) get_attached_file( $attachment_id ), PATHINFO_FILENAME );
        $basis    = '' !== trim( $title ) ? $title : self::humanize( (string) $filename );

        // --- Alt text (images only) ---
        $stored_alt = (string) get_post_meta( $attachment_id, '_wp_attachment_alt_text', true );
        $final_alt  = $stored_alt;
        if ( $is_image ) {
            $candidate = '' !== trim( (string) $alt ) ? (string) $alt : $stored_alt;
            if ( '' === trim( $candidate ) ) {
                $candidate = $basis; // fallback: image title / humanized file name
            }
            $candidate = sanitize_text_field( $candidate );
            if ( '' !== $candidate && $candidate !== $stored_alt ) {
                update_post_meta( $attachment_id, '_wp_attachment_alt_text', $candidate );
            }
            $final_alt = $candidate;
        }

        // --- Caption (post_excerpt) ---
        $stored_caption = (string) $post->post_excerpt;
        $candidate_cap  = '' !== trim( (string) $caption ) ? (string) $caption : $stored_caption;
        if ( '' === trim( $candidate_cap ) ) {
            $candidate_cap = self::build_caption_fallback( $basis, $context );
        }
        $candidate_cap = sanitize_text_field( $candidate_cap );
        if ( '' !== $candidate_cap && $candidate_cap !== $stored_caption ) {
            wp_update_post( array(
                'ID'           => $attachment_id,
                'post_excerpt' => $candidate_cap,
            ) );
        }

        return array(
            'alt'     => $final_alt,
            'caption' => $candidate_cap,
        );
    }

    /**
     * Build a caption when none is provided.
     *
     * @param string $basis   Title or humanized file name.
     * @param string $context Optional extra context (e.g. an image prompt).
     * @return string
     */
    private static function build_caption_fallback( $basis, $context ) {
        $basis = trim( (string) $basis );
        if ( '' === $basis ) {
            $basis = __( 'Media asset', 'wp-agent' );
        }
        // Keep it short and useful; prefer the title alone for a clean caption.
        return $basis;
    }

    /**
     * Turn a slug-like file name into a readable phrase.
     *
     * @param string $filename
     * @return string
     */
    private static function humanize( $filename ) {
        $name = preg_replace( '/[-_]+/', ' ', (string) $filename );
        $name = preg_replace( '/\b\d{6,}\b/', '', (string) $name ); // strip timestamp-like runs
        $name = trim( preg_replace( '/\s{2,}/', ' ', (string) $name ) );
        return '' !== $name ? ucfirst( $name ) : '';
    }
}
