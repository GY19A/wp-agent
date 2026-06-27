<?php
/**
 * Audit log page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$page_num = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$per_page = 50;
$offset   = ( $page_num - 1 ) * $per_page;

$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wp_agent_audit_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$logs  = $wpdb->get_results( $wpdb->prepare(
    "SELECT a.*, u.display_name
     FROM {$wpdb->prefix}wp_agent_audit_log a
     LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
     ORDER BY a.created_at DESC
     LIMIT %d OFFSET %d",
    $per_page,
    $offset
), ARRAY_A );

$total_pages = ceil( $total / $per_page );
?>
<div class="wp-agent-wrap">
    <div class="wp-agent-page-header">
        <h1><?php esc_html_e( 'Audit Log', 'wp-agent' ); ?></h1>
        <p><?php esc_html_e( 'Every action taken by WP Agent is logged here for security and accountability', 'wp-agent' ); ?></p>
    </div>

    <div class="wp-agent-page-content">

        <?php if ( ! empty( $logs ) ) : ?>
        <div class="wp-agent-table-wrap">
            <div class="wp-agent-table-scroll">
                <table class="wp-agent-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'wp-agent' ); ?></th>
                            <th><?php esc_html_e( 'User', 'wp-agent' ); ?></th>
                            <th><?php esc_html_e( 'Channel', 'wp-agent' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'wp-agent' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'wp-agent' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) : ?>
                        <tr>
                            <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Time', 'wp-agent' ); ?>" title="<?php echo esc_attr( $log['created_at'] ); ?>" style="white-space: nowrap;">
                                <?php echo esc_html( human_time_diff( strtotime( $log['created_at'] ) ) ); ?> ago
                            </td>
                            <td data-label="<?php esc_attr_e( 'User', 'wp-agent' ); ?>"><?php echo esc_html( $log['display_name'] ?? '#' . $log['user_id'] ); ?></td>
                            <td data-label="<?php esc_attr_e( 'Channel', 'wp-agent' ); ?>"><span class="wp-agent-badge wp-agent-badge--channel"><?php echo esc_html( ucfirst( $log['channel'] ) ); ?></span></td>
                            <td data-label="<?php esc_attr_e( 'Action', 'wp-agent' ); ?>"><code><?php echo esc_html( $log['action'] ); ?></code></td>
                            <td data-label="<?php esc_attr_e( 'Details', 'wp-agent' ); ?>">
                                <?php
                                $details = json_decode( $log['details'], true );
                                if ( $details ) {
                                    $summary = array();
                                    foreach ( $details as $k => $v ) {
                                        if ( is_array( $v ) || is_object( $v ) ) {
                                            $summary[] = $k . ': ' . wp_json_encode( $v );
                                        } else {
                                            $summary[] = $k . ': ' . (string) $v;
                                        }
                                    }
                                    echo '<span class="wp-agent-text-muted" style="font-size: 12px;">' . esc_html( implode( ' | ', $summary ) ) . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ( $total_pages > 1 ) : ?>
        <div class="wp-agent-pagination">
            <?php
            echo wp_kses_post( paginate_links( array(
                'base'      => admin_url( 'admin.php?page=wp-agent-audit&paged=%#%' ),
                'format'    => '',
                'current'   => $page_num,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ) ) );
            ?>
        </div>
        <?php endif; ?>

        <?php else : ?>
        <div class="wp-agent-empty">
            <div class="wp-agent-empty-icon">&#128274;</div>
            <h3><?php esc_html_e( 'No audit events yet', 'wp-agent' ); ?></h3>
            <p><?php esc_html_e( 'Actions will be logged here as WP Agent processes requests.', 'wp-agent' ); ?></p>
        </div>
        <?php endif; ?>

    </div>
</div>
