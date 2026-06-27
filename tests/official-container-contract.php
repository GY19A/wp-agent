<?php
/**
 * Host-side official container contract check.
 *
 * Verifies that docker-compose.official.yml keeps acceptance on official
 * WordPress images without custom builds, privileged containers, sidecars, or
 * VM-oriented device/capability exposure.
 *
 * Run from the host:
 * php tests/official-container-contract.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This official container contract check must run from PHP CLI.\n" );
	exit( 1 );
}

function wp_agent_official_contract_fail( $message, $details = array() ) {
	fwrite( STDERR, "FAIL: " . $message . "\n" );
	if ( ! empty( $details ) ) {
		fwrite( STDERR, json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

function wp_agent_official_contract_assert( $condition, $message, $details = array() ) {
	if ( ! $condition ) {
		wp_agent_official_contract_fail( $message, $details );
	}
}

function wp_agent_official_contract_command( $args ) {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $output ),
	);
}

function wp_agent_official_contract_compose_config( $compose_file ) {
	$result = wp_agent_official_contract_command( array(
		'docker',
		'compose',
		'-p',
		'wp-agent-official',
		'-f',
		$compose_file,
		'--profile',
		'cli',
		'config',
		'--format',
		'json',
	) );
	wp_agent_official_contract_assert( 0 === $result['status'], 'docker compose config failed.', array(
		'exit_status' => $result['status'],
		'output'      => $result['output'],
	) );

	$config = json_decode( $result['output'], true );
	wp_agent_official_contract_assert( is_array( $config ), 'docker compose JSON config could not be decoded.', array(
		'json_error' => json_last_error_msg(),
	) );
	return $config;
}

function wp_agent_official_contract_no_runtime_escalation( $service_name, $service ) {
	$forbidden_keys = array( 'build', 'privileged', 'cap_add', 'devices', 'security_opt', 'pid', 'network_mode' );
	$violations     = array();

	foreach ( $forbidden_keys as $key ) {
		if ( ! array_key_exists( $key, $service ) ) {
			continue;
		}
		if ( 'privileged' === $key && false === $service[ $key ] ) {
			continue;
		}
		if ( is_array( $service[ $key ] ) && empty( $service[ $key ] ) ) {
			continue;
		}
		if ( null === $service[ $key ] ) {
			continue;
		}
		$violations[ $key ] = $service[ $key ];
	}

	wp_agent_official_contract_assert( empty( $violations ), $service_name . ' must not use custom builds, privileged mode, sidecar-style namespaces, or VM/device capabilities.', $violations );
}

function wp_agent_official_contract_volume_exists( $service, $target, $type = null, $source = null ) {
	foreach ( $service['volumes'] ?? array() as $volume ) {
		if ( ( $volume['target'] ?? '' ) !== $target ) {
			continue;
		}
		if ( null !== $type && ( $volume['type'] ?? '' ) !== $type ) {
			continue;
		}
		if ( null !== $source && ( $volume['source'] ?? '' ) !== $source ) {
			continue;
		}
		return true;
	}
	return false;
}

$plugin_dir   = realpath( dirname( __DIR__ ) );
$compose_file = $plugin_dir ? $plugin_dir . DIRECTORY_SEPARATOR . 'docker-compose.official.yml' : '';

wp_agent_official_contract_assert( $plugin_dir && is_dir( $plugin_dir ), 'Plugin directory could not be resolved.' );
wp_agent_official_contract_assert( is_file( $compose_file ), 'docker-compose.official.yml is missing.' );

$config   = wp_agent_official_contract_compose_config( $compose_file );
$services = $config['services'] ?? array();

wp_agent_official_contract_assert( is_array( $services ) && ! empty( $services ), 'Compose config does not define services.' );

$expected_services = array( 'db', 'wordpress', 'wpcli' );
$actual_services   = array_keys( $services );
sort( $expected_services );
sort( $actual_services );
wp_agent_official_contract_assert( $expected_services === $actual_services, 'Official stack must contain only db, wordpress, and wpcli services.', array(
	'expected' => $expected_services,
	'actual'   => $actual_services,
) );

$db        = $services['db'];
$wordpress = $services['wordpress'];
$wpcli     = $services['wpcli'];

wp_agent_official_contract_assert( 'mysql:8.0' === ( $db['image'] ?? '' ), 'Official stack database must use mysql:8.0.', array(
	'image' => $db['image'] ?? '',
) );
wp_agent_official_contract_assert( 'wordpress:php8.3-apache' === ( $wordpress['image'] ?? '' ), 'Official WordPress service must use the official wordpress:php8.3-apache image.', array(
	'image' => $wordpress['image'] ?? '',
) );
wp_agent_official_contract_assert( 'wordpress:cli-php8.3' === ( $wpcli['image'] ?? '' ), 'Official WP-CLI service must use the official wordpress:cli-php8.3 image.', array(
	'image' => $wpcli['image'] ?? '',
) );

wp_agent_official_contract_no_runtime_escalation( 'db', $db );
wp_agent_official_contract_no_runtime_escalation( 'wordpress', $wordpress );
wp_agent_official_contract_no_runtime_escalation( 'wpcli', $wpcli );

$official_db_dir = '/path/to/wp-agent/database/official-mysql';
wp_agent_official_contract_assert( wp_agent_official_contract_volume_exists( $db, '/var/lib/mysql', 'bind', $official_db_dir ), 'Official database must bind-mount the local official-mysql datadir.', array(
	'expected_source' => $official_db_dir,
	'volumes'         => $db['volumes'] ?? array(),
) );
wp_agent_official_contract_assert( ! wp_agent_official_contract_volume_exists( $wordpress, '/var/lib/mysql' ), 'WordPress service must not mount the raw database datadir.' );
wp_agent_official_contract_assert( ! wp_agent_official_contract_volume_exists( $wpcli, '/var/lib/mysql' ), 'WP-CLI service must not mount the raw database datadir.' );
wp_agent_official_contract_assert( wp_agent_official_contract_volume_exists( $wordpress, '/var/www/html/wp-content/plugins/wp-agent', 'bind', $plugin_dir ), 'WordPress service must load WP Agent as a plugin bind mount.', array(
	'plugin_dir' => $plugin_dir,
) );
wp_agent_official_contract_assert( wp_agent_official_contract_volume_exists( $wpcli, '/var/www/html/wp-content/plugins/wp-agent', 'bind', $plugin_dir ), 'WP-CLI service must load WP Agent as a plugin bind mount.', array(
	'plugin_dir' => $plugin_dir,
) );

$forbidden_command_pattern = '/\b(?:apt-get|apk|yum|dnf|pecl|docker-php-ext-install|composer\s+install|npm\s+install|pip\s+install)\b/i';
foreach ( array( 'db' => $db, 'wordpress' => $wordpress, 'wpcli' => $wpcli ) as $service_name => $service ) {
	$command = isset( $service['command'] ) ? json_encode( $service['command'], JSON_UNESCAPED_SLASHES ) : '';
	wp_agent_official_contract_assert( ! preg_match( $forbidden_command_pattern, (string) $command ), $service_name . ' command must not install packages or mutate the container image at runtime.', array(
		'command' => $service['command'] ?? null,
	) );
}

echo json_encode( array(
	'success'             => true,
	'compose_file'        => $compose_file,
	'services'            => $actual_services,
	'wordpress_image'     => $wordpress['image'],
	'wpcli_image'         => $wpcli['image'],
	'db_image'            => $db['image'],
	'db_bind_mount'       => $official_db_dir,
	'plugin_bind_mount'   => $plugin_dir,
	'agentd_sidecar'      => false,
	'privileged_services' => array(),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
