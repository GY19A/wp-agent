<?php
/**
 * Non-executable reusable agent skills.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Skills {

    const MAX_BODY_BYTES = 20000;
    const MAX_PACKAGE_FILES = 120;
    const MAX_PACKAGE_FILE_BYTES = 524288;
    const MAX_PACKAGE_TOTAL_BYTES = 2097152;

    /**
     * Configured GitHub Skill Store defaults.
     *
     * @return array
     */
    public static function github_store_defaults() {
        $repository = class_exists( 'WPAgent' ) ? WPAgent::get_option( 'github_default_repository', '' ) : '';
        $skill_path = class_exists( 'WPAgent' ) ? WPAgent::get_option( 'github_default_skill_path', '' ) : '';
        $ref        = class_exists( 'WPAgent' ) ? WPAgent::get_option( 'github_default_ref', '' ) : '';
        $policy     = class_exists( 'WPAgent' ) ? WPAgent::get_option( 'github_activation_policy', 'quarantine' ) : 'quarantine';

        $repository = self::normalize_github_repository_value( $repository );
        $skill_path = self::normalize_skill_package_path( $skill_path );
        $ref        = self::sanitize_git_ref_value( $ref );
        $policy     = self::sanitize_github_activation_policy( $policy );

        return array(
            'repository'        => $repository,
            'skill_path'        => $skill_path,
            'ref'               => '' !== $ref ? $ref : 'main',
            'activation_policy' => $policy,
            'configured'        => '' !== $repository && '' !== $skill_path,
        );
    }

    /**
     * Readiness summary for configured GitHub Skill Store workflows.
     *
     * This is intentionally read-only and does not call GitHub. It answers
     * whether the local configuration is sufficient to run the live store
     * acceptance harness or default install flow without passing explicit
     * repository/path arguments.
     *
     * @return array
     */
    public static function github_store_readiness() {
        $defaults = self::github_store_defaults();
        $missing  = array();
        $warnings = array();
        $placeholder_reason = self::github_store_placeholder_reason(
            $defaults['repository'] ?? '',
            $defaults['skill_path'] ?? ''
        );

        if ( '' === (string) ( $defaults['repository'] ?? '' ) ) {
            $missing[] = 'repository';
        }
        if ( '' === (string) ( $defaults['skill_path'] ?? '' ) ) {
            $missing[] = 'skill_path';
        }
        if ( '' === (string) ( $defaults['ref'] ?? '' ) ) {
            $missing[] = 'ref';
        }
        if ( '' !== $placeholder_reason ) {
            $missing[]  = 'official_coordinates';
            $warnings[] = 'github_store_placeholder';
        }

        $token_state      = 'not_configured';
        $token_configured = false;
        $token_usable     = false;
        if ( class_exists( 'WPAgent' ) ) {
            $stored_token     = (string) WPAgent::get_option( 'github_token', '' );
            $token_configured = '' !== $stored_token;
            if ( $token_configured ) {
                $token_usable = '' !== WPAgent::decrypt( $stored_token );
                $token_state  = $token_usable ? 'encrypted' : 'unreadable';
                if ( ! $token_usable ) {
                    $warnings[] = 'github_token_unreadable';
                }
            }
        }

        $ready = empty( $missing ) && ( ! $token_configured || $token_usable );
        if ( ! empty( $missing ) ) {
            $next_action = 'configure_defaults';
        } elseif ( $token_configured && ! $token_usable ) {
            $next_action = 'resave_github_token';
        } elseif ( $token_configured ) {
            $next_action = 'ready_private_or_public';
        } else {
            $next_action = 'ready_public';
        }

        return array(
            'ready'                 => $ready,
            'configured'            => ! empty( $defaults['configured'] ),
            'live_acceptance_ready' => $ready,
            'repository'            => (string) ( $defaults['repository'] ?? '' ),
            'ref'                   => (string) ( $defaults['ref'] ?? 'main' ),
            'skill_path'            => (string) ( $defaults['skill_path'] ?? '' ),
            'placeholder_reason'     => $placeholder_reason,
            'activation_policy'     => (string) ( $defaults['activation_policy'] ?? 'quarantine' ),
            'missing'               => $missing,
            'warnings'              => $warnings,
            'token_configured'      => $token_configured,
            'token_usable'          => $token_usable,
            'token_state'           => $token_state,
            'next_action'           => $next_action,
        );
    }

    /**
     * Detect documented placeholder GitHub Skill Store coordinates.
     *
     * This is intentionally a local/read-only live-acceptance guard. Normal
     * deterministic tests may still install from fake GitHub URLs through the
     * HTTP preemption layer; final live acceptance must not.
     *
     * @param string $repository GitHub repository, usually owner/repo.
     * @param string $skill_path Repository-relative Skill path.
     * @return string Empty when coordinates are usable, otherwise a reason.
     */
    public static function github_store_placeholder_reason( $repository, $skill_path ) {
        $repo = strtolower( trim( (string) $repository ) );
        $path = strtolower( trim( trim( (string) $skill_path ), '/' ) );

        if ( '' !== $repo ) {
            $placeholder_repositories = array(
                'owner/repo',
                'example/skills',
                'example/default-skills',
                'example/wp-agent-skills',
                'your-org/your-repo',
                'your-user/your-repo',
            );
            if ( in_array( $repo, $placeholder_repositories, true ) ) {
                return 'repository uses a documented placeholder value';
            }

            $parts = explode( '/', $repo );
            if ( 2 === count( $parts ) ) {
                $placeholder_owners = array( 'owner', 'org', 'user', 'example', 'sample', 'your-org', 'your-user', 'your-owner' );
                $placeholder_names  = array( 'repo', 'repository', 'example', 'sample', 'default-skills', 'your-repo' );
                if ( in_array( $parts[0], $placeholder_owners, true ) ) {
                    return 'repository owner is a placeholder';
                }
                if ( in_array( $parts[1], $placeholder_names, true ) ) {
                    return 'repository name is a placeholder';
                }
            }
        }

        if ( '' !== $path ) {
            $placeholder_paths = array(
                'example',
                'skills/example',
                'path/to/skill',
                'skill/path',
                'skills/path-to-skill',
                'your-skill',
                'skills/your-skill',
            );
            if ( in_array( $path, $placeholder_paths, true ) ) {
                return 'skill path uses a documented placeholder value';
            }

            $segments = preg_split( '/\/+/', $path, -1, PREG_SPLIT_NO_EMPTY );
            if ( array_intersect( $segments, array( 'example', 'placeholder', 'sample', 'your-skill' ) ) ) {
                return 'skill path contains a placeholder segment';
            }
        }

        return '';
    }

    /**
     * Normalize a GitHub repository value to owner/repo.
     *
     * @param string $value Raw repository input.
     * @return string
     */
    public static function normalize_github_repository_value( $value ) {
        $repo = self::parse_github_repository( $value );
        if ( is_wp_error( $repo ) || empty( $repo['owner'] ) || empty( $repo['repo'] ) ) {
            return '';
        }
        return $repo['owner'] . '/' . $repo['repo'];
    }

    /**
     * Normalize a GitHub Skill package path.
     *
     * @param string $value Raw path input.
     * @return string
     */
    public static function normalize_skill_package_path( $value ) {
        return self::normalize_package_path( $value );
    }

    /**
     * Normalize a Git ref for settings and CLI defaults.
     *
     * @param string $value Raw ref input.
     * @return string
     */
    public static function sanitize_git_ref_value( $value ) {
        return self::sanitize_git_ref( $value );
    }

    /**
     * Valid activation policies for a configured GitHub Skill Store.
     *
     * @return string[]
     */
    public static function github_activation_policies() {
        return array( 'quarantine', 'activate', 'activate_pin' );
    }

    /**
     * Normalize activation policy.
     *
     * @param string $value Raw policy.
     * @return string
     */
    public static function sanitize_github_activation_policy( $value ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, self::github_activation_policies(), true ) ? $value : 'quarantine';
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wp_agent_skills';
    }

    /**
     * Create or update a skill by owner/slug.
     *
     * @param int   $user_id Owner user.
     * @param array $data    Skill fields.
     * @return array|WP_Error
     */
    public static function save( $user_id, $data ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $name    = sanitize_text_field( $data['name'] ?? '' );
        $slug    = sanitize_title( $data['slug'] ?? $name );
        $body    = (string) ( $data['body'] ?? '' );

        if ( $user_id <= 0 || '' === $name || '' === $slug || '' === trim( $body ) ) {
            return new WP_Error( 'wp_agent_skill_invalid', 'Skill name, slug, and body are required.' );
        }

        if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
            return new WP_Error( 'wp_agent_skill_size', 'Skill body exceeds the size limit.' );
        }

        $blocked = self::validate_body( $body );
        if ( is_wp_error( $blocked ) ) {
            return $blocked;
        }

        $description     = sanitize_textarea_field( $data['description'] ?? '' );
        $triggers        = self::normalize_triggers( $data['triggers'] ?? array() );
        $visibility      = 'site' === ( $data['visibility'] ?? 'private' ) ? 'site' : 'private';
        $persist_runtime = array_key_exists( 'persist_runtime', $data ) ? (bool) $data['persist_runtime'] : true;
        $runtime_source  = is_array( $data['runtime_source'] ?? null ) ? $data['runtime_source'] : array( 'type' => 'local' );
        $permissions     = array_key_exists( 'permissions', $data )
            ? self::sanitize_permissions( $data['permissions'] )
            : self::permissions_for_skill( $user_id, $slug );
        $now             = current_time( 'mysql', true );

        if ( $persist_runtime ) {
            $conflict = self::installed_package_slug_conflict( $user_id, $slug );
            if ( is_wp_error( $conflict ) ) {
                return $conflict;
            }
        }

        $existing = self::get_by_slug( $user_id, $slug );
        if ( $existing ) {
            $version = (int) $existing['version'] + 1;
            $wpdb->update(
                self::table(),
                array(
                    'name'        => $name,
                    'description' => $description,
                    'triggers'    => wp_json_encode( $triggers ),
                    'permissions' => wp_json_encode( $permissions ),
                    'body'        => sanitize_textarea_field( $body ),
                    'visibility'  => $visibility,
                    'status'      => 'active',
                    'version'     => $version,
                    'updated_at'  => $now,
                ),
                array( 'id' => (int) $existing['id'] ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );
            $skill = self::get( (int) $existing['id'], false );
            $skill['permissions'] = $permissions;
            if ( $persist_runtime ) {
                $persisted = self::persist_local_skill( $skill, $runtime_source );
                if ( is_wp_error( $persisted ) ) {
                    return $persisted;
                }
            }
            return $skill;
        }

        $wpdb->insert(
            self::table(),
            array(
                'user_id'     => $user_id,
                'slug'        => $slug,
                'name'        => $name,
                'description' => $description,
                'triggers'    => wp_json_encode( $triggers ),
                'permissions' => wp_json_encode( $permissions ),
                'body'        => sanitize_textarea_field( $body ),
                'visibility'  => $visibility,
                'status'      => 'active',
                'version'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        $skill = self::get( (int) $wpdb->insert_id, false );
        $skill['permissions'] = $permissions;
        if ( $persist_runtime ) {
            $persisted = self::persist_local_skill( $skill, $runtime_source );
            if ( is_wp_error( $persisted ) ) {
                return $persisted;
            }
        }

        return $skill;
    }

    /**
     * Get a skill by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function get( $id, $apply_runtime = true ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            (int) $id
        ), ARRAY_A );

        return $row ? self::hydrate( $row, $apply_runtime ) : null;
    }

    /**
     * Get a skill by owner/slug.
     *
     * @param int    $user_id
     * @param string $slug
     * @return array|null
     */
    public static function get_by_slug( $user_id, $slug, $apply_runtime = true ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $slug    = sanitize_title( $slug );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d AND slug = %s",
            $user_id,
            $slug
        ), ARRAY_A );

        if ( ! $row && $apply_runtime ) {
            self::recover_runtime_skill_by_slug( $user_id, $slug );
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE user_id = %d AND slug = %s",
                $user_id,
                $slug
            ), ARRAY_A );
        }

        return $row ? self::hydrate( $row, $apply_runtime ) : null;
    }

    /**
     * Return a Skill's declared tool/network/code permissions.
     *
     * Permissions are stored with runtime SKILL.md/lock metadata, not in the
     * fast DB index, so this method reads the active private runtime source.
     *
     * @param int    $user_id Skill owner.
     * @param string $slug    Skill slug.
     * @return array
     */
    public static function permissions_for_skill( $user_id, $slug ) {
        $user_id = (int) $user_id;
        $slug    = sanitize_title( $slug );
        if ( $user_id <= 0 || '' === $slug ) {
            return array();
        }

        $package_lock = self::active_installed_package_lock( $slug );
        if ( $package_lock ) {
            $activated_by = (int) ( $package_lock['activated_by'] ?? 0 );
            $installed_by = (int) ( $package_lock['installed_by'] ?? 0 );
            if ( ( $activated_by <= 0 || $activated_by === $user_id ) && ( $installed_by <= 0 || $installed_by === $user_id ) ) {
                return self::sanitize_permissions( $package_lock['permissions'] ?? array() );
            }
        }

        $manifest = self::local_runtime_manifest( $user_id, $slug );
        if ( is_wp_error( $manifest ) ) {
            return self::permissions_from_db( $user_id, $slug );
        }

        $lock        = is_array( $manifest['lock'] ?? null ) ? $manifest['lock'] : array();
        $parsed      = self::parse_skill_markdown( (string) ( $manifest['skill_md'] ?? '' ) );
        $metadata    = is_array( $parsed['metadata'] ?? null ) ? $parsed['metadata'] : array();
        $permissions = self::sanitize_permissions( $metadata['permissions'] ?? ( $lock['permissions'] ?? array() ) );

        if ( empty( $permissions ) && ! empty( $lock['source']['template_slug'] ) ) {
            $template = self::template( $lock['source']['template_slug'] );
            if ( $template ) {
                $permissions = self::sanitize_permissions( $template['permissions'] ?? array() );
            }
        }

        if ( empty( $permissions ) && ! empty( $lock['source']['tools'] ) ) {
            $permissions = self::sanitize_permissions( array(
                'tools' => $lock['source']['tools'],
            ) );
        }

        if ( empty( $permissions ) ) {
            $permissions = self::permissions_from_db( $user_id, $slug );
        }

        return $permissions;
    }

    /**
     * Convert declared Skill permissions into the normalized runtime policy.
     *
     * This is shared by the Agent execution gate and diagnostics so the
     * operator-visible policy matches the policy actually enforced at runtime.
     *
     * @param array $permissions Raw or sanitized permissions.
     * @return array
     */
    public static function policy_from_permissions( $permissions ) {
        $permissions   = self::sanitize_permissions( $permissions );
        $allowed_tools = self::normalize_tool_permission_specs( $permissions['tools'] ?? array() );
        $restricted    = ! empty( $allowed_tools )
            || array_key_exists( 'network', $permissions )
            || array_key_exists( 'code_execution', $permissions );

        return array(
            'restricted'     => $restricted,
            'allowed_tools'  => $allowed_tools,
            'network'        => array_key_exists( 'network', $permissions ) ? (bool) $permissions['network'] : null,
            'code_execution' => array_key_exists( 'code_execution', $permissions ) ? (bool) $permissions['code_execution'] : null,
        );
    }

    /**
     * Normalize declared Skill tool permission strings.
     *
     * @param array|string $specs Permission strings like manage_posts.create.
     * @return string[]
     */
    public static function normalize_tool_permission_specs( $specs ) {
        if ( is_string( $specs ) ) {
            $specs = preg_split( '/[,\\s]+/', $specs );
        }
        if ( ! is_array( $specs ) ) {
            return array();
        }

        $out = array();
        foreach ( $specs as $spec ) {
            $spec = strtolower( trim( (string) $spec ) );
            $spec = preg_replace( '/[^a-z0-9_.-]+/', '', $spec );
            if ( '' !== $spec ) {
                $out[] = $spec;
            }
        }

        return array_values( array_unique( $out ) );
    }

    /**
     * Resolve a permission spec to the registered tool name.
     *
     * @param string $spec Permission string.
     * @return string
     */
    public static function tool_name_from_permission_spec( $spec ) {
        $spec  = strtolower( trim( (string) $spec ) );
        $parts = explode( '.', $spec, 2 );
        $tool  = $parts[0] ?? $spec;
        $tool  = str_replace( '-', '_', $tool );

        $aliases = array(
            'post'       => 'manage_posts',
            'posts'      => 'manage_posts',
            'page'       => 'manage_pages',
            'pages'      => 'manage_pages',
            'media'      => 'manage_media',
            'comment'    => 'manage_comments',
            'comments'   => 'manage_comments',
            'taxonomy'   => 'manage_taxonomies',
            'taxonomies' => 'manage_taxonomies',
            'menu'       => 'manage_menus',
            'menus'      => 'manage_menus',
            'seo'        => 'manage_seo',
            'skill'      => 'manage_skills',
            'skills'     => 'manage_skills',
            'schedule'   => 'manage_schedules',
            'schedules'  => 'manage_schedules',
            'file'       => 'manage_files',
            'files'      => 'manage_files',
            'user'       => 'manage_users',
            'users'      => 'manage_users',
            'settings'   => 'manage_wp_agent_settings',
            'setting'    => 'manage_wp_agent_settings',
            'image'      => 'generate_image',
            'images'     => 'generate_image',
        );

        return sanitize_key( $aliases[ $tool ] ?? $tool );
    }

    /**
     * Resolve the action suffix from a permission spec.
     *
     * @param string $spec Permission string.
     * @return string
     */
    public static function action_from_permission_spec( $spec ) {
        $spec = strtolower( trim( (string) $spec ) );
        if ( false === strpos( $spec, '.' ) ) {
            return '';
        }

        $parts = explode( '.', $spec, 2 );
        return sanitize_key( str_replace( '-', '_', $parts[1] ?? '' ) );
    }

    /**
     * Read the DB-indexed permissions for a Skill without touching runtime files.
     *
     * @param int    $user_id Skill owner.
     * @param string $slug    Skill slug.
     * @return array
     */
    private static function permissions_from_db( $user_id, $slug ) {
        global $wpdb;

        $json = $wpdb->get_var( $wpdb->prepare(
            "SELECT permissions FROM " . self::table() . " WHERE user_id = %d AND slug = %s LIMIT 1",
            (int) $user_id,
            sanitize_title( $slug )
        ) );
        if ( ! is_string( $json ) || '' === trim( $json ) ) {
            return array();
        }

        $decoded = json_decode( $json, true );
        return self::sanitize_permissions( is_array( $decoded ) ? $decoded : array() );
    }

    /**
     * List active skills for an owner.
     *
     * @param int $user_id
     * @param int $limit
     * @return array
     */
    public static function all( $user_id, $limit = 50 ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $limit = max( 1, min( (int) $limit, 100 ) );
        self::discover_runtime_catalog_index( $user_id );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE user_id = %d AND status = 'active'
             ORDER BY updated_at DESC
             LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A );

        return array_map( array( __CLASS__, 'hydrate' ), $rows );
    }

    /**
     * Search skills by text and trigger terms.
     *
     * @param int    $user_id
     * @param string $query
     * @param int    $limit
     * @return array
     */
    public static function search( $user_id, $query, $limit = 10 ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $query = trim( (string) $query );
        $limit = max( 1, min( (int) $limit, 20 ) );

        if ( '' === $query ) {
            return self::all( $user_id, $limit );
        }

        self::discover_runtime_catalog_index( $user_id );

        $like = '%' . $wpdb->esc_like( $query ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE user_id = %d
               AND status = 'active'
               AND (name LIKE %s OR description LIKE %s OR triggers LIKE %s OR body LIKE %s)
             ORDER BY updated_at DESC
             LIMIT %d",
            $user_id,
            $like,
            $like,
            $like,
            $like,
            $limit
        ), ARRAY_A );

        return array_map( array( __CLASS__, 'hydrate' ), $rows );
    }

    /**
     * Built-in non-executable Skill templates shipped with the plugin.
     *
     * @param bool $include_body Whether to include full Markdown playbooks.
     * @return array
     */
    public static function built_in_templates( $include_body = false ) {
        $templates = array();
        foreach ( self::built_in_template_slugs() as $slug ) {
            $tpl = self::load_template_from_markdown( $slug );
            if ( null !== $tpl ) {
                $templates[] = $tpl;
            }
        }

        if ( $include_body ) {
            return $templates;
        }

        return array_map( array( __CLASS__, 'template_summary' ), $templates );
    }

    /**
     * Ordered slugs of the built-in Skill templates shipped as Markdown files
     * under includes/data/skills/<slug>/SKILL.md.
     *
     * @return string[]
     */
    private static function built_in_template_slugs() {
        return array(
            'news-site-operator',
            'image-to-article',
            'title-to-article',
            'research-article',
            'paper-to-article',
            'expand-categories',
            'skill-creator',
        );
    }

    /**
     * Directory holding the shipped built-in Skill Markdown files.
     *
     * @return string
     */
    private static function built_in_templates_dir() {
        return WP_AGENT_PLUGIN_DIR . 'includes/data/skills';
    }

    /**
     * Load a built-in template from its SKILL.md file and normalize it into the
     * template array shape (slug/name/description/triggers/permissions/
     * schedule_templates/body). Every built-in Skill's prompt is stored as a
     * Markdown file, exactly like GitHub-installed and user-saved Skills.
     *
     * @param string $slug Template slug.
     * @return array|null
     */
    private static function load_template_from_markdown( $slug ) {
        $slug = sanitize_title( $slug );
        if ( '' === $slug ) {
            return null;
        }

        // Prefer the guarded PHP wrapper (skill.php): it returns the verbatim
        // SKILL.md content but cannot be downloaded directly over HTTP on any
        // web server. Fall back to a raw SKILL.md only if no wrapper exists
        // (e.g. during local development before the wrapper is built).
        $dir       = self::built_in_templates_dir() . '/' . $slug;
        $php_file  = $dir . '/skill.php';
        $md_file   = $dir . '/SKILL.md';
        $markdown  = '';

        if ( is_readable( $php_file ) ) {
            $loaded = include $php_file;
            if ( is_string( $loaded ) ) {
                $markdown = $loaded;
            }
        }
        if ( '' === trim( $markdown ) && is_readable( $md_file ) ) {
            $markdown = (string) file_get_contents( $md_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        }
        if ( '' === trim( $markdown ) ) {
            return null;
        }

        $parsed   = self::parse_skill_markdown( $markdown );
        $meta     = $parsed['metadata'];
        $body     = $parsed['body'];

        $resolved_slug = sanitize_title( $meta['slug'] ?? $slug );
        if ( '' === $resolved_slug ) {
            $resolved_slug = $slug;
        }

        $template = array(
            'slug'        => $resolved_slug,
            'name'        => sanitize_text_field( $meta['name'] ?? $slug ),
            'description' => sanitize_textarea_field( $meta['description'] ?? '' ),
            'triggers'    => self::string_list( $meta['triggers'] ?? array() ),
            'permissions' => self::sanitize_permissions( $meta['permissions'] ?? array() ),
            'body'        => $body,
        );

        if ( ! empty( $meta['schedule_templates'] ) ) {
            $template['schedule_templates'] = self::string_list( $meta['schedule_templates'] );
        }

        return $template;
    }

    /**
     * Get a built-in Skill template by slug.
     *
     * @param string $slug Template slug.
     * @return array|null
     */
    public static function template( $slug ) {
        $slug = sanitize_title( $slug );
        foreach ( self::built_in_templates( true ) as $template ) {
            if ( $slug === $template['slug'] ) {
                return $template;
            }
        }
        return null;
    }

    /**
     * Install a built-in template as a normal private Skill for a user.
     *
     * @param int    $user_id       Owner user ID.
     * @param string $template_slug Template slug.
     * @return array|WP_Error
     */
    public static function install_template( $user_id, $template_slug ) {
        $template = self::template( $template_slug );
        if ( ! $template ) {
            return new WP_Error( 'wp_agent_skill_template_missing', 'Built-in Skill template was not found.' );
        }

        $skill = self::save( $user_id, array(
            'name'           => $template['name'],
            'slug'           => $template['slug'],
            'description'    => $template['description'],
            'triggers'       => $template['triggers'],
            'permissions'    => $template['permissions'] ?? array(),
            'body'           => $template['body'],
            'visibility'     => 'private',
            'runtime_source' => array(
                'type'          => 'built_in_template',
                'template_slug' => $template['slug'],
            ),
        ) );
        if ( is_wp_error( $skill ) ) {
            return $skill;
        }

        WPAgent::audit_log( (int) $user_id, 'skill_template_installed', array(
            'template_slug' => $template['slug'],
            'skill_id'      => (int) $skill['id'],
            'skill_version' => (int) $skill['version'],
        ), 'admin' );

        return array(
            'success'  => true,
            'template' => self::template_summary( $template ),
            'skill'    => $skill,
        );
    }

    /**
     * Archive a skill.
     *
     * @param int    $user_id
     * @param string $slug
     * @return bool
     */
    public static function archive( $user_id, $slug ) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status'     => 'archived',
                'updated_at' => current_time( 'mysql', true ),
            ),
            array(
                'user_id' => (int) $user_id,
                'slug'    => sanitize_title( $slug ),
            ),
            array( '%s', '%s' ),
            array( '%d', '%s' )
        );

        $archived = $wpdb->rows_affected > 0;
        if ( $archived ) {
            self::update_local_skill_status( $user_id, $slug, 'archived' );
        }

        return $archived;
    }

    /**
     * Draft a non-executable local Skill from an existing Agent run.
     *
     * This is intentionally read-only. Callers must pass the returned
     * save_params back through manage_skills action=save, which is already
     * covered by the human-confirmation gate before anything is persisted.
     *
     * @param int   $user_id Owner/requesting user.
     * @param int   $run_id  Source run ID.
     * @param array $data    Optional name, slug, description, triggers.
     * @return array|WP_Error
     */
    public static function draft_from_run( $user_id, $run_id, $data = array() ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $run_id  = (int) $run_id;
        if ( $user_id <= 0 || $run_id <= 0 ) {
            return new WP_Error( 'wp_agent_skill_draft_run', 'A valid user and run_id are required to draft a Skill.' );
        }

        if ( ! class_exists( 'WPAgent_Runs' ) ) {
            return new WP_Error( 'wp_agent_skill_draft_runs_unavailable', 'Run storage is unavailable.' );
        }

        $run = WPAgent_Runs::get( $run_id );
        if ( ! $run ) {
            return new WP_Error( 'wp_agent_skill_draft_run_missing', 'Source run was not found.' );
        }

        if ( (int) $run->user_id !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
            return new WP_Error( 'wp_agent_skill_draft_forbidden', 'You cannot draft a Skill from another user\'s run.' );
        }

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, role, content, tool_calls, created_at
             FROM {$wpdb->prefix}wp_agent_messages
             WHERE conversation_id = %d
             ORDER BY id ASC
             LIMIT 80",
            (int) $run->conversation_id
        ), ARRAY_A );

        $events = class_exists( 'WPAgent_Run_Events' ) ? array_reverse( WPAgent_Run_Events::recent( $run_id, 120 ) ) : array();

        if ( empty( $messages ) && empty( $events ) ) {
            return new WP_Error( 'wp_agent_skill_draft_empty', 'Source run does not have enough context to draft a Skill.' );
        }

        $first_user         = '';
        $assistant_notes    = array();
        $tool_sequence      = array();
        $observed_tools     = array();
        $source_message_ids = array();

        foreach ( $messages as $message ) {
            $role = (string) ( $message['role'] ?? '' );
            $text = self::skill_draft_excerpt( $message['content'] ?? '', 'assistant' === $role ? 280 : 220 );
            if ( '' !== $text ) {
                $source_message_ids[] = (int) $message['id'];
            }
            if ( 'user' === $role && '' === $first_user && '' !== $text ) {
                $first_user = $text;
            } elseif ( 'assistant' === $role && '' !== $text && count( $assistant_notes ) < 4 ) {
                $assistant_notes[] = $text;
            }

            $calls = ! empty( $message['tool_calls'] ) ? json_decode( (string) $message['tool_calls'], true ) : array();
            if ( is_array( $calls ) ) {
                foreach ( $calls as $call ) {
                    $tool = sanitize_key( $call['name'] ?? ( $call['function']['name'] ?? '' ) );
                    if ( '' === $tool ) {
                        continue;
                    }
                    $action = '';
                    $args   = $call['input'] ?? array();
                    if ( empty( $args ) && ! empty( $call['function']['arguments'] ) ) {
                        $decoded = json_decode( (string) $call['function']['arguments'], true );
                        $args    = is_array( $decoded ) ? $decoded : array();
                    }
                    if ( is_array( $args ) ) {
                        $action = sanitize_key( $args['action'] ?? '' );
                    }
                    $tool_sequence[] = array(
                        'tool'   => $tool,
                        'action' => $action,
                        'status' => 'observed',
                    );
                    $observed_tools[] = $tool;
                }
            }
        }

        foreach ( $events as $event ) {
            if ( 'tool_call' !== ( $event['event_type'] ?? '' ) ) {
                continue;
            }
            $metadata = json_decode( (string) ( $event['metadata'] ?? '' ), true );
            if ( ! is_array( $metadata ) ) {
                continue;
            }
            $tool = sanitize_key( $metadata['tool'] ?? '' );
            if ( '' === $tool ) {
                continue;
            }
            $tool_sequence[] = array(
                'tool'   => $tool,
                'action' => sanitize_key( $metadata['action'] ?? '' ),
                'status' => sanitize_key( $metadata['status'] ?? '' ),
            );
            $observed_tools[] = $tool;
        }

        $tool_sequence  = self::dedupe_tool_sequence( $tool_sequence );
        $observed_tools = array_values( array_unique( array_filter( $observed_tools ) ) );

        $name = sanitize_text_field( $data['name'] ?? '' );
        if ( '' === $name ) {
            $name = '' !== $first_user ? self::skill_draft_title( $first_user ) : 'Run ' . $run_id . ' Skill Draft';
        }

        $slug = sanitize_title( $data['slug'] ?? $name );
        if ( '' === $slug ) {
            $slug = 'run-' . $run_id . '-skill';
        }

        $description = sanitize_textarea_field( $data['description'] ?? '' );
        if ( '' === $description ) {
            $description = 'Reusable playbook drafted from WP Agent run #' . $run_id . '.';
        }

        $triggers = self::normalize_triggers( $data['triggers'] ?? array() );
        if ( empty( $triggers ) ) {
            $triggers = self::normalize_triggers( array_filter( array(
                $first_user,
                str_replace( '-', ' ', $slug ),
                'repeat run ' . $run_id,
            ) ) );
        }

        $body = self::build_run_skill_draft_body(
            $run,
            $first_user,
            $assistant_notes,
            $tool_sequence,
            $observed_tools
        );
        $blocked = self::validate_body( $body );
        if ( is_wp_error( $blocked ) ) {
            return $blocked;
        }

        $save_params = array(
            'action'         => 'save',
            'name'           => $name,
            'slug'           => $slug,
            'description'    => $description,
            'triggers'       => $triggers,
            'body'           => $body,
            'visibility'     => 'private',
            'runtime_source' => array(
                'type'            => 'run_draft',
                'run_id'          => $run_id,
                'conversation_id' => (int) $run->conversation_id,
                'message_ids'     => array_slice( array_values( array_unique( $source_message_ids ) ), 0, 20 ),
                'tools'           => $observed_tools,
            ),
        );

        return array(
            'success'     => true,
            'draft'       => array(
                'name'             => $name,
                'slug'             => $slug,
                'description'      => $description,
                'triggers'         => $triggers,
                'body'             => $body,
                'source_run_id'    => $run_id,
                'conversation_id'  => (int) $run->conversation_id,
                'message_count'    => count( $messages ),
                'tool_sequence'    => $tool_sequence,
                'observed_tools'   => $observed_tools,
                'requires_approval' => true,
            ),
            'save_params' => $save_params,
            'message'     => 'Skill draft created. Review it and submit save_params through manage_skills action=save; saving requires human confirmation.',
        );
    }

    /**
     * Return the private runtime mirror for a locally saved Skill.
     *
     * @param int    $user_id Owner user.
     * @param string $slug    Skill slug.
     * @return array|WP_Error
     */
    public static function local_runtime_manifest( $user_id, $slug ) {
        $user_id = (int) $user_id;
        $slug    = sanitize_title( $slug );
        if ( $user_id <= 0 || '' === $slug ) {
            return new WP_Error( 'wp_agent_skill_runtime_manifest', 'A valid user and skill slug are required.' );
        }

        $dir        = self::local_skill_dir( $user_id, $slug );
        $lock_file  = $dir . '/.lock.json';
        $skill_file = $dir . '/SKILL.md';
        $lock       = self::read_lock_file( $lock_file );
        if ( ! $lock || ! is_readable( $skill_file ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_manifest_missing', 'Local Skill runtime files were not found.' );
        }

        return array(
            'success'    => true,
            'dir'        => $dir,
            'lock_file'  => $lock_file,
            'skill_file' => $skill_file,
            'lock'       => $lock,
            'skill_md'   => (string) file_get_contents( $skill_file ),
        );
    }

    /**
     * Rebuild DB Skill rows from private local runtime mirrors for a user.
     *
     * The database remains the fast index, but the local runtime SKILL.md files
     * are treated as the recoverable source of truth for local playbooks.
     *
     * @param int $user_id Owner user.
     * @return array|WP_Error
     */
    public static function sync_local_runtime_index( $user_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return new WP_Error( 'wp_agent_skill_runtime_sync_user', 'A valid user is required to sync local Skill runtime files.' );
        }

        $dir = self::local_user_dir( $user_id );
        if ( ! is_dir( $dir ) ) {
            return array(
                'success'  => true,
                'scanned'  => 0,
                'restored' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'errors'   => array(),
            );
        }

        if ( ! self::runtime_path_within_skills_dir( $dir ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_path', 'Refusing to sync local Skills outside the private skills runtime directory.' );
        }

        $summary = array(
            'success'  => true,
            'scanned'  => 0,
            'restored' => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'errors'   => array(),
        );

        foreach ( scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $skill_dir = $dir . '/' . $entry;
            if ( ! is_dir( $skill_dir ) ) {
                continue;
            }
            $summary['scanned']++;

            $data = self::local_runtime_skill_data( $user_id, $skill_dir );
            if ( is_wp_error( $data ) ) {
                $summary['skipped']++;
                $summary['errors'][] = array(
                    'slug'  => sanitize_title( $entry ),
                    'error' => $data->get_error_message(),
                );
                continue;
            }

            $conflict = self::installed_package_slug_conflict( $user_id, $data['slug'] );
            if ( is_wp_error( $conflict ) ) {
                $summary['skipped']++;
                $summary['errors'][] = array(
                    'slug'  => $data['slug'],
                    'error' => $conflict->get_error_message(),
                );
                continue;
            }

            $existing = self::get_by_slug( $user_id, $data['slug'], false );
            $now      = current_time( 'mysql', true );
            if ( $existing ) {
                $updated = $wpdb->update(
                    self::table(),
                    array(
                        'name'        => $data['name'],
                        'description' => $data['description'],
                        'triggers'    => wp_json_encode( $data['triggers'] ),
                        'permissions' => wp_json_encode( self::sanitize_permissions( $data['permissions'] ?? array() ) ),
                        'body'        => sanitize_textarea_field( $data['body'] ),
                        'visibility'  => $data['visibility'],
                        'status'      => $data['status'],
                        'version'     => $data['version'],
                        'updated_at'  => $now,
                    ),
                    array( 'id' => (int) $existing['id'] ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
                    array( '%d' )
                );
                if ( false === $updated ) {
                    $summary['skipped']++;
                    $summary['errors'][] = array(
                        'slug'  => $data['slug'],
                        'error' => 'Could not update the DB Skill index from runtime.',
                    );
                    continue;
                }
                $skill_id = (int) $existing['id'];
                $summary['updated']++;
            } else {
                $inserted = $wpdb->insert(
                    self::table(),
                    array(
                        'user_id'     => $user_id,
                        'slug'        => $data['slug'],
                        'name'        => $data['name'],
                        'description' => $data['description'],
                        'triggers'    => wp_json_encode( $data['triggers'] ),
                        'permissions' => wp_json_encode( self::sanitize_permissions( $data['permissions'] ?? array() ) ),
                        'body'        => sanitize_textarea_field( $data['body'] ),
                        'visibility'  => $data['visibility'],
                        'status'      => $data['status'],
                        'version'     => $data['version'],
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ),
                    array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
                );
                if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) {
                    $summary['skipped']++;
                    $summary['errors'][] = array(
                        'slug'  => $data['slug'],
                        'error' => 'Could not restore the DB Skill index from runtime.',
                    );
                    continue;
                }
                $skill_id = (int) $wpdb->insert_id;
                $summary['restored']++;
            }

            $data['lock']['wp_skill_id']      = $skill_id;
            $data['lock']['wp_skill_version'] = $data['version'];
            $data['lock']['status']           = $data['status'];
            $data['lock']['synced_at']        = $now;
            self::write_json_file( $skill_dir . '/.lock.json', $data['lock'] );
        }

        return $summary;
    }

    /**
     * Parse a SKILL.md document with simple YAML-like frontmatter.
     *
     * @param string $markdown Raw SKILL.md content.
     * @return array
     */
    public static function parse_skill_markdown( $markdown ) {
        $markdown = (string) $markdown;
        $metadata = array();
        $body     = $markdown;

        if ( preg_match( "/\\A---\\s*\\n(.*?)\\n---\\s*\\n?(.*)\\z/s", $markdown, $matches ) ) {
            $metadata = self::parse_frontmatter( $matches[1] );
            $body     = $matches[2];
        }

        return array(
            'metadata' => $metadata,
            'body'     => trim( $body ),
        );
    }

    /**
     * Install a GitHub-hosted skill package into quarantine.
     *
     * @param int   $user_id Installer user.
     * @param array $data    repository, skill_path, ref, github_token.
     * @return array|WP_Error
     */
    public static function install_from_github( $user_id, $data ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return new WP_Error( 'wp_agent_skill_user', 'A valid user is required to install a skill.' );
        }

        $defaults        = self::github_store_defaults();
        $repository_arg  = trim( (string) ( $data['repository'] ?? ( $data['repo'] ?? '' ) ) );
        $ref_arg         = trim( (string) ( $data['ref'] ?? '' ) );
        $skill_path_arg  = trim( (string) ( $data['skill_path'] ?? ( $data['path'] ?? '' ) ) );

        $repo = self::parse_github_repository( '' !== $repository_arg ? $repository_arg : $defaults['repository'] );
        if ( is_wp_error( $repo ) ) {
            return $repo;
        }

        $ref = self::sanitize_git_ref( '' !== $ref_arg ? $ref_arg : $defaults['ref'] );
        if ( '' === $ref ) {
            return new WP_Error( 'wp_agent_skill_ref', 'A GitHub ref is required.' );
        }

        $package_path = self::normalize_package_path( '' !== $skill_path_arg ? $skill_path_arg : $defaults['skill_path'] );
        if ( '' === $package_path ) {
            return new WP_Error( 'wp_agent_skill_path', 'A skill_path such as skills/news-rewriter is required.' );
        }

        $skill_file_path = self::skill_file_path( $package_path );
        $skill_dir_path  = dirname( $skill_file_path );
        if ( '.' === $skill_dir_path ) {
            $skill_dir_path = '';
        }

        $token = self::github_token( $data );
        $skill_file = self::github_fetch_file( $repo['owner'], $repo['repo'], $skill_file_path, $ref, $token );
        if ( is_wp_error( $skill_file ) ) {
            return $skill_file;
        }

        $files = array(
            'SKILL.md' => $skill_file['body'],
        );
        $total_bytes = strlen( $skill_file['body'] );
        $warnings    = array();

        foreach ( array( 'references', 'templates', 'assets', 'scripts' ) as $subdir ) {
            $remote_dir = '' === $skill_dir_path ? $subdir : $skill_dir_path . '/' . $subdir;
            $collected  = self::github_collect_dir(
                $repo['owner'],
                $repo['repo'],
                $remote_dir,
                $ref,
                $token,
                $subdir,
                $files,
                $total_bytes
            );
            if ( is_wp_error( $collected ) ) {
                return $collected;
            }
        }

        foreach ( array_keys( $files ) as $rel ) {
            if ( 0 === strpos( $rel, 'scripts/' ) ) {
                $warnings[] = 'Package contains scripts. Scripts remain quarantined files and are not executable skills.';
                break;
            }
        }

        return self::quarantine_package( $user_id, array(
            'source' => array(
                'type'       => 'github',
                'repository' => $repo['owner'] . '/' . $repo['repo'],
                'owner'      => $repo['owner'],
                'repo'       => $repo['repo'],
                'ref'        => $ref,
                'path'       => $package_path,
                'skill_file' => $skill_file_path,
                'file_sha'   => $skill_file['sha'],
            ),
            'files'    => $files,
            'warnings' => $warnings,
        ) );
    }

    /**
     * List quarantined packages.
     *
     * @param int $limit
     * @return array
     */
    public static function quarantine_list( $limit = 50 ) {
        $limit = max( 1, min( (int) $limit, 100 ) );
        $dir   = self::quarantine_dir();
        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $items = array();
        foreach ( scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $lock = self::read_lock_file( $dir . '/' . $entry . '/.lock.json' );
            if ( $lock ) {
                $items[] = self::lock_summary( $lock );
            }
        }

        usort( $items, function( $a, $b ) {
            return strcmp( $b['fetched_at'] ?? '', $a['fetched_at'] ?? '' );
        } );

        return array_slice( $items, 0, $limit );
    }

    /**
     * Get a quarantined package lock and SKILL body.
     *
     * @param string $quarantine_id
     * @return array|WP_Error
     */
    public static function get_quarantined( $quarantine_id ) {
        $quarantine_id = self::sanitize_package_id( $quarantine_id );
        if ( '' === $quarantine_id ) {
            return new WP_Error( 'wp_agent_skill_quarantine_id', 'A quarantine_id is required.' );
        }

        $dir  = self::quarantine_dir() . '/' . $quarantine_id;
        $lock = self::read_lock_file( $dir . '/.lock.json' );
        if ( ! $lock ) {
            return new WP_Error( 'wp_agent_skill_quarantine_missing', 'Quarantined skill package was not found.' );
        }

        $body = is_readable( $dir . '/SKILL.md' ) ? (string) file_get_contents( $dir . '/SKILL.md' ) : '';
        return array(
            'lock'     => $lock,
            'summary'  => self::lock_summary( $lock ),
            'skill_md' => $body,
            'parsed'   => self::parse_skill_markdown( $body ),
        );
    }

    /**
     * Activate a quarantined package as a non-executable DB skill.
     *
     * @param int    $user_id
     * @param string $quarantine_id
     * @return array|WP_Error
     */
    public static function activate_quarantined( $user_id, $quarantine_id, $force = false ) {
        $user_id = (int) $user_id;
        $package = self::get_quarantined( $quarantine_id );
        if ( is_wp_error( $package ) ) {
            return $package;
        }

        $lock     = $package['lock'];
        $parsed   = $package['parsed'];
        $metadata = $parsed['metadata'];
        $body     = trim( (string) $parsed['body'] );
        $name     = sanitize_text_field( $metadata['name'] ?? ( $lock['name'] ?? '' ) );
        $slug     = sanitize_title( $metadata['slug'] ?? ( $lock['slug'] ?? $name ) );

        if ( 'quarantined' !== ( $lock['status'] ?? '' ) ) {
            return new WP_Error( 'wp_agent_skill_quarantine_status', 'Quarantined package is not pending activation.' );
        }

        if ( '' === $name || '' === $slug || '' === $body ) {
            return new WP_Error( 'wp_agent_skill_package_invalid', 'Quarantined package is missing name, slug, or playbook body.' );
        }

        $conflict = self::local_skill_slug_conflict( $user_id, $slug );
        if ( is_wp_error( $conflict ) ) {
            return $conflict;
        }

        $description = sanitize_textarea_field( $metadata['description'] ?? ( $lock['description'] ?? '' ) );
        $triggers    = array();
        if ( ! empty( $metadata['triggers'] ) ) {
            $triggers = $metadata['triggers'];
        } elseif ( ! empty( $metadata['schedule_templates'] ) ) {
            $triggers = $metadata['schedule_templates'];
        }

        $quarantine_dir = self::quarantine_dir() . '/' . self::sanitize_package_id( $quarantine_id );
        $installed_dir  = self::installed_dir() . '/' . $slug;
        $existing_lock  = null;
        if ( is_dir( $installed_dir ) ) {
            $existing_lock = self::read_lock_file( $installed_dir . '/.lock.json' );
            $pinned_error  = self::pinned_package_error( $existing_lock, 'activate an updated package' );
            if ( ! $force && is_wp_error( $pinned_error ) ) {
                return $pinned_error;
            }
        }

        $skill = self::save( $user_id, array(
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $description,
            'triggers'        => $triggers,
            'body'            => $body,
            'visibility'      => 'private',
            'persist_runtime' => false,
        ) );
        if ( is_wp_error( $skill ) ) {
            return $skill;
        }

        if ( is_dir( $installed_dir ) ) {
            $rollback_dir = self::next_rollback_dir( $slug );
            self::copy_runtime_dir( $installed_dir, $rollback_dir );
            self::delete_runtime_dir( $installed_dir );
        }
        $copy = self::copy_runtime_dir( $quarantine_dir, $installed_dir );
        if ( is_wp_error( $copy ) ) {
            return $copy;
        }

        $lock['status']           = 'active';
        $lock['activated_at']     = current_time( 'mysql', true );
        $lock['activated_by']     = $user_id;
        $lock['wp_skill_id']      = (int) $skill['id'];
        $lock['wp_skill_version'] = (int) $skill['version'];
        $lock                     = self::preserve_pin_state( $lock, $existing_lock, $force );
        self::write_json_file( $installed_dir . '/.lock.json', $lock );
        self::write_json_file( $quarantine_dir . '/.lock.json', array_merge( $lock, array( 'status' => 'activated' ) ) );

        WPAgent::audit_log( $user_id, 'skill_package_activated', array(
            'slug'          => $slug,
            'quarantine_id' => self::sanitize_package_id( $quarantine_id ),
            'source'        => $lock['source'] ?? array(),
        ), 'admin' );

        return array(
            'success'       => true,
            'skill'         => $skill,
            'installed_dir' => $installed_dir,
            'lock'          => self::lock_summary( $lock ),
        );
    }

    /**
     * Pin or unpin an installed package so active package mutations are explicit.
     *
     * @param int    $user_id Requesting user.
     * @param string $slug    Installed package slug.
     * @param bool   $pinned  Whether the package should be pinned.
     * @return array|WP_Error
     */
    public static function pin_package( $user_id, $slug, $pinned = true ) {
        $user_id = (int) $user_id;
        $slug    = sanitize_title( $slug );
        if ( $user_id <= 0 || '' === $slug ) {
            return new WP_Error( 'wp_agent_skill_package_pin', 'A valid user and package slug are required.' );
        }

        $lock = self::installed_lock( $slug );
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }
        if ( 'active' !== ( $lock['status'] ?? '' ) ) {
            return new WP_Error( 'wp_agent_skill_package_status', 'Only active installed packages can be pinned.' );
        }

        $now = current_time( 'mysql', true );
        if ( $pinned ) {
            $lock['pinned']    = true;
            $lock['pinned_at'] = $now;
            $lock['pinned_by'] = $user_id;
            unset( $lock['unpinned_at'], $lock['unpinned_by'] );
        } else {
            $lock['pinned']      = false;
            $lock['unpinned_at'] = $now;
            $lock['unpinned_by'] = $user_id;
        }
        $lock['updated_at'] = $now;

        $installed_dir = self::installed_dir() . '/' . $slug;
        $written       = self::write_json_file( $installed_dir . '/.lock.json', $lock );
        if ( is_wp_error( $written ) ) {
            return $written;
        }

        WPAgent::audit_log( $user_id, $pinned ? 'skill_package_pinned' : 'skill_package_unpinned', array(
            'slug'   => $slug,
            'source' => $lock['source'] ?? array(),
        ), 'admin' );

        return array(
            'success' => true,
            'slug'    => $slug,
            'pinned'  => ! empty( $lock['pinned'] ),
            'lock'    => self::lock_summary( $lock ),
        );
    }

    /**
     * List activated packaged skills.
     *
     * @param int $limit
     * @return array
     */
    public static function installed_packages( $limit = 50 ) {
        $limit = max( 1, min( (int) $limit, 100 ) );
        $dir   = self::installed_dir();
        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $items = array();
        foreach ( scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $lock = self::read_lock_file( $dir . '/' . $entry . '/.lock.json' );
            if ( $lock ) {
                $summary                     = self::lock_summary( $lock );
                $rollbacks                   = self::package_rollbacks( $summary['slug'], 10 );
                $summary['rollback_count']   = count( $rollbacks );
                $summary['latest_rollback']  = $rollbacks[0]['rollback_id'] ?? '';
                $items[]                     = $summary;
            }
        }

        return array_slice( $items, 0, $limit );
    }

    /**
     * Rebuild DB Skill rows from activated packaged Skill runtime files.
     *
     * @param int $user_id Owner user.
     * @return array|WP_Error
     */
    public static function sync_installed_package_index( $user_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return new WP_Error( 'wp_agent_skill_package_sync_user', 'A valid user is required to sync installed Skill packages.' );
        }

        $dir = self::installed_dir();
        if ( ! is_dir( $dir ) ) {
            return array(
                'success'  => true,
                'scanned'  => 0,
                'restored' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'errors'   => array(),
            );
        }

        if ( ! self::runtime_path_within_skills_dir( $dir ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_path', 'Refusing to sync installed packages outside the private skills runtime directory.' );
        }

        $summary = array(
            'success'  => true,
            'scanned'  => 0,
            'restored' => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'errors'   => array(),
        );

        foreach ( scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }
            $package_dir = $dir . '/' . $entry;
            if ( ! is_dir( $package_dir ) ) {
                continue;
            }
            $summary['scanned']++;

            $data = self::installed_package_skill_data( $user_id, $package_dir );
            if ( is_wp_error( $data ) ) {
                $summary['skipped']++;
                $summary['errors'][] = array(
                    'slug'  => sanitize_title( $entry ),
                    'error' => $data->get_error_message(),
                );
                continue;
            }

            $conflict = self::local_skill_slug_conflict( $user_id, $data['slug'] );
            if ( is_wp_error( $conflict ) ) {
                $summary['skipped']++;
                $summary['errors'][] = array(
                    'slug'  => $data['slug'],
                    'error' => $conflict->get_error_message(),
                );
                continue;
            }

            $existing = self::get_by_slug( $user_id, $data['slug'], false );
            $now      = current_time( 'mysql', true );
            if ( $existing ) {
                $updated = $wpdb->update(
                    self::table(),
                    array(
                        'name'        => $data['name'],
                        'description' => $data['description'],
                        'triggers'    => wp_json_encode( $data['triggers'] ),
                        'permissions' => wp_json_encode( self::sanitize_permissions( $data['permissions'] ?? array() ) ),
                        'body'        => sanitize_textarea_field( $data['body'] ),
                        'visibility'  => $data['visibility'],
                        'status'      => 'active',
                        'version'     => $data['version'],
                        'updated_at'  => $now,
                    ),
                    array( 'id' => (int) $existing['id'] ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
                    array( '%d' )
                );
                if ( false === $updated ) {
                    $summary['skipped']++;
                    $summary['errors'][] = array(
                        'slug'  => $data['slug'],
                        'error' => 'Could not update the DB Skill index from installed package runtime.',
                    );
                    continue;
                }
                $skill_id = (int) $existing['id'];
                $summary['updated']++;
            } else {
                $inserted = $wpdb->insert(
                    self::table(),
                    array(
                        'user_id'     => $user_id,
                        'slug'        => $data['slug'],
                        'name'        => $data['name'],
                        'description' => $data['description'],
                        'triggers'    => wp_json_encode( $data['triggers'] ),
                        'permissions' => wp_json_encode( self::sanitize_permissions( $data['permissions'] ?? array() ) ),
                        'body'        => sanitize_textarea_field( $data['body'] ),
                        'visibility'  => $data['visibility'],
                        'status'      => 'active',
                        'version'     => $data['version'],
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ),
                    array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
                );
                if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) {
                    $summary['skipped']++;
                    $summary['errors'][] = array(
                        'slug'  => $data['slug'],
                        'error' => 'Could not restore the DB Skill index from installed package runtime.',
                    );
                    continue;
                }
                $skill_id = (int) $wpdb->insert_id;
                $summary['restored']++;
            }

            $data['lock']['status']           = 'active';
            $data['lock']['wp_skill_id']      = $skill_id;
            $data['lock']['wp_skill_version'] = $data['version'];
            $data['lock']['synced_at']        = $now;
            self::write_json_file( $package_dir . '/.lock.json', $data['lock'] );
        }

        return $summary;
    }

    /**
     * Check whether an installed GitHub package source has changed.
     *
     * @param string $slug Installed package slug.
     * @return array|WP_Error
     */
    public static function check_package_update( $slug ) {
        $lock = self::installed_lock( $slug );
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        $source = $lock['source'] ?? array();
        if ( 'github' !== ( $source['type'] ?? '' ) ) {
            return new WP_Error( 'wp_agent_skill_source', 'Only GitHub skill packages can be checked for updates.' );
        }

        $owner = self::sanitize_github_component( $source['owner'] ?? '' );
        $repo  = self::sanitize_github_component( $source['repo'] ?? '' );
        $ref   = self::sanitize_git_ref( $source['ref'] ?? '' );
        $path  = self::normalize_package_path( $source['skill_file'] ?? self::skill_file_path( $source['path'] ?? '' ) );
        if ( '' === $owner || '' === $repo || '' === $ref || '' === $path ) {
            return new WP_Error( 'wp_agent_skill_source', 'Installed package source is missing GitHub owner, repo, ref, or path.' );
        }

        $skill_file = self::github_fetch_file( $owner, $repo, $path, $ref, self::github_token( array() ) );
        if ( is_wp_error( $skill_file ) ) {
            return $skill_file;
        }

        $parsed             = self::parse_skill_markdown( $skill_file['body'] );
        $remote_body_sha256 = hash( 'sha256', trim( (string) $parsed['body'] ) );
        $current_file_sha   = sanitize_text_field( $source['file_sha'] ?? '' );
        $remote_file_sha    = sanitize_text_field( $skill_file['sha'] ?? '' );
        $current_body_sha   = sanitize_text_field( $lock['body_sha256'] ?? '' );
        $has_update         = false;

        if ( '' !== $current_file_sha && '' !== $remote_file_sha && $current_file_sha !== $remote_file_sha ) {
            $has_update = true;
        } elseif ( '' !== $current_body_sha && $current_body_sha !== $remote_body_sha256 ) {
            $has_update = true;
        }

        return array(
            'success'    => true,
            'slug'       => sanitize_title( $lock['slug'] ?? $slug ),
            'pinned'     => ! empty( $lock['pinned'] ),
            'has_update' => $has_update,
            'current'    => array(
                'version'      => sanitize_text_field( $lock['version'] ?? '' ),
                'file_sha'     => $current_file_sha,
                'body_sha256'  => $current_body_sha,
                'source_ref'   => $ref,
                'source_path'  => sanitize_text_field( $source['path'] ?? '' ),
            ),
            'remote'     => array(
                'version'      => sanitize_text_field( $parsed['metadata']['version'] ?? '' ),
                'file_sha'     => $remote_file_sha,
                'body_sha256'  => $remote_body_sha256,
                'source_ref'   => $ref,
                'source_path'  => sanitize_text_field( $source['path'] ?? '' ),
            ),
        );
    }

    /**
     * Re-download an installed package's GitHub source into quarantine.
     *
     * @param int    $user_id Requesting user.
     * @param string $slug    Installed package slug.
     * @return array|WP_Error
     */
    public static function refresh_package_from_source( $user_id, $slug ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return new WP_Error( 'wp_agent_skill_user', 'A valid user is required to refresh a skill package.' );
        }

        $lock = self::installed_lock( $slug );
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        $source = $lock['source'] ?? array();
        if ( 'github' !== ( $source['type'] ?? '' ) ) {
            return new WP_Error( 'wp_agent_skill_source', 'Only GitHub skill packages can be refreshed.' );
        }

        return self::install_from_github( $user_id, array(
            'repository' => $source['repository'] ?? ( ( $source['owner'] ?? '' ) . '/' . ( $source['repo'] ?? '' ) ),
            'ref'        => $source['ref'] ?? '',
            'skill_path' => $source['path'] ?? ( $source['skill_file'] ?? '' ),
        ) );
    }

    /**
     * List rollback snapshots for an installed package.
     *
     * @param string $slug  Installed package slug.
     * @param int    $limit Maximum snapshots.
     * @return array
     */
    public static function package_rollbacks( $slug, $limit = 20 ) {
        $slug  = sanitize_title( $slug );
        $limit = max( 1, min( (int) $limit, 100 ) );
        if ( '' === $slug ) {
            return array();
        }

        $dir = self::rollback_dir() . '/' . $slug;
        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $items = array();
        foreach ( scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry || $entry !== self::sanitize_package_id( $entry ) ) {
                continue;
            }
            $lock = self::read_lock_file( $dir . '/' . $entry . '/.lock.json' );
            if ( ! $lock ) {
                continue;
            }
            $summary                = self::lock_summary( $lock );
            $summary['rollback_id'] = $entry;
            $summary['snapshot_at'] = $entry;
            $items[]                = $summary;
        }

        usort( $items, function( $a, $b ) {
            return strcmp( $b['rollback_id'] ?? '', $a['rollback_id'] ?? '' );
        } );

        return array_slice( $items, 0, $limit );
    }

    /**
     * Restore an installed package from a rollback snapshot.
     *
     * @param int    $user_id     Requesting user.
     * @param string $slug        Installed package slug.
     * @param string $rollback_id Optional rollback snapshot id. Latest is used when empty.
     * @return array|WP_Error
     */
    public static function rollback_package( $user_id, $slug, $rollback_id = '', $force = false ) {
        $user_id = (int) $user_id;
        $slug    = sanitize_title( $slug );
        if ( $user_id <= 0 || '' === $slug ) {
            return new WP_Error( 'wp_agent_skill_rollback', 'A valid user and package slug are required for rollback.' );
        }

        $current_lock = self::installed_lock( $slug );
        if ( is_wp_error( $current_lock ) ) {
            if ( 'wp_agent_skill_package_missing' !== $current_lock->get_error_code() ) {
                return $current_lock;
            }
            $current_lock = null;
        }
        if ( is_array( $current_lock ) ) {
            $pinned_error = self::pinned_package_error( $current_lock, 'roll back this package' );
            if ( ! $force && is_wp_error( $pinned_error ) ) {
                return $pinned_error;
            }
        }

        $rollbacks = self::package_rollbacks( $slug, 100 );
        if ( empty( $rollbacks ) ) {
            return new WP_Error( 'wp_agent_skill_rollback_missing', 'No rollback snapshots are available for this package.' );
        }

        $rollback_id = self::sanitize_package_id( $rollback_id );
        if ( '' === $rollback_id ) {
            $rollback_id = $rollbacks[0]['rollback_id'] ?? '';
        }

        $rollback_dir = self::rollback_dir() . '/' . $slug . '/' . $rollback_id;
        $lock         = self::read_lock_file( $rollback_dir . '/.lock.json' );
        $skill_md     = is_readable( $rollback_dir . '/SKILL.md' ) ? (string) file_get_contents( $rollback_dir . '/SKILL.md' ) : '';
        if ( ! $lock || '' === trim( $skill_md ) ) {
            return new WP_Error( 'wp_agent_skill_rollback_missing', 'Rollback snapshot is missing its lock file or SKILL.md.' );
        }

        $parsed   = self::parse_skill_markdown( $skill_md );
        $metadata = $parsed['metadata'];
        $body     = trim( (string) $parsed['body'] );
        $name     = sanitize_text_field( $metadata['name'] ?? ( $lock['name'] ?? '' ) );
        $skill_slug = sanitize_title( $metadata['slug'] ?? ( $lock['slug'] ?? $name ) );
        if ( $skill_slug !== $slug || '' === $name || '' === $body ) {
            return new WP_Error( 'wp_agent_skill_rollback_invalid', 'Rollback snapshot does not match the requested package slug.' );
        }

        $conflict = self::local_skill_slug_conflict( $user_id, $slug );
        if ( is_wp_error( $conflict ) ) {
            return $conflict;
        }

        $description = sanitize_textarea_field( $metadata['description'] ?? ( $lock['description'] ?? '' ) );
        $triggers    = array();
        if ( ! empty( $metadata['triggers'] ) ) {
            $triggers = $metadata['triggers'];
        } elseif ( ! empty( $metadata['schedule_templates'] ) ) {
            $triggers = $metadata['schedule_templates'];
        }

        $skill = self::save( $user_id, array(
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $description,
            'triggers'        => $triggers,
            'body'            => $body,
            'visibility'      => 'private',
            'persist_runtime' => false,
        ) );
        if ( is_wp_error( $skill ) ) {
            return $skill;
        }

        $installed_dir = self::installed_dir() . '/' . $slug;
        $temp_dir      = self::skills_dir() . '/tmp/' . self::sanitize_package_id( 'rollback-' . $slug . '-' . $rollback_id );
        self::delete_runtime_dir( $temp_dir );
        $copy = self::copy_runtime_dir( $rollback_dir, $temp_dir );
        if ( is_wp_error( $copy ) ) {
            return $copy;
        }

        if ( is_dir( $installed_dir ) ) {
            $backup_dir = self::next_rollback_dir( $slug );
            $backup     = self::copy_runtime_dir( $installed_dir, $backup_dir );
            if ( is_wp_error( $backup ) ) {
                self::delete_runtime_dir( $temp_dir );
                return $backup;
            }
            $deleted = self::delete_runtime_dir( $installed_dir );
            if ( is_wp_error( $deleted ) ) {
                self::delete_runtime_dir( $temp_dir );
                return $deleted;
            }
        }

        $copy = self::copy_runtime_dir( $temp_dir, $installed_dir );
        self::delete_runtime_dir( $temp_dir );
        if ( is_wp_error( $copy ) ) {
            return $copy;
        }

        $lock['status']           = 'active';
        $lock['activated_at']     = current_time( 'mysql', true );
        $lock['activated_by']     = $user_id;
        $lock['rolled_back_at']   = current_time( 'mysql', true );
        $lock['rollback_from']    = $rollback_id;
        $lock['wp_skill_id']      = (int) $skill['id'];
        $lock['wp_skill_version'] = (int) $skill['version'];
        $lock                     = self::preserve_pin_state( $lock, $current_lock, $force );
        self::write_json_file( $installed_dir . '/.lock.json', $lock );

        WPAgent::audit_log( $user_id, 'skill_package_rolled_back', array(
            'slug'        => $slug,
            'rollback_id' => $rollback_id,
            'source'      => $lock['source'] ?? array(),
        ), 'admin' );

        return array(
            'success'     => true,
            'skill'       => $skill,
            'rollback_id' => $rollback_id,
            'lock'        => self::lock_summary( $lock ),
        );
    }

    private static function hydrate( $row, $apply_runtime = true ) {
        $row['id']       = (int) $row['id'];
        $row['user_id']  = (int) $row['user_id'];
        $row['version']  = (int) $row['version'];
        $row['triggers'] = ! empty( $row['triggers'] ) ? json_decode( $row['triggers'], true ) : array();
        if ( ! is_array( $row['triggers'] ) ) {
            $row['triggers'] = array();
        }
        $row['permissions'] = ! empty( $row['permissions'] ) ? json_decode( (string) $row['permissions'], true ) : array();
        if ( ! is_array( $row['permissions'] ) ) {
            $row['permissions'] = array();
        }
        $row['permissions'] = self::sanitize_permissions( $row['permissions'] );
        if ( ! $apply_runtime ) {
            return $row;
        }
        return self::apply_local_runtime( $row );
    }

    private static function apply_local_runtime( $row ) {
        if ( ! is_array( $row ) || empty( $row['user_id'] ) || empty( $row['slug'] ) ) {
            return $row;
        }

        if ( self::active_installed_package_lock( $row['slug'] ) ) {
            return $row;
        }

        $manifest = self::local_runtime_manifest( (int) $row['user_id'], $row['slug'] );
        if ( is_wp_error( $manifest ) ) {
            return $row;
        }

        $lock = $manifest['lock'];
        if ( ! empty( $lock['wp_skill_id'] ) && (int) $lock['wp_skill_id'] !== (int) $row['id'] ) {
            return $row;
        }

        $parsed = self::parse_skill_markdown( $manifest['skill_md'] );
        $body   = trim( (string) ( $parsed['body'] ?? '' ) );
        if ( '' !== $body ) {
            $row['body'] = $body;
        }

        $metadata = is_array( $parsed['metadata'] ?? null ) ? $parsed['metadata'] : array();
        if ( ! empty( $metadata['name'] ) ) {
            $row['name'] = sanitize_text_field( $metadata['name'] );
        }
        if ( array_key_exists( 'description', $metadata ) ) {
            $row['description'] = sanitize_textarea_field( (string) $metadata['description'] );
        }
        if ( ! empty( $metadata['triggers'] ) ) {
            $row['triggers'] = self::normalize_triggers( $metadata['triggers'] );
        }
        $permissions = self::sanitize_permissions( $metadata['permissions'] ?? ( $lock['permissions'] ?? array() ) );
        if ( empty( $permissions ) && ! empty( $lock['source']['template_slug'] ) ) {
            $template = self::template( $lock['source']['template_slug'] );
            if ( $template ) {
                $permissions = self::sanitize_permissions( $template['permissions'] ?? array() );
            }
        }
        if ( ! empty( $permissions ) ) {
            $row['permissions'] = $permissions;
        }
        if ( ! empty( $lock['source'] ) ) {
            $row['runtime_source'] = self::sanitize_local_source( $lock['source'] );
        }

        return $row;
    }

    private static function local_runtime_skill_data( $user_id, $dir ) {
        if ( ! is_dir( $dir ) || ! self::runtime_path_within_skills_dir( $dir ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_path', 'Local Skill runtime path is invalid.' );
        }

        $lock = self::read_lock_file( $dir . '/.lock.json' );
        if ( ! $lock ) {
            return new WP_Error( 'wp_agent_skill_runtime_lock', 'Local Skill runtime lock is missing or invalid.' );
        }

        if ( 'local' !== ( $lock['kind'] ?? 'local' ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_kind', 'Only local Skill runtime mirrors can be synced into the local DB index.' );
        }

        $skill_md = is_readable( $dir . '/SKILL.md' ) ? (string) file_get_contents( $dir . '/SKILL.md' ) : '';
        if ( '' === trim( $skill_md ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_file', 'Local Skill runtime SKILL.md is missing.' );
        }

        $parsed   = self::parse_skill_markdown( $skill_md );
        $metadata = is_array( $parsed['metadata'] ?? null ) ? $parsed['metadata'] : array();
        $body     = trim( (string) ( $parsed['body'] ?? '' ) );
        $name     = sanitize_text_field( $metadata['name'] ?? ( $lock['name'] ?? '' ) );
        $slug     = sanitize_title( $metadata['slug'] ?? ( $lock['slug'] ?? $name ) );
        $dir_slug = sanitize_title( basename( $dir ) );

        if ( '' === $name || '' === $slug || '' === $body ) {
            return new WP_Error( 'wp_agent_skill_runtime_invalid', 'Local Skill runtime file must include name, slug, and playbook body.' );
        }

        if ( $slug !== $dir_slug ) {
            return new WP_Error( 'wp_agent_skill_runtime_slug', 'Local Skill runtime slug must match its directory name.' );
        }

        if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
            return new WP_Error( 'wp_agent_skill_size', 'Skill body exceeds the size limit.' );
        }

        $blocked = self::validate_body( $body );
        if ( is_wp_error( $blocked ) ) {
            return $blocked;
        }

        $status = sanitize_key( $lock['status'] ?? 'active' );
        if ( ! in_array( $status, array( 'active', 'archived' ), true ) ) {
            $status = 'active';
        }

        $version = (int) ( $metadata['version'] ?? ( $lock['wp_skill_version'] ?? ( $lock['version'] ?? 1 ) ) );
        if ( $version <= 0 ) {
            $version = 1;
        }

        $triggers = array();
        if ( ! empty( $metadata['triggers'] ) ) {
            $triggers = self::normalize_triggers( $metadata['triggers'] );
        } elseif ( ! empty( $lock['triggers'] ) ) {
            $triggers = self::normalize_triggers( $lock['triggers'] );
        }

        return array(
            'user_id'     => (int) $user_id,
            'slug'        => $slug,
            'name'        => $name,
            'description' => sanitize_textarea_field( $metadata['description'] ?? ( $lock['description'] ?? '' ) ),
            'triggers'    => $triggers,
            'permissions' => self::sanitize_permissions( $metadata['permissions'] ?? ( $lock['permissions'] ?? array() ) ),
            'body'        => $body,
            'visibility'  => 'site' === ( $metadata['visibility'] ?? ( $lock['visibility'] ?? 'private' ) ) ? 'site' : 'private',
            'status'      => $status,
            'version'     => $version,
            'lock'        => $lock,
        );
    }

    private static function installed_package_skill_data( $user_id, $dir ) {
        if ( ! is_dir( $dir ) || ! self::runtime_path_within_skills_dir( $dir ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_path', 'Installed package runtime path is invalid.' );
        }

        $lock = self::read_lock_file( $dir . '/.lock.json' );
        if ( ! $lock ) {
            return new WP_Error( 'wp_agent_skill_package_lock', 'Installed package lock is missing or invalid.' );
        }

        if ( 'active' !== ( $lock['status'] ?? '' ) ) {
            return new WP_Error( 'wp_agent_skill_package_status', 'Only active installed packages can be synced into the DB index.' );
        }

        $activated_by = (int) ( $lock['activated_by'] ?? 0 );
        $installed_by = (int) ( $lock['installed_by'] ?? 0 );
        if ( ( $activated_by > 0 && $activated_by !== (int) $user_id ) || ( $installed_by > 0 && $installed_by !== (int) $user_id ) ) {
            return new WP_Error( 'wp_agent_skill_package_owner', 'Installed package owner does not match the requested user.' );
        }

        $skill_md = is_readable( $dir . '/SKILL.md' ) ? (string) file_get_contents( $dir . '/SKILL.md' ) : '';
        if ( '' === trim( $skill_md ) ) {
            return new WP_Error( 'wp_agent_skill_package_file', 'Installed package SKILL.md is missing.' );
        }

        $parsed   = self::parse_skill_markdown( $skill_md );
        $metadata = is_array( $parsed['metadata'] ?? null ) ? $parsed['metadata'] : array();
        $body     = trim( (string) ( $parsed['body'] ?? '' ) );
        $name     = sanitize_text_field( $metadata['name'] ?? ( $lock['name'] ?? '' ) );
        $slug     = sanitize_title( $metadata['slug'] ?? ( $lock['slug'] ?? $name ) );
        $dir_slug = sanitize_title( basename( $dir ) );

        if ( '' === $name || '' === $slug || '' === $body ) {
            return new WP_Error( 'wp_agent_skill_package_invalid', 'Installed package must include name, slug, and playbook body.' );
        }

        if ( $slug !== $dir_slug ) {
            return new WP_Error( 'wp_agent_skill_package_slug', 'Installed package slug must match its directory name.' );
        }

        if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
            return new WP_Error( 'wp_agent_skill_size', 'Skill body exceeds the size limit.' );
        }

        $blocked = self::validate_body( $body );
        if ( is_wp_error( $blocked ) ) {
            return $blocked;
        }

        $triggers = array();
        if ( ! empty( $metadata['triggers'] ) ) {
            $triggers = self::normalize_triggers( $metadata['triggers'] );
        } elseif ( ! empty( $metadata['schedule_templates'] ) ) {
            $triggers = self::normalize_triggers( $metadata['schedule_templates'] );
        } elseif ( ! empty( $lock['schedule_templates'] ) ) {
            $triggers = self::normalize_triggers( $lock['schedule_templates'] );
        }

        $version = (int) ( $lock['wp_skill_version'] ?? 0 );
        if ( $version <= 0 ) {
            $version = (int) ( $lock['version'] ?? 1 );
        }
        if ( $version <= 0 ) {
            $version = 1;
        }

        return array(
            'user_id'     => (int) $user_id,
            'slug'        => $slug,
            'name'        => $name,
            'description' => sanitize_textarea_field( $metadata['description'] ?? ( $lock['description'] ?? '' ) ),
            'triggers'    => $triggers,
            'permissions' => self::sanitize_permissions( $metadata['permissions'] ?? ( $lock['permissions'] ?? array() ) ),
            'body'        => $body,
            'visibility'  => 'private',
            'version'     => $version,
            'lock'        => $lock,
        );
    }

    private static function recover_runtime_skill_by_slug( $user_id, $slug ) {
        $user_id = (int) $user_id;
        $slug    = sanitize_title( $slug );
        if ( $user_id <= 0 || '' === $slug ) {
            return null;
        }

        $local_dir   = self::local_skill_dir( $user_id, $slug );
        $package_dir = self::installed_dir() . '/' . $slug;
        $local_data  = null;
        $package_data = null;

        if ( is_dir( $local_dir ) ) {
            $data = self::local_runtime_skill_data( $user_id, $local_dir );
            if ( ! is_wp_error( $data ) ) {
                $local_data = $data;
            }
        }

        if ( is_dir( $package_dir ) ) {
            $data = self::installed_package_skill_data( $user_id, $package_dir );
            if ( ! is_wp_error( $data ) ) {
                $package_data = $data;
            }
        }

        if ( $local_data && $package_data ) {
            return null;
        }

        if ( $local_data ) {
            return self::restore_runtime_skill_data( $local_data, $local_dir . '/.lock.json', $local_data['status'] );
        }

        if ( $package_data ) {
            return self::restore_runtime_skill_data( $package_data, $package_dir . '/.lock.json', 'active' );
        }

        return null;
    }

    private static function discover_runtime_catalog_index( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        self::discover_local_runtime_catalog_index( $user_id );
        self::discover_installed_package_catalog_index( $user_id );
    }

    private static function discover_local_runtime_catalog_index( $user_id ) {
        $dir = self::local_user_dir( $user_id );
        if ( ! is_dir( $dir ) || ! self::runtime_path_within_skills_dir( $dir ) ) {
            return;
        }

        foreach ( scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }

            $skill_dir = $dir . '/' . $entry;
            if ( ! is_dir( $skill_dir ) ) {
                continue;
            }

            $data = self::local_runtime_skill_data( $user_id, $skill_dir );
            if ( is_wp_error( $data ) || self::get_by_slug( $user_id, $data['slug'], false ) ) {
                continue;
            }

            $conflict = self::installed_package_slug_conflict( $user_id, $data['slug'] );
            if ( is_wp_error( $conflict ) ) {
                continue;
            }

            self::restore_runtime_skill_data( $data, $skill_dir . '/.lock.json', $data['status'] );
        }
    }

    private static function discover_installed_package_catalog_index( $user_id ) {
        $dir = self::installed_dir();
        if ( ! is_dir( $dir ) || ! self::runtime_path_within_skills_dir( $dir ) ) {
            return;
        }

        foreach ( scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }

            $package_dir = $dir . '/' . $entry;
            if ( ! is_dir( $package_dir ) ) {
                continue;
            }

            $data = self::installed_package_skill_data( $user_id, $package_dir );
            if ( is_wp_error( $data ) || self::get_by_slug( $user_id, $data['slug'], false ) ) {
                continue;
            }

            $conflict = self::local_skill_slug_conflict( $user_id, $data['slug'] );
            if ( is_wp_error( $conflict ) ) {
                continue;
            }

            self::restore_runtime_skill_data( $data, $package_dir . '/.lock.json', 'active' );
        }
    }

    private static function restore_runtime_skill_data( $data, $lock_file, $status ) {
        global $wpdb;

        if ( ! is_array( $data ) || empty( $data['user_id'] ) || empty( $data['slug'] ) ) {
            return null;
        }

        $user_id = (int) $data['user_id'];
        $slug    = sanitize_title( $data['slug'] );
        $status  = sanitize_key( $status );
        $status  = in_array( $status, array( 'active', 'archived' ), true ) ? $status : 'active';
        $now     = current_time( 'mysql', true );

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id = %d AND slug = %s",
            $user_id,
            $slug
        ), ARRAY_A );

        if ( $existing ) {
            $updated = $wpdb->update(
                self::table(),
                array(
                    'name'        => $data['name'],
                    'description' => $data['description'],
                    'triggers'    => wp_json_encode( $data['triggers'] ),
                    'permissions' => wp_json_encode( self::sanitize_permissions( $data['permissions'] ?? array() ) ),
                    'body'        => sanitize_textarea_field( $data['body'] ),
                    'visibility'  => $data['visibility'],
                    'status'      => $status,
                    'version'     => $data['version'],
                    'updated_at'  => $now,
                ),
                array( 'id' => (int) $existing['id'] ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );
            if ( false === $updated ) {
                return null;
            }
            $skill_id = (int) $existing['id'];
        } else {
            $inserted = $wpdb->insert(
                self::table(),
                array(
                    'user_id'     => $user_id,
                    'slug'        => $slug,
                    'name'        => $data['name'],
                    'description' => $data['description'],
                    'triggers'    => wp_json_encode( $data['triggers'] ),
                    'permissions' => wp_json_encode( self::sanitize_permissions( $data['permissions'] ?? array() ) ),
                    'body'        => sanitize_textarea_field( $data['body'] ),
                    'visibility'  => $data['visibility'],
                    'status'      => $status,
                    'version'     => $data['version'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
            );
            if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) {
                return null;
            }
            $skill_id = (int) $wpdb->insert_id;
        }

        $lock = is_array( $data['lock'] ?? null ) ? $data['lock'] : array();
        $lock['status']            = $status;
        $lock['wp_skill_id']       = $skill_id;
        $lock['wp_skill_version']  = (int) $data['version'];
        $lock['auto_recovered_at'] = $now;
        self::write_json_file( $lock_file, $lock );

        return $skill_id;
    }

    private static function persist_local_skill( $skill, $source = array() ) {
        if ( ! is_array( $skill ) || empty( $skill['id'] ) || empty( $skill['user_id'] ) || empty( $skill['slug'] ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_invalid', 'A persisted Skill row is required before writing runtime files.' );
        }

        $dir   = self::local_skill_dir( (int) $skill['user_id'], $skill['slug'] );
        $ready = self::ensure_runtime_dir( $dir );
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }

        $source   = self::sanitize_local_source( $source );
        $skill_md = self::local_skill_markdown( $skill, $source );
        $write    = self::write_runtime_file( $dir, 'SKILL.md', $skill_md );
        if ( is_wp_error( $write ) ) {
            return $write;
        }

        $lock = array(
            'id'               => 'local-' . (int) $skill['user_id'] . '-' . sanitize_title( $skill['slug'] ),
            'status'           => sanitize_key( $skill['status'] ?? 'active' ),
            'kind'             => 'local',
            'slug'             => sanitize_title( $skill['slug'] ),
            'name'             => sanitize_text_field( $skill['name'] ?? '' ),
            'version'          => (int) ( $skill['version'] ?? 1 ),
            'description'      => sanitize_textarea_field( $skill['description'] ?? '' ),
            'triggers'         => self::string_list( $skill['triggers'] ?? array() ),
            'permissions'      => self::sanitize_permissions( $skill['permissions'] ?? array() ),
            'visibility'       => 'site' === ( $skill['visibility'] ?? 'private' ) ? 'site' : 'private',
            'source'           => $source,
            'files'            => array(
                array(
                    'path'   => 'SKILL.md',
                    'bytes'  => strlen( $skill_md ),
                    'sha256' => hash( 'sha256', $skill_md ),
                ),
            ),
            'wp_skill_id'      => (int) $skill['id'],
            'wp_skill_version' => (int) ( $skill['version'] ?? 1 ),
            'user_id'          => (int) $skill['user_id'],
            'body_sha256'      => hash( 'sha256', (string) ( $skill['body'] ?? '' ) ),
            'updated_at'       => current_time( 'mysql', true ),
        );

        $written = self::write_json_file( $dir . '/.lock.json', $lock );
        if ( is_wp_error( $written ) ) {
            return $written;
        }

        return array(
            'dir'        => $dir,
            'lock_file'  => $dir . '/.lock.json',
            'skill_file' => $dir . '/SKILL.md',
            'lock'       => $lock,
        );
    }

    private static function update_local_skill_status( $user_id, $slug, $status ) {
        $manifest = self::local_runtime_manifest( $user_id, $slug );
        if ( is_wp_error( $manifest ) ) {
            return false;
        }

        $lock               = $manifest['lock'];
        $lock['status']     = sanitize_key( $status );
        $lock['updated_at'] = current_time( 'mysql', true );
        if ( 'archived' === $lock['status'] ) {
            $lock['archived_at'] = $lock['updated_at'];
        }

        return self::write_json_file( $manifest['lock_file'], $lock );
    }

    private static function local_skill_markdown( $skill, $source ) {
        $lines = array(
            '---',
            'name: ' . self::frontmatter_scalar( $skill['name'] ?? '' ),
            'slug: ' . sanitize_title( $skill['slug'] ?? '' ),
            'version: ' . (int) ( $skill['version'] ?? 1 ),
            'description: ' . self::frontmatter_scalar( $skill['description'] ?? '' ),
            'visibility: ' . ( 'site' === ( $skill['visibility'] ?? 'private' ) ? 'site' : 'private' ),
        );

        $triggers = self::string_list( $skill['triggers'] ?? array() );
        if ( ! empty( $triggers ) ) {
            $lines[] = 'triggers:';
            foreach ( $triggers as $trigger ) {
                $lines[] = '  - ' . self::frontmatter_scalar( $trigger );
            }
        }

        $permissions = self::sanitize_permissions( $skill['permissions'] ?? array() );
        if ( ! empty( $permissions ) ) {
            $lines[] = 'permissions:';
            if ( ! empty( $permissions['tools'] ) ) {
                $lines[] = '  tools:';
                foreach ( $permissions['tools'] as $tool ) {
                    $lines[] = '    - ' . self::frontmatter_scalar( $tool );
                }
            }
            if ( array_key_exists( 'network', $permissions ) ) {
                $lines[] = '  network: ' . ( $permissions['network'] ? 'true' : 'false' );
            }
            if ( array_key_exists( 'code_execution', $permissions ) ) {
                $lines[] = '  code_execution: ' . ( $permissions['code_execution'] ? 'true' : 'false' );
            }
        }

        $lines[] = 'source:';
        foreach ( $source as $key => $value ) {
            if ( is_scalar( $value ) ) {
                $lines[] = '  ' . sanitize_key( $key ) . ': ' . self::frontmatter_scalar( $value );
            }
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = trim( (string) ( $skill['body'] ?? '' ) );

        return implode( "\n", $lines ) . "\n";
    }

    private static function sanitize_local_source( $source ) {
        if ( ! is_array( $source ) ) {
            $source = array();
        }

        $type = sanitize_key( $source['type'] ?? 'local' );
        if ( '' === $type ) {
            $type = 'local';
        }

        $out = array( 'type' => $type );
        foreach ( array( 'template_slug', 'package_slug', 'package_id' ) as $key ) {
            if ( ! empty( $source[ $key ] ) ) {
                $out[ $key ] = sanitize_text_field( (string) $source[ $key ] );
            }
        }
        foreach ( array( 'run_id', 'conversation_id' ) as $key ) {
            if ( ! empty( $source[ $key ] ) ) {
                $out[ $key ] = (int) $source[ $key ];
            }
        }
        if ( ! empty( $source['tools'] ) ) {
            $out['tools'] = self::string_list( $source['tools'] );
        }
        if ( ! empty( $source['message_ids'] ) && is_array( $source['message_ids'] ) ) {
            $out['message_ids'] = array_values( array_filter( array_map( 'absint', $source['message_ids'] ) ) );
        }
        return $out;
    }

    private static function local_skill_slug_conflict( $user_id, $slug ) {
        $manifest = self::local_runtime_manifest( $user_id, $slug );
        if ( is_wp_error( $manifest ) ) {
            return false;
        }

        $lock = is_array( $manifest['lock'] ?? null ) ? $manifest['lock'] : array();
        if ( 'local' !== ( $lock['kind'] ?? 'local' ) ) {
            return false;
        }

        return new WP_Error(
            'wp_agent_skill_slug_conflict',
            'A local Skill runtime mirror already uses this slug. Rename the local Skill or choose a package with a different slug before activating this package.'
        );
    }

    private static function installed_package_slug_conflict( $user_id, $slug ) {
        unset( $user_id );

        if ( ! self::active_installed_package_lock( $slug ) ) {
            return false;
        }

        return new WP_Error(
            'wp_agent_skill_slug_conflict',
            'An active installed Skill package already uses this slug. Local Skills cannot overwrite installed packages.'
        );
    }

    private static function frontmatter_scalar( $value ) {
        return trim( str_replace( array( "\r", "\n" ), ' ', sanitize_text_field( (string) $value ) ) );
    }

    private static function skill_draft_excerpt( $text, $limit = 240 ) {
        $text = wp_strip_all_tags( (string) $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = trim( sanitize_textarea_field( $text ) );
        if ( '' === $text ) {
            return '';
        }
        $limit = max( 40, (int) $limit );
        if ( mb_strlen( $text ) <= $limit ) {
            return $text;
        }
        return rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '...';
    }

    private static function skill_draft_title( $text ) {
        $text = self::skill_draft_excerpt( $text, 72 );
        $text = preg_replace( '/^(please|can you|could you|i need you to|help me)\s+/i', '', $text );
        $text = trim( $text, " \t\n\r\0\x0B.,:;!?\"'" );
        if ( '' === $text ) {
            return 'Run Skill Draft';
        }
        return mb_substr( ucwords( mb_strtolower( $text ) ), 0, 80 );
    }

    private static function dedupe_tool_sequence( $sequence ) {
        $out  = array();
        $seen = array();
        foreach ( $sequence as $step ) {
            $tool = sanitize_key( $step['tool'] ?? '' );
            if ( '' === $tool ) {
                continue;
            }
            $action = sanitize_key( $step['action'] ?? '' );
            $status = sanitize_key( $step['status'] ?? '' );
            $key    = $tool . '|' . $action . '|' . $status;
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[] = array(
                'tool'   => $tool,
                'action' => $action,
                'status' => $status,
            );
            if ( count( $out ) >= 20 ) {
                break;
            }
        }
        return $out;
    }

    private static function build_run_skill_draft_body( $run, $first_user, $assistant_notes, $tool_sequence, $observed_tools ) {
        $lines = array();

        $lines[] = '## Purpose';
        $lines[] = '';
        if ( '' !== $first_user ) {
            $lines[] = 'Repeat the workflow requested in the source run: "' . self::skill_draft_excerpt( $first_user, 320 ) . '"';
        } else {
            $lines[] = 'Repeat the useful workflow captured in the source WP Agent run.';
        }
        $lines[] = '';
        $lines[] = '## Source Run';
        $lines[] = '';
        $lines[] = '- Run ID: ' . (int) $run->id;
        $lines[] = '- Conversation ID: ' . (int) $run->conversation_id;
        $lines[] = '- Channel: ' . sanitize_key( $run->channel ?? 'webchat' );
        $lines[] = '- Status when drafted: ' . sanitize_key( $run->status ?? '' );

        if ( ! empty( $assistant_notes ) ) {
            $lines[] = '';
            $lines[] = '## Observed Outcome';
            $lines[] = '';
            foreach ( array_slice( $assistant_notes, 0, 4 ) as $note ) {
                $lines[] = '- ' . self::skill_draft_excerpt( $note, 260 );
            }
        }

        $lines[] = '';
        $lines[] = '## Tool Pattern';
        $lines[] = '';
        if ( empty( $tool_sequence ) ) {
            $lines[] = '- No tool calls were recorded. Treat this Skill as a planning checklist until a future run adds tool evidence.';
        } else {
            foreach ( $tool_sequence as $step ) {
                $label = '`' . sanitize_key( $step['tool'] ?? '' ) . '`';
                if ( '' !== ( $step['action'] ?? '' ) ) {
                    $label .= ' action `' . sanitize_key( $step['action'] ) . '`';
                }
                if ( '' !== ( $step['status'] ?? '' ) ) {
                    $label .= ' status `' . sanitize_key( $step['status'] ) . '`';
                }
                $lines[] = '- ' . $label;
            }
        }

        $lines[] = '';
        $lines[] = '## Workflow';
        $lines[] = '';
        $lines[] = '1. Reconfirm the user goal, scope, publishing policy, budget, and approval requirements before mutating WordPress data.';
        $lines[] = '2. Inspect current site state and recent WP Agent journal entries so repeated runs stay idempotent.';
        if ( in_array( 'web', $observed_tools, true ) ) {
            $lines[] = '3. Use approved web tools for research, keep source URLs, and avoid copying protected content.';
        } else {
            $lines[] = '3. Gather any missing context through approved read-only tools before writing content or changing site structure.';
        }
        $lines[] = '4. Execute the observed tool pattern only within the user-approved scope; prefer drafts and reversible changes.';
        $lines[] = '5. Run quality checks before approval, scheduling, or publishing when content is produced.';
        $lines[] = '6. Record decisions, created object IDs, source URLs, skipped work, and follow-up tasks in the journal.';

        $lines[] = '';
        $lines[] = '## Safety Rules';
        $lines[] = '';
        $lines[] = '- This Skill is instructions only. Do not embed executable code.';
        $lines[] = '- Publishing, destructive edits, settings changes, package activation, and Skill saves must use the normal WP Agent confirmation gates.';
        $lines[] = '- Public files must enter WordPress through Media Library or another approved import path, never by writing into the plugin directory.';

        return trim( implode( "\n", $lines ) ) . "\n";
    }

    private static function normalize_triggers( $triggers ) {
        if ( is_string( $triggers ) ) {
            $triggers = preg_split( '/[,\\n]+/', $triggers );
        }
        if ( ! is_array( $triggers ) ) {
            return array();
        }

        $out = array();
        foreach ( $triggers as $trigger ) {
            $trigger = sanitize_text_field( (string) $trigger );
            if ( '' !== $trigger ) {
                $out[] = mb_substr( $trigger, 0, 80 );
            }
        }
        return array_values( array_unique( array_slice( $out, 0, 20 ) ) );
    }

    private static function template_summary( $template ) {
        unset( $template['body'] );
        return $template;
    }

    private static function validate_body( $body ) {
        $dangerous = array( '<?php', '<script', '#!/bin/', '#!/usr/bin/', 'proc_open(', 'shell_exec(', 'passthru(', 'system(' );
        $lower = strtolower( $body );
        foreach ( $dangerous as $needle ) {
            if ( false !== strpos( $lower, strtolower( $needle ) ) ) {
                return new WP_Error( 'wp_agent_skill_executable', 'Skills are instructions only; executable code patterns are not allowed.' );
            }
        }
        return true;
    }

    private static function parse_frontmatter( $frontmatter ) {
        $metadata = array();
        $current  = '';
        $nested   = '';

        foreach ( preg_split( "/\\r?\\n/", (string) $frontmatter ) as $line ) {
            if ( '' === trim( $line ) || 0 === strpos( trim( $line ), '#' ) ) {
                continue;
            }

            if ( preg_match( '/^([A-Za-z0-9_-]+):\\s*(.*)$/', $line, $m ) ) {
                $current = sanitize_key( $m[1] );
                $nested  = '';
                $value   = trim( $m[2] );
                $metadata[ $current ] = '' === $value ? array() : self::parse_frontmatter_value( $value );
                continue;
            }

            if ( '' === $current ) {
                continue;
            }

            if ( '' !== $nested && preg_match( '/^\\s{4,}-\\s*(.+)$/', $line, $m ) ) {
                if ( ! isset( $metadata[ $current ][ $nested ] ) || ! is_array( $metadata[ $current ][ $nested ] ) ) {
                    $metadata[ $current ][ $nested ] = array();
                }
                $metadata[ $current ][ $nested ][] = self::parse_frontmatter_value( trim( $m[1] ) );
                continue;
            }

            if ( preg_match( '/^\\s+-\\s*(.+)$/', $line, $m ) ) {
                if ( ! is_array( $metadata[ $current ] ) ) {
                    $metadata[ $current ] = array();
                }
                $metadata[ $current ][] = self::parse_frontmatter_value( trim( $m[1] ) );
                continue;
            }

            if ( preg_match( '/^\\s+([A-Za-z0-9_-]+):\\s*(.*)$/', $line, $m ) ) {
                if ( ! is_array( $metadata[ $current ] ) ) {
                    $metadata[ $current ] = array();
                }
                $nested = sanitize_key( $m[1] );
                $value  = trim( $m[2] );
                $metadata[ $current ][ $nested ] = '' === $value ? array() : self::parse_frontmatter_value( $value );
                continue;
            }
        }

        return $metadata;
    }

    private static function parse_frontmatter_value( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        if ( '[' === substr( $value, 0, 1 ) && ']' === substr( $value, -1 ) ) {
            $inner = trim( substr( $value, 1, -1 ) );
            if ( '' === $inner ) {
                return array();
            }
            return array_map( array( __CLASS__, 'parse_frontmatter_value' ), preg_split( '/\\s*,\\s*/', $inner ) );
        }

        $lower = strtolower( $value );
        if ( 'true' === $lower ) {
            return true;
        }
        if ( 'false' === $lower ) {
            return false;
        }
        if ( 'null' === $lower ) {
            return null;
        }

        if ( preg_match( '/\\A([\'"])(.*)\\1\\z/s', $value, $m ) ) {
            return $m[2];
        }

        return $value;
    }

    private static function quarantine_package( $user_id, $package ) {
        $files = self::normalize_package_files( $package['files'] ?? array() );
        if ( is_wp_error( $files ) ) {
            return $files;
        }

        $skill_md = (string) ( $files['SKILL.md'] ?? '' );
        $parsed   = self::parse_skill_markdown( $skill_md );
        $metadata = $parsed['metadata'];
        $name     = sanitize_text_field( $metadata['name'] ?? '' );
        $slug     = sanitize_title( $metadata['slug'] ?? $name );

        if ( '' === $name || '' === $slug || '' === trim( $parsed['body'] ) ) {
            return new WP_Error( 'wp_agent_skill_package_invalid', 'SKILL.md must include a name and a non-empty Markdown body.' );
        }

        if ( strlen( $parsed['body'] ) > self::MAX_BODY_BYTES ) {
            return new WP_Error( 'wp_agent_skill_size', 'Skill body exceeds the size limit.' );
        }

        $blocked = self::validate_body( $parsed['body'] );
        if ( is_wp_error( $blocked ) ) {
            return $blocked;
        }

        $warnings = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $package['warnings'] ?? array() ) ) ) );
        $permissions = self::sanitize_permissions( $metadata['permissions'] ?? array() );
        if ( ! empty( $permissions['code_execution'] ) ) {
            $warnings[] = 'Package requests code_execution permission. Activation still stores only a non-executable Markdown skill.';
        }
        if ( ! empty( $permissions['network'] ) ) {
            $warnings[] = 'Package requests network access; any future workflow must use approved network tools.';
        }

        $hash = substr( hash( 'sha256', wp_json_encode( $package['source'] ) . '|' . hash( 'sha256', $skill_md ) ), 0, 12 );
        $id   = self::sanitize_package_id( $slug . '-' . $hash );
        $dir  = self::quarantine_dir() . '/' . $id;

        self::delete_runtime_dir( $dir );
        $created = self::ensure_runtime_dir( $dir );
        if ( is_wp_error( $created ) ) {
            return $created;
        }

        $files_written = array();
        foreach ( $files as $rel => $bytes ) {
            $write = self::write_runtime_file( $dir, $rel, (string) $bytes );
            if ( is_wp_error( $write ) ) {
                return $write;
            }
            $files_written[] = array(
                'path'   => $rel,
                'bytes'  => strlen( (string) $bytes ),
                'sha256' => hash( 'sha256', (string) $bytes ),
            );
        }

        $lock = array(
            'id'                 => $id,
            'status'             => 'quarantined',
            'slug'               => $slug,
            'name'               => $name,
            'version'            => sanitize_text_field( $metadata['version'] ?? '0.0.0' ),
            'description'        => sanitize_textarea_field( $metadata['description'] ?? '' ),
            'permissions'        => $permissions,
            'schedule_templates' => self::string_list( $metadata['schedule_templates'] ?? array() ),
            'source'             => $package['source'],
            'files'              => $files_written,
            'warnings'           => array_values( array_unique( $warnings ) ),
            'fetched_at'         => current_time( 'mysql', true ),
            'installed_by'       => (int) $user_id,
            'body_sha256'        => hash( 'sha256', $parsed['body'] ),
        );
        self::write_json_file( $dir . '/.lock.json', $lock );

        WPAgent::audit_log( $user_id, 'skill_package_quarantined', array(
            'slug'   => $slug,
            'source' => $package['source'],
        ), 'admin' );

        return array(
            'success'       => true,
            'quarantine_id' => $id,
            'summary'       => self::lock_summary( $lock ),
            'lock_file'     => $dir . '/.lock.json',
        );
    }

    private static function normalize_package_files( $files ) {
        if ( ! is_array( $files ) || empty( $files ) ) {
            return new WP_Error( 'wp_agent_skill_package_files', 'Skill package must include files.' );
        }

        if ( count( $files ) > self::MAX_PACKAGE_FILES ) {
            return new WP_Error( 'wp_agent_skill_github_file_count', 'Skill package has too many files.' );
        }

        $normalized  = array();
        $total_bytes = 0;
        foreach ( $files as $rel => $bytes ) {
            $rel = self::normalize_package_path( $rel );
            if ( '' === $rel ) {
                return new WP_Error( 'wp_agent_skill_runtime_path', 'Invalid package file path.' );
            }

            $bytes = (string) $bytes;
            if ( strlen( $bytes ) > self::MAX_PACKAGE_FILE_BYTES ) {
                return new WP_Error( 'wp_agent_skill_github_size', 'Skill package file exceeds the size limit: ' . $rel );
            }

            $total_bytes += strlen( $bytes );
            if ( $total_bytes > self::MAX_PACKAGE_TOTAL_BYTES ) {
                return new WP_Error( 'wp_agent_skill_github_total_size', 'Skill package exceeds the total size limit.' );
            }

            $normalized[ $rel ] = $bytes;
        }

        if ( ! isset( $normalized['SKILL.md'] ) ) {
            return new WP_Error( 'wp_agent_skill_package_invalid', 'Skill package must include SKILL.md.' );
        }

        return $normalized;
    }

    private static function parse_github_repository( $value ) {
        $value = trim( (string) $value );
        $value = preg_replace( '/\\.git$/', '', $value );
        if ( preg_match( '#github\\.com[:/]([^/]+)/([^/]+)#i', $value, $m ) ) {
            return array(
                'owner' => self::sanitize_github_component( $m[1] ),
                'repo'  => self::sanitize_github_component( $m[2] ),
            );
        }
        if ( preg_match( '#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $value, $m ) ) {
            return array(
                'owner' => self::sanitize_github_component( $m[1] ),
                'repo'  => self::sanitize_github_component( $m[2] ),
            );
        }
        return new WP_Error( 'wp_agent_skill_repo', 'Repository must be owner/repo or a GitHub repository URL.' );
    }

    private static function sanitize_github_component( $value ) {
        return trim( preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $value ), '.-' );
    }

    private static function sanitize_git_ref( $ref ) {
        $ref = trim( (string) $ref );
        if ( '' === $ref || strlen( $ref ) > 120 || preg_match( '#(^|/)\\.\\.?($|/)#', $ref ) ) {
            return '';
        }
        return preg_replace( '/[^A-Za-z0-9._\\/-]/', '', $ref );
    }

    private static function normalize_package_path( $path ) {
        $path = str_replace( '\\', '/', (string) $path );
        $path = trim( preg_replace( '#/+#', '/', $path ), '/' );
        if ( '' === $path || false !== strpos( $path, "\0" ) ) {
            return '';
        }
        $parts = array();
        foreach ( explode( '/', $path ) as $part ) {
            if ( '' === $part || '.' === $part || '..' === $part ) {
                return '';
            }
            $parts[] = sanitize_file_name( $part );
        }
        return implode( '/', $parts );
    }

    private static function skill_file_path( $package_path ) {
        return preg_match( '#(^|/)SKILL\\.md$#i', $package_path ) ? $package_path : trailingslashit( $package_path ) . 'SKILL.md';
    }

    private static function github_token( $data ) {
        $token = trim( (string) ( $data['github_token'] ?? '' ) );
        if ( '' === $token && class_exists( 'WPAgent' ) ) {
            $configured = WPAgent::get_option( 'github_token', '' );
            if ( '' !== $configured ) {
                $token = WPAgent::decrypt( $configured );
            }
        }
        return $token;
    }

    private static function github_fetch_file( $owner, $repo, $path, $ref, $token, $allow_missing = false ) {
        $data = self::github_api_get( $owner, $repo, $path, $ref, $token, $allow_missing );
        if ( null === $data ) {
            return null;
        }
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( ! is_array( $data ) || 'file' !== ( $data['type'] ?? '' ) || empty( $data['content'] ) ) {
            return new WP_Error( 'wp_agent_skill_github_file', 'GitHub path is not a readable file: ' . $path );
        }
        $body = base64_decode( preg_replace( '/\\s+/', '', (string) $data['content'] ), true );
        if ( false === $body ) {
            return new WP_Error( 'wp_agent_skill_github_decode', 'Could not decode GitHub file content: ' . $path );
        }
        if ( strlen( $body ) > self::MAX_PACKAGE_FILE_BYTES ) {
            return new WP_Error( 'wp_agent_skill_github_size', 'GitHub file exceeds the size limit: ' . $path );
        }
        return array(
            'body' => $body,
            'sha'  => sanitize_text_field( $data['sha'] ?? '' ),
            'size' => strlen( $body ),
        );
    }

    private static function github_collect_dir( $owner, $repo, $path, $ref, $token, $rel_base, &$files, &$total_bytes ) {
        $data = self::github_api_get( $owner, $repo, $path, $ref, $token, true );
        if ( null === $data ) {
            return true;
        }
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( ! is_array( $data ) || ! isset( $data[0] ) ) {
            return true;
        }

        foreach ( $data as $item ) {
            if ( count( $files ) >= self::MAX_PACKAGE_FILES ) {
                return new WP_Error( 'wp_agent_skill_github_file_count', 'GitHub skill package has too many files.' );
            }
            $name = sanitize_file_name( $item['name'] ?? '' );
            if ( '' === $name || '.' === substr( $name, 0, 1 ) ) {
                continue;
            }
            $remote_path = (string) ( $item['path'] ?? '' );
            $rel_path    = self::normalize_package_path( trailingslashit( $rel_base ) . $name );
            if ( '' === $remote_path || '' === $rel_path ) {
                continue;
            }

            if ( 'dir' === ( $item['type'] ?? '' ) ) {
                $nested = self::github_collect_dir( $owner, $repo, $remote_path, $ref, $token, $rel_path, $files, $total_bytes );
                if ( is_wp_error( $nested ) ) {
                    return $nested;
                }
                continue;
            }

            if ( 'file' !== ( $item['type'] ?? '' ) ) {
                continue;
            }

            $file = self::github_fetch_file( $owner, $repo, $remote_path, $ref, $token );
            if ( is_wp_error( $file ) ) {
                return $file;
            }
            $total_bytes += strlen( $file['body'] );
            if ( $total_bytes > self::MAX_PACKAGE_TOTAL_BYTES ) {
                return new WP_Error( 'wp_agent_skill_github_total_size', 'GitHub skill package exceeds the total size limit.' );
            }
            $files[ $rel_path ] = $file['body'];
        }

        return true;
    }

    /**
     * Search a GitHub repository for installable skills.
     *
     * Lists the skill directories under a base path (default `skills/`) in the
     * repository, reads each SKILL.md frontmatter for its name, description,
     * triggers, and permissions, and optionally filters by a query string. The
     * result is read-only discovery data; installing still goes through the
     * normal quarantine flow.
     *
     * @param array $data { repository, ref, base_path, query, github_token, limit }
     * @return array|WP_Error { repository, ref, base_path, count, skills[] }
     */
    public static function search_github( $data ) {
        $defaults       = self::github_store_defaults();
        $repository_arg = trim( (string) ( $data['repository'] ?? '' ) );
        $ref_arg        = trim( (string) ( $data['ref'] ?? '' ) );
        $base_path_arg  = trim( (string) ( $data['base_path'] ?? '' ) );
        $query          = trim( (string) ( $data['query'] ?? '' ) );
        $limit          = max( 1, min( (int) ( $data['limit'] ?? 50 ), 100 ) );

        $repo = self::parse_github_repository( '' !== $repository_arg ? $repository_arg : ( $defaults['repository'] ?? '' ) );
        if ( is_wp_error( $repo ) ) {
            return $repo;
        }
        if ( empty( $repo['owner'] ) || empty( $repo['repo'] ) ) {
            return new WP_Error( 'wp_agent_skill_search_repo', 'A GitHub repository (owner/repo) is required to search.' );
        }

        $ref   = '' !== $ref_arg ? self::sanitize_git_ref( $ref_arg ) : ( $defaults['ref'] ?? 'main' );
        $ref   = '' !== $ref ? $ref : 'main';
        $token = self::github_token( $data );

        // Determine the directory to enumerate. Default to a top-level `skills/`
        // folder; fall back to the repository root when that does not exist.
        $base_path = '' !== $base_path_arg ? self::normalize_package_path( $base_path_arg ) : 'skills';
        $listing   = self::github_api_get( $repo['owner'], $repo['repo'], $base_path, $ref, $token, true );
        if ( is_wp_error( $listing ) ) {
            return $listing;
        }
        if ( null === $listing ) {
            // No `skills/` directory — list the repository root instead.
            $base_path = '';
            $listing   = self::github_api_get( $repo['owner'], $repo['repo'], '', $ref, $token, true );
            if ( is_wp_error( $listing ) ) {
                return $listing;
            }
        }
        if ( ! is_array( $listing ) ) {
            return new WP_Error( 'wp_agent_skill_search_listing', 'Could not read the repository contents.' );
        }

        // Candidate skill directories from the listing.
        $dirs = array();
        foreach ( $listing as $entry ) {
            if ( is_array( $entry ) && 'dir' === ( $entry['type'] ?? '' ) && ! empty( $entry['name'] ) ) {
                $dirs[] = (string) $entry['name'];
            }
        }

        $needle  = '' !== $query ? mb_strtolower( $query ) : '';
        $skills  = array();
        $scanned = 0;
        foreach ( $dirs as $name ) {
            // Cap how many directories we read to keep the request bounded.
            if ( $scanned >= 60 ) {
                break;
            }
            $scanned++;

            $skill_path = '' !== $base_path ? $base_path . '/' . $name : $name;
            $md_path    = self::skill_file_path( $skill_path );
            $file       = self::github_fetch_file( $repo['owner'], $repo['repo'], $md_path, $ref, $token, true );
            if ( is_wp_error( $file ) || null === $file ) {
                continue; // not a skill directory
            }

            $parsed       = self::parse_skill_markdown( $file['body'] );
            $meta         = is_array( $parsed['metadata'] ?? null ) ? $parsed['metadata'] : array();
            $skill_name   = sanitize_text_field( (string) ( $meta['name'] ?? $name ) );
            $skill_slug   = sanitize_title( (string) ( $meta['slug'] ?? $name ) );
            $description  = sanitize_text_field( (string) ( $meta['description'] ?? '' ) );
            $triggers     = self::string_list( $meta['triggers'] ?? array() );
            $permissions  = self::sanitize_permissions( $meta['permissions'] ?? array() );
            $tools        = isset( $permissions['tools'] ) && is_array( $permissions['tools'] ) ? $permissions['tools'] : array();

            // Filter by query across name/slug/description/triggers/tools.
            if ( '' !== $needle ) {
                $haystack = mb_strtolower( implode( ' ', array_merge(
                    array( $skill_name, $skill_slug, $description ),
                    $triggers,
                    $tools
                ) ) );
                if ( false === mb_strpos( $haystack, $needle ) ) {
                    continue;
                }
            }

            $skills[] = array(
                'name'        => $skill_name,
                'slug'        => $skill_slug,
                'description' => $description,
                'triggers'    => array_slice( $triggers, 0, 8 ),
                'tools'       => array_slice( $tools, 0, 12 ),
                'skill_path'  => $skill_path,
            );

            if ( count( $skills ) >= $limit ) {
                break;
            }
        }

        return array(
            'repository' => $repo['owner'] . '/' . $repo['repo'],
            'ref'        => $ref,
            'base_path'  => $base_path,
            'query'      => $query,
            'count'      => count( $skills ),
            'skills'     => $skills,
        );
    }

    private static function github_api_get( $owner, $repo, $path, $ref, $token, $allow_missing = false ) {
        $url = 'https://api.github.com/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo )
            . '/contents/' . self::encode_github_path( $path )
            . '?ref=' . rawurlencode( $ref );
        $headers = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WP-Agent-Skills',
        );
        if ( '' !== $token ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $response = wp_remote_get( $url, array(
            'timeout' => 20,
            'headers' => $headers,
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 404 === $code && $allow_missing ) {
            return null;
        }
        if ( $code < 200 || $code >= 300 ) {
            $message = wp_remote_retrieve_response_message( $response );
            return new WP_Error( 'wp_agent_skill_github_http', 'GitHub API request failed: HTTP ' . $code . ( $message ? ' ' . $message : '' ) );
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( null === $data ) {
            return new WP_Error( 'wp_agent_skill_github_json', 'GitHub API returned invalid JSON.' );
        }
        return $data;
    }

    private static function encode_github_path( $path ) {
        return implode( '/', array_map( 'rawurlencode', explode( '/', trim( (string) $path, '/' ) ) ) );
    }

    private static function sanitize_permissions( $permissions ) {
        if ( ! is_array( $permissions ) ) {
            return array();
        }
        $out = array();
        if ( isset( $permissions['tools'] ) ) {
            $out['tools'] = self::string_list( $permissions['tools'] );
        }
        foreach ( array( 'network', 'code_execution' ) as $key ) {
            if ( array_key_exists( $key, $permissions ) ) {
                $out[ $key ] = (bool) $permissions[ $key ];
            }
        }
        return $out;
    }

    private static function string_list( $value ) {
        if ( is_string( $value ) ) {
            $value = preg_split( '/[,\\n]+/', $value );
        }
        if ( ! is_array( $value ) ) {
            return array();
        }
        $out = array();
        foreach ( $value as $item ) {
            $item = sanitize_text_field( (string) $item );
            if ( '' !== $item ) {
                $out[] = $item;
            }
        }
        return array_values( array_unique( $out ) );
    }

    private static function lock_summary( $lock ) {
        return array(
            'id'                 => $lock['id'] ?? '',
            'status'             => $lock['status'] ?? '',
            'slug'               => $lock['slug'] ?? '',
            'name'               => $lock['name'] ?? '',
            'version'            => $lock['version'] ?? '',
            'description'        => $lock['description'] ?? '',
            'permissions'        => $lock['permissions'] ?? array(),
            'schedule_templates' => $lock['schedule_templates'] ?? array(),
            'source'             => $lock['source'] ?? array(),
            'warnings'           => $lock['warnings'] ?? array(),
            'fetched_at'         => $lock['fetched_at'] ?? '',
            'activated_at'       => $lock['activated_at'] ?? '',
            'rolled_back_at'     => $lock['rolled_back_at'] ?? '',
            'rollback_from'      => $lock['rollback_from'] ?? '',
            'pinned'             => ! empty( $lock['pinned'] ),
            'pinned_at'          => $lock['pinned_at'] ?? '',
            'pinned_by'          => (int) ( $lock['pinned_by'] ?? 0 ),
            'unpinned_at'        => $lock['unpinned_at'] ?? '',
            'unpinned_by'        => (int) ( $lock['unpinned_by'] ?? 0 ),
            'wp_skill_id'        => (int) ( $lock['wp_skill_id'] ?? 0 ),
            'wp_skill_version'   => (int) ( $lock['wp_skill_version'] ?? 0 ),
        );
    }

    private static function pinned_package_error( $lock, $action ) {
        if ( ! is_array( $lock ) || empty( $lock['pinned'] ) ) {
            return false;
        }

        return new WP_Error(
            'wp_agent_skill_package_pinned',
            'This Skill package is pinned. Unpin it or use force to ' . sanitize_text_field( (string) $action ) . '.'
        );
    }

    private static function preserve_pin_state( $lock, $previous_lock, $force ) {
        if ( ! is_array( $previous_lock ) || empty( $previous_lock['pinned'] ) ) {
            $lock['pinned'] = false;
            unset( $lock['pinned_at'], $lock['pinned_by'], $lock['unpinned_at'], $lock['unpinned_by'] );
            return $lock;
        }

        if ( $force ) {
            $lock['pinned']     = true;
            $lock['pinned_at']  = $previous_lock['pinned_at'] ?? current_time( 'mysql', true );
            $lock['pinned_by']  = (int) ( $previous_lock['pinned_by'] ?? 0 );
            $lock['updated_at'] = current_time( 'mysql', true );
            unset( $lock['unpinned_at'], $lock['unpinned_by'] );
        }

        return $lock;
    }

    private static function skills_dir() {
        return WPAgent_Sandbox::runtime_area_dir( 'skills' );
    }

    private static function local_dir() {
        return self::skills_dir() . '/local';
    }

    private static function local_user_dir( $user_id ) {
        return self::local_dir() . '/user-' . max( 1, (int) $user_id );
    }

    private static function local_skill_dir( $user_id, $slug ) {
        return self::local_user_dir( $user_id ) . '/' . sanitize_title( $slug );
    }

    private static function quarantine_dir() {
        return self::skills_dir() . '/quarantine';
    }

    private static function installed_dir() {
        return self::skills_dir() . '/installed';
    }

    private static function rollback_dir() {
        return self::skills_dir() . '/rollback';
    }

    private static function installed_lock( $slug ) {
        $slug = sanitize_title( $slug );
        if ( '' === $slug ) {
            return new WP_Error( 'wp_agent_skill_package_slug', 'An installed package slug is required.' );
        }

        $lock = self::read_lock_file( self::installed_dir() . '/' . $slug . '/.lock.json' );
        if ( ! $lock ) {
            return new WP_Error( 'wp_agent_skill_package_missing', 'Installed skill package was not found.' );
        }

        return $lock;
    }

    private static function active_installed_package_lock( $slug ) {
        $lock = self::installed_lock( $slug );
        if ( is_wp_error( $lock ) || 'active' !== ( $lock['status'] ?? '' ) ) {
            return null;
        }

        return $lock;
    }

    private static function next_rollback_dir( $slug ) {
        $slug = sanitize_title( $slug );
        $base = self::rollback_dir() . '/' . $slug;
        $id   = gmdate( 'YmdHis' );
        $dir  = $base . '/' . $id;
        $i    = 2;

        while ( is_dir( $dir ) ) {
            $dir = $base . '/' . $id . '-' . $i;
            $i++;
        }

        return $dir;
    }

    private static function sanitize_package_id( $id ) {
        return preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $id ) );
    }

    private static function ensure_runtime_dir( $dir ) {
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_dir', 'Could not create private skills runtime directory.' );
        }
        @chmod( $dir, 0700 );
        return true;
    }

    private static function write_runtime_file( $root, $rel, $bytes ) {
        $rel = self::normalize_package_path( $rel );
        if ( '' === $rel ) {
            return new WP_Error( 'wp_agent_skill_runtime_path', 'Invalid package file path.' );
        }
        $target = trailingslashit( $root ) . $rel;
        $dir    = dirname( $target );
        $ready  = self::ensure_runtime_dir( $dir );
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        if ( false === file_put_contents( $target, $bytes ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_write', 'Could not write private skill package file.' );
        }
        @chmod( $target, 0600 );
        return true;
    }

    private static function write_json_file( $path, $data ) {
        $ready = self::ensure_runtime_dir( dirname( $path ) );
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        if ( false === file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_write', 'Could not write private skill runtime metadata.' );
        }
        @chmod( $path, 0600 );
        return true;
    }

    private static function read_lock_file( $path ) {
        if ( ! is_readable( $path ) ) {
            return null;
        }
        $data = json_decode( (string) file_get_contents( $path ), true );
        return is_array( $data ) ? $data : null;
    }

    private static function delete_runtime_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return true;
        }
        if ( ! self::runtime_path_within_skills_dir( $dir ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_path', 'Refusing to delete outside the private skills runtime directory.' );
        }
        $real = realpath( $dir );
        $it   = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $it as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $real );
        return true;
    }

    private static function copy_runtime_dir( $src, $dst ) {
        if ( ! is_dir( $src ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_missing', 'Source runtime directory is missing.' );
        }
        if ( ! self::runtime_path_within_skills_dir( $src ) || ! self::runtime_path_within_skills_dir( $dst, true ) ) {
            return new WP_Error( 'wp_agent_skill_runtime_path', 'Refusing to copy outside the private skills runtime directory.' );
        }
        $ready = self::ensure_runtime_dir( $dst );
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $it as $item ) {
            $rel    = substr( $item->getPathname(), strlen( $src ) + 1 );
            $target = trailingslashit( $dst ) . $rel;
            if ( $item->isDir() ) {
                $ready = self::ensure_runtime_dir( $target );
                if ( is_wp_error( $ready ) ) {
                    return $ready;
                }
            } else {
                $ready = self::ensure_runtime_dir( dirname( $target ) );
                if ( is_wp_error( $ready ) ) {
                    return $ready;
                }
                if ( ! copy( $item->getPathname(), $target ) ) {
                    return new WP_Error( 'wp_agent_skill_runtime_copy', 'Could not copy skill package file.' );
                }
                @chmod( $target, 0600 );
            }
        }
        return true;
    }

    private static function runtime_path_within_skills_dir( $path, $allow_missing = false ) {
        $ready = self::ensure_runtime_dir( self::skills_dir() );
        if ( is_wp_error( $ready ) ) {
            return false;
        }

        $root = realpath( self::skills_dir() );
        if ( ! $root ) {
            return false;
        }

        $candidate = self::runtime_compare_path( $path, $allow_missing );
        if ( '' === $candidate ) {
            return false;
        }

        $root = untrailingslashit( wp_normalize_path( $root ) );
        if ( $candidate === $root ) {
            return false;
        }

        return 0 === strpos( trailingslashit( $candidate ), trailingslashit( $root ) );
    }

    private static function runtime_compare_path( $path, $allow_missing ) {
        $path = wp_normalize_path( (string) $path );
        if ( '' === $path || false !== strpos( $path, "\0" ) || '/' !== substr( $path, 0, 1 ) ) {
            return '';
        }

        $real = realpath( $path );
        if ( false !== $real ) {
            return untrailingslashit( wp_normalize_path( $real ) );
        }

        if ( ! $allow_missing ) {
            return '';
        }

        $tail   = array();
        $cursor = untrailingslashit( $path );
        while ( '' !== $cursor && dirname( $cursor ) !== $cursor ) {
            $tail[] = basename( $cursor );
            $cursor = dirname( $cursor );
            $real   = realpath( $cursor );
            if ( false !== $real ) {
                return untrailingslashit( wp_normalize_path( $real ) . '/' . implode( '/', array_reverse( $tail ) ) );
            }
        }

        return '';
    }
}
