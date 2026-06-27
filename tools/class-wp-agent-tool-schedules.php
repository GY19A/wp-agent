<?php
/**
 * Schedules tool — create and manage recurring scheduled agent tasks.
 *
 * Lets the agent set up recurring runs (minutes/hourly/daily/weekly) that later
 * execute the full synchronous agent loop as the bounded wp-agent identity.
 * Each scheduled run is owned by the user who created it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Schedules extends WPAgent_Tool {

    public function get_name() {
        return 'manage_schedules';
    }

    public function get_description() {
        return 'Create, parse, and manage recurring scheduled agent tasks from structured fields or common natural-language schedule phrases, optionally bound to a saved Skill playbook.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'create', 'parse', 'list', 'delete', 'pause', 'resume', 'run_now' ),
                    'description' => 'The operation to perform.',
                ),
                'natural_language' => array(
                    'type'        => 'string',
                    'description' => 'Optional natural-language cadence phrase to parse, such as "every 15 minutes", "daily at 8am", or "每天早上8点".',
                ),
                'prompt' => array(
                    'type'        => 'string',
                    'description' => 'The instruction to run on each scheduled execution (required for create).',
                ),
                'interval' => array(
                    'type'        => 'string',
                    'enum'        => array( 'minutes', 'hourly', 'daily', 'weekly' ),
                    'description' => 'How often to run: minutes, hourly, daily, or weekly (for create; defaults to daily).',
                ),
                'interval_minutes' => array(
                    'type'        => 'integer',
                    'description' => 'Number of minutes between runs when interval is minutes. Defaults to 5; minimum 1, maximum 1440.',
                ),
                'time' => array(
                    'type'        => 'string',
                    'description' => 'Time of day in 24-hour HH:MM (site timezone) for daily/weekly schedules. Defaults to 09:00.',
                ),
                'day_of_week' => array(
                    'type'        => 'integer',
                    'description' => 'Day of week for weekly schedules: 0=Sunday through 6=Saturday.',
                ),
                'schedule_id' => array(
                    'type'        => 'integer',
                    'description' => 'ID of the schedule to delete, pause, resume, or run now.',
                ),
                'skill_slug' => array(
                    'type'        => 'string',
                    'description' => 'Optional saved Skill slug to bind. The Skill playbook is injected into each scheduled run.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        if ( ! class_exists( 'WPAgent_Schedules' ) ) {
            return array( 'error' => 'Scheduling is not available.' );
        }

        $action = $params['action'] ?? '';

        switch ( $action ) {
            case 'create':
                return $this->create( $params );
            case 'parse':
                return $this->parse( $params );
            case 'list':
                return $this->list_schedules();
            case 'delete':
                return $this->delete( $params );
            case 'pause':
                return $this->set_status( $params, 'paused' );
            case 'resume':
                return $this->set_status( $params, 'active' );
            case 'run_now':
                return $this->run_now( $params );
            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    /**
     * Create a new recurring schedule.
     */
    private function create( $params ) {
        $prompt = isset( $params['prompt'] ) ? trim( (string) $params['prompt'] ) : '';
        if ( '' === $prompt ) {
            return array( 'error' => 'prompt is required for create action.' );
        }

        $has_interval = isset( $params['interval'] ) && '' !== trim( (string) $params['interval'] );
        $has_minutes  = array_key_exists( 'interval_minutes', $params ) && '' !== (string) $params['interval_minutes'];
        $has_time     = isset( $params['time'] ) && '' !== trim( (string) $params['time'] );
        $has_day      = array_key_exists( 'day_of_week', $params ) && '' !== (string) $params['day_of_week'];

        $natural_language = isset( $params['natural_language'] ) ? trim( (string) $params['natural_language'] ) : '';
        if ( '' === $natural_language && ! $has_interval && ! $has_minutes && ! $has_time && ! $has_day ) {
            $natural_language = $prompt;
        }

        $parsed = '' !== $natural_language ? WPAgent_Schedules::parse_natural_language( $natural_language ) : null;

        $interval    = $has_interval ? sanitize_key( $params['interval'] ) : ( $parsed['interval'] ?? 'daily' );
        $minutes     = $has_minutes ? (int) $params['interval_minutes'] : ( $parsed['interval_minutes'] ?? null );
        $time        = $has_time ? sanitize_text_field( $params['time'] ) : ( $parsed['time'] ?? null );
        $day_of_week = $has_day ? (int) $params['day_of_week'] : ( $parsed['day_of_week'] ?? null );
        $skill_slug  = isset( $params['skill_slug'] ) ? sanitize_title( $params['skill_slug'] ) : '';

        $id = WPAgent_Schedules::create( $this->owner_id(), $prompt, $interval, $time, $day_of_week, $minutes, $skill_slug );

        if ( ! $id ) {
            return array( 'error' => 'Could not create schedule. Check the interval, prompt, and optional skill_slug.' );
        }

        $schedule = WPAgent_Schedules::get( $id );

        return array(
            'success'          => true,
            'schedule_id'      => $id,
            'interval'         => $schedule ? (string) $schedule->schedule_interval : $interval,
            'interval_minutes' => $schedule && isset( $schedule->interval_minutes ) ? (int) $schedule->interval_minutes : $minutes,
            'time'             => $schedule ? (string) $schedule->time_of_day : $time,
            'day_of_week'      => $schedule && null !== $schedule->day_of_week ? (int) $schedule->day_of_week : $day_of_week,
            'next_run'         => $schedule ? $schedule->next_run : null,
            'skill_slug'       => $schedule ? (string) ( $schedule->skill_slug ?? '' ) : '',
            'parsed_schedule'  => $parsed,
            'message'          => 'Schedule created.',
        );
    }

    /**
     * Parse a natural-language schedule phrase without creating a schedule.
     */
    private function parse( $params ) {
        $text = isset( $params['natural_language'] ) ? trim( (string) $params['natural_language'] ) : '';
        if ( '' === $text && isset( $params['prompt'] ) ) {
            $text = trim( (string) $params['prompt'] );
        }
        if ( '' === $text ) {
            return array( 'error' => 'natural_language is required for parse action.' );
        }

        return array(
            'success' => true,
            'parsed'  => WPAgent_Schedules::parse_natural_language( $text ),
        );
    }

    /**
     * List all schedules.
     */
    private function list_schedules() {
        $schedules = array();

        foreach ( WPAgent_Schedules::all( 100, $this->owner_id() ) as $s ) {
            $schedules[] = array(
                'id'       => (int) $s->id,
                'prompt'   => (string) $s->prompt,
                'skill_slug' => (string) ( $s->skill_slug ?? '' ),
                'interval' => (string) $s->schedule_interval,
                'interval_minutes' => isset( $s->interval_minutes ) ? (int) $s->interval_minutes : null,
                'time'     => isset( $s->time_of_day ) ? (string) $s->time_of_day : null,
                'day_of_week' => isset( $s->day_of_week ) ? ( null === $s->day_of_week ? null : (int) $s->day_of_week ) : null,
                'next_run' => (string) $s->next_run,
                'status'   => (string) $s->status,
                'last_run' => null !== $s->last_run ? (string) $s->last_run : null,
                'last_run_id' => isset( $s->last_run_id ) ? ( null === $s->last_run_id ? null : (int) $s->last_run_id ) : null,
                'last_status' => isset( $s->last_status ) ? (string) $s->last_status : '',
                'last_summary' => isset( $s->last_summary ) ? (string) $s->last_summary : '',
            );
        }

        return array(
            'success'   => true,
            'schedules' => $schedules,
        );
    }

    /**
     * Delete a schedule.
     */
    private function delete( $params ) {
        $id = (int) ( $params['schedule_id'] ?? 0 );
        if ( $id <= 0 ) {
            return array( 'error' => 'schedule_id is required for delete action.' );
        }

        $deleted = WPAgent_Schedules::delete( $id, $this->owner_id() );

        if ( ! $deleted ) {
            return array( 'error' => 'Schedule not found.' );
        }

        return array(
            'success'     => true,
            'schedule_id' => $id,
            'message'     => 'Schedule deleted.',
        );
    }

    /**
     * Pause or resume a schedule.
     */
    private function set_status( $params, $status ) {
        $id = (int) ( $params['schedule_id'] ?? 0 );
        if ( $id <= 0 ) {
            return array( 'error' => 'schedule_id is required for this action.' );
        }

        if ( ! WPAgent_Schedules::get( $id, $this->owner_id() ) ) {
            return array( 'error' => 'Schedule not found.' );
        }

        WPAgent_Schedules::set_status( $id, $status, $this->owner_id() );

        return array(
            'success'     => true,
            'schedule_id' => $id,
            'status'      => $status,
            'message'     => 'paused' === $status ? 'Schedule paused.' : 'Schedule resumed.',
        );
    }

    /**
     * Run a schedule immediately.
     */
    private function run_now( $params ) {
        $id = (int) ( $params['schedule_id'] ?? 0 );
        if ( $id <= 0 ) {
            return array( 'error' => 'schedule_id is required for run_now action.' );
        }

        if ( ! WPAgent_Schedules::get( $id, $this->owner_id() ) ) {
            return array( 'error' => 'Schedule not found.' );
        }

        $result = WPAgent_Schedules::run( $id );

        if ( empty( $result['ok'] ) ) {
            return array(
                'error'  => $result['summary'] ?? 'Schedule could not be run.',
                'status' => $result['status'] ?? 'error',
            );
        }

        return array(
            'success'     => true,
            'schedule_id' => $id,
            'status'      => $result['status'] ?? null,
            'run_id'      => $result['run_id'] ?? null,
            'summary'     => $result['summary'] ?? '',
        );
    }
}
