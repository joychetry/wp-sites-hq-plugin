<?php
/**
 * Get Terms Ability - WordPress Abilities API
 *
 * Gets taxonomy terms (categories, tags, custom taxonomies).
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
 * Register the get-terms ability.
 *
 * Returns taxonomy terms with filtering options.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_terms_ability' );
function cm_register_get_terms_ability() {
	wp_register_ability(
		'classic-monks/get-terms',
		array(
			'label'               => __( 'Get Terms', 'monks-central' ),
			'description'         => __(
				"Gets taxonomy terms (categories, tags, or custom taxonomies). Use this ability to retrieve available terms for content categorization. \n\n## Optional Parameters\n- **taxonomy**: Taxonomy slug (category, post_tag, or custom). Default: category\n- **limit**: Number of terms to return (1-100, default 20)\n- **hide_empty**: Whether to hide terms with no posts (default: false)\n- **parent**: Parent term ID to get children (0 for top-level only)\n- **search**: Search term to filter by name\n- **orderby**: Order by name, count, id, slug (default name)\n- **order**: ASC or DESC (default ASC)\n\n## Returns\nArray of terms with: id, name, slug, description, count, parent. Also includes taxonomy name and total count.",
				'monks-central'
			),
			'category'            => 'taxonomy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy'   => array(
						'type'        => 'string',
						'default'     => 'category',
						'description' => 'Taxonomy slug: category, post_tag, or custom taxonomy.',
					),
					'limit'      => array(
						'type'        => 'integer',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of terms to return (1-100).',
					),
					'hide_empty' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Whether to hide terms with no posts.',
					),
					'parent'     => array(
						'type'        => 'integer',
						'description' => 'Parent term ID. Use 0 for top-level terms only.',
					),
					'search'     => array(
						'type'        => 'string',
						'description' => 'Search term to filter by name.',
					),
					'orderby'    => array(
						'type'        => 'string',
						'enum'        => array( 'name', 'count', 'id', 'slug' ),
						'default'     => 'name',
						'description' => 'Field to order by.',
					),
					'order'      => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'default'     => 'ASC',
						'description' => 'Sort direction.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'terms'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'          => array( 'type' => 'integer' ),
								'name'        => array( 'type' => 'string' ),
								'slug'        => array( 'type' => 'string' ),
								'description' => array( 'type' => 'string' ),
								'count'       => array( 'type' => 'integer' ),
								'parent'      => array( 'type' => 'integer' ),
								'link'        => array(
									'type'   => 'string',
									'format' => 'uri',
								),
							),
						),
					),
					'taxonomy' => array( 'type' => 'string' ),
					'total'    => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_terms',
			'permission_callback' => function () {
				return current_user_can( 'manage_categories' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Use to retrieve taxonomy terms. Specify taxonomy name. For tags specifically, use get-tags instead.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-terms ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Terms and metadata or error.
 */
function cm_execute_get_terms( $input = array() ) {
	$taxonomy = sanitize_key( isset( $input['taxonomy'] ) ? $input['taxonomy'] : 'category' );

	// Validate taxonomy exists
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error(
			'invalid_taxonomy',
			/* translators: %s: taxonomy name */
			sprintf( __( 'Taxonomy "%s" does not exist.', 'monks-central' ), $taxonomy )
		);
	}

	$args = array(
		'taxonomy'   => $taxonomy,
		'number'     => min( absint( isset( $input['limit'] ) ? $input['limit'] : 20 ), 100 ),
		'hide_empty' => isset( $input['hide_empty'] ) ? (bool) $input['hide_empty'] : false,
		'orderby'    => sanitize_key( isset( $input['orderby'] ) ? $input['orderby'] : 'name' ),
		'order'      => ( isset( $input['order'] ) && 'DESC' === strtoupper( $input['order'] ) ) ? 'DESC' : 'ASC',
	);

	if ( isset( $input['parent'] ) ) {
		$args['parent'] = absint( $input['parent'] );
	}

	if ( ! empty( $input['search'] ) ) {
		$args['search'] = sanitize_text_field( $input['search'] );
	}

	$terms = get_terms( $args );

	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	$result = array();
	foreach ( $terms as $term ) {
		$result[] = array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => $term->count,
			'parent'      => $term->parent,
			'link'        => get_term_link( $term ),
		);
	}

	return array(
		'terms'    => $result,
		'taxonomy' => $taxonomy,
		'total'    => count( $result ),
	);
}
