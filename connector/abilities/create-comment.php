<?php
/**
 * Create Comment Ability - WordPress Abilities API
 *
 * Posts a new top-level comment on a post as the current user.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_create_comment_ability' );
function cm_register_create_comment_ability() {
	wp_register_ability(
		'classic-monks/create-comment',
		array(
			'label'               => __( 'Create Comment', 'monks-central' ),
			'description'         => __(
				"Posts a new top-level comment on a post as the current user. To answer an existing comment use reply-to-comment. The comment is approved immediately when the user can moderate comments, otherwise it is held for moderation. \n\n## Parameters\n- **post_id**: ID of the post to comment on (required)\n- **content**: Comment text; basic HTML allowed (required)\n\n## Returns\nThe new comment: comment_id, post_id, status, link.",
				'monks-central'
			),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'content' ),
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'ID of the post to comment on.',
					),
					'content' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Comment text.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'comment_id' => array( 'type' => 'integer' ),
					'post_id'    => array( 'type' => 'integer' ),
					'status'     => array( 'type' => 'string' ),
					'link'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_create_comment',
			'permission_callback' => function ( $input ) {
				// Same check as reply-to-comment (core's admin "Reply").
				$post_id = absint( $input['post_id'] ?? 0 );
				return $post_id && get_post( $post_id ) && current_user_can( 'edit_post', $post_id );
			},
			'meta'                => array(
				'annotations'      => array(
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false, // Each call posts a new comment
					'instructions' => 'Posts a comment as the current user. For answers to existing comments use reply-to-comment.',
				),
				'cm_undo_callback' => 'cm_undo_reply_to_comment', // Trashes the comment by ID; defined in reply-to-comment.php.
				'show_in_rest'     => true,
			),
		)
	);
}

function cm_execute_create_comment( $input ) {
	$post = get_post( absint( $input['post_id'] ) );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'monks-central' ) );
	}

	$content = trim( (string) $input['content'] );
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$content = wp_kses_data( $content );
	}
	if ( '' === $content ) {
		return new WP_Error( 'empty_content', __( 'Comment content is empty.', 'monks-central' ) );
	}

	$user = wp_get_current_user();

	// wp_insert_comment() expects slashed data.
	$comment_id = wp_insert_comment(
		wp_slash(
			array(
				'comment_post_ID'      => (int) $post->ID,
				'comment_content'      => $content,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_author_url'   => $user->user_url,
				'user_id'              => $user->ID,
				'comment_approved'     => current_user_can( 'moderate_comments' ) ? 1 : 0,
				'comment_type'         => 'comment',
			)
		)
	);

	if ( ! $comment_id ) {
		return new WP_Error( 'comment_failed', __( 'Could not save the comment.', 'monks-central' ) );
	}

	if ( class_exists( 'CM_AI_Activity_Log' ) ) {
		CM_AI_Activity_Log::set_undo( 'classic-monks/create-comment', array( 'comment_id' => (int) $comment_id ) );
	}

	return array(
		'comment_id' => (int) $comment_id,
		'post_id'    => (int) $post->ID,
		'status'     => (string) wp_get_comment_status( $comment_id ),
		'link'       => (string) get_comment_link( $comment_id ),
	);
}
