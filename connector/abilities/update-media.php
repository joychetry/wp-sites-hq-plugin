<?php
/**
 * Update Media Ability - WordPress Abilities API
 *
 * Edits an attachment's details, as wp-admin's "Attachment details" does: alt text, title,
 * caption and description (WP Sites HQ).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_update_media_ability' );
function cm_register_update_media_ability() {
	wp_register_ability(
		'classic-monks/update-media',
		array(
			'label'               => __( 'Update Media', 'monks-central' ),
			'description'         => __(
				'Changes a Media Library item\'s alt text, title, caption or description. Send only the fields to change; an empty string clears one. The file itself is not touched (replace-media does that).',
				'monks-central'
			),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Attachment ID.',
					),
					'alt'         => array(
						'type'        => 'string',
						'description' => 'Alternative text (images).',
					),
					'title'       => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'alt'         => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_update_media',
			'permission_callback' => 'cm_can_edit_media',
			'meta'                => array(
				// WP Sites HQ only: the operator edits from the app window.
				'cm_operator_only'    => true,
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Send only the fields to change.',
				),
				'cm_undo_callback'    => 'cm_undo_update_media',
				// Undo is refused when the details were changed again since.
				'cm_preview_callback' => 'cm_preview_update_media',
				'show_in_rest'        => true,
			),
		)
	);
}

/**
 * upload_files plus edit_post on the attachment named by id.
 */
function cm_can_edit_media( $input ) {
	$id = absint( $input['id'] ?? 0 );
	return $id && 'attachment' === get_post_type( $id ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
}

/**
 * The details update-media edits, as it returns them.
 */
function cm_media_details( $id ) {
	$post = get_post( $id );
	return array(
		'id'          => (int) $id,
		'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'title'       => $post->post_title,
		'caption'     => $post->post_excerpt,
		'description' => $post->post_content,
	);
}

function cm_execute_update_media( $input ) {
	$id     = absint( $input['id'] );
	$before = cm_media_details( $id );
	$fields = array_intersect_key( $input, array_flip( array( 'alt', 'title', 'caption', 'description' ) ) );
	if ( ! $fields ) {
		return new WP_Error( 'missing_fields', __( 'Send at least one of alt, title, caption or description.', 'monks-central' ) );
	}
	$error = cm_apply_media_details( $id, $fields );
	if ( is_wp_error( $error ) ) {
		return $error;
	}
	if ( class_exists( 'CM_AI_Activity_Log' ) ) {
		CM_AI_Activity_Log::set_undo( 'classic-monks/update-media', array_intersect_key( $before, $fields + array( 'id' => 0 ) ) );
	}
	return cm_media_details( $id );
}

/**
 * Writes alt, title, caption and description; the post fields go through wp_update_post(), so kses applies.
 */
function cm_apply_media_details( $id, array $fields ) {
	$post = array( 'ID' => $id );
	foreach ( array(
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	) as $key => $column ) {
		if ( isset( $fields[ $key ] ) ) {
			$post[ $column ] = (string) $fields[ $key ];
		}
	}
	if ( count( $post ) > 1 ) {
		$result = wp_update_post( wp_slash( $post ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	if ( isset( $fields['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $fields['alt'] ) ) );
	}
	return true;
}

/**
 * The current values of the details a call names, for the undo drift check.
 */
function cm_preview_update_media( $input ) {
	$id = absint( $input['id'] ?? 0 );
	if ( 'attachment' !== get_post_type( $id ) ) {
		return array();
	}
	return array_intersect_key( cm_media_details( $id ), array_flip( array_intersect( array_keys( $input ), array( 'alt', 'title', 'caption', 'description' ) ) ) );
}

/**
 * Puts the details back as they were before the logged change.
 */
function cm_undo_update_media( $undo ) {
	$id = absint( $undo['id'] ?? 0 );
	if ( ! cm_can_edit_media( array( 'id' => $id ) ) ) {
		return new WP_Error( 'forbidden', __( 'You are not allowed to edit this media item.', 'monks-central' ) );
	}
	unset( $undo['id'] );
	return cm_apply_media_details( $id, $undo );
}
