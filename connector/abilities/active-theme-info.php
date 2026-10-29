<?php
/**
 * Get Theme Ability - WordPress Abilities API
 *
 * Gets current theme and available themes information.
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
 * Register the get-theme ability.
 *
 * Returns current theme and all installed themes.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_theme_ability' );
function cm_register_get_theme_ability() {
	wp_register_ability(
		'classic-monks/get-theme',
		array(
			'label'               => __( 'Get Theme', 'monks-central' ),
			'description'         => __(
				"Gets current theme and available themes information. Use this ability to check theme compatibility or audit installed themes. \n\n## No Parameters Required\nThis ability takes no input parameters.\n\n## Returns\n- **current**: Active theme details (name, version, author, description, screenshot, parent)\n- **themes**: All installed themes with their status\n- **total**: Total number of installed themes",
				'monks-central'
			),
			'category'            => 'system',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'current' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'version'     => array( 'type' => 'string' ),
							'author'      => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'screenshot'  => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'parent'      => array( 'type' => 'string' ),
							'stylesheet'  => array( 'type' => 'string' ),
							'block_theme' => array( 'type' => 'boolean' ),
							'features'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
					'themes'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'       => array( 'type' => 'string' ),
								'version'    => array( 'type' => 'string' ),
								'active'     => array( 'type' => 'boolean' ),
								'stylesheet' => array( 'type' => 'string' ),
							),
						),
					),
					'total'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_theme',
			'permission_callback' => function () {
				return current_user_can( 'switch_themes' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Get current theme details and list of installed themes. Does not activate themes.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-theme ability.
 *
 * @since 1.2.0
 * @return array Theme information.
 */
function cm_execute_get_theme() {
	$current_theme = wp_get_theme();
	$all_themes    = wp_get_themes();

	// Current theme details
	$features_to_check  = array(
		'post-thumbnails',
		'custom-header',
		'custom-background',
		'custom-logo',
		'title-tag',
		'menus',
		'widgets',
		'editor-styles',
		'responsive-embeds',
	);
	$supported_features = array();
	foreach ( $features_to_check as $feature ) {
		if ( current_theme_supports( $feature ) ) {
			$supported_features[] = $feature;
		}
	}

	$current = array(
		'name'        => $current_theme->get( 'Name' ),
		'version'     => $current_theme->get( 'Version' ),
		'author'      => wp_strip_all_tags( $current_theme->get( 'Author' ) ),
		'description' => $current_theme->get( 'Description' ),
		'screenshot'  => $current_theme->get_screenshot(),
		'parent'      => $current_theme->parent() ? $current_theme->parent()->get( 'Name' ) : '',
		'stylesheet'  => $current_theme->get_stylesheet(),
		'block_theme' => $current_theme->is_block_theme(),
		'features'    => $supported_features,
	);

	// All themes
	$themes = array();
	foreach ( $all_themes as $theme ) {
		$themes[] = array(
			'name'       => $theme->get( 'Name' ),
			'version'    => $theme->get( 'Version' ),
			'active'     => $theme->get_stylesheet() === $current_theme->get_stylesheet(),
			'stylesheet' => $theme->get_stylesheet(),
		);
	}

	return array(
		'current' => $current,
		'themes'  => $themes,
		'total'   => count( $themes ),
	);
}
