<?php
/**
 * Uninstall Plugin Ability - WordPress Abilities API
 *
 * Runs an inactive plugin's uninstall routine and deletes its files. Cannot be undone.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'wpshq_register_uninstall_plugin_ability' );
function wpshq_register_uninstall_plugin_ability() {
	wp_register_ability(
		'classic-monks/uninstall-plugin',
		array(
			'label'               => __( 'Uninstall Plugin', 'wp-sites-hq' ),
			'description'         => __(
				"Uninstalls an inactive plugin: runs its uninstall routine (which may delete its data) and deletes its files. \n\n## Parameters\n- One of **plugin_file**, **slug** or **name** to pick the plugin.\n\n## Notes\n- This cannot be undone.\n- Deactivate the plugin first; active plugins and the plugin that runs the connection are refused.\n- Not available when the site disallows file changes (DISALLOW_FILE_MODS).\n\n## Returns\nThe plugin file and name, and whether it was removed.",
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
					'plugin_file' => array( 'type' => 'string' ),
					'name'        => array( 'type' => 'string' ),
					'uninstalled' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'wpshq_execute_uninstall_plugin',
			// delete_plugins is denied by core when DISALLOW_FILE_MODS is set.
			'permission_callback' => function () {
				return current_user_can( 'delete_plugins' );
			},
			'meta'                => array(
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
					'instructions' => 'Cannot be undone and may delete the plugin\'s data. Confirm with the user first.',
				),
				'cm_preview_callback' => 'wpshq_preview_installed_plugin_state',
				'cm_always_approve'   => true,
				'show_in_rest'        => true,
			),
		)
	);
}

/**
 * The plugin about to be removed, for approvals and drift checks.
 */
function wpshq_preview_installed_plugin_state( $input ) {
	$resolved = wpshq_ai_resolve_installed_plugin( $input );
	return is_wp_error( $resolved ) ? null : wpshq_ai_get_plugin_state( $resolved['plugin_file'], $resolved['plugin_data'] );
}

function wpshq_execute_uninstall_plugin( $input ) {
	$resolved = wpshq_ai_resolve_installed_plugin( $input );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$file  = $resolved['plugin_file'];
	$state = wpshq_ai_get_plugin_state( $file, $resolved['plugin_data'] );

	if ( wpshq_ai_is_current_plugin( $file ) ) {
		return new WP_Error( 'cm_plugin_self_uninstall', __( 'This plugin runs the connection, so it cannot be uninstalled over it.', 'wp-sites-hq' ) );
	}
	if ( $state['active'] ) {
		return new WP_Error( 'cm_plugin_active', __( 'Deactivate the plugin before uninstalling it.', 'wp-sites-hq' ) );
	}

	// Runs the plugin's uninstall.php / uninstall hook, then deletes its files.
	$result = delete_plugins( array( $file ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( true !== $result ) {
		return new WP_Error( 'cm_plugin_uninstall_failed', __( 'The plugin files could not be deleted. WordPress may need FTP credentials.', 'wp-sites-hq' ) );
	}

	return array(
		'plugin_file' => $file,
		'name'        => $state['name'],
		'uninstalled' => true,
	);
}
