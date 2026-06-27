<?php
/**
 * WP Agent news-site goal through the real agent loop.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/news-site-agent-loop.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This news-site agent-loop script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_news_loop_fail( $message ) {
    if ( function_exists( 'wp_agent_news_loop_cleanup' ) ) {
        wp_agent_news_loop_cleanup();
    }
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_news_loop_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_news_loop_fail( $message );
    }
}

function wp_agent_news_loop_response( $message, $finish_reason = 'stop' ) {
    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'id'      => 'chatcmpl-wp-agent-news-loop',
            'object'  => 'chat.completion',
            'created' => time(),
            'model'   => 'wp-agent-test-model',
            'choices' => array(
                array(
                    'index'         => 0,
                    'message'       => $message,
                    'finish_reason' => $finish_reason,
                ),
            ),
            'usage'   => array(
                'prompt_tokens'     => 25,
                'completion_tokens' => 15,
                'total_tokens'      => 40,
            ),
        ) ),
        'response' => array(
            'code'    => 200,
            'message' => 'OK',
        ),
        'cookies'  => array(),
    );
}

function wp_agent_news_loop_tool_call( $id, $name, $arguments ) {
    return array(
        'id'       => $id,
        'type'     => 'function',
        'function' => array(
            'name'      => $name,
            'arguments' => wp_json_encode( $arguments ),
        ),
    );
}

function wp_agent_news_loop_tool_results( $messages ) {
    $results = array();
    foreach ( $messages as $message ) {
        if ( 'tool' !== ( $message['role'] ?? '' ) ) {
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

function wp_agent_news_loop_value( $results, $id, $path, $fallback = 0 ) {
    $value = $results[ $id ] ?? null;
    foreach ( explode( '.', $path ) as $segment ) {
        if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
            return $fallback;
        }
        $value = $value[ $segment ];
    }
    return $value;
}

function wp_agent_news_loop_term( $taxonomy, $slug, $name ) {
    $term = get_term_by( 'slug', $slug, $taxonomy );
    if ( $term && ! is_wp_error( $term ) ) {
        return $term;
    }
    $term = get_term_by( 'name', $name, $taxonomy );
    return $term && ! is_wp_error( $term ) ? $term : null;
}

function wp_agent_news_loop_delete_fixture_posts() {
    global $wpdb;

    $post_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_title = %s",
        'Public Institutions Expand Digital Policy Briefings'
    ) );

    foreach ( $post_ids as $post_id ) {
        $post_id = (int) $post_id;
        $notes   = (string) get_post_meta( $post_id, '_wp_agent_source_notes', true );
        if ( false === strpos( $notes, 'Agent-loop acceptance fixture' ) ) {
            continue;
        }
        wp_delete_post( $post_id, true );
    }
}

$GLOBALS['wp_agent_news_loop_previous'] = array(
    'mode'           => get_option( 'wp_agent_mode', 'author' ),
    'api_key'        => WPAgent::get_option( 'meowl_api_key', '' ),
    'model'          => WPAgent::get_option( 'meowl_model', '' ),
    'monthly_budget' => get_option( 'wp_agent_monthly_budget', null ),
    'budget_exists'  => false !== get_option( 'wp_agent_monthly_budget', false ),
);
$GLOBALS['wp_agent_news_loop_restored'] = false;

function wp_agent_news_loop_cleanup() {
    if ( ! empty( $GLOBALS['wp_agent_news_loop_restored'] ) ) {
        return;
    }

    $previous = $GLOBALS['wp_agent_news_loop_previous'];
    update_option( 'wp_agent_mode', $previous['mode'] );
    WPAgent::update_option( 'meowl_api_key', $previous['api_key'] );
    WPAgent::update_option( 'meowl_model', $previous['model'] );
    if ( $previous['budget_exists'] ) {
        update_option( 'wp_agent_monthly_budget', $previous['monthly_budget'] );
    } else {
        delete_option( 'wp_agent_monthly_budget' );
    }
    WPAgent_Roles::ensure();
    $GLOBALS['wp_agent_news_loop_restored'] = true;
}

register_shutdown_function( 'wp_agent_news_loop_cleanup' );

update_option( 'wp_agent_mode', 'administrator' );
WPAgent_Roles::ensure();
wp_agent_news_loop_delete_fixture_posts();

$agent_user = WPAgent_Roles::get_user_id();
wp_agent_news_loop_assert( $agent_user > 0, 'Bounded agent user is missing.' );

$registry = new WPAgent_Tools();
$definitions = $registry->get_definitions_for_user( $agent_user );
$tool_names = array();
foreach ( $definitions as $definition ) {
    $tool_names[] = $definition['name'] ?? '';
}
foreach ( array( 'manage_taxonomies', 'manage_pages', 'manage_menus', 'manage_posts', 'manage_seo', 'content_quality', 'manage_schedules', 'journal' ) as $required_tool ) {
    wp_agent_news_loop_assert( in_array( $required_tool, $tool_names, true ), $required_tool . ' should be available to the administrator-mode agent.' );
}

WPAgent::update_option( 'meowl_api_key', WPAgent::encrypt( 'wp-agent-test-key' ) );
WPAgent::update_option( 'meowl_model', 'wp-agent-test-model' );
WPAgent::update_option( 'monthly_budget', 0 );

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
        $tool_steps = 0;
        foreach ( $messages as $message ) {
            if ( 'assistant' === ( $message['role'] ?? '' ) && ! empty( $message['tool_calls'] ) ) {
                $tool_steps++;
            }
        }

        if ( 0 === $tool_steps ) {
            return wp_agent_news_loop_response(
                array(
                    'role'       => 'assistant',
                    'content'    => 'Planning the news site structure.',
                    'tool_calls' => array(
                        wp_agent_news_loop_tool_call( 'call_world_category', 'manage_taxonomies', array(
                            'action'      => 'create',
                            'taxonomy'    => 'category',
                            'name'        => 'World Desk',
                            'slug'        => 'world-desk-agent-loop',
                            'description' => 'Public-source international reporting.',
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_tech_category', 'manage_taxonomies', array(
                            'action'      => 'create',
                            'taxonomy'    => 'category',
                            'name'        => 'Technology',
                            'slug'        => 'technology-agent-loop',
                            'description' => 'Technology policy and infrastructure.',
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_source_tag', 'manage_taxonomies', array(
                            'action'   => 'create',
                            'taxonomy' => 'post_tag',
                            'name'     => 'Public Source',
                            'slug'     => 'public-source-agent-loop',
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_about_page', 'manage_pages', array(
                            'action'  => 'create',
                            'title'   => 'About WP Agent News Loop',
                            'content' => '<p>About page for an autonomous public-source news site.</p>',
                            'status'  => 'draft',
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_policy_page', 'manage_pages', array(
                            'action'  => 'create',
                            'title'   => 'Editorial Policy',
                            'content' => '<p>Editorial policy for source retention, corrections, and review workflow.</p>',
                            'status'  => 'draft',
                        ) ),
                    ),
                ),
                'tool_calls'
            );
        }

        $results = wp_agent_news_loop_tool_results( $messages );
        if ( 1 === $tool_steps ) {
            return wp_agent_news_loop_response(
                array(
                    'role'       => 'assistant',
                    'content'    => 'Creating navigation and the first original draft.',
                    'tool_calls' => array(
                        wp_agent_news_loop_tool_call( 'call_main_menu', 'manage_menus', array(
                            'action'    => 'create',
                            'menu_name' => 'WP Agent News Main Navigation',
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_draft_post', 'manage_posts', array(
                            'action'      => 'create',
                            'title'       => 'Public Institutions Expand Digital Policy Briefings',
                            'content'     => '<figure><img src="https://example.com/wp-content/uploads/digital-policy-briefing.png" alt="Illustration of a digital policy briefing" /><figcaption>Concept illustration of a public digital policy briefing.</figcaption></figure><h2>Why the briefings are expanding</h2><p>Public institutions are increasing digital policy briefings as governments review platform rules, data infrastructure, and cross-border public services. The new briefings focus on how agencies explain policy changes, publish source documents, and coordinate public notices across several departments. The shift reflects a broader move toward proactive communication, where institutions try to reach the public before confusion or misinformation spreads.</p><p>This is original acceptance content created for the WP Agent loop test. It keeps source URLs for provenance, avoids copying source language, and gives readers a concise account of why digital policy briefings matter for public access. Editors can expand the draft with interviews, local context, and follow-up documents before publication.</p><h2>What a briefing typically covers</h2><p>A standard briefing summarizes the policy change in plain language, links to the underlying source documents, and explains who is affected and from when. Agencies increasingly add a short FAQ that anticipates the questions residents and journalists ask most often, reducing the volume of repeated inquiries that staff would otherwise field individually.</p><p>Because the briefings are public-facing, accessibility matters as much as accuracy. Clear headings, short paragraphs, and consistent terminology help readers who are scanning quickly, while retained source links give specialists a path to verify every claim independently.</p><h2>How institutions coordinate</h2><p>Coordination across departments is a recurring theme. When a policy touches several agencies, a single confused message can undermine trust, so institutions align on terminology and timing before publishing. Shared templates and review checklists make that coordination repeatable rather than ad hoc, which is exactly the kind of recurring workflow this editorial loop is meant to support.</p><h2>What readers should take away</h2><p>For readers, the practical value is straightforward: a trustworthy, plainly written explanation of a policy change, backed by primary sources they can check. For newsrooms, the briefings are a reliable starting point that can be expanded with reporting rather than reproduced verbatim. That balance — original explanation plus verifiable sourcing — is the standard this draft is written to model.</p>',
                            'excerpt'     => 'An original, source-retained draft explaining why public institutions are expanding digital policy briefings and what those briefings cover.',
                            'status'      => 'draft',
                            'categories'  => array( 'World Desk', 'Technology' ),
                            'tags'        => array( 'Public Source', 'Digital Policy' ),
                            'source_urls' => array( 'https://www.un.org/press/en', 'https://www.reuters.com/world/' ),
                            'source_notes' => 'Agent-loop acceptance fixture: public-source style notes only.',
                        ) ),
                    ),
                ),
                'tool_calls'
            );
        }

        if ( 2 === $tool_steps ) {
            $menu_id = (int) wp_agent_news_loop_value( $results, 'call_main_menu', 'menu.menu_id' );
            $about_id = (int) wp_agent_news_loop_value( $results, 'call_about_page', 'page_id' );
            $policy_id = (int) wp_agent_news_loop_value( $results, 'call_policy_page', 'page_id' );
            $world_id = (int) wp_agent_news_loop_value( $results, 'call_world_category', 'term.term_id' );
            $post_id = (int) wp_agent_news_loop_value( $results, 'call_draft_post', 'post_id' );

            return wp_agent_news_loop_response(
                array(
                    'role'       => 'assistant',
                    'content'    => 'Finishing SEO, navigation, recurring workflow, and journal handoff.',
                    'tool_calls' => array(
                        wp_agent_news_loop_tool_call( 'call_home_link', 'manage_menus', array(
                            'action'  => 'add_custom_link',
                            'menu_id' => $menu_id,
                            'title'   => 'Home',
                            'url'     => home_url( '/' ),
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_about_menu', 'manage_menus', array(
                            'action'  => 'add_page',
                            'menu_id' => $menu_id,
                            'page_id' => $about_id,
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_policy_menu', 'manage_menus', array(
                            'action'  => 'add_page',
                            'menu_id' => $menu_id,
                            'page_id' => $policy_id,
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_world_menu', 'manage_menus', array(
                            'action'  => 'add_category',
                            'menu_id' => $menu_id,
                            'term_id' => $world_id,
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_seo_update', 'manage_seo', array(
                            'action'           => 'update',
                            'post_id'          => $post_id,
                            'meta_title'       => 'Digital Policy Briefings Expand',
                            'meta_description' => 'Public institutions are expanding digital policy briefings with retained source URLs, plain-language summaries, editorial categories, and a recurring workflow.',
                            'focus_keyword'    => 'digital policy briefings',
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_quality_gate', 'content_quality', array(
                            'action'  => 'audit_post',
                            'post_id' => $post_id,
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_daily_schedule', 'manage_schedules', array(
                            'action'           => 'create',
                            'natural_language' => 'daily at 8am',
                            'prompt'           => 'Find public-source stories for World Desk and Technology, retain source URLs, draft original summaries, add SEO metadata, and leave posts as drafts.',
                        ) ),
                        wp_agent_news_loop_tool_call( 'call_journal', 'journal', array(
                            'action'     => 'add',
                            'entry_type' => 'decision',
                            'title'      => 'News site agent-loop plan',
                            'body'       => 'Created categories, fixed pages, navigation, a source-retained draft, SEO metadata, and a recurring editorial workflow.',
                        ) ),
                    ),
                ),
                'tool_calls'
            );
        }

        return wp_agent_news_loop_response( array(
            'role'    => 'assistant',
            'content' => 'The news site foundation is ready: sections, policy pages, navigation, one source-retained draft, SEO metadata, and a daily editorial workflow are in place.',
        ) );
    },
    10,
    3
);

$conversation = new WPAgent_Conversation();
$conversation_id = $conversation->get_or_create( 1, 'wpcli', 'news-site-agent-loop-' . wp_generate_uuid4() );
wp_agent_news_loop_assert( $conversation_id > 0, 'Conversation should be created.' );

$message = 'Build this site into a Voice of America style public-source news site with categories, policy pages, navigation, a rewritten original draft, SEO metadata, and a recurring news workflow.';
$message_id = $conversation->add_message( $conversation_id, 'user', $message );
wp_agent_news_loop_assert( $message_id > 0, 'User message should be created.' );

$run_id = WPAgent_Runs::create( $conversation_id, 1, $message_id, 'wpcli' );
wp_agent_news_loop_assert( $run_id > 0, 'Run should be queued.' );

$results = array();
for ( $i = 0; $i < 12; $i++ ) {
    $result = WPAgent_Worker::run_once();
    $results[] = $result;
    if ( (int) ( $result['run_id'] ?? 0 ) === (int) $run_id && 'done' === ( $result['status'] ?? '' ) ) {
        break;
    }
}

$run = WPAgent_Runs::get( $run_id );
wp_agent_news_loop_assert( $run && 'done' === $run->status, 'Agent-loop run should complete: ' . wp_json_encode( $results ) );
wp_agent_news_loop_assert( (int) $run->loop_count >= 4, 'Agent loop should advance through multiple model/tool steps.' );
wp_agent_news_loop_assert( $http_calls >= 4, 'Fake model should be called for planning, tool steps, and final response.' );

$world = wp_agent_news_loop_term( 'category', 'world-desk-agent-loop', 'World Desk' );
$tech = wp_agent_news_loop_term( 'category', 'technology-agent-loop', 'Technology' );
$source_tag = wp_agent_news_loop_term( 'post_tag', 'public-source-agent-loop', 'Public Source' );
wp_agent_news_loop_assert( $world && $tech && $source_tag, 'Expected editorial taxonomy should exist.' );

$about = get_page_by_title( 'About WP Agent News Loop' );
$policy = get_page_by_title( 'Editorial Policy' );
wp_agent_news_loop_assert( $about && $policy, 'Expected fixed pages should exist.' );

$menu = wp_get_nav_menu_object( 'WP Agent News Main Navigation' );
wp_agent_news_loop_assert( $menu && ! is_wp_error( $menu ), 'Navigation menu should exist.' );
$items = wp_get_nav_menu_items( (int) $menu->term_id );
wp_agent_news_loop_assert( is_array( $items ) && count( $items ) >= 4, 'Navigation menu should have at least four items.' );

$posts = get_posts( array(
    'post_type'      => 'post',
    'post_status'    => 'draft',
    'title'          => 'Public Institutions Expand Digital Policy Briefings',
    'posts_per_page' => 1,
) );
wp_agent_news_loop_assert( ! empty( $posts ), 'Expected source-retained draft post should exist.' );
$post_id = (int) $posts[0]->ID;
wp_agent_news_loop_assert( has_term( 'World Desk', 'category', $post_id ), 'Draft should have World Desk category.' );
wp_agent_news_loop_assert( has_term( 'Technology', 'category', $post_id ), 'Draft should have Technology category.' );
wp_agent_news_loop_assert( has_term( 'Public Source', 'post_tag', $post_id ), 'Draft should have Public Source tag.' );
wp_agent_news_loop_assert( 'digital policy briefings' === get_post_meta( $post_id, '_wp_agent_focus_keyword', true ), 'SEO focus keyword should be stored.' );
$stored_sources = json_decode( (string) get_post_meta( $post_id, '_wp_agent_source_urls', true ), true );
wp_agent_news_loop_assert( array( 'https://www.un.org/press/en', 'https://www.reuters.com/world/' ) === $stored_sources, 'Source URLs should be retained.' );

$context_results = wp_agent_news_loop_tool_results( $conversation->get_context_messages( $conversation_id, 100 ) );
$quality_result = $context_results['call_quality_gate'] ?? array();
wp_agent_news_loop_assert( ! empty( $quality_result['success'] ), 'Quality gate should complete successfully.' );
wp_agent_news_loop_assert( in_array( $quality_result['status'] ?? '', array( 'pass', 'review' ), true ), 'Quality gate should not require revision.' );
wp_agent_news_loop_assert( (int) ( $quality_result['score'] ?? 0 ) >= 80, 'Quality score should be acceptance-ready.' );
wp_agent_news_loop_assert( (int) wp_agent_news_loop_value( $context_results, 'call_quality_gate', 'checks.provenance.source_count' ) >= 1, 'Quality gate should confirm retained source URLs.' );
wp_agent_news_loop_assert( in_array( wp_agent_news_loop_value( $context_results, 'call_quality_gate', 'checks.duplicate.risk', '' ), array( 'low', 'medium' ), true ), 'Quality gate should report acceptable duplicate risk.' );

$schedules = WPAgent_Schedules::all( 20, 1 );
$matching_schedule = null;
foreach ( $schedules as $schedule ) {
    if ( false !== strpos( (string) $schedule->prompt, 'World Desk and Technology' ) ) {
        $matching_schedule = $schedule;
        break;
    }
}
wp_agent_news_loop_assert( $matching_schedule && 'daily' === $matching_schedule->schedule_interval && '08:00' === $matching_schedule->time_of_day, 'Daily editorial workflow should be scheduled at 08:00.' );

$messages = $conversation->get_messages_for_display( $conversation_id, 0, 100 );
$final = '';
foreach ( array_reverse( $messages ) as $row ) {
    if ( 'assistant' === ( $row['role'] ?? '' ) && false !== strpos( (string) $row['content'], 'news site foundation is ready' ) ) {
        $final = (string) $row['content'];
        break;
    }
}
wp_agent_news_loop_assert( '' !== $final, 'Final assistant response should be stored.' );

$events = WPAgent_Run_Events::recent( $run_id, 30 );
$event_types = array_map(
    function( $event ) {
        return $event['event_type'] ?? '';
    },
    $events
);
wp_agent_news_loop_assert( in_array( 'claimed', $event_types, true ), 'Run should record claimed events.' );
wp_agent_news_loop_assert( in_array( 'done', $event_types, true ), 'Run should record done event.' );

wp_agent_news_loop_cleanup();

echo wp_json_encode( array(
    'success'         => true,
    'run_id'          => (int) $run_id,
    'conversation_id' => (int) $conversation_id,
    'http_calls'      => (int) $http_calls,
    'loop_count'      => (int) $run->loop_count,
    'post_id'         => $post_id,
    'menu_id'         => (int) $menu->term_id,
    'schedule_id'     => (int) $matching_schedule->id,
    'final'           => $final,
) ) . "\n";
