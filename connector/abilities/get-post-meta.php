<?php
/**
 * Get Post Meta Ability - WordPress Abilities API
 *
 * Retrieves meta data for a post.
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
 * Register the get-post-meta ability.
 *
 * Returns meta data for a post.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_post_meta_ability' );
function cm_register_get_post_meta_ability() {
	wp_register_ability(
		'classic-monks/get-post-meta',
		array(
			'label'               => __( 'Get Post Meta', 'monks-central' ),
			'description'         => __(
				"Retrieves custom field (meta) data for a post. Use this to get SEO meta, custom fields, or any stored metadata. \n\n## Required Parameters\n- **post_id**: The ID of the post (required)\n\n## Optional Parameters\n- **key**: Specific meta key to retrieve (returns all if not provided)\n- **exclude_private**: Whether to exclude keys starting with underscore (default: true)\n\n## Returns\nIf key specified: single key-value pair. If no key: all public meta fields with their values.",
				'monks-central'
			),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The ID of the post (required).',
					),
					'key'             => array(
						'type'        => 'string',
						'description' => 'Specific meta key to retrieve.',
					),
					'exclude_private' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Exclude private meta keys (starting with underscore).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'key'     => array( 'type' => 'string' ),
					'value'   => array( 'type' => array( 'string', 'array', 'object', 'null' ) ),
					'meta'    => array( 'type' => 'object' ),
					'count'   => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_post_meta',
			'permission_callback' => function ( $input ) {
				if ( empty( $input['post_id'] ) ) {
					return false;
				}
				return current_user_can( 'edit_post', $input['post_id'] );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Get post meta/custom fields. Use key parameter for specific field or omit for all.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-post-meta ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Meta data or error.
 */
function cm_execute_get_post_meta( $input ) {
	$post_id = absint( $input['post_id'] );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'monks-central' ) );
	}

	// Protected fields (a leading _) can hold a plugin's tokens. Core REST hides them, so here only
	// administrators read them.
	$see_protected = current_user_can( 'manage_options' );

	// Get specific key
	if ( ! empty( $input['key'] ) ) {
		// Meta keys are case-sensitive, so no sanitize_key().
		$meta_key = trim( (string) $input['key'] );
		if ( ! $see_protected && is_protected_meta( $meta_key, 'post' ) ) {
			/* translators: %s: meta key */
			return new WP_Error( 'protected_meta', sprintf( __( 'The custom field "%s" is protected. Only administrators can read it.', 'monks-central' ), $meta_key ) );
		}
		$value = get_post_meta( $post_id, $meta_key, true );

		return array(
			'post_id' => $post_id,
			'key'     => $meta_key,
			'value'   => $value,
		);
	}

	// Get all meta
	$all_meta        = get_post_meta( $post_id );
	$exclude_private = isset( $input['exclude_private'] ) ? (bool) $input['exclude_private'] : true;

	$meta = array();
	foreach ( $all_meta as $key => $values ) {
		// Skip private keys if requested
		if ( ( $exclude_private && strpos( $key, '_' ) === 0 ) || ( ! $see_protected && is_protected_meta( $key, 'post' ) ) ) {
			continue;
		}
		$meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
	}

	return array(
		'post_id' => $post_id,
		'meta'    => $meta,
		'count'   => count( $meta ),
	);
}
