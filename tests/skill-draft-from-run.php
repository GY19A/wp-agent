<?php
/**
 * WP Agent Skill draft-from-run acceptance checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/skill-draft-from-run.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This Skill draft-from-run script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_skill_draft_user_id']         = 0;
$GLOBALS['wp_agent_skill_draft_conversation_id'] = 0;
$GLOBALS['wp_agent_skill_draft_run_id']          = 0;
$GLOBALS['wp_agent_skill_draft_slug']            = '';
$GLOBALS['wp_agent_skill_draft_runtime_dirs']    = array();

function wp_agent_skill_draft_path_starts_with( $path, $parent ) {
    $path   = trailingslashit( wp_normalize_path( (string) $path ) );
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_skill_draft_delete_dir( $dir ) {
    $dir = wp_normalize_path( (string) $dir );
    if ( '' === $dir || ! is_dir( $dir ) ) {
        return;
    }
    $skills_root = wp_normalize_path( WPAgent_Sandbox::runtime_area_dir( 'skills' ) );
    if ( ! wp_agent_skill_draft_path_starts_with( $dir, $skills_root ) ) {
        return;
    }
    $items = scandir( $dir );
    if ( ! is_array( $items ) ) {
        return;
    }
    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }
        $path = $dir . '/' . $item;
        if ( is_dir( $path ) ) {
            wp_agent_skill_draft_delete_dir( $path );
        } else {
            unlink( $path );
        }
    }
    rmdir( $dir );
}

function wp_agent_skill_draft_cleanup() {
    global $wpdb;

    if ( $GLOBALS['wp_agent_skill_draft_slug'] && $GLOBALS['wp_agent_skill_draft_user_id'] > 0 ) {
        $manifest = WPAgent_Skills::local_runtime_manifest(
            (int) $GLOBALS['wp_agent_skill_draft_user_id'],
            $GLOBALS['wp_agent_skill_draft_slug']
        );
        if ( ! is_wp_error( $manifest ) && ! empty( $manifest['dir'] ) ) {
            $GLOBALS['wp_agent_skill_draft_runtime_dirs'][] = $manifest['dir'];
        }
    }

    foreach ( array_unique( $GLOBALS['wp_agent_skill_draft_runtime_dirs'] ) as $dir ) {
        wp_agent_skill_draft_delete_dir( $dir );
    }

    if ( $GLOBALS['wp_agent_skill_draft_slug'] && $GLOBALS['wp_agent_skill_draft_user_id'] > 0 ) {
        $wpdb->delete(
            $wpdb->prefix . 'wp_agent_skills',
            array(
                'user_id' => (int) $GLOBALS['wp_agent_skill_draft_user_id'],
                'slug'    => $GLOBALS['wp_agent_skill_draft_slug'],
            ),
            array( '%d', '%s' )
        );
    }

    if ( $GLOBALS['wp_agent_skill_draft_run_id'] > 0 ) {
        $run_id = (int) $GLOBALS['wp_agent_skill_draft_run_id'];
        $wpdb->delete( $wpdb->prefix . 'wp_agent_confirmations', array( 'run_id' => $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_run_events', array( 'run_id' => $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_journal', array( 'run_id' => $run_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_runs', array( 'id' => $run_id ), array( '%d' ) );
    }

    if ( $GLOBALS['wp_agent_skill_draft_conversation_id'] > 0 ) {
        $conversation_id = (int) $GLOBALS['wp_agent_skill_draft_conversation_id'];
        $wpdb->delete( $wpdb->prefix . 'wp_agent_messages', array( 'conversation_id' => $conversation_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'wp_agent_conversations', array( 'id' => $conversation_id ), array( '%d' ) );
    }
}

function wp_agent_skill_draft_fail( $message ) {
    wp_agent_skill_draft_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_skill_draft_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_skill_draft_fail( $message );
    }
}

function wp_agent_skill_draft_admin_id() {
    $admins = get_users( array(
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ) );
    return ! empty( $admins ) ? (int) $admins[0] : 0;
}

register_shutdown_function( 'wp_agent_skill_draft_cleanup' );

global $wpdb;

$user_id = wp_agent_skill_draft_admin_id();
wp_agent_skill_draft_assert( $user_id > 0, 'Administrator user is required for Skill draft acceptance.' );
$GLOBALS['wp_agent_skill_draft_user_id'] = $user_id;

WPAgent_Roles::ensure();
$agent_user = WPAgent_Roles::get_user_id();
wp_agent_skill_draft_assert( $agent_user > 0 && user_can( $agent_user, 'edit_posts' ), 'Bounded agent user must be able to execute manage_skills after confirmation.' );

$marker = 'skill-draft-run-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
$slug   = 'draft-from-run-' . strtolower( wp_generate_password( 6, false, false ) );
$GLOBALS['wp_agent_skill_draft_slug'] = $slug;

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( $user_id, 'wpcli', $marker );
$GLOBALS['wp_agent_skill_draft_conversation_id'] = $conversation_id;

$message_id = $conversation->add_message(
    $conversation_id,
    'user',
    'Turn this repeated editorial research and draft workflow into a reusable Skill with sources, SEO, quality checks, and journal handoff.'
);
$run_id = WPAgent_Runs::create( $conversation_id, $user_id, $message_id, 'wpcli' );
$GLOBALS['wp_agent_skill_draft_run_id'] = $run_id;

$conversation->add_message(
    $conversation_id,
    'assistant',
    'I searched public sources, created taxonomy structure, drafted a post, audited quality, and wrote a journal handoff.',
    array(
        'tool_calls' => array(
            array(
                'id'    => 'call_search_sources',
                'name'  => 'web',
                'input' => array(
                    'action' => 'search',
                    'query'  => 'public-source editorial workflow fixture',
                ),
            ),
        ),
    )
);
$conversation->add_message(
    $conversation_id,
    'tool',
    wp_json_encode( array( 'success' => true, 'result_count' => 2 ) ),
    array( 'tool_results' => array( 'tool_call_id' => 'call_search_sources' ) )
);
$conversation->add_message(
    $conversation_id,
    'assistant',
    'The repeatable workflow should preserve source URLs, prefer draft status, run content quality before approval, and summarize outputs.'
);

foreach ( array(
    array( 'web', 'search', 'success' ),
    array( 'manage_taxonomies', 'create', 'success' ),
    array( 'manage_posts', 'create', 'success' ),
    array( 'content_quality', 'audit_post', 'success' ),
    array( 'journal', 'add', 'success' ),
) as $event ) {
    WPAgent_Run_Events::add(
        $run_id,
        $user_id,
        'tool_call',
        'Tool call completed.',
        array(
            'tool'   => $event[0],
            'action' => $event[1],
            'status' => $event[2],
        )
    );
}
WPAgent_Runs::set_done( $run_id );

$tool = new WPAgent_Tool_Skills();
$tool->set_context( $agent_user, 'wpcli', $conversation_id, $user_id, $run_id );
$draft = $tool->execute( array(
    'action' => 'draft_from_run',
    'run_id' => $run_id,
    'name'   => 'Draft From Run Fixture',
    'slug'   => $slug,
) );

wp_agent_skill_draft_assert( ! empty( $draft['success'] ), 'draft_from_run should succeed: ' . wp_json_encode( $draft ) );
wp_agent_skill_draft_assert( $slug === ( $draft['draft']['slug'] ?? '' ), 'Draft slug should match requested slug.' );
wp_agent_skill_draft_assert( ! empty( $draft['draft']['requires_approval'] ), 'Draft result should make approval requirement explicit.' );
wp_agent_skill_draft_assert( false !== strpos( (string) ( $draft['draft']['body'] ?? '' ), '## Source Run' ), 'Draft body should include source run evidence.' );
wp_agent_skill_draft_assert( false !== strpos( (string) ( $draft['draft']['body'] ?? '' ), '## Tool Pattern' ), 'Draft body should include the observed tool pattern.' );
wp_agent_skill_draft_assert( in_array( 'manage_posts', $draft['draft']['observed_tools'] ?? array(), true ), 'Draft should include observed post tooling.' );
wp_agent_skill_draft_assert( null === WPAgent_Skills::get_by_slug( $user_id, $slug, false ), 'draft_from_run must not persist a Skill row.' );

$save_params = $draft['save_params'] ?? array();
wp_agent_skill_draft_assert( 'save' === ( $save_params['action'] ?? '' ), 'Draft should include save_params for a later explicit save.' );
$permissions = new WPAgent_Permissions();
wp_agent_skill_draft_assert( $permissions->requires_confirmation( 'manage_skills', $save_params ), 'Saving a generated Skill must require human confirmation.' );

$confirmation = WPAgent_Confirmations::create( array(
    'run_id'          => $run_id,
    'conversation_id' => $conversation_id,
    'user_id'         => $user_id,
    'actor_id'        => $agent_user,
    'channel'         => 'wpcli',
    'tool_name'       => 'manage_skills',
    'tool_call_id'    => 'call_save_generated_skill',
    'params'          => $save_params,
) );
wp_agent_skill_draft_assert( ! is_wp_error( $confirmation ) && ! empty( $confirmation['id'] ), is_wp_error( $confirmation ) ? $confirmation->get_error_message() : 'Confirmation should be created for generated Skill save.' );
WPAgent_Runs::set_awaiting_confirmation( $run_id, 'Awaiting approval to save generated Skill.', array( 'confirmation_id' => (int) $confirmation['id'], 'tool' => 'manage_skills' ) );
wp_agent_skill_draft_assert( null === WPAgent_Skills::get_by_slug( $user_id, $slug, false ), 'Generated Skill should not save before approval.' );

$approved = WPAgent_Confirmations::decide( (int) $confirmation['id'], $user_id, 'approved' );
wp_agent_skill_draft_assert( ! is_wp_error( $approved ) && WPAgent_Confirmations::STATUS_APPROVED === $approved['status'], 'Generated Skill confirmation should approve.' );

$saved = WPAgent::get_agent()->execute_confirmed_tool( (int) $confirmation['id'] );
wp_agent_skill_draft_assert( ! is_wp_error( $saved ) && ! empty( $saved['success'] ), is_wp_error( $saved ) ? $saved->get_error_message() : 'Approved generated Skill save should execute: ' . wp_json_encode( $saved ) );
wp_agent_skill_draft_assert( $slug === ( $saved['skill']['slug'] ?? '' ), 'Approved generated Skill save should preserve the slug.' );

$manifest = WPAgent_Skills::local_runtime_manifest( $user_id, $slug );
wp_agent_skill_draft_assert( ! is_wp_error( $manifest ), is_wp_error( $manifest ) ? $manifest->get_error_message() : 'Saved generated Skill should have a private runtime mirror.' );
$GLOBALS['wp_agent_skill_draft_runtime_dirs'][] = $manifest['dir'];
wp_agent_skill_draft_assert( wp_agent_skill_draft_path_starts_with( $manifest['dir'], WPAgent_Sandbox::runtime_area_dir( 'skills' ) ), 'Generated Skill runtime mirror must live under the private skills runtime area.' );
wp_agent_skill_draft_assert( ! wp_agent_skill_draft_path_starts_with( $manifest['dir'], WP_AGENT_PLUGIN_DIR ), 'Generated Skill runtime mirror must not live under the plugin directory.' );
wp_agent_skill_draft_assert( 'run_draft' === ( $manifest['lock']['source']['type'] ?? '' ), 'Generated Skill lock should retain run_draft source type.' );
wp_agent_skill_draft_assert( $run_id === (int) ( $manifest['lock']['source']['run_id'] ?? 0 ), 'Generated Skill lock should retain source run id.' );
wp_agent_skill_draft_assert( in_array( 'content_quality', $manifest['lock']['source']['tools'] ?? array(), true ), 'Generated Skill lock should retain observed tools.' );

$executed = WPAgent_Confirmations::get( (int) $confirmation['id'] );
wp_agent_skill_draft_assert( $executed && WPAgent_Confirmations::STATUS_EXECUTED === $executed['status'], 'Confirmation should be marked executed after saving generated Skill.' );

$result = array(
    'success'         => true,
    'run_id'          => $run_id,
    'skill_slug'      => $slug,
    'confirmation_id' => (int) $confirmation['id'],
    'runtime_dir'     => $manifest['dir'],
    'tools'           => $manifest['lock']['source']['tools'] ?? array(),
);

wp_agent_skill_draft_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
