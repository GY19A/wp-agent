<?php
/**
 * Delegate tool — Hermes-style task delegation to parallel sub-agents.
 *
 * When called inside an async run, spawns one or more child runs, each in its
 * own isolated conversation with a restricted toolset and its own iteration
 * budget. The parent run pauses (awaiting_subagents) until every child finishes,
 * then resumes with the children's summaries injected as the tool result.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Delegate extends WPAgent_Tool {

    /** @var int Max parallel sub-tasks per delegate call. */
    const MAX_TASKS = 3;

    /** @var int Default per-child iteration budget. */
    const DEFAULT_CHILD_ITERATIONS = 50;

    /**
     * Default safe toolset for a leaf sub-agent (no delegate/code/settings/users).
     *
     * @var string[]
     */
    private static $default_child_tools = array(
        'manage_posts', 'manage_pages', 'web', 'manage_seo', 'content_quality',
        'manage_media', 'manage_taxonomies', 'journal', 'get_site_info', 'plan',
    );

    /** @var string[] Tools a sub-agent may never use. */
    private static $blocked_child_tools = array(
        'delegate', 'execute_code', 'manage_wp_agent_settings', 'manage_users',
    );

    public function get_name() {
        return 'delegate';
    }

    public function get_description() {
        return 'Delegate one or more independent sub-tasks to parallel sub-agents. Each sub-agent works in its own isolated context on its own iteration budget and returns only a final summary. Use this to split a large goal into focused parts (e.g. research, drafting, SEO) that run concurrently. Available only in the background Agent queue; this run will pause and resume automatically when the sub-agents finish.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'tasks' => array(
                    'type'        => 'array',
                    'description'  => 'Up to ' . self::MAX_TASKS . ' independent sub-tasks to run in parallel sub-agents.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'goal'    => array( 'type' => 'string', 'description' => 'Self-contained instruction for the sub-agent. Required.' ),
                            'context' => array( 'type' => 'string', 'description' => 'Background the sub-agent needs (facts, URLs, constraints). Optional.' ),
                            'label'   => array( 'type' => 'string', 'description' => 'Short label for this sub-task. Optional.' ),
                            'tools'   => array(
                                'type'        => 'array',
                                'description' => 'Optional allowlist of tool names the sub-agent may use (a subset of yours). Omit for a safe default research/drafting toolset.',
                                'items'       => array( 'type' => 'string' ),
                            ),
                        ),
                        'required'   => array( 'goal' ),
                    ),
                ),
            ),
            'required'   => array( 'tasks' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        $run_id = (int) $this->run_id;
        if ( $run_id <= 0 ) {
            return array( 'error' => 'Delegation requires the background agent queue. Use the Agent workspace chat so sub-agents can run asynchronously.' );
        }

        $run = WPAgent_Runs::get( $run_id );
        if ( ! $run ) {
            return array( 'error' => 'Could not load the current run for delegation.' );
        }

        $max_depth = (int) WPAgent::get_option( 'delegate_max_depth', 1 );
        if ( (int) $run->depth >= $max_depth ) {
            return array( 'error' => 'Maximum sub-agent depth reached; sub-agents cannot delegate further.' );
        }

        $tasks = ( isset( $params['tasks'] ) && is_array( $params['tasks'] ) ) ? array_values( $params['tasks'] ) : array();
        if ( empty( $tasks ) ) {
            return array( 'error' => 'Provide at least one task with a goal.' );
        }
        if ( count( $tasks ) > self::MAX_TASKS ) {
            return array( 'error' => sprintf( 'At most %d sub-tasks can be delegated at once.', self::MAX_TASKS ) );
        }

        $parent_policy = $this->parent_policy( $run );
        $group_id      = wp_generate_uuid4();
        $owner_id      = $this->owner_id();
        $conversation  = new WPAgent_Conversation();
        $created       = array();

        foreach ( $tasks as $i => $task ) {
            $goal = isset( $task['goal'] ) ? trim( (string) $task['goal'] ) : '';
            if ( '' === $goal ) {
                continue;
            }
            $context = isset( $task['context'] ) ? (string) $task['context'] : '';
            $label   = isset( $task['label'] ) ? sanitize_text_field( (string) $task['label'] ) : '';
            $tools   = ( isset( $task['tools'] ) && is_array( $task['tools'] ) ) ? $task['tools'] : array();

            $child_policy = $this->child_policy( $parent_policy, $tools );

            $child_conv = $conversation->get_or_create( $owner_id, 'agent', 'sa-' . $group_id . '-' . $i );

            $seed = '' !== $label ? '[' . $label . "]\n" . $goal : $goal;
            if ( '' !== $context ) {
                $seed .= "\n\n## Context\n" . $context;
            }
            $message_id = (int) $conversation->add_message( $child_conv, 'user', $seed );

            $child_run = (int) WPAgent_Runs::create_child(
                $child_conv,
                $owner_id,
                $message_id,
                $run_id,
                $group_id,
                (int) $run->depth + 1,
                $child_policy,
                'agent'
            );

            if ( $child_run <= 0 ) {
                continue;
            }

            $display = '' !== $label ? $label : ( 'task ' . ( $i + 1 ) );
            $created[] = array( 'run_id' => $child_run, 'label' => $display );

            WPAgent_Run_Events::add( $run_id, $owner_id, 'subagent_started', 'Spawned sub-agent.', array(
                'child_run_id'   => $child_run,
                'subagent_group' => $group_id,
                'label'          => $display,
            ) );
        }

        if ( empty( $created ) ) {
            return array( 'error' => 'No valid sub-tasks were provided.' );
        }

        return array(
            'awaiting_subagents' => true,
            'subagent_group'     => $group_id,
            'spawned'            => $created,
            'message'            => sprintf( 'Delegated %d sub-task(s) to parallel sub-agents. This run will resume automatically when they finish.', count( $created ) ),
        );
    }

    /**
     * The parent run's effective tool policy (if it was itself restricted).
     */
    private function parent_policy( $run ) {
        if ( isset( $run->tool_policy_json ) && '' !== (string) $run->tool_policy_json ) {
            $policy = json_decode( (string) $run->tool_policy_json, true );
            if ( is_array( $policy ) ) {
                return $policy;
            }
        }
        return array( 'restricted' => false );
    }

    /**
     * Build a restricted leaf policy for a child: requested-or-default tools,
     * intersected with the parent's allowlist, minus always-blocked tools.
     */
    private function child_policy( $parent_policy, $requested_tools ) {
        $requested = array();
        foreach ( (array) $requested_tools as $tool ) {
            $tool = sanitize_key( (string) $tool );
            if ( '' !== $tool ) {
                $requested[] = $tool;
            }
        }

        $allowed = ! empty( $requested ) ? $requested : self::$default_child_tools;

        // Intersect with the parent's allowlist when the parent was restricted.
        if ( ! empty( $parent_policy['restricted'] ) && ! empty( $parent_policy['allowed_tools'] ) && class_exists( 'WPAgent_Skills' ) ) {
            $parent_tools = array();
            foreach ( (array) $parent_policy['allowed_tools'] as $spec ) {
                $name = WPAgent_Skills::tool_name_from_permission_spec( $spec );
                if ( '' !== $name ) {
                    $parent_tools[] = $name;
                }
            }
            if ( ! empty( $parent_tools ) ) {
                $allowed = array_intersect( $allowed, $parent_tools );
            }
        }

        $allowed = array_values( array_diff( $allowed, self::$blocked_child_tools ) );

        return array(
            'restricted'     => true,
            'allowed_tools'  => $allowed,
            'network'        => true,
            'code_execution' => false,
            'max_iterations' => self::DEFAULT_CHILD_ITERATIONS,
        );
    }
}
