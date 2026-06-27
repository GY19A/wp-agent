<?php
/**
 * Full-page chat template.
 *
 * Renders a full-height, three-pane chat experience: a conversation list on
 * the left, a scrollable message thread in the center, and a composer at the
 * bottom. Driven by chat.js, which talks to WP Agent's poll-based run queue
 * via the REST API (window.wpAgentChat).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wp-agent-wrap wp-agent-wrap--chat">
    <div class="wp-agent-page-header">
        <h1><?php esc_html_e( 'Agent', 'wp-agent' ); ?></h1>
        <p><?php esc_html_e( 'Your WordPress AI agent workspace', 'wp-agent' ); ?></p>
    </div>

    <div id="wpa-chat" class="wpa-chat">
        <!-- Left: conversation list -->
        <aside id="wpa-conv-list-wrap" class="wpa-conv-list-wrap" aria-label="<?php esc_attr_e( 'Conversations', 'wp-agent' ); ?>">
            <div class="wpa-rail-header">
                <img src="<?php echo esc_url( WP_AGENT_PLUGIN_URL . 'assets/images/logo.png' ); ?>" alt="" class="wpa-rail-logo" />
                <div>
                    <div class="wpa-rail-title">WP Agent</div>
                    <div class="wpa-rail-subtitle"><?php esc_html_e( 'Site command center', 'wp-agent' ); ?></div>
                </div>
            </div>
            <button type="button" id="wpa-new-chat" class="wpa-new-chat">
                <span class="wpa-new-chat-icon" aria-hidden="true">&#43;</span>
                <span><?php esc_html_e( 'New chat', 'wp-agent' ); ?></span>
            </button>
            <ul id="wpa-conv-list" class="wpa-conv-list"></ul>
        </aside>

        <!-- Center: thread + composer -->
        <div class="wpa-main">
            <div class="wpa-thread-header">
                <div>
                    <div class="wpa-thread-title"><?php esc_html_e( 'WP Agent', 'wp-agent' ); ?></div>
                    <div class="wpa-thread-subtitle"><?php esc_html_e( 'Autonomous WordPress workspace', 'wp-agent' ); ?></div>
                </div>
                <div class="wpa-thread-actions">
                    <button type="button" id="wpa-chat-history" class="wpa-history-button">
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <span><?php esc_html_e( 'History', 'wp-agent' ); ?></span>
                    </button>
                </div>
            </div>
            <div id="wpa-thread" class="wpa-thread" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Conversation', 'wp-agent' ); ?>"></div>

            <div class="wpa-composer">
                <div id="wpa-status" class="wpa-status" role="status" aria-live="polite"></div>
                <div id="wpa-attachments" class="wpa-attachments" aria-live="polite"></div>
                <div class="wpa-composer-row">
                    <input id="wpa-file-input" class="wpa-file-input" type="file" multiple accept="image/*,audio/*,video/*,.pdf,.txt,.md,.markdown,.csv,.json" />
                    <button type="button" id="wpa-attach" class="wpa-attach" aria-label="<?php esc_attr_e( 'Attach media', 'wp-agent' ); ?>" title="<?php esc_attr_e( 'Attach media', 'wp-agent' ); ?>">
                        <span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
                    </button>
                    <label for="wpa-input" class="screen-reader-text"><?php esc_html_e( 'Message', 'wp-agent' ); ?></label>
                    <textarea id="wpa-input"
                              class="wpa-input"
                              rows="1"
                              placeholder="<?php esc_attr_e( 'Ask WP Agent anything...', 'wp-agent' ); ?>"
                              autocomplete="off"></textarea>
                    <button type="button" id="wpa-stop" class="wpa-stop" aria-label="<?php esc_attr_e( 'Stop active agent run', 'wp-agent' ); ?>" title="<?php esc_attr_e( 'Stop active agent run', 'wp-agent' ); ?>" hidden>
                        <span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
                        <span class="wpa-stop-label"><?php esc_html_e( 'Stop', 'wp-agent' ); ?></span>
                    </button>
                    <button type="button" id="wpa-send" class="wpa-send" aria-label="<?php esc_attr_e( 'Send message', 'wp-agent' ); ?>" title="<?php esc_attr_e( 'Send message', 'wp-agent' ); ?>">
                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="wpa-composer-hint">
                    <span class="wpa-hint-item"><kbd>/</kbd> <?php esc_html_e( 'for commands', 'wp-agent' ); ?></span>
                    <span class="wpa-hint-sep" aria-hidden="true">·</span>
                    <span class="wpa-hint-item"><kbd>Enter</kbd> <?php esc_html_e( 'to send', 'wp-agent' ); ?></span>
                    <span class="wpa-hint-sep" aria-hidden="true">·</span>
                    <span class="wpa-hint-item"><kbd>Shift</kbd>+<kbd>Enter</kbd> <?php esc_html_e( 'for a new line', 'wp-agent' ); ?></span>
                    <span class="wpa-hint-sep" aria-hidden="true">·</span>
                    <span class="wpa-hint-item"><?php esc_html_e( 'drop files to attach', 'wp-agent' ); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div id="wpa-history-modal" class="wpa-history-modal" hidden>
        <div class="wpa-history-backdrop" data-wpa-close-history></div>
        <div class="wpa-history-dialog" role="dialog" aria-modal="true" aria-labelledby="wpa-history-title">
            <div class="wpa-history-head">
                <div>
                    <h2 id="wpa-history-title"><?php esc_html_e( 'History', 'wp-agent' ); ?></h2>
                </div>
                <button type="button" class="wpa-history-close" data-wpa-close-history aria-label="<?php esc_attr_e( 'Close history', 'wp-agent' ); ?>">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <div class="wpa-history-search-wrap">
                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                <label for="wpa-history-search" class="screen-reader-text"><?php esc_html_e( 'Search history', 'wp-agent' ); ?></label>
                <input type="search" id="wpa-history-search" class="wpa-history-search" placeholder="<?php esc_attr_e( 'Search conversations', 'wp-agent' ); ?>" autocomplete="off" />
            </div>
            <div id="wpa-history-list" class="wpa-history-list"></div>
        </div>
    </div>
</div>
