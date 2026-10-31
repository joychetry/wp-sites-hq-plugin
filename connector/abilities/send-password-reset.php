<?php
/**
 * Send Password Reset Ability - WordPress Abilities API
 *
 * Emails a user WordPress' own "set a new password" link (WP Sites HQ M7). The
 * password is never seen or set by the caller; the person picks it from the email.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_send_password_reset_ability' );
function cm_register_send_password_reset_ability() {
	wp_register_ability(
		'classic-monks/send-password-reset',
		array(
			'label'               => __( 'Send Password Reset', 'wp-sites-hq' ),
			'description'         => __( 'Emails a user the link to set a new password, as "Lost your password?" on the login screen does. Their current password keeps working until they set a new one.', 'wp-sites-hq' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'user_id' ),
				'properties'           => array(
					'user_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array( 'type' => 'integer' ),
					'sent'    => array( 'type' => 'boolean' ),
					'email'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_send_password_reset',
			'permission_callback' => function ( $input ) {
				return ! empty( $input['user_id'] ) && current_user_can( 'edit_user', (int) $input['user_id'] );
			},
			'meta'                => array(
				// WP Sites HQ only: keeps AI clients under their tool caps.
				'cm_operator_only' => true,
				'annotations'      => array(
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
					'instructions' => 'Sends an email to the user. Confirm the user first.',
				),
				'show_in_rest'     => true,
			),
		)
	);
}

function cm_execute_send_password_reset( $input ) {
	$user = get_userdata( absint( $input['user_id'] ) );
	if ( ! $user ) {
		return new WP_Error( 'cm_user_not_found', __( 'User not found.', 'wp-sites-hq' ) );
	}
	// Core's own flow: honours allow_password_reset and sends the site's reset email.
	$sent = retrieve_password( $user->user_login );
	if ( is_wp_error( $sent ) ) {
		return $sent;
	}
	return array(
		'user_id' => $user->ID,
		'sent'    => true,
		'email'   => $user->user_email,
	);
}
