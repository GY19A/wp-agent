<?php
/**
 * Runtime tool — inspect workspace, daemon, isolation tier, and diagnostics.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Runtime extends WPAgent_Tool {

    public function get_name() {
        return 'runtime';
    }

    public function get_description() {
        return 'Inspect this agent session runtime: daemon/sub-agent status, persistent workspace scope, PHP/OPcache/queue diagnostics, and available native PHP or namespace isolation backends. Use this before asking for code execution.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'status' ),
                    'description' => 'Return workspace and backend status.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        $broker    = new WPAgent_Sandbox_Broker();
        $workspace = $broker->workspace( $this->conversation_id, $this->owner_id(), $this->run_id );
        $root      = $workspace->root();
        $daemon    = WPAgent_Daemon::status();

        return array(
            'success'         => true,
            'conversation_id' => $this->conversation_id,
            'workspace_scope' => $this->conversation_id > 0 ? 'conversation' : 'user-fallback',
            'workspace_ready' => ! is_wp_error( $root ),
            'workspace_error' => is_wp_error( $root ) ? $root->get_error_message() : null,
            'daemon'          => $daemon,
            'diagnostics'     => WPAgent_Diagnostics::runtime( array( 'daemon' => $daemon ) ),
            'runtime'         => $broker->status(),
        );
    }
}
