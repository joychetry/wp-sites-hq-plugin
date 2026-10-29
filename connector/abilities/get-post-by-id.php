<?php
/**
 * Get Post By ID Ability - WordPress Abilities API
 *
 * Retrieves a single post with full details by its ID.
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
 * Register the get-post-by-id ability.
 *
 * Returns comprehensive details for a single post.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_post_by_id_ability' );
function cm_register_get_post_by_id_ability() {
	wp_register_ability(
		'classic-monks/get-post-by-id',
		array(
			'label'               => __( 'Get Post by ID', 'monks-central' ),
			'description'         => __(
				"Retrieves a single post by its ID with full details. Use this to get complete information about a specific post including content, metadata, taxonomies, and featured image. \n\n## Required Parameters\n- **post_id**: The ID of the post to retrieve (required)\n\n## Returns\n- **Basic Info**: id, title, slug, status, type, permalink, edit_link\n- **Content**: Full content, excerpt\n- **Dates**: Created date, modified date (with GMT variants)\n- **Author**: Author name and ID\n- **Taxonomies**: Categories and tags (for post type)\n- **Settings**: Comment status, ping status, menu order, parent\n- **Media**: Featured image URL",
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
						'description' => 'The ID of the post to retrieve (required).',
					),
					'choices' => array(
						'type'        => 'boolean',
						'description' => 'Also list what an editor can pick: authors, parents and page templates.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'                => array( 'type' => 'integer' ),
					'title'             => array( 'type' => 'string' ),
					'slug'              => array( 'type' => 'string' ),
					'content'           => array( 'type' => 'string' ),
					'excerpt'           => array( 'type' => 'string' ),
					'status'            => array( 'type' => 'string' ),
					'type'              => array( 'type' => 'string' ),
					'date'              => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'date_gmt'          => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'modified'          => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'modified_gmt'      => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'author'            => array( 'type' => 'string' ),
					'author_id'         => array( 'type' => 'integer' ),
					'permalink'         => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'edit_link'         => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'parent'            => array( 'type' => 'integer' ),
					'menu_order'        => array( 'type' => 'integer' ),
					'comment_status'    => array( 'type' => 'string' ),
					'ping_status'       => array( 'type' => 'string' ),
					'featured_image'    => array(
						'type'     => array( 'string', 'null' ),
						'format'   => 'uri',
						'nullable' => true,
					),
					'featured_image_id' => array(
						'type'        => 'integer',
						'description' => 'Attachment ID of the featured image, 0 if none.',
					),
					'terms'             => array(
						'type'        => 'object',
						'description' => 'Every term on the post, by taxonomy: {"category": [{"id", "name", "slug"}]}.',
					),
					'password'          => array(
						'type'        => 'string',
						'description' => 'The password visitors type to read it; empty when public.',
					),
					'template'          => array(
						'type'        => 'string',
						'description' => 'Page template file; empty for the default.',
					),
					'preview_link'      => array(
						'type'        => 'string',
						'description' => 'Where a logged-in editor sees it as it is now, drafts included.',
					),
					'choices'           => array(
						'type'        => 'object',
						'description' => 'With choices: authors [{id, name}] (only when you may change the author), parents [{id, title}] and templates {file: name}.',
					),
					'builder'           => array(
						'type'        => array( 'string', 'null' ),
						'enum'        => array( 'elementor', 'bricks', null ),
						'description' => 'The page builder that renders the body, if any. Its body is not post content.',
					),
					'categories'        => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'   => array( 'type' => 'integer' ),
								'name' => array( 'type' => 'string' ),
								'slug' => array( 'type' => 'string' ),
							),
						),
					),
					'tags'              => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'   => array( 'type' => 'integer' ),
								'name' => array( 'type' => 'string' ),
								'slug' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => 'cm_execute_get_post_by_id',
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
					'instructions' => 'Get full details of a single post by ID. Returns complete content, meta, and taxonomies.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-post-by-id ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Post details or error.
 */
function cm_execute_get_post_by_id( $input ) {
	$post_id = absint( $input['post_id'] );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'monks-central' ) );
	}

	$result = array(
		'id'                => $post->ID,
		'title'             => $post->post_title,
		'slug'              => $post->post_name,
		'content'           => $post->post_content,
		'excerpt'           => $post->post_excerpt,
		'status'            => $post->post_status,
		'type'              => $post->post_type,
		'date'              => $post->post_date,
		'date_gmt'          => $post->post_date_gmt,
		'modified'          => $post->post_modified,
		'modified_gmt'      => $post->post_modified_gmt,
		'author'            => get_the_author_meta( 'display_name', $post->post_author ),
		'author_id'         => (int) $post->post_author,
		'permalink'         => get_permalink( $post->ID ),
		'edit_link'         => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
		'parent'            => (int) $post->post_parent,
		'menu_order'        => (int) $post->menu_order,
		'comment_status'    => $post->comment_status,
		'ping_status'       => $post->ping_status,
		'featured_image'    => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: null,
		'featured_image_id' => (int) get_post_thumbnail_id( $post->ID ),
		'terms'             => cm_content_post_terms( $post ),
		'builder'           => cm_content_builder( $post->ID ),
		'password'          => $post->post_password,
		'template'          => (string) get_page_template_slug( $post ),
		'preview_link'      => (string) get_preview_post_link( $post ),
	);
	if ( ! empty( $input['choices'] ) ) {
		$result['choices'] = cm_content_choices( $post );
	}

	// Get categories for posts
	if ( 'post' === $post->post_type ) {
		$categories           = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
		$result['categories'] = array_map(
			function ( $cat ) {
				return array(
					'id'   => $cat->term_id,
					'name' => $cat->name,
					'slug' => $cat->slug,
				);
			},
			$categories
		);

		$tags           = wp_get_post_tags( $post->ID, array( 'fields' => 'all' ) );
		$result['tags'] = array_map(
			function ( $tag ) {
				return array(
					'id'   => $tag->term_id,
					'name' => $tag->name,
					'slug' => $tag->slug,
				);
			},
			$tags
		);
	} else {
		$result['categories'] = array();
		$result['tags']       = array();
	}

	return $result;
}
