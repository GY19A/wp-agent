<?php
/**
 * Scheduled Tasks page template.
 *
 * Lists recurring scheduled agent tasks and exposes per-row
 * Run now / Pause / Resume / Delete controls wired to the
 * wp-agent/v1 /schedules REST endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$schedules = WPAgent_Schedules::all();

/**
 * Render a UTC datetime in the site timezone, or an em-dash when empty.
 *
 * @param string|null $gmt UTC 'Y-m-d H:i:s' value.
 * @return string
 */
$format_local = static function ( $gmt ) {
    if ( empty( $gmt ) ) {
        return "\xe2\x80\x94";
    }
    $local = get_date_from_gmt( $gmt );
    $ts    = strtotime( $local );
    if ( ! $ts ) {
        return esc_html( $gmt );
    }
    return esc_html( date_i18n( 'M j, Y g:i a', $ts ) );
};

/**
 * Map a last_status string to a wp-agent-status modifier class.
 *
 * @param string $status One of ok|error or empty.
 * @return string
 */
$status_class = static function ( $status ) {
    if ( in_array( $status, array( 'ok', 'done' ), true ) ) {
        return 'wp-agent-status--ok';
    }
    if ( 'error' === $status ) {
        return 'wp-agent-status--error';
    }
    return 'wp-agent-status--warn';
};
?>
<div class="wp-agent-wrap">
    <div class="wp-agent-page-header">
        <h1><?php esc_html_e( 'Scheduled Tasks', 'wp-agent' ); ?></h1>
        <p><?php esc_html_e( 'Recurring agent runs. Each run executes the full agent loop as the wp-agent user.', 'wp-agent' ); ?></p>
    </div>

    <div class="wp-agent-page-content">

        <?php if ( ! empty( $schedules ) ) : ?>
        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Schedules', 'wp-agent' ); ?></h2>
            <div class="wp-agent-table-wrap">
                <div class="wp-agent-table-scroll">
                    <table class="wp-agent-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;"><?php esc_html_e( 'ID', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Prompt', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Skill', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Schedule', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Next Run', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Last Run', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $schedules as $schedule ) : ?>
                            <?php
                            $is_active = ( 'active' === $schedule->status );
                            $interval  = ucfirst( (string) $schedule->schedule_interval );
                            $time_text = '';
                            if ( 'minutes' === $schedule->schedule_interval ) {
                                $minutes = isset( $schedule->interval_minutes ) ? max( 1, (int) $schedule->interval_minutes ) : 5;
                                $interval = sprintf(
                                    /* translators: %d: number of minutes between scheduled runs. */
                                    _n( 'Every %d minute', 'Every %d minutes', $minutes, 'wp-agent' ),
                                    $minutes
                                );
                            } elseif ( 'hourly' !== $schedule->schedule_interval ) {
                                $time_text = ! empty( $schedule->time_of_day ) ? $schedule->time_of_day : '09:00';
                            }
                            $sched_label = $interval;
                            if ( '' !== $time_text ) {
                                $sched_label .= ' @ ' . $time_text;
                            }
                            $skill_slug = ! empty( $schedule->skill_slug ) ? sanitize_title( $schedule->skill_slug ) : '';
                            ?>
                            <tr data-id="<?php echo esc_attr( $schedule->id ); ?>">
                                <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'ID', 'wp-agent' ); ?>">#<?php echo esc_html( $schedule->id ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Prompt', 'wp-agent' ); ?>"><?php echo esc_html( wp_trim_words( (string) $schedule->prompt, 18 ) ); ?></td>
                                <td data-label="<?php esc_attr_e( 'Skill', 'wp-agent' ); ?>">
                                    <?php if ( '' !== $skill_slug ) : ?>
                                        <code><?php echo esc_html( $skill_slug ); ?></code>
                                    <?php else : ?>
                                        <span class="wp-agent-text-muted"><?php esc_html_e( 'None', 'wp-agent' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Schedule', 'wp-agent' ); ?>"><?php echo esc_html( $sched_label ); ?></td>
                                <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Next Run', 'wp-agent' ); ?>"><?php echo $format_local( $schedule->next_run ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Last Run', 'wp-agent' ); ?>">
                                    <?php echo $format_local( $schedule->last_run ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php if ( ! empty( $schedule->last_status ) ) : ?>
                                        <span class="wp-agent-status <?php echo esc_attr( $status_class( $schedule->last_status ) ); ?>"><?php echo esc_html( ucfirst( $schedule->last_status ) ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $schedule->last_run_id ) ) : ?>
                                        <div class="wp-agent-text-muted">#<?php echo esc_html( (int) $schedule->last_run_id ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Status', 'wp-agent' ); ?>">
                                    <span class="wp-agent-badge"><?php echo esc_html( ucfirst( (string) $schedule->status ) ); ?></span>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Actions', 'wp-agent' ); ?>">
                                    <button type="button" class="wp-agent-btn wp-agent-btn-primary wp-agent-btn-sm wp-agent-schedule-run"
                                            data-id="<?php echo esc_attr( $schedule->id ); ?>">
                                        <?php esc_html_e( 'Run now', 'wp-agent' ); ?>
                                    </button>
                                    <?php if ( $is_active ) : ?>
                                        <button type="button" class="wp-agent-btn wp-agent-btn-secondary wp-agent-btn-sm wp-agent-schedule-toggle"
                                                data-id="<?php echo esc_attr( $schedule->id ); ?>" data-action="pause">
                                            <?php esc_html_e( 'Pause', 'wp-agent' ); ?>
                                        </button>
                                    <?php else : ?>
                                        <button type="button" class="wp-agent-btn wp-agent-btn-secondary wp-agent-btn-sm wp-agent-schedule-toggle"
                                                data-id="<?php echo esc_attr( $schedule->id ); ?>" data-action="resume">
                                            <?php esc_html_e( 'Resume', 'wp-agent' ); ?>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="wp-agent-btn wp-agent-btn-danger wp-agent-btn-sm wp-agent-schedule-delete"
                                            data-id="<?php echo esc_attr( $schedule->id ); ?>">
                                        <?php esc_html_e( 'Delete', 'wp-agent' ); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="wp-agent-form-help" style="margin-top: 12px;">
                <?php esc_html_e( 'Create and manage schedules conversationally by asking the agent to "schedule a site health check every 5 minutes" — it uses the manage_schedules tool.', 'wp-agent' ); ?>
            </p>
        </div>
        <?php else : ?>
        <div class="wp-agent-empty">
            <div class="wp-agent-empty-icon">&#128197;</div>
            <h3><?php esc_html_e( 'No scheduled tasks yet', 'wp-agent' ); ?></h3>
            <p><?php esc_html_e( 'Ask the agent to schedule a recurring task — for example, "run a site health check every 5 minutes".', 'wp-agent' ); ?></p>
        </div>
        <?php endif; ?>

    </div>
</div>
<script>
// Run after DOMContentLoaded so wpAgentChat (localized in the footer) is defined.
document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.wpAgentChat || {};
    if (!cfg.restUrl) {
        return;
    }

    function endpoint(path) {
        // restUrl already ends with the namespace + trailing slash, e.g. ".../wp-agent/v1/".
        // These endpoints carry their ids in the path and take no query string, so no
        // '?' vs '&' juggling is needed for the plain-permalink ?rest_route= form.
        return cfg.restUrl + path;
    }

    function send(path, method, btn) {
        if (btn) {
            btn.disabled = true;
        }
        return fetch(endpoint(path), {
            method: method,
            headers: { 'X-WP-Nonce': cfg.nonce || '' }
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                return { ok: r.ok, data: data };
            });
        });
    }

    // Run now.
    document.querySelectorAll('.wp-agent-schedule-run').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.dataset.id;
            var label = btn.textContent;
            btn.textContent = 'Running…';
            send('schedules/' + id + '/run', 'POST', btn).then(function (res) {
                if (res.ok) {
                    location.reload();
                } else {
                    btn.textContent = 'Failed';
                    btn.disabled = false;
                    setTimeout(function () { btn.textContent = label; }, 2000);
                }
            }).catch(function () {
                btn.textContent = 'Error';
                btn.disabled = false;
                setTimeout(function () { btn.textContent = label; }, 2000);
            });
        });
    });

    // Pause / Resume.
    document.querySelectorAll('.wp-agent-schedule-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.dataset.id;
            var action = this.dataset.action;
            send('schedules/' + id + '/' + action, 'POST', btn).then(function (res) {
                if (res.ok) {
                    location.reload();
                } else {
                    btn.disabled = false;
                }
            }).catch(function () {
                btn.disabled = false;
            });
        });
    });

    // Delete.
    document.querySelectorAll('.wp-agent-schedule-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Delete this scheduled task? This cannot be undone.')) {
                return;
            }
            var id = this.dataset.id;
            send('schedules/' + id, 'DELETE', btn).then(function (res) {
                if (res.ok) {
                    var row = btn.closest('tr');
                    if (row) {
                        row.remove();
                    }
                    location.reload();
                } else {
                    btn.disabled = false;
                }
            }).catch(function () {
                btn.disabled = false;
            });
        });
    });
});
</script>
