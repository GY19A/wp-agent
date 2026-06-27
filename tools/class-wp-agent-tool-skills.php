<?php
/**
 * Skills tool — manage reusable non-executable Markdown playbooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Skills extends WPAgent_Tool {

    public function get_name() {
        return 'manage_skills';
    }

    public function get_description() {
        return 'Draft reusable Markdown skills from completed runs, create, update, list, search, read, archive, pin, install built-in workflow templates, or install reusable Markdown skills. Use search_github to discover installable skills in a GitHub repository before installing. GitHub installs are quarantined for administrator review before activation.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'draft_from_run', 'save', 'list', 'search', 'search_github', 'get', 'archive', 'list_templates', 'get_template', 'install_template', 'install_github', 'list_quarantine', 'get_quarantine', 'activate_quarantine', 'list_installed_packages', 'check_package_update', 'refresh_package', 'pin_package', 'unpin_package', 'list_package_rollbacks', 'rollback_package' ),
                    'description' => 'Operation to perform.',
                ),
                'run_id' => array(
                    'type'        => 'integer',
                    'description' => 'Completed or in-progress WP Agent run ID to summarize into a local Skill draft. Required for draft_from_run.',
                ),
                'slug' => array(
                    'type'        => 'string',
                    'description' => 'Stable skill slug. Required for get/archive; optional for save.',
                ),
                'name' => array(
                    'type'        => 'string',
                    'description' => 'Human-readable skill name. Required for save.',
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'Short summary of when to use the skill.',
                ),
                'triggers' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => 'Search trigger phrases.',
                ),
                'body' => array(
                    'type'        => 'string',
                    'description' => 'Markdown playbook body. Instructions only; executable code is rejected.',
                ),
                'query' => array(
                    'type'        => 'string',
                    'description' => 'Search text.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum results to return.',
                ),
                'repository' => array(
                    'type'        => 'string',
                    'description' => 'GitHub repository as owner/repo or https://github.com/owner/repo. Optional for install_github when a default Skills Store repository is configured.',
                ),
                'ref' => array(
                    'type'        => 'string',
                    'description' => 'Git ref, branch, tag, or commit for install_github. Uses the configured default ref, then main, when omitted.',
                ),
                'skill_path' => array(
                    'type'        => 'string',
                    'description' => 'Path to a skill directory or SKILL.md in the GitHub repository, such as skills/news-rewrite-publisher. Optional when a default Skill path is configured.',
                ),
                'quarantine_id' => array(
                    'type'        => 'string',
                    'description' => 'Quarantine package id for get_quarantine or activate_quarantine.',
                ),
                'template_slug' => array(
                    'type'        => 'string',
                    'description' => 'Built-in workflow template slug for get_template or install_template. Available: news-site-operator, image-to-article, title-to-article, research-article, paper-to-article, expand-categories, skill-creator.',
                ),
                'rollback_id' => array(
                    'type'        => 'string',
                    'description' => 'Optional rollback snapshot id for rollback_package. Latest is used when omitted.',
                ),
                'github_token' => array(
                    'type'        => 'string',
                    'description' => 'Optional GitHub token for private repositories. It is used only for the request and is never returned.',
                ),
                'force' => array(
                    'type'        => 'boolean',
                    'description' => 'Explicitly bypass a pinned package guard for activation or rollback.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_posts';
    }

    public function execute( array $params ) {
        $action = $params['action'] ?? '';
        $owner  = $this->owner_id();
        $limit  = max( 1, min( (int) ( $params['limit'] ?? 20 ), 50 ) );

        switch ( $action ) {
            case 'draft_from_run':
                $draft = WPAgent_Skills::draft_from_run( $owner, (int) ( $params['run_id'] ?? 0 ), $params );
                return is_wp_error( $draft ) ? array( 'error' => $draft->get_error_message() ) : $draft;

            case 'save':
                $skill = WPAgent_Skills::save( $owner, $params );
                if ( is_wp_error( $skill ) ) {
                    return array( 'error' => $skill->get_error_message() );
                }
                WPAgent_Journal::add(
                    $owner,
                    'decision',
                    'Saved skill: ' . $skill['name'],
                    'Skill `' . $skill['slug'] . '` version ' . $skill['version'] . ' was saved.',
                    array( 'skill_id' => $skill['id'], 'slug' => $skill['slug'] ),
                    $this->conversation_id,
                    $this->run_id
                );
                return array( 'success' => true, 'skill' => $skill );

            case 'list':
                return array( 'success' => true, 'skills' => WPAgent_Skills::all( $owner, $limit ) );

            case 'search':
                return array( 'success' => true, 'skills' => WPAgent_Skills::search( $owner, $params['query'] ?? '', $limit ) );

            case 'search_github':
                $result = WPAgent_Skills::search_github( array(
                    'query'        => (string) ( $params['query'] ?? '' ),
                    'repository'   => (string) ( $params['repository'] ?? '' ),
                    'ref'          => (string) ( $params['ref'] ?? '' ),
                    'skill_path'   => (string) ( $params['skill_path'] ?? '' ),
                    'github_token' => (string) ( $params['github_token'] ?? '' ),
                    'limit'        => $limit,
                ) );
                return is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array_merge( array( 'success' => true ), $result );

            case 'get':
                $skill = WPAgent_Skills::get_by_slug( $owner, $params['slug'] ?? '' );
                return $skill ? array( 'success' => true, 'skill' => $skill ) : array( 'error' => 'Skill not found.' );

            case 'archive':
                $ok = WPAgent_Skills::archive( $owner, $params['slug'] ?? '' );
                return $ok ? array( 'success' => true, 'message' => 'Skill archived.' ) : array( 'error' => 'Skill not found.' );

            case 'list_templates':
                return array( 'success' => true, 'templates' => WPAgent_Skills::built_in_templates() );

            case 'get_template':
                $template = WPAgent_Skills::template( $params['template_slug'] ?? ( $params['slug'] ?? '' ) );
                return $template ? array( 'success' => true, 'template' => $template ) : array( 'error' => 'Built-in Skill template not found.' );

            case 'install_template':
                $result = WPAgent_Skills::install_template( $owner, $params['template_slug'] ?? ( $params['slug'] ?? '' ) );
                if ( is_wp_error( $result ) ) {
                    return array( 'error' => $result->get_error_message() );
                }
                WPAgent_Journal::add(
                    $owner,
                    'decision',
                    'Installed built-in skill template: ' . ( $result['template']['name'] ?? 'Unknown template' ),
                    'Built-in template `' . ( $result['template']['slug'] ?? '' ) . '` was saved as Skill `' . ( $result['skill']['slug'] ?? '' ) . '`.',
                    array(
                        'template_slug' => $result['template']['slug'] ?? '',
                        'skill_id'      => $result['skill']['id'] ?? 0,
                        'skill_slug'    => $result['skill']['slug'] ?? '',
                    ),
                    $this->conversation_id,
                    $this->run_id
                );
                return $result;

            case 'install_github':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                $result = WPAgent_Skills::install_from_github( $owner, $params );
                if ( is_wp_error( $result ) ) {
                    return array( 'error' => $result->get_error_message() );
                }
                WPAgent_Journal::add(
                    $owner,
                    'decision',
                    'Quarantined GitHub skill: ' . ( $result['summary']['name'] ?? 'Unknown skill' ),
                    'Skill package `' . ( $result['summary']['slug'] ?? '' ) . '` was downloaded to quarantine for review.',
                    array(
                        'quarantine_id' => $result['quarantine_id'] ?? '',
                        'source'        => $result['summary']['source'] ?? array(),
                    ),
                    $this->conversation_id,
                    $this->run_id
                );
                return $result;

            case 'list_quarantine':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                return array( 'success' => true, 'packages' => WPAgent_Skills::quarantine_list( $limit ) );

            case 'get_quarantine':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                $package = WPAgent_Skills::get_quarantined( $params['quarantine_id'] ?? '' );
                return is_wp_error( $package ) ? array( 'error' => $package->get_error_message() ) : array( 'success' => true, 'package' => $package );

            case 'activate_quarantine':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                $activated = WPAgent_Skills::activate_quarantined( $owner, $params['quarantine_id'] ?? '', ! empty( $params['force'] ) );
                if ( is_wp_error( $activated ) ) {
                    return array( 'error' => $activated->get_error_message() );
                }
                WPAgent_Journal::add(
                    $owner,
                    'decision',
                    'Activated skill package: ' . ( $activated['skill']['name'] ?? 'Unknown skill' ),
                    'Skill package `' . ( $activated['skill']['slug'] ?? '' ) . '` was reviewed and activated.',
                    array(
                        'skill_id' => $activated['skill']['id'] ?? 0,
                        'lock'     => $activated['lock'] ?? array(),
                    ),
                    $this->conversation_id,
                    $this->run_id
                );
                return $activated;

            case 'list_installed_packages':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                return array( 'success' => true, 'packages' => WPAgent_Skills::installed_packages( $limit ) );

            case 'check_package_update':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                $result = WPAgent_Skills::check_package_update( $params['slug'] ?? '' );
                return is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result;

            case 'refresh_package':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                $result = WPAgent_Skills::refresh_package_from_source( $owner, $params['slug'] ?? '' );
                if ( is_wp_error( $result ) ) {
                    return array( 'error' => $result->get_error_message() );
                }
                WPAgent_Journal::add(
                    $owner,
                    'decision',
                    'Downloaded skill package update to quarantine',
                    'Installed package `' . ( $params['slug'] ?? '' ) . '` was refreshed from source into quarantine for human review.',
                    array(
                        'quarantine_id' => $result['quarantine_id'] ?? '',
                        'source'        => $result['summary']['source'] ?? array(),
                    ),
                    $this->conversation_id,
                    $this->run_id
                );
                return $result;

            case 'pin_package':
            case 'unpin_package':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                $result = WPAgent_Skills::pin_package( $owner, $params['slug'] ?? '', 'pin_package' === $action );
                if ( is_wp_error( $result ) ) {
                    return array( 'error' => $result->get_error_message() );
                }
                WPAgent_Journal::add(
                    $owner,
                    'decision',
                    ( ! empty( $result['pinned'] ) ? 'Pinned' : 'Unpinned' ) . ' skill package: ' . ( $result['slug'] ?? '' ),
                    'Skill package `' . ( $result['slug'] ?? '' ) . '` pin state is now ' . ( ! empty( $result['pinned'] ) ? 'pinned.' : 'unpinned.' ),
                    array(
                        'slug'   => $result['slug'] ?? '',
                        'pinned' => ! empty( $result['pinned'] ),
                        'lock'   => $result['lock'] ?? array(),
                    ),
                    $this->conversation_id,
                    $this->run_id
                );
                return $result;

            case 'list_package_rollbacks':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                return array( 'success' => true, 'rollbacks' => WPAgent_Skills::package_rollbacks( $params['slug'] ?? '', $limit ) );

            case 'rollback_package':
                $allowed = $this->require_admin();
                if ( is_wp_error( $allowed ) ) {
                    return array( 'error' => $allowed->get_error_message() );
                }
                $result = WPAgent_Skills::rollback_package( $owner, $params['slug'] ?? '', $params['rollback_id'] ?? '', ! empty( $params['force'] ) );
                if ( is_wp_error( $result ) ) {
                    return array( 'error' => $result->get_error_message() );
                }
                WPAgent_Journal::add(
                    $owner,
                    'decision',
                    'Rolled back skill package: ' . ( $result['skill']['name'] ?? 'Unknown skill' ),
                    'Skill package `' . ( $result['skill']['slug'] ?? '' ) . '` was restored from rollback snapshot `' . ( $result['rollback_id'] ?? '' ) . '`.',
                    array(
                        'skill_id'    => $result['skill']['id'] ?? 0,
                        'rollback_id' => $result['rollback_id'] ?? '',
                        'lock'        => $result['lock'] ?? array(),
                    ),
                    $this->conversation_id,
                    $this->run_id
                );
                return $result;

            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    private function require_admin() {
        $owner = $this->owner_id();
        $user  = get_user_by( 'id', $owner );
        if ( $user && class_exists( 'WPAgent_Roles' ) && WPAgent_Roles::USER_LOGIN === $user->user_login ) {
            return new WP_Error( 'wp_agent_skill_human_admin_required', 'Third-party skill packages must be requested by a human administrator, not the bounded agent user.' );
        }
        if ( ! user_can( $owner, 'manage_options' ) ) {
            return new WP_Error( 'wp_agent_skill_admin_required', 'Installing or activating third-party skill packages requires administrator permission.' );
        }
        return true;
    }
}
