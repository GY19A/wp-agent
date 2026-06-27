<?php
/**
 * Skills page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wp-agent-wrap">
    <div class="wp-agent-page-header">
        <h1><?php esc_html_e( 'Skills', 'wp-agent' ); ?></h1>
        <p><?php esc_html_e( 'Reusable non-executable playbooks for repeatable agent workflows', 'wp-agent' ); ?></p>
    </div>

    <div class="wp-agent-page-content">
        <?php if ( ! empty( $notice ) ) : ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif; ?>
        <?php if ( ! empty( $error ) ) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Search GitHub Skills', 'wp-agent' ); ?></h2>
            <div class="wp-agent-form-card">
                <?php
                $search_defaults = is_array( $github_store ?? null ) ? $github_store : array();
                $search_repo_default = (string) ( $search_defaults['repository'] ?? '' );
                $search_ref_default  = (string) ( $search_defaults['ref'] ?? 'main' );
                ?>
                <p class="wp-agent-form-help" style="margin-top:0;">
                    <?php esc_html_e( 'Search a GitHub repository for installable skills, then download one to quarantine for review. Repository defaults to your configured Skill Store; you can search any public repo (owner/repo).', 'wp-agent' ); ?>
                </p>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Repository', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <input class="wp-agent-input" type="text" id="wpa-skill-search-repo" value="<?php echo esc_attr( $search_repo_default ); ?>" placeholder="<?php esc_attr_e( 'owner/repository (blank = default store)', 'wp-agent' ); ?>" />
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Search', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input class="wp-agent-input" type="search" id="wpa-skill-search-query" placeholder="<?php esc_attr_e( 'Search skills by name, keyword, or tool…', 'wp-agent' ); ?>" autocomplete="off" style="flex:1;" />
                            <button type="button" class="wp-agent-btn wp-agent-btn-primary" id="wpa-skill-search-btn"><?php esc_html_e( 'Search', 'wp-agent' ); ?></button>
                        </div>
                        <input type="hidden" id="wpa-skill-search-ref" value="<?php echo esc_attr( $search_ref_default ); ?>" />
                    </div>
                </div>
                <div id="wpa-skill-search-status" class="wp-agent-form-help" aria-live="polite"></div>
                <div id="wpa-skill-search-results" class="wp-agent-skill-search-results"></div>
            </div>
        </div>

        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Install From GitHub', 'wp-agent' ); ?></h2>
            <form method="post" class="wp-agent-form-card" id="wpa-github-install-form">
                <?php wp_nonce_field( 'wp_agent_skills', 'wp_agent_skills_nonce' ); ?>
                <input type="hidden" name="wp_agent_skill_action" value="install_github" />
                <?php
                $github_store_readiness = is_array( $github_store_readiness ?? null ) ? $github_store_readiness : WPAgent_Skills::github_store_readiness();
                $github_ready = ! empty( $github_store_readiness['ready'] );
                $github_status_class = $github_ready ? 'wp-agent-status--ok' : 'wp-agent-status--warn';
                $github_status_label = $github_ready ? __( 'Ready', 'wp-agent' ) : __( 'Needs defaults', 'wp-agent' );
                if ( in_array( 'github_token_unreadable', (array) ( $github_store_readiness['warnings'] ?? array() ), true ) ) {
                    $github_status_label = __( 'Token needs re-save', 'wp-agent' );
                }
                ?>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Store Readiness', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <span class="wp-agent-status <?php echo esc_attr( $github_status_class ); ?>"><?php echo esc_html( $github_status_label ); ?></span>
                        <?php if ( empty( $github_store_readiness['ready'] ) ) : ?>
                            <p class="wp-agent-form-help">
                                <?php esc_html_e( 'Set a default repository and Skill path in Settings before running default or live store acceptance workflows.', 'wp-agent' ); ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-agent-settings#wp_agent_github_default_repository' ) ); ?>"><?php esc_html_e( 'Open Settings', 'wp-agent' ); ?></a>
                            </p>
                        <?php else : ?>
                            <p class="wp-agent-form-help"><?php esc_html_e( 'Default GitHub installs can omit repository and Skill path.', 'wp-agent' ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ( ! empty( $github_store['configured'] ) ) : ?>
                    <div class="wp-agent-form-row">
                        <div class="wp-agent-form-label"><?php esc_html_e( 'Store Default', 'wp-agent' ); ?></div>
                        <div class="wp-agent-form-field">
                            <div class="wp-agent-text-muted">
                                <code><?php echo esc_html( $github_store['repository'] ); ?></code>
                                <?php echo esc_html( ' · ' . $github_store['ref'] . ' · ' . $github_store['skill_path'] ); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Repository', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <input class="wp-agent-input" id="wpa-github-repository" type="text" name="github_repository" value="<?php echo esc_attr( $github_store['repository'] ?? '' ); ?>" <?php echo empty( $github_store['repository'] ) ? 'required' : ''; ?> placeholder="<?php esc_attr_e( 'owner/repository', 'wp-agent' ); ?>" />
                        <p class="wp-agent-form-help"><?php esc_html_e( 'Packages are downloaded to private quarantine first. They are not active until reviewed.', 'wp-agent' ); ?></p>
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Ref', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <input class="wp-agent-input" id="wpa-github-ref" type="text" name="github_ref" value="<?php echo esc_attr( $github_store['ref'] ?? 'main' ); ?>" placeholder="<?php esc_attr_e( 'main, tag, or commit', 'wp-agent' ); ?>" />
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Skill Path', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <input class="wp-agent-input" id="wpa-github-skill-path" type="text" name="github_skill_path" value="<?php echo esc_attr( $github_store['skill_path'] ?? '' ); ?>" <?php echo empty( $github_store['skill_path'] ) ? 'required' : ''; ?> placeholder="<?php esc_attr_e( 'skills/news-rewrite-publisher', 'wp-agent' ); ?>" />
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Token', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <input class="wp-agent-input" type="password" name="github_token" autocomplete="off" placeholder="<?php esc_attr_e( 'Optional one-time token', 'wp-agent' ); ?>" />
                        <p class="wp-agent-form-help"><?php esc_html_e( 'Used only for this request; not stored or shown in lockfiles.', 'wp-agent' ); ?></p>
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"></div>
                    <div class="wp-agent-form-field">
                        <button type="submit" class="wp-agent-btn wp-agent-btn-primary"><?php esc_html_e( 'Download to Quarantine', 'wp-agent' ); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Quarantine Review', 'wp-agent' ); ?></h2>
            <?php if ( ! empty( $quarantine_packages ) ) : ?>
                <div class="wp-agent-table-wrap">
                    <table class="wp-agent-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Package', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Source', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Permissions', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Warnings', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Action', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $quarantine_packages as $package ) : ?>
                                <?php
                                $source      = $package['source'] ?? array();
                                $permissions = $package['permissions'] ?? array();
                                $tools       = ! empty( $permissions['tools'] ) && is_array( $permissions['tools'] ) ? $permissions['tools'] : array();
                                $warnings    = ! empty( $package['warnings'] ) && is_array( $package['warnings'] ) ? $package['warnings'] : array();
                                ?>
                                <tr>
                                    <td data-label="<?php esc_attr_e( 'Package', 'wp-agent' ); ?>">
                                        <strong><?php echo esc_html( $package['name'] ?? '' ); ?></strong>
                                        <div><code><?php echo esc_html( $package['slug'] ?? '' ); ?></code></div>
                                        <?php if ( ! empty( $package['description'] ) ) : ?>
                                            <div class="wp-agent-text-muted"><?php echo esc_html( wp_trim_words( $package['description'], 14 ) ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?php esc_attr_e( 'Source', 'wp-agent' ); ?>">
                                        <div><code><?php echo esc_html( $source['repository'] ?? '' ); ?></code></div>
                                        <div class="wp-agent-text-muted"><?php echo esc_html( ( $source['ref'] ?? '' ) . ' · ' . ( $source['path'] ?? '' ) ); ?></div>
                                    </td>
                                    <td data-label="<?php esc_attr_e( 'Permissions', 'wp-agent' ); ?>">
                                        <?php if ( ! empty( $tools ) ) : ?>
                                            <div><?php echo esc_html( implode( ', ', array_slice( $tools, 0, 5 ) ) ); ?></div>
                                        <?php endif; ?>
                                        <div class="wp-agent-text-muted">
                                            <?php
                                            echo ! empty( $permissions['network'] ) ? esc_html__( 'Network requested', 'wp-agent' ) : esc_html__( 'No network flag', 'wp-agent' );
                                            echo ' · ';
                                            echo ! empty( $permissions['code_execution'] ) ? esc_html__( 'Code execution requested', 'wp-agent' ) : esc_html__( 'No code execution', 'wp-agent' );
                                            ?>
                                        </div>
                                    </td>
                                    <td data-label="<?php esc_attr_e( 'Warnings', 'wp-agent' ); ?>">
                                        <?php if ( ! empty( $warnings ) ) : ?>
                                            <?php foreach ( array_slice( $warnings, 0, 3 ) as $warning ) : ?>
                                                <div class="wp-agent-text-muted"><?php echo esc_html( $warning ); ?></div>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <span class="wp-agent-text-muted"><?php esc_html_e( 'None', 'wp-agent' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?php esc_attr_e( 'Status', 'wp-agent' ); ?>"><span class="wp-agent-badge"><?php echo esc_html( $package['status'] ?? '' ); ?></span></td>
                                    <td data-label="<?php esc_attr_e( 'Action', 'wp-agent' ); ?>">
                                        <?php if ( 'quarantined' === ( $package['status'] ?? '' ) ) : ?>
                                            <form method="post">
                                                <?php wp_nonce_field( 'wp_agent_skills', 'wp_agent_skills_nonce' ); ?>
                                                <input type="hidden" name="wp_agent_skill_action" value="activate_quarantine" />
                                                <input type="hidden" name="quarantine_id" value="<?php echo esc_attr( $package['id'] ?? '' ); ?>" />
                                                <button type="submit" class="wp-agent-btn wp-agent-btn-sm"><?php esc_html_e( 'Activate', 'wp-agent' ); ?></button>
                                            </form>
                                        <?php else : ?>
                                            <span class="wp-agent-text-muted"><?php esc_html_e( 'Reviewed', 'wp-agent' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="wp-agent-empty">
                    <h3><?php esc_html_e( 'No quarantined packages', 'wp-agent' ); ?></h3>
                    <p><?php esc_html_e( 'Downloaded GitHub skill packages appear here for review before activation.', 'wp-agent' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Installed Packages', 'wp-agent' ); ?></h2>
            <?php if ( ! empty( $installed_packages ) ) : ?>
                <div class="wp-agent-table-wrap">
                    <table class="wp-agent-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Skill', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Version', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Source', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Activated', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $installed_packages as $package ) : ?>
                                <?php
                                $source         = $package['source'] ?? array();
                                $rollback_count = (int) ( $package['rollback_count'] ?? 0 );
                                ?>
                                <tr>
                                    <td data-label="<?php esc_attr_e( 'Skill', 'wp-agent' ); ?>">
                                        <strong><?php echo esc_html( $package['name'] ?? '' ); ?></strong>
                                        <div><code><?php echo esc_html( $package['slug'] ?? '' ); ?></code></div>
                                    </td>
                                    <td data-label="<?php esc_attr_e( 'Version', 'wp-agent' ); ?>"><?php echo esc_html( $package['version'] ?? '' ); ?></td>
                                    <td data-label="<?php esc_attr_e( 'Source', 'wp-agent' ); ?>">
                                        <code><?php echo esc_html( $source['repository'] ?? '' ); ?></code>
                                        <div class="wp-agent-text-muted"><?php echo esc_html( ( $source['ref'] ?? '' ) . ' · ' . ( $source['path'] ?? '' ) ); ?></div>
                                    </td>
                                    <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Activated', 'wp-agent' ); ?>">
                                        <?php echo ! empty( $package['activated_at'] ) ? esc_html( human_time_diff( strtotime( $package['activated_at'] ) ) . ' ago' ) : esc_html__( 'Unknown', 'wp-agent' ); ?>
                                        <?php if ( $rollback_count > 0 ) : ?>
                                            <div>
                                                <?php
                                                printf(
                                                    esc_html( _n( '%d rollback', '%d rollbacks', $rollback_count, 'wp-agent' ) ),
                                                    $rollback_count
                                                );
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?php esc_attr_e( 'Actions', 'wp-agent' ); ?>">
                                        <div class="wp-agent-runtime-actions" style="gap: 6px; margin: 0;">
                                            <?php if ( 'github' === ( $source['type'] ?? '' ) ) : ?>
                                                <form method="post" class="wp-agent-inline-form">
                                                    <?php wp_nonce_field( 'wp_agent_skills', 'wp_agent_skills_nonce' ); ?>
                                                    <input type="hidden" name="wp_agent_skill_action" value="check_package_update" />
                                                    <input type="hidden" name="package_slug" value="<?php echo esc_attr( $package['slug'] ?? '' ); ?>" />
                                                    <button type="submit" class="wp-agent-btn wp-agent-btn-sm"><?php esc_html_e( 'Check', 'wp-agent' ); ?></button>
                                                </form>
                                                <form method="post" class="wp-agent-inline-form">
                                                    <?php wp_nonce_field( 'wp_agent_skills', 'wp_agent_skills_nonce' ); ?>
                                                    <input type="hidden" name="wp_agent_skill_action" value="refresh_package" />
                                                    <input type="hidden" name="package_slug" value="<?php echo esc_attr( $package['slug'] ?? '' ); ?>" />
                                                    <button type="submit" class="wp-agent-btn wp-agent-btn-sm"><?php esc_html_e( 'Download', 'wp-agent' ); ?></button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ( $rollback_count > 0 ) : ?>
                                                <form method="post" class="wp-agent-inline-form">
                                                    <?php wp_nonce_field( 'wp_agent_skills', 'wp_agent_skills_nonce' ); ?>
                                                    <input type="hidden" name="wp_agent_skill_action" value="rollback_package" />
                                                    <input type="hidden" name="package_slug" value="<?php echo esc_attr( $package['slug'] ?? '' ); ?>" />
                                                    <input type="hidden" name="rollback_id" value="<?php echo esc_attr( $package['latest_rollback'] ?? '' ); ?>" />
                                                    <button type="submit" class="wp-agent-btn wp-agent-btn-sm"><?php esc_html_e( 'Rollback', 'wp-agent' ); ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="wp-agent-empty">
                    <h3><?php esc_html_e( 'No installed packages', 'wp-agent' ); ?></h3>
                    <p><?php esc_html_e( 'Activated GitHub skills appear here with source provenance.', 'wp-agent' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Create Skill', 'wp-agent' ); ?></h2>
            <form method="post" class="wp-agent-form-card">
                <?php wp_nonce_field( 'wp_agent_skills', 'wp_agent_skills_nonce' ); ?>
                <input type="hidden" name="wp_agent_skill_action" value="save" />
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Name', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <input class="wp-agent-input" type="text" name="skill_name" required placeholder="<?php esc_attr_e( 'Daily news draft', 'wp-agent' ); ?>" />
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Slug', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <input class="wp-agent-input" type="text" name="skill_slug" placeholder="<?php esc_attr_e( 'daily-news-draft', 'wp-agent' ); ?>" />
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Description', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <textarea class="wp-agent-input" name="skill_description" rows="2"></textarea>
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Triggers', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <textarea class="wp-agent-input" name="skill_triggers" rows="2" placeholder="<?php esc_attr_e( 'news roundup, research article', 'wp-agent' ); ?>"></textarea>
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"><?php esc_html_e( 'Playbook', 'wp-agent' ); ?></div>
                    <div class="wp-agent-form-field">
                        <textarea class="wp-agent-input" name="skill_body" rows="8" required placeholder="<?php esc_attr_e( '# Steps...', 'wp-agent' ); ?>"></textarea>
                        <p class="wp-agent-form-help"><?php esc_html_e( 'Skills are Markdown instructions only. Executable code patterns are rejected.', 'wp-agent' ); ?></p>
                    </div>
                </div>
                <div class="wp-agent-form-row">
                    <div class="wp-agent-form-label"></div>
                    <div class="wp-agent-form-field">
                        <button type="submit" class="wp-agent-btn wp-agent-btn-primary"><?php esc_html_e( 'Save Skill', 'wp-agent' ); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <div class="wp-agent-section">
            <h2 class="wp-agent-section-title"><?php esc_html_e( 'Saved Skills', 'wp-agent' ); ?></h2>
            <?php if ( ! empty( $skills ) ) : ?>
                <div class="wp-agent-table-wrap">
                    <table class="wp-agent-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Slug', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Triggers', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Version', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Updated', 'wp-agent' ); ?></th>
                                <th><?php esc_html_e( 'Action', 'wp-agent' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $skills as $skill ) : ?>
                                <tr>
                                    <td data-label="<?php esc_attr_e( 'Name', 'wp-agent' ); ?>">
                                        <strong><?php echo esc_html( $skill['name'] ); ?></strong>
                                        <?php if ( ! empty( $skill['description'] ) ) : ?>
                                            <div class="wp-agent-text-muted"><?php echo esc_html( wp_trim_words( $skill['description'], 16 ) ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?php esc_attr_e( 'Slug', 'wp-agent' ); ?>"><code><?php echo esc_html( $skill['slug'] ); ?></code></td>
                                    <td data-label="<?php esc_attr_e( 'Triggers', 'wp-agent' ); ?>"><?php echo esc_html( implode( ', ', array_slice( $skill['triggers'], 0, 4 ) ) ); ?></td>
                                    <td data-label="<?php esc_attr_e( 'Version', 'wp-agent' ); ?>"><?php echo esc_html( (string) $skill['version'] ); ?></td>
                                    <td class="wp-agent-text-muted" data-label="<?php esc_attr_e( 'Updated', 'wp-agent' ); ?>"><?php echo esc_html( human_time_diff( strtotime( $skill['updated_at'] ) ) ); ?> ago</td>
                                    <td data-label="<?php esc_attr_e( 'Action', 'wp-agent' ); ?>">
                                        <form method="post">
                                            <?php wp_nonce_field( 'wp_agent_skills', 'wp_agent_skills_nonce' ); ?>
                                            <input type="hidden" name="wp_agent_skill_action" value="archive" />
                                            <input type="hidden" name="skill_slug" value="<?php echo esc_attr( $skill['slug'] ); ?>" />
                                            <button type="submit" class="wp-agent-btn wp-agent-btn-sm"><?php esc_html_e( 'Archive', 'wp-agent' ); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="wp-agent-empty">
                    <h3><?php esc_html_e( 'No skills yet', 'wp-agent' ); ?></h3>
                    <p><?php esc_html_e( 'Create a reusable playbook here or ask the agent to save one with manage_skills.', 'wp-agent' ); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.wp-agent-skill-search-results { margin-top: 10px; display: grid; gap: 10px; }
.wp-agent-skill-card {
    border: 1px solid var(--wpa-hairline, #e2e2e2);
    border-radius: 10px;
    padding: 12px 14px;
    background: var(--wpa-card, #fff);
    display: flex;
    gap: 14px;
    align-items: flex-start;
    justify-content: space-between;
}
.wp-agent-skill-card__main { min-width: 0; }
.wp-agent-skill-card__name { font-weight: 600; color: var(--wpa-ink, #1f1f1f); }
.wp-agent-skill-card__slug { color: var(--wpa-muted, #6a6a6a); font-size: 12px; }
.wp-agent-skill-card__slug code { font-size: 12px; }
.wp-agent-skill-card__desc { color: var(--wpa-body, #3a3a3a); font-size: 13px; margin: 4px 0 6px; }
.wp-agent-skill-card__tools { display: flex; flex-wrap: wrap; gap: 5px; }
.wp-agent-skill-tool {
    font-size: 11px; color: var(--wpa-muted, #6a6a6a);
    background: var(--wpa-surface-soft, #f4f4f5);
    border-radius: 5px; padding: 1px 7px;
}
.wp-agent-skill-card__action { flex-shrink: 0; }
</style>

<script>
(function () {
    function initSkillSearch() {
    var cfg = window.wpAgentChat || {};
    var btn = document.getElementById('wpa-skill-search-btn');
    var queryEl = document.getElementById('wpa-skill-search-query');
    var repoEl = document.getElementById('wpa-skill-search-repo');
    var refEl = document.getElementById('wpa-skill-search-ref');
    var statusEl = document.getElementById('wpa-skill-search-status');
    var resultsEl = document.getElementById('wpa-skill-search-results');
    if (!btn || !queryEl || !resultsEl || !cfg.restUrl) { return; }

    function setStatus(msg) { statusEl.textContent = msg || ''; }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function fillInstallForm(repo, ref, path) {
        var r = document.getElementById('wpa-github-repository');
        var f = document.getElementById('wpa-github-ref');
        var p = document.getElementById('wpa-github-skill-path');
        if (r) r.value = repo || '';
        if (f && ref) f.value = ref;
        if (p) p.value = path || '';
        var form = document.getElementById('wpa-github-install-form');
        if (form && form.scrollIntoView) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function renderResults(data) {
        resultsEl.innerHTML = '';
        var skills = (data && data.skills) || [];
        if (!skills.length) {
            setStatus('No skills found in ' + (data.repository || 'that repository') + '.');
            return;
        }
        setStatus('Found ' + data.count + ' skill' + (data.count === 1 ? '' : 's') + ' in ' + data.repository + ' (' + data.ref + ').');
        skills.forEach(function (s) {
            var card = document.createElement('div');
            card.className = 'wp-agent-skill-card';

            var main = document.createElement('div');
            main.className = 'wp-agent-skill-card__main';
            var tools = (s.tools || []).slice(0, 8).map(function (t) {
                return '<span class="wp-agent-skill-tool">' + esc(t) + '</span>';
            }).join('');
            main.innerHTML =
                '<div class="wp-agent-skill-card__name">' + esc(s.name || s.slug) + '</div>' +
                '<div class="wp-agent-skill-card__slug"><code>' + esc(s.slug) + '</code> · <code>' + esc(s.skill_path) + '</code></div>' +
                (s.description ? '<div class="wp-agent-skill-card__desc">' + esc(s.description) + '</div>' : '') +
                (tools ? '<div class="wp-agent-skill-card__tools">' + tools + '</div>' : '');

            var actionWrap = document.createElement('div');
            actionWrap.className = 'wp-agent-skill-card__action';
            var install = document.createElement('button');
            install.type = 'button';
            install.className = 'wp-agent-btn';
            install.textContent = 'Use for install';
            install.addEventListener('click', function () {
                fillInstallForm(data.repository, data.ref, s.skill_path);
            });
            actionWrap.appendChild(install);

            card.appendChild(main);
            card.appendChild(actionWrap);
            resultsEl.appendChild(card);
        });
    }

    function runSearch() {
        var q = queryEl.value.trim();
        var repo = repoEl ? repoEl.value.trim() : '';
        var ref = refEl ? refEl.value.trim() : '';
        setStatus('Searching…');
        resultsEl.innerHTML = '';
        // Support both pretty permalinks (".../wp-agent/v1/") and the plain
        // "?rest_route=/wp-agent/v1/" form: append the route, then add params
        // with the correct separator.
        var base = cfg.restUrl + 'skills/search-github';
        var sep = base.indexOf('?') === -1 ? '?' : '&';
        var url = base + sep + 'query=' + encodeURIComponent(q) +
            '&repository=' + encodeURIComponent(repo) + '&ref=' + encodeURIComponent(ref);
        fetch(url, {
            headers: { 'X-WP-Nonce': cfg.nonce || '' },
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().then(function (body) { return { ok: res.ok, body: body }; });
        }).then(function (r) {
            if (!r.ok || (r.body && r.body.error)) {
                setStatus('Search failed: ' + ((r.body && r.body.error) || 'request error') + '.');
                return;
            }
            renderResults(r.body);
        }).catch(function () {
            setStatus('Search failed: network error.');
        });
    }

    btn.addEventListener('click', runSearch);
    queryEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
    });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSkillSearch);
    } else {
        initSkillSearch();
    }
})();
</script>
