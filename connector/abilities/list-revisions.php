<?php
/**
 * List Revisions and Restore Revision Abilities - WordPress Abilities API
 *
 * A post's saved revisions, and putting one back (Monks Central M6).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_revision_abilities' );
function cm_register_revision_abilities() {
	wp_register_ability(
		'classic-monks/list-revisions',
		array(
			'label'               => __( 'List Revisions', 'monks-central' ),
			'description'         => __( 'Lists the saved revisions of a post, newest first: id, date, author and title. Restore one with restore-revision.', 'monks-central' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array(
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'revisions' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'     => array( 'type' => 'integer' ),
								'date'   => array( 'type' => 'string' ),
								'author' => array( 'type' => 'string' ),
								'title'  => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => 'cm_execute_list_revisions',
			'permission_callback' => function ( $input ) {
				return ! empty( $input['post_id'] ) && current_user_can( 'edit_post', $input['post_id'] );
			},
			'meta'                => array(
				// Monks Central only: keeps AI clients under their tool caps.
				'cm_operator_only' => true,
				'annotations'      => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Revisions exist only for post types that support them.',
				),
				'show_in_rest'     => true,
			),
		)
	);

	wp_register_ability(
		'classic-monks/restore-revision',
		array(
			'label'               => __( 'Restore Revision', 'monks-central' ),
			'description'         => __( 'Puts a saved revision back as the post\'s title, content and excerpt. The current version is kept as a revision first, so this can be reversed.', 'monks-central' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'revision_id' ),
				'properties'           => array(
					'revision_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => array( 'type' => 'integer' ),
					'revision_id' => array( 'type' => 'integer' ),
					'message'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_restore_revision',
			'permission_callback' => function ( $input ) {
				$id       = absint( $input['revision_id'] ?? 0 );
				$revision = wp_get_post_revision( $id );
				return $revision && current_user_can( 'edit_post', $revision->post_parent );
			},
			'meta'                => array(
				// Monks Central only: keeps AI clients under their tool caps.
				'cm_operator_only'    => true,
				'cm_preview_callback' => 'cm_preview_revision_parent',
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true, // Replaces the current title and body.
					'idempotent'   => true,
					'instructions' => 'Confirm with the user first. The current version is saved as a revision, so restoring it again reverses this.',
				),
				'show_in_rest'        => true,
			),
		)
	);
}

function cm_execute_list_revisions( $input ) {
	$post = get_post( absint( $input['post_id'] ) );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'monks-central' ) );
	}
	$out = array();
	foreach ( wp_get_post_revisions( $post->ID ) as $r ) {
		$out[] = array(
			'id'     => $r->ID,
			'date'   => $r->post_modified,
			'author' => get_the_author_meta( 'display_name', $r->post_author ),
			'title'  => $r->post_title,
		);
	}
	return array( 'revisions' => $out );
}

function cm_execute_restore_revision( $input ) {
	$id       = absint( $input['revision_id'] );
	$revision = wp_get_post_revision( $id );
	if ( ! $revision ) {
		return new WP_Error( 'revision_not_found', __( 'Revision not found.', 'monks-central' ) );
	}
	// Save what is there now, so this restore can be reversed.
	wp_save_post_revision( $revision->post_parent );
	if ( ! wp_restore_post_revision( $revision->ID ) ) {
		return new WP_Error( 'restore_failed', __( 'The revision could not be restored.', 'monks-central' ) );
	}
	return array(
		'post_id'     => (int) $revision->post_parent,
		'revision_id' => (int) $revision->ID,
		'message'     => __( 'Revision restored. The version before it is saved as a revision.', 'monks-central' ),
	);
}

function cm_preview_revision_parent( $input ) {
	$id       = absint( $input['revision_id'] ?? 0 );
	$revision = wp_get_post_revision( $id );
	return $revision ? cm_preview_post_state( array( 'post_id' => $revision->post_parent ) ) : null;
}
