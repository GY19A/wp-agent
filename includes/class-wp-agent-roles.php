<?php
/**
 * Bounded WordPress identity for the agent.
 *
 * The agent operates under its OWN dedicated WordPress user ('wp-agent') on a
 * custom 'wp_agent' role whose capabilities are governed by a 4-value operating
 * mode. This role's cap set is a HARD CEILING on what the built-in agent (and
 * the future MCP interface) can do — the requesting human is only an access
 * gate + audit trail; the agent always acts as the wp-agent user.
 *
 * Modes (option 'wp_agent_mode', default 'author'):
 *   - author        : draft/edit content, NO publishing.
 *   - editor        : author + publish + manage others' content.
 *   - administrator : editor + site/option/user/WooCommerce management.
 *   - root          : every cap on the built-in administrator role, minus the
 *                     NEVER_GRANT set (no code execution / executable writes).
 *
 * NEVER_GRANT caps are excluded from EVERY mode, including root: the public
 * WordPress surface NEVER lets the agent execute code or write executable files.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Roles {

    /** @var string Custom role slug. */
    const ROLE = 'wp_agent';

    /** @var string Dedicated agent user login. */
    const USER_LOGIN = 'wp-agent';

    /**
     * Caps shared by author and every richer mode. No publish_* caps.
     *
     * @var string[]
     */
    const CAPS_AUTHOR = array(
        'read',
        'upload_files',
        'manage_categories',
        'moderate_comments',
        'edit_posts',
        'edit_others_posts',
        'edit_published_posts',
        'delete_posts',
        'edit_pages',
        'edit_others_pages',
        'edit_published_pages',
        'delete_pages',
    );

    /**
     * Editor-only additions (publishing + managing others' content).
     *
     * @var string[]
     */
    const CAPS_EDITOR_EXTRA = array(
        'publish_posts',
        'publish_pages',
        'delete_others_posts',
        'delete_published_posts',
        'delete_others_pages',
        'delete_published_pages',
    );

    /**
     * Administrator-only additions (site/option/user/WooCommerce management).
     *
     * @var string[]
     */
    const CAPS_ADMINISTRATOR_EXTRA = array(
        'manage_options',
        'edit_theme_options',
        'manage_links',
        'list_users',
        'create_users',
        'edit_users',
        'manage_woocommerce',
    );

    /**
     * Capabilities NEVER granted to the agent in ANY mode, including root.
     *
     * These are the code-execution / executable-write surfaces. The public
     * WordPress agent must never be able to run code or modify plugin/theme/core
     * files, so author/editor/administrator must NOT include any of these, and
     * root must strip them from the administrator cap set.
     *
     * @var string[]
     */
    const NEVER_GRANT = array(
        'edit_files',
        'edit_plugins',
        'edit_themes',
        'edit_dashboard',
        'install_plugins',
        'install_themes',
        'install_languages',
        'update_core',
        'update_plugins',
        'update_themes',
        'delete_plugins',
        'delete_themes',
        'unfiltered_html',
        'unfiltered_upload',
    );

    /**
     * Register hooks so changes to the mode option re-apply the role caps.
     *
     * @return void
     */
    public static function init() {
        add_action( 'update_option_wp_agent_mode', array( __CLASS__, 'on_mode_change' ), 10, 2 );
        add_action( 'add_option_wp_agent_mode', array( __CLASS__, 'on_add_mode' ), 10, 2 );
    }

    /**
     * Build the capability map (cap => true) for a given mode.
     *
     * @param string $mode One of author|editor|administrator|root.
     * @return array<string,bool>
     */
    public static function caps_for( $mode ) {
        $mode = self::clamp_mode( $mode );

        if ( 'root' === $mode ) {
            return self::root_caps();
        }

        $caps = self::CAPS_AUTHOR;

        if ( in_array( $mode, array( 'editor', 'administrator' ), true ) ) {
            $caps = array_merge( $caps, self::CAPS_EDITOR_EXTRA );
        }

        if ( 'administrator' === $mode ) {
            $caps = array_merge( $caps, self::CAPS_ADMINISTRATOR_EXTRA );
        }

        $map = array();
        foreach ( $caps as $cap ) {
            // Defensive: never grant a NEVER_GRANT cap even if listed above.
            if ( in_array( $cap, self::NEVER_GRANT, true ) ) {
                continue;
            }
            $map[ $cap ] = true;
        }

        return $map;
    }

    /**
     * Root cap set: every cap that is true on the built-in administrator role,
     * minus every cap in NEVER_GRANT.
     *
     * @return array<string,bool>
     */
    private static function root_caps() {
        $admin = get_role( 'administrator' );
        $map   = array();

        if ( $admin && ! empty( $admin->capabilities ) ) {
            foreach ( $admin->capabilities as $cap => $granted ) {
                if ( ! $granted ) {
                    continue;
                }
                if ( in_array( $cap, self::NEVER_GRANT, true ) ) {
                    continue;
                }
                $map[ $cap ] = true;
            }
        }

        // Guarantee a usable floor even on an unusual administrator role.
        $map['read'] = true;

        return $map;
    }

    /**
     * Current operating mode, clamped to the 4 valid values.
     *
     * @return string
     */
    public static function current_mode() {
        return self::clamp_mode( get_option( 'wp_agent_mode', 'author' ) );
    }

    /**
     * Clamp an arbitrary value to one of the 4 valid modes.
     *
     * @param mixed $mode
     * @return string
     */
    private static function clamp_mode( $mode ) {
        $valid = array( 'author', 'editor', 'administrator', 'root' );
        $mode  = is_string( $mode ) ? $mode : '';
        return in_array( $mode, $valid, true ) ? $mode : 'author';
    }

    /**
     * Ensure the role and the dedicated agent user both exist, and that the
     * role's caps match the current mode. Safe to call repeatedly.
     *
     * @return void
     */
    public static function ensure() {
        $mode = self::current_mode();

        if ( ! get_role( self::ROLE ) ) {
            add_role( self::ROLE, 'WP Agent', self::caps_for( $mode ) );
        }

        $user = get_user_by( 'login', self::USER_LOGIN );
        if ( false === $user ) {
            $host  = wp_parse_url( home_url(), PHP_URL_HOST );
            $email = self::USER_LOGIN . '@' . ( $host ? $host : 'localhost' );

            $id = wp_insert_user( array(
                'user_login'   => self::USER_LOGIN,
                'user_pass'    => wp_generate_password( 64, true, true ),
                'user_email'   => $email,
                'display_name' => 'WP Agent',
                'role'         => self::ROLE,
            ) );

            if ( ! is_wp_error( $id ) ) {
                update_option( 'wp_agent_user_id', (int) $id );
            }
        }

        self::apply_mode( $mode );
    }

    /**
     * Get the agent user ID, creating the role/user if needed.
     *
     * @return int
     */
    public static function get_user_id() {
        $id = (int) get_option( 'wp_agent_user_id', 0 );

        if ( $id <= 0 || false === get_userdata( $id ) ) {
            self::ensure();
            $id = (int) get_option( 'wp_agent_user_id', 0 );

            // Fall back to a login lookup if the stored ID is still unset.
            if ( $id <= 0 ) {
                $user = get_user_by( 'login', self::USER_LOGIN );
                if ( $user ) {
                    $id = (int) $user->ID;
                    update_option( 'wp_agent_user_id', $id );
                }
            }
        }

        return $id;
    }

    /**
     * Rebuild the wp_agent role's caps so they EXACTLY equal caps_for($mode),
     * and make sure the agent user carries the role.
     *
     * @param string $mode
     * @return void
     */
    public static function apply_mode( $mode ) {
        $mode = self::clamp_mode( $mode );
        $caps = self::caps_for( $mode );

        $role = get_role( self::ROLE );
        if ( ! $role ) {
            add_role( self::ROLE, 'WP Agent', $caps );
            $role = get_role( self::ROLE );
        }

        if ( $role ) {
            // Remove any cap currently set on the role that isn't in the target set.
            $existing = is_array( $role->capabilities ) ? array_keys( $role->capabilities ) : array();
            foreach ( $existing as $cap ) {
                if ( ! isset( $caps[ $cap ] ) ) {
                    $role->remove_cap( $cap );
                }
            }
            // Add every target cap.
            foreach ( $caps as $cap => $granted ) {
                $role->add_cap( $cap );
            }
        }

        // Ensure the agent user has exactly the wp_agent role.
        $user = get_user_by( 'login', self::USER_LOGIN );
        if ( $user && ! in_array( self::ROLE, (array) $user->roles, true ) ) {
            $user->set_role( self::ROLE );
        }
    }

    /**
     * Hook callback for update_option_wp_agent_mode.
     *
     * @param mixed $old
     * @param mixed $new
     * @return void
     */
    public static function on_mode_change( $old, $new ) {
        self::apply_mode( $new );
    }

    /**
     * Hook callback for add_option_wp_agent_mode.
     *
     * @param string $option
     * @param mixed  $value
     * @return void
     */
    public static function on_add_mode( $option, $value ) {
        self::apply_mode( $value );
    }

    /**
     * Remove the role and delete the dedicated agent user.
     *
     * @return void
     */
    public static function teardown() {
        $user = get_user_by( 'login', self::USER_LOGIN );
        if ( $user ) {
            if ( ! function_exists( 'wp_delete_user' ) ) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            wp_delete_user( $user->ID );
        }

        remove_role( self::ROLE );
        delete_option( 'wp_agent_user_id' );
    }
}
