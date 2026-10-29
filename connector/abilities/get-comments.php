<?php
/**
 * Get Comments Ability - WordPress Abilities API
 *
 * Retrieves comments with filtering options.
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
 * Register the get-comments ability.
 *
 * Returns comments with filtering and pagination.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_comments_ability' );
function cm_register_get_comments_ability() {
	wp_register_ability(
		'classic-monks/get-comments',
		array(
			'label'               => __( 'Get Comments', 'monks-central' ),
			'description'         => __(
				"Retrieves comments with filtering options. Use this for comment moderation, engagement analysis, or content review. \n\n## Optional Parameters\n- **post_id**: Get comments for a specific post\n- **status**: Comment status (approve, hold, spam, trash, all). Default: approve\n- **limit**: Number of comments (1-100, default 10)\n- **orderby**: Order by comment_date, comment_author (default comment_date)\n- **order**: ASC or DESC (default DESC)\n\n## Returns\nArray of comments with: id, post_id, post_title, author, content, date, status, parent.",
				'monks-central'
			),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'Get comments for specific post.',
					),
					'status'  => array(
						'type'        => 'string',
						'enum'        => array( 'approve', 'hold', 'spam', 'trash', 'all' ),
						'default'     => 'approve',
						'description' => 'Comment status filter.',
					),
					'limit'   => array(
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of comments (1-100).',
					),
					'orderby' => array(
						'type'        => 'string',
						'enum'        => array( 'comment_date', 'comment_author' ),
						'default'     => 'comment_date',
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
					'comments' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'           => array( 'type' => 'integer' ),
								'post_id'      => array( 'type' => 'integer' ),
								'post_title'   => array( 'type' => 'string' ),
								'author'       => array( 'type' => 'string' ),
								'author_email' => array(
									'type'        => 'string',
									'description' => 'Empty for comments left without one (WP-CLI, pingbacks, imports).',
								),
								'author_url'   => array(
									'type'   => 'string',
									'format' => 'uri',
								),
								'content'      => array( 'type' => 'string' ),
								'date'         => array(
									'type'   => 'string',
									'format' => 'date-time',
								),
								'status'       => array( 'type' => 'string' ),
								'parent'       => array( 'type' => 'integer' ),
								'user_id'      => array( 'type' => 'integer' ),
							),
						),
					),
					'total'    => array( 'type' => 'integer' ),
					'returned' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_comments',
			'permission_callback' => function () {
				return current_user_can( 'moderate_comments' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Retrieve comments with filtering. Returns approved comments by default. Use status parameter for moderation.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

/**
 * Execute the get-comments ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array Comments with metadata.
 */
function cm_execute_get_comments( $input = array() ) {
	$args = array(
		'number'  => min( absint( isset( $input['limit'] ) ? $input['limit'] : 10 ), 100 ),
		'status'  => sanitize_key( isset( $input['status'] ) ? $input['status'] : 'approve' ),
		'orderby' => sanitize_key( isset( $input['orderby'] ) ? $input['orderby'] : 'comment_date' ),
		'order'   => ( isset( $input['order'] ) && 'ASC' === strtoupper( $input['order'] ) ) ? 'ASC' : 'DESC',
	);

	if ( ! empty( $input['post_id'] ) ) {
		$args['post_id'] = absint( $input['post_id'] );
	}

	$comments = get_comments( $args );
	$result   = array();

	foreach ( $comments as $comment ) {
		$result[] = array(
			'id'           => (int) $comment->comment_ID,
			'post_id'      => (int) $comment->comment_post_ID,
			'post_title'   => get_the_title( (int) $comment->comment_post_ID ),
			'author'       => $comment->comment_author,
			'author_email' => $comment->comment_author_email,
			'author_url'   => $comment->comment_author_url,
			'content'      => $comment->comment_content,
			'date'         => $comment->comment_date,
			'status'       => wp_get_comment_status( $comment ),
			'parent'       => (int) $comment->comment_parent,
			'user_id'      => (int) $comment->user_id,
		);
	}

	// Get total count
	$count_args          = $args;
	$count_args['count'] = true;
	unset( $count_args['number'] );
	$total = get_comments( $count_args );

	return array(
		'comments' => $result,
		'total'    => (int) $total,
		'returned' => count( $result ),
	);
}
