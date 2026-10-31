<?php
/**
 * Moderate Comment Ability - WordPress Abilities API
 *
 * Approves, unapproves, spams, unspams, trashes or untrashes comments.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 2.0.0
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the moderate-comment ability.
 *
 * @since 2.0.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_moderate_comment_ability' );
function cm_register_moderate_comment_ability() {
	wp_register_ability(
		'classic-monks/moderate-comment',
		array(
			'label'               => __( 'Moderate Comments', 'wp-sites-hq' ),
			'description'         => __(
				"Changes the moderation status of one or more comments. Use this to approve held comments, mark spam, or move comments to the trash. \n\n## Parameters\n- **comment_ids**: Comment IDs to moderate (1-50, required)\n- **action**: approve, unapprove, spam, unspam, trash or untrash (required)\n\n## Returns\nPer comment: id, previous_status, status, success. Plus the number of comments updated.",
				'wp-sites-hq'
			),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'comment_ids', 'action' ),
				'properties'           => array(
					'comment_ids' => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems'    => 1,
						'maxItems'    => 50,
						'description' => 'Comment IDs to moderate (1-50).',
					),
					'action'      => array(
						'type'        => 'string',
						'enum'        => array( 'approve', 'unapprove', 'spam', 'unspam', 'trash', 'untrash' ),
						'description' => 'Moderation action.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'action'  => array( 'type' => 'string' ),
					'results' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'              => array( 'type' => 'integer' ),
								'previous_status' => array( 'type' => 'string' ),
								'status'          => array( 'type' => 'string' ),
								'success'         => array( 'type' => 'boolean' ),
							),
						),
					),
					'updated' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_moderate_comment',
			'permission_callback' => function ( $input ) {
				if ( ! current_user_can( 'moderate_comments' ) || empty( $input['comment_ids'] ) ) {
					return false;
				}
				foreach ( (array) $input['comment_ids'] as $id ) {
					if ( ! current_user_can( 'edit_comment', absint( $id ) ) ) {
						return false;
					}
				}
				return true;
			},
			'meta'                => array(
				'cm_preview_callback' => 'cm_preview_moderate_comment',
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true, // Spam and trash hide content from the site
					'idempotent'   => true,
					'instructions' => 'Moderate comments in bulk (max 50). Use get-comments with a status filter to find comment IDs first. Trash is recoverable with untrash.',
				),
				'cm_undo_callback'    => 'cm_undo_moderate_comment',
				'show_in_rest'        => true,
			),
		)
	);
}

/**
 * Execute the moderate-comment ability.
 *
 * @since 2.0.0
 * @param array $input Input parameters.
 * @return array|WP_Error Per-comment results or error.
 */
function cm_execute_moderate_comment( $input ) {
	$action = sanitize_key( $input['action'] );
	$ids    = array_slice( array_unique( array_map( 'absint', (array) $input['comment_ids'] ) ), 0, 50 );

	// With EMPTY_TRASH_DAYS at 0, wp_trash_comment() deletes permanently.
	if ( 'trash' === $action && ! EMPTY_TRASH_DAYS ) {
		return new WP_Error( 'trash_disabled', __( 'The trash is disabled on this site, so trashing would delete comments permanently.', 'wp-sites-hq' ) );
	}

	$handlers = array(
		'approve'   => function ( $id ) {
			return wp_set_comment_status( $id, 'approve' ); },
		'unapprove' => function ( $id ) {
			return wp_set_comment_status( $id, 'hold' ); },
		'spam'      => 'wp_spam_comment',
		'unspam'    => 'wp_unspam_comment',
		'trash'     => 'wp_trash_comment',
		'untrash'   => 'wp_untrash_comment',
	);
	if ( ! isset( $handlers[ $action ] ) ) {
		return new WP_Error( 'invalid_action', __( 'Unknown moderation action.', 'wp-sites-hq' ) );
	}

	$results = array();
	$undo    = array();
	foreach ( $ids as $id ) {
		$previous = $id ? wp_get_comment_status( $id ) : false;
		if ( false === $previous ) {
			$results[] = array(
				'id'              => $id,
				'previous_status' => '',
				'status'          => '',
				'success'         => false,
			);
			continue;
		}
		$undo[ $id ] = $previous;
	}

	if ( class_exists( 'CM_AI_Activity_Log' ) && $undo ) {
		CM_AI_Activity_Log::set_undo( 'classic-monks/moderate-comment', array( 'statuses' => $undo ) );
	}

	// Status each action leads to; unspam/untrash just leave spam/trash.
	$target  = array(
		'approve'   => 'approved',
		'unapprove' => 'unapproved',
		'spam'      => 'spam',
		'trash'     => 'trash',
		'unspam'    => 'spam',
		'untrash'   => 'trash',
	);
	$leaving = in_array( $action, array( 'unspam', 'untrash' ), true );
	$updated = 0;
	foreach ( $undo as $id => $previous ) {
		// Skip comments already there: core's untrash/unspam would otherwise send them to pending.
		$done = $leaving ? $previous !== $target[ $action ] : $previous === $target[ $action ];
		$ok   = $done || (bool) call_user_func( $handlers[ $action ], $id );
		if ( $ok && ! $done ) {
			++$updated;
		}
		$results[] = array(
			'id'              => (int) $id,
			'previous_status' => (string) $previous,
			'status'          => (string) wp_get_comment_status( $id ),
			'success'         => $ok,
		);
	}

	return array(
		'action'  => $action,
		'results' => $results,
		'updated' => $updated,
	);
}

/**
 * Current status of each targeted comment, for the approval screen.
 */
function cm_preview_moderate_comment( $input ) {
	$out = array();
	foreach ( array_slice( (array) ( $input['comment_ids'] ?? array() ), 0, 50 ) as $id ) {
		$out[] = array(
			'id'     => absint( $id ),
			'status' => (string) wp_get_comment_status( absint( $id ) ),
		);
	}
	return $out;
}

/**
 * Put each comment back to the status it had before the call.
 */
function cm_undo_moderate_comment( $undo ) {
	// wp_get_comment_status() names differ from wp_set_comment_status() inputs.
	$map = array(
		'approved'   => 'approve',
		'unapproved' => 'hold',
		'spam'       => 'spam',
		'trash'      => 'trash',
	);
	foreach ( (array) ( $undo['statuses'] ?? array() ) as $id => $status ) {
		if ( ! current_user_can( 'edit_comment', (int) $id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to moderate this comment.', 'wp-sites-hq' ) );
		}
		if ( isset( $map[ $status ] ) && wp_get_comment_status( (int) $id ) !== $status ) {
			wp_set_comment_status( (int) $id, $map[ $status ] );
		}
	}
	return true;
}
