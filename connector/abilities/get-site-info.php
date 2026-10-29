<?php
/**
 * Get Site Info Ability - WordPress Abilities API
 *
 * Retrieves comprehensive WordPress site configuration and system information.
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
 * Register the get-site-info ability.
 *
 * Returns comprehensive WordPress site information including server,
 * content statistics, and security status.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_site_info_ability' );
function cm_register_get_site_info_ability() {
	wp_register_ability(
		'classic-monks/get-site-info',
		array(
			'label'               => __( 'Get Site Info', 'monks-central' ),
			'description'         => __(
				"Retrieves comprehensive WordPress site configuration and environment information. Provides a complete health overview including server, content, and security data. \n\n## Returns\n- **WordPress**: Version, name, URL, tagline, admin email, locale, timezone\n- **Server**: PHP version, memory limit, max execution time, server software\n- **Database**: Version, table prefix count, charset\n- **Content**: Post/page/user/comment counts, active plugins count\n- **Security**: SSL status, debug mode, file editing, auto-updates\n- **Performance**: Object cache, cron status\n\n## No Parameters Required\nThis ability takes no input parameters.",
				'monks-central'
			),
			'category'            => 'system',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'wordpress'   => array(
						'type'        => 'object',
						'description' => 'WordPress installation details.',
						'properties'  => array(
							'version'             => array( 'type' => 'string' ),
							'site_name'           => array( 'type' => 'string' ),
							'site_tagline'        => array( 'type' => 'string' ),
							'site_url'            => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'admin_url'           => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'admin_email'         => array(
								'type'   => 'string',
								'format' => 'email',
							),
							'language'            => array( 'type' => 'string' ),
							'timezone'            => array( 'type' => 'string' ),
							'permalink_structure' => array( 'type' => 'string' ),
							'posts_per_page'      => array( 'type' => 'integer' ),
							'is_multisite'        => array( 'type' => 'boolean' ),
							'users_can_register'  => array( 'type' => 'boolean' ),
							'date_format'         => array( 'type' => 'string' ),
							'time_format'         => array( 'type' => 'string' ),
						),
					),
					'server'      => array(
						'type'        => 'object',
						'description' => 'Server and PHP environment.',
						'properties'  => array(
							'php_version'        => array( 'type' => 'string' ),
							'memory_limit'       => array( 'type' => 'string' ),
							'max_execution_time' => array( 'type' => 'integer' ),
							'upload_max_size'    => array( 'type' => 'string' ),
							'server_software'    => array( 'type' => 'string' ),
						),
					),
					'database'    => array(
						'type'        => 'object',
						'description' => 'Database information.',
						'properties'  => array(
							'version'      => array( 'type' => 'string' ),
							'table_prefix' => array( 'type' => 'string' ),
							'charset'      => array( 'type' => 'string' ),
						),
					),
					'content'     => array(
						'type'        => 'object',
						'description' => 'Content statistics.',
						'properties'  => array(
							'posts_count'          => array( 'type' => 'integer' ),
							'pages_count'          => array( 'type' => 'integer' ),
							'users_count'          => array( 'type' => 'integer' ),
							'comments_count'       => array( 'type' => 'integer' ),
							'active_plugins_count' => array( 'type' => 'integer' ),
						),
					),
					'security'    => array(
						'type'        => 'object',
						'description' => 'Security status indicators.',
						'properties'  => array(
							'ssl_enabled'        => array( 'type' => 'boolean' ),
							'debug_mode'         => array( 'type' => 'boolean' ),
							'file_edit_disabled' => array( 'type' => 'boolean' ),
							'auto_updates'       => array( 'type' => 'boolean' ),
						),
					),
					'performance' => array(
						'type'        => 'object',
						'description' => 'Performance indicators.',
						'properties'  => array(
							'object_cache'  => array( 'type' => 'boolean' ),
							'cron_disabled' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
			'execute_callback'    => 'cm_execute_get_site_info',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Use as first call when user asks about site configuration or WordPress setup. For detailed system diagnostics, use get-environment-info instead.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-site-info ability.
 *
 * Returns comprehensive site information organized by category.
 *
 * @since 1.2.0
 * @return array Site information organized by category.
 */
function cm_execute_get_site_info() {
	global $wp_version, $wpdb;

	// WordPress info.
	$wordpress = array(
		'version'             => $wp_version,
		'site_name'           => get_bloginfo( 'name' ),
		'site_tagline'        => get_bloginfo( 'description' ),
		'site_url'            => home_url(),
		'admin_url'           => admin_url(),
		'admin_email'         => get_bloginfo( 'admin_email' ),
		'language'            => get_locale(),
		'timezone'            => wp_timezone_string(),
		'permalink_structure' => get_option( 'permalink_structure' ) ?: 'Plain',
		'posts_per_page'      => (int) get_option( 'posts_per_page' ),
		'is_multisite'        => is_multisite(),
		'users_can_register'  => (bool) get_option( 'users_can_register' ),
		'date_format'         => get_option( 'date_format' ),
		'time_format'         => get_option( 'time_format' ),
	);

	// Server info.
	$server = array(
		'php_version'        => phpversion(),
		'memory_limit'       => ini_get( 'memory_limit' ),
		'max_execution_time' => (int) ini_get( 'max_execution_time' ),
		'upload_max_size'    => ini_get( 'upload_max_filesize' ),
		'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown',
	);

	// Database info.
	$database = array(
		'version'      => $wpdb->db_version(),
		'table_prefix' => $wpdb->prefix,
		'charset'      => $wpdb->charset,
	);

	// Content counts.
	$content = array(
		'posts_count'          => (int) wp_count_posts( 'post' )->publish,
		'pages_count'          => (int) wp_count_posts( 'page' )->publish,
		'users_count'          => (int) count_users()['total_users'],
		'comments_count'       => (int) wp_count_comments()->approved,
		'active_plugins_count' => count( get_option( 'active_plugins', array() ) ),
	);

	// Security indicators.
	$security = array(
		'ssl_enabled'        => is_ssl(),
		'debug_mode'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
		'file_edit_disabled' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
		'auto_updates'       => ! ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ),
	);

	// Performance indicators.
	$performance = array(
		'object_cache'  => (bool) wp_using_ext_object_cache(),
		'cron_disabled' => (bool) ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
	);

	return array(
		'wordpress'   => $wordpress,
		'server'      => $server,
		'database'    => $database,
		'content'     => $content,
		'security'    => $security,
		'performance' => $performance,
	);
}
