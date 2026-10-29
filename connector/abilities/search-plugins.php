<?php
/**
 * Search Plugins Ability - WordPress Abilities API
 *
 * Searches the WordPress.org plugin directory.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_search_plugins_ability' );
function cm_register_search_plugins_ability() {
	wp_register_ability(
		'classic-monks/search-plugins',
		array(
			'label'               => __( 'Search Plugins', 'monks-central' ),
			'description'         => __(
				"Searches the WordPress.org plugin directory. Use the slug with install-plugin. \n\n## Parameters\n- **search**: Search words (required).\n- **per_page**: Results per page (1-24, default 10).\n- **page**: Page number (default 1).\n\n## Returns\nMatching plugins with slug, name, version, author, rating (0-100), active installs, last update, tested-up-to version, short description and whether it is installed here.",
				'monks-central'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'      => 'string',
						'minLength' => 2,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 24,
						'default' => 10,
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
				'required'             => array( 'search' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'              => array( 'type' => 'string' ),
								'name'              => array( 'type' => 'string' ),
								'version'           => array( 'type' => 'string' ),
								'author'            => array( 'type' => 'string' ),
								'rating'            => array( 'type' => 'integer' ),
								'active_installs'   => array( 'type' => 'integer' ),
								'last_updated'      => array( 'type' => 'string' ),
								'tested'            => array( 'type' => 'string' ),
								'short_description' => array( 'type' => 'string' ),
								'installed'         => array( 'type' => 'boolean' ),
							),
						),
					),
					'total'   => array( 'type' => 'integer' ),
					'pages'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_search_plugins',
			'permission_callback' => function () {
				return current_user_can( 'install_plugins' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Search WordPress.org before installing. Prefer well-rated plugins with many active installs that were updated recently.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

function cm_execute_search_plugins( $input ) {
	cm_ai_require_plugin_management_files();

	$api = plugins_api(
		'query_plugins',
		array(
			'search'   => sanitize_text_field( $input['search'] ),
			'per_page' => min( absint( $input['per_page'] ?? 10 ), 24 ),
			'page'     => max( 1, absint( $input['page'] ?? 1 ) ),
			'fields'   => array(
				'sections' => false,
				'icons'    => false,
				'banners'  => false,
				'tags'     => false,
				'versions' => false,
			),
		)
	);

	if ( is_wp_error( $api ) ) {
		return $api;
	}

	$installed = array_map( 'cm_ai_get_plugin_slug_from_file', array_keys( get_plugins() ) );
	$plugins   = array();

	foreach ( (array) $api->plugins as $plugin ) {
		$plugin    = (array) $plugin;
		$plugins[] = array(
			'slug'              => (string) $plugin['slug'],
			'name'              => wp_strip_all_tags( html_entity_decode( (string) $plugin['name'] ) ),
			'version'           => (string) ( $plugin['version'] ?? '' ),
			'author'            => wp_strip_all_tags( (string) ( $plugin['author'] ?? '' ) ),
			'rating'            => (int) ( $plugin['rating'] ?? 0 ),
			'active_installs'   => (int) ( $plugin['active_installs'] ?? 0 ),
			'last_updated'      => (string) ( $plugin['last_updated'] ?? '' ),
			'tested'            => (string) ( $plugin['tested'] ?? '' ),
			'short_description' => wp_strip_all_tags( html_entity_decode( (string) ( $plugin['short_description'] ?? '' ) ) ),
			'installed'         => in_array( $plugin['slug'], $installed, true ),
		);
	}

	return array(
		'plugins' => $plugins,
		'total'   => (int) ( $api->info['results'] ?? count( $plugins ) ),
		'pages'   => (int) ( $api->info['pages'] ?? 1 ),
	);
}
