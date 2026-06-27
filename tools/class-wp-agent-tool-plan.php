<?php
/**
 * Plan tool — maintain a short, visible task plan (todo list).
 *
 * Lets the agent lay out the steps for a multi-step or scheduled goal,
 * then mark each step complete as it works through them. The plan is
 * stored per-user in a short-lived transient so it survives across the
 * tool-use loop without polluting permanent storage.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Plan extends WPAgent_Tool {

    /** @var int Plan transient lifetime in seconds (2 hours). */
    const PLAN_TTL = 7200;

    public function get_name() {
        return 'plan';
    }

    public function get_description() {
        return 'Maintain a short visible task plan (todo list) for a multi-step goal: set the steps, then mark them complete as you go.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'set', 'get', 'complete' ),
                    'description' => 'set: replace the plan with new steps. get: return the current plan. complete: mark a step done.',
                ),
                'steps' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => 'The list of step descriptions (used with action "set").',
                ),
                'index' => array(
                    'type'        => 'integer',
                    'description' => 'Zero-based index of the step to mark complete (used with action "complete").',
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

        switch ( $action ) {
            case 'set':
                return $this->set_plan( $params['steps'] ?? array() );

            case 'complete':
                return $this->complete_step( $params['index'] ?? null );

            case 'get':
                return $this->build_result( $this->get_plan() );

            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    /**
     * Replace the current plan with a fresh set of steps.
     *
     * @param array $steps List of step description strings.
     * @return array
     */
    private function set_plan( $steps ) {
        if ( ! is_array( $steps ) || empty( $steps ) ) {
            return array( 'error' => 'steps must be a non-empty array of strings for the set action.' );
        }

        $plan = array();
        foreach ( $steps as $step ) {
            $text = sanitize_text_field( (string) $step );
            if ( '' === $text ) {
                continue;
            }
            $plan[] = array(
                'text' => $text,
                'done' => false,
            );
        }

        if ( empty( $plan ) ) {
            return array( 'error' => 'No valid steps provided.' );
        }

        $this->save_plan( $plan );

        return $this->build_result( $plan );
    }

    /**
     * Mark a single step complete by index.
     *
     * @param int|null $index Zero-based step index.
     * @return array
     */
    private function complete_step( $index ) {
        if ( ! is_numeric( $index ) ) {
            return array( 'error' => 'index is required for the complete action.' );
        }

        $index = (int) $index;
        $plan  = $this->get_plan();

        if ( empty( $plan ) ) {
            return array( 'error' => 'No plan exists yet. Call action "set" first.' );
        }

        if ( ! isset( $plan[ $index ] ) ) {
            return array( 'error' => 'No step at index ' . $index . '.' );
        }

        $plan[ $index ]['done'] = true;
        $this->save_plan( $plan );

        return $this->build_result( $plan );
    }

    /**
     * Load the current plan for the acting user.
     *
     * @return array
     */
    private function get_plan() {
        $plan = get_transient( $this->plan_key() );

        return is_array( $plan ) ? $plan : array();
    }

    /**
     * Persist the plan for the acting user.
     *
     * @param array $plan
     */
    private function save_plan( $plan ) {
        set_transient( $this->plan_key(), $plan, self::PLAN_TTL );
    }

    /**
     * Transient key for the acting user's plan.
     *
     * @return string
     */
    private function plan_key() {
        if ( $this->run_id > 0 ) {
            return 'wp_agent_plan_r' . $this->run_id;
        }
        if ( $this->conversation_id > 0 ) {
            return 'wp_agent_plan_c' . $this->conversation_id;
        }
        return 'wp_agent_plan_u' . $this->owner_id();
    }

    /**
     * Build the standard tool result for a plan.
     *
     * @param array $plan
     * @return array
     */
    private function build_result( $plan ) {
        $steps    = array();
        $rendered = array();

        foreach ( $plan as $entry ) {
            $text = (string) ( $entry['text'] ?? '' );
            $done = ! empty( $entry['done'] );

            $steps[]    = array(
                'step' => $text,
                'done' => $done,
            );
            $rendered[] = ( $done ? '[x] ' : '[ ] ' ) . $text;
        }

        return array(
            'success'  => true,
            'plan'     => $steps,
            'rendered' => implode( "\n", $rendered ),
        );
    }
}
