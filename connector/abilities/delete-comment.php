<?php
/**
 * Delete Comment Ability - WordPress Abilities API
 *
 * Permanently deletes a comment that is already in the trash or marked as spam.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_delete_comment_ability' );
function cm_register_delete_comment_ability() {
	wp_register_ability(
		'classic-monks/delete-comment',
		array(
			'label'               => __( 'Delete Comment', 'monks-central' ),
			'description'         => __(
				"Permanently deletes one comment, like \"Delete Permanently\" on the Comments screen. Cannot be undone. Only comments in the trash or marked as spam can be deleted; move a comment there first with moderate-comment. Its replies move up to its parent.\n\n## Parameters\n- **comment_id**: ID of the comment (required)\n\n## Returns\ncomment_id, post_id, previous_status, deleted.",
				'monks-central'
			),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'comment_id' ),
				'properties'           => array(
					'comment_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'ID of the comment to delete.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'comment_id'      => array( 'type' => 'integer' ),
					'post_id'         => array( 'type' => 'integer' ),
					'previous_status' => array( 'type' => 'string' ),
					'deleted'         => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'cm_execute_delete_comment',
			'permission_callback' => function ( $input ) {
				return current_user_can( 'moderate_comments' ) && current_user_can( 'edit_comment', absint( $input['comment_id'] ?? 0 ) );
			},
			'meta'                => array(
				'cm_always_approve'   => true, // Can't be undone: trusted connections still ask.
				'cm_preview_callback' => 'cm_preview_delete_comment',
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => true,
					'instructions' => 'Permanent. Only for comments already in the trash or spam (see get-comments with a status filter); trash with moderate-comment first.',
				),
				'show_in_rest'        => true,
			),
		)
	);
}

function cm_execute_delete_comment( $input ) {
	$comment = get_comment( absint( $input['comment_id'] ) );
	if ( ! $comment ) {
		return new WP_Error( 'comment_not_found', __( 'Comment not found.', 'monks-central' ) );
	}
	$status = (string) wp_get_comment_status( $comment );
	if ( ! in_array( $status, array( 'trash', 'spam' ), true ) ) {
		return new WP_Error( 'comment_not_trashed', __( 'Only comments in the trash or marked as spam can be deleted permanently. Trash it with moderate-comment first.', 'monks-central' ) );
	}
	if ( ! wp_delete_comment( $comment, true ) ) {
		return new WP_Error( 'delete_failed', __( 'Could not delete the comment.', 'monks-central' ) );
	}

	return array(
		'comment_id'      => (int) $comment->comment_ID,
		'post_id'         => (int) $comment->comment_post_ID,
		'previous_status' => $status,
		'deleted'         => true,
	);
}

/**
 * The comment as it is now, for the approval screen.
 */
function cm_preview_delete_comment( $input ) {
	$comment = get_comment( absint( $input['comment_id'] ?? 0 ) );
	if ( ! $comment ) {
		return null;
	}
	return array(
		'comment_id' => (int) $comment->comment_ID,
		'post_id'    => (int) $comment->comment_post_ID,
		'author'     => (string) $comment->comment_author,
		'status'     => (string) wp_get_comment_status( $comment ),
		'excerpt'    => wp_html_excerpt( (string) $comment->comment_content, 200, '…' ),
	);
}
