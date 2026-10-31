<?php
/**
 * Verify Plugin Checksums Ability - WordPress Abilities API
 *
 * Compares an installed plugin's files with the official WordPress.org release.
 *
 * @package MonksCentral\Connector
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_verify_plugin_checksums_ability' );
function cm_register_verify_plugin_checksums_ability() {
	wp_register_ability(
		'classic-monks/verify-plugin-checksums',
		array(
			'label'               => __( 'Verify Plugin Checksums', 'wp-sites-hq' ),
			'description'         => __(
				"Checks an installed plugin's files against the official WordPress.org release of the same version, to spot tampering or hacked files. \n\n## Parameters\n- One of **plugin_file**, **slug** or **name** to pick the plugin.\n\n## Notes\n- Plugins not hosted on WordPress.org (premium or custom) return verifiable=false.\n- At most 5000 files are checked; each list returns up to 100 paths.\n\n## Returns\nWhether the plugin could be verified, how many files were checked, and lists of modified, added and missing files.",
				'wp-sites-hq'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin_file' => array(
						'type'        => 'string',
						'description' => 'Installed plugin file path.',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'Installed plugin slug.',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'Installed plugin name.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugin_file'   => array( 'type' => 'string' ),
					'version'       => array( 'type' => 'string' ),
					'verifiable'    => array( 'type' => 'boolean' ),
					'message'       => array( 'type' => 'string' ),
					'files_checked' => array( 'type' => 'integer' ),
					'modified'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'added'         => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'missing'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'truncated'     => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'cm_execute_verify_plugin_checksums',
			'permission_callback' => function () {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Use to check whether a plugin has been tampered with. Modified or added files in a plugin are a common sign of a hack.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

function cm_execute_verify_plugin_checksums( $input ) {
	$resolved = cm_ai_resolve_installed_plugin( $input );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$file    = $resolved['plugin_file'];
	$version = (string) ( $resolved['plugin_data']['Version'] ?? '' );
	$slug    = cm_ai_get_plugin_slug_from_file( $file );
	$dir     = WP_PLUGIN_DIR . '/' . $slug;
	$report  = array(
		'plugin_file'   => $file,
		'version'       => $version,
		'verifiable'    => false,
		'message'       => '',
		'files_checked' => 0,
		'modified'      => array(),
		'added'         => array(),
		'missing'       => array(),
		'truncated'     => false,
	);

	if ( false === strpos( $file, '/' ) || '' === $version ) {
		$report['message'] = __( 'Single-file plugins and plugins without a version cannot be verified.', 'wp-sites-hq' );
		return $report;
	}

	$response = wp_remote_get( sprintf( 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json', rawurlencode( $slug ), rawurlencode( $version ) ), array( 'timeout' => 20 ) );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['files'] ) || ! is_array( $body['files'] ) ) {
		$report['message'] = __( 'Not verifiable: WordPress.org has no checksums for this plugin and version (premium, custom or retired plugin).', 'wp-sites-hq' );
		return $report;
	}

	$max      = 5000;
	$expected = $body['files'];
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $local ) {
		if ( $report['files_checked'] >= $max ) {
			$report['truncated'] = true;
			break;
		}
		$path = str_replace( '\\', '/', substr( $local->getPathname(), strlen( $dir ) + 1 ) );
		++$report['files_checked'];

		if ( ! isset( $expected[ $path ] ) ) {
			$report['added'][] = $path;
			continue;
		}
		// Each hash is a string, or a list when the file changed between builds of this version.
		$sha256 = (array) ( $expected[ $path ]['sha256'] ?? array() );
		if ( ! in_array( hash_file( 'sha256', $local->getPathname() ), $sha256, true ) ) {
			$report['modified'][] = $path;
		}
		unset( $expected[ $path ] );
	}

	if ( ! $report['truncated'] ) {
		$report['missing'] = array_keys( $expected );
	}

	foreach ( array( 'modified', 'added', 'missing' ) as $list ) {
		if ( count( $report[ $list ] ) > 100 ) {
			$report[ $list ]     = array_slice( $report[ $list ], 0, 100 );
			$report['truncated'] = true;
		}
		$report[ $list ] = array_map( 'strval', $report[ $list ] );
	}

	$report['verifiable'] = true;
	$report['message']    = ( $report['modified'] || $report['added'] || $report['missing'] )
		? __( 'Some files differ from the official release.', 'wp-sites-hq' )
		: __( 'All files match the official release.', 'wp-sites-hq' );

	return $report;
}
