<?php
/**
 * Usage & Costs page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$tracker  = new WPAgent_Cost_Tracker();
$user_id  = get_current_user_id();
$period   = sanitize_text_field( wp_unslash( $_GET['period'] ?? 'month' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$period   = in_array( $period, array( 'today', 'week', 'month', 'all' ), true ) ? $period : 'month';

$summary  = $tracker->get_usage_summary( $user_id, $period );
$daily    = $tracker->get_daily_breakdown( $user_id );
$by_model = $tracker->get_model_breakdown( $user_id, $period );
$budget   = (float) WPAgent::get_option( 'monthly_budget', 0 );

$periods = array(
    'today' => __( 'Today', 'wp-agent' ),
    'week'  => __( 'This Week', 'wp-agent' ),
    'month' => __( 'This Month', 'wp-agent' ),
    'all'   => __( 'All Time', 'wp-agent' ),
);
?>
<div class="wp-agent-wrap">
    <div class="wp-agent-page-header">
        <h1><?php esc_html_e( 'Usage & Costs', 'wp-agent' ); ?></h1>
        <p><?php esc_html_e( 'Track your AI token usage and estimated costs', 'wp-agent' ); ?></p>
    </div>

    <div class="wp-agent-page-content">

        <!-- Period Tabs -->
        <div class="wp-agent-tabs">
            <?php foreach ( $periods as $key => $label ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-agent-costs&period=' . $key ) ); ?>"
                   class="wp-agent-tab <?php echo esc_attr( $period === $key ? 'wp-agent-tab--active' : '' ); ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Summary Cards -->
        <div class="wp-agent-cards">
            <div class="wp-agent-card">
                <div class="wp-agent-card-label"><?php esc_html_e( 'Estimated Cost', 'wp-agent' ); ?></div>
                <div class="wp-agent-card-value wp-agent-card-value--accent">$<?php echo esc_html( number_format( $summary['total_cost'], 2 ) ); ?></div>
                <?php if ( $budget > 0 ) : ?>
                    <?php
                    $pct = min( 100, ( $summary['total_cost'] / $budget ) * 100 );
                    $bar_class = $pct > 90 ? 'danger' : ( $pct > 70 ? 'warn' : 'ok' );
                    ?>
                    <div class="wp-agent-card-detail">
                        <?php
                        // translators: %1$.0f: percentage used, %2$.2f: budget amount.
                        printf( esc_html__( '%1$.0f%% of $%2$.2f budget', 'wp-agent' ), esc_html( $pct ), esc_html( $budget ) );
                        ?>
                    </div>
                    <div class="wp-agent-progress">
                        <div class="wp-agent-progress-bar wp-agent-progress-bar--<?php echo esc_attr( $bar_class ); ?>" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="wp-agent-card">
                <div class="wp-agent-card-label"><?php esc_html_e( 'Requests', 'wp-agent' ); ?></div>
                <div class="wp-agent-card-value"><?php echo esc_html( number_format( $summary['request_count'] ) ); ?></div>
            </div>

            <div class="wp-agent-card">
                <div class="wp-agent-card-label"><?php esc_html_e( 'Input Tokens', 'wp-agent' ); ?></div>
                <div class="wp-agent-card-value"><?php echo esc_html( number_format( $summary['total_tokens_in'] ) ); ?></div>
            </div>

            <div class="wp-agent-card">
                <div class="wp-agent-card-label"><?php esc_html_e( 'Output Tokens', 'wp-agent' ); ?></div>
                <div class="wp-agent-card-value"><?php echo esc_html( number_format( $summary['total_tokens_out'] ) ); ?></div>
            </div>
        </div>

        <!-- By Model Breakdown -->
        <?php if ( ! empty( $by_model ) ) : ?>
        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Usage by Model', 'wp-agent' ); ?></h2>
            <div class="wp-agent-table-wrap">
                <div class="wp-agent-table-scroll">
                    <table class="wp-agent-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Model', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Requests', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Input Tokens', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Output Tokens', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Estimated Cost', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $by_model as $row ) : ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Model', 'wp-agent' ); ?>"><code><?php echo esc_html( $row['model'] ); ?></code></td>
                                <td data-label="<?php esc_attr_e( 'Requests', 'wp-agent' ); ?>"><?php echo esc_html( number_format( $row['requests'] ) ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Input Tokens', 'wp-agent' ); ?>"><?php echo esc_html( number_format( $row['tokens_in'] ) ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Output Tokens', 'wp-agent' ); ?>"><?php echo esc_html( number_format( $row['tokens_out'] ) ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Estimated Cost', 'wp-agent' ); ?>">$<?php echo esc_html( number_format( $row['cost'], 4 ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Daily Breakdown -->
        <?php if ( ! empty( $daily ) ) : ?>
        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Daily Usage (Last 30 Days)', 'wp-agent' ); ?></h2>
            <div class="wp-agent-table-wrap">
                <div class="wp-agent-table-scroll">
                    <table class="wp-agent-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Date', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Requests', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Tokens', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Cost', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_reverse( $daily ) as $day ) : ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Date', 'wp-agent' ); ?>"><?php echo esc_html( $day['date'] ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Requests', 'wp-agent' ); ?>"><?php echo esc_html( number_format( $day['requests'] ) ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Tokens', 'wp-agent' ); ?>"><?php echo esc_html( number_format( $day['tokens_in'] + $day['tokens_out'] ) ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Cost', 'wp-agent' ); ?>">$<?php echo esc_html( number_format( $day['cost'], 4 ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( empty( $by_model ) && empty( $daily ) ) : ?>
        <div class="wp-agent-empty">
            <div class="wp-agent-empty-icon">&#128200;</div>
            <h3><?php esc_html_e( 'No usage data yet', 'wp-agent' ); ?></h3>
            <p><?php esc_html_e( 'Start chatting with WP Agent to see your usage and costs here.', 'wp-agent' ); ?></p>
        </div>
        <?php endif; ?>

    </div>
</div>
