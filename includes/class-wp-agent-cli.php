<?php
/**
 * WP-CLI commands for WP Agent.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAgent_CLI {

    /**
     * Register WP-CLI commands.
     */
    public static function register() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'wp-agent worker', array( __CLASS__, 'worker' ) );
            WP_CLI::add_command( 'wp-agent daemon', array( __CLASS__, 'daemon' ) );
            WP_CLI::add_command( 'wp-agent skills', array( __CLASS__, 'skills' ) );
            WP_CLI::add_command( 'wp-agent diagnostics', array( __CLASS__, 'diagnostics' ) );
        }
    }

    /**
     * Run the autonomous agent worker.
     *
     * ## OPTIONS
     *
     * [--max-seconds=<seconds>]
     * : Maximum runtime for this process. Default: 300.
     *
     * [--sleep=<seconds>]
     * : Sleep between idle polls. Default: 2.
     *
     * [--batch=<count>]
     * : Number of steps to process per tick. Default: 1.
     *
     * [--once]
     * : Process one tick and exit.
     *
     * ## EXAMPLES
     *
     *     wp wp-agent worker --max-seconds=300 --sleep=2 --batch=1
     *
     * @param array $args
     * @param array $assoc_args
     */
    public static function worker( $args, $assoc_args ) {
        WPAgent_Worker::run_loop(
            array(
                'max_seconds' => isset( $assoc_args['max-seconds'] ) ? (int) $assoc_args['max-seconds'] : 300,
                'sleep'       => isset( $assoc_args['sleep'] ) ? (int) $assoc_args['sleep'] : 2,
                'batch'       => isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 1,
                'once'        => isset( $assoc_args['once'] ),
            ),
            function( $line ) {
                WP_CLI::log( $line );
            }
        );

        WP_CLI::success( 'WP Agent worker stopped.' );
    }

    /**
     * Manage the native PHP agent daemon.
     *
     * ## OPTIONS
     *
     * <action>
     * : One of status, wake, start, stop, run, watchdog.
     *
     * [--max-children=<count>]
     * : Maximum child agents for wake/run. Default uses saved configuration.
     *
     * [--idle-sleep=<seconds>]
     * : Sleep between idle daemon polls. Default: 2.
     *
     * [--max-seconds=<seconds>]
     * : Foreground run duration. 0 means forever. Default: 0.
     *
     * [--max-jobs=<count>]
     * : Stop or rotate the daemon after this many processed jobs. 0 means unlimited.
     *
     * [--max-lifetime=<seconds>]
     * : Stop or rotate the daemon after this many seconds. 0 means unlimited.
     *
     * [--max-idle-time=<seconds>]
     * : Stop or rotate the daemon after this many idle seconds. 0 means unlimited.
     *
     * [--memory-soft-limit=<mb>]
     * : Run garbage collection after this memory threshold. Default: 192.
     *
     * [--memory-hard-limit=<mb>]
     * : Stop or rotate the daemon after this memory threshold. Default: 256.
     *
     * [--once]
     * : Foreground run processes one daemon tick and exits.
     *
     * ## EXAMPLES
     *
     *     wp wp-agent daemon wake --max-children=3
     *     wp wp-agent daemon watchdog
     *     wp wp-agent daemon status
     *     wp wp-agent daemon stop
     *     wp wp-agent daemon run --once
     *
     * @param array $args
     * @param array $assoc_args
     */
    public static function daemon( $args, $assoc_args ) {
        $action = isset( $args[0] ) ? sanitize_key( $args[0] ) : 'status';

        if ( in_array( $action, array( 'wake', 'start' ), true ) ) {
            $result = WPAgent_Daemon::wake( self::daemon_lifecycle_args( $assoc_args ) );
            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            }
            WP_CLI::success( ! empty( $result['started'] ) ? 'Daemon wake requested.' : 'Daemon already running.' );
            WP_CLI::log( wp_json_encode( WPAgent_Daemon::status(), JSON_PRETTY_PRINT ) );
            return;
        }

        if ( 'stop' === $action ) {
            $result = WPAgent_Daemon::request_stop();
            if ( is_wp_error( $result ) ) {
                WP_CLI::error( $result->get_error_message() );
            }
            WP_CLI::success( 'Daemon stop requested.' );
            WP_CLI::log( wp_json_encode( WPAgent_Daemon::status(), JSON_PRETTY_PRINT ) );
            return;
        }

        if ( 'run' === $action ) {
            $result = WPAgent_Daemon::run(
                array_merge( self::daemon_lifecycle_args( $assoc_args ), array( 'once' => isset( $assoc_args['once'] ) ) ),
                function( $line ) {
                    WP_CLI::log( $line );
                }
            );
            if ( empty( $result['ok'] ) ) {
                WP_CLI::error( $result['error'] ?? 'Daemon run failed.' );
            }
            WP_CLI::success( 'Daemon foreground run stopped.' );
            return;
        }

        if ( 'watchdog' === $action ) {
            $result = WPAgent_Daemon::watchdog( self::daemon_lifecycle_args( $assoc_args ) );
            if ( is_wp_error( $result ) ) {
                WP_CLI::warning( $result->get_error_message() );
                WP_CLI::log( wp_json_encode( WPAgent_Daemon::status(), JSON_PRETTY_PRINT ) );
                return;
            }
            WP_CLI::success( 'Daemon watchdog check completed.' );
            WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
            return;
        }

        if ( 'status' !== $action ) {
            WP_CLI::error( 'Unknown daemon action. Use status, wake, start, stop, run, or watchdog.' );
        }

        WP_CLI::log( wp_json_encode( WPAgent_Daemon::status(), JSON_PRETTY_PRINT ) );
    }

    /**
     * Print lightweight runtime diagnostics.
     *
     * ## EXAMPLES
     *
     *     wp wp-agent diagnostics
     */
    public static function diagnostics( $args = array(), $assoc_args = array() ) {
        unset( $args, $assoc_args );
        WP_CLI::log( wp_json_encode( WPAgent_Diagnostics::runtime(), JSON_PRETTY_PRINT ) );
    }

    /**
     * Manage WP Agent Skills and private runtime indexes.
     *
     * ## OPTIONS
     *
     * <action>
     * : One of list, search, get, templates, installed, quarantine, sync-index,
     *   install-github, activate-quarantine, check-package-update,
     *   refresh-package, pin-package, unpin-package, rollbacks, rollback-package.
     *
     * [--owner=<user>]
     * : Skill owner ID, login, or email. Defaults to current user or user ID 1.
     *
     * [--slug=<slug>]
     * : Skill or package slug.
     *
     * [--query=<query>]
     * : Search query for `search`.
     *
     * [--limit=<count>]
     * : Maximum list count. Default: 20.
     *
     * [--local]
     * : For sync-index, sync local runtime Skill mirrors.
     *
     * [--packages]
     * : For sync-index, sync active installed package runtime files.
     *
     * [--repository=<repository>]
     * : GitHub repository for install-github, using owner/repo format. Optional when a default store repository is configured.
     *
     * [--skill-path=<path>]
     * : Skill directory or SKILL.md path for install-github. Optional when a default store path is configured.
     *
     * [--ref=<ref>]
     * : Git ref for install-github. Uses the configured default ref, then main, when omitted.
     *
     * [--github-token=<token>]
     * : Optional one-shot token for private GitHub repositories.
     *
     * [--quarantine-id=<id>]
     * : Quarantine package id for activate-quarantine.
     *
     * [--rollback-id=<id>]
     * : Optional rollback snapshot id for rollback-package.
     *
     * [--force]
     * : Explicitly bypass a pinned package guard for activate-quarantine or rollback-package.
     *
     * [--format=<format>]
     * : Output format. Currently json is emitted for automation.
     *
     * ## EXAMPLES
     *
     *     wp wp-agent skills sync-index --owner=1
     *     wp wp-agent skills installed --format=json
     *     wp wp-agent skills install-github --repository=owner/repo --skill-path=skills/news
     *
     * @param array $args
     * @param array $assoc_args
     */
    public static function skills( $args, $assoc_args ) {
        $action  = isset( $args[0] ) ? sanitize_key( $args[0] ) : 'list';
        $owner   = self::resolve_skill_owner( $assoc_args['owner'] ?? '' );
        $limit   = max( 1, min( (int) ( $assoc_args['limit'] ?? 20 ), 100 ) );
        $payload = null;

        if ( is_wp_error( $owner ) ) {
            WP_CLI::error( $owner->get_error_message() );
        }

        switch ( $action ) {
            case 'list':
                $payload = array(
                    'success' => true,
                    'owner'   => $owner,
                    'skills'  => WPAgent_Skills::all( $owner, $limit ),
                );
                break;

            case 'search':
                $payload = array(
                    'success' => true,
                    'owner'   => $owner,
                    'skills'  => WPAgent_Skills::search( $owner, $assoc_args['query'] ?? '', $limit ),
                );
                break;

            case 'get':
                $skill = WPAgent_Skills::get_by_slug( $owner, $assoc_args['slug'] ?? '' );
                if ( ! $skill ) {
                    WP_CLI::error( 'Skill not found.' );
                }
                $payload = array(
                    'success' => true,
                    'owner'   => $owner,
                    'skill'   => $skill,
                );
                break;

            case 'templates':
                $payload = array(
                    'success'   => true,
                    'templates' => WPAgent_Skills::built_in_templates( isset( $assoc_args['include-body'] ) ),
                );
                break;

            case 'installed':
                $payload = array(
                    'success'  => true,
                    'packages' => WPAgent_Skills::installed_packages( $limit ),
                );
                break;

            case 'quarantine':
                $payload = array(
                    'success'  => true,
                    'packages' => WPAgent_Skills::quarantine_list( $limit ),
                );
                break;

            case 'sync-index':
                $sync_local    = isset( $assoc_args['local'] );
                $sync_packages = isset( $assoc_args['packages'] );
                if ( ! $sync_local && ! $sync_packages ) {
                    $sync_local    = true;
                    $sync_packages = true;
                }

                $payload = array(
                    'success' => true,
                    'owner'   => $owner,
                    'local'   => $sync_local ? WPAgent_Skills::sync_local_runtime_index( $owner ) : null,
                    'packages' => $sync_packages ? WPAgent_Skills::sync_installed_package_index( $owner ) : null,
                );
                self::cli_error_if_nested_wp_error( $payload );
                break;

            case 'install-github':
                $result = WPAgent_Skills::install_from_github( $owner, array(
                    'repository'   => $assoc_args['repository'] ?? '',
                    'skill_path'   => $assoc_args['skill-path'] ?? ( $assoc_args['skill_path'] ?? '' ),
                    'ref'          => $assoc_args['ref'] ?? '',
                    'github_token' => $assoc_args['github-token'] ?? '',
                ) );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result->get_error_message() );
                }
                $payload = $result;
                break;

            case 'activate-quarantine':
                $result = WPAgent_Skills::activate_quarantined( $owner, $assoc_args['quarantine-id'] ?? ( $assoc_args['quarantine_id'] ?? '' ), isset( $assoc_args['force'] ) );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result->get_error_message() );
                }
                $payload = $result;
                break;

            case 'check-package-update':
                $result = WPAgent_Skills::check_package_update( $assoc_args['slug'] ?? '' );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result->get_error_message() );
                }
                $payload = $result;
                break;

            case 'refresh-package':
                $result = WPAgent_Skills::refresh_package_from_source( $owner, $assoc_args['slug'] ?? '' );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result->get_error_message() );
                }
                $payload = $result;
                break;

            case 'pin-package':
            case 'unpin-package':
                $result = WPAgent_Skills::pin_package( $owner, $assoc_args['slug'] ?? '', 'pin-package' === $action );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result->get_error_message() );
                }
                $payload = $result;
                break;

            case 'rollbacks':
                $payload = array(
                    'success'   => true,
                    'slug'      => sanitize_title( $assoc_args['slug'] ?? '' ),
                    'rollbacks' => WPAgent_Skills::package_rollbacks( $assoc_args['slug'] ?? '', $limit ),
                );
                break;

            case 'rollback-package':
                $result = WPAgent_Skills::rollback_package( $owner, $assoc_args['slug'] ?? '', $assoc_args['rollback-id'] ?? ( $assoc_args['rollback_id'] ?? '' ), isset( $assoc_args['force'] ) );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result->get_error_message() );
                }
                $payload = $result;
                break;

            default:
                WP_CLI::error( 'Unknown skills action. Use list, search, get, templates, installed, quarantine, sync-index, install-github, activate-quarantine, check-package-update, refresh-package, pin-package, unpin-package, rollbacks, or rollback-package.' );
        }

        self::cli_log_json( $payload );
    }

    /**
     * Normalize daemon lifecycle options shared by wake, run, and watchdog.
     *
     * @param array $assoc_args WP-CLI associative args.
     * @return array
     */
    private static function daemon_lifecycle_args( $assoc_args ) {
        return array(
            'max_children'      => isset( $assoc_args['max-children'] ) ? (int) $assoc_args['max-children'] : WPAgent_Daemon::configured_max_children(),
            'idle_sleep'        => isset( $assoc_args['idle-sleep'] ) ? (int) $assoc_args['idle-sleep'] : WPAgent_Daemon::DEFAULT_IDLE_SLEEP,
            'max_seconds'       => isset( $assoc_args['max-seconds'] ) ? (int) $assoc_args['max-seconds'] : 0,
            'max_jobs'          => isset( $assoc_args['max-jobs'] ) ? (int) $assoc_args['max-jobs'] : 0,
            'max_lifetime'      => isset( $assoc_args['max-lifetime'] ) ? (int) $assoc_args['max-lifetime'] : 0,
            'max_idle_time'     => isset( $assoc_args['max-idle-time'] ) ? (int) $assoc_args['max-idle-time'] : 0,
            'memory_soft_limit' => isset( $assoc_args['memory-soft-limit'] ) ? (int) $assoc_args['memory-soft-limit'] : WPAgent_Daemon::DEFAULT_MEMORY_SOFT,
            'memory_hard_limit' => isset( $assoc_args['memory-hard-limit'] ) ? (int) $assoc_args['memory-hard-limit'] : WPAgent_Daemon::DEFAULT_MEMORY_HARD,
        );
    }

    /**
     * Resolve a Skill owner from CLI input.
     *
     * @param string $owner User id, login, or email.
     * @return int|WP_Error
     */
    private static function resolve_skill_owner( $owner ) {
        $owner = trim( (string) $owner );
        if ( '' === $owner ) {
            $current = get_current_user_id();
            return $current > 0 ? $current : 1;
        }

        if ( ctype_digit( $owner ) ) {
            $user = get_user_by( 'id', (int) $owner );
        } elseif ( false !== strpos( $owner, '@' ) ) {
            $user = get_user_by( 'email', $owner );
        } else {
            $user = get_user_by( 'login', $owner );
        }

        if ( ! $user ) {
            return new WP_Error( 'wp_agent_cli_owner', 'Skill owner user was not found.' );
        }

        return (int) $user->ID;
    }

    /**
     * Emit JSON for scriptable WP-CLI usage.
     *
     * @param mixed $payload Payload.
     */
    private static function cli_log_json( $payload ) {
        WP_CLI::log( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Fail if a nested payload contains a WP_Error.
     *
     * @param mixed $payload Payload.
     */
    private static function cli_error_if_nested_wp_error( $payload ) {
        if ( is_wp_error( $payload ) ) {
            WP_CLI::error( $payload->get_error_message() );
        }
        if ( is_array( $payload ) ) {
            foreach ( $payload as $value ) {
                self::cli_error_if_nested_wp_error( $value );
            }
        }
    }
}
