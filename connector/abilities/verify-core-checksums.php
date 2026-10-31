<?php
/**
 * Verify Core Checksums Ability - WordPress Abilities API
 *
 * Compares WordPress core files with the official release checksums.
 *
 * @package MonksCentral\Connector
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_verify_core_checksums_ability' );
function cm_register_verify_core_checksums_ability() {
	wp_register_ability(
		'classic-monks/verify-core-checksums',
		array(
			'label'               => __( 'Verify Core Checksums', 'wp-sites-hq' ),
			'description'         => __(
				"Checks WordPress core files against the official checksums for the installed version, to spot tampering or hacked files. wp-content is not checked. \n\n## Returns\nWhether checksums were available, how many files were checked, modified and missing core files, and unexpected files in wp-admin and wp-includes. Each list returns up to 100 paths.",
				'wp-sites-hq'
			),
			'category'            => 'system',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'version'       => array( 'type' => 'string' ),
					'verifiable'    => array( 'type' => 'boolean' ),
					'message'       => array( 'type' => 'string' ),
					'files_checked' => array( 'type' => 'integer' ),
					'modified'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'missing'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'added'         => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'truncated'     => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'cm_execute_verify_core_checksums',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Use when checking a site for hacks. Modified or extra files in wp-admin or wp-includes are a strong warning sign.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

function cm_execute_verify_core_checksums() {
	require_once ABSPATH . 'wp-admin/includes/update.php';

	$version = (string) get_bloginfo( 'version' );
	$report  = array(
		'version'       => $version,
		'verifiable'    => false,
		'message'       => '',
		'files_checked' => 0,
		'modified'      => array(),
		'missing'       => array(),
		'added'         => array(),
		'truncated'     => false,
	);

	$locale    = isset( $GLOBALS['wp_local_package'] ) ? $GLOBALS['wp_local_package'] : 'en_US';
	$checksums = get_core_checksums( $version, $locale );
	if ( ! $checksums && 'en_US' !== $locale ) {
		$checksums = get_core_checksums( $version, 'en_US' );
	}
	if ( ! is_array( $checksums ) || ! $checksums ) {
		$report['message'] = __( 'WordPress.org has no checksums for this version (beta, development or offline).', 'wp-sites-hq' );
		return $report;
	}

	foreach ( $checksums as $file => $md5 ) {
		// Themes and plugins under wp-content change legitimately.
		if ( 0 === strpos( $file, 'wp-content/' ) ) {
			continue;
		}
		++$report['files_checked'];
		if ( ! file_exists( ABSPATH . $file ) ) {
			$report['missing'][] = $file;
		} elseif ( md5_file( ABSPATH . $file ) !== $md5 ) {
			$report['modified'][] = $file;
		}
	}

	foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $local ) {
			$path = $dir . '/' . str_replace( '\\', '/', substr( $local->getPathname(), strlen( ABSPATH . $dir ) + 1 ) );
			if ( ! isset( $checksums[ $path ] ) ) {
				$report['added'][] = $path;
			}
		}
	}

	foreach ( array( 'modified', 'missing', 'added' ) as $list ) {
		if ( count( $report[ $list ] ) > 100 ) {
			$report[ $list ]     = array_slice( $report[ $list ], 0, 100 );
			$report['truncated'] = true;
		}
		$report[ $list ] = array_map( 'strval', $report[ $list ] );
	}

	$report['verifiable'] = true;
	$report['message']    = ( $report['modified'] || $report['missing'] || $report['added'] )
		? __( 'Some core files differ from the official release.', 'wp-sites-hq' )
		: __( 'All core files match the official release.', 'wp-sites-hq' );

	return $report;
}
