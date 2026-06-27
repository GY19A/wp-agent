<?php
/**
 * Sandbox — a jailed per-scope filesystem area for agent file operations.
 *
 * Provides a safe, allow-listed working directory under the WordPress
 * private non-web filesystem location where the agent can read, write, list,
 * and delete a limited set of text-like files. All paths are confined to the
 * jail via a robust containment check that rejects traversal and absolute paths.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Jailed filesystem sandbox.
 */
class WPAgent_Sandbox {

    /**
     * File extensions the sandbox is allowed to write/serve.
     *
     * @var string[]
     */
    const ALLOWED_EXT = array( 'md', 'markdown', 'html', 'htm', 'txt', 'json', 'csv', 'xml' );

    /**
     * Maximum size (in bytes) of a single file.
     *
     * @var int
     */
    const MAX_BYTES = 2097152;

    /**
     * Maximum number of files allowed in the sandbox.
     *
     * @var int
     */
    const MAX_FILES = 200;

    /**
     * Sanitized scope name (used as the jail subdirectory).
     *
     * @var string
     */
    private $scope;

    /**
     * Optional private base directory for this sandbox.
     *
     * @var string
     */
    private $base_dir = '';

    /**
     * Cached absolute jail directory (no trailing slash).
     *
     * @var string
     */
    private $root = '';

    /**
     * Constructor.
     *
     * @param string $scope    Scope name; sanitized to [a-z0-9_-]. Empty falls back to 'shared'.
     * @param string $base_dir Optional private base directory under the site runtime root.
     */
    public function __construct( $scope, $base_dir = '' ) {
        $scope = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $scope ) );
        if ( '' === $scope ) {
            $scope = 'shared';
        }
        $this->scope = $scope;

        if ( '' !== (string) $base_dir ) {
            $this->base_dir = self::normalize_owned_base_dir( $base_dir );
        }
    }

    /**
     * Get (and lazily create) the absolute jail directory.
     *
     * Creates the directory and writes guard files (index.php, .htaccess)
     * on first use if they are missing. If the directory cannot be created
     * (e.g. the uploads folder is not writable by the current user), a
     * WP_Error is returned so callers can surface the true cause instead of
     * misreporting it as a path-escape security failure.
     *
     * @return string|WP_Error Absolute jail directory path (no trailing slash), or WP_Error.
     */
    public function root() {
        if ( '' !== $this->root ) {
            return $this->root;
        }

        $base_dir = '' !== $this->base_dir ? $this->base_dir : self::base_dir();
        $base     = trailingslashit( $base_dir ) . $this->scope;

        if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
            return new WP_Error(
                'wp_agent_sandbox_root',
                sprintf( 'Failed to create sandbox directory: %s', $base )
            );
        }

        if ( ! is_dir( $base ) || ! wp_is_writable( $base ) ) {
            return new WP_Error(
                'wp_agent_sandbox_root',
                sprintf( 'Sandbox directory is not writable: %s', $base )
            );
        }

        // Process-private workspace: only the owning runtime user may access it.
        @chmod( $base_dir, 0700 );
        @chmod( $base, 0700 );

        $this->root = untrailingslashit( $base );

        return $this->root;
    }

    /**
     * Private sandbox base directory outside the web document root.
     *
     * @return string Absolute base directory.
     */
    public static function base_dir() {
        return self::runtime_area_dir( 'workspaces' );
    }

    /**
     * Private site-scoped runtime root.
     *
     * @return string Absolute site runtime root.
     */
    public static function site_runtime_root() {
        $sites_dir = trailingslashit( self::runtime_root() ) . 'sites';
        if ( ! is_dir( $sites_dir ) ) {
            wp_mkdir_p( $sites_dir );
        }
        @chmod( $sites_dir, 0700 );

        $site_root = trailingslashit( $sites_dir ) . self::site_hash();
        self::ensure_runtime_root_dir( $site_root );
        return untrailingslashit( $site_root );
    }

    /**
     * Private site-scoped runtime area directory.
     *
     * @param string $area Runtime area name.
     * @return string Absolute area directory.
     */
    public static function runtime_area_dir( $area ) {
        $area = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $area ) );
        if ( '' === $area ) {
            $area = 'misc';
        }

        $dir = trailingslashit( self::site_runtime_root() ) . $area;
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        @chmod( $dir, 0700 );
        return untrailingslashit( $dir );
    }

    /**
     * Private runtime root for sandbox and execution artifacts.
     *
     * @return string Absolute runtime root directory.
     */
    public static function runtime_root() {
        $selection = self::runtime_root_selection();
        if ( '' !== (string) ( $selection['runtime_root'] ?? '' ) ) {
            return (string) $selection['runtime_root'];
        }

        $fallback = untrailingslashit( trailingslashit( sys_get_temp_dir() ) . 'wp-agent/' . self::site_hash() );
        self::ensure_runtime_root_dir( $fallback );
        return $fallback;
    }

    /**
     * Return the active runtime root plus source diagnostics.
     *
     * This mirrors runtime_root() candidate priority while exposing enough
     * metadata for UI and CLI diagnostics to explain why a path was selected.
     *
     * @return array
     */
    public static function runtime_root_selection() {
        $candidate_statuses = array();

        foreach ( self::runtime_root_candidates_with_sources() as $candidate ) {
            $source = (string) ( $candidate['source'] ?? '' );
            $path   = (string) ( $candidate['path'] ?? '' );
            $status = self::runtime_root_status( $path, true );
            $entry  = array(
                'source'       => $source,
                'source_label' => self::runtime_root_source_label( $source ),
                'path'         => $path,
                'status'       => $status,
            );
            $candidate_statuses[] = $entry;

            if ( ! empty( $status['ok'] ) && '' !== (string) ( $status['normalized'] ?? '' ) ) {
                return array(
                    'runtime_root' => untrailingslashit( (string) $status['normalized'] ),
                    'source'       => $source,
                    'source_label' => self::runtime_root_source_label( $source ),
                    'candidates'   => $candidate_statuses,
                );
            }
        }

        $fallback = untrailingslashit( trailingslashit( sys_get_temp_dir() ) . 'wp-agent/' . self::site_hash() );
        self::ensure_runtime_root_dir( $fallback );
        $status = self::runtime_root_status( $fallback, false );
        $candidate_statuses[] = array(
            'source'       => 'emergency_temp',
            'source_label' => self::runtime_root_source_label( 'emergency_temp' ),
            'path'         => $fallback,
            'status'       => $status,
        );

        return array(
            'runtime_root' => $fallback,
            'source'       => 'emergency_temp',
            'source_label' => self::runtime_root_source_label( 'emergency_temp' ),
            'candidates'   => $candidate_statuses,
        );
    }

    /**
     * Human-readable label for runtime root source identifiers.
     *
     * @param string $source Source identifier.
     * @return string
     */
    public static function runtime_root_source_label( $source ) {
        switch ( (string) $source ) {
            case 'constant':
                return __( 'Constant path', 'wp-agent' );
            case 'environment':
                return __( 'Environment path', 'wp-agent' );
            case 'setting':
                return __( 'Settings path', 'wp-agent' );
            case 'wp_content_parent':
                return __( 'WordPress parent fallback', 'wp-agent' );
            case 'system_temp':
                return __( 'Server temp fallback', 'wp-agent' );
            case 'legacy_temp':
                return __( 'Legacy temp fallback', 'wp-agent' );
            case 'emergency_temp':
                return __( 'Emergency temp fallback', 'wp-agent' );
        }

        return __( 'Unknown source', 'wp-agent' );
    }

    /**
     * Explain whether a configured runtime root can be used safely.
     *
     * This is intentionally side-effect free unless $create is true. Admin UI
     * and diagnostics can use it to show precise rejection reasons instead of
     * a generic fallback path.
     *
     * @param string $path   Candidate runtime root.
     * @param bool   $create Whether to create the directory if it is otherwise valid.
     * @return array
     */
    public static function runtime_root_status( $path, $create = false ) {
        $raw = is_string( $path ) ? trim( $path ) : '';
        $status = array(
            'input'      => $raw,
            'normalized' => '',
            'ok'         => false,
            'code'       => '',
            'message'    => '',
            'exists'     => false,
            'writable'   => false,
            'created'    => false,
        );

        if ( '' === $raw ) {
            $status['code']    = 'empty';
            $status['message'] = __( 'No custom runtime root is configured.', 'wp-agent' );
            return $status;
        }

        $path = str_replace( '\\', '/', $raw );
        if ( false !== strpos( $path, "\0" ) ) {
            $status['code']    = 'invalid';
            $status['message'] = __( 'Runtime root contains an invalid null byte.', 'wp-agent' );
            return $status;
        }

        if ( ! self::is_absolute_path( wp_normalize_path( $path ) ) ) {
            $status['code']    = 'not_absolute';
            $status['message'] = __( 'Runtime root must be an absolute filesystem path.', 'wp-agent' );
            return $status;
        }

        if ( preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
            $status['code']    = 'traversal';
            $status['message'] = __( 'Runtime root must not contain parent-directory traversal segments.', 'wp-agent' );
            return $status;
        }

        $normalized = self::normalize_runtime_root( $path );
        $status['normalized'] = $normalized;
        if ( '' === $normalized ) {
            $status['code']    = 'invalid';
            $status['message'] = __( 'Runtime root could not be normalized.', 'wp-agent' );
            return $status;
        }

        if ( ! self::is_allowed_runtime_root( $normalized ) ) {
            $status['code']    = 'public_path';
            $status['message'] = __( 'Runtime root must be outside WordPress core, wp-content, plugins, themes, and uploads.', 'wp-agent' );
            return $status;
        }

        if ( ! is_dir( $normalized ) ) {
            if ( $create ) {
                if ( ! wp_mkdir_p( $normalized ) ) {
                    $status['code']    = 'create_failed';
                    $status['message'] = __( 'Runtime root could not be created by PHP.', 'wp-agent' );
                    return $status;
                }
                @chmod( $normalized, 0700 );
                $status['created'] = true;
            } else {
                $status['code']    = 'missing';
                $status['message'] = __( 'Runtime root does not exist yet.', 'wp-agent' );
                return $status;
            }
        }

        $status['exists']   = is_dir( $normalized );
        $status['writable'] = $status['exists'] && wp_is_writable( $normalized );
        if ( ! $status['writable'] ) {
            $status['code']    = 'not_writable';
            $status['message'] = __( 'Runtime root is not writable by PHP.', 'wp-agent' );
            return $status;
        }

        @chmod( $normalized, 0700 );
        $status['ok']      = true;
        $status['code']    = 'ok';
        $status['message'] = __( 'Runtime root is private, writable, and outside public WordPress paths.', 'wp-agent' );
        return $status;
    }

    /**
     * Candidate runtime roots with source metadata in priority order.
     *
     * @return array<int,array{source:string,path:string}>
     */
    private static function runtime_root_candidates_with_sources() {
        $candidates = array();
        $seen       = array();
        $add        = function( $source, $path ) use ( &$candidates, &$seen ) {
            $path = is_string( $path ) ? trim( $path ) : '';
            if ( '' === $path ) {
                return;
            }

            $key = str_replace( '\\', '/', $path );
            if ( isset( $seen[ $key ] ) ) {
                return;
            }

            $seen[ $key ] = true;
            $candidates[] = array(
                'source' => (string) $source,
                'path'   => $path,
            );
        };

        if ( defined( 'WP_AGENT_RUNTIME_ROOT' ) && '' !== WP_AGENT_RUNTIME_ROOT ) {
            $add( 'constant', (string) WP_AGENT_RUNTIME_ROOT );
        }

        $env = getenv( 'WP_AGENT_RUNTIME_ROOT' );
        if ( false !== $env && '' !== trim( (string) $env ) ) {
            $add( 'environment', (string) $env );
        }

        $configured = get_option( 'wp_agent_runtime_root', '' );
        if ( is_string( $configured ) && '' !== trim( $configured ) ) {
            $add( 'setting', $configured );
        }

        if ( defined( 'WP_CONTENT_DIR' ) && '' !== WP_CONTENT_DIR ) {
            $add( 'wp_content_parent', dirname( WP_CONTENT_DIR ) . '/wp-agent-runtime' );
        }

        $add( 'system_temp', trailingslashit( sys_get_temp_dir() ) . 'wp-agent/' . self::site_hash() );
        $add( 'legacy_temp', trailingslashit( sys_get_temp_dir() ) . 'wp-agent-' . self::site_hash() );

        return $candidates;
    }

    /**
     * Normalize a candidate runtime root and reject malformed paths.
     *
     * @param string $path Raw candidate path.
     * @return string Normalized absolute path, or empty string.
     */
    private static function normalize_runtime_root( $path ) {
        $path = trim( (string) $path );
        if ( '' === $path || false !== strpos( $path, "\0" ) ) {
            return '';
        }

        if ( function_exists( 'wp_normalize_path' ) ) {
            $path = wp_normalize_path( $path );
        } else {
            $path = str_replace( '\\', '/', $path );
        }

        if ( ! self::is_absolute_path( $path ) ) {
            return '';
        }

        $resolved = self::resolve_existing_ancestor_path( $path );
        return '' !== $resolved ? untrailingslashit( $resolved ) : untrailingslashit( $path );
    }

    /**
     * Return whether a path is absolute on POSIX or Windows.
     *
     * @param string $path Candidate path.
     * @return bool
     */
    private static function is_absolute_path( $path ) {
        return ( isset( $path[0] ) && '/' === $path[0] ) || (bool) preg_match( '/^[A-Za-z]:\//', $path );
    }

    /**
     * Resolve a non-existing path through its deepest existing ancestor.
     *
     * @param string $path Absolute path.
     * @return string
     */
    private static function resolve_existing_ancestor_path( $path ) {
        $real = realpath( $path );
        if ( false !== $real ) {
            return self::normalize_compare_path( $real );
        }

        $tail = array();
        $cursor = $path;
        while ( true ) {
            $parent = dirname( $cursor );
            if ( $parent === $cursor ) {
                return '';
            }

            $real_parent = realpath( $parent );
            if ( false !== $real_parent ) {
                $tail[] = basename( $cursor );
                $tail = array_reverse( $tail );
                return untrailingslashit( self::normalize_compare_path( $real_parent ) . '/' . implode( '/', $tail ) );
            }

            $tail[] = basename( $cursor );
            $cursor = $parent;
        }
    }

    /**
     * Reject runtime roots in public WordPress/plugin locations.
     *
     * @param string $root Normalized absolute root.
     * @return bool
     */
    private static function is_allowed_runtime_root( $root ) {
        $root = self::normalize_compare_path( $root );
        if ( '' === $root ) {
            return false;
        }

        $blocked = array();
        foreach ( array( 'ABSPATH', 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
            if ( defined( $constant ) && '' !== constant( $constant ) ) {
                $blocked[] = constant( $constant );
            }
        }
        if ( defined( 'WP_AGENT_PLUGIN_DIR' ) ) {
            $blocked[] = WP_AGENT_PLUGIN_DIR;
        }

        $uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
        if ( ! empty( $uploads['basedir'] ) ) {
            $blocked[] = $uploads['basedir'];
        }
        if ( defined( 'WP_CONTENT_DIR' ) ) {
            $blocked[] = trailingslashit( WP_CONTENT_DIR ) . 'themes';
            $blocked[] = trailingslashit( WP_CONTENT_DIR ) . 'plugins';
            $blocked[] = trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
            $blocked[] = trailingslashit( WP_CONTENT_DIR ) . 'uploads';
        }

        foreach ( array_filter( $blocked ) as $path ) {
            $path = self::normalize_runtime_root( $path );
            if ( '' !== $path && self::path_contains_or_equals( $path, $root ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create a private runtime directory if possible.
     *
     * @param string $root Runtime root.
     * @return bool
     */
    private static function ensure_runtime_root_dir( $root ) {
        if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
            return false;
        }
        if ( ! is_dir( $root ) || ! wp_is_writable( $root ) ) {
            return false;
        }
        @chmod( $root, 0700 );
        return true;
    }

    /**
     * Site-specific stable hash for default temp runtime root.
     *
     * @return string
     */
    public static function site_hash() {
        $source = '';
        if ( function_exists( 'home_url' ) ) {
            $source .= home_url();
        }
        if ( defined( 'ABSPATH' ) ) {
            $source .= '|' . ABSPATH;
        }
        if ( function_exists( 'get_current_blog_id' ) ) {
            $source .= '|' . get_current_blog_id();
        }
        return substr( preg_replace( '/[^a-z0-9]/', '', strtolower( wp_hash( $source ) ) ), 0, 16 );
    }

    /**
     * Normalize an optional sandbox base directory and keep it under site runtime root.
     *
     * @param string $base_dir Candidate base directory.
     * @return string Normalized private base directory, or empty string.
     */
    private static function normalize_owned_base_dir( $base_dir ) {
        $base_dir  = self::normalize_runtime_root( $base_dir );
        $site_root = self::site_runtime_root();

        if ( '' === $base_dir || ! self::path_contains_or_equals( $site_root, $base_dir ) ) {
            return '';
        }

        return $base_dir;
    }

    /**
     * Normalize path for comparison.
     *
     * @param string $path Raw path.
     * @return string
     */
    private static function normalize_compare_path( $path ) {
        $path = str_replace( '\\', '/', (string) $path );
        if ( function_exists( 'wp_normalize_path' ) ) {
            $path = wp_normalize_path( $path );
        }
        return untrailingslashit( $path );
    }

    /**
     * Return true when $child equals $parent or is inside it.
     *
     * @param string $parent Parent path.
     * @param string $child  Candidate child path.
     * @return bool
     */
    private static function path_contains_or_equals( $parent, $child ) {
        $parent = self::normalize_compare_path( $parent );
        $child  = self::normalize_compare_path( $child );
        return $child === $parent || 0 === strpos( $child . '/', trailingslashit( $parent ) );
    }

    /**
     * Resolve a relative path to an absolute path inside the jail.
     *
     * Rejects non-strings, null bytes, absolute paths, drive letters, and
     * any '..' segment. Performs a realpath-based containment check against
     * the deepest existing ancestor so the result is guaranteed to stay
     * under the jail root.
     *
     * @param string $rel Relative path within the sandbox.
     * @return string|WP_Error Absolute path, or WP_Error on escape.
     */
    public function resolve( $rel ) {
        if ( ! is_string( $rel ) ) {
            return new WP_Error( 'wp_agent_sandbox_path', 'Path escapes sandbox.' );
        }

        // Reject null bytes.
        if ( false !== strpos( $rel, "\0" ) ) {
            return new WP_Error( 'wp_agent_sandbox_path', 'Path escapes sandbox.' );
        }

        // Reject absolute paths (POSIX or Windows) and drive letters.
        if ( '' !== $rel && ( '/' === $rel[0] || '\\' === $rel[0] ) ) {
            return new WP_Error( 'wp_agent_sandbox_path', 'Path escapes sandbox.' );
        }
        if ( preg_match( '/^[A-Za-z]:/', $rel ) ) {
            return new WP_Error( 'wp_agent_sandbox_path', 'Path escapes sandbox.' );
        }

        // Reject any '..' segment (covers both separators).
        $segments = preg_split( '#[/\\\\]+#', $rel );
        foreach ( $segments as $segment ) {
            if ( '..' === $segment ) {
                return new WP_Error( 'wp_agent_sandbox_path', 'Path escapes sandbox.' );
            }
        }
        if ( $this->is_private_rel( $rel ) ) {
            return new WP_Error( 'wp_agent_sandbox_private', 'Path is reserved for internal sandbox state.' );
        }

        $root = $this->root();
        if ( is_wp_error( $root ) ) {
            // Directory could not be created/accessed — surface the true cause
            // rather than misreporting a legitimate path as a sandbox escape.
            return $root;
        }

        $target = $root . '/' . ltrim( $rel, '/\\' );

        $real_root = realpath( $root );
        if ( false === $real_root ) {
            return new WP_Error(
                'wp_agent_sandbox_root',
                'Sandbox directory could not be resolved.'
            );
        }

        // Find the deepest existing ancestor of $target and realpath it,
        // then re-append the not-yet-existing tail.
        $real = $this->realpath_existing_ancestor( $target );
        if ( false === $real ) {
            return new WP_Error( 'wp_agent_sandbox_path', 'Path escapes sandbox.' );
        }

        // Containment: resolved candidate must equal root or sit under it.
        $real_root_prefix = untrailingslashit( $real_root ) . DIRECTORY_SEPARATOR;
        if ( $real !== $real_root && 0 !== strpos( $real . DIRECTORY_SEPARATOR, $real_root_prefix ) ) {
            return new WP_Error( 'wp_agent_sandbox_path', 'Path escapes sandbox.' );
        }

        return $target;
    }

    /**
     * Resolve a candidate path by realpath-ing its deepest existing ancestor
     * and re-appending the non-existing trailing segments.
     *
     * @param string $target Absolute candidate path (may not yet exist).
     * @return string|false Resolved absolute path, or false on failure.
     */
    private function realpath_existing_ancestor( $target ) {
        $real = realpath( $target );
        if ( false !== $real ) {
            return $real;
        }

        $tail = array();
        $path = $target;

        // Walk up until we find an existing directory we can realpath.
        while ( true ) {
            $parent = dirname( $path );
            if ( $parent === $path ) {
                // Reached the filesystem root without finding an existing ancestor.
                return false;
            }

            $real_parent = realpath( $parent );
            if ( false !== $real_parent ) {
                $tail[] = basename( $path );
                $tail   = array_reverse( $tail );
                return $real_parent . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $tail );
            }

            $tail[] = basename( $path );
            $path    = $parent;
        }
    }

    /**
     * Write content to a file inside the sandbox.
     *
     * @param string $rel     Relative path.
     * @param string $content File contents.
     * @return array|WP_Error array('rel','path','bytes') or WP_Error.
     */
    public function write( $rel, $content ) {
        $content = (string) $content;

        $ext = strtolower( pathinfo( (string) $rel, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::ALLOWED_EXT, true ) ) {
            return new WP_Error(
                'wp_agent_sandbox_ext',
                sprintf( 'File extension "%s" is not allowed.', $ext )
            );
        }

        if ( strlen( $content ) > self::MAX_BYTES ) {
            return new WP_Error(
                'wp_agent_sandbox_size',
                sprintf( 'File exceeds the maximum size of %d bytes.', self::MAX_BYTES )
            );
        }

        $path = $this->resolve( $rel );
        if ( is_wp_error( $path ) ) {
            return $path;
        }

        // Enforce the file-count cap only when creating a new file.
        if ( ! file_exists( $path ) && $this->count_files() >= self::MAX_FILES ) {
            return new WP_Error(
                'wp_agent_sandbox_limit',
                sprintf( 'Sandbox file limit of %d reached.', self::MAX_FILES )
            );
        }

        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $written = file_put_contents( $path, $content );
        if ( false === $written ) {
            return new WP_Error( 'wp_agent_sandbox_write', 'Failed to write file.' );
        }

        @chmod( $path, 0600 );

        return array(
            'rel'   => $this->relative_to_root( $path ),
            'path'  => $path,
            'bytes' => $written,
        );
    }

    /**
     * Read a file from the sandbox.
     *
     * @param string $rel Relative path.
     * @return string|WP_Error File contents or WP_Error.
     */
    public function read( $rel ) {
        $path = $this->resolve( $rel );
        if ( is_wp_error( $path ) ) {
            return $path;
        }

        if ( ! is_file( $path ) ) {
            return new WP_Error( 'wp_agent_sandbox_missing', 'File not found.' );
        }

        $content = file_get_contents( $path );
        if ( false === $content ) {
            return new WP_Error( 'wp_agent_sandbox_read', 'Failed to read file.' );
        }

        return $content;
    }

    /**
     * List files in the sandbox (recursively) under an optional subdirectory.
     *
     * @param string $rel_dir Relative subdirectory (default: sandbox root).
     * @return array|WP_Error List of array('rel','bytes','modified') or WP_Error.
     */
    public function list( $rel_dir = '' ) {
        $rel_dir = (string) $rel_dir;

        if ( '' === $rel_dir ) {
            $dir = $this->root();
            if ( is_wp_error( $dir ) ) {
                return $dir;
            }
        } else {
            $dir = $this->resolve( $rel_dir );
            if ( is_wp_error( $dir ) ) {
                return $dir;
            }
        }

        $files = array();

        if ( ! is_dir( $dir ) ) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $real = $file->getPathname();
            $rel  = $this->relative_to_root( $real );

            // Skip guard files.
            if ( 'index.php' === $file->getFilename() || '.htaccess' === $file->getFilename() ) {
                continue;
            }
            if ( $this->is_private_rel( $rel ) ) {
                continue;
            }

            $files[] = array(
                'rel'      => $rel,
                'bytes'    => $file->getSize(),
                'modified' => $file->getMTime(),
            );
        }

        return $files;
    }

    /**
     * Delete a file from the sandbox.
     *
     * @param string $rel Relative path.
     * @return bool|WP_Error True on success, WP_Error if missing or on failure.
     */
    public function delete( $rel ) {
        $path = $this->resolve( $rel );
        if ( is_wp_error( $path ) ) {
            return $path;
        }

        if ( ! is_file( $path ) ) {
            return new WP_Error( 'wp_agent_sandbox_missing', 'File not found.' );
        }

        if ( ! @unlink( $path ) ) {
            return new WP_Error( 'wp_agent_sandbox_delete', 'Failed to delete file.' );
        }

        return true;
    }

    /**
     * Count all files currently in the sandbox (recursive).
     *
     * @return int
     */
    private function count_files() {
        $root = $this->root();

        if ( is_wp_error( $root ) || ! is_dir( $root ) ) {
            return 0;
        }

        $count    = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            // Do not count guard files against the user-facing limit.
            if ( 'index.php' === $file->getFilename() || '.htaccess' === $file->getFilename() ) {
                continue;
            }
            if ( $this->is_private_rel( $this->relative_to_root( $file->getPathname() ) ) ) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Internal directories are never exposed through manage_files.
     *
     * @param string $rel Relative path.
     * @return bool
     */
    private function is_private_rel( $rel ) {
        $rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
        return '.exec' === $rel || 0 === strpos( $rel, '.exec/' );
    }

    /**
     * Compute a path's location relative to the jail root.
     *
     * @param string $abs Absolute path inside the jail.
     * @return string Relative path (forward slashes, no leading slash).
     */
    private function relative_to_root( $abs ) {
        $root = $this->root();
        $rel  = $abs;

        if ( is_wp_error( $root ) ) {
            return ltrim( str_replace( '\\', '/', $abs ), '/' );
        }

        if ( 0 === strpos( $abs, $root ) ) {
            $rel = substr( $abs, strlen( $root ) );
        }

        $rel = str_replace( '\\', '/', $rel );

        return ltrim( $rel, '/' );
    }
}
