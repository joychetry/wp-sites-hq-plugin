<?php
/**
 * Create Term Ability - WordPress Abilities API
 *
 * Creates new taxonomy terms.
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
 * Register the create-term ability.
 *
 * Creates new taxonomy terms (categories, tags, custom).
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_create_term_ability' );
function cm_register_create_term_ability() {
	wp_register_ability(
		'classic-monks/create-term',
		array(
			'label'               => __( 'Create Term', 'monks-central' ),
			'description'         => __(
				"Creates a new taxonomy term (category, tag, or custom taxonomy). Use this ability to add new categories, tags, or custom taxonomy terms. \n\n## Required Parameters\n- **name**: The term name (required)\n- **taxonomy**: Taxonomy slug - category, post_tag, or custom (required)\n\n## Optional Parameters\n- **slug**: Term slug (auto-generated from name if not provided)\n- **description**: Term description\n- **parent**: Parent term ID (for hierarchical taxonomies)\n\n## Returns\nCreated term ID, name, slug, and taxonomy.",
				'monks-central'
			),
			'category'            => 'taxonomy',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'taxonomy' ),
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'The term name (required).',
					),
					'taxonomy'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Taxonomy slug (required).',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'Term slug (auto-generated if not provided).',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Term description.',
					),
					'parent'      => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Parent term ID (for hierarchical taxonomies).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'term_id'  => array( 'type' => 'integer' ),
					'name'     => array( 'type' => 'string' ),
					'slug'     => array( 'type' => 'string' ),
					'taxonomy' => array( 'type' => 'string' ),
					'link'     => array(
						'type'   => 'string',
						'format' => 'uri',
					),
				),
			),
			'execute_callback'    => 'cm_execute_create_term',
			'permission_callback' => function ( $input = array() ) {
				// The taxonomy's own capability: manage_categories alone would let an editor create menus or product categories.
				// An unknown taxonomy falls through so execute can say it does not exist.
				$taxonomy = get_taxonomy( sanitize_key( $input['taxonomy'] ?? '' ) );
				return current_user_can( $taxonomy ? $taxonomy->cap->edit_terms : 'manage_categories' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false, // Creates new term each time
					'instructions' => 'Creates new taxonomy terms. Requires taxonomy slug AND term name. Check list-taxonomies first for available taxonomies.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the create-term ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Created term info or error.
 */
function cm_execute_create_term( $input ) {
	$taxonomy = sanitize_key( $input['taxonomy'] );

	// Validate taxonomy exists
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error(
			'invalid_taxonomy',
			/* translators: %s: taxonomy name */
			sprintf( __( 'Taxonomy "%s" does not exist.', 'monks-central' ), $taxonomy )
		);
	}

	$args = array();

	if ( ! empty( $input['slug'] ) ) {
		$args['slug'] = sanitize_title( $input['slug'] );
	}

	if ( ! empty( $input['description'] ) ) {
		$args['description'] = sanitize_text_field( $input['description'] );
	}

	if ( isset( $input['parent'] ) && $input['parent'] > 0 ) {
		// Check if taxonomy is hierarchical
		$tax_obj = get_taxonomy( $taxonomy );
		if ( $tax_obj && $tax_obj->hierarchical ) {
			$args['parent'] = absint( $input['parent'] );
		}
	}

	$result = wp_insert_term(
		sanitize_text_field( $input['name'] ),
		$taxonomy,
		$args
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$term = get_term( $result['term_id'], $taxonomy );

	return array(
		'term_id'  => $result['term_id'],
		'name'     => $term->name,
		'slug'     => $term->slug,
		'taxonomy' => $taxonomy,
		'link'     => is_wp_error( $link = get_term_link( $term ) ) ? '' : $link,
	);
}
