<?php
/**
 * List Themes Ability - WordPress Abilities API
 *
 * Lists installed themes with update and auto-update status.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_list_themes_ability' );
function cm_register_list_themes_ability() {
	wp_register_ability(
		'classic-monks/list-themes',
		array(
			'label'               => __( 'List Themes', 'wp-sites-hq' ),
			'description'         => __(
				"Lists installed themes with their version, parent theme, whether they are active, and whether an update is available. Use the stylesheet value with the other theme abilities. \n\n## Returns\n- **themes**: stylesheet, name, version, parent (stylesheet of the parent theme, or empty), active, is_parent_of_active, update_version (empty when up to date), auto_update.\n- **active**: stylesheet of the active theme.",
				'wp-sites-hq'
			),
			'category'            => 'system',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'themes' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'stylesheet'          => array( 'type' => 'string' ),
								'name'                => array( 'type' => 'string' ),
								'version'             => array( 'type' => 'string' ),
								'parent'              => array( 'type' => 'string' ),
								'active'              => array( 'type' => 'boolean' ),
								'is_parent_of_active' => array( 'type' => 'boolean' ),
								'update_version'      => array( 'type' => 'string' ),
								'auto_update'         => array( 'type' => 'boolean' ),
							),
						),
					),
					'active' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_list_themes',
			'permission_callback' => function () {
				return current_user_can( 'switch_themes' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'List themes before activating, updating or deleting one.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

function cm_execute_list_themes() {
	$stylesheet = get_stylesheet();
	$template   = get_template();
	$updates    = get_site_transient( 'update_themes' );
	$updates    = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : array();
	$auto       = (array) get_site_option( 'auto_update_themes', array() );
	$themes     = array();

	foreach ( wp_get_themes() as $slug => $theme ) {
		$themes[] = array(
			'stylesheet'          => (string) $slug,
			'name'                => (string) $theme->get( 'Name' ),
			'version'             => (string) $theme->get( 'Version' ),
			'parent'              => $theme->parent() ? (string) $theme->get_template() : '',
			'active'              => $slug === $stylesheet,
			'is_parent_of_active' => $slug === $template && $slug !== $stylesheet,
			'update_version'      => isset( $updates[ $slug ]['new_version'] ) ? (string) $updates[ $slug ]['new_version'] : '',
			'auto_update'         => in_array( $slug, $auto, true ),
		);
	}

	return array(
		'themes' => $themes,
		'active' => $stylesheet,
	);
}
