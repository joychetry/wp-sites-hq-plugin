<?php
/**
 * List Updates Ability - WordPress Abilities API
 *
 * Every pending update in one call (WP Sites HQ M5): WordPress core offers,
 * plugins, themes and translations, from WordPress's own update checks.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_list_updates_ability' );
function cm_register_list_updates_ability() {
	wp_register_ability(
		'classic-monks/list-updates',
		array(
			'label'               => __( 'List Updates', 'wp-sites-hq' ),
			'description'         => __(
				'Lists every pending update: WordPress core offers (version, locale), plugins and themes (installed and new version, required WordPress and PHP), and translations. Uses the results of WordPress\'s last update check (last_checked); pass refresh true to check WordPress.org now. Install with update-core, update-plugin, update-theme and update-translations.',
				'wp-sites-hq'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'refresh' => array(
						'type'        => 'boolean',
						'description' => 'Check WordPress.org now instead of using the last check. Slower. Default false.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'core'         => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'plugins'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'themes'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'translations' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'counts'       => array( 'type' => 'object' ),
					'last_checked' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => 'cm_execute_list_updates',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Pending updates from WordPress's update transients.
 *
 * @param bool $refresh Run the WordPress.org checks first.
 * @return array
 */
function cm_ai_list_updates( $refresh = false ) {
	cm_ai_require_plugin_management_files();
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';

	if ( $refresh ) {
		// Clearing the transients is how wp-admin's "Check again" forces the checks.
		wp_clean_update_cache();
		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();
	}

	$core = array();
	foreach ( (array) get_core_updates() as $offer ) {
		if ( is_object( $offer ) && 'upgrade' === $offer->response ) {
			$core[] = array(
				'version'     => (string) $offer->current,
				'locale'      => (string) $offer->locale,
				'php_version' => (string) ( $offer->php_version ?? '' ),
			);
		}
	}

	$plugins = array();
	foreach ( get_plugin_updates() as $file => $data ) {
		$plugins[] = array(
			'plugin'          => $file,
			'name'            => $data->Name, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core plugin/theme header fields
			'version'         => $data->Version, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core plugin/theme header fields
			'new_version'     => (string) ( $data->update->new_version ?? '' ),
			'requires_wp'     => (string) ( $data->update->requires ?? '' ),
			'requires_php'    => (string) ( $data->update->requires_php ?? '' ),
			'tested'          => (string) ( $data->update->tested ?? '' ),
			'package_offered' => ! empty( $data->update->package ),
		);
	}

	$themes = array();
	foreach ( get_theme_updates() as $stylesheet => $theme ) {
		$themes[] = array(
			'stylesheet'   => $stylesheet,
			'name'         => $theme->get( 'Name' ),
			'version'      => $theme->get( 'Version' ),
			'new_version'  => (string) ( $theme->update['new_version'] ?? '' ),
			'requires_wp'  => (string) ( $theme->update['requires'] ?? '' ),
			'requires_php' => (string) ( $theme->update['requires_php'] ?? '' ),
		);
	}

	$translations = array();
	foreach ( wp_get_translation_updates() as $pack ) {
		$translations[] = array(
			'type'     => (string) $pack->type,
			'slug'     => (string) $pack->slug,
			'language' => (string) $pack->language,
			'version'  => (string) $pack->version,
			'updated'  => (string) $pack->updated,
		);
	}

	$checked = array();
	foreach ( array(
		'core'    => 'update_core',
		'plugins' => 'update_plugins',
		'themes'  => 'update_themes',
	) as $key => $transient ) {
		$value           = get_site_transient( $transient );
		$checked[ $key ] = is_object( $value ) && isset( $value->last_checked ) ? (int) $value->last_checked : 0;
	}

	return array(
		'core'         => $core,
		'plugins'      => $plugins,
		'themes'       => $themes,
		'translations' => $translations,
		'counts'       => array(
			'core'         => count( $core ),
			'plugins'      => count( $plugins ),
			'themes'       => count( $themes ),
			'translations' => count( $translations ),
		),
		'last_checked' => $checked,
	);
}

function cm_execute_list_updates( $input = array() ) {
	return cm_ai_list_updates( ! empty( $input['refresh'] ) );
}
