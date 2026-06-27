<?php
/**
 * WP Agent cost tracking and budget workflow checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/cost-budget-workflow.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This cost budget workflow script must run through WP-CLI.\n" );
    exit( 1 );
}

$GLOBALS['wp_agent_cost_previous_budget_sentinel'] = '__wp_agent_cost_budget_missing__';
$GLOBALS['wp_agent_cost_previous_budget']          = get_option( 'wp_agent_monthly_budget', $GLOBALS['wp_agent_cost_previous_budget_sentinel'] );
$GLOBALS['wp_agent_cost_user_id']                  = 0;
$GLOBALS['wp_agent_cost_model']                    = '';
$GLOBALS['wp_agent_cost_image_model']              = '';
$GLOBALS['wp_agent_cost_mail_filter']              = null;
$GLOBALS['wp_agent_cost_restored']                 = false;

function wp_agent_cost_cleanup() {
    global $wpdb;

    if ( ! empty( $GLOBALS['wp_agent_cost_restored'] ) ) {
        return;
    }

    if ( '' !== $GLOBALS['wp_agent_cost_model'] ) {
        $wpdb->delete(
            $wpdb->prefix . 'wp_agent_usage',
            array( 'model' => $GLOBALS['wp_agent_cost_model'] ),
            array( '%s' )
        );
    }

    if ( '' !== $GLOBALS['wp_agent_cost_image_model'] ) {
        $wpdb->delete(
            $wpdb->prefix . 'wp_agent_usage',
            array( 'model' => $GLOBALS['wp_agent_cost_image_model'] ),
            array( '%s' )
        );
    }

    if ( $GLOBALS['wp_agent_cost_user_id'] > 0 ) {
        $wpdb->delete(
            $wpdb->prefix . 'wp_agent_audit_log',
            array(
                'user_id' => (int) $GLOBALS['wp_agent_cost_user_id'],
                'action'  => 'budget_alert',
            ),
            array( '%d', '%s' )
        );
        delete_user_meta( (int) $GLOBALS['wp_agent_cost_user_id'], 'wp_agent_budget_warned_month' );
        wp_delete_user( (int) $GLOBALS['wp_agent_cost_user_id'] );
    }

    if ( null !== $GLOBALS['wp_agent_cost_mail_filter'] ) {
        remove_filter( 'pre_wp_mail', $GLOBALS['wp_agent_cost_mail_filter'], 10 );
    }

    if ( $GLOBALS['wp_agent_cost_previous_budget_sentinel'] === $GLOBALS['wp_agent_cost_previous_budget'] ) {
        delete_option( 'wp_agent_monthly_budget' );
    } else {
        update_option( 'wp_agent_monthly_budget', $GLOBALS['wp_agent_cost_previous_budget'] );
    }
    $GLOBALS['wp_agent_cost_restored'] = true;
}

register_shutdown_function( 'wp_agent_cost_cleanup' );

function wp_agent_cost_fail( $message ) {
    wp_agent_cost_cleanup();
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_cost_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_cost_fail( $message );
    }
}

function wp_agent_cost_count_mail_to( $mail_log, $email, $needle ) {
    $count = 0;
    foreach ( $mail_log as $mail ) {
        $to      = $mail['to'] ?? '';
        $subject = $mail['subject'] ?? '';
        if ( is_array( $to ) ) {
            $to = implode( ',', $to );
        }
        if ( false !== strpos( (string) $to, $email ) && false !== strpos( (string) $subject, $needle ) ) {
            $count++;
        }
    }
    return $count;
}

function wp_agent_cost_recent_alert_types( $user_id ) {
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT details FROM {$wpdb->prefix}wp_agent_audit_log WHERE user_id = %d AND action = 'budget_alert' ORDER BY id DESC LIMIT 5",
            (int) $user_id
        ),
        ARRAY_A
    );

    $types = array();
    foreach ( $rows as $row ) {
        $details = json_decode( (string) $row['details'], true );
        if ( isset( $details['type'] ) ) {
            $types[] = (string) $details['type'];
        }
    }
    return $types;
}

$admin = get_user_by( 'login', 'admin' );
wp_agent_cost_assert( $admin instanceof WP_User, 'Admin user is required for settings tool verification.' );
$admin_id = (int) $admin->ID;

$marker = 'cost-budget-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
$email  = $marker . '@example.test';
$user_id = wp_insert_user(
    array(
        'user_login'   => $marker,
        'user_email'   => $email,
        'user_pass'    => wp_generate_password( 20, true, true ),
        'display_name' => 'WP Agent Cost Fixture',
        'role'         => 'subscriber',
    )
);
wp_agent_cost_assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Temporary cost user was not created.' );
$GLOBALS['wp_agent_cost_user_id'] = (int) $user_id;

$model = 'wp-agent-cost-' . substr( hash( 'sha256', $marker ), 0, 12 );
$GLOBALS['wp_agent_cost_model'] = $model;

$settings = new WPAgent_Tool_Settings();
$settings->set_context( $admin_id, 'wpcli', 0, $admin_id, 0 );
$set_budget = $settings->execute(
    array(
        'action' => 'set',
        'key'    => 'monthly_budget',
        'value'  => '1',
    )
);
wp_agent_cost_assert( ! empty( $set_budget['success'] ) && 1 === (int) ( $set_budget['value'] ?? 0 ), 'Settings tool should set a $1 budget: ' . wp_json_encode( $set_budget ) );
$get_budget = $settings->execute(
    array(
        'action' => 'get',
        'key'    => 'monthly_budget',
    )
);
wp_agent_cost_assert( ! empty( $get_budget['success'] ) && 1 === (int) ( $get_budget['value'] ?? 0 ), 'Settings tool should read the $1 budget.' );

$tracker = new WPAgent_Cost_Tracker();
$estimated = $tracker->estimate_cost( $model, 100000, 36667 );
wp_agent_cost_assert( $estimated > 0.84 && $estimated < 0.86, 'Default model pricing should estimate the first fixture near $0.85.' );

$tracker->record( (int) $user_id, $model, 100000, 36667 );
$summary = $tracker->get_usage_summary( (int) $user_id, 'month' );
wp_agent_cost_assert( 1 === (int) $summary['request_count'], 'Usage summary should count one request.' );
wp_agent_cost_assert( 100000 === (int) $summary['total_tokens_in'] && 36667 === (int) $summary['total_tokens_out'], 'Usage summary should add token totals.' );
wp_agent_cost_assert( (float) $summary['total_cost'] > 0.84 && (float) $summary['total_cost'] < 0.86, 'Usage summary should add estimated cost.' );
wp_agent_cost_assert( true === $tracker->assert_within_budget( (int) $user_id ), 'User below budget should be allowed.' );

$daily = $tracker->get_daily_breakdown( (int) $user_id, 1 );
wp_agent_cost_assert( ! empty( $daily ) && (int) $daily[0]['requests'] >= 1, 'Daily breakdown should include the fixture usage.' );
$models = $tracker->get_model_breakdown( (int) $user_id, 'month' );
wp_agent_cost_assert( ! empty( $models ) && $model === (string) $models[0]['model'], 'Model breakdown should include the fixture model.' );

$image_usage_model = WPAgent_Cost_Tracker::image_usage_model( 'dall-e-3', '1024x1024' );
$GLOBALS['wp_agent_cost_image_model'] = $image_usage_model;
$image_estimated = $tracker->estimate_image_cost( 'dall-e-3', '1024x1024', 1 );
wp_agent_cost_assert( $image_estimated > 0.039 && $image_estimated < 0.041, 'Image pricing should estimate a DALL-E 3 square image near $0.04.' );
$tracker->record_image( (int) $user_id, 'dall-e-3', '1024x1024', 1 );
$summary_with_image = $tracker->get_usage_summary( (int) $user_id, 'month' );
wp_agent_cost_assert( 2 === (int) $summary_with_image['request_count'], 'Image usage should add a second usage request.' );
wp_agent_cost_assert( 1 === (int) $summary_with_image['total_tokens_out'] - (int) $summary['total_tokens_out'], 'Image usage should record the image count in tokens_out.' );
wp_agent_cost_assert( (float) $summary_with_image['total_cost'] > (float) $summary['total_cost'], 'Image usage should increase estimated cost.' );
$models_with_image = $tracker->get_model_breakdown( (int) $user_id, 'month' );
$model_names = wp_list_pluck( $models_with_image, 'model' );
wp_agent_cost_assert( in_array( $image_usage_model, $model_names, true ), 'Model breakdown should include generated image usage.' );
wp_agent_cost_assert( true === $tracker->assert_within_budget( (int) $user_id, 0.01 ), 'Projected usage below budget should be allowed.' );

$mail_log = array();
$GLOBALS['wp_agent_cost_mail_filter'] = function( $return, $atts ) use ( &$mail_log ) {
    $mail_log[] = $atts;
    return true;
};
add_filter( 'pre_wp_mail', $GLOBALS['wp_agent_cost_mail_filter'], 10, 2 );

$tracker->check_budget_alerts();
wp_agent_cost_assert( 1 === wp_agent_cost_count_mail_to( $mail_log, $email, 'Budget Warning' ), '80% budget warning email should be sent once.' );
wp_agent_cost_assert( gmdate( 'Y-m' ) === get_user_meta( (int) $user_id, 'wp_agent_budget_warned_month', true ), 'Warning month marker should be set.' );

$tracker->check_budget_alerts();
wp_agent_cost_assert( 1 === wp_agent_cost_count_mail_to( $mail_log, $email, 'Budget Warning' ), '80% warning email should not repeat in the same month.' );

$tracker->record( (int) $user_id, $model, 10000, 8000 );
$blocked = $tracker->assert_within_budget( (int) $user_id );
wp_agent_cost_assert( is_wp_error( $blocked ) && 'wp_agent_budget_exceeded' === $blocked->get_error_code(), 'User at/over budget should be blocked.' );

$tracker->check_budget_alerts();
wp_agent_cost_assert( 1 === wp_agent_cost_count_mail_to( $mail_log, $email, 'Budget Exceeded' ), 'Budget exceeded email should be sent.' );

$alert_types = wp_agent_cost_recent_alert_types( (int) $user_id );
wp_agent_cost_assert( in_array( 'warning', $alert_types, true ), 'Budget warning should be audited.' );
wp_agent_cost_assert( in_array( 'exceeded', $alert_types, true ), 'Budget exceeded should be audited.' );

$summary_after = $tracker->get_usage_summary( (int) $user_id, 'month' );
wp_agent_cost_assert( (float) $summary_after['total_cost'] >= 1.0, 'Final usage should be at or above the configured budget.' );

$result = array(
    'success'            => true,
    'user_id'            => (int) $user_id,
    'model'              => $model,
    'request_count'      => (int) $summary_after['request_count'],
    'total_cost'         => (float) $summary_after['total_cost'],
    'warning_mails'      => wp_agent_cost_count_mail_to( $mail_log, $email, 'Budget Warning' ),
    'exceeded_mails'     => wp_agent_cost_count_mail_to( $mail_log, $email, 'Budget Exceeded' ),
    'budget_error_code'  => is_wp_error( $blocked ) ? $blocked->get_error_code() : '',
    'audited_alerts'     => array_values( array_unique( $alert_types ) ),
);

wp_agent_cost_cleanup();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
