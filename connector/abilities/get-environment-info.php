<?php
/**
 * Get Environment Info Ability - WordPress Abilities API
 *
 * Retrieves server environment information.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 1.2.0
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the get-environment-info ability.
 *
 * Returns detailed server environment information.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_environment_info_ability' );
function cm_register_get_environment_info_ability() {
	wp_register_ability(
		'classic-monks/get-environment-info',
		array(
			'label'               => __( 'Get Environment Info', 'monks-central' ),
			'description'         => __(
				"Retrieves detailed server environment information. Use this ability to debug server-related issues or check compatibility. \n\n## Returns\n- **PHP**: Version, memory limit, max execution time\n- **MySQL**: Version\n- **Server**: Software, OS\n- **WordPress**: Memory limits, debug mode status\n\n## No Parameters Required\nThis ability takes no input parameters.",
				'monks-central'
			),
			'category'            => 'system',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'php_version'            => array(
						'type'        => 'string',
						'description' => 'PHP version.',
					),
					'mysql_version'          => array(
						'type'        => 'string',
						'description' => 'MySQL version.',
					),
					'server_software'        => array(
						'type'        => 'string',
						'description' => 'Web server software.',
					),
					'php_memory_limit'       => array(
						'type'        => 'string',
						'description' => 'PHP memory limit.',
					),
					'php_max_execution_time' => array(
						'type'        => 'string',
						'description' => 'PHP max execution time.',
					),
					'wp_memory_limit'        => array(
						'type'        => 'string',
						'description' => 'WordPress memory limit.',
					),
					'wp_debug_mode'          => array(
						'type'        => 'boolean',
						'description' => 'Whether WP_DEBUG is enabled.',
					),
					'upload_max_filesize'    => array(
						'type'        => 'string',
						'description' => 'Max upload size.',
					),
					'php_extensions'         => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Loaded PHP extensions.',
					),
					'cron_jobs_count'        => array(
						'type'        => 'integer',
						'description' => 'Number of scheduled cron events.',
					),
				),
			),
			'execute_callback'    => 'cm_execute_get_environment_info',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Technical server diagnostics. Use get-site-info for general WordPress configuration instead.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-environment-info ability.
 *
 * @since 1.2.0
 * @return array Environment information.
 */
function cm_execute_get_environment_info() {
	global $wpdb;

	// Count cron jobs
	$crons      = _get_cron_array();
	$cron_count = 0;
	if ( is_array( $crons ) ) {
		foreach ( $crons as $timestamp => $hooks ) {
			$cron_count += count( $hooks );
		}
	}

	return array(
		'php_version'            => phpversion(),
		'mysql_version'          => $wpdb->db_version(),
		'server_software'        => sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ) ),
		'php_memory_limit'       => ini_get( 'memory_limit' ),
		'php_max_execution_time' => ini_get( 'max_execution_time' ),
		'wp_memory_limit'        => WP_MEMORY_LIMIT,
		'wp_debug_mode'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
		'upload_max_filesize'    => ini_get( 'upload_max_filesize' ),
		'php_extensions'         => get_loaded_extensions(),
		'cron_jobs_count'        => $cron_count,
	);
}
