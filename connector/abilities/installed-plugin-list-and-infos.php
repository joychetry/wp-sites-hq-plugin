<?php
/**
 * Get Plugins Ability - WordPress Abilities API
 *
 * Lists all installed plugins with status information.
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
 * Register the get-plugins ability.
 *
 * Returns installed plugins with status and details.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_plugins_ability' );
function cm_register_get_plugins_ability() {
	wp_register_ability(
		'classic-monks/get-plugins',
		array(
			'label'               => __( 'Get Plugins', 'monks-central' ),
			'description'         => __(
				"Lists all installed WordPress plugins with their status and details. Use this ability to audit installed plugins or check for specific functionality. \n\n## Optional Parameters\n- **status**: Filter by status (active, inactive, all). Default: all\n- **search**: Search term to filter by plugin name\n- **force_update_check**: Force a fresh plugin update check (default: false, uses cached data)\n- **include_update_package_url**: Include masked package URL (requires manage_options)\n\n## Returns\nArray of plugins with: name, version, description, author, active status, file path, and update status (if available). Also includes global and filtered counts for active plugins and available updates.",
				'monks-central'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(
					'status'                     => array(
						'type'        => 'string',
						'enum'        => array( 'active', 'inactive', 'all' ),
						'default'     => 'all',
						'description' => 'Filter by activation status.',
					),
					'search'                     => array(
						'type'        => 'string',
						'description' => 'Search term to filter by plugin name.',
					),
					'limit'                      => array(
						'type'        => 'integer',
						'default'     => 100,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of plugins to return (1-100).',
					),
					'offset'                     => array(
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Number of plugins to skip.',
					),
					'force_update_check'         => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Force a fresh plugin update check before returning data.',
					),
					'include_update_package_url' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Include update package URL (masked query/fragment). Requires manage_options.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugins'                => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'               => array( 'type' => 'string' ),
								'version'            => array( 'type' => 'string' ),
								'current_version'    => array( 'type' => 'string' ),
								'description'        => array( 'type' => 'string' ),
								'author'             => array( 'type' => 'string' ),
								'active'             => array( 'type' => 'boolean' ),
								'network_active'     => array( 'type' => 'boolean' ),
								'file'               => array( 'type' => 'string' ),
								'plugin_uri'         => array(
									'type'   => array( 'string', 'null' ),
									'format' => 'uri',
								),
								'update_available'   => array( 'type' => 'boolean' ),
								'available_version'  => array( 'type' => 'string' ),
								'requires'           => array( 'type' => 'string' ),
								'requires_php'       => array( 'type' => 'string' ),
								'tested'             => array( 'type' => 'string' ),
								'update_package_url' => array(
									'type'   => array( 'string', 'null' ),
									'format' => 'uri',
								),
								'update_source'      => array( 'type' => 'string' ),
							),
						),
					),
					'total'                  => array( 'type' => 'integer' ),
					'active_count'           => array( 'type' => 'integer' ),
					'filtered_active_count'  => array( 'type' => 'integer' ),
					'updates_count'          => array( 'type' => 'integer' ),
					'filtered_updates_count' => array( 'type' => 'integer' ),
					'updates_checked_at'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_plugins',
			'permission_callback' => function () {
				return current_user_can( 'activate_plugins' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Lists all plugins with activation status. Does not activate/deactivate plugins.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-plugins ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Plugins information or permission error.
 */
function cm_execute_get_plugins( $input = array() ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'wp_update_plugins' ) ) {
		require_once ABSPATH . 'wp-includes/update.php';
	}

	$force_update_check         = isset( $input['force_update_check'] ) ? filter_var( $input['force_update_check'], FILTER_VALIDATE_BOOLEAN ) : false;
	$include_update_package_url = isset( $input['include_update_package_url'] ) ? filter_var( $input['include_update_package_url'], FILTER_VALIDATE_BOOLEAN ) : false;

	if ( $force_update_check && ! current_user_can( 'update_plugins' ) ) {
		return new WP_Error(
			'cm_forbidden_update_check',
			__( 'You are not allowed to force plugin update checks.', 'monks-central' ),
			array( 'status' => 403 )
		);
	}

	if ( $include_update_package_url && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'cm_forbidden_package_url',
			__( 'You are not allowed to view plugin update package URLs.', 'monks-central' ),
			array( 'status' => 403 )
		);
	}

	if ( $force_update_check && function_exists( 'wp_update_plugins' ) ) {
		wp_update_plugins();
	}

	$all_plugins        = get_plugins();
	$active_plugins     = get_option( 'active_plugins', array() );
	$status_filter      = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'all';
	$search             = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';
	$update_plugins     = get_site_transient( 'update_plugins' );
	$update_response    = ( is_object( $update_plugins ) && isset( $update_plugins->response ) && is_array( $update_plugins->response ) )
		? $update_plugins->response
		: array();
	$no_update_response = ( is_object( $update_plugins ) && isset( $update_plugins->no_update ) && is_array( $update_plugins->no_update ) )
		? $update_plugins->no_update
		: array();
	$updates_checked_at = ( is_object( $update_plugins ) && ! empty( $update_plugins->last_checked ) )
		? gmdate( 'c', (int) $update_plugins->last_checked )
		: '';
	$updates_count      = count( $update_response );

	$plugins                = array();
	$active_count           = 0;
	$filtered_active_count  = 0;
	$filtered_updates_count = 0;

	foreach ( $all_plugins as $plugin_file => $plugin_data ) {
		$is_site_active    = in_array( $plugin_file, $active_plugins, true );
		$is_network_active = is_multisite() && function_exists( 'is_plugin_active_for_network' ) ? is_plugin_active_for_network( $plugin_file ) : false;
		$is_active         = $is_site_active || $is_network_active;
		$plugin_name       = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '';

		if ( $is_active ) {
			++$active_count;
		}

		// Status filter
		if ( 'active' === $status_filter && ! $is_active ) {
			continue;
		}
		if ( 'inactive' === $status_filter && $is_active ) {
			continue;
		}

		// Search filter
		if ( ! empty( $search ) && stripos( $plugin_name, $search ) === false ) {
			continue;
		}

		$update_source = 'none';
		$update_data   = null;

		if ( isset( $update_response[ $plugin_file ] ) ) {
			$update_source = 'response';
			$update_data   = $update_response[ $plugin_file ];
		} elseif ( isset( $no_update_response[ $plugin_file ] ) ) {
			$update_source = 'no_update';
			$update_data   = $no_update_response[ $plugin_file ];
		}

		$update_payload    = is_object( $update_data ) ? (array) $update_data : ( is_array( $update_data ) ? $update_data : array() );
		$current_version   = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
		$available_version = ! empty( $update_payload['new_version'] )
			? (string) $update_payload['new_version']
			: $current_version;
		$requires          = ! empty( $update_payload['requires'] ) ? (string) $update_payload['requires'] : '';
		$requires_php      = ! empty( $update_payload['requires_php'] )
			? (string) $update_payload['requires_php']
			: ( isset( $plugin_data['RequiresPHP'] ) ? (string) $plugin_data['RequiresPHP'] : '' );
		$tested            = ! empty( $update_payload['tested'] ) ? (string) $update_payload['tested'] : '';
		$update_available  = isset( $update_response[ $plugin_file ] );
		$plugin_uri        = ! empty( $plugin_data['PluginURI'] ) ? esc_url_raw( (string) $plugin_data['PluginURI'] ) : '';
		$plugin_uri        = '' !== $plugin_uri ? $plugin_uri : null;

		$update_package_url = null;
		if ( $include_update_package_url && ! empty( $update_payload['package'] ) ) {
			$update_package_url = cm_mask_plugin_update_package_url( (string) $update_payload['package'] );
		}

		if ( $is_active ) {
			++$filtered_active_count;
		}
		if ( $update_available ) {
			++$filtered_updates_count;
		}

		$plugins[] = array(
			'name'               => $plugin_name,
			'version'            => $current_version,
			'current_version'    => $current_version,
			'description'        => wp_strip_all_tags( $plugin_data['Description'] ?? '' ),
			'author'             => wp_strip_all_tags( $plugin_data['Author'] ?? '' ),
			'active'             => $is_active,
			'network_active'     => $is_network_active,
			'file'               => $plugin_file,
			'plugin_uri'         => $plugin_uri,
			'update_available'   => $update_available,
			'available_version'  => $available_version,
			'requires'           => $requires,
			'requires_php'       => $requires_php,
			'tested'             => $tested,
			'update_package_url' => $update_package_url,
			'update_source'      => $update_source,
		);
	}

	// Pagination
	$total_count = count( $plugins );
	$limit       = isset( $input['limit'] ) ? absint( $input['limit'] ) : 100;
	$offset      = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;
	$plugins     = array_slice( $plugins, $offset, $limit );

	return array(
		'plugins'                => $plugins,
		'total'                  => $total_count,
		'active_count'           => $active_count,
		'filtered_active_count'  => $filtered_active_count,
		'updates_count'          => $updates_count,
		'filtered_updates_count' => $filtered_updates_count,
		'updates_checked_at'     => $updates_checked_at,
	);
}

/**
 * Mask plugin update package URL by stripping query string and fragment.
 *
 * @since 1.2.0
 * @param string $url Update package URL.
 * @return string|null Masked URL or null when invalid.
 */
function cm_mask_plugin_update_package_url( $url ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return null;
	}

	$masked = $parts['scheme'] . '://' . $parts['host'];

	if ( ! empty( $parts['port'] ) ) {
		$masked .= ':' . (int) $parts['port'];
	}
	if ( ! empty( $parts['path'] ) ) {
		$masked .= $parts['path'];
	}

	$masked = esc_url_raw( $masked );

	return '' !== $masked ? $masked : null;
}
