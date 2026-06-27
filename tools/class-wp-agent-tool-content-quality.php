<?php
/**
 * Content quality tool — local editorial checks before publication.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Content_Quality extends WPAgent_Tool {

    public function get_name() {
        return 'content_quality';
    }

    public function get_description() {
        return 'Audit draft text or an existing post for source provenance, duplicate risk, SEO hygiene, sensitive-topic flags, readability, and media readiness before publication.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'audit_text', 'audit_post' ),
                    'description' => 'audit_text checks supplied draft fields. audit_post checks an existing WordPress post.',
                ),
                'post_id' => array(
                    'type'        => 'integer',
                    'description' => 'Post ID for audit_post.',
                ),
                'title' => array(
                    'type'        => 'string',
                    'description' => 'Draft title for audit_text.',
                ),
                'content' => array(
                    'type'        => 'string',
                    'description' => 'Draft content for audit_text.',
                ),
                'source_urls' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => 'Public source URLs retained with the draft.',
                ),
                'source_notes' => array(
                    'type'        => 'string',
                    'description' => 'Short source or reporting notes retained with the draft.',
                ),
                'meta_title' => array(
                    'type'        => 'string',
                    'description' => 'SEO meta title for audit_text.',
                ),
                'meta_description' => array(
                    'type'        => 'string',
                    'description' => 'SEO meta description for audit_text.',
                ),
                'focus_keyword' => array(
                    'type'        => 'string',
                    'description' => 'Primary focus keyword for audit_text.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Number of existing posts to compare for duplicate risk. Default 20, max 50.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        switch ( $params['action'] ?? '' ) {
            case 'audit_text':
                return $this->audit_text( $params );
            case 'audit_post':
                return $this->audit_post( $params );
            default:
                return array( 'error' => 'Unknown action. Use audit_text or audit_post.' );
        }
    }

    private function audit_post( $params ) {
        $post_id = (int) ( $params['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            return array( 'error' => 'post_id is required for audit_post.' );
        }

        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
            return array( 'error' => 'Post not found.' );
        }

        return $this->audit_document( array(
            'post_id'          => $post_id,
            'title'            => $post->post_title,
            'content'          => $post->post_content,
            'source_urls'      => $this->stored_source_urls( $post_id ),
            'source_notes'     => (string) get_post_meta( $post_id, '_wp_agent_source_notes', true ),
            'meta_title'       => $this->first_meta( $post_id, array( '_yoast_wpseo_title', 'rank_math_title', '_wp_agent_meta_title' ) ),
            'meta_description' => $this->first_meta( $post_id, array( '_yoast_wpseo_metadesc', 'rank_math_description', '_wp_agent_meta_description' ) ),
            'focus_keyword'    => $this->first_meta( $post_id, array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword', '_wp_agent_focus_keyword' ) ),
            'featured_image_id' => (int) get_post_thumbnail_id( $post_id ),
            'limit'            => $params['limit'] ?? 20,
        ) );
    }

    private function audit_text( $params ) {
        return $this->audit_document( array(
            'post_id'          => 0,
            'title'            => (string) ( $params['title'] ?? '' ),
            'content'          => (string) ( $params['content'] ?? '' ),
            'source_urls'      => $params['source_urls'] ?? array(),
            'source_notes'     => (string) ( $params['source_notes'] ?? '' ),
            'meta_title'       => (string) ( $params['meta_title'] ?? '' ),
            'meta_description' => (string) ( $params['meta_description'] ?? '' ),
            'focus_keyword'    => (string) ( $params['focus_keyword'] ?? '' ),
            'featured_image_id' => 0,
            'limit'            => $params['limit'] ?? 20,
        ) );
    }

    /**
     * Lightweight quality gate run automatically after a post is created or
     * edited, so the agent always sees what is still missing (length, images,
     * SEO) and can fix it — without having to remember to call audit_post.
     *
     * @param int $post_id Saved post ID.
     * @return array { status, score, issues[], must_fix[] }
     */
    public static function gate_for_post( $post_id ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
            return array();
        }

        $tool  = new self();
        $audit = $tool->audit_document( array(
            'post_id'          => $post_id,
            'title'            => $post->post_title,
            'content'          => $post->post_content,
            'source_urls'      => $tool->stored_source_urls( $post_id ),
            'source_notes'     => (string) get_post_meta( $post_id, '_wp_agent_source_notes', true ),
            'meta_title'       => $tool->first_meta( $post_id, array( '_yoast_wpseo_title', 'rank_math_title', '_wp_agent_meta_title' ) ),
            'meta_description' => $tool->first_meta( $post_id, array( '_yoast_wpseo_metadesc', 'rank_math_description', '_wp_agent_meta_description' ) ),
            'focus_keyword'    => $tool->first_meta( $post_id, array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword', '_wp_agent_focus_keyword' ) ),
            'featured_image_id' => (int) get_post_thumbnail_id( $post_id ),
            'limit'            => 20,
        ) );

        // Only surface the actionable, must-fix items so the gate stays compact.
        $must_fix_codes = array(
            'content_too_short', 'content_below_article_length',
            'missing_images', 'missing_featured_image',
            'missing_meta_description', 'meta_description_too_short',
            'missing_focus_keyword', 'missing_meta_title',
        );
        $must_fix = array();
        foreach ( (array) ( $audit['recommendations'] ?? array() ) as $rec ) {
            $must_fix[] = $rec;
        }
        $present = array_values( array_intersect( $must_fix_codes, (array) ( $audit['issues'] ?? array() ) ) );

        return array(
            'status'          => $audit['status'] ?? 'review',
            'score'           => $audit['score'] ?? 0,
            'effective_words' => $audit['checks']['readability']['effective_words'] ?? 0,
            'in_body_images'  => $audit['checks']['media']['in_body_images'] ?? 0,
            'featured_image'  => (int) ( $audit['checks']['media']['featured_image_id'] ?? 0 ) > 0,
            'meta_description_length' => $audit['checks']['seo']['description_length'] ?? 0,
            'must_fix_codes'  => $present,
            'must_fix'        => array_values( array_unique( $must_fix ) ),
        );
    }

    private function audit_document( array $document ) {
        $title   = sanitize_text_field( (string) ( $document['title'] ?? '' ) );
        $content = (string) ( $document['content'] ?? '' );
        $text    = $this->plain_text( $content );
        $post_id = (int) ( $document['post_id'] ?? 0 );

        $checks = array(
            'provenance'   => $this->check_provenance( $document['source_urls'] ?? array(), (string) ( $document['source_notes'] ?? '' ) ),
            'duplicate'    => $this->check_duplicates( $title, $text, $post_id, (int) ( $document['limit'] ?? 20 ) ),
            'seo'          => $this->check_seo( $title, (string) ( $document['meta_title'] ?? '' ), (string) ( $document['meta_description'] ?? '' ), (string) ( $document['focus_keyword'] ?? '' ) ),
            'sensitive'    => $this->check_sensitive_topics( $text ),
            'readability'  => $this->check_readability( $content, $text ),
            'media'        => $this->check_media( $post_id, (int) ( $document['featured_image_id'] ?? 0 ) ),
        );

        $issues = array();
        foreach ( $checks as $check ) {
            foreach ( $check['issues'] ?? array() as $issue ) {
                $issues[] = $issue;
            }
        }

        $score  = $this->score( $checks );
        $status = $this->status( $score, $checks );

        return array(
            'success'         => true,
            'status'          => $status,
            'score'           => $score,
            'post_id'         => $post_id,
            'title'           => $title,
            'word_count'      => $checks['readability']['word_count'],
            'issues'          => array_values( array_unique( $issues ) ),
            'checks'          => $checks,
            'recommendations' => $this->recommendations( $checks ),
        );
    }

    private function check_provenance( $source_urls, $source_notes ) {
        $issues = array();
        $valid  = array();
        $invalid = array();

        if ( ! is_array( $source_urls ) ) {
            $issues[] = 'source_urls_not_array';
            $source_urls = array();
        }

        foreach ( $source_urls as $url ) {
            $url = trim( (string) $url );
            if ( '' === $url ) {
                continue;
            }

            $validated = WPAgent_URL_Safety::validate_public_http_url( $url, 'source_urls' );
            if ( is_wp_error( $validated ) ) {
                $invalid[] = array(
                    'url'    => esc_url_raw( $url ),
                    'reason' => $validated->get_error_code(),
                );
                continue;
            }
            $valid[] = esc_url_raw( $url );
        }

        $valid = array_values( array_unique( $valid ) );
        if ( empty( $valid ) ) {
            $issues[] = 'missing_source_urls';
        }
        if ( ! empty( $invalid ) ) {
            $issues[] = 'invalid_source_urls';
        }
        if ( '' === trim( $source_notes ) ) {
            $issues[] = 'missing_source_notes';
        }

        return array(
            'ok'           => empty( $issues ),
            'source_count' => count( $valid ),
            'source_urls'  => $valid,
            'invalid_urls' => $invalid,
            'has_notes'    => '' !== trim( $source_notes ),
            'issues'       => $issues,
        );
    }

    private function check_duplicates( $title, $text, $exclude_post_id, $limit ) {
        $tokens = $this->tokens( $title . ' ' . $text );
        $limit  = max( 1, min( $limit, 50 ) );
        $matches = array();

        if ( count( $tokens ) < 12 ) {
            return array(
                'risk'           => 'unknown',
                'max_similarity' => 0,
                'matches'        => array(),
                'issues'         => array( 'too_short_for_duplicate_scan' ),
            );
        }

        $posts = get_posts( array(
            'post_type'      => array( 'post', 'page' ),
            'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
            'posts_per_page' => $limit,
            'exclude'        => $exclude_post_id > 0 ? array( $exclude_post_id ) : array(),
            'post__not_in'   => $exclude_post_id > 0 ? array( $exclude_post_id ) : array(),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        foreach ( $posts as $post ) {
            $other_tokens = $this->tokens( $post->post_title . ' ' . $this->plain_text( $post->post_content ) );
            $similarity   = $this->similarity( $tokens, $other_tokens );
            if ( $similarity < 0.35 ) {
                continue;
            }
            $matches[] = array(
                'post_id'    => (int) $post->ID,
                'title'      => $post->post_title,
                'status'     => $post->post_status,
                'similarity' => round( $similarity, 3 ),
                'url'        => get_permalink( $post ),
            );
        }

        usort( $matches, function( $a, $b ) {
            return $b['similarity'] <=> $a['similarity'];
        } );
        $matches = array_slice( $matches, 0, 5 );
        $max     = ! empty( $matches ) ? (float) $matches[0]['similarity'] : 0.0;
        $risk    = 'low';
        $issues  = array();

        if ( $max >= 0.82 ) {
            $risk = 'high';
            $issues[] = 'high_duplicate_risk';
        } elseif ( $max >= 0.55 ) {
            $risk = 'medium';
            $issues[] = 'medium_duplicate_risk';
        }

        return array(
            'risk'           => $risk,
            'max_similarity' => round( $max, 3 ),
            'matches'        => $matches,
            'issues'         => $issues,
        );
    }

    private function check_seo( $title, $meta_title, $meta_description, $focus_keyword ) {
        $issues = array();
        $title_length = mb_strlen( $title );
        $meta_title_length = mb_strlen( $meta_title );
        $description_length = mb_strlen( $meta_description );

        if ( $title_length < 20 ) {
            $issues[] = 'title_too_short';
        } elseif ( $title_length > 70 ) {
            $issues[] = 'title_too_long';
        }
        if ( '' === trim( $meta_title ) ) {
            $issues[] = 'missing_meta_title';
        } elseif ( $meta_title_length > 70 ) {
            $issues[] = 'meta_title_too_long';
        }
        if ( '' === trim( $meta_description ) ) {
            $issues[] = 'missing_meta_description';
        } elseif ( $description_length < 120 ) {
            $issues[] = 'meta_description_too_short';
        } elseif ( $description_length > 160 ) {
            $issues[] = 'meta_description_too_long';
        }
        if ( '' === trim( $focus_keyword ) ) {
            $issues[] = 'missing_focus_keyword';
        }

        return array(
            'ok'                  => empty( $issues ),
            'title_length'        => $title_length,
            'meta_title_length'   => $meta_title_length,
            'description_length'  => $description_length,
            'focus_keyword'       => sanitize_text_field( $focus_keyword ),
            'issues'              => $issues,
        );
    }

    private function check_sensitive_topics( $text ) {
        $lower = strtolower( $text );
        $catalog = array(
            'graphic_or_violent' => array( 'graphic violence', 'bloodshed', 'weapon', 'explosive' ),
            'medical_or_health'  => array( 'diagnosis', 'treatment', 'cancer', 'dosage' ),
            'financial_or_legal' => array( 'investment advice', 'guaranteed return', 'legal advice', 'lawsuit' ),
            'adult_or_hate'      => array( 'explicit sexual', 'hate speech', 'racial slur' ),
            'self_harm'          => array( 'self-harm', 'suicide method' ),
        );

        $flags = array();
        foreach ( $catalog as $category => $terms ) {
            foreach ( $terms as $term ) {
                if ( false !== strpos( $lower, $term ) ) {
                    $flags[] = array(
                        'category' => $category,
                        'term'     => $term,
                    );
                }
            }
        }

        return array(
            'ok'     => empty( $flags ),
            'flags'  => $flags,
            'issues' => empty( $flags ) ? array() : array( 'sensitive_topic_review' ),
        );
    }

    private function check_readability( $content, $text ) {
        $tokens = preg_split( '/\s+/', trim( $text ) );
        $tokens = array_values( array_filter( $tokens, 'strlen' ) );
        $word_count = count( $tokens );

        // CJK text rarely uses spaces, so a space-split word count under-reports
        // length badly. Approximate an effective word count for length checks by
        // also counting CJK characters (~1.6 chars ≈ 1 "word").
        $cjk_chars = preg_match_all( '/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $text );
        $cjk_chars = (int) $cjk_chars;
        $char_count = mb_strlen( trim( $text ) );
        $effective_words = max( $word_count, (int) round( $cjk_chars / 1.6 ) );

        $paragraphs = preg_split( '/\n\s*\n|<\/p>/i', (string) $content );
        $paragraph_count = count( array_filter( array_map( 'trim', $paragraphs ) ) );
        $heading_count = preg_match_all( '#<h[1-6]\b#i', (string) $content );
        $issues = array();

        // Article-grade length bar aligned to a substantial, in-depth article.
        // We grade in tiers so the agent is pushed to expand thin drafts to a
        // real, publishable size:
        //   < ~350 words / < ~600 CJK    -> unusable (content_too_short)
        //   < ~1,900 words / < ~3,000 CJK -> below required length (revise)
        // Both tiers are revise-blocking. The required minimum is a genuinely
        // long-form article: ~3,000 CJK characters (~1,900 English words).
        if ( $effective_words < 350 && $cjk_chars < 600 ) {
            $issues[] = 'content_too_short';
        } elseif ( $effective_words < 1900 && $cjk_chars < 3000 ) {
            $issues[] = 'content_below_article_length';
        }
        if ( $paragraph_count < 2 ) {
            $issues[] = 'too_few_paragraphs';
        }
        if ( $effective_words > 250 && $heading_count < 1 ) {
            $issues[] = 'missing_headings';
        }

        return array(
            'ok'              => empty( $issues ),
            'word_count'      => $word_count,
            'effective_words' => $effective_words,
            'char_count'      => $char_count,
            'cjk_chars'       => $cjk_chars,
            'paragraph_count' => $paragraph_count,
            'heading_count'   => (int) $heading_count,
            'issues'          => $issues,
        );
    }

    private function check_media( $post_id, $featured_image_id ) {
        // Count in-body images from the post content when we have a post.
        $in_body_images = 0;
        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            if ( $post ) {
                $in_body_images = (int) preg_match_all( '#<img\b#i', (string) $post->post_content );
                if ( $featured_image_id <= 0 ) {
                    $featured_image_id = (int) get_post_thumbnail_id( $post_id );
                }
            }
        }

        if ( $post_id <= 0 ) {
            return array(
                'ok'                => true,
                'featured_image_id' => $featured_image_id,
                'in_body_images'    => $in_body_images,
                'issues'            => array(),
            );
        }

        $issues = array();
        if ( $featured_image_id <= 0 ) {
            $issues[] = 'missing_featured_image';
        }
        // A real multimedia article must be illustrated throughout, not just a
        // wall of text with a single cover. Require at least one in-body image
        // in addition to the featured image.
        if ( $in_body_images < 1 ) {
            $issues[] = 'missing_images';
        }

        return array(
            'ok'                => empty( $issues ),
            'featured_image_id' => $featured_image_id,
            'in_body_images'    => $in_body_images,
            'issues'            => $issues,
        );
    }

    private function score( array $checks ) {
        $score = 100;
        $penalties = array(
            'missing_source_urls'          => 15,
            'invalid_source_urls'          => 20,
            'missing_source_notes'         => 5,
            'high_duplicate_risk'          => 35,
            'medium_duplicate_risk'        => 18,
            'too_short_for_duplicate_scan' => 5,
            'title_too_short'              => 4,
            'title_too_long'               => 4,
            'missing_meta_title'           => 8,
            'meta_title_too_long'          => 4,
            'missing_meta_description'     => 10,
            'meta_description_too_short'   => 4,
            'meta_description_too_long'    => 4,
            'missing_focus_keyword'        => 8,
            'sensitive_topic_review'       => 10,
            'content_too_short'            => 15,
            'content_below_article_length' => 6,
            'too_few_paragraphs'           => 4,
            'missing_headings'             => 4,
            'missing_featured_image'       => 10,
            'missing_images'               => 12,
        );

        foreach ( $checks as $check ) {
            foreach ( $check['issues'] ?? array() as $issue ) {
                $score -= $penalties[ $issue ] ?? 3;
            }
        }

        return max( 0, min( 100, $score ) );
    }

    private function status( $score, array $checks ) {
        $issues = array();
        foreach ( $checks as $check ) {
            $issues = array_merge( $issues, $check['issues'] ?? array() );
        }

        if ( in_array( 'invalid_source_urls', $issues, true ) || in_array( 'high_duplicate_risk', $issues, true ) || in_array( 'content_too_short', $issues, true ) || in_array( 'content_below_article_length', $issues, true ) || in_array( 'missing_images', $issues, true ) || in_array( 'missing_meta_description', $issues, true ) || in_array( 'meta_description_too_short', $issues, true ) ) {
            return 'revise';
        }

        if ( $score < 80 || in_array( 'sensitive_topic_review', $issues, true ) || in_array( 'medium_duplicate_risk', $issues, true ) ) {
            return 'review';
        }

        return 'pass';
    }

    private function recommendations( array $checks ) {
        $map = array(
            'missing_source_urls'          => 'Attach at least one retained public source URL before publication.',
            'invalid_source_urls'          => 'Replace private, local, relative, or non-http source URLs with public http(s) sources.',
            'missing_source_notes'         => 'Add concise source notes explaining what facts came from the retained sources.',
            'high_duplicate_risk'          => 'Rewrite the draft substantially before publishing; it closely matches an existing post.',
            'medium_duplicate_risk'        => 'Review the matched posts and adjust angle, structure, or wording before publishing.',
            'missing_meta_title'           => 'Add an SEO meta title (call manage_seo with the post_id).',
            'missing_meta_description'     => 'Add an SEO meta description of 120–155 characters (call manage_seo with the post_id).',
            'meta_description_too_short'   => 'Lengthen the SEO meta description to 120–155 characters (call manage_seo).',
            'meta_description_too_long'    => 'Shorten the SEO meta description to 120–155 characters.',
            'missing_focus_keyword'        => 'Set a focus keyword for the article (call manage_seo).',
            'sensitive_topic_review'       => 'Review sensitive-topic flags before publication.',
            'content_too_short'            => 'The draft is far too short. Substantially expand it into a full long-form article (at least ~1,900 words / ~3,000+ Chinese characters) with multiple H2 sections, concrete detail, examples, data, and analysis — not padding.',
            'content_below_article_length' => 'The draft is below the required length. Expand it to at least ~1,900 words / ~3,000+ Chinese characters with several H2 sections, real depth, examples, data, and a clear intro and conclusion. Edit the post again and re-check until the length passes.',
            'missing_featured_image'       => 'Add a featured image with alt text: prefer a real, freely-usable image (manage_media import from Wikimedia/an official source) or generate_image with a prompt that clearly depicts the article\'s specific topic, then set it as featured.',
            'missing_images'               => 'This must be a multimedia article: add at least one in-body image (ideally several, spread across sections), each with descriptive alt text and a caption. Prefer real, on-topic images (manage_media import) and use generate_image as a fallback.',
        );

        $recommendations = array();
        foreach ( $checks as $check ) {
            foreach ( $check['issues'] ?? array() as $issue ) {
                if ( isset( $map[ $issue ] ) ) {
                    $recommendations[] = $map[ $issue ];
                }
            }
        }

        return array_values( array_unique( $recommendations ) );
    }

    private function plain_text( $content ) {
        $content = preg_replace( '#<(script|style|noscript|svg|head)\b[^>]*>.*?</\1>#is', ' ', (string) $content );
        $content = preg_replace( '#</(p|div|h[1-6]|li|section|article)>#i', "\n\n", $content );
        $text    = wp_strip_all_tags( $content );
        $text    = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text    = preg_replace( "/[ \t]+/", ' ', $text );
        $text    = preg_replace( "/\n{3,}/", "\n\n", $text );
        return trim( $text );
    }

    private function tokens( $text ) {
        $parts = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( (string) $text ) );
        $stop = array_flip( array(
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'about', 'after',
            'before', 'while', 'their', 'there', 'have', 'has', 'are', 'was', 'were',
            'will', 'would', 'could', 'should', 'article', 'post', 'news',
        ) );
        $tokens = array();
        foreach ( $parts as $part ) {
            $part = trim( (string) $part );
            if ( strlen( $part ) < 3 || isset( $stop[ $part ] ) ) {
                continue;
            }
            $tokens[] = $part;
        }
        return array_values( array_unique( $tokens ) );
    }

    private function similarity( array $a, array $b ) {
        if ( empty( $a ) || empty( $b ) ) {
            return 0.0;
        }

        $a = array_fill_keys( $a, true );
        $b = array_fill_keys( $b, true );
        $intersection = count( array_intersect_key( $a, $b ) );
        $union = count( $a + $b );

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function stored_source_urls( $post_id ) {
        $raw = get_post_meta( $post_id, '_wp_agent_source_urls', true );
        if ( is_string( $raw ) && '' !== $raw ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : array();
        }
        return is_array( $raw ) ? $raw : array();
    }

    private function first_meta( $post_id, array $keys ) {
        foreach ( $keys as $key ) {
            $value = (string) get_post_meta( $post_id, $key, true );
            if ( '' !== $value ) {
                return $value;
            }
        }
        return '';
    }
}
