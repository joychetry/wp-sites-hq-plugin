<?php
/**
 * Update Translations Ability - WordPress Abilities API
 *
 * Installs every pending translation update (core, plugins, themes), as the
 * "Update Translations" button in Dashboard > Updates does (Monks Central M5).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_update_translations_ability' );
function cm_register_update_translations_ability() {
	wp_register_ability(
		'classic-monks/update-translations',
		array(
			'label'               => __( 'Update Translations', 'monks-central' ),
			'description'         => __(
				'Installs all pending translation updates for WordPress, plugins and themes (see list-updates). Same as "Update Translations" in Dashboard > Updates. Returns each language pack and whether it was installed.',
				'monks-central'
			),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'updated' => array( 'type' => 'integer' ),
					'failed'  => array( 'type' => 'integer' ),
					'items'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_update_translations',
			// Denied by core under DISALLOW_FILE_MODS, and to sub-site admins on multisite.
			'permission_callback' => function () {
				return current_user_can( 'update_languages' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		)
	);
}

function cm_execute_update_translations() {
	cm_ai_require_plugin_management_files();
	$packs = wp_get_translation_updates();
	if ( ! $packs ) {
		return array(
			'updated' => 0,
			'failed'  => 0,
			'items'   => array(),
			'message' => __( 'Translations are up to date.', 'monks-central' ),
		);
	}

	$upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
	$results  = $upgrader->bulk_upgrade( $packs );
	if ( is_wp_error( $results ) ) {
		return $results;
	}

	$items = array();
	foreach ( $packs as $i => $pack ) {
		$result  = is_array( $results ) ? ( $results[ $i ] ?? false ) : false;
		$items[] = array(
			'type'     => (string) $pack->type,
			'slug'     => (string) $pack->slug,
			'language' => (string) $pack->language,
			'updated'  => ! empty( $result ) && ! is_wp_error( $result ),
			'error'    => is_wp_error( $result ) ? $result->get_error_message() : '',
		);
	}
	$updated = count( array_filter( wp_list_pluck( $items, 'updated' ) ) );

	return array(
		'updated' => $updated,
		'failed'  => count( $items ) - $updated,
		'items'   => $items,
		/* translators: 1: installed count, 2: total count */
		'message' => sprintf( __( 'Installed %1$d of %2$d translation updates.', 'monks-central' ), $updated, count( $items ) ),
	);
}
