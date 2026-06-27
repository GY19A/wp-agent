<?php
/**
 * Taxonomy tool — manage WordPress categories and tags.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Taxonomies extends WPAgent_Tool {

    private static $allowed_taxonomies = array( 'category', 'post_tag' );

    public function get_name() {
        return 'manage_taxonomies';
    }

    public function get_description() {
        return 'List, search, get, create, update, or delete WordPress categories and tags for site planning, editorial sections, keywords, and content organization.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'list', 'search', 'get', 'create', 'update', 'delete' ),
                    'description' => 'The taxonomy operation to perform.',
                ),
                'taxonomy' => array(
                    'type'        => 'string',
                    'enum'        => self::$allowed_taxonomies,
                    'description' => 'WordPress taxonomy to manage: category or post_tag.',
                ),
                'term_id' => array(
                    'type'        => 'integer',
                    'description' => 'Term ID for get, update, or delete.',
                ),
                'name' => array(
                    'type'        => 'string',
                    'description' => 'Term name for create or update.',
                ),
                'slug' => array(
                    'type'        => 'string',
                    'description' => 'Optional term slug for create or update.',
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'Optional term description for create or update.',
                ),
                'parent' => array(
                    'type'        => 'integer',
                    'description' => 'Optional parent category ID. Ignored for tags.',
                ),
                'search' => array(
                    'type'        => 'string',
                    'description' => 'Search string for search/list.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum number of terms to return. Default 50, max 100.',
                ),
            ),
            'required' => array( 'action', 'taxonomy' ),
        );
    }

    public function get_required_capability() {
        return 'manage_categories';
    }

    public function execute( array $params ) {
        $taxonomy = $this->sanitize_taxonomy( $params['taxonomy'] ?? '' );
        if ( '' === $taxonomy ) {
            return array( 'error' => 'taxonomy must be category or post_tag.' );
        }

        $action = $params['action'] ?? '';
        switch ( $action ) {
            case 'list':
            case 'search':
                return $this->list_terms( $taxonomy, $params );
            case 'get':
                return $this->get_term( $taxonomy, $params );
            case 'create':
                return $this->create_term( $taxonomy, $params );
            case 'update':
                return $this->update_term( $taxonomy, $params );
            case 'delete':
                return $this->delete_term( $taxonomy, $params );
            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    private function list_terms( $taxonomy, $params ) {
        $limit = max( 1, min( (int) ( $params['limit'] ?? 50 ), 100 ) );
        $args  = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => $limit,
            'orderby'    => 'name',
            'order'      => 'ASC',
        );

        if ( ! empty( $params['search'] ) ) {
            $args['search'] = sanitize_text_field( (string) $params['search'] );
        }

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            return array( 'error' => $terms->get_error_message() );
        }

        return array(
            'success'  => true,
            'taxonomy' => $taxonomy,
            'terms'    => array_map( array( $this, 'format_term' ), $terms ),
        );
    }

    private function get_term( $taxonomy, $params ) {
        $term_id = (int) ( $params['term_id'] ?? 0 );
        if ( $term_id <= 0 ) {
            return array( 'error' => 'term_id is required for get action.' );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return array( 'error' => 'Term not found.' );
        }

        return array(
            'success' => true,
            'term'    => $this->format_term( $term ),
        );
    }

    private function create_term( $taxonomy, $params ) {
        $name = sanitize_text_field( $params['name'] ?? '' );
        if ( '' === $name ) {
            return array( 'error' => 'name is required for create action.' );
        }

        $args = $this->term_args( $taxonomy, $params );
        $existing = $this->find_existing_term_for_create( $taxonomy, $name, $args['slug'] ?? '' );
        if ( $existing ) {
            return array(
                'success'  => true,
                'term'     => $this->format_term( $existing ),
                'message'  => 'Term already exists.',
                'existing' => true,
            );
        }

        $created = wp_insert_term( $name, $taxonomy, $args );
        if ( is_wp_error( $created ) ) {
            if ( 'term_exists' === $created->get_error_code() ) {
                $term_id = (int) $created->get_error_data();
                $term    = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;
                if ( $term && ! is_wp_error( $term ) ) {
                    return array(
                        'success'  => true,
                        'term'     => $this->format_term( $term ),
                        'message'  => 'Term already exists.',
                        'existing' => true,
                    );
                }
            }
            return array( 'error' => $created->get_error_message() );
        }

        $term = get_term( (int) $created['term_id'], $taxonomy );
        WPAgent::audit_log( $this->owner_id(), 'taxonomy_term_created', array(
            'taxonomy' => $taxonomy,
            'term_id'  => (int) $created['term_id'],
            'name'     => $name,
        ), $this->channel ?: 'agent' );

        return array(
            'success' => true,
            'term'    => $this->format_term( $term ),
            'message' => 'Term created.',
        );
    }

    private function find_existing_term_for_create( $taxonomy, $name, $slug = '' ) {
        $slug = sanitize_title( $slug );
        if ( '' !== $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( $term && ! is_wp_error( $term ) ) {
                return $term;
            }
        }

        $term = term_exists( $name, $taxonomy );
        if ( ! $term ) {
            return null;
        }

        $term_id = is_array( $term ) ? (int) ( $term['term_id'] ?? 0 ) : (int) $term;
        if ( $term_id <= 0 ) {
            return null;
        }

        $term = get_term( $term_id, $taxonomy );
        return $term && ! is_wp_error( $term ) ? $term : null;
    }

    private function update_term( $taxonomy, $params ) {
        $term_id = (int) ( $params['term_id'] ?? 0 );
        if ( $term_id <= 0 ) {
            return array( 'error' => 'term_id is required for update action.' );
        }

        $existing = get_term( $term_id, $taxonomy );
        if ( ! $existing || is_wp_error( $existing ) ) {
            return array( 'error' => 'Term not found.' );
        }

        $args = $this->term_args( $taxonomy, $params );
        if ( isset( $params['name'] ) && '' !== trim( (string) $params['name'] ) ) {
            $args['name'] = sanitize_text_field( $params['name'] );
        }
        if ( empty( $args ) ) {
            return array( 'error' => 'No update fields were provided.' );
        }

        $updated = wp_update_term( $term_id, $taxonomy, $args );
        if ( is_wp_error( $updated ) ) {
            return array( 'error' => $updated->get_error_message() );
        }

        $term = get_term( $term_id, $taxonomy );
        WPAgent::audit_log( $this->owner_id(), 'taxonomy_term_updated', array(
            'taxonomy' => $taxonomy,
            'term_id'  => $term_id,
        ), $this->channel ?: 'agent' );

        return array(
            'success' => true,
            'term'    => $this->format_term( $term ),
            'message' => 'Term updated.',
        );
    }

    private function delete_term( $taxonomy, $params ) {
        $term_id = (int) ( $params['term_id'] ?? 0 );
        if ( $term_id <= 0 ) {
            return array( 'error' => 'term_id is required for delete action.' );
        }

        $existing = get_term( $term_id, $taxonomy );
        if ( ! $existing || is_wp_error( $existing ) ) {
            return array( 'error' => 'Term not found.' );
        }
        if ( 'category' === $taxonomy && (int) get_option( 'default_category' ) === $term_id ) {
            return array( 'error' => 'The default category cannot be deleted.' );
        }

        $deleted = wp_delete_term( $term_id, $taxonomy );
        if ( is_wp_error( $deleted ) ) {
            return array( 'error' => $deleted->get_error_message() );
        }
        if ( ! $deleted ) {
            return array( 'error' => 'Term could not be deleted.' );
        }

        WPAgent::audit_log( $this->owner_id(), 'taxonomy_term_deleted', array(
            'taxonomy' => $taxonomy,
            'term_id'  => $term_id,
            'name'     => $existing->name,
            'slug'     => $existing->slug,
        ), $this->channel ?: 'agent' );

        return array(
            'success' => true,
            'term_id' => $term_id,
            'message' => 'Term deleted.',
        );
    }

    private function term_args( $taxonomy, $params ) {
        $args = array();

        if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
            $args['slug'] = sanitize_title( $params['slug'] );
        }
        if ( isset( $params['description'] ) ) {
            $args['description'] = sanitize_textarea_field( $params['description'] );
        }
        if ( 'category' === $taxonomy && isset( $params['parent'] ) && (int) $params['parent'] > 0 ) {
            $parent = get_term( (int) $params['parent'], 'category' );
            if ( $parent && ! is_wp_error( $parent ) ) {
                $args['parent'] = (int) $params['parent'];
            }
        }

        return $args;
    }

    private function sanitize_taxonomy( $taxonomy ) {
        $taxonomy = sanitize_key( (string) $taxonomy );
        return in_array( $taxonomy, self::$allowed_taxonomies, true ) ? $taxonomy : '';
    }

    private function format_term( $term ) {
        if ( is_wp_error( $term ) || ! $term ) {
            return null;
        }

        return array(
            'term_id'     => (int) $term->term_id,
            'taxonomy'    => (string) $term->taxonomy,
            'name'        => (string) $term->name,
            'slug'        => (string) $term->slug,
            'description' => (string) $term->description,
            'parent'      => isset( $term->parent ) ? (int) $term->parent : 0,
            'count'       => (int) $term->count,
            'edit_url'    => get_edit_term_link( (int) $term->term_id, (string) $term->taxonomy, 'post' ),
        );
    }
}
