<?php
/**
 * Update Core Database Ability - WordPress Abilities API
 *
 * Runs WordPress's database upgrade after a core update, like wp-admin/upgrade.php.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_update_core_db_ability' );
function cm_register_update_core_db_ability() {
	wp_register_ability(
		'classic-monks/update-core-db',
		array(
			'label'               => __( 'Update Core Database', 'wp-sites-hq' ),
			'description'         => __(
				"Runs the WordPress database upgrade, the same as the \"Update WordPress Database\" button after a core update (wp-admin/upgrade.php). Does nothing when the database is already current. \n\n## Notes\n- Changes the database schema and data for this site only; it cannot be undone.\n\n## Returns\nprevious_db_version, db_version, required_db_version, updated.",
				'wp-sites-hq'
			),
			'category'            => 'system',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'previous_db_version' => array( 'type' => 'integer' ),
					'db_version'          => array( 'type' => 'integer' ),
					'required_db_version' => array( 'type' => 'integer' ),
					'updated'             => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'cm_execute_update_core_db',
			// Core's upgrade.php checks no capability; this is database-only, so the database tools' caps (not update_core,
			// which DISALLOW_FILE_MODS denies even though the host may have updated the files).
			'permission_callback' => function () {
				return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
			},
			'meta'                => array(
				'cm_always_approve'   => true, // Can't be undone: trusted connections still ask.
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
					'instructions' => 'Run after update-core when get-core-version or the admin asks for a database update. Suggest a backup first.',
				),
				'cm_preview_callback' => 'cm_preview_core_db_version',
				'show_in_rest'        => true,
			),
		)
	);
}

function cm_preview_core_db_version( $input = array() ) {
	global $wp_db_version;
	return array(
		'db_version'          => (int) get_option( 'db_version' ),
		'required_db_version' => (int) $wp_db_version,
	);
}

function cm_execute_update_core_db() {
	global $wp_db_version;
	$previous = (int) get_option( 'db_version' );

	if ( $previous !== (int) $wp_db_version ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		wp_upgrade();
	}
	$current = (int) get_option( 'db_version' );

	return array(
		'previous_db_version' => $previous,
		'db_version'          => $current,
		'required_db_version' => (int) $wp_db_version,
		'updated'             => $current !== $previous,
	);
}
