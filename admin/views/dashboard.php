<?php
/**
 * Dashboard page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wp-agent-wrap">
    <div class="wp-agent-page-header">
        <h1><?php esc_html_e( 'Dashboard', 'wp-agent' ); ?></h1>
        <p><?php esc_html_e( 'Your WordPress AI Agent', 'wp-agent' ); ?></p>
    </div>

    <div class="wp-agent-page-content">

        <!-- Status Cards -->
        <div class="wp-agent-cards">
            <div class="wp-agent-card">
                <div class="wp-agent-card-label"><?php esc_html_e( 'AI Provider', 'wp-agent' ); ?></div>
                <?php
                $ai_readiness = is_array( $ai_readiness ?? null ) ? $ai_readiness : WPAgent::ai_provider_readiness();
                $ai_card_ready = ! empty( $ai_readiness['ready'] );
                $ai_card_class = $ai_card_ready ? 'wp-agent-status--ok' : ( ! empty( $ai_readiness['api_key_configured'] ) ? 'wp-agent-status--warn' : 'wp-agent-status--error' );
                $ai_card_label = $ai_card_ready ? __( 'Ready', 'wp-agent' ) : __( 'Needs setup', 'wp-agent' );
                if ( 'unreadable' === ( $ai_readiness['api_key_state'] ?? '' ) ) {
                    $ai_card_label = __( 'Key needs re-save', 'wp-agent' );
                } elseif ( 'select_model' === ( $ai_readiness['next_action'] ?? '' ) ) {
                    $ai_card_label = __( 'Select model', 'wp-agent' );
                }
                ?>
                <?php if ( ! empty( $ai_readiness['api_key_configured'] ) ) : ?>
                    <div class="wp-agent-card-value"><span class="wp-agent-status <?php echo esc_attr( $ai_card_class ); ?>"><?php echo esc_html( $ai_card_label ); ?></span></div>
                    <div class="wp-agent-card-detail">
                        <?php
                        $current_model = $ai_readiness['model'] ?? '';
                        echo esc_html( $current_model ? $current_model : __( 'Not set', 'wp-agent' ) );
                        ?>
                    </div>
                <?php else : ?>
                    <div class="wp-agent-card-value"><span class="wp-agent-status wp-agent-status--error"><?php esc_html_e( 'Not configured', 'wp-agent' ); ?></span></div>
                    <div class="wp-agent-card-detail"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-agent-settings' ) ); ?>"><?php esc_html_e( 'Add API key', 'wp-agent' ); ?></a></div>
                <?php endif; ?>
            </div>

            <div class="wp-agent-card">
                <div class="wp-agent-card-label"><?php esc_html_e( 'IM Channels', 'wp-agent' ); ?></div>
                <?php
                $im_channels        = isset( $im_channels ) && is_array( $im_channels ) ? $im_channels : array();
                $connected_channels = array_values( array_filter( $im_channels, static function ( $channel ) {
                    return ! empty( $channel['connected'] );
                } ) );
                $connected_count    = count( $connected_channels );
                ?>
                <?php if ( $connected_count > 0 ) : ?>
                    <div class="wp-agent-card-value">
                        <span class="wp-agent-status wp-agent-status--ok">
                            <?php
                            printf(
                                /* translators: %1$d: connected channels, %2$d: total supported channels. */
                                esc_html__( '%1$d of %2$d connected', 'wp-agent' ),
                                (int) $connected_count,
                                (int) count( $im_channels )
                            );
                            ?>
                        </span>
                    </div>
                    <div class="wp-agent-card-detail">
                        <?php
                        echo esc_html( implode( ', ', wp_list_pluck( $connected_channels, 'label' ) ) );
                        ?>
                    </div>
                <?php else : ?>
                    <div class="wp-agent-card-value"><span class="wp-agent-status wp-agent-status--warn"><?php esc_html_e( 'Not connected', 'wp-agent' ); ?></span></div>
                    <div class="wp-agent-card-detail">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-agent-settings' ) ); ?>"><?php esc_html_e( 'Set up Telegram, Slack, or Discord', 'wp-agent' ); ?></a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="wp-agent-card">
                <div class="wp-agent-card-label"><?php esc_html_e( 'Paired Channels', 'wp-agent' ); ?></div>
                <div class="wp-agent-card-value"><?php echo esc_html( count( $pairings ) ); ?></div>
            </div>

            <div class="wp-agent-card">
                <div class="wp-agent-card-label"><?php esc_html_e( 'This Month', 'wp-agent' ); ?></div>
                <div class="wp-agent-card-value wp-agent-card-value--accent">$<?php echo esc_html( number_format( $usage['total_cost'], 2 ) ); ?></div>
                <div class="wp-agent-card-detail">
                    <?php
                    // translators: %1$s: number of requests, %2$s: number of tokens.
                    printf(
                        esc_html__( '%1$s requests &middot; %2$s tokens', 'wp-agent' ),
                        esc_html( number_format( $usage['request_count'] ) ),
                        esc_html( number_format( $usage['total_tokens_in'] + $usage['total_tokens_out'] ) )
                    ); ?>
                </div>
            </div>
        </div>

        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Autonomous Runtime', 'wp-agent' ); ?></h2>
            <?php if ( isset( $_GET['wp_agent_daemon'] ) ) : ?>
                <div class="wp-agent-notice wp-agent-notice-info">
                    <?php
                    $daemon_notice = sanitize_key( wp_unslash( $_GET['wp_agent_daemon'] ) );
                    if ( 'started' === $daemon_notice ) {
                        esc_html_e( 'Agent daemon wake requested.', 'wp-agent' );
                    } elseif ( 'running' === $daemon_notice ) {
                        esc_html_e( 'Agent daemon is already running.', 'wp-agent' );
                    } elseif ( 'stopping' === $daemon_notice ) {
                        esc_html_e( 'Agent daemon stop requested.', 'wp-agent' );
                    } else {
                        esc_html_e( 'Agent daemon action failed. Check the runtime log path below.', 'wp-agent' );
                    }
                    ?>
                </div>
            <?php endif; ?>
            <div class="wp-agent-cards">
                <div class="wp-agent-card">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Queued Runs', 'wp-agent' ); ?></div>
                    <div class="wp-agent-card-value"><?php echo esc_html( number_format_i18n( $runtime_status['counts']['queued'] ?? 0 ) ); ?></div>
                </div>
                <div class="wp-agent-card">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Running Runs', 'wp-agent' ); ?></div>
                    <div class="wp-agent-card-value"><?php echo esc_html( number_format_i18n( $runtime_status['counts']['running'] ?? 0 ) ); ?></div>
                </div>
                <div class="wp-agent-card">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Failed Runs', 'wp-agent' ); ?></div>
                    <div class="wp-agent-card-value"><?php echo esc_html( number_format_i18n( $runtime_status['counts']['error'] ?? 0 ) ); ?></div>
                </div>
                <div class="wp-agent-card">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Agent Daemon', 'wp-agent' ); ?></div>
                    <?php $daemon = $runtime_status['daemon'] ?? array(); ?>
                    <div class="wp-agent-card-value">
                        <span class="wp-agent-status <?php echo ! empty( $daemon['running'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                            <?php echo ! empty( $daemon['running'] ) ? esc_html__( 'Running', 'wp-agent' ) : esc_html__( 'Stopped', 'wp-agent' ); ?>
                        </span>
                    </div>
                    <div class="wp-agent-card-detail">
                        <?php
                        printf(
                            /* translators: %1$s: process id, %2$s: heartbeat age. */
                            esc_html__( 'PID %1$s · heartbeat %2$s ago', 'wp-agent' ),
                            esc_html( (string) ( $daemon['pid'] ?? 0 ) ),
                            isset( $daemon['heartbeat_age'] ) && null !== $daemon['heartbeat_age'] ? esc_html( human_time_diff( time() - (int) $daemon['heartbeat_age'], time() ) ) : esc_html__( 'never', 'wp-agent' )
                        );
                        ?>
                    </div>
                    <?php if ( ! empty( $daemon['liveness_source'] ) ) : ?>
                        <div class="wp-agent-card-detail">
                            <span class="wp-agent-status <?php echo 'pid' === ( $daemon['liveness_source'] ?? '' ) || 'heartbeat' === ( $daemon['liveness_source'] ?? '' ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                                <?php
                                if ( 'pid' === ( $daemon['liveness_source'] ?? '' ) ) {
                                    esc_html_e( 'PID verified', 'wp-agent' );
                                } elseif ( 'heartbeat' === ( $daemon['liveness_source'] ?? '' ) ) {
                                    esc_html_e( 'Heartbeat verified', 'wp-agent' );
                                } elseif ( 'starting' === ( $daemon['liveness_source'] ?? '' ) ) {
                                    esc_html_e( 'Starting', 'wp-agent' );
                                } elseif ( 'stale_heartbeat' === ( $daemon['liveness_source'] ?? '' ) ) {
                                    esc_html_e( 'Stale heartbeat', 'wp-agent' );
                                } else {
                                    esc_html_e( 'No heartbeat', 'wp-agent' );
                                }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $daemon['last_watchdog_action'] ) ) : ?>
                        <div class="wp-agent-card-detail">
                            <?php
                            printf(
                                /* translators: %1$s: watchdog action, %2$d: restart count. */
                                esc_html__( 'Watchdog %1$s · restarts %2$d', 'wp-agent' ),
                                esc_html( (string) $daemon['last_watchdog_action'] ),
                                (int) ( $daemon['watchdog_restart_count'] ?? 0 )
                            );
                            ?>
                        </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $daemon['watchdog_backoff_remaining'] ) ) : ?>
                        <div class="wp-agent-card-detail">
                            <?php
                            printf(
                                /* translators: %s: remaining backoff time. */
                                esc_html__( 'Restart backoff %s remaining', 'wp-agent' ),
                                esc_html( human_time_diff( time(), time() + (int) $daemon['watchdog_backoff_remaining'] ) )
                            );
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="wp-agent-card">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Sub Agents', 'wp-agent' ); ?></div>
                    <div class="wp-agent-card-value"><?php echo esc_html( number_format_i18n( $daemon['active_children'] ?? 0 ) ); ?>/<?php echo esc_html( number_format_i18n( $daemon['max_children'] ?? 1 ) ); ?></div>
                    <div class="wp-agent-card-detail">
                        <span class="wp-agent-status <?php echo ! empty( $daemon['can_fork'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                            <?php echo ! empty( $daemon['can_fork'] ) ? esc_html__( 'Fork enabled', 'wp-agent' ) : esc_html__( 'Single-process fallback', 'wp-agent' ); ?>
                        </span>
                    </div>
                </div>
                <div class="wp-agent-card">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Cron Fallback', 'wp-agent' ); ?></div>
                    <div class="wp-agent-card-value">
                        <span class="wp-agent-status <?php echo ! empty( $runtime_status['has_worker'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                            <?php echo ! empty( $runtime_status['has_worker'] ) ? esc_html__( 'Scheduled', 'wp-agent' ) : esc_html__( 'Not scheduled', 'wp-agent' ); ?>
                        </span>
                    </div>
                    <?php if ( ! empty( $runtime_status['next_worker'] ) ) : ?>
                        <div class="wp-agent-card-detail">
                            <?php if ( ! empty( $runtime_status['next_worker']['due'] ) ) : ?>
                                <?php esc_html_e( 'Due now; WP-Cron will run on the next site request.', 'wp-agent' ); ?>
                            <?php else : ?>
                                <?php
                                printf(
                                    /* translators: %s: relative time until next worker tick. */
                                    esc_html__( 'Next tick in %s', 'wp-agent' ),
                                    esc_html( $runtime_status['next_worker']['relative'] )
                                );
                                ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wp-agent-runtime-actions">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-agent-inline-form">
                    <?php wp_nonce_field( 'wp_agent_daemon_wake' ); ?>
                    <input type="hidden" name="action" value="wp_agent_daemon_wake" />
                    <label for="wp_agent_max_children"><?php esc_html_e( 'Sub-agent slots', 'wp-agent' ); ?></label>
                    <input id="wp_agent_max_children" type="number" name="max_children" value="<?php echo esc_attr( (string) ( $daemon['configured_max_children'] ?? 3 ) ); ?>" min="1" max="10" />
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Wake Agent', 'wp-agent' ); ?></button>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-agent-inline-form">
                    <?php wp_nonce_field( 'wp_agent_daemon_stop' ); ?>
                    <input type="hidden" name="action" value="wp_agent_daemon_stop" />
                    <button type="submit" class="button"><?php esc_html_e( 'Stop Agent', 'wp-agent' ); ?></button>
                </form>
                <div class="wp-agent-card-detail">
                    <?php
                    printf(
                        /* translators: %s: daemon log file path. */
                        esc_html__( 'Runtime log: %s', 'wp-agent' ),
                        esc_html( (string) ( $daemon['log_file'] ?? '' ) )
                    );
                    ?>
                </div>
            </div>

            <?php
            $isolation = $runtime_status['isolation'] ?? array();
            $storage   = $runtime_status['storage'] ?? array();
            $storage_source = (string) ( $storage['active_source'] ?? '' );
            $storage_source_label = (string) ( $storage['active_source_label'] ?? '' );
            if ( '' === $storage_source_label ) {
                $storage_source_label = WPAgent_Sandbox::runtime_root_source_label( $storage_source );
            }
            $backends  = $isolation['backends'] ?? array();
            $backend_names = array_values( array_intersect( array( 'namespace', 'wasm' ), array_keys( $backends ) ) );
            $diagnostics = $runtime_status['diagnostics'] ?? array();
            $diag_php    = $diagnostics['php'] ?? array();
            $diag_ai     = $diagnostics['ai'] ?? array();
            $diag_opcache = $diagnostics['opcache'] ?? array();
            $diag_queue  = $diagnostics['queue'] ?? array();
            $diag_schedules = $diagnostics['schedules'] ?? array();
            $diag_db     = $diagnostics['database'] ?? array();
            $diag_daemon = $diagnostics['daemon'] ?? array();
            $diag_skills = $diagnostics['skills'] ?? array();
            $diag_github_store = is_array( $diag_skills['github_store'] ?? null ) ? $diag_skills['github_store'] : array();
            $diag_perf   = $diagnostics['performance'] ?? array();
            $recent_bound_skill_runs = is_array( $diag_schedules['recent_bound_skill_runs'] ?? null )
                ? array_slice( $diag_schedules['recent_bound_skill_runs'], 0, 3 )
                : array();
            $oldest_queued_age = $diag_queue['oldest_queued_age'] ?? null;
            $oldest_queued = null === $oldest_queued_age
                ? __( 'None', 'wp-agent' )
                : human_time_diff( time() - (int) $oldest_queued_age, time() );
            $oldest_due_age = $diag_schedules['oldest_due_age'] ?? null;
            $oldest_due = null === $oldest_due_age
                ? __( 'None', 'wp-agent' )
                : human_time_diff( time() - (int) $oldest_due_age, time() );
            $jit_label = __( 'Off', 'wp-agent' );
            if ( ! empty( $diag_opcache['jit_on'] ) ) {
                $jit_label = __( 'On', 'wp-agent' );
            } elseif ( ! empty( $diag_opcache['jit_buffer_size_bytes'] ) ) {
                $jit_label = __( 'Configured', 'wp-agent' );
            }
            ?>
            <div class="wp-agent-runtime-health">
                <div class="wp-agent-runtime-panel">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'AI Readiness', 'wp-agent' ); ?></div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Content model', 'wp-agent' ); ?></span>
                        <span>
                            <span class="wp-agent-status <?php echo ! empty( $diag_ai['content_ready'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                                <?php echo ! empty( $diag_ai['content_ready'] ) ? esc_html__( 'Ready', 'wp-agent' ) : esc_html__( 'Needs setup', 'wp-agent' ); ?>
                            </span>
                            <code><?php echo esc_html( $diag_ai['model'] ?? '' ); ?></code>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Image model', 'wp-agent' ); ?></span>
                        <span>
                            <span class="wp-agent-status <?php echo ! empty( $diag_ai['image_generation_ready'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                                <?php echo ! empty( $diag_ai['image_generation_ready'] ) ? esc_html__( 'Ready', 'wp-agent' ) : esc_html__( 'Optional setup', 'wp-agent' ); ?>
                            </span>
                            <code><?php echo esc_html( $diag_ai['image_model'] ?? '' ); ?></code>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Endpoint', 'wp-agent' ); ?></span>
                        <span class="wp-agent-text-muted">
                            <?php echo esc_html( $diag_ai['base_url_host'] ?? '' ); ?>
                            <?php if ( ! empty( $diag_ai['base_url_source'] ) ) : ?>
                                <?php echo esc_html( ' · ' . $diag_ai['base_url_source'] ); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="wp-agent-runtime-panel">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Execution Isolation', 'wp-agent' ); ?></div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Selected backend', 'wp-agent' ); ?></span>
                        <strong><?php echo esc_html( $isolation['selected'] ?? 'disabled' ); ?></strong>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Code execution', 'wp-agent' ); ?></span>
                        <span class="wp-agent-status <?php echo ! empty( $isolation['execution'] ) && 'enabled' === $isolation['execution'] ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                            <?php echo ! empty( $isolation['execution'] ) && 'enabled' === $isolation['execution'] ? esc_html__( 'Enabled', 'wp-agent' ) : esc_html__( 'Disabled', 'wp-agent' ); ?>
                        </span>
                    </div>
                    <?php foreach ( $backend_names as $backend_name ) : ?>
                        <?php $backend = $backends[ $backend_name ] ?? array(); ?>
                        <div class="wp-agent-runtime-line">
                            <span><?php echo esc_html( ucfirst( $backend_name ) ); ?></span>
                            <span class="wp-agent-text-muted"><?php echo esc_html( $backend['reason'] ?? __( 'Not checked', 'wp-agent' ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="wp-agent-runtime-panel">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Private Storage', 'wp-agent' ); ?></div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Runtime root', 'wp-agent' ); ?></span>
                        <code><?php echo esc_html( $storage['runtime_root'] ?? '' ); ?></code>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Sandbox base', 'wp-agent' ); ?></span>
                        <code><?php echo esc_html( $storage['sandbox_base'] ?? '' ); ?></code>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Writable', 'wp-agent' ); ?></span>
                        <span class="wp-agent-status <?php echo ! empty( $storage['writable'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--error'; ?>">
                            <?php echo ! empty( $storage['writable'] ) ? esc_html__( 'Ready', 'wp-agent' ) : esc_html__( 'Needs attention', 'wp-agent' ); ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Configuration', 'wp-agent' ); ?></span>
                        <span class="wp-agent-text-muted">
                            <?php echo esc_html( $storage_source_label ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-agent-settings#wp_agent_runtime_root' ) ); ?>"><?php esc_html_e( 'Change', 'wp-agent' ); ?></a>
                        </span>
                    </div>
                </div>
                <div class="wp-agent-runtime-panel wp-agent-runtime-panel--wide">
                    <div class="wp-agent-card-label"><?php esc_html_e( 'Runtime Diagnostics', 'wp-agent' ); ?></div>
                    <div class="wp-agent-runtime-metrics">
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'PHP process', 'wp-agent' ); ?></span>
                        <span>
                            <?php
                            printf(
                                /* translators: %1$s: PHP version, %2$s: PHP SAPI. */
                                esc_html__( '%1$s (%2$s)', 'wp-agent' ),
                                esc_html( $diag_php['version'] ?? PHP_VERSION ),
                                esc_html( $diag_php['sapi'] ?? PHP_SAPI )
                            );
                            ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Process memory', 'wp-agent' ); ?></span>
                        <span>
                            <?php
                            printf(
                                /* translators: %1$s: current memory, %2$s: peak memory, %3$s: memory limit. */
                                esc_html__( '%1$s current / %2$s peak / %3$s limit', 'wp-agent' ),
                                esc_html( $diag_php['memory_usage_display'] ?? '' ),
                                esc_html( $diag_php['memory_peak_display'] ?? '' ),
                                esc_html( $diag_php['memory_limit_display'] ?? '' )
                            );
                            ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Daemon memory', 'wp-agent' ); ?></span>
                        <span>
                            <?php if ( ! empty( $diag_daemon['running'] ) ) : ?>
                                <?php
                                printf(
                                    /* translators: %1$s: daemon memory, %2$s: daemon peak memory, %3$d: garbage collection runs. */
                                    esc_html__( '%1$s current / %2$s peak / GC %3$d', 'wp-agent' ),
                                    esc_html( $diag_daemon['memory_usage_display'] ?? '' ),
                                    esc_html( $diag_daemon['memory_peak_display'] ?? '' ),
                                    (int) ( $diag_daemon['gc_runs'] ?? 0 )
                                );
                                ?>
                            <?php else : ?>
                                <?php esc_html_e( 'No live daemon sample', 'wp-agent' ); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Daemon liveness', 'wp-agent' ); ?></span>
                        <span>
                            <span class="wp-agent-status <?php echo ! empty( $diag_daemon['running'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                                <?php
                                if ( 'pid' === ( $diag_daemon['liveness_source'] ?? '' ) ) {
                                    esc_html_e( 'PID verified', 'wp-agent' );
                                } elseif ( 'heartbeat' === ( $diag_daemon['liveness_source'] ?? '' ) ) {
                                    esc_html_e( 'Heartbeat verified', 'wp-agent' );
                                } elseif ( 'starting' === ( $diag_daemon['liveness_source'] ?? '' ) ) {
                                    esc_html_e( 'Starting', 'wp-agent' );
                                } elseif ( 'stale_heartbeat' === ( $diag_daemon['liveness_source'] ?? '' ) ) {
                                    esc_html_e( 'Stale heartbeat', 'wp-agent' );
                                } else {
                                    esc_html_e( 'No heartbeat', 'wp-agent' );
                                }
                                ?>
                            </span>
                            <?php if ( ! empty( $diag_daemon['liveness_note'] ) ) : ?>
                                <span class="wp-agent-text-muted"><?php echo esc_html( $diag_daemon['liveness_note'] ); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'OPcache CLI', 'wp-agent' ); ?></span>
                        <span class="wp-agent-status <?php echo ! empty( $diag_opcache['enable_cli'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--warn'; ?>">
                            <?php echo ! empty( $diag_opcache['enable_cli'] ) ? esc_html__( 'Enabled', 'wp-agent' ) : esc_html__( 'Disabled', 'wp-agent' ); ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'JIT', 'wp-agent' ); ?></span>
                        <span>
                            <?php
                            printf(
                                /* translators: %1$s: JIT state, %2$s: JIT buffer size. */
                                esc_html__( '%1$s / buffer %2$s', 'wp-agent' ),
                                esc_html( $jit_label ),
                                esc_html( $diag_opcache['jit_buffer_size_display'] ?? '' )
                            );
                            ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Queue lag', 'wp-agent' ); ?></span>
                        <span>
                            <?php
                            printf(
                                /* translators: %1$s: oldest queued age, %2$d: claimable run count. */
                                esc_html__( 'Oldest queued %1$s / claimable %2$d', 'wp-agent' ),
                                esc_html( $oldest_queued ),
                                (int) ( $diag_queue['claimable_count'] ?? 0 )
                            );
                            ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Schedule health', 'wp-agent' ); ?></span>
                        <span>
                            <?php
                            printf(
                                /* translators: %1$d: due schedules, %2$s: oldest due age, %3$d: locked schedules. */
                                esc_html__( 'Due %1$d / oldest %2$s / locked %3$d', 'wp-agent' ),
                                (int) ( $diag_schedules['due_count'] ?? 0 ),
                                esc_html( $oldest_due ),
                                (int) ( $diag_schedules['locked_count'] ?? 0 )
                            );
                            ?>
                        </span>
                    </div>
                    <?php if ( ! empty( $diag_schedules['stale_lock_count'] ) || ! empty( $diag_schedules['due_locked_count'] ) ) : ?>
                        <div class="wp-agent-runtime-line">
                            <span><?php esc_html_e( 'Schedule locks', 'wp-agent' ); ?></span>
                            <span>
                                <?php
                                printf(
                                    /* translators: %1$d: due locked schedules, %2$d: stale lock count. */
                                    esc_html__( 'Due locked %1$d / stale %2$d', 'wp-agent' ),
                                    (int) ( $diag_schedules['due_locked_count'] ?? 0 ),
                                    (int) ( $diag_schedules['stale_lock_count'] ?? 0 )
                                );
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Skill-bound schedules', 'wp-agent' ); ?></span>
                        <span>
                            <?php
                            printf(
                                /* translators: %1$d: Skill-bound schedule count, %2$d: active Skill-bound schedules, %3$d: recently checked rows. */
                                esc_html__( '%1$d total / %2$d active / %3$d checked', 'wp-agent' ),
                                (int) ( $diag_schedules['skill_bound_count'] ?? 0 ),
                                (int) ( $diag_schedules['skill_bound_active_count'] ?? 0 ),
                                (int) ( $diag_schedules['skill_bound_recent_checked'] ?? 0 )
                            );
                            ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Skills Store', 'wp-agent' ); ?></span>
                        <?php
                        $store_ready = ! empty( $diag_github_store['ready'] );
                        $store_status_class = $store_ready ? 'wp-agent-status--ok' : 'wp-agent-status--warn';
                        $store_status_label = $store_ready ? __( 'Ready', 'wp-agent' ) : __( 'Needs defaults', 'wp-agent' );
                        if ( in_array( 'github_token_unreadable', (array) ( $diag_github_store['warnings'] ?? array() ), true ) ) {
                            $store_status_label = __( 'Token needs re-save', 'wp-agent' );
                        }
                        ?>
                        <span>
                            <span class="wp-agent-status <?php echo esc_attr( $store_status_class ); ?>"><?php echo esc_html( $store_status_label ); ?></span>
                            <span class="wp-agent-text-muted">
                                <?php
                                printf(
                                    /* translators: %1$s: GitHub repository, %2$s: Git ref, %3$s: Skill path. */
                                    esc_html__( '%1$s · %2$s · %3$s', 'wp-agent' ),
                                    esc_html( '' !== (string) ( $diag_github_store['repository'] ?? '' ) ? $diag_github_store['repository'] : __( 'repository missing', 'wp-agent' ) ),
                                    esc_html( $diag_github_store['ref'] ?? 'main' ),
                                    esc_html( '' !== (string) ( $diag_github_store['skill_path'] ?? '' ) ? $diag_github_store['skill_path'] : __( 'path missing', 'wp-agent' ) )
                                );
                                ?>
                            </span>
                        </span>
                    </div>
                    <?php if ( ! empty( $recent_bound_skill_runs ) ) : ?>
                        <div class="wp-agent-runtime-line wp-agent-runtime-line--full">
                            <span><?php esc_html_e( 'Recent Skill policies', 'wp-agent' ); ?></span>
                            <span class="wp-agent-runtime-stack wp-agent-runtime-stack--grid">
                                <?php foreach ( $recent_bound_skill_runs as $bound_run ) : ?>
                                    <?php
                                    $skill_found       = ! empty( $bound_run['skill_found'] );
                                    $permissions_found = ! empty( $bound_run['permissions_found'] );
                                    $restricted        = ! empty( $bound_run['restricted'] );
                                    $allowed_tools     = is_array( $bound_run['allowed_tools'] ?? null ) ? $bound_run['allowed_tools'] : array();
                                    if ( ! $skill_found ) {
                                        $policy_class = 'wp-agent-status--error';
                                        $policy_label = __( 'Missing Skill', 'wp-agent' );
                                    } elseif ( ! $permissions_found ) {
                                        $policy_class = 'wp-agent-status--warn';
                                        $policy_label = __( 'No permissions', 'wp-agent' );
                                    } elseif ( $restricted ) {
                                        $policy_class = 'wp-agent-status--ok';
                                        $policy_label = __( 'Restricted', 'wp-agent' );
                                    } else {
                                        $policy_class = 'wp-agent-status--info';
                                        $policy_label = __( 'Observed', 'wp-agent' );
                                    }
                                    $run_label = ! empty( $bound_run['run_id'] )
                                        ? '#' . (int) $bound_run['run_id']
                                        : __( 'none', 'wp-agent' );
                                    $tool_preview = implode( ', ', array_slice( array_map( 'sanitize_text_field', $allowed_tools ), 0, 3 ) );
                                    ?>
                                    <span class="wp-agent-runtime-mini-row">
                                        <code><?php echo esc_html( $bound_run['skill_slug'] ?? '' ); ?></code>
                                        <span class="wp-agent-status <?php echo esc_attr( $policy_class ); ?>"><?php echo esc_html( $policy_label ); ?></span>
                                        <span class="wp-agent-text-muted">
                                            <?php
                                            printf(
                                                /* translators: %1$d: schedule ID, %2$s: run label, %3$d: allowed tool count. */
                                                esc_html__( 'Schedule #%1$d · run %2$s · %3$d tools', 'wp-agent' ),
                                                (int) ( $bound_run['schedule_id'] ?? 0 ),
                                                esc_html( $run_label ),
                                                (int) ( $bound_run['allowed_tool_count'] ?? count( $allowed_tools ) )
                                            );
                                            ?>
                                            <?php if ( '' !== $tool_preview ) : ?>
                                                <?php echo esc_html( ' · ' . $tool_preview ); ?>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Database ping', 'wp-agent' ); ?></span>
                        <span class="wp-agent-status <?php echo ! empty( $diag_db['ok'] ) ? 'wp-agent-status--ok' : 'wp-agent-status--error'; ?>">
                            <?php
                            printf(
                                /* translators: %s: query duration in milliseconds. */
                                esc_html__( '%s ms', 'wp-agent' ),
                                esc_html( number_format_i18n( (float) ( $diag_db['query_ms'] ?? 0 ), 2 ) )
                            );
                            ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Autoload options', 'wp-agent' ); ?></span>
                        <span>
                            <?php
                            printf(
                                /* translators: %1$d: autoloaded option count, %2$s: estimated autoloaded option size. */
                                esc_html__( '%1$d options / %2$s', 'wp-agent' ),
                                (int) ( $diag_perf['autoload_options_count'] ?? 0 ),
                                esc_html( $diag_perf['autoload_options_display'] ?? '' )
                            );
                            ?>
                        </span>
                    </div>
                    <div class="wp-agent-runtime-line">
                        <span><?php esc_html_e( 'Loaded files', 'wp-agent' ); ?></span>
                        <span>
                            <?php
                            printf(
                                /* translators: %1$d: included PHP file count, %2$d: WP Agent included PHP file count. */
                                esc_html__( '%1$d total / %2$d WP Agent', 'wp-agent' ),
                                (int) ( $diag_perf['included_files_count'] ?? 0 ),
                                (int) ( $diag_perf['wp_agent_included_files_count'] ?? 0 )
                            );
                            ?>
                        </span>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Conversations -->
        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Recent Conversations', 'wp-agent' ); ?></h2>

            <?php if ( ! empty( $recent ) ) : ?>
            <div class="wp-agent-table-wrap">
                <div class="wp-agent-table-scroll">
                    <table class="wp-agent-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Channel', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'First Message', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Messages', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Last Active', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recent as $convo ) : ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Channel', 'wp-agent' ); ?>"><span class="wp-agent-badge wp-agent-badge--channel"><?php echo esc_html( ucfirst( $convo['channel'] ) ); ?></span></td>
                                <td data-label="<?php esc_attr_e( 'First Message', 'wp-agent' ); ?>"><?php echo esc_html( wp_trim_words( $convo['first_message'] ?? "\xe2\x80\x94", 12 ) ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Messages', 'wp-agent' ); ?>"><?php echo esc_html( $convo['message_count'] ); ?></td>
                                <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Last Active', 'wp-agent' ); ?>"><?php echo esc_html( human_time_diff( strtotime( $convo['updated_at'] ) ) ); ?> ago</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <p style="margin-top: 12px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-agent-logs' ) ); ?>"><?php esc_html_e( 'View all conversations', 'wp-agent' ); ?> &rarr;</a></p>
            <?php else : ?>
            <div class="wp-agent-empty">
                <div class="wp-agent-empty-icon">&#128172;</div>
                <h3><?php esc_html_e( 'No conversations yet', 'wp-agent' ); ?></h3>
                <p><?php esc_html_e( 'Open the WP Agent workspace to begin a conversation.', 'wp-agent' ); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick Start Guide -->
        <?php if ( ! $has_api_key ) : ?>
        <div class="wp-agent-info-box">
            <h3><?php esc_html_e( 'Quick Start', 'wp-agent' ); ?></h3>
            <ol>
                <li><?php esc_html_e( 'Get an API key for your configured AI gateway.', 'wp-agent' ); ?></li>
                <?php // translators: %s: link to Settings page. ?>
                <li><?php printf( esc_html__( 'Enter it in %s', 'wp-agent' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wp-agent-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-agent' ) . '</a>' ); ?></li>
                <li><?php esc_html_e( 'Open the WP Agent workspace and say hello.', 'wp-agent' ); ?></li>
                <li><?php esc_html_e( '(Optional) Connect Telegram, Slack, or Discord to manage your site on the go', 'wp-agent' ); ?></li>
            </ol>
        </div>
        <?php endif; ?>

    </div>
</div>
