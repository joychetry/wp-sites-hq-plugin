<?php
/**
 * List Taxonomies Ability - WordPress Abilities API
 *
 * Lists all registered taxonomies.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 1.2.0
 * @requires WordPress 6.9+ (Abilities API)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the list-taxonomies ability.
 *
 * Returns all registered taxonomies with details.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'wpshq_register_list_taxonomies_ability' );
function wpshq_register_list_taxonomies_ability() {
	wp_register_ability(
		'classic-monks/list-taxonomies',
		array(
			'label'               => __( 'List Taxonomies', 'wp-sites-hq' ),
			'description'         => __(
				"Lists all registered taxonomies in WordPress. Use this to discover available content organization structures. \n\n## Optional Parameters\n- **public_only**: Only show public taxonomies (default true)\n- **object_type**: Filter by object type (post, page, etc.)\n\n## Returns\nArray of taxonomies with: name, label, description, public, hierarchical, show_in_rest, object_types, term_count.",
				'wp-sites-hq'
			),
			'category'            => 'taxonomy',
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(
					'public_only' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Only show public taxonomies.',
					),
					'object_type' => array(
						'type'        => 'string',
						'description' => 'Filter by object type (post, page, etc.).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomies' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'         => array( 'type' => 'string' ),
								'label'        => array( 'type' => 'string' ),
								'description'  => array( 'type' => 'string' ),
								'public'       => array( 'type' => 'boolean' ),
								'hierarchical' => array( 'type' => 'boolean' ),
								'show_in_rest' => array( 'type' => 'boolean' ),
								'object_types' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'term_count'   => array( 'type' => 'integer' ),
							),
						),
					),
					'total'      => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'wpshq_execute_list_taxonomies',
			'permission_callback' => function () {
				return current_user_can( 'manage_categories' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Discover available taxonomies before creating/querying terms. Returns public and private taxonomies.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the list-taxonomies ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array Taxonomies information.
 */
function wpshq_execute_list_taxonomies( $input = array() ) {
	$args = array();

	$public_only = isset( $input['public_only'] ) ? (bool) $input['public_only'] : true;
	if ( $public_only ) {
		$args['public'] = true;
	}

	if ( ! empty( $input['object_type'] ) ) {
		$args['object_type'] = array( sanitize_key( $input['object_type'] ) );
	}

	$taxonomies = get_taxonomies( $args, 'objects' );
	$result     = array();

	foreach ( $taxonomies as $taxonomy ) {
		$term_count = wp_count_terms( array( 'taxonomy' => $taxonomy->name ) );

		$result[] = array(
			'name'         => $taxonomy->name,
			'label'        => $taxonomy->label,
			'description'  => $taxonomy->description,
			'public'       => $taxonomy->public,
			'hierarchical' => $taxonomy->hierarchical,
			'show_in_rest' => $taxonomy->show_in_rest,
			'object_types' => $taxonomy->object_type,
			'term_count'   => is_wp_error( $term_count ) ? 0 : (int) $term_count,
		);
	}

	return array(
		'taxonomies' => $result,
		'total'      => count( $result ),
	);
}
