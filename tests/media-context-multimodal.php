<?php
/**
 * Deterministic test: chat attachment markers expand into multimodal content.
 *
 * Verifies that WPAgent_Media_Context turns an image attachment marker into an
 * OpenAI-style image_url content part (base64 data URI) while audio/video/other
 * files keep their textual note. No AI gateway, GitHub, or Docker calls.
 *
 * Run: wp eval-file wp-content/plugins/wp-agent/tests/media-context-multimodal.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run via wp eval-file.\n" );
    exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$failures = array();
$created  = array();

function wpa_mc_assert( $cond, $label, &$failures ) {
    echo ( $cond ? '[PASS] ' : '[FAIL] ' ) . $label . "\n";
    if ( ! $cond ) {
        $failures[] = $label;
    }
}

// --- Build a tiny in-memory PNG and import it as an attachment. ---
$png_bytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
);
$png_tmp = wp_tempnam( 'wpa-mc.png' );
file_put_contents( $png_tmp, $png_bytes );
$png_id = media_handle_sideload( array( 'name' => 'wpa-mc.png', 'tmp_name' => $png_tmp ), 0 );
if ( is_wp_error( $png_id ) ) {
    echo '[FAIL] could not import test PNG: ' . $png_id->get_error_message() . "\n";
    exit( 1 );
}
$created[] = $png_id;

// --- Case 1: image marker -> multimodal array with image_url part. ---
$text_img = "Describe this.\n\nAttached media:\n- #$png_id wpa-mc.png (image/png): http://x/wpa-mc.png\n  [wp-agent-media id=$png_id kind=image]";
$content  = WPAgent_Media_Context::expand_content( $text_img );

wpa_mc_assert( is_array( $content ), 'image marker expands to a content array', $failures );
$has_text  = false;
$has_image = false;
$marker_gone = true;
if ( is_array( $content ) ) {
    foreach ( $content as $part ) {
        if ( ( $part['type'] ?? '' ) === 'text' ) {
            $has_text = true;
            if ( false !== strpos( $part['text'], '[wp-agent-media' ) ) {
                $marker_gone = false;
            }
        }
        if ( ( $part['type'] ?? '' ) === 'image_url' ) {
            $has_image = true;
            $url = $part['image_url']['url'] ?? '';
            wpa_mc_assert( 0 === strpos( $url, 'data:image/png;base64,' ), 'image_url is a base64 data URI', $failures );
        }
    }
}
wpa_mc_assert( $has_text, 'a text part is present', $failures );
wpa_mc_assert( $has_image, 'an image_url part is present', $failures );
wpa_mc_assert( $marker_gone, 'machine marker stripped from text part', $failures );

// --- Case 2: audio marker -> stays a plain string (no inline image). ---
$text_audio = "Transcribe.\n\nAttached media:\n- #999 clip.ogg (audio/ogg): http://x/clip.ogg\n  [wp-agent-media id=999 kind=audio]";
$content_a  = WPAgent_Media_Context::expand_content( $text_audio );
wpa_mc_assert( is_string( $content_a ), 'audio-only message stays a plain string', $failures );
wpa_mc_assert( is_string( $content_a ) && false === strpos( $content_a, '[wp-agent-media' ), 'audio marker stripped from text', $failures );

// --- Case 3: no markers -> unchanged string. ---
$plain = 'Just a normal message.';
wpa_mc_assert( WPAgent_Media_Context::expand_content( $plain ) === $plain, 'plain message returned unchanged', $failures );

// --- Cleanup ---
foreach ( $created as $id ) {
    wp_delete_attachment( $id, true );
}
echo 'cleaned up ' . count( $created ) . " test attachment(s)\n";

echo "\n" . ( empty( $failures ) ? 'ALL PASS' : count( $failures ) . ' FAILURE(S): ' . implode( '; ', $failures ) ) . "\n";
exit( empty( $failures ) ? 0 : 1 );
