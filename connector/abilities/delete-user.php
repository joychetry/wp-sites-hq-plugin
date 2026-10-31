<?php
/**
 * Delete User Ability - WordPress Abilities API
 *
 * Deletes a user and hands their content to another user. Cannot be undone.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_delete_user_ability' );
function cm_register_delete_user_ability() {
	wp_register_ability(
		'classic-monks/delete-user',
		array(
			'label'               => __( 'Delete User', 'wp-sites-hq' ),
			'description'         => __(
				"Deletes a user account. Their posts and links are given to another user. \n\n## Parameters\n- **user_id**: The user to delete (required).\n- **reassign**: The user who receives their content (required).\n\n## Notes\n- This cannot be undone.\n- You cannot delete yourself or the last administrator.\n\n## Returns\nThe deleted user id and who received the content.",
				'wp-sites-hq'
			),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id'  => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'reassign' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'user_id', 'reassign' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'  => array( 'type' => 'integer' ),
					'reassign' => array( 'type' => 'integer' ),
					'deleted'  => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'cm_execute_delete_user',
			'permission_callback' => function ( $input ) {
				return ! empty( $input['user_id'] ) && current_user_can( 'delete_user', (int) $input['user_id'] );
			},
			'meta'                => array(
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
					'instructions' => 'Cannot be undone. Confirm the user and who receives their content before deleting.',
				),
				'cm_preview_callback' => 'cm_preview_user_state',
				'cm_always_approve'   => true,
				'show_in_rest'        => true,
			),
		)
	);
}

function cm_execute_delete_user( $input ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$user_id  = absint( $input['user_id'] );
	$reassign = absint( $input['reassign'] );

	if ( ! get_userdata( $user_id ) ) {
		return new WP_Error( 'cm_user_not_found', __( 'User not found.', 'wp-sites-hq' ) );
	}
	if ( get_current_user_id() === $user_id ) {
		return new WP_Error( 'cm_user_delete_self', __( 'You cannot delete your own account.', 'wp-sites-hq' ) );
	}
	if ( $reassign === $user_id || ! get_userdata( $reassign ) ) {
		return new WP_Error( 'cm_user_bad_reassign', __( 'Choose a different, existing user to receive the content.', 'wp-sites-hq' ) );
	}
	if ( cm_ai_is_last_admin( $user_id ) ) {
		return new WP_Error( 'cm_user_last_admin', __( 'This is the last administrator and cannot be deleted.', 'wp-sites-hq' ) );
	}

	return array(
		'user_id'  => $user_id,
		'reassign' => $reassign,
		'deleted'  => (bool) wp_delete_user( $user_id, $reassign ),
	);
}
