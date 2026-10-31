<?php
/**
 * Deactivate Plugin Ability - WordPress Abilities API
 *
 * Deactivates an installed WordPress plugin.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 1.2.0
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_deactivate_plugin_ability' );
function cm_register_deactivate_plugin_ability() {
	wp_register_ability(
		'classic-monks/deactivate-plugin',
		array(
			'label'               => __( 'Deactivate Plugin', 'wp-sites-hq' ),
			'description'         => __(
				"Deactivates an installed WordPress plugin. Provide the plugin file, slug, or exact installed plugin name. \n\n## Required Parameters\n- Provide one of: **plugin_file**, **slug**, or **name**\n\n## Returns\nThe plugin file, slug, name, current version, activation state, and a status message.",
				'wp-sites-hq'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin_file'  => array(
						'type'        => 'string',
						'description' => 'Installed plugin file path.',
					),
					'slug'         => array(
						'type'        => 'string',
						'description' => 'Installed plugin slug.',
					),
					'name'         => array(
						'type'        => 'string',
						'description' => 'Installed plugin name.',
					),
					'network_wide' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Deactivate network-wide in multisite if supported.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugin_file'    => array( 'type' => 'string' ),
					'slug'           => array( 'type' => 'string' ),
					'name'           => array( 'type' => 'string' ),
					'version'        => array( 'type' => 'string' ),
					'active'         => array( 'type' => 'boolean' ),
					'network_active' => array( 'type' => 'boolean' ),
					'message'        => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_deactivate_plugin',
			'permission_callback' => function () {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
					'instructions' => 'Deactivates an installed plugin. Do not use this on the plugin that runs the connection.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the deactivate-plugin ability.
 *
 * @since 1.2.0
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function cm_execute_deactivate_plugin( $input ) {
	cm_ai_require_plugin_management_files();

	$resolved = cm_ai_resolve_installed_plugin( $input );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$plugin_file  = $resolved['plugin_file'];
	$plugin_data  = $resolved['plugin_data'];
	$network_wide = is_multisite() && ! empty( $input['network_wide'] ) && current_user_can( 'manage_network_plugins' );
	$state        = cm_ai_get_plugin_state( $plugin_file, $plugin_data );

	if ( cm_ai_is_current_plugin( $plugin_file ) ) {
		return new WP_Error(
			'cm_plugin_protected',
			__( 'This plugin runs the connection, so it cannot be deactivated over it.', 'wp-sites-hq' ),
			array( 'status' => 409 )
		);
	}

	if ( ! $state['active'] ) {
		$state['message'] = __( 'Plugin is already inactive.', 'wp-sites-hq' );
		return $state;
	}

	deactivate_plugins( $plugin_file, false, $network_wide );

	$state            = cm_ai_get_plugin_state( $plugin_file, $plugin_data );
	$state['message'] = __( 'Plugin deactivated successfully.', 'wp-sites-hq' );

	return $state;
}
