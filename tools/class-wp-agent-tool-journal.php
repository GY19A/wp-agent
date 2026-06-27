<?php
/**
 * Journal tool — durable working memory for the agent.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Journal extends WPAgent_Tool {

    public function get_name() {
        return 'journal';
    }

    public function get_description() {
        return 'Maintain the agent durable work journal. Record goals, plans, actions, generated assets, schedule changes, failures, decisions, and handoff notes; search or list recent entries before continuing prior work.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'add', 'recent', 'search' ),
                    'description' => 'add a journal entry, list recent entries, or search prior entries.',
                ),
                'entry_type' => array(
                    'type'        => 'string',
                    'enum'        => WPAgent_Journal::TYPES,
                    'description' => 'Entry type for add/recent filtering.',
                ),
                'title' => array(
                    'type'        => 'string',
                    'description' => 'Short title for add.',
                ),
                'body' => array(
                    'type'        => 'string',
                    'description' => 'Markdown-friendly body for add.',
                ),
                'query' => array(
                    'type'        => 'string',
                    'description' => 'Search text for search.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum entries to return, default 10, max 50.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        $action = $params['action'] ?? '';
        $limit  = max( 1, min( (int) ( $params['limit'] ?? 10 ), 50 ) );

        switch ( $action ) {
            case 'add':
                return $this->add_entry( $params );
            case 'recent':
                return array(
                    'success' => true,
                    'entries' => WPAgent_Journal::recent( $this->owner_id(), $limit, $params['entry_type'] ?? '' ),
                );
            case 'search':
                return array(
                    'success' => true,
                    'entries' => WPAgent_Journal::search( $this->owner_id(), $params['query'] ?? '', $limit ),
                );
            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    private function add_entry( $params ) {
        $title = trim( (string) ( $params['title'] ?? '' ) );
        $body  = trim( (string) ( $params['body'] ?? '' ) );

        if ( '' === $title || '' === $body ) {
            return array( 'error' => 'title and body are required for add.' );
        }

        $id = WPAgent_Journal::add(
            $this->owner_id(),
            $params['entry_type'] ?? 'note',
            $title,
            $body,
            array(),
            $this->conversation_id,
            $this->run_id
        );

        if ( ! $id ) {
            return array( 'error' => 'Journal entry could not be stored.' );
        }

        return array(
            'success'    => true,
            'journal_id' => $id,
            'message'    => 'Journal entry stored.',
        );
    }
}
