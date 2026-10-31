<?php
/**
 * Update Post Ability - WordPress Abilities API
 *
 * Updates existing WordPress posts, pages, or custom post types.
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

require_once __DIR__ . '/content-write-helpers.php';

/**
 * Register the update-post ability.
 *
 * Updates existing content in WordPress.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_update_post_ability' );
function cm_register_update_post_ability() {
	wp_register_ability(
		'classic-monks/update-post',
		array(
			'label'               => __( 'Update Post', 'wp-sites-hq' ),
			'description'         => __(
				"Updates an existing WordPress post, page, or custom post type. Only the fields provided will be updated; others remain unchanged. \n\n## Required Parameters\n- **post_id**: The ID of the post to update (required)\n\n## Optional Parameters (provide at least one)\n- **title**: New post title\n- **content**: New post body content (HTML allowed)\n- **excerpt**: New post excerpt\n- **status**: New post status (draft, publish, pending, private)\n- **categories**: New category IDs (replaces existing, post type only)\n- **tags**: New tag names (replaces existing)\n- **terms**: Terms for any taxonomy, by ID or name (replaces each taxonomy given)\n- **meta**: Custom fields (protected keys refused)\n- **date**: Publish date, \"YYYY-MM-DD HH:MM:SS\" (a future date schedules it)\n- **slug**, **author**, **parent**, **menu_order**, **template**, **password**: Permalink, author ID, page attributes and password protection\n- **featured_image**: Attachment ID, or 0 to remove it\n\nPages built with Elementor or Bricks keep their body in the builder: content is refused there.\n\n## Returns\nUpdated post ID, title, permalink, and edit link.",
				'wp-sites-hq'
			),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The ID of the post to update (required).',
					),
					'title'          => array(
						'type'        => 'string',
						'description' => 'New post title.',
					),
					'content'        => array(
						'type'        => 'string',
						'description' => 'New post body content. HTML is allowed and sanitized.',
					),
					'excerpt'        => array(
						'type'        => 'string',
						'description' => 'New post excerpt.',
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'publish', 'pending', 'private', 'future' ),
						'description' => 'New post status.',
					),
					'categories'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Array of category IDs (replaces existing). Only for "post" type.',
					),
					'tags'           => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Array of tag names (replaces existing).',
					),
					'featured_image' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Attachment ID to use as featured image; 0 removes it.',
					),
				) + cm_content_write_schema(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'        => array(
						'type'        => 'integer',
						'description' => 'Updated post ID.',
					),
					'title'          => array(
						'type'        => 'string',
						'description' => 'Post title after update.',
					),
					'status'         => array(
						'type'        => 'string',
						'description' => 'Post status after update.',
					),
					'permalink'      => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'Full URL to the post.',
					),
					'edit_link'      => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'Admin URL to edit the post.',
					),
					'updated_fields' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'List of fields that were updated.',
					),
				),
			),
			'execute_callback'    => 'cm_execute_update_post',
			'permission_callback' => function ( $input ) {
				if ( empty( $input['post_id'] ) ) {
					return false;
				}
				$post = get_post( $input['post_id'] );
				$type = $post ? get_post_type_object( $post->post_type ) : null;
				// An unregistered type (its plugin deactivated) has no publish capability to check: refuse.
				if ( $post && in_array( $input['status'] ?? '', array( 'publish', 'private', 'future' ), true ) && ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) ) {
					return false;
				}
				return current_user_can( 'edit_post', $input['post_id'] );
			},
			'meta'                => array(
				'cm_preview_callback' => 'cm_preview_post_state',
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true, // Updates don't destroy, they modify
					'idempotent'   => true,  // Same updates yield same result
					'instructions' => 'Modifies existing post. Only specified fields are updated. Use create-post for new content.',
				),
				'show_in_rest'        => true,
			),
		)
	);
}

/**
 * Execute the update-post ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Updated post info or error.
 */
function cm_execute_update_post( $input ) {
	$post_id = absint( $input['post_id'] );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'wp-sites-hq' ) );
	}

	$checked = cm_content_write_check( $post->post_type, $post_id, $input );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$builder = isset( $input['content'] ) ? cm_content_builder( $post_id ) : null;
	if ( $builder ) {
		return new WP_Error(
			'builder_page',
			/* translators: %1$s: Elementor or Bricks */
			sprintf( __( 'This page is built with %1$s, which keeps the body in its own data. Edit the body in %1$s; title, status and terms can still change here.', 'wp-sites-hq' ), ucfirst( $builder ) )
		);
	}
	if ( isset( $input['featured_image'] ) && $input['featured_image'] && ! wp_attachment_is_image( absint( $input['featured_image'] ) ) ) {
		return new WP_Error( 'invalid_featured_image', __( 'The featured image must be an image in the media library.', 'wp-sites-hq' ) );
	}

	$post_data      = array( 'ID' => $post_id );
	$updated_fields = array();

	// Title
	if ( isset( $input['title'] ) ) {
		$post_data['post_title'] = sanitize_text_field( $input['title'] );
		$updated_fields[]        = 'title';
	}

	// Content
	if ( isset( $input['content'] ) ) {
		$post_data['post_content'] = $input['content'];
		$updated_fields[]          = 'content';
	}

	// Excerpt
	if ( isset( $input['excerpt'] ) ) {
		$post_data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
		$updated_fields[]          = 'excerpt';
	}

	// Status
	if ( isset( $input['status'] ) ) {
		$post_data['post_status'] = sanitize_key( $input['status'] );
		$updated_fields[]         = 'status';
	}

	if ( isset( $input['date'] ) ) {
		$post_data['post_date']     = $input['date'];
		$post_data['post_date_gmt'] = get_gmt_from_date( $input['date'] );
		$post_data['edit_date']     = true;
		$updated_fields[]           = 'date';
	}

	$more = cm_content_post_fields( $post->post_type, $post_id, $input, $post_data );
	if ( is_wp_error( $more ) ) {
		return $more;
	}
	$updated_fields = array_merge( $updated_fields, $more );

	// Only update if there are fields to update
	if ( count( $updated_fields ) === 0 && empty( $input['categories'] ) && empty( $input['tags'] ) && empty( $input['terms'] ) && empty( $input['meta'] ) && ! isset( $input['featured_image'] ) ) {
		return new WP_Error( 'no_fields', __( 'No fields provided to update.', 'wp-sites-hq' ) );
	}

	// Update the post
	if ( count( $post_data ) > 1 ) { // More than just ID
		// wp_update_post() unslashes, and runs kses for users without unfiltered_html.
		$result = wp_update_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	// Categories (only for 'post' type)
	if ( isset( $input['categories'] ) && 'post' === $post->post_type ) {
		wp_set_post_categories( $post_id, array_map( 'absint', (array) $input['categories'] ) );
		$updated_fields[] = 'categories';
	}

	// Tags
	if ( isset( $input['tags'] ) ) {
		wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', (array) $input['tags'] ) );
		$updated_fields[] = 'tags';
	}

	$written = cm_content_write_after( $post_id, $input );
	if ( is_wp_error( $written ) ) {
		return $written;
	}
	$updated_fields = array_merge( $updated_fields, $written );

	if ( isset( $input['featured_image'] ) ) {
		if ( $input['featured_image'] ) {
			set_post_thumbnail( $post_id, absint( $input['featured_image'] ) );
		} else {
			delete_post_thumbnail( $post_id );
		}
		$updated_fields[] = 'featured_image';
	}

	// Get updated post
	$updated_post = get_post( $post_id );

	return array(
		'post_id'        => $post_id,
		'title'          => $updated_post->post_title,
		'status'         => $updated_post->post_status,
		'permalink'      => get_permalink( $post_id ),
		'edit_link'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		'updated_fields' => $updated_fields,
	);
}
