<?php
/**
 * WP Agent web tool usage tracking checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/web-usage-tracking.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This web usage tracking script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_web_usage_user_id'] = 0;
$GLOBALS['wp_agent_web_usage_filter']  = null;

function wp_agent_web_usage_cleanup() {
    global $wpdb;

    if ( null !== $GLOBALS['wp_agent_web_usage_filter'] ) {
        remove_filter( 'pre_http_request', $GLOBALS['wp_agent_web_usage_filter'], 10 );
    }

    if ( $GLOBALS['wp_agent_web_usage_user_id'] > 0 ) {
        $wpdb->delete(
            $wpdb->prefix . 'wp_agent_usage',
            array( 'user_id' => (int) $GLOBALS['wp_agent_web_usage_user_id'] ),
            array( '%d' )
        );
        wp_delete_user( (int) $GLOBALS['wp_agent_web_usage_user_id'] );
    }
}

function wp_agent_web_usage_fail( $message ) {
    wp_agent_web_usage_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_web_usage_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_web_usage_fail( $message );
    }
}

function wp_agent_web_usage_count( $user_id, $model ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_usage WHERE user_id = %d AND model = %s",
        (int) $user_id,
        (string) $model
    ) );
}

register_shutdown_function( 'wp_agent_web_usage_cleanup' );

$user_id = wp_insert_user( array(
    'user_login'   => 'wp-agent-web-usage-' . strtolower( wp_generate_password( 8, false, false ) ),
    'user_email'   => 'wp-agent-web-usage-' . wp_generate_uuid4() . '@example.test',
    'user_pass'    => wp_generate_password( 20, true, true ),
    'display_name' => 'WP Agent Web Usage Fixture',
    'role'         => 'subscriber',
) );
wp_agent_web_usage_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Temporary web usage user was not created.' );
$GLOBALS['wp_agent_web_usage_user_id'] = (int) $user_id;

$search_model = WPAgent_Cost_Tracker::web_usage_model( 'search' );
$fetch_model  = WPAgent_Cost_Tracker::web_usage_model( 'fetch' );

$http_calls = array();
$GLOBALS['wp_agent_web_usage_filter'] = function( $preempt, $parsed_args, $url ) use ( &$http_calls ) {
    $http_calls[] = (string) $url;

    if ( false !== strpos( (string) $url, 'html.duckduckgo.com/html/' ) ) {
        return array(
            'headers'  => array(),
            'body'     => '<html><body>'
                . '<a class="result__a" href="https://example.com/source-one">Source One</a>'
                . '<a class="result__snippet">First source snippet.</a>'
                . '<a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.org%2Fsource-two">Source Two</a>'
                . '<a class="result__snippet">Second source snippet.</a>'
                . '</body></html>',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'cookies'  => array(),
        );
    }

    if ( 'https://example.com/source-one' === (string) $url ) {
        return array(
            'headers'  => array( 'content-type' => 'text/html; charset=utf-8' ),
            'body'     => '<html><head><title>Fixture</title></head><body><article><h1>Fixture Source</h1><p>Public source text for web usage tracking.</p></article></body></html>',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'cookies'  => array(),
        );
    }

    return $preempt;
};
add_filter( 'pre_http_request', $GLOBALS['wp_agent_web_usage_filter'], 10, 3 );

$tool = new WPAgent_Tool_Web();
$tool->set_context( 1, 'wpcli', 0, (int) $user_id, 0 );

$blocked = $tool->execute( array(
    'action' => 'fetch',
    'url'    => 'http://127.0.0.1/private',
) );
wp_agent_web_usage_assert( ! empty( $blocked['error'] ), 'SSRF-blocked fetch should fail.' );
wp_agent_web_usage_assert( 0 === wp_agent_web_usage_count( (int) $user_id, $fetch_model ), 'Blocked fetch should not record web usage.' );

$search = $tool->execute( array(
    'action' => 'search',
    'query'  => 'wp agent web usage fixture',
    'limit'  => 2,
) );
wp_agent_web_usage_assert( ! empty( $search['success'] ), 'Fake web search should succeed: ' . wp_json_encode( $search ) );
wp_agent_web_usage_assert( ! empty( $search['usage_recorded'] ) && $search_model === ( $search['usage_model'] ?? '' ), 'Search result should report usage metadata.' );
wp_agent_web_usage_assert( 1 === wp_agent_web_usage_count( (int) $user_id, $search_model ), 'Successful search should record one web usage row.' );

$fetch = $tool->execute( array(
    'action' => 'fetch',
    'url'    => 'https://example.com/source-one',
) );
wp_agent_web_usage_assert( ! empty( $fetch['success'] ), 'Fake web fetch should succeed: ' . wp_json_encode( $fetch ) );
wp_agent_web_usage_assert( ! empty( $fetch['usage_recorded'] ) && $fetch_model === ( $fetch['usage_model'] ?? '' ), 'Fetch result should report usage metadata.' );
wp_agent_web_usage_assert( false !== strpos( (string) ( $fetch['text'] ?? '' ), 'Public source text' ), 'Fetch should return readable fixture text.' );
wp_agent_web_usage_assert( 1 === wp_agent_web_usage_count( (int) $user_id, $fetch_model ), 'Successful fetch should record one web usage row.' );

$tracker = new WPAgent_Cost_Tracker();
$summary = $tracker->get_usage_summary( (int) $user_id, 'month' );
wp_agent_web_usage_assert( 2 === (int) $summary['request_count'], 'Web usage should add two zero-cost usage requests.' );
wp_agent_web_usage_assert( 0.0 === (float) $summary['total_cost'], 'Web usage should be tracked as zero estimated cost.' );
wp_agent_web_usage_assert( 2 === (int) $summary['total_tokens_out'], 'Web usage should count request units in tokens_out.' );

$models = $tracker->get_model_breakdown( (int) $user_id, 'month' );
$model_names = wp_list_pluck( $models, 'model' );
wp_agent_web_usage_assert( in_array( $search_model, $model_names, true ), 'Model breakdown should include web search usage.' );
wp_agent_web_usage_assert( in_array( $fetch_model, $model_names, true ), 'Model breakdown should include web fetch usage.' );
wp_agent_web_usage_assert( 0.0 === $tracker->estimate_cost( $search_model, 0, 1 ), 'Web search usage should estimate zero cost.' );
wp_agent_web_usage_assert( 0.0 === $tracker->estimate_cost( $fetch_model, 0, 1 ), 'Web fetch usage should estimate zero cost.' );

$result = array(
    'success'       => true,
    'user_id'       => (int) $user_id,
    'search_model'  => $search_model,
    'fetch_model'   => $fetch_model,
    'request_count' => (int) $summary['request_count'],
    'total_cost'    => (float) $summary['total_cost'],
    'http_calls'    => count( $http_calls ),
);

wp_agent_web_usage_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
