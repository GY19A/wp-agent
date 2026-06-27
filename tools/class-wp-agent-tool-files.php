<?php
/**
 * Files tool — a private sandbox workspace for drafting intermediate artifacts.
 *
 * This is a scratch workspace (not the live site) for drafting markdown, HTML,
 * and text files before turning them into posts or pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Files extends WPAgent_Tool {

    public function get_name() {
        return 'manage_files';
    }

    public function get_description() {
        return 'A private sandbox workspace for drafting intermediate files (markdown, HTML, or plain text) — this is NOT the live website. '
            . 'Use it to draft, store, and revise long-form content before creating a post or page. '
            . 'Files are stored outside the web root and are only accessible to server-side WP Agent processes. '
            . 'Supported actions: write (create/overwrite a file), read (get a file\'s contents), list (see all files), delete (remove a file).';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'write', 'read', 'list', 'delete' ),
                    'description' => 'The operation to perform.',
                ),
                'path' => array(
                    'type'        => 'string',
                    'description' => 'Relative path within the sandbox (e.g. "draft.md", "notes/outline.txt"). For the list action, an optional subdirectory to list.',
                ),
                'content' => array(
                    'type'        => 'string',
                    'description' => 'File contents (required for write action).',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        if ( $this->owner_id() < 1 ) {
            return array( 'error' => 'No user context.' );
        }

        $action = $params['action'] ?? '';
        $path   = isset( $params['path'] ) ? sanitize_text_field( (string) $params['path'] ) : '';

        $broker  = new WPAgent_Sandbox_Broker();
        $sandbox = $broker->workspace( $this->conversation_id, $this->owner_id(), $this->run_id );

        switch ( $action ) {
            case 'write':
                return $this->write_file( $sandbox, $path, $params );
            case 'read':
                return $this->read_file( $sandbox, $path );
            case 'list':
                return $this->list_files( $sandbox, $path );
            case 'delete':
                return $this->delete_file( $sandbox, $path );
            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    private function write_file( $sandbox, $path, $params ) {
        $content = isset( $params['content'] ) ? (string) $params['content'] : '';

        $result = $sandbox->write( $path, $content );
        if ( is_wp_error( $result ) ) {
            return array( 'error' => $result->get_error_message() );
        }

        return array(
            'success' => true,
            'path'    => $result['rel'],
            'bytes'   => $result['bytes'],
            'message' => 'File stored in the private agent workspace. It is not web-accessible.',
        );
    }

    private function read_file( $sandbox, $path ) {
        $content = $sandbox->read( $path );
        if ( is_wp_error( $content ) ) {
            return array( 'error' => $content->get_error_message() );
        }

        return array(
            'success' => true,
            'path'    => $path,
            'content' => $content,
        );
    }

    private function list_files( $sandbox, $path ) {
        $files = $sandbox->list( $path );
        if ( is_wp_error( $files ) ) {
            return array( 'error' => $files->get_error_message() );
        }

        return array(
            'success' => true,
            'files'   => $files,
        );
    }

    private function delete_file( $sandbox, $path ) {
        $result = $sandbox->delete( $path );
        if ( is_wp_error( $result ) ) {
            return array( 'error' => $result->get_error_message() );
        }

        return array(
            'success' => true,
            'message' => 'Deleted ' . $path,
        );
    }
}
