<?php
/**
 * Token usage and cost tracking.
 *
 * Tracks API usage per user, calculates estimated costs,
 * and triggers budget alerts. Directly addresses OpenClaw's
 * biggest user complaint: surprise $300-700/month bills.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Cost_Tracker {

    /**
     * Record token usage for an API call.
     *
     * @param int    $user_id
     * @param string $model      Model ID used.
     * @param int    $tokens_in  Input tokens consumed.
     * @param int    $tokens_out Output tokens generated.
     * @param int    $cached_tokens Cached input tokens (subset of $tokens_in).
     */
    public function record( $user_id, $model, $tokens_in, $tokens_out, $cached_tokens = 0 ) {
        global $wpdb;

        $cost = $this->estimate_cost( $model, $tokens_in, $tokens_out, $cached_tokens );

        $wpdb->insert(
            $wpdb->prefix . 'wp_agent_usage',
            array(
                'user_id'        => $user_id,
                'model'          => $model,
                'tokens_in'      => $tokens_in,
                'tokens_out'     => $tokens_out,
                'estimated_cost' => $cost,
                'created_at'     => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%d', '%d', '%f', '%s' )
        );
    }

    /**
     * Record generated image usage.
     *
     * The usage table is token-oriented, so generated images are stored with
     * tokens_out as the image count and an image-prefixed model key.
     *
     * @param int    $user_id
     * @param string $model Image model ID, or empty for provider default.
     * @param string $size  Requested image size.
     * @param int    $count Number of images generated.
     */
    public function record_image( $user_id, $model, $size, $count = 1 ) {
        global $wpdb;

        $count = max( 1, (int) $count );
        $cost  = $this->estimate_image_cost( $model, $size, $count );

        $wpdb->insert(
            $wpdb->prefix . 'wp_agent_usage',
            array(
                'user_id'        => (int) $user_id,
                'model'          => self::image_usage_model( $model, $size ),
                'tokens_in'      => 0,
                'tokens_out'     => $count,
                'estimated_cost' => $cost,
                'created_at'     => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%d', '%d', '%f', '%s' )
        );
    }

    /**
     * Record web/search tool usage.
     *
     * Web research is normally keyless in this plugin, so cost is recorded as
     * zero while request count remains visible in usage summaries and model
     * breakdowns.
     *
     * @param int    $user_id
     * @param string $action search|fetch.
     * @param int    $count  Number of tool requests represented.
     */
    public function record_web( $user_id, $action, $count = 1 ) {
        global $wpdb;

        $count = max( 1, (int) $count );

        $wpdb->insert(
            $wpdb->prefix . 'wp_agent_usage',
            array(
                'user_id'        => (int) $user_id,
                'model'          => self::web_usage_model( $action ),
                'tokens_in'      => 0,
                'tokens_out'     => $count,
                'estimated_cost' => 0.0,
                'created_at'     => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%d', '%d', '%f', '%s' )
        );
    }

    /**
     * Estimate cost for a given model and token count.
     *
     * Prompt-cache hits (cached_tokens, a subset of tokens_in) are billed at a
     * model-specific discounted rate. Non-cached input is billed at full price.
     *
     * @param string $model
     * @param int    $tokens_in
     * @param int    $tokens_out
     * @param int    $cached_tokens Cached input tokens (subset of $tokens_in).
     * @return float Estimated cost in USD.
     */
    public function estimate_cost( $model, $tokens_in, $tokens_out, $cached_tokens = 0 ) {
        if ( 0 === strpos( (string) $model, 'image:' ) ) {
            $parts = explode( ':', (string) $model, 3 );
            return $this->estimate_image_cost( $parts[1] ?? '', $parts[2] ?? '', max( 1, (int) $tokens_out ) );
        }

        if ( 0 === strpos( (string) $model, 'web:' ) ) {
            return 0.0;
        }

        $pricing = $this->get_model_pricing( $model );

        // Cached input tokens are a subset of tokens_in; bill them at the
        // model's cache discount and the remainder at full input price.
        $cached_tokens = max( 0, min( (int) $cached_tokens, (int) $tokens_in ) );
        $fresh_input   = (int) $tokens_in - $cached_tokens;
        $cache_ratio   = $this->get_cache_ratio( $model );

        $input_cost  = ( $fresh_input / 1000000 ) * $pricing['input'];
        $input_cost += ( $cached_tokens / 1000000 ) * $pricing['input'] * $cache_ratio;
        $output_cost = ( $tokens_out / 1000000 ) * $pricing['output'];
        return round( $input_cost + $output_cost, 6 );
    }

    /**
     * Estimate generated image cost.
     *
     * @param string $model Image model ID.
     * @param string $size  Requested image size.
     * @param int    $count Number of images.
     * @return float Estimated cost in USD.
     */
    public function estimate_image_cost( $model, $size, $count = 1 ) {
        $count = max( 1, (int) $count );
        return round( self::image_unit_price( $model, $size ) * $count, 6 );
    }

    /**
     * Usage model key for generated images.
     *
     * @param string $model Image model ID.
     * @param string $size  Requested image size.
     * @return string
     */
    public static function image_usage_model( $model, $size ) {
        $model = sanitize_text_field( trim( (string) $model ) );
        $size  = sanitize_text_field( trim( (string) $size ) );
        if ( '' === $model ) {
            $model = 'provider-default';
        }
        if ( '' === $size ) {
            $size = '1024x1024';
        }
        return 'image:' . $model . ':' . $size;
    }

    /**
     * Usage model key for public web research tools.
     *
     * @param string $action Tool action.
     * @return string
     */
    public static function web_usage_model( $action ) {
        $action = sanitize_key( (string) $action );
        if ( ! in_array( $action, array( 'search', 'fetch' ), true ) ) {
            $action = 'request';
        }
        return 'web:' . $action;
    }

    /**
     * Get usage summary for a user within a date range.
     *
     * @param int    $user_id
     * @param string $period  'today', 'week', 'month', or 'all'.
     * @return array{total_tokens_in: int, total_tokens_out: int, total_cost: float, request_count: int}
     */
    public function get_usage_summary( $user_id, $period = 'month' ) {
        global $wpdb;

        list( $date_clause, $date_value ) = $this->get_date_condition( $period );
        $args = array( $user_id );
        if ( null !== $date_value ) {
            $args[] = $date_value;
        }

        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(tokens_in), 0) as total_tokens_in,
                COALESCE(SUM(tokens_out), 0) as total_tokens_out,
                COALESCE(SUM(estimated_cost), 0) as total_cost,
                COUNT(*) as request_count
             FROM {$wpdb->prefix}wp_agent_usage
             WHERE user_id = %d {$date_clause}",
            $args
        ), ARRAY_A );

        return array(
            'total_tokens_in'  => (int) $result['total_tokens_in'],
            'total_tokens_out' => (int) $result['total_tokens_out'],
            'total_cost'       => (float) $result['total_cost'],
            'request_count'    => (int) $result['request_count'],
        );
    }

    /**
     * Check whether a user may start another billable model step.
     *
     * @param int $user_id
     * @return true|WP_Error
     */
    public function assert_within_budget( $user_id, $additional_cost = 0.0 ) {
        $budget = (float) WPAgent::get_option( 'monthly_budget', 0 );
        if ( $budget <= 0 ) {
            return true;
        }

        $summary = $this->get_usage_summary( $user_id, 'month' );
        $projected_cost = (float) $summary['total_cost'] + max( 0.0, (float) $additional_cost );
        if ( $projected_cost >= $budget ) {
            return new WP_Error(
                'wp_agent_budget_exceeded',
                sprintf(
                    'Monthly WP Agent budget exceeded: $%.2f of $%.2f used.',
                    $projected_cost,
                    $budget
                )
            );
        }

        return true;
    }

    /**
     * Get daily usage breakdown for charts.
     *
     * @param int $user_id
     * @param int $days Number of days to look back.
     * @return array
     */
    public function get_daily_breakdown( $user_id, $days = 30 ) {
        global $wpdb;

        // Clamp days to a safe integer range.
        $days = max( 1, min( (int) $days, 365 ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                DATE(created_at) as date,
                SUM(tokens_in) as tokens_in,
                SUM(tokens_out) as tokens_out,
                SUM(estimated_cost) as cost,
                COUNT(*) as requests
             FROM {$wpdb->prefix}wp_agent_usage
             WHERE user_id = %d AND created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $user_id,
            gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) )
        ), ARRAY_A );
    }

    /**
     * Get usage breakdown by model.
     *
     * @param int    $user_id
     * @param string $period
     * @return array
     */
    public function get_model_breakdown( $user_id, $period = 'month' ) {
        global $wpdb;

        list( $date_clause, $date_value ) = $this->get_date_condition( $period );
        $args = array( $user_id );
        if ( null !== $date_value ) {
            $args[] = $date_value;
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                model,
                SUM(tokens_in) as tokens_in,
                SUM(tokens_out) as tokens_out,
                SUM(estimated_cost) as cost,
                COUNT(*) as requests
             FROM {$wpdb->prefix}wp_agent_usage
             WHERE user_id = %d {$date_clause}
             GROUP BY model
             ORDER BY cost DESC",
            $args
        ), ARRAY_A );
    }

    /**
     * Check budget alerts for all users.
     */
    public function check_budget_alerts() {
        $budget = (float) WPAgent::get_option( 'monthly_budget', 0 );
        if ( $budget <= 0 ) {
            return;
        }

        // Get all users with usage this month.
        global $wpdb;
        $month_start = gmdate( 'Y-m-01 00:00:00' );

        $users = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, SUM(estimated_cost) as total_cost
             FROM {$wpdb->prefix}wp_agent_usage
             WHERE created_at >= %s
             GROUP BY user_id",
            $month_start
        ), ARRAY_A );

        foreach ( $users as $user_data ) {
            $user_id    = (int) $user_data['user_id'];
            $total_cost = (float) $user_data['total_cost'];
            $percentage = ( $total_cost / $budget ) * 100;

            // Alert at 80% and 100%.
            if ( $percentage >= 100 ) {
                $this->send_budget_alert( $user_id, $total_cost, $budget, 'exceeded' );
            } elseif ( $percentage >= 80 ) {
                $already_warned = get_user_meta( $user_id, 'wp_agent_budget_warned_month', true );
                if ( $already_warned !== gmdate( 'Y-m' ) ) {
                    $this->send_budget_alert( $user_id, $total_cost, $budget, 'warning' );
                    update_user_meta( $user_id, 'wp_agent_budget_warned_month', gmdate( 'Y-m' ) );
                }
            }
        }
    }

    /**
     * Send a budget alert notification.
     */
    private function send_budget_alert( $user_id, $current_cost, $budget, $type ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $subject = 'warning' === $type
            ? sprintf( '[WP Agent] Budget Warning: 80%% of $%.2f monthly limit reached', $budget )
            : sprintf( '[WP Agent] Budget Exceeded: $%.2f spent of $%.2f monthly limit', $current_cost, $budget );

        $message = sprintf(
            "Hi %s,\n\nYour WP Agent AI usage this month has %s your budget.\n\nCurrent spend: $%.2f\nMonthly budget: $%.2f\n\nYou can adjust your budget in WordPress admin under WP Agent > Settings.\n\n— WP Agent",
            $user->display_name,
            'warning' === $type ? 'reached 80% of' : 'exceeded',
            $current_cost,
            $budget
        );

        wp_mail( $user->user_email, $subject, $message );

        WPAgent::audit_log( $user_id, 'budget_alert', array(
            'type'   => $type,
            'cost'   => $current_cost,
            'budget' => $budget,
        ) );
    }

    /**
     * Get pricing for a model.
     *
     * Prices are loaded from includes/data/model-pricing.json (auto-generated
     * from QuantumNous/new-api via bin/gen-pricing.php) so model prices can be
     * updated by editing data, without touching code. Matching is done in three
     * tiers so estimates stay correct even when gateways append date/revision
     * suffixes to model IDs:
     *   1. Exact model-ID match.
     *   2. Longest known-prefix match (e.g. "gpt-5.4-mini-2026-01-01" -> "gpt-5.4-mini").
     *   3. Configured fallback price for unknown models.
     *
     * @param string $model
     * @return array{input: float, output: float} Price per million tokens.
     */
    private function get_model_pricing( $model ) {
        $model = strtolower( trim( (string) $model ) );
        $table = self::pricing_table();

        // Tier 1: exact match.
        if ( isset( $table['models'][ $model ] ) ) {
            return $table['models'][ $model ];
        }

        // Tier 2: longest known-prefix match (handles dated/revisioned IDs).
        $best_key = '';
        foreach ( $table['models'] as $key => $price ) {
            if ( 0 === strpos( $model, $key ) && strlen( $key ) > strlen( $best_key ) ) {
                $best_key = $key;
            }
        }
        if ( '' !== $best_key ) {
            return $table['models'][ $best_key ];
        }

        // Tier 3: configured fallback for unknown models.
        return $table['fallback'];
    }

    /**
     * Load, normalize, and cache the model pricing table.
     *
     * @return array{models: array<string, array{input: float, output: float}>, fallback: array{input: float, output: float}, cache_ratios: array<string, float>}
     */
    private static function pricing_table() {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }

        $fallback     = array( 'input' => 3.00, 'output' => 15.00 );
        $models       = array();
        $cache_ratios = array();

        // Prefer the guarded PHP data file (model-pricing.php): it cannot be
        // downloaded directly over HTTP on any web server because a direct
        // request executes PHP and returns nothing. Fall back to the legacy
        // JSON only if the PHP file is absent.
        $data    = null;
        $php_file = WP_AGENT_PLUGIN_DIR . 'includes/data/model-pricing.php';
        if ( is_readable( $php_file ) ) {
            $loaded = include $php_file;
            if ( is_array( $loaded ) ) {
                $data = $loaded;
            }
        }
        if ( null === $data ) {
            $json_file = WP_AGENT_PLUGIN_DIR . 'includes/data/model-pricing.json';
            if ( is_readable( $json_file ) ) {
                $decoded = json_decode( (string) file_get_contents( $json_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                if ( is_array( $decoded ) ) {
                    $data = $decoded;
                }
            }
        }

        if ( is_array( $data ) ) {
            if ( isset( $data['fallback']['input'], $data['fallback']['output'] ) ) {
                $fallback = array(
                    'input'  => (float) $data['fallback']['input'],
                    'output' => (float) $data['fallback']['output'],
                );
            }
            foreach ( (array) ( $data['models'] ?? array() ) as $name => $price ) {
                if ( isset( $price['input'], $price['output'] ) ) {
                    $models[ strtolower( (string) $name ) ] = array(
                        'input'  => (float) $price['input'],
                        'output' => (float) $price['output'],
                    );
                }
            }
            foreach ( (array) ( $data['cache_ratios'] ?? array() ) as $name => $ratio ) {
                $cache_ratios[ strtolower( (string) $name ) ] = (float) $ratio;
            }
        }

        /**
         * Filter the model pricing table (USD per 1M tokens). Deployments using
         * custom gateways can register or override model prices here.
         *
         * @param array $models   Map of model ID => array{input, output}.
         * @param array $fallback Fallback price for unknown models.
         */
        $models = apply_filters( 'wp_agent_model_pricing', $models, $fallback );

        // Longest model IDs first so prefix matching prefers the most specific.
        uksort( $models, static function ( $a, $b ) {
            return strlen( (string) $b ) <=> strlen( (string) $a );
        } );

        /**
         * Filter the prompt-cache discount ratios (cached input price multiplier
         * per model). 1.0 means no discount.
         *
         * @param array $cache_ratios Map of model ID => ratio.
         */
        $cache_ratios = apply_filters( 'wp_agent_model_cache_ratios', $cache_ratios );
        uksort( $cache_ratios, static function ( $a, $b ) {
            return strlen( (string) $b ) <=> strlen( (string) $a );
        } );

        $cache = array( 'models' => $models, 'fallback' => $fallback, 'cache_ratios' => $cache_ratios );
        return $cache;
    }

    /**
     * Get the prompt-cache discount ratio for a model (cached input multiplier).
     *
     * @param string $model
     * @return float Ratio in (0, 1]; 1.0 when no discount is known.
     */
    private function get_cache_ratio( $model ) {
        $model  = strtolower( trim( (string) $model ) );
        $ratios = self::pricing_table()['cache_ratios'];

        if ( isset( $ratios[ $model ] ) ) {
            return $ratios[ $model ];
        }
        foreach ( $ratios as $key => $ratio ) {
            if ( 0 === strpos( $model, $key ) ) {
                return $ratio;
            }
        }
        return 1.0;
    }

    /**
     * Estimated per-image price for common image sizes/models.
     *
     * @param string $model Image model ID.
     * @param string $size  Requested size.
     * @return float USD per generated image.
     */
    private static function image_unit_price( $model, $size ) {
        $model = strtolower( trim( (string) $model ) );
        $size  = trim( (string) $size );

        if ( false !== strpos( $model, 'dall-e-2' ) ) {
            return '1024x1024' === $size ? 0.02 : 0.018;
        }

        if ( false !== strpos( $model, 'dall-e-3' ) ) {
            return '1024x1024' === $size ? 0.04 : 0.08;
        }

        if ( false !== strpos( $model, 'gpt-image' ) ) {
            return '1024x1024' === $size ? 0.04 : 0.08;
        }

        return '1024x1024' === $size ? 0.04 : 0.08;
    }

    /**
     * Get the SQL clause and bind value for a date period filter.
     *
     * Returns an array with the clause string and an optional value,
     * designed to be merged into the caller's $wpdb->prepare() args.
     *
     * @param string $period 'today', 'week', 'month', or 'all'.
     * @return array{string, string|null} [ clause, value ].
     */
    private function get_date_condition( $period ) {
        switch ( $period ) {
            case 'today':
                return array( 'AND created_at >= %s', gmdate( 'Y-m-d 00:00:00' ) );
            case 'week':
                return array( 'AND created_at >= %s', gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) ) );
            case 'month':
                return array( 'AND created_at >= %s', gmdate( 'Y-m-01 00:00:00' ) );
            case 'all':
            default:
                return array( '', null );
        }
    }
}
