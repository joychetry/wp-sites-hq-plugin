<?php
/**
 * Get Users Ability - WordPress Abilities API
 *
 * Lists WordPress users with filtering options.
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
 * Register the get-users ability.
 *
 * Returns WordPress users with filtering and pagination.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_users_ability' );
function cm_register_get_users_ability() {
	wp_register_ability(
		'classic-monks/get-users',
		array(
			'label'               => __( 'Get Users', 'monks-central' ),
			'description'         => __(
				"Lists WordPress users with filtering options. Use this ability to retrieve user information for content attribution, permissions, or user management. \n\n## Optional Parameters\n- **role**: Filter by user role slug (administrator, editor, shop_manager, any role the site has)\n- **limit**: Number of users to return (1-100, default 10)\n- **offset**: Number of users to skip (for pagination)\n- **search**: Search term to filter by name, login, or email\n- **orderby**: Order by ID, display_name, user_login, registered (default registered)\n- **order**: ASC or DESC (default DESC)\n\n## Returns\nArray of users with: id, username, email, display_name, role, registered_date. Also includes total count for pagination, and roles: the roles you may give a user (id and name).",
				'monks-central'
			),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(
					'role'    => array(
						'type'        => 'string',
						'default'     => '',
						'description' => 'Filter by user role slug. Empty string returns all roles.',
					),
					'limit'   => array(
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of users to return (1-100).',
					),
					'offset'  => array(
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Number of users to skip.',
					),
					'search'  => array(
						'type'        => 'string',
						'description' => 'Search by name, login, or email.',
					),
					'orderby' => array(
						'type'        => 'string',
						'enum'        => array( 'ID', 'display_name', 'user_login', 'registered' ),
						'default'     => 'registered',
						'description' => 'Field to order by.',
					),
					'order'   => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'default'     => 'DESC',
						'description' => 'Sort direction.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'users' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'              => array( 'type' => 'integer' ),
								'username'        => array( 'type' => 'string' ),
								'email'           => array( 'type' => 'string' ),
								'display_name'    => array( 'type' => 'string' ),
								'role'            => array( 'type' => 'string' ),
								'registered_date' => array(
									'type'   => 'string',
									'format' => 'date-time',
								),
							),
						),
					),
					'total' => array( 'type' => 'integer' ),
					'roles' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'   => array( 'type' => 'string' ),
								'name' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => 'cm_execute_get_users',
			'permission_callback' => function () {
				return current_user_can( 'list_users' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'List WordPress users. Sensitive data - only returns safe fields. Use role parameter to filter.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-users ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array Users and total count.
 */
function cm_execute_get_users( $input = array() ) {
	$args = array(
		'number'  => min( absint( isset( $input['limit'] ) ? $input['limit'] : 10 ), 100 ),
		'offset'  => absint( isset( $input['offset'] ) ? $input['offset'] : 0 ),
		'orderby' => sanitize_key( isset( $input['orderby'] ) ? $input['orderby'] : 'registered' ),
		'order'   => ( isset( $input['order'] ) && 'ASC' === strtoupper( $input['order'] ) ) ? 'ASC' : 'DESC',
	);

	if ( ! empty( $input['role'] ) ) {
		$args['role'] = sanitize_key( $input['role'] );
		if ( ! wp_roles()->is_role( $args['role'] ) ) {
			return new WP_Error( 'cm_user_role_not_found', __( 'This site has no such role.', 'monks-central' ) );
		}
	}

	if ( ! empty( $input['search'] ) ) {
		$args['search']         = '*' . sanitize_text_field( $input['search'] ) . '*';
		$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
	}

	$user_query = new WP_User_Query( $args );
	$users      = array();

	foreach ( $user_query->get_results() as $user ) {
		$users[] = array(
			'id'              => $user->ID,
			'username'        => $user->user_login,
			'email'           => $user->user_email,
			'display_name'    => $user->display_name,
			'role'            => isset( $user->roles[0] ) ? $user->roles[0] : 'none',
			'registered_date' => $user->user_registered,
		);
	}

	return array(
		'users' => $users,
		'total' => (int) $user_query->get_total(),
		'roles' => cm_ai_assignable_roles(),
	);
}

/**
 * The roles the current user may give someone, as create-user and update-user check them.
 */
function cm_ai_assignable_roles() {
	if ( ! current_user_can( 'promote_users' ) ) {
		return array();
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$roles = array();
	foreach ( get_editable_roles() as $id => $role ) {
		if ( empty( $role['capabilities']['manage_options'] ) || current_user_can( 'manage_options' ) ) {
			$roles[] = array(
				'id'   => $id,
				'name' => translate_user_role( $role['name'] ),
			);
		}
	}
	return $roles;
}
