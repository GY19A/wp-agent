<?php
/**
 * Deterministic WP Agent site planning acceptance demo.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/site-planning-demo.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This site planning script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_site_plan_fail( $message ) {
    if ( function_exists( 'wp_agent_site_plan_cleanup' ) ) {
        wp_agent_site_plan_cleanup();
    }
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_site_plan_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_site_plan_fail( $message );
    }
}

$GLOBALS['wp_agent_site_plan_previous_mode'] = get_option( 'wp_agent_mode', 'author' );
$GLOBALS['wp_agent_site_plan_restored']      = false;

function wp_agent_site_plan_cleanup() {
    if ( ! empty( $GLOBALS['wp_agent_site_plan_restored'] ) ) {
        return;
    }
    update_option( 'wp_agent_mode', $GLOBALS['wp_agent_site_plan_previous_mode'] );
    WPAgent_Roles::ensure();
    $GLOBALS['wp_agent_site_plan_restored'] = true;
}

register_shutdown_function( 'wp_agent_site_plan_cleanup' );

update_option( 'wp_agent_mode', 'administrator' );
WPAgent_Roles::ensure();

$agent_user = WPAgent_Roles::get_user_id();
wp_agent_site_plan_assert( $agent_user > 0, 'Bounded agent user is missing.' );
wp_agent_site_plan_assert( user_can( $agent_user, 'edit_theme_options' ), 'Administrator-mode agent should be able to manage menus.' );

$registry    = new WPAgent_Tools();
$definitions = $registry->get_definitions_for_user( $agent_user );
$tool_names  = array();
foreach ( $definitions as $definition ) {
    $tool_names[] = $definition['name'] ?? '';
}
wp_agent_site_plan_assert( in_array( 'manage_menus', $tool_names, true ), 'manage_menus should be visible in administrator mode.' );

$context = array( $agent_user, 'wpcli', 0, 1, 0 );

$pages = new WPAgent_Tool_Pages();
$pages->set_context( ...$context );
$about = $pages->execute( array(
    'action'  => 'create',
    'title'   => 'About WP Agent News',
    'content' => '<p>About page fixture for WP Agent site planning acceptance.</p>',
    'status'  => 'publish',
) );
wp_agent_site_plan_assert( ! empty( $about['success'] ) && ! empty( $about['page_id'] ), 'About page was not created: ' . wp_json_encode( $about ) );

$policy = $pages->execute( array(
    'action'  => 'create',
    'title'   => 'Editorial Policy',
    'content' => '<p>Editorial policy fixture for source retention, corrections, and review workflow.</p>',
    'status'  => 'publish',
) );
wp_agent_site_plan_assert( ! empty( $policy['success'] ) && ! empty( $policy['page_id'] ), 'Editorial Policy page was not created: ' . wp_json_encode( $policy ) );

$taxonomies = new WPAgent_Tool_Taxonomies();
$taxonomies->set_context( ...$context );
$world = $taxonomies->execute( array(
    'action'      => 'create',
    'taxonomy'    => 'category',
    'name'        => 'World Desk',
    'slug'        => 'world-desk',
    'description' => 'Site planning fixture category for navigation.',
) );
wp_agent_site_plan_assert( ! empty( $world['success'] ) && ! empty( $world['term']['term_id'] ), 'World Desk category was not created: ' . wp_json_encode( $world ) );

$menus = new WPAgent_Tool_Menus();
$menus->set_context( ...$context );
$created = $menus->execute( array(
    'action'    => 'create',
    'menu_name' => 'WP Agent Main Navigation',
) );
wp_agent_site_plan_assert( ! empty( $created['success'] ) && ! empty( $created['menu']['menu_id'] ), 'Navigation menu was not created: ' . wp_json_encode( $created ) );
$menu_id = (int) $created['menu']['menu_id'];

$home_link = $menus->execute( array(
    'action'  => 'add_custom_link',
    'menu_id' => $menu_id,
    'title'   => 'Home',
    'url'     => home_url( '/' ),
) );
wp_agent_site_plan_assert( ! empty( $home_link['success'] ) && 'custom' === ( $home_link['item']['type'] ?? '' ), 'Home custom link was not added: ' . wp_json_encode( $home_link ) );

$about_item = $menus->execute( array(
    'action'  => 'add_page',
    'menu_id' => $menu_id,
    'page_id' => (int) $about['page_id'],
) );
wp_agent_site_plan_assert( ! empty( $about_item['success'] ) && (int) $about_item['item']['object_id'] === (int) $about['page_id'], 'About page was not added to menu: ' . wp_json_encode( $about_item ) );

$policy_item = $menus->execute( array(
    'action'  => 'add_page',
    'menu_id' => $menu_id,
    'page_id' => (int) $policy['page_id'],
) );
wp_agent_site_plan_assert( ! empty( $policy_item['success'] ) && (int) $policy_item['item']['object_id'] === (int) $policy['page_id'], 'Editorial Policy page was not added to menu: ' . wp_json_encode( $policy_item ) );

$category_item = $menus->execute( array(
    'action'  => 'add_category',
    'menu_id' => $menu_id,
    'term_id' => (int) $world['term']['term_id'],
) );
wp_agent_site_plan_assert( ! empty( $category_item['success'] ) && 'taxonomy' === ( $category_item['item']['type'] ?? '' ), 'World Desk category was not added to menu: ' . wp_json_encode( $category_item ) );

$listed = $menus->execute( array( 'action' => 'list' ) );
wp_agent_site_plan_assert( ! empty( $listed['success'] ), 'Menu listing failed: ' . wp_json_encode( $listed ) );

$locations = $listed['locations'] ?? array();
$assigned_location = '';
if ( ! empty( $locations ) ) {
    $assigned_location = (string) $locations[0]['location'];
    $assigned = $menus->execute( array(
        'action'   => 'assign_location',
        'menu_id'  => $menu_id,
        'location' => $assigned_location,
    ) );
    wp_agent_site_plan_assert( ! empty( $assigned['success'] ), 'Menu location assignment failed: ' . wp_json_encode( $assigned ) );
}

$menu = $menus->execute( array( 'action' => 'get', 'menu_id' => $menu_id ) );
wp_agent_site_plan_assert( ! empty( $menu['success'] ), 'Menu get failed: ' . wp_json_encode( $menu ) );
$items = $menu['menu']['items'] ?? array();
wp_agent_site_plan_assert( count( $items ) >= 4, 'Menu should contain at least four planning items.' );

$objects = array();
foreach ( $items as $item ) {
    $objects[] = $item['object'] . ':' . $item['object_id'];
}
wp_agent_site_plan_assert( in_array( 'page:' . (int) $about['page_id'], $objects, true ), 'Menu missing About page item.' );
wp_agent_site_plan_assert( in_array( 'page:' . (int) $policy['page_id'], $objects, true ), 'Menu missing Editorial Policy page item.' );
wp_agent_site_plan_assert( in_array( 'category:' . (int) $world['term']['term_id'], $objects, true ), 'Menu missing World Desk category item.' );

$permissions = new WPAgent_Permissions();
wp_agent_site_plan_assert( $permissions->requires_confirmation( 'manage_menus', array( 'action' => 'delete_menu' ) ), 'Menu deletion should require confirmation.' );
wp_agent_site_plan_assert( $permissions->requires_confirmation( 'manage_menus', array( 'action' => 'delete_item' ) ), 'Menu item deletion should require confirmation.' );
wp_agent_site_plan_assert( ! $permissions->requires_confirmation( 'manage_menus', array( 'action' => 'list' ) ), 'Menu listing should not require confirmation.' );

wp_agent_site_plan_cleanup();

echo wp_json_encode( array(
    'success'           => true,
    'menu_id'           => $menu_id,
    'item_count'        => count( $items ),
    'about_page_id'     => (int) $about['page_id'],
    'policy_page_id'    => (int) $policy['page_id'],
    'category_id'       => (int) $world['term']['term_id'],
    'assigned_location' => $assigned_location,
) ) . "\n";
