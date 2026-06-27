<?php
/**
 * Pages tool — create, edit, list, and delete WordPress pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Pages extends WPAgent_Tool {

    public function get_name() {
        return 'manage_pages';
    }

    public function get_description() {
        return 'Create, edit, list, or delete WordPress pages.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type' => 'string',
                    'enum' => array( 'create', 'edit', 'list', 'delete', 'get' ),
                    'description' => 'The operation to perform.',
                ),
                'page_id' => array(
                    'type' => 'integer',
                    'description' => 'Page ID (required for edit, delete, get).',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Page title.',
                ),
                'content' => array(
                    'type' => 'string',
                    'description' => 'Page content (HTML).',
                ),
                'status' => array(
                    'type' => 'string',
                    'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ),
                    'description' => 'Page status. Default: draft.',
                ),
                'parent_id' => array(
                    'type' => 'integer',
                    'description' => 'Parent page ID for hierarchical pages.',
                ),
                'template' => array(
                    'type' => 'string',
                    'description' => 'Page template filename.',
                ),
                'search' => array(
                    'type' => 'string',
                    'description' => 'Search query for listing.',
                ),
                'limit' => array(
                    'type' => 'integer',
                    'description' => 'Number of pages to return (default 10).',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_pages';
    }

    public function execute( array $params ) {
        switch ( $params['action'] ) {
            case 'create':
                $allowed_statuses = array( 'draft', 'publish', 'pending', 'private', 'future' );
                $status = isset( $params['status'] ) && in_array( $params['status'], $allowed_statuses, true )
                    ? $params['status'] : 'draft';

                // Publish funnel: downgrade to pending when the actor lacks the publish cap.
                $note = '';
                if ( in_array( $status, array( 'publish', 'future' ), true ) && ! user_can( $this->user_id, 'publish_pages' ) ) {
                    $status = 'pending';
                    $note   = 'Publishing requires approval in author mode — use request_approval.';
                }

                $data = array(
                    'post_title'   => sanitize_text_field( $params['title'] ?? 'Untitled Page' ),
                    'post_content' => wp_kses_post( $params['content'] ?? '' ),
                    'post_status'  => $status,
                    'post_type'    => 'page',
                    'post_parent'  => (int) ( $params['parent_id'] ?? 0 ),
                );
                $page_id = wp_insert_post( $data, true );
                if ( is_wp_error( $page_id ) ) {
                    return array( 'error' => $page_id->get_error_message() );
                }
                if ( ! empty( $params['template'] ) ) {
                    update_post_meta( $page_id, '_wp_page_template', sanitize_text_field( $params['template'] ) );
                }
                $response = array(
                    'success'     => true,
                    'page_id'     => $page_id,
                    'url'         => get_permalink( $page_id ),
                    'preview_url' => ( 'publish' === $status ) ? get_permalink( $page_id ) : get_preview_post_link( $page_id ),
                    'edit_url'    => get_edit_post_link( $page_id, 'raw' ),
                );
                if ( '' !== $note ) {
                    $response['note'] = $note;
                }
                return $response;

            case 'edit':
                if ( empty( $params['page_id'] ) ) return array( 'error' => 'page_id required.' );
                $page_check = get_post( $params['page_id'] );
                if ( ! $page_check || 'page' !== $page_check->post_type ) return array( 'error' => 'Page not found.' );

                $allowed_statuses_edit = array( 'draft', 'publish', 'pending', 'private', 'future' );
                $update = array( 'ID' => (int) $params['page_id'] );
                if ( isset( $params['title'] ) )   $update['post_title']   = sanitize_text_field( $params['title'] );
                if ( isset( $params['content'] ) ) $update['post_content'] = wp_kses_post( $params['content'] );
                $note = '';
                if ( isset( $params['status'] ) && in_array( $params['status'], $allowed_statuses_edit, true ) ) {
                    $new_status = $params['status'];
                    // Publish funnel: downgrade to pending when the actor lacks the publish cap.
                    if ( in_array( $new_status, array( 'publish', 'future' ), true ) && ! user_can( $this->user_id, 'publish_pages' ) ) {
                        $new_status = 'pending';
                        $note       = 'Publishing requires approval in author mode — use request_approval.';
                    }
                    $update['post_status'] = $new_status;
                }
                $result = wp_update_post( $update, true );
                if ( is_wp_error( $result ) ) return array( 'error' => $result->get_error_message() );
                $current_status = get_post_status( $params['page_id'] );
                $response = array(
                    'success'     => true,
                    'page_id'     => $params['page_id'],
                    'message'     => 'Page updated.',
                    'url'         => get_permalink( $params['page_id'] ),
                    'preview_url' => ( 'publish' === $current_status ) ? get_permalink( $params['page_id'] ) : get_preview_post_link( $params['page_id'] ),
                    'edit_url'    => get_edit_post_link( $params['page_id'], 'raw' ),
                );
                if ( '' !== $note ) {
                    $response['note'] = $note;
                }
                return $response;

            case 'list':
                $args = array(
                    'post_type'      => 'page',
                    'posts_per_page' => min( (int) ( $params['limit'] ?? 10 ), 50 ),
                    'post_status'    => 'any',
                    'orderby'        => 'menu_order',
                    'order'          => 'ASC',
                );
                if ( ! empty( $params['search'] ) ) $args['s'] = $params['search'];
                $query = new WP_Query( $args );
                $pages = array();
                foreach ( $query->posts as $p ) {
                    $pages[] = array(
                        'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
                        'url' => get_permalink( $p ), 'parent' => $p->post_parent,
                        'template' => get_post_meta( $p->ID, '_wp_page_template', true ) ?: 'default',
                    );
                }
                return array( 'total' => $query->found_posts, 'pages' => $pages );

            case 'get':
                if ( empty( $params['page_id'] ) ) return array( 'error' => 'page_id required.' );
                $page = get_post( $params['page_id'] );
                if ( ! $page || 'page' !== $page->post_type ) return array( 'error' => 'Page not found.' );
                return array(
                    'id' => $page->ID, 'title' => $page->post_title, 'content' => $page->post_content,
                    'status' => $page->post_status, 'url' => get_permalink( $page ),
                    'template' => get_post_meta( $page->ID, '_wp_page_template', true ) ?: 'default',
                );

            case 'delete':
                if ( empty( $params['page_id'] ) ) return array( 'error' => 'page_id required.' );
                $page = get_post( $params['page_id'] );
                if ( ! $page ) return array( 'error' => 'Page not found.' );
                wp_trash_post( $params['page_id'] );
                return array( 'success' => true, 'message' => sprintf( 'Page "%s" trashed.', $page->post_title ) );

            default:
                return array( 'error' => 'Unknown action.' );
        }
    }
}
