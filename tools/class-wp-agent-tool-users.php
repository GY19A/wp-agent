<?php
/**
 * Users tool — create, list, get, update, delete WordPress users and change roles.
 *
 * Security-critical: every mutating action is gated by a per-action capability
 * check against the ACTING user ($this->user_id), and privilege escalation to
 * the administrator role is blocked unless the acting user can manage_options.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Users extends WPAgent_Tool {

    public function get_name() {
        return 'manage_users';
    }

    public function get_description() {
        return 'Create, list, get, update, or delete WordPress users and change their roles via natural language. Use the action parameter to specify the operation.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'set_role' ),
                    'description' => 'The operation to perform.',
                ),
                'user_id' => array(
                    'type'        => 'integer',
                    'description' => 'User ID (required for get, update, delete, set_role).',
                ),
                'user_login' => array(
                    'type'        => 'string',
                    'description' => 'Username / login (required for create). Identifies the user on get/update if user_id is absent.',
                ),
                'email' => array(
                    'type'        => 'string',
                    'description' => 'Email address.',
                ),
                'role' => array(
                    'type'        => 'string',
                    'description' => 'Role to assign, e.g. subscriber, contributor, author, editor, administrator.',
                ),
                'display_name' => array(
                    'type'        => 'string',
                    'description' => 'Display name.',
                ),
                'first_name' => array(
                    'type'        => 'string',
                    'description' => 'First name.',
                ),
                'last_name' => array(
                    'type'        => 'string',
                    'description' => 'Last name.',
                ),
                'password' => array(
                    'type'        => 'string',
                    'description' => 'Password. If omitted on create, a strong one is generated and returned once.',
                ),
                'send_notification' => array(
                    'type'        => 'boolean',
                    'description' => 'Whether to email the new user/notification on create. Default false.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Number of users to return for list (default 20, max 100).',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        // Coarse visibility gate. Per-action capabilities are enforced in execute().
        return 'list_users';
    }

    public function execute( array $params ) {
        $action = $params['action'] ?? '';

        switch ( $action ) {
            case 'list':
                return $this->list_users( $params );
            case 'get':
                return $this->get_user( $params );
            case 'create':
                return $this->create_user( $params );
            case 'update':
                return $this->update_user( $params );
            case 'delete':
                return $this->delete_user( $params );
            case 'set_role':
                return $this->set_role( $params );
            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    /**
     * Validate a requested role and guard against administrator escalation.
     *
     * @param string $role Raw role slug.
     * @return string|array Sanitized role slug, or an error array.
     */
    private function validate_role( $role ) {
        $role = sanitize_key( $role );

        if ( '' === $role ) {
            return array( 'error' => 'A role is required.' );
        }

        // Only roles the current install considers editable are assignable.
        $editable = array_keys( get_editable_roles() );
        if ( ! in_array( $role, $editable, true ) ) {
            return array( 'error' => 'Invalid or non-assignable role: ' . $role );
        }

        // Privilege-escalation guard: assigning administrator requires the acting
        // user to be able to manage_options themselves.
        if ( 'administrator' === $role && ! user_can( $this->user_id, 'manage_options' ) ) {
            return array( 'error' => 'Permission denied: you may not assign the administrator role.' );
        }

        return $role;
    }

    private function list_users( $params ) {
        $limit = min( max( (int) ( $params['limit'] ?? 20 ), 1 ), 100 );

        $query = new WP_User_Query(
            array(
                'number'  => $limit,
                'orderby' => 'registered',
                'order'   => 'DESC',
            )
        );

        $users = array();
        foreach ( $query->get_results() as $user ) {
            $users[] = array(
                'id'           => $user->ID,
                'user_login'   => $user->user_login,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
                'registered'   => $user->user_registered,
            );
        }

        return array(
            'total' => (int) $query->get_total(),
            'count' => count( $users ),
            'users' => $users,
        );
    }

    /**
     * Resolve a user from params by user_id, then user_login, then email.
     *
     * @param array $params
     * @return WP_User|false
     */
    private function resolve_user( $params ) {
        if ( ! empty( $params['user_id'] ) ) {
            return get_user_by( 'id', (int) $params['user_id'] );
        }
        if ( ! empty( $params['user_login'] ) ) {
            return get_user_by( 'login', sanitize_user( $params['user_login'] ) );
        }
        if ( ! empty( $params['email'] ) && is_email( $params['email'] ) ) {
            return get_user_by( 'email', sanitize_email( $params['email'] ) );
        }
        return false;
    }

    private function get_user( $params ) {
        $user = $this->resolve_user( $params );
        if ( ! $user ) {
            return array( 'error' => 'User not found.' );
        }

        return array(
            'id'           => $user->ID,
            'user_login'   => $user->user_login,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'roles'        => $user->roles,
            'registered'   => $user->user_registered,
            'url'          => $user->user_url,
        );
    }

    private function create_user( $params ) {
        if ( ! user_can( $this->user_id, 'create_users' ) ) {
            return array( 'error' => 'Permission denied.' );
        }

        $login = isset( $params['user_login'] ) ? sanitize_user( $params['user_login'], true ) : '';
        if ( '' === $login ) {
            return array( 'error' => 'user_login is required for create action.' );
        }
        if ( username_exists( $login ) ) {
            return array( 'error' => 'A user with that login already exists.' );
        }

        $email = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
        if ( '' === $email || ! is_email( $email ) ) {
            return array( 'error' => 'A valid email address is required for create action.' );
        }
        if ( email_exists( $email ) ) {
            return array( 'error' => 'A user with that email address already exists.' );
        }

        // Resolve and validate the role (default subscriber).
        $requested_role = isset( $params['role'] ) ? $params['role'] : get_option( 'default_role', 'subscriber' );
        $role           = $this->validate_role( $requested_role );
        if ( is_array( $role ) ) {
            return $role; // Error array.
        }

        // Password: use provided, else generate a strong one to return once.
        $generated = false;
        if ( isset( $params['password'] ) && '' !== (string) $params['password'] ) {
            $password = (string) $params['password'];
        } else {
            $password  = wp_generate_password( 24, true, true );
            $generated = true;
        }

        $userdata = array(
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'role'         => $role,
            'display_name' => isset( $params['display_name'] ) ? sanitize_text_field( $params['display_name'] ) : $login,
            'first_name'   => isset( $params['first_name'] ) ? sanitize_text_field( $params['first_name'] ) : '',
            'last_name'    => isset( $params['last_name'] ) ? sanitize_text_field( $params['last_name'] ) : '',
        );

        $new_id = wp_insert_user( $userdata );
        if ( is_wp_error( $new_id ) ) {
            return array( 'error' => $new_id->get_error_message() );
        }

        // Optional notification email to the new user (and admin).
        if ( ! empty( $params['send_notification'] ) && function_exists( 'wp_new_user_notification' ) ) {
            wp_new_user_notification( $new_id, null, 'user' );
        }

        $result = array(
            'success'    => true,
            'user_id'    => $new_id,
            'user_login' => $login,
            'email'      => $email,
            'role'       => $role,
            'message'    => sprintf( 'User "%s" created.', $login ),
        );

        // Return the generated password exactly once.
        if ( $generated ) {
            $result['password']         = $password;
            $result['password_notice']  = 'This is the only time the generated password will be shown. Store it securely.';
        }

        return $result;
    }

    private function update_user( $params ) {
        if ( ! user_can( $this->user_id, 'edit_users' ) ) {
            return array( 'error' => 'Permission denied.' );
        }

        $user = $this->resolve_user( $params );
        if ( ! $user ) {
            return array( 'error' => 'User not found.' );
        }

        $userdata = array( 'ID' => $user->ID );

        if ( isset( $params['email'] ) ) {
            $email = sanitize_email( $params['email'] );
            if ( '' === $email || ! is_email( $email ) ) {
                return array( 'error' => 'Invalid email address.' );
            }
            $existing = email_exists( $email );
            if ( $existing && (int) $existing !== (int) $user->ID ) {
                return array( 'error' => 'Another user already uses that email address.' );
            }
            $userdata['user_email'] = $email;
        }

        if ( isset( $params['display_name'] ) ) {
            $userdata['display_name'] = sanitize_text_field( $params['display_name'] );
        }
        if ( isset( $params['first_name'] ) ) {
            $userdata['first_name'] = sanitize_text_field( $params['first_name'] );
        }
        if ( isset( $params['last_name'] ) ) {
            $userdata['last_name'] = sanitize_text_field( $params['last_name'] );
        }
        if ( isset( $params['password'] ) && '' !== (string) $params['password'] ) {
            $userdata['user_pass'] = (string) $params['password'];
        }

        // Role changes go through the escalation-guarded validator.
        if ( isset( $params['role'] ) ) {
            $role = $this->validate_role( $params['role'] );
            if ( is_array( $role ) ) {
                return $role; // Error array.
            }
            $userdata['role'] = $role;
        }

        $result = wp_update_user( $userdata );
        if ( is_wp_error( $result ) ) {
            return array( 'error' => $result->get_error_message() );
        }

        return array(
            'success' => true,
            'user_id' => $user->ID,
            'message' => sprintf( 'User "%s" updated.', $user->user_login ),
        );
    }

    private function set_role( $params ) {
        if ( ! user_can( $this->user_id, 'edit_users' ) ) {
            return array( 'error' => 'Permission denied.' );
        }

        $user = $this->resolve_user( $params );
        if ( ! $user ) {
            return array( 'error' => 'User not found.' );
        }

        if ( ! isset( $params['role'] ) ) {
            return array( 'error' => 'role is required for set_role action.' );
        }

        $role = $this->validate_role( $params['role'] );
        if ( is_array( $role ) ) {
            return $role; // Error array.
        }

        // set_role() replaces all existing roles with the single given role.
        $user->set_role( $role );

        return array(
            'success' => true,
            'user_id' => $user->ID,
            'role'    => $role,
            'message' => sprintf( 'User "%s" role set to %s.', $user->user_login, $role ),
        );
    }

    private function delete_user( $params ) {
        if ( ! user_can( $this->user_id, 'delete_users' ) ) {
            return array( 'error' => 'Permission denied.' );
        }

        $user = $this->resolve_user( $params );
        if ( ! $user ) {
            return array( 'error' => 'User not found.' );
        }

        // Never allow the acting user to delete their own account via this tool.
        if ( (int) $user->ID === (int) $this->user_id ) {
            return array( 'error' => 'You cannot delete your own account.' );
        }

        // wp_delete_user lives in wp-admin/includes/user.php.
        require_once ABSPATH . 'wp-admin/includes/user.php';

        // Reassign content to the acting user when possible; otherwise null
        // (wp_delete_user deletes the orphaned content).
        $reassign = ( (int) $this->user_id > 0 && (int) $this->user_id !== (int) $user->ID )
            ? (int) $this->user_id
            : null;

        $deleted = wp_delete_user( $user->ID, $reassign );
        if ( ! $deleted ) {
            return array( 'error' => 'Failed to delete user.' );
        }

        return array(
            'success'  => true,
            'message'  => sprintf( 'User "%s" deleted.', $user->user_login ),
            'reassigned_to' => $reassign,
        );
    }
}
