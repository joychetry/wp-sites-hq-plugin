<?php
/**
 * Update Post Metadata Ability - WordPress Abilities API
 *
 * Updates meta data for a post.
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
 * Register the update-post-metadata ability.
 *
 * Updates custom field values for posts.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_update_post_metadata_ability' );
function cm_register_update_post_metadata_ability() {
	wp_register_ability(
		'classic-monks/update-post-metadata',
		array(
			'label'               => __( 'Update Post Metadata', 'monks-central' ),
			'description'         => __(
				"Updates custom field (meta) values for a post. Use this to update SEO fields, custom settings, or any post metadata. \n\n## Required Parameters\n- **post_id**: The ID of the post (required)\n- **key**: The meta key to update (required)\n- **value**: The new value to set (required)\n\n## Security\n- Protected keys (starting with underscore) require manage_options capability\n- User must have edit capability for the specific post\n\n## Returns\nUpdated key, value, and confirmation.",
				'monks-central'
			),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'key', 'value' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The ID of the post (required).',
					),
					'key'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'The meta key to update (required).',
					),
					'value'   => array(
						'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object' ),
						'description' => 'The new value to set (required).',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'        => array( 'type' => 'integer' ),
					'key'            => array( 'type' => 'string' ),
					'value'          => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object' ) ),
					'previous_value' => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ) ),
					'success'        => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'cm_execute_update_post_metadata',
			'permission_callback' => function ( $input ) {
				if ( empty( $input['post_id'] ) ) {
					return false;
				}
				return current_user_can( 'edit_post', $input['post_id'] );
			},
			'meta'                => array(
				'annotations'      => array(
					'readonly'     => false,
					'destructive'  => true, // Overwrites the previous value
					'idempotent'   => true, // Same update yields same result
					'instructions' => 'Updates post meta/custom fields. Use get-post-meta to read values first.',
				),
				'cm_undo_callback' => 'cm_undo_update_post_metadata',
				'show_in_rest'     => true,
			),
		)
	);
}

/**
 * Execute the update-post-metadata ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Update result or error.
 */
function cm_execute_update_post_metadata( $input ) {
	$post_id = absint( $input['post_id'] );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'monks-central' ) );
	}

	// Meta keys are case-sensitive (e.g. Bricks' _bricks_page_content_2), so no sanitize_key().
	$meta_key = trim( (string) $input['key'] );

	// Protected (underscore) keys are editable only by admins; core's edit_post_meta denies them outright.
	if ( is_protected_meta( $meta_key, 'post' ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Cannot update protected meta keys without manage_options capability.', 'monks-central' )
			);
		}
	} elseif ( ! current_user_can( 'edit_post_meta', $post_id, $meta_key ) ) {
		return new WP_Error( 'forbidden', __( 'You are not allowed to edit this custom field.', 'monks-central' ) );
	}

	// Get previous value for reference
	$previous_value = get_post_meta( $post_id, $meta_key, true );

	// Keep structure intact (nested arrays, newlines); only strip disallowed HTML.
	$meta_value = $input['value'];
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$meta_value = map_deep(
			$meta_value,
			function ( $v ) {
				return is_string( $v ) ? wp_kses_post( $v ) : $v;
			}
		);
	}

	if ( class_exists( 'CM_AI_Activity_Log' ) ) {
		CM_AI_Activity_Log::set_undo(
			'classic-monks/update-post-metadata',
			array(
				'post_id' => $post_id,
				'key'     => $meta_key,
				'existed' => metadata_exists( 'post', $post_id, $meta_key ),
				'value'   => $previous_value,
			)
		);
	}

	// update_post_meta() unslashes its input.
	$result = update_post_meta( $post_id, $meta_key, wp_slash( $meta_value ) );

	// update_post_meta returns false if value unchanged (not an error)
	return array(
		'post_id'        => $post_id,
		'key'            => $meta_key,
		'value'          => $meta_value,
		'previous_value' => $previous_value,
		'success'        => true,
	);
}
/**
 * Restore a meta value from the AI Activity log.
 */
function cm_undo_update_post_metadata( $undo ) {
	if ( ! current_user_can( 'edit_post', $undo['post_id'] ) ) {
		return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'monks-central' ) );
	}
	if ( empty( $undo['existed'] ) ) {
		delete_post_meta( $undo['post_id'], $undo['key'] );
	} else {
		update_post_meta( $undo['post_id'], $undo['key'], wp_slash( $undo['value'] ) );
	}
	return true;
}
