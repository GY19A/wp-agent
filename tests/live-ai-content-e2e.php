<?php
/**
 * Live AI content automation acceptance.
 *
 * This test uses the configured OpenAI-compatible gateway and may incur cost.
 * Run only when explicitly enabled:
 *
 * WP_AGENT_LIVE_AI=1 wp eval-file wp-content/plugins/wp-agent/tests/live-ai-content-e2e.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This live AI acceptance script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_live_ai_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_live_ai_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_live_ai_fail( $message );
    }
}

if ( '1' !== (string) getenv( 'WP_AGENT_LIVE_AI' ) ) {
    echo wp_json_encode( array(
        'skipped' => true,
        'reason'  => 'Set WP_AGENT_LIVE_AI=1 to run the credentials-backed live AI acceptance.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    return;
}

global $wpdb;

$api_key = WPAgent::decrypt( WPAgent::get_option( 'meowl_api_key' ) );
wp_agent_live_ai_assert( '' !== $api_key, 'Configured AI gateway API key is required.' );

$model = (string) WPAgent::get_option( 'meowl_model', '' );
wp_agent_live_ai_assert( '' !== $model, 'Configured AI model is required.' );

$previous_mode            = get_option( 'wp_agent_mode', 'author' );
$previous_budget_sentinel = '__wp_agent_live_ai_missing_budget__';
$previous_budget          = get_option( 'wp_agent_monthly_budget', $previous_budget_sentinel );
$restored_environment     = false;
$restore_environment      = function() use ( &$restored_environment, $previous_mode, $previous_budget, $previous_budget_sentinel ) {
    if ( $restored_environment ) {
        return;
    }
    update_option( 'wp_agent_mode', $previous_mode );
    if ( $previous_budget_sentinel === $previous_budget ) {
        delete_option( 'wp_agent_monthly_budget' );
    } else {
        update_option( 'wp_agent_monthly_budget', $previous_budget );
    }
    WPAgent_Roles::ensure();
    $restored_environment = true;
};
register_shutdown_function( $restore_environment );

update_option( 'wp_agent_mode', 'administrator' );
WPAgent::update_option( 'monthly_budget', 0 );
WPAgent_Roles::ensure();

$requester_id = 1;
$user = get_user_by( 'id', $requester_id );
wp_agent_live_ai_assert( $user instanceof WP_User, 'Requester user #1 is required for live acceptance.' );

$agent_user_id = WPAgent_Roles::get_user_id();
wp_agent_live_ai_assert( $agent_user_id > 0, 'Bounded agent user is missing.' );

$definitions = ( new WPAgent_Tools() )->get_definitions_for_user( $agent_user_id );
$tool_names  = wp_list_pluck( $definitions, 'name' );
foreach ( array( 'plan', 'web', 'manage_taxonomies', 'manage_posts', 'manage_seo', 'journal' ) as $required_tool ) {
    wp_agent_live_ai_assert( in_array( $required_tool, $tool_names, true ), $required_tool . ' should be available to the live agent.' );
}

$conversation = new WPAgent_Conversation();
$stamp        = gmdate( 'Ymd-His' );
$source_url   = 'https://wordpress.org/news/';
$title        = 'WP Agent Live Verification ' . $stamp;
$channel_id   = 'live-ai-content-e2e-' . $stamp;

$conversation_id = $conversation->get_or_create( $requester_id, 'wpcli', $channel_id );
$prompt = "Run a live WP Agent content automation acceptance.\n"
    . "Use the available tools, not just prose. Do exactly this and keep the post as a draft:\n"
    . "1. Set a short plan.\n"
    . "2. Fetch this public source URL with the web tool: {$source_url}\n"
    . "3. Create or reuse a category named WP Agent Live Verification with slug wp-agent-live-verification.\n"
    . "4. Create a draft post titled \"{$title}\". Write original English content in 2 short paragraphs based on the fetched source only. Do not copy source text. Include source_urls with {$source_url} and a short source_notes value.\n"
    . "5. Update SEO for the created post with focus_keyword \"wp agent live verification\".\n"
    . "6. Add a journal note summarizing the live test.\n"
    . "7. Final response must be compact JSON with post_id, preview_url, source_url, and tools_used. Do not publish anything.";

$message_id = $conversation->add_message( $conversation_id, 'user', $prompt );
$run_id     = WPAgent_Runs::create( $conversation_id, $requester_id, $message_id, 'wpcli' );
wp_agent_live_ai_assert( $run_id > 0, 'Could not create live AI run.' );

$started_usage_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d",
    $requester_id
) );

$worker_results = array();
for ( $i = 0; $i < WPAgent_Agent::MAX_TOOL_LOOPS + 2; $i++ ) {
    $worker_results[] = WPAgent_Worker::run_once( $run_id );
    $run = WPAgent_Runs::get( $run_id );
    if ( $run && in_array( (string) $run->status, array( 'done', 'error', 'awaiting_confirmation', 'canceled' ), true ) ) {
        break;
    }
}

$run = WPAgent_Runs::get( $run_id );
wp_agent_live_ai_assert( $run, 'Live AI run disappeared.' );
wp_agent_live_ai_assert( 'done' === (string) $run->status, 'Live AI run did not finish successfully: ' . wp_json_encode( array(
    'status' => $run->status,
    'error'  => $run->error,
) ) );

$messages = $conversation->get_messages_for_display( $conversation_id, 0, 500 );
$tools_used = array();
$final_response = '';
foreach ( $messages as $message ) {
    if ( 'assistant' === $message['role'] && ! empty( $message['tool_calls'] ) ) {
        foreach ( (array) $message['tool_calls'] as $tool_call ) {
            if ( ! empty( $tool_call['name'] ) ) {
                $tools_used[] = (string) $tool_call['name'];
            }
        }
    }
    if ( 'assistant' === $message['role'] && empty( $message['tool_calls'] ) ) {
        $final_response = (string) $message['content'];
    }
}
$tools_used = array_values( array_unique( $tools_used ) );

foreach ( array( 'web', 'manage_taxonomies', 'manage_posts', 'manage_seo', 'journal' ) as $required_tool ) {
    wp_agent_live_ai_assert( in_array( $required_tool, $tools_used, true ), 'Live agent did not use required tool: ' . $required_tool . '. Used: ' . implode( ', ', $tools_used ) );
}

$post = get_page_by_title( $title, OBJECT, 'post' );
wp_agent_live_ai_assert( $post instanceof WP_Post, 'Expected live verification draft post was not created.' );
wp_agent_live_ai_assert( 'draft' === get_post_status( $post ), 'Live verification post must remain a draft.' );
wp_agent_live_ai_assert( false !== strpos( (string) $post->post_content, 'WordPress' ), 'Draft content should be based on the fetched WordPress source.' );

$source_urls = get_post_meta( $post->ID, '_wp_agent_source_urls', true );
if ( is_string( $source_urls ) ) {
    $decoded = json_decode( $source_urls, true );
    $source_urls = is_array( $decoded ) ? $decoded : array();
}
wp_agent_live_ai_assert( in_array( $source_url, (array) $source_urls, true ), 'Draft post did not retain the source URL.' );

$focus_keyword = get_post_meta( $post->ID, '_wp_agent_focus_keyword', true );
wp_agent_live_ai_assert( 'wp agent live verification' === $focus_keyword, 'SEO focus keyword was not stored.' );

$term = get_term_by( 'slug', 'wp-agent-live-verification', 'category' );
wp_agent_live_ai_assert( $term && ! is_wp_error( $term ), 'Live verification category was not created.' );
wp_agent_live_ai_assert( has_term( (int) $term->term_id, 'category', $post ), 'Draft post is not assigned to the live verification category.' );

$finished_usage_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d",
    $requester_id
) );
wp_agent_live_ai_assert( $finished_usage_count > $started_usage_count, 'Live AI run did not record usage rows.' );

$restore_environment();

echo wp_json_encode( array(
    'success'             => true,
    'run_id'              => (int) $run_id,
    'conversation_id'     => (int) $conversation_id,
    'post_id'             => (int) $post->ID,
    'post_status'         => get_post_status( $post ),
    'preview_url_present' => '' !== (string) get_preview_post_link( $post ),
    'source_url_retained' => true,
    'seo_keyword'         => $focus_keyword,
    'tools_used'          => $tools_used,
    'usage_rows_added'    => $finished_usage_count - $started_usage_count,
    'model'               => $model,
    'base_url_source'     => WPAgent_AI_Meowl::base_url_source(),
    'final_length'        => strlen( $final_response ),
    'worker_results'      => $worker_results,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
