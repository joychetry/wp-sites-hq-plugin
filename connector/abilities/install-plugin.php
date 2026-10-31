<?php
/**
 * Install Plugin Ability - WordPress Abilities API
 *
 * Installs a WordPress plugin from WordPress.org.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 1.2.0
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_install_plugin_ability' );
function cm_register_install_plugin_ability() {
	wp_register_ability(
		'classic-monks/install-plugin',
		array(
			'label'               => __( 'Install Plugin', 'wp-sites-hq' ),
			'description'         => __(
				"Installs a WordPress plugin from WordPress.org by slug or name. If the plugin is already installed, this ability can activate it instead of reinstalling. \n\n## Required Parameters\n- Provide at least one of: **slug** or **name**\n\n## Optional Parameters\n- **activate**: Activate the plugin after install (default: true)\n- **plugin_file**: Explicit plugin file if it is already installed and should be activated without reinstalling\n\n## Returns\nThe resolved plugin slug, plugin file, installed version, activation state, and a status message.",
				'wp-sites-hq'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug'        => array(
						'type'        => 'string',
						'description' => 'WordPress.org plugin slug, for example classic-editor.',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'Plugin name to resolve from WordPress.org search.',
					),
					'plugin_file' => array(
						'type'        => 'string',
						'description' => 'Installed plugin file path, for example classic-editor/classic-editor.php.',
					),
					'activate'    => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Whether to activate the plugin after install.',
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
					'installed'      => array( 'type' => 'boolean' ),
					'activated'      => array( 'type' => 'boolean' ),
					'message'        => array( 'type' => 'string' ),
					'source'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_install_plugin',
			'permission_callback' => function ( $input = array() ) {
				$activate = ! isset( $input['activate'] ) || filter_var( $input['activate'], FILTER_VALIDATE_BOOLEAN );
				return current_user_can( 'install_plugins' ) && ( ! $activate || current_user_can( 'activate_plugins' ) );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => false,
					'instructions' => 'Installs a plugin when missing and can activate it after install. Use activate-plugin for installed inactive plugins when you already know the plugin file.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the install-plugin ability.
 *
 * @since 1.2.0
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function cm_execute_install_plugin( $input ) {
	cm_ai_require_plugin_management_files();

	$activate = ! isset( $input['activate'] ) || filter_var( $input['activate'], FILTER_VALIDATE_BOOLEAN );

	$existing = cm_ai_resolve_installed_plugin( $input );
	if ( is_wp_error( $existing ) && 'cm_plugin_not_found' !== $existing->get_error_code() ) {
		return $existing;
	}

	if ( ! is_wp_error( $existing ) ) {
		$state   = cm_ai_get_plugin_state( $existing['plugin_file'], $existing['plugin_data'] );
		$message = __( 'Plugin is already installed.', 'wp-sites-hq' );

		if ( $activate && ! $state['active'] ) {
			$activation = activate_plugin( $existing['plugin_file'] );
			if ( is_wp_error( $activation ) ) {
				return $activation;
			}

			$state['active'] = true;
			$message         = __( 'Plugin was already installed and has now been activated.', 'wp-sites-hq' );
		} elseif ( $activate && $state['active'] ) {
			$message = __( 'Plugin is already installed and active.', 'wp-sites-hq' );
		}

		$state['installed'] = true;
		$state['activated'] = (bool) $state['active'];
		$state['message']   = $message;
		$state['source']    = 'installed';

		return $state;
	}

	$source = cm_ai_resolve_plugin_install_source( $input );
	if ( is_wp_error( $source ) ) {
		return $source;
	}

	$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$result   = $upgrader->install( $source['download_link'] );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! $result ) {
		return new WP_Error(
			'cm_plugin_install_failed',
			__( 'Plugin installation failed.', 'wp-sites-hq' ),
			array( 'status' => 500 )
		);
	}

	$plugin_file = $upgrader->plugin_info();
	if ( ! $plugin_file ) {
		return new WP_Error(
			'cm_plugin_install_unknown_file',
			__( 'Plugin installed but its main plugin file could not be detected.', 'wp-sites-hq' ),
			array( 'status' => 500 )
		);
	}

	$plugin_file = plugin_basename( $plugin_file );
	$all_plugins = get_plugins();
	$plugin_data = isset( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : array();

	if ( $activate ) {
		$activation = activate_plugin( $plugin_file );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}
	}

	$state              = cm_ai_get_plugin_state( $plugin_file, $plugin_data );
	$state['installed'] = true;
	$state['activated'] = (bool) $state['active'];
	$state['source']    = $source['source'];
	$state['message']   = $activate
		? __( 'Plugin installed and activated successfully.', 'wp-sites-hq' )
		: __( 'Plugin installed successfully.', 'wp-sites-hq' );

	if ( ! empty( $source['slug'] ) ) {
		$state['slug'] = $source['slug'];
	}

	if ( ! empty( $source['name'] ) ) {
		$state['name'] = $source['name'];
	}

	return $state;
}
