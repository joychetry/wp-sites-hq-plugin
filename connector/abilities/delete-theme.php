<?php
/**
 * Delete Theme Ability - WordPress Abilities API
 *
 * Deletes an installed theme that is not in use. Cannot be undone.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_delete_theme_ability' );
function cm_register_delete_theme_ability() {
	wp_register_ability(
		'classic-monks/delete-theme',
		array(
			'label'               => __( 'Delete Theme', 'monks-central' ),
			'description'         => __(
				"Deletes an installed theme and its files. \n\n## Parameters\n- **stylesheet**: The theme's stylesheet from list-themes (required).\n\n## Notes\n- This cannot be undone.\n- The active theme and the parent of the active theme are refused.\n\n## Returns\nThe theme stylesheet and whether it was deleted.",
				'monks-central'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'stylesheet' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'required'             => array( 'stylesheet' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'stylesheet' => array( 'type' => 'string' ),
					'deleted'    => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'cm_execute_delete_theme',
			'permission_callback' => function () {
				return current_user_can( 'delete_themes' );
			},
			'meta'                => array(
				'cm_always_approve'   => true, // Can't be undone: trusted connections still ask.
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
					'instructions' => 'Cannot be undone. Confirm with the user first.',
				),
				'cm_preview_callback' => 'cm_preview_theme_state',
				'show_in_rest'        => true,
			),
		)
	);
}

function cm_execute_delete_theme( $input ) {
	cm_ai_require_plugin_management_files();
	require_once ABSPATH . 'wp-admin/includes/theme.php';

	$theme = wp_get_theme( sanitize_text_field( $input['stylesheet'] ) );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'cm_theme_not_found', __( 'Theme not found. Use list-themes for the exact stylesheet.', 'monks-central' ) );
	}

	$stylesheet = $theme->get_stylesheet();
	if ( in_array( $stylesheet, array( get_stylesheet(), get_template() ), true ) ) {
		return new WP_Error( 'cm_theme_in_use', __( 'This theme is active or is the parent of the active theme, so it cannot be deleted.', 'monks-central' ) );
	}

	$result = delete_theme( $stylesheet );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( true !== $result ) {
		return new WP_Error( 'cm_theme_delete_failed', __( 'The theme files could not be deleted. WordPress may need FTP credentials.', 'monks-central' ) );
	}

	return array(
		'stylesheet' => $stylesheet,
		'deleted'    => true,
	);
}
