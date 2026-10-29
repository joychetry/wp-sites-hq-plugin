<?php
/**
 * Get Core Version Ability - WordPress Abilities API
 *
 * Reports the WordPress version and whether a core update is available.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_get_core_version_ability' );
function cm_register_get_core_version_ability() {
	wp_register_ability(
		'classic-monks/get-core-version',
		array(
			'label'               => __( 'Get Core Version', 'monks-central' ),
			'description'         => __(
				"Reports the installed WordPress version and whether a newer one is available. Refreshes the update check when it is more than 12 hours old. \n\n## Returns\n- **version**: Installed version.\n- **update_available**: true when a newer release is offered.\n- **latest_version**: Newest version offered (the installed one when up to date).\n- **last_checked**: Unix time of the last update check.",
				'monks-central'
			),
			'category'            => 'system',
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'version'          => array( 'type' => 'string' ),
					'update_available' => array( 'type' => 'boolean' ),
					'latest_version'   => array( 'type' => 'string' ),
					'last_checked'     => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_core_version',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Check before suggesting a WordPress update.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * The newest core release WordPress offers, or null when up to date. Refreshes a stale check.
 */
function cm_ai_get_core_update_offer() {
	require_once ABSPATH . 'wp-includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';

	$current = get_site_transient( 'update_core' );
	if ( ! is_object( $current ) || empty( $current->last_checked ) || time() - $current->last_checked > 12 * HOUR_IN_SECONDS ) {
		wp_version_check();
	}

	foreach ( (array) get_core_updates() as $update ) {
		if ( is_object( $update ) && 'upgrade' === $update->response ) {
			return $update;
		}
	}
	return null;
}

function cm_execute_get_core_version() {
	$offer     = cm_ai_get_core_update_offer();
	$transient = get_site_transient( 'update_core' );

	return array(
		'version'          => (string) get_bloginfo( 'version' ),
		'update_available' => null !== $offer,
		'latest_version'   => $offer ? (string) $offer->current : (string) get_bloginfo( 'version' ),
		'last_checked'     => is_object( $transient ) ? (int) $transient->last_checked : 0,
	);
}
