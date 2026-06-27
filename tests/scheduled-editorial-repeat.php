<?php
/**
 * Deterministic repeated scheduled editorial workflow acceptance.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/scheduled-editorial-repeat.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This scheduled editorial repeat script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_sched_repeat_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_sched_repeat_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_sched_repeat_fail( $message );
    }
}

function wp_agent_sched_repeat_response( $message, $finish_reason = 'stop' ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'id'      => 'chatcmpl-wp-agent-scheduled-repeat',
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => 'wp-agent-scheduled-repeat-model',
            'choices' => array(
                array(
                    'index'         => 0,
                    'message'       => $message,
                    'finish_reason' => $finish_reason,
                ),
            ),
            'usage'   => array(
                'prompt_tokens'     => 20,
                'completion_tokens' => 12,
                'total_tokens'      => 32,
            ),
        ) ),
        'response' => array(
            'code'    => 200,
            'message' => 'OK',
        ),
        'cookies'  => array(),
    );
}

function wp_agent_sched_repeat_tool_call( $id, $name, $arguments ) {
    return array(
        'id'       => $id,
        'type'     => 'function',
        'function' => array(
            'name'      => $name,
            'arguments' => wp_json_encode( $arguments ),
        ),
    );
}

function wp_agent_sched_repeat_tool_results_after_last_user( $messages ) {
    $last_user_index = -1;
    foreach ( $messages as $index => $message ) {
        if ( 'user' === ( $message['role'] ?? '' ) ) {
            $last_user_index = $index;
        }
    }

    $results = array();
    foreach ( $messages as $index => $message ) {
        if ( $index <= $last_user_index || 'tool' !== ( $message['role'] ?? '' ) ) {
            continue;
        }
        $id = (string) ( $message['tool_call_id'] ?? '' );
        if ( '' === $id ) {
            continue;
        }
        $decoded = json_decode( (string) ( $message['content'] ?? '' ), true );
        $results[ $id ] = is_array( $decoded ) ? $decoded : array();
    }
    return $results;
}

function wp_agent_sched_repeat_current_tool_steps( $messages ) {
    $last_user_index = -1;
    foreach ( $messages as $index => $message ) {
        if ( 'user' === ( $message['role'] ?? '' ) ) {
            $last_user_index = $index;
        }
    }

    $steps = 0;
    foreach ( $messages as $index => $message ) {
        if ( $index > $last_user_index && 'assistant' === ( $message['role'] ?? '' ) && ! empty( $message['tool_calls'] ) ) {
            $steps++;
        }
    }
    return $steps;
}

function wp_agent_sched_repeat_cycle( $messages ) {
    $cycle = 0;
    foreach ( $messages as $message ) {
        if ( 'user' === ( $message['role'] ?? '' ) && false !== strpos( (string) ( $message['content'] ?? '' ), '[Scheduled task #' ) ) {
            $cycle++;
        }
    }
    return max( 1, $cycle );
}

function wp_agent_sched_repeat_result_value( $results, $id, $path, $fallback = 0 ) {
    $value = $results[ $id ] ?? null;
    foreach ( explode( '.', $path ) as $segment ) {
        if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
            return $fallback;
        }
        $value = $value[ $segment ];
    }
    return $value;
}

function wp_agent_sched_repeat_force_due( $schedule_id ) {
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'wp_agent_schedules',
        array( 'next_run' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
        array( 'id' => (int) $schedule_id ),
        array( '%s' ),
        array( '%d' )
    );
}

function wp_agent_sched_repeat_latest_run( $conversation_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wp_agent_runs WHERE conversation_id = %d ORDER BY id DESC LIMIT 1",
        (int) $conversation_id
    ) );
}

global $wpdb;

$previous = array(
    'mode'           => get_option( 'wp_agent_mode', 'author' ),
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$restored = false;
$restore = function() use ( &$restored, $previous ) {
    if ( $restored ) {
        return;
    }
    update_option( 'wp_agent_mode', $previous['mode'] );
    WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
    WPAgent::update_option( 'meowl_model', $previous['model'] );
    if ( $previous['budget_exists'] ) {
        update_option( 'wp_agent_monthly_budget', $previous['monthly_budget'] );
    } else {
        delete_option( 'wp_agent_monthly_budget' );
    }
    WPAgent_Roles::ensure();
    $restored = true;
};
register_shutdown_function( $restore );

update_option( 'wp_agent_mode', 'administrator' );
WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-scheduled-repeat-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-scheduled-repeat-model' );
WPAgent::update_option( 'monthly_budget', 0 );
WPAgent_Roles::ensure();

$agent_user = WPAgent_Roles::get_user_id();
wp_agent_sched_repeat_assert( $agent_user > 0, 'Bounded agent user is missing.' );

$http_calls = 0;
add_filter(
    'pre_http_request',
    function( $preempt, $parsed_args, $url ) use ( &$http_calls ) {
        if ( false === strpos( (string) $url, '/chat/completions' ) ) {
            return $preempt;
        }

        $http_calls++;
        $request  = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
        $messages = is_array( $request['messages'] ?? null ) ? $request['messages'] : array();
        $cycle    = min( 2, wp_agent_sched_repeat_cycle( $messages ) );
        $step     = wp_agent_sched_repeat_current_tool_steps( $messages );
        $label    = 1 === $cycle ? 'One' : 'Two';
        $title    = 'Scheduled Editorial Repeat Cycle ' . $label;

        if ( 0 === $step ) {
            return wp_agent_sched_repeat_response(
                array(
                    'role'       => 'assistant',
                    'content'    => 'Preparing scheduled editorial cycle ' . $label . '.',
                    'tool_calls' => array(
                        wp_agent_sched_repeat_tool_call( 'call_category_' . $cycle, 'manage_taxonomies', array(
                            'action'      => 'create',
                            'taxonomy'    => 'category',
                            'name'        => 'Editorial Repeat',
                            'slug'        => 'editorial-repeat-schedule',
                            'description' => 'Repeated scheduled editorial workflow acceptance.',
                        ) ),
                        wp_agent_sched_repeat_tool_call( 'call_post_' . $cycle, 'manage_posts', array(
                            'action'       => 'create',
                            'title'        => $title,
                            'content'      => '<p>This scheduled editorial acceptance draft proves that a bound Skill can run repeatedly from the same WordPress schedule.</p><p>Cycle ' . strtolower( $label ) . ' keeps source provenance, taxonomy, SEO metadata, and journal handoff without publishing.</p>',
                            'excerpt'      => 'Scheduled editorial repeat acceptance draft cycle ' . strtolower( $label ) . '.',
                            'status'       => 'draft',
                            'categories'   => array( 'Editorial Repeat' ),
                            'tags'         => array( 'Scheduled Workflow', 'Public Source' ),
                            'source_urls'  => array( 'https://wordpress.org/news/' ),
                            'source_notes' => 'Deterministic scheduled repeat acceptance using public WordPress news as the retained source.',
                        ) ),
                    ),
                ),
                'tool_calls'
            );
        }

        if ( 1 === $step ) {
            $results = wp_agent_sched_repeat_tool_results_after_last_user( $messages );
            $post_id = (int) wp_agent_sched_repeat_result_value( $results, 'call_post_' . $cycle, 'post_id' );
            return wp_agent_sched_repeat_response(
                array(
                    'role'       => 'assistant',
                    'content'    => 'Adding SEO and journal evidence for scheduled editorial cycle ' . $label . '.',
                    'tool_calls' => array(
                        wp_agent_sched_repeat_tool_call( 'call_seo_' . $cycle, 'manage_seo', array(
                            'action'           => 'update',
                            'post_id'          => $post_id,
                            'meta_title'       => $title,
                            'meta_description' => 'Scheduled editorial repeat acceptance with source retention and Skill-bound workflow evidence.',
                            'focus_keyword'    => 'scheduled editorial repeat',
                        ) ),
                        wp_agent_sched_repeat_tool_call( 'call_journal_' . $cycle, 'journal', array(
                            'action'     => 'add',
                            'entry_type' => 'decision',
                            'title'      => 'Scheduled editorial repeat cycle ' . $label,
                            'body'       => 'Completed cycle ' . strtolower( $label ) . ' of the repeated Skill-bound editorial schedule acceptance.',
                        ) ),
                    ),
                ),
                'tool_calls'
            );
        }

        return wp_agent_sched_repeat_response( array(
            'role'    => 'assistant',
            'content' => wp_json_encode( array(
                'scheduled_editorial_repeat' => true,
                'cycle'                      => $cycle,
                'tools_used'                 => array( 'manage_taxonomies', 'manage_posts', 'manage_seo', 'journal' ),
            ) ),
        ) );
    },
    10,
    3
);

$template = WPAgent_Skills::install_template( 1, 'news-site-operator' );
wp_agent_sched_repeat_assert( ! is_wp_error( $template ), is_wp_error( $template ) ? $template->get_error_message() : 'news-site-operator template install failed.' );

$schedule_id = WPAgent_Schedules::create(
    1,
    'Run one editorial repeat cycle. Create one original draft with retained source URL, SEO metadata, taxonomy, and a journal handoff. Keep the post as draft.',
    'minutes',
    null,
    null,
    5,
    'news-site-operator'
);
wp_agent_sched_repeat_assert( $schedule_id > 0, 'Repeated editorial schedule could not be created.' );

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( 1, 'schedule', 'schedule-' . $schedule_id );
$run_ids = array();
$worker_results = array();

for ( $cycle = 1; $cycle <= 2; $cycle++ ) {
    wp_agent_sched_repeat_force_due( $schedule_id );
    WPAgent_Schedules::check_and_run();

    $schedule = WPAgent_Schedules::get( $schedule_id );
    wp_agent_sched_repeat_assert( $schedule && 'queued' === (string) $schedule->last_status, 'Schedule cycle ' . $cycle . ' should queue a run.' );

    $run = wp_agent_sched_repeat_latest_run( $conversation_id );
    wp_agent_sched_repeat_assert( $run && (int) $run->id > 0, 'Cycle ' . $cycle . ' queued run should exist.' );
    $run_id = (int) $run->id;
    $run_ids[] = $run_id;

    for ( $i = 0; $i < WPAgent_Agent::MAX_TOOL_LOOPS + 2; $i++ ) {
        $result = WPAgent_Worker::run_once( $run_id );
        $worker_results[] = $result;
        $current = WPAgent_Runs::get( $run_id );
        if ( $current && in_array( (string) $current->status, array( 'done', 'error', 'awaiting_confirmation', 'canceled' ), true ) ) {
            break;
        }
    }

    $finished = WPAgent_Runs::get( $run_id );
    wp_agent_sched_repeat_assert( $finished && 'done' === (string) $finished->status, 'Cycle ' . $cycle . ' run should finish: ' . wp_json_encode( $worker_results ) );
    wp_agent_sched_repeat_assert( empty( $finished->locked_until ), 'Cycle ' . $cycle . ' run should not retain a lock.' );
}

$posts = get_posts( array(
    'post_type'      => 'post',
    'post_status'    => 'draft',
    'posts_per_page' => 10,
    'orderby'        => 'ID',
    'order'          => 'DESC',
    's'              => 'Scheduled Editorial Repeat Cycle',
) );

$found = array();
foreach ( $posts as $post ) {
    if ( 0 === strpos( $post->post_title, 'Scheduled Editorial Repeat Cycle ' ) ) {
        $found[ $post->post_title ] = $post;
    }
}
wp_agent_sched_repeat_assert( isset( $found['Scheduled Editorial Repeat Cycle One'], $found['Scheduled Editorial Repeat Cycle Two'] ), 'Both repeated scheduled draft posts should exist.' );

foreach ( $found as $title => $post ) {
    $post_id = (int) $post->ID;
    wp_agent_sched_repeat_assert( 'draft' === get_post_status( $post_id ), $title . ' should remain a draft.' );
    wp_agent_sched_repeat_assert( has_term( 'Editorial Repeat', 'category', $post_id ), $title . ' should have Editorial Repeat category.' );
    wp_agent_sched_repeat_assert( 'scheduled editorial repeat' === get_post_meta( $post_id, '_wp_agent_focus_keyword', true ), $title . ' should have SEO focus keyword.' );
    $source_urls = json_decode( (string) get_post_meta( $post_id, '_wp_agent_source_urls', true ), true );
    wp_agent_sched_repeat_assert( array( 'https://wordpress.org/news/' ) === $source_urls, $title . ' should retain the source URL.' );
}

$journal_rows = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_journal WHERE title LIKE %s",
    'Scheduled editorial repeat cycle%'
) );
wp_agent_sched_repeat_assert( $journal_rows >= 2, 'Both scheduled cycles should write journal handoff entries.' );

WPAgent_Schedules::set_status( $schedule_id, 'paused' );
$restore();

echo wp_json_encode( array(
    'success'        => true,
    'schedule_id'    => (int) $schedule_id,
    'run_ids'        => array_map( 'intval', $run_ids ),
    'post_ids'       => array(
        (int) $found['Scheduled Editorial Repeat Cycle One']->ID,
        (int) $found['Scheduled Editorial Repeat Cycle Two']->ID,
    ),
    'http_calls'     => (int) $http_calls,
    'journal_rows'   => $journal_rows,
    'schedule_status'=> 'paused',
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
