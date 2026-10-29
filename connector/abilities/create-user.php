<?php
/**
 * Create User Ability - WordPress Abilities API
 *
 * Creates a WordPress user with a random password. No email is sent.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_create_user_ability' );
function cm_register_create_user_ability() {
	wp_register_ability(
		'classic-monks/create-user',
		array(
			'label'               => __( 'Create User', 'monks-central' ),
			'description'         => __(
				"Creates a WordPress user account. A random password is set and never returned; the person sets their own with \"Lost your password?\" on the login screen. No email is sent. \n\n## Parameters\n- **username**: Login name (required).\n- **email**: Email address (required).\n- **role**: Role slug (default: the site's default role). Must be a role you are allowed to assign.\n- **first_name**, **last_name**, **display_name**: Optional.\n- **confirm_admin_role**: Must be true to create a user whose role can manage the site (e.g. administrator). Only administrators can do that.\n\n## Returns\nThe new user's id, username, email and role.",
				'monks-central'
			),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'username'           => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 60,
					),
					'email'              => array(
						'type'      => 'string',
						'minLength' => 3,
						'maxLength' => 100,
					),
					'role'               => array( 'type' => 'string' ),
					'first_name'         => array( 'type' => 'string' ),
					'last_name'          => array( 'type' => 'string' ),
					'display_name'       => array( 'type' => 'string' ),
					'confirm_admin_role' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'username', 'email' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'username' => array( 'type' => 'string' ),
					'email'    => array( 'type' => 'string' ),
					'role'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_create_user',
			'permission_callback' => function () {
				return current_user_can( 'create_users' );
			},
			'meta'                => array(
				'annotations'       => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => false,
					'instructions' => 'Confirm the username, email and role with the user first. Never create an administrator unless the user explicitly asked for one.',
				),
				'cm_always_approve' => true,
				'show_in_rest'      => true,
			),
		)
	);
}

function cm_execute_create_user( $input ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$role  = isset( $input['role'] ) && '' !== $input['role'] ? sanitize_key( $input['role'] ) : get_option( 'default_role', 'subscriber' );
	$roles = get_editable_roles();

	if ( ! isset( $roles[ $role ] ) || ! current_user_can( 'promote_users' ) ) {
		return new WP_Error( 'cm_user_role_not_allowed', __( 'That role does not exist or you are not allowed to assign it.', 'monks-central' ) );
	}

	if ( ! empty( $roles[ $role ]['capabilities']['manage_options'] ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'cm_user_admin_role_forbidden', __( 'Only administrators can create users with this role.', 'monks-central' ) );
		}
		if ( empty( $input['confirm_admin_role'] ) ) {
			return new WP_Error( 'cm_user_admin_role_unconfirmed', __( 'This role can manage the whole site. Ask the user to confirm, then call again with confirm_admin_role set to true.', 'monks-central' ) );
		}
	}

	$email = sanitize_email( $input['email'] );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'cm_user_bad_email', __( 'The email address is not valid.', 'monks-central' ) );
	}

	// wp_insert_user() rejects duplicate or invalid usernames and duplicate emails.
	$user_id = wp_insert_user(
		array(
			'user_login'   => sanitize_user( $input['username'], true ),
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24 ),
			'role'         => $role,
			'first_name'   => sanitize_text_field( $input['first_name'] ?? '' ),
			'last_name'    => sanitize_text_field( $input['last_name'] ?? '' ),
			'display_name' => sanitize_text_field( $input['display_name'] ?? '' ),
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	$user = get_userdata( $user_id );

	return array(
		'id'       => (int) $user_id,
		'username' => $user->user_login,
		'email'    => $user->user_email,
		'role'     => $role,
	);
}
