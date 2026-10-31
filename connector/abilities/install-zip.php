<?php
/**
 * Install Zip Ability - WordPress Abilities API
 *
 * Installs a plugin or theme from a zip on the caller's computer, sent in base64 chunks
 * with the upload-media protocol (WP Sites HQ M8).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_install_zip_ability' );
function cm_register_install_zip_ability() {
	wp_register_ability(
		'classic-monks/install-zip',
		array(
			'label'               => __( 'Install Plugin or Theme from Zip', 'wp-sites-hq' ),
			'description'         => __(
				'Installs a plugin or theme from a zip file, sent in base64 chunks of up to 2 MB (decoded). First call: filename, kind ("plugin" or "theme") and data (no upload_id); the reply gives an upload_id. Next calls: upload_id, offset (the bytes received so far, from the last reply) and data. Set done: true on the last chunk to install. A chunk sent twice with the same offset is ignored, so a retry is safe. overwrite: true replaces an installed copy in the same folder (like WordPress\' "Replace current with uploaded"); activate: true activates the plugin or switches to the theme. Both can be sent on any call. Not available when the site disallows file changes (DISALLOW_FILE_MODS) or needs FTP details to write files.',
				'wp-sites-hq'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'data' ),
				'properties'           => array(
					'filename'  => array(
						'type'        => 'string',
						'description' => 'Zip file name, on the first call.',
					),
					'kind'      => array(
						'type'        => 'string',
						'enum'        => array( 'plugin', 'theme' ),
						'description' => 'What the zip holds, on the first call.',
					),
					'data'      => array(
						'type'        => 'string',
						'description' => 'This chunk, base64.',
					),
					'upload_id' => array(
						'type'        => 'string',
						'description' => 'From the first reply, on every later call.',
					),
					'offset'    => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Bytes received before this chunk.',
					),
					'done'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'This is the last chunk.',
					),
					'overwrite' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Replace an installed copy in the same folder.',
					),
					'activate'  => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Activate the plugin, or switch to the theme.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'upload_id' => array( 'type' => 'string' ),
					'received'  => array( 'type' => 'integer' ),
					'kind'      => array( 'type' => 'string' ),
					'slug'      => array(
						'type'        => 'string',
						'description' => 'The plugin or theme folder.',
					),
					'name'      => array( 'type' => 'string' ),
					'version'   => array( 'type' => 'string' ),
					'plugin'    => array(
						'type'        => 'string',
						'description' => 'Plugin file, for plugins.',
					),
					'activated' => array(
						'type'        => 'boolean',
						'description' => 'Active after this call.',
					),
					'replaced'  => array(
						'type'        => 'boolean',
						'description' => 'An installed copy was replaced.',
					),
				),
			),
			'execute_callback'    => 'cm_execute_install_zip',
			'permission_callback' => 'cm_install_zip_permission',
			'meta'                => array(
				// WP Sites HQ only: keeps AI clients under their tool caps.
				'cm_operator_only' => true,
				'annotations'      => array(
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
					'instructions' => 'Send chunks in order. An unfinished upload is dropped after an hour.',
				),
					// Not in the REST list: the wp-admin chat loads its tools from there.
			),
		)
	);
}

/**
 * Kind, overwrite and activate for this call: a later chunk keeps its upload's kind and earlier switches.
 */
function cm_install_zip_options( $input ) {
	$state = empty( $input['upload_id'] ) ? false : get_transient( 'cm_install_zip_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['upload_id'] ) );
	return array_merge(
		array(
			'kind'      => $input['kind'] ?? '',
			'overwrite' => false,
			'activate'  => false,
		),
		is_array( $state ) ? $state : array(),
		array_intersect_key( $input, array_flip( array( 'overwrite', 'activate' ) ) )
	);
}

function cm_install_zip_permission( $input ) {
	// DISALLOW_FILE_MODS takes the install capabilities from everyone; let an administrator in so the tool can say why.
	if ( ! wp_is_file_mod_allowed( 'cm_install_zip' ) ) {
		return current_user_can( 'manage_options' );
	}
	$o    = cm_install_zip_options( $input );
	$caps = 'theme' === $o['kind']
		? array( 'install_themes', $o['overwrite'] ? 'update_themes' : '', $o['activate'] ? 'switch_themes' : '' )
		: array( 'install_plugins', $o['overwrite'] ? 'update_plugins' : '', $o['activate'] ? 'activate_plugins' : '' );
	foreach ( array_filter( $caps ) as $cap ) {
		if ( ! current_user_can( $cap ) ) {
			return false;
		}
	}
	return true;
}

function cm_execute_install_zip( $input ) {
	// WP Sites HQ's own connection only: it installs or takes down code for the whole site.
	if ( ! cm_is_operator_request() ) {
		return new WP_Error( 'cm_operator_only', __( 'Only the WP Sites HQ app can use this tool.', 'wp-sites-hq' ), array( 'status' => 403 ) );
	}
	if ( ! wp_is_file_mod_allowed( 'cm_install_zip' ) ) {
		return new WP_Error( 'cm_file_mods_disabled', __( 'This site does not allow installing plugins or themes: file changes are turned off (DISALLOW_FILE_MODS in wp-config.php).', 'wp-sites-hq' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'cm_filesystem_credentials', __( 'WordPress needs FTP or SSH details to write files on this site, so nothing can be installed from here. Ask the host to let WordPress write files directly, or add the FTP details to wp-config.php.', 'wp-sites-hq' ) );
	}

	$set = array_intersect_key( $input, array_flip( array( 'overwrite', 'activate' ) ) );
	if ( empty( $input['upload_id'] ) ) {
		if ( empty( $input['kind'] ) ) {
			return new WP_Error( 'missing_kind', __( 'kind ("plugin" or "theme") is required on the first call.', 'wp-sites-hq' ) );
		}
		$set['kind'] = $input['kind'];
	}
	$upload = cm_ai_receive_chunk( $input, 'cm_install_zip_', $set );
	if ( is_wp_error( $upload ) ) {
		return $upload;
	}
	$out = array(
		'upload_id' => $upload['id'],
		'received'  => $upload['size'],
	);
	if ( empty( $input['done'] ) ) {
		return $out;
	}

	$o    = array_merge(
		array(
			'overwrite' => false,
			'activate'  => false,
		),
		$upload['state']
	);
	$path = $o['path'];
	if ( "PK\x03\x04" !== file_get_contents( $path, false, null, 0, 4 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the first bytes of a local temp file
		wp_delete_file( $path );
		return new WP_Error( 'cm_not_a_zip', __( 'This file is not a zip. Send the plugin or theme as a .zip file.', 'wp-sites-hq' ) );
	}

	cm_ai_require_plugin_management_files();
	$theme    = 'theme' === $o['kind'];
	$root     = $theme ? get_theme_root() : WP_PLUGIN_DIR;
	$before   = (array) glob( $root . '/*', GLOB_ONLYDIR );
	$upgrader = $theme ? new Theme_Upgrader( new Automatic_Upgrader_Skin() ) : new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$result   = $upgrader->install( $path, array( 'overwrite_package' => (bool) $o['overwrite'] ) );
	wp_delete_file( $path );
	if ( ! $result && is_wp_error( $upgrader->skin->result ) ) {
		$result = $upgrader->skin->result; // install() returns false; the reason is on the skin.
	}

	if ( is_wp_error( $result ) ) {
		if ( 'folder_exists' === $result->get_error_code() ) {
			return new WP_Error(
				'cm_already_installed',
				$theme
				? __( 'A theme with this folder is already installed. Send overwrite: true to replace it.', 'wp-sites-hq' )
				: __( 'A plugin with this folder is already installed. Send overwrite: true to replace it.', 'wp-sites-hq' )
			);
		}
		if ( 0 === strpos( $result->get_error_code(), 'incompatible_archive' ) && is_string( $result->get_error_data() ) ) {
			// The reason ("No valid plugins were found.") is in the data, not the message.
			return new WP_Error( 'cm_not_a_package', $result->get_error_message() . ' ' . $result->get_error_data() );
		}
		return $result;
	}
	if ( ! $result ) {
		return new WP_Error( 'cm_install_failed', __( 'The install failed: WordPress could not write the files.', 'wp-sites-hq' ) );
	}

	$slug = (string) $upgrader->result['destination_name'];
	$out += array(
		'kind'     => $o['kind'],
		'slug'     => $slug,
		'replaced' => in_array( $root . '/' . $slug, $before, true ),
	);

	if ( $theme ) {
		$info = $upgrader->theme_info();
		if ( ! $info ) {
			return new WP_Error( 'cm_install_unknown', __( 'The theme was installed, but WordPress cannot read it.', 'wp-sites-hq' ) );
		}
		if ( $o['activate'] && get_stylesheet() !== $info->get_stylesheet() ) {
			if ( $info->errors() ) {
				/* translators: %s: why the theme cannot be used */
				return new WP_Error( 'cm_theme_broken', sprintf( __( 'The theme was installed but not switched on: %s', 'wp-sites-hq' ), $info->errors()->get_error_message() ) );
			}
			switch_theme( $info->get_stylesheet() );
		}
		return $out + array(
			'name'      => (string) $info->get( 'Name' ),
			'version'   => (string) $info->get( 'Version' ),
			'activated' => get_stylesheet() === $info->get_stylesheet(),
		);
	}

	$file = $upgrader->plugin_info();
	if ( ! $file ) {
		return new WP_Error( 'cm_install_unknown', __( 'The zip was installed, but WordPress found no plugin file in it.', 'wp-sites-hq' ) );
	}
	if ( $o['activate'] ) {
		$activated = activate_plugin( $file );
		if ( is_wp_error( $activated ) ) {
			/* translators: %s: activation error */
			return new WP_Error( 'cm_plugin_activation_failed', sprintf( __( 'The plugin was installed but could not be activated: %s', 'wp-sites-hq' ), $activated->get_error_message() ) );
		}
	}
	$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
	return $out + array(
		'name'      => (string) $data['Name'],
		'version'   => (string) $data['Version'],
		'plugin'    => $file,
		'activated' => is_plugin_active( $file ),
	);
}
