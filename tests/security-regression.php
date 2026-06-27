<?php
/**
 * WP Agent security regression checks.
 *
 * Run inside a loaded WordPress context:
 * wp eval-file wp-content/plugins/wp-agent/tests/security-regression.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    fwrite( STDERR, "This regression script must run through WP-CLI.\n" );
    exit( 1 );
}

function wp_agent_security_fail( $message ) {
    fwrite( STDERR, "FAIL: " . $message . "\n" );
    exit( 1 );
}

function wp_agent_security_assert( $condition, $message ) {
    if ( ! $condition ) {
        wp_agent_security_fail( $message );
    }
}

function wp_agent_security_expect_error( $result, $label ) {
    wp_agent_security_assert( is_array( $result ) && ! empty( $result['error'] ), $label . ' should return an error.' );
}

function wp_agent_security_path_starts_with( $path, $parent ) {
    $path   = untrailingslashit( wp_normalize_path( (string) $path ) ) . '/';
    $parent = trailingslashit( wp_normalize_path( (string) $parent ) );
    return 0 === strpos( $path, $parent );
}

function wp_agent_security_post_title_exists( $title ) {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_title = %s LIMIT 1",
        (string) $title
    ) );
}

function wp_agent_security_text_attachment() {
    $upload = wp_upload_bits( 'wp-agent-security-note.txt', null, 'not an image' );
    wp_agent_security_assert( empty( $upload['error'] ) && ! empty( $upload['file'] ), 'Text fixture upload should succeed.' );

    $attachment_id = wp_insert_attachment( array(
        'post_title'     => 'WP Agent Security Text Fixture',
        'post_mime_type' => 'text/plain',
        'post_status'    => 'inherit',
    ), $upload['file'] );

    wp_agent_security_assert( ! is_wp_error( $attachment_id ) && $attachment_id > 0, 'Text fixture attachment should be created.' );
    return (int) $attachment_id;
}

$summary = array(
    'ssrf'             => 0,
    'workspace'        => 0,
    'skills'           => 0,
    'confirmations'    => 0,
    'secrets'          => 0,
    'runtime_root'     => 0,
    'content_metadata' => 0,
);

$web = new WPAgent_Tool_Web();
$web->set_context( 1, 'wpcli', 0, 1, 0 );
foreach ( array(
    'http://localhost/',
    'http://127.0.0.1/',
    'http://[::1]/',
    'http://169.254.169.254/latest/meta-data/',
    'file:///etc/passwd',
) as $url ) {
    wp_agent_security_expect_error( $web->execute( array( 'action' => 'fetch', 'url' => $url ) ), 'SSRF guard for ' . $url );
    $summary['ssrf']++;
}

$files = new WPAgent_Tool_Files();
$files->set_context( 1, 'wpcli', 991601, 1, 0 );
$valid_write = $files->execute( array( 'action' => 'write', 'path' => 'security/probe.txt', 'content' => 'private workspace probe' ) );
wp_agent_security_assert( ! empty( $valid_write['success'] ), 'Valid workspace write should succeed.' );
$valid_read = $files->execute( array( 'action' => 'read', 'path' => 'security/probe.txt' ) );
wp_agent_security_assert( 'private workspace probe' === ( $valid_read['content'] ?? '' ), 'Valid workspace read should return stored content.' );
$summary['workspace'] += 2;

foreach ( array( '../escape.txt', '/tmp/escape.txt', 'security/bad.php' ) as $path ) {
    wp_agent_security_expect_error( $files->execute( array( 'action' => 'write', 'path' => $path, 'content' => 'blocked' ) ), 'Workspace write guard for ' . $path );
    $summary['workspace']++;
}
wp_agent_security_expect_error( $files->execute( array( 'action' => 'read', 'path' => '../security/probe.txt' ) ), 'Workspace read traversal guard' );
$summary['workspace']++;

$blocked_skill = WPAgent_Skills::save( 1, array(
    'name' => 'Security Executable Probe',
    'slug' => 'security-executable-probe',
    'body' => '<?php echo "blocked";',
) );
wp_agent_security_assert( is_wp_error( $blocked_skill ), 'Executable Skill body should be rejected.' );
$summary['skills']++;

$blocked_shell_skill = WPAgent_Skills::save( 1, array(
    'name' => 'Security Shell Probe',
    'slug' => 'security-shell-probe',
    'body' => 'Run shell_exec("id") during this workflow.',
) );
wp_agent_security_assert( is_wp_error( $blocked_shell_skill ), 'Shell execution Skill body should be rejected.' );
$summary['skills']++;

$permissions = new WPAgent_Permissions();
$confirmation_cases = array(
    array( true, 'manage_posts', array( 'action' => 'create', 'status' => 'publish' ), 'Publishing should require confirmation.' ),
    array( true, 'manage_posts', array( 'action' => 'delete' ), 'Post delete should require confirmation.' ),
    array( true, 'manage_wp_agent_settings', array( 'action' => 'set' ), 'Settings writes should require confirmation.' ),
    array( true, 'execute_code', array( 'action' => 'run' ), 'Code execution should require confirmation.' ),
    array( true, 'manage_taxonomies', array( 'action' => 'delete' ), 'Taxonomy deletion should require confirmation.' ),
    array( true, 'manage_menus', array( 'action' => 'delete_menu' ), 'Menu deletion should require confirmation.' ),
    array( true, 'manage_menus', array( 'action' => 'delete_item' ), 'Menu item deletion should require confirmation.' ),
    array( true, 'manage_skills', array( 'action' => 'save' ), 'Local Skill writes should require confirmation.' ),
    array( true, 'manage_skills', array( 'action' => 'install_template' ), 'Built-in Skill template installs should require confirmation.' ),
    array( true, 'manage_skills', array( 'action' => 'install_github' ), 'Third-party Skill installs should require confirmation.' ),
    array( true, 'manage_skills', array( 'action' => 'activate_quarantine' ), 'Third-party Skill activation should require confirmation.' ),
    array( true, 'manage_skills', array( 'action' => 'refresh_package' ), 'Third-party Skill refresh should require confirmation.' ),
    array( true, 'manage_skills', array( 'action' => 'pin_package' ), 'Third-party Skill package pinning should require confirmation.' ),
    array( true, 'manage_skills', array( 'action' => 'unpin_package' ), 'Third-party Skill package unpinning should require confirmation.' ),
    array( true, 'manage_skills', array( 'action' => 'rollback_package' ), 'Third-party Skill rollback should require confirmation.' ),
    array( false, 'manage_skills', array( 'action' => 'list_templates' ), 'Template listing should not require confirmation.' ),
    array( false, 'manage_schedules', array( 'action' => 'parse' ), 'Schedule parsing should not require confirmation.' ),
    array( false, 'manage_menus', array( 'action' => 'list' ), 'Menu listing should not require confirmation.' ),
);
foreach ( $confirmation_cases as $case ) {
    list( $expected, $tool, $params, $label ) = $case;
    wp_agent_security_assert( $expected === $permissions->requires_confirmation( $tool, $params ), $label );
    $summary['confirmations']++;
}

$settings = new WPAgent_Tool_Settings();
$settings->set_context( 1, 'wpcli', 0, 1, 0 );
$secret = 'xoxb-security-regression-secret';
$set_secret = $settings->execute( array( 'action' => 'set', 'key' => 'slack_bot_token', 'value' => $secret ) );
wp_agent_security_assert( ! empty( $set_secret['success'] ), 'Secret setting write should succeed for admin user.' );
wp_agent_security_assert( false === strpos( wp_json_encode( $set_secret ), $secret ), 'Secret write response should not echo the secret.' );
$get_secret = $settings->execute( array( 'action' => 'get', 'key' => 'slack_bot_token' ) );
wp_agent_security_assert( 'set' === ( $get_secret['value'] ?? '' ), 'Secret read should return only set/not set state.' );
wp_agent_security_assert( false === strpos( wp_json_encode( $get_secret ), $secret ), 'Secret read response should not echo the secret.' );
$summary['secrets'] += 4;

$runtime_root = WPAgent_Sandbox::runtime_root();
wp_agent_security_assert( is_string( $runtime_root ) && '' !== $runtime_root, 'Runtime root should resolve.' );
foreach ( array( ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, wp_get_upload_dir()['basedir'] ) as $blocked_root ) {
    wp_agent_security_assert( ! wp_agent_security_path_starts_with( $runtime_root, $blocked_root ), 'Runtime root must not sit under public WordPress paths: ' . $blocked_root );
    $summary['runtime_root']++;
}

$posts = new WPAgent_Tool_Posts();
$posts->set_context( 1, 'wpcli', 0, 1, 0 );

$valid_title = 'WP Agent Security Valid Metadata ' . wp_generate_uuid4();
$valid_post = $posts->execute( array(
    'action'       => 'create',
    'title'        => $valid_title,
    'content'      => '<p>Security regression fixture.</p>',
    'status'       => 'draft',
    'source_urls'  => array( 'https://example.com/', 'https://example.com/' ),
    'source_notes' => 'Public source URL retention fixture.',
) );
wp_agent_security_assert( ! empty( $valid_post['success'] ) && ! empty( $valid_post['post_id'] ), 'Valid public source metadata should be accepted.' );
wp_agent_security_assert( array( 'https://example.com/' ) === ( $valid_post['metadata']['source_urls'] ?? array() ), 'Source URLs should be deduplicated and retained.' );
$summary['content_metadata'] += 2;

$invalid_source_cases = array(
    'not-array' => 'https://example.com/',
    'ftp'       => array( 'ftp://example.com/file' ),
    'relative'  => array( '/relative/source' ),
    'localhost' => array( 'http://localhost/' ),
    'loopback'  => array( 'http://127.0.0.1/' ),
    'metadata'  => array( 'http://169.254.169.254/latest/meta-data/' ),
);
foreach ( $invalid_source_cases as $label => $source_urls ) {
    $title = 'WP Agent Security Invalid Source ' . $label . ' ' . wp_generate_uuid4();
    wp_agent_security_expect_error( $posts->execute( array(
        'action'      => 'create',
        'title'       => $title,
        'content'     => '<p>This post must not be created.</p>',
        'status'      => 'draft',
        'source_urls' => $source_urls,
    ) ), 'Invalid source URL metadata for ' . $label );
    wp_agent_security_assert( ! wp_agent_security_post_title_exists( $title ), 'Invalid source URL metadata must fail before creating a post: ' . $label );
    $summary['content_metadata']++;
}

$unchanged_title = get_post_field( 'post_title', $valid_post['post_id'] );
$edit_result = $posts->execute( array(
    'action'      => 'edit',
    'post_id'     => $valid_post['post_id'],
    'title'       => 'WP Agent Security Mutated Title',
    'source_urls' => array( 'http://127.0.0.1/' ),
) );
wp_agent_security_expect_error( $edit_result, 'Invalid source URL edit metadata' );
wp_agent_security_assert( $unchanged_title === get_post_field( 'post_title', $valid_post['post_id'] ), 'Invalid source URL edit must not mutate the post title.' );
$summary['content_metadata'] += 2;

$text_attachment_id = wp_agent_security_text_attachment();
$image_title = 'WP Agent Security Non Image Featured ' . wp_generate_uuid4();
wp_agent_security_expect_error( $posts->execute( array(
    'action'            => 'create',
    'title'             => $image_title,
    'content'           => '<p>This post must not be created.</p>',
    'status'            => 'draft',
    'featured_image_id' => $text_attachment_id,
) ), 'Non-image featured attachment' );
wp_agent_security_assert( ! wp_agent_security_post_title_exists( $image_title ), 'Non-image featured attachment must fail before creating a post.' );
$summary['content_metadata']++;

echo wp_json_encode( array(
    'success' => true,
    'summary' => $summary,
    'runtime_root' => $runtime_root,
) ) . "\n";
