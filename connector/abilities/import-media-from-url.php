<?php
/**
 * Import Media From URL Ability - WordPress Abilities API
 *
 * Downloads a remote image into the Media Library. Works without Bricks; the
 * download and file checks are shared with import-external-image.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_import_media_from_url_ability' );
function cm_register_import_media_from_url_ability() {
	wp_register_ability(
		'classic-monks/import-media-from-url',
		array(
			'label'               => __( 'Import Media From URL', 'monks-central' ),
			'description'         => __(
				"Downloads an image from a public http(s) URL and adds it to the Media Library as a new attachment. Private, loopback and link-local addresses are refused, also after redirects. The file type is read from its content: only image types this site accepts are imported (SVG is sanitized), up to the site's maximum upload size. Each call creates a new attachment.\n\n## Parameters\n- **url**: Public http(s) image URL (required).\n- **title**: Attachment title. Defaults to the file name.\n- **alt**: Image alt text.\n- **caption**: Attachment caption.\n- **post_id**: Post to attach the image to (you must be able to edit it).\n\n## Returns\nattachment_id, url, mime_type, filename, original_url and edit_url.",
				'monks-central'
			),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'url' ),
				'properties'           => array(
					'url'     => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => 'Public http(s) image URL.',
					),
					'title'   => array(
						'type'        => 'string',
						'description' => 'Optional attachment title.',
					),
					'alt'     => array(
						'type'        => 'string',
						'description' => 'Optional image alt text.',
					),
					'caption' => array(
						'type'        => 'string',
						'description' => 'Optional attachment caption.',
					),
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Optional post to attach the image to.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'attachment_id' => array( 'type' => 'integer' ),
					'url'           => array( 'type' => 'string' ),
					'mime_type'     => array( 'type' => 'string' ),
					'filename'      => array( 'type' => 'string' ),
					'original_url'  => array( 'type' => 'string' ),
					'edit_url'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_import_media_from_url',
			'permission_callback' => function () {
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
					'instructions' => 'Each call creates another attachment; check get-media first if the image may already be imported.',
				),
				'show_in_rest' => true,
			),
		)
	);
}

function cm_execute_import_media_from_url( $input = array() ) {
	$url = esc_url_raw( (string) ( $input['url'] ?? '' ), array( 'http', 'https' ) );
	if ( '' === $url ) {
		return new WP_Error( 'invalid_url', __( 'A valid http(s) image URL is required.', 'monks-central' ) );
	}

	$post_id = absint( $input['post_id'] ?? 0 );
	if ( $post_id ) {
		$parent = get_post( $post_id );
		if ( ! $parent || 'attachment' === $parent->post_type ) {
			return new WP_Error( 'post_not_found', __( 'Post not found.', 'monks-central' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'monks-central' ) );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once CM_FUNCTIONS_DIR . 'monks-central/media-import-helpers.php';

	// Scheme, private/reserved IPs (every redirect hop), IP pinning and size cap.
	$tmp_file = cm_ai_safe_download( $url, 30 );
	if ( is_wp_error( $tmp_file ) ) {
		return $tmp_file;
	}

	// Type from the sniffed bytes, never the URL; non-images refused, SVG sanitized.
	$file_array = cm_ai_prepare_image_file( $tmp_file, wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'image' ) );
	if ( is_wp_error( $file_array ) ) {
		wp_delete_file( $tmp_file );
		return $file_array;
	}

	$post_data = array();
	if ( '' !== trim( (string) ( $input['title'] ?? '' ) ) ) {
		$post_data['post_title'] = sanitize_text_field( $input['title'] );
	}
	if ( '' !== trim( (string) ( $input['caption'] ?? '' ) ) ) {
		$post_data['post_excerpt'] = sanitize_textarea_field( $input['caption'] );
	}

	$attachment_id = media_handle_sideload( $file_array, $post_id, null, $post_data );
	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $tmp_file );
		return $attachment_id;
	}

	if ( '' !== trim( (string) ( $input['alt'] ?? '' ) ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
	}

	return array(
		'attachment_id' => (int) $attachment_id,
		'url'           => (string) wp_get_attachment_url( $attachment_id ),
		'mime_type'     => (string) get_post_mime_type( $attachment_id ),
		'filename'      => wp_basename( (string) get_attached_file( $attachment_id ) ),
		'original_url'  => $url,
		'edit_url'      => (string) get_edit_post_link( $attachment_id, 'raw' ),
	);
}
