<?php
/**
 * Get Post Content Ability - WordPress Abilities API
 *
 * Retrieves full post content with formatting options.
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
 * Register the get-post-content ability.
 *
 * Returns post content in various formats.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_post_content_ability' );
function cm_register_get_post_content_ability() {
	wp_register_ability(
		'classic-monks/get-post-content',
		array(
			'label'               => __( 'Get Post Content', 'monks-central' ),
			'description'         => __(
				"Retrieves full post content with formatting options. Use this when you need the complete content of a post in a specific format. \n\n## Required Parameters\n- **post_id**: The ID of the post (required)\n\n## Optional Parameters\n- **format**: Content format - raw, rendered, or stripped (default: raw)\n\n## Format Options\n- **raw**: Content exactly as stored in database (Gutenberg blocks, shortcodes)\n- **rendered**: Content with filters applied (shortcodes executed, blocks rendered)\n- **stripped**: Plain text without any HTML tags\n\n## Returns\nPost content in requested format, plus word count and character count.",
				'monks-central'
			),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The ID of the post (required).',
					),
					'format'  => array(
						'type'        => 'string',
						'enum'        => array( 'raw', 'rendered', 'stripped' ),
						'default'     => 'raw',
						'description' => 'Content format: raw, rendered, or stripped.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'title'      => array( 'type' => 'string' ),
					'content'    => array( 'type' => 'string' ),
					'format'     => array( 'type' => 'string' ),
					'word_count' => array( 'type' => 'integer' ),
					'char_count' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_post_content',
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
					'instructions' => 'Get rendered content only. For full post data use get-post-by-id.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-post-content ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Post content or error.
 */
function cm_execute_get_post_content( $input ) {
	$post_id = absint( $input['post_id'] );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'monks-central' ) );
	}

	$format = isset( $input['format'] ) ? sanitize_key( $input['format'] ) : 'raw';

	switch ( $format ) {
		case 'rendered':
			// Apply the_content filters (shortcodes, blocks, etc.)
			$content = apply_filters( 'the_content', $post->post_content );
			break;
		case 'stripped':
			// Plain text without HTML
			$content = wp_strip_all_tags( $post->post_content );
			break;
		case 'raw':
		default:
			// Raw content from database
			$content = $post->post_content;
			break;
	}

	// Calculate counts from stripped content
	$stripped_content = wp_strip_all_tags( $post->post_content );

	return array(
		'post_id'    => $post_id,
		'title'      => $post->post_title,
		'content'    => $content,
		'format'     => $format,
		'word_count' => str_word_count( $stripped_content ),
		'char_count' => strlen( $stripped_content ),
	);
}
