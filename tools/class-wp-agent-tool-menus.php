<?php
/**
 * Menus tool — manage WordPress navigation menus for site planning.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_Tool_Menus extends WPAgent_Tool {

    public function get_name() {
        return 'manage_menus';
    }

    public function get_description() {
        return 'List, create, inspect, assign, and update WordPress navigation menus. Use this for site planning navigation after pages/categories exist.';
    }

    public function get_parameters() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action' => array(
                    'type'        => 'string',
                    'enum'        => array( 'list', 'get', 'create', 'add_page', 'add_category', 'add_custom_link', 'assign_location', 'delete_item', 'delete_menu' ),
                    'description' => 'The menu operation to perform.',
                ),
                'menu_id' => array(
                    'type'        => 'integer',
                    'description' => 'Navigation menu term ID.',
                ),
                'menu_name' => array(
                    'type'        => 'string',
                    'description' => 'Menu name for create, or lookup fallback when menu_id is omitted.',
                ),
                'page_id' => array(
                    'type'        => 'integer',
                    'description' => 'Page ID to add for add_page.',
                ),
                'term_id' => array(
                    'type'        => 'integer',
                    'description' => 'Category term ID to add for add_category.',
                ),
                'title' => array(
                    'type'        => 'string',
                    'description' => 'Menu item title or custom link label.',
                ),
                'url' => array(
                    'type'        => 'string',
                    'description' => 'Absolute URL for add_custom_link.',
                ),
                'location' => array(
                    'type'        => 'string',
                    'description' => 'Registered theme menu location slug for assign_location.',
                ),
                'item_id' => array(
                    'type'        => 'integer',
                    'description' => 'Menu item post ID for delete_item.',
                ),
                'position' => array(
                    'type'        => 'integer',
                    'description' => 'Optional menu item position.',
                ),
            ),
            'required' => array( 'action' ),
        );
    }

    public function get_required_capability() {
        return 'edit_theme_options';
    }

    public function execute( array $params ) {
        $action = $params['action'] ?? '';

        switch ( $action ) {
            case 'list':
                return $this->list_menus();
            case 'get':
                return $this->get_menu( $params );
            case 'create':
                return $this->create_menu( $params );
            case 'add_page':
                return $this->add_page( $params );
            case 'add_category':
                return $this->add_category( $params );
            case 'add_custom_link':
                return $this->add_custom_link( $params );
            case 'assign_location':
                return $this->assign_location( $params );
            case 'delete_item':
                return $this->delete_item( $params );
            case 'delete_menu':
                return $this->delete_menu( $params );
            default:
                return array( 'error' => 'Unknown action: ' . $action );
        }
    }

    private function list_menus() {
        $menus = wp_get_nav_menus( array( 'hide_empty' => false ) );
        if ( is_wp_error( $menus ) ) {
            return array( 'error' => $menus->get_error_message() );
        }

        return array(
            'success'   => true,
            'menus'     => array_map( array( $this, 'format_menu' ), $menus ),
            'locations' => $this->locations(),
        );
    }

    private function get_menu( $params ) {
        $menu = $this->resolve_menu( $params );
        if ( is_wp_error( $menu ) ) {
            return array( 'error' => $menu->get_error_message() );
        }

        return array(
            'success' => true,
            'menu'    => $this->format_menu( $menu, true ),
        );
    }

    private function create_menu( $params ) {
        $name = sanitize_text_field( $params['menu_name'] ?? '' );
        if ( '' === $name ) {
            return array( 'error' => 'menu_name is required for create action.' );
        }

        $existing = wp_get_nav_menu_object( $name );
        if ( $existing ) {
            return array(
                'success' => true,
                'menu'    => $this->format_menu( $existing, true ),
                'message' => 'Menu already exists.',
            );
        }

        $menu_id = wp_create_nav_menu( $name );
        if ( is_wp_error( $menu_id ) ) {
            return array( 'error' => $menu_id->get_error_message() );
        }

        $menu = wp_get_nav_menu_object( (int) $menu_id );
        WPAgent::audit_log( $this->owner_id(), 'nav_menu_created', array(
            'menu_id' => (int) $menu_id,
            'name'    => $name,
        ), $this->channel ?: 'agent' );

        return array(
            'success' => true,
            'menu'    => $this->format_menu( $menu, true ),
            'message' => 'Menu created.',
        );
    }

    private function add_page( $params ) {
        $menu = $this->resolve_menu( $params );
        if ( is_wp_error( $menu ) ) {
            return array( 'error' => $menu->get_error_message() );
        }

        $page_id = (int) ( $params['page_id'] ?? 0 );
        $page    = get_post( $page_id );
        if ( ! $page || 'page' !== $page->post_type ) {
            return array( 'error' => 'A valid page_id is required for add_page.' );
        }

        return $this->add_item( $menu, array(
            'menu-item-title'     => sanitize_text_field( $params['title'] ?? get_the_title( $page ) ),
            'menu-item-object-id' => $page_id,
            'menu-item-object'    => 'page',
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
            'menu-item-position'  => max( 0, (int) ( $params['position'] ?? 0 ) ),
        ) );
    }

    private function add_category( $params ) {
        $menu = $this->resolve_menu( $params );
        if ( is_wp_error( $menu ) ) {
            return array( 'error' => $menu->get_error_message() );
        }

        $term_id = (int) ( $params['term_id'] ?? 0 );
        $term    = get_term( $term_id, 'category' );
        if ( ! $term || is_wp_error( $term ) ) {
            return array( 'error' => 'A valid category term_id is required for add_category.' );
        }

        return $this->add_item( $menu, array(
            'menu-item-title'     => sanitize_text_field( $params['title'] ?? $term->name ),
            'menu-item-object-id' => $term_id,
            'menu-item-object'    => 'category',
            'menu-item-type'      => 'taxonomy',
            'menu-item-status'    => 'publish',
            'menu-item-position'  => max( 0, (int) ( $params['position'] ?? 0 ) ),
        ) );
    }

    private function add_custom_link( $params ) {
        $menu = $this->resolve_menu( $params );
        if ( is_wp_error( $menu ) ) {
            return array( 'error' => $menu->get_error_message() );
        }

        $title = sanitize_text_field( $params['title'] ?? '' );
        $url   = esc_url_raw( $params['url'] ?? '' );
        if ( '' === $title || '' === $url || ! $this->valid_menu_url( $url ) ) {
            return array( 'error' => 'title and a valid absolute http(s) url are required for add_custom_link.' );
        }

        return $this->add_item( $menu, array(
            'menu-item-title'    => $title,
            'menu-item-url'      => $url,
            'menu-item-type'     => 'custom',
            'menu-item-status'   => 'publish',
            'menu-item-position' => max( 0, (int) ( $params['position'] ?? 0 ) ),
        ) );
    }

    private function assign_location( $params ) {
        $menu = $this->resolve_menu( $params );
        if ( is_wp_error( $menu ) ) {
            return array( 'error' => $menu->get_error_message() );
        }

        $location  = sanitize_key( $params['location'] ?? '' );
        $locations = get_registered_nav_menus();
        if ( '' === $location || ! isset( $locations[ $location ] ) ) {
            return array( 'error' => 'location must be one of the registered theme menu locations.' );
        }

        $assigned = get_theme_mod( 'nav_menu_locations', array() );
        if ( ! is_array( $assigned ) ) {
            $assigned = array();
        }
        $assigned[ $location ] = (int) $menu->term_id;
        set_theme_mod( 'nav_menu_locations', $assigned );

        WPAgent::audit_log( $this->owner_id(), 'nav_menu_location_assigned', array(
            'menu_id'  => (int) $menu->term_id,
            'location' => $location,
        ), $this->channel ?: 'agent' );

        return array(
            'success'  => true,
            'menu'     => $this->format_menu( $menu, true ),
            'location' => $location,
            'message'  => 'Menu location assigned.',
        );
    }

    private function delete_item( $params ) {
        $item_id = (int) ( $params['item_id'] ?? 0 );
        if ( $item_id <= 0 || 'nav_menu_item' !== get_post_type( $item_id ) ) {
            return array( 'error' => 'A valid menu item_id is required for delete_item.' );
        }

        $deleted = wp_delete_post( $item_id, true );
        if ( ! $deleted ) {
            return array( 'error' => 'Menu item could not be deleted.' );
        }

        WPAgent::audit_log( $this->owner_id(), 'nav_menu_item_deleted', array( 'item_id' => $item_id ), $this->channel ?: 'agent' );
        return array( 'success' => true, 'item_id' => $item_id, 'message' => 'Menu item deleted.' );
    }

    private function delete_menu( $params ) {
        $menu = $this->resolve_menu( $params );
        if ( is_wp_error( $menu ) ) {
            return array( 'error' => $menu->get_error_message() );
        }

        $deleted = wp_delete_nav_menu( (int) $menu->term_id );
        if ( is_wp_error( $deleted ) ) {
            return array( 'error' => $deleted->get_error_message() );
        }
        if ( ! $deleted ) {
            return array( 'error' => 'Menu could not be deleted.' );
        }

        WPAgent::audit_log( $this->owner_id(), 'nav_menu_deleted', array(
            'menu_id' => (int) $menu->term_id,
            'name'    => $menu->name,
        ), $this->channel ?: 'agent' );

        return array( 'success' => true, 'menu_id' => (int) $menu->term_id, 'message' => 'Menu deleted.' );
    }

    private function add_item( $menu, $item ) {
        $item_id = wp_update_nav_menu_item( (int) $menu->term_id, 0, $item );
        if ( is_wp_error( $item_id ) ) {
            return array( 'error' => $item_id->get_error_message() );
        }

        WPAgent::audit_log( $this->owner_id(), 'nav_menu_item_added', array(
            'menu_id' => (int) $menu->term_id,
            'item_id' => (int) $item_id,
            'type'    => $item['menu-item-type'] ?? '',
        ), $this->channel ?: 'agent' );

        return array(
            'success' => true,
            'menu'    => $this->format_menu( wp_get_nav_menu_object( (int) $menu->term_id ), true ),
            'item'    => $this->format_item( wp_setup_nav_menu_item( get_post( (int) $item_id ) ) ),
            'message' => 'Menu item added.',
        );
    }

    private function resolve_menu( $params ) {
        $menu_id = (int) ( $params['menu_id'] ?? 0 );
        $menu    = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : null;

        if ( ! $menu && ! empty( $params['menu_name'] ) ) {
            $menu = wp_get_nav_menu_object( sanitize_text_field( $params['menu_name'] ) );
        }

        if ( ! $menu ) {
            return new WP_Error( 'wp_agent_menu_missing', 'Menu not found.' );
        }

        return $menu;
    }

    private function format_menu( $menu, $include_items = false ) {
        if ( ! $menu || is_wp_error( $menu ) ) {
            return null;
        }

        $out = array(
            'menu_id' => (int) $menu->term_id,
            'name'    => (string) $menu->name,
            'slug'    => (string) $menu->slug,
            'count'   => (int) $menu->count,
        );

        if ( $include_items ) {
            $items = wp_get_nav_menu_items( (int) $menu->term_id, array( 'update_post_term_cache' => false ) );
            $out['items'] = is_array( $items ) ? array_map( array( $this, 'format_item' ), $items ) : array();
            $out['locations'] = $this->menu_locations( (int) $menu->term_id );
        }

        return $out;
    }

    private function format_item( $item ) {
        if ( ! $item ) {
            return null;
        }

        return array(
            'item_id'   => (int) $item->ID,
            'title'     => (string) $item->title,
            'type'      => (string) $item->type,
            'object'    => (string) $item->object,
            'object_id' => (int) $item->object_id,
            'url'       => (string) $item->url,
            'parent'    => (int) $item->menu_item_parent,
            'position'  => (int) $item->menu_order,
        );
    }

    private function locations() {
        $registered = get_registered_nav_menus();
        $assigned   = get_theme_mod( 'nav_menu_locations', array() );
        $assigned   = is_array( $assigned ) ? $assigned : array();
        $locations  = array();

        foreach ( $registered as $slug => $label ) {
            $locations[] = array(
                'location' => (string) $slug,
                'label'    => (string) $label,
                'menu_id'  => (int) ( $assigned[ $slug ] ?? 0 ),
            );
        }

        return $locations;
    }

    private function menu_locations( $menu_id ) {
        $assigned = get_theme_mod( 'nav_menu_locations', array() );
        $assigned = is_array( $assigned ) ? $assigned : array();
        $out      = array();

        foreach ( $assigned as $location => $assigned_menu_id ) {
            if ( (int) $assigned_menu_id === (int) $menu_id ) {
                $out[] = (string) $location;
            }
        }

        return $out;
    }

    private function valid_menu_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }

        $scheme = strtolower( $parts['scheme'] ?? '' );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
            return false;
        }

        return empty( $parts['user'] ) && empty( $parts['pass'] );
    }
}
