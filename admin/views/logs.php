<?php
/**
 * Conversations log page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$conversation_mgr = new WPAgent_Conversation();
$user_id = get_current_user_id();
$conversations = $conversation_mgr->list_conversations( $user_id, '', 50 );
?>
<div class="wp-agent-wrap">
    <div class="wp-agent-page-header">
        <h1><?php esc_html_e( 'Conversations', 'wp-agent' ); ?></h1>
        <p><?php esc_html_e( 'View your chat history across all channels', 'wp-agent' ); ?></p>
    </div>

    <div class="wp-agent-page-content">

        <?php if ( ! empty( $conversations ) ) : ?>
        <div class="wp-agent-table-wrap">
            <div class="wp-agent-table-scroll">
                <table class="wp-agent-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;"><?php esc_html_e( 'ID', 'wp-agent' ); ?></th>
                            <th><?php esc_html_e( 'Channel', 'wp-agent' ); ?></th>
                            <th><?php esc_html_e( 'First Message', 'wp-agent' ); ?></th>
                            <th style="width: 90px;"><?php esc_html_e( 'Messages', 'wp-agent' ); ?></th>
                            <th><?php esc_html_e( 'Started', 'wp-agent' ); ?></th>
                            <th><?php esc_html_e( 'Last Active', 'wp-agent' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $conversations as $convo ) : ?>
                        <tr>
                            <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'ID', 'wp-agent' ); ?>">#<?php echo esc_html( $convo['id'] ); ?></td>
                            <td data-label="<?php esc_attr_e( 'Channel', 'wp-agent' ); ?>"><span class="wp-agent-badge wp-agent-badge--channel"><?php echo esc_html( ucfirst( $convo['channel'] ) ); ?></span></td>
                            <td data-label="<?php esc_attr_e( 'First Message', 'wp-agent' ); ?>"><?php echo esc_html( wp_trim_words( $convo['first_message'] ?? "\xe2\x80\x94", 15 ) ); ?></td>
                            <td data-label="<?php esc_attr_e( 'Messages', 'wp-agent' ); ?>"><?php echo esc_html( $convo['message_count'] ); ?></td>
                            <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Started', 'wp-agent' ); ?>"><?php echo esc_html( human_time_diff( strtotime( $convo['created_at'] ) ) ); ?> ago</td>
                            <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Last Active', 'wp-agent' ); ?>"><?php echo esc_html( human_time_diff( strtotime( $convo['updated_at'] ) ) ); ?> ago</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else : ?>
        <div class="wp-agent-empty">
            <div class="wp-agent-empty-icon">&#128172;</div>
            <h3><?php esc_html_e( 'No conversations yet', 'wp-agent' ); ?></h3>
            <p><?php esc_html_e( 'Start chatting with WP Agent to see your conversation history here.', 'wp-agent' ); ?></p>
        </div>
        <?php endif; ?>

    </div>
</div>
