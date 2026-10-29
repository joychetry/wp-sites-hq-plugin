<?php
/**
 * Get Posts Ability - WordPress Abilities API
 *
 * Retrieves a list of posts with filtering and sorting options.
 * Enhanced with orderby, order, category parameters, and comprehensive AI guidance.
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
 * Register the get-posts ability.
 *
 * This ability retrieves WordPress posts with extensive filtering options.
 * It's designed for AI agents operating in dashboard context, so default
 * status includes both published and draft posts.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_posts_ability' );
function cm_register_get_posts_ability() {
	wp_register_ability(
		'classic-monks/get-posts',
		array(
			'label'               => __( 'Get Posts', 'monks-central' ),
			'description'         => __(
				"Retrieves a list of posts with filtering and sorting options. Use this ability to fetch posts from the WordPress database with various filters. \n\n## Default Behavior\n- Returns both published AND draft posts (dashboard context)\n- Only returns posts the current user can read (others' drafts and private posts are left out)\n- Ordered by date descending (newest first)\n- Default post type is \"post\"\n- Maximum 100 posts per request (default 10)\n\n## Available Parameters\n- **post_type**: post, page, or any registered custom post type slug\n- **status**: publish, draft, pending, private, future, trash (comma-separated for multiple)\n- **category**: Category slug to filter by (only for \"post\" post type)\n- **orderby**: date, title, modified, rand\n- **order**: ASC (oldest/A-Z first) or DESC (newest/Z-A first)\n- **limit**: Number of posts (1-100)\n- **offset**: Skip this many posts (for pagination)\n- **search**: Search term to filter posts by title or content\n\n## Returns\nArray of post objects containing: id, title, slug, status, type, date, modified, author, excerpt, permalink. Also includes total found posts count for pagination.",
				'monks-central'
			),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'default'     => 'post',
						'description' => 'Post type slug: post, page, or any registered custom post type (e.g., product, portfolio).',
					),
					'status'    => array(
						'type'        => 'string',
						'default'     => 'publish,draft',
						'description' => 'Post status filter. Comma-separated for multiple values: publish,draft,pending,private,future,trash.',
					),
					'category'  => array(
						'type'        => 'string',
						'description' => 'Category slug to filter by. Only applies to "post" post type.',
					),
					'orderby'   => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'title', 'modified', 'rand' ),
						'default'     => 'date',
						'description' => 'Field to order results by: date (published), title (alphabetical), modified (last updated), rand (random).',
					),
					'order'     => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'default'     => 'DESC',
						'description' => 'Sort direction: ASC (oldest first, A-Z) or DESC (newest first, Z-A).',
					),
					'limit'     => array(
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of posts to return (1-100).',
					),
					'offset'    => array(
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Number of posts to skip (for pagination).',
					),
					'search'    => array(
						'type'        => 'string',
						'description' => 'Search term to filter posts by title or content.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'posts' => array(
						'type'        => 'array',
						'description' => 'Array of post objects matching the query.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'        => array(
									'type'        => 'integer',
									'description' => 'Post ID.',
								),
								'title'     => array(
									'type'        => 'string',
									'description' => 'Post title.',
								),
								'slug'      => array(
									'type'        => 'string',
									'description' => 'Post slug (URL-friendly name).',
								),
								'status'    => array(
									'type'        => 'string',
									'description' => 'Post status: publish, draft, pending, etc.',
								),
								'type'      => array(
									'type'        => 'string',
									'description' => 'Post type: post, page, or custom post type.',
								),
								'date'      => array(
									'type'        => 'string',
									'format'      => 'date-time',
									'description' => 'Publication date (YYYY-MM-DD HH:MM:SS).',
								),
								'modified'  => array(
									'type'        => 'string',
									'format'      => 'date-time',
									'description' => 'Last modified date (YYYY-MM-DD HH:MM:SS).',
								),
								'author'    => array(
									'type'        => 'string',
									'description' => 'Author display name.',
								),
								'excerpt'   => array(
									'type'        => 'string',
									'description' => 'Truncated excerpt of post content (30 words).',
								),
								'permalink' => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => 'Full URL to the post.',
								),
							),
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => 'Total number of posts matching the query (for pagination).',
					),
				),
			),
			'execute_callback'    => 'cm_execute_get_posts',
			'permission_callback' => function ( $input = null ) {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Use this to list or search posts. For full content of a specific post, use get-post-by-id. Returns summarized data with pagination support.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-posts ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters from the ability call.
 * @return array|WP_Error Array of posts and total count, or WP_Error on failure.
 */
function cm_execute_get_posts( $input = array() ) {
	// Parse status - handle comma-separated values
	$status = isset( $input['status'] ) ? $input['status'] : 'publish,draft';
	if ( is_string( $status ) && strpos( $status, ',' ) !== false ) {
		$status = array_map( 'trim', explode( ',', $status ) );
	}

	// Build query arguments
	$args = array(
		'post_type'      => sanitize_key( isset( $input['post_type'] ) ? $input['post_type'] : 'post' ),
		'post_status'    => $status,
		'posts_per_page' => min( absint( isset( $input['limit'] ) ? $input['limit'] : 10 ), 100 ),
		'offset'         => absint( isset( $input['offset'] ) ? $input['offset'] : 0 ),
		'orderby'        => sanitize_key( isset( $input['orderby'] ) ? $input['orderby'] : 'date' ),
		'order'          => ( isset( $input['order'] ) && 'ASC' === strtoupper( $input['order'] ) ) ? 'ASC' : 'DESC',
	);

	if ( ! cm_ai_can_list_post_type( $args['post_type'] ) ) {
		return new WP_Error( 'invalid_post_type', __( 'Unknown post type, or you are not allowed to list it.', 'monks-central' ) );
	}
	// Like core REST: private posts only if readable here, other non-public statuses filtered per post below.
	$args['perm'] = 'readable';

	// Category filter (only for posts)
	if ( ! empty( $input['category'] ) && 'post' === $args['post_type'] ) {
		$args['category_name'] = sanitize_text_field( $input['category'] );
	}

	// Search filter
	if ( ! empty( $input['search'] ) ) {
		$args['s'] = sanitize_text_field( $input['search'] );
	}

	// Execute query
	$query = new WP_Query( $args );

	// Build results array
	$posts = array();
	foreach ( $query->posts as $post ) {
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			continue;
		}
		$author = get_userdata( $post->post_author );

		$posts[] = array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'status'    => $post->post_status,
			'type'      => $post->post_type,
			'date'      => get_the_date( 'Y-m-d H:i:s', $post ),
			'modified'  => get_the_modified_date( 'Y-m-d H:i:s', $post ),
			'author'    => $author ? $author->display_name : '',
			'excerpt'   => cm_ai_post_text_visible( $post ) ? wp_trim_words( $post->post_content, 30, '...' ) : '',
			'permalink' => get_permalink( $post ),
		);
	}

	return array(
		'posts' => $posts,
		'total' => (int) $query->found_posts,
	);
}

/**
 * Whether the current user may list posts of a type: public or REST-exposed types, or
 * admin-UI types they can edit. Keeps internal types (user_request, oembed_cache) out.
 * Also used by search-content.php.
 *
 * @param string $post_type Post type slug.
 * @return bool
 */
function cm_ai_can_list_post_type( $post_type ) {
	$obj = get_post_type_object( $post_type );
	return $obj && ( $obj->public || $obj->show_in_rest || ( $obj->show_ui && current_user_can( $obj->cap->edit_posts ) ) );
}

/**
 * Whether a readable post's text may be shown: password-protected posts only to
 * users who can edit them, as core REST does. Also used by search-content.php and
 * create-post-summaries.php.
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function cm_ai_post_text_visible( $post ) {
	return '' === $post->post_password || current_user_can( 'edit_post', $post->ID );
}
