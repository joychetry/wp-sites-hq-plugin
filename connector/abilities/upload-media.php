<?php
/**
 * Upload Media Ability - WordPress Abilities API
 *
 * Adds a file from the caller's computer to the Media Library, sent in base64
 * chunks so large files fit in MCP requests (Monks Central M6).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Decoded bytes per chunk; keeps each request well under common post_max_size limits.
const CM_UPLOAD_CHUNK_MAX = 2 * MB_IN_BYTES;

add_action( 'wp_abilities_api_init', 'cm_register_upload_media_ability' );
function cm_register_upload_media_ability() {
	wp_register_ability(
		'classic-monks/upload-media',
		array(
			'label'               => __( 'Upload Media', 'monks-central' ),
			'description'         => __(
				'Uploads a file (an image, PDF or any type the site allows) to the Media Library in base64 chunks of up to 2 MB (decoded). First call: filename and data (no upload_id); the reply gives an upload_id. Next calls: upload_id, offset (the bytes received so far, from the last reply) and data. Set done: true on the last chunk to add the file. A chunk sent twice with the same offset is ignored, so a retry is safe. Images are typed by their content and SVG is sanitized; other files must match their extension. get-media lists the allowed types.',
				'monks-central'
			),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'data' ),
				'properties'           => array(
					'filename'  => array(
						'type'        => 'string',
						'description' => 'File name, on the first call.',
					),
					'data'      => array(
						'type'        => 'string',
						'description' => 'This chunk, base64.',
					),
					'upload_id' => array(
						'type'        => 'string',
						'description' => 'From the first reply, on every later call.',
					),
					'offset'    => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Bytes received before this chunk.',
					),
					'done'      => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'This is the last chunk.',
					),
					'title'     => array( 'type' => 'string' ),
					'alt'       => array( 'type' => 'string' ),
					'post_id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Attach to this post.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'upload_id'     => array( 'type' => 'string' ),
					'received'      => array( 'type' => 'integer' ),
					'attachment_id' => array( 'type' => 'integer' ),
					'url'           => array( 'type' => 'string' ),
					'mime_type'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'cm_execute_upload_media',
			'permission_callback' => function ( $input ) {
				if ( ! empty( $input['post_id'] ) && ! current_user_can( 'edit_post', $input['post_id'] ) ) {
					return false;
				}
				return current_user_can( 'upload_files' );
			},
			'meta'                => array(
				// Monks Central only: keeps AI clients under their tool caps.
				'cm_operator_only' => true,
				'annotations'      => array(
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
					'instructions' => 'Send chunks in order. An unfinished upload is dropped after an hour.',
				),
				'show_in_rest'     => true,
			),
		)
	);
}

/**
 * Receives one base64 chunk of a chunked upload, the protocol upload-media and install-zip share.
 * $set is merged into the upload's state on each call. The state lives an hour after each chunk; on the
 * last chunk (done) it is dropped and the caller owns the file. Returns id, state and size, or a WP_Error.
 */
function cm_ai_receive_chunk( $input, $prefix, $set = array() ) {
	$chunk = base64_decode( (string) $input['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- chunks arrive base64 encoded over JSON
	if ( false === $chunk ) {
		return new WP_Error( 'invalid_data', __( 'data is not valid base64.', 'monks-central' ) );
	}
	if ( strlen( $chunk ) > CM_UPLOAD_CHUNK_MAX ) {
		return new WP_Error( 'chunk_too_large', __( 'Each chunk may hold at most 2 MB.', 'monks-central' ) );
	}

	if ( empty( $input['upload_id'] ) ) {
		$name = sanitize_file_name( (string) ( $input['filename'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'missing_filename', __( 'filename is required on the first call.', 'monks-central' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$id    = wp_generate_password( 20, false );
		$state = array(
			'user' => get_current_user_id(),
			'path' => wp_tempnam( $name ),
			'name' => $name,
		);
		file_put_contents( $state['path'], '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appends a chunk to a temp file; WP_Filesystem cannot append
		// Deletes the file if the upload is abandoned.
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cm_ai_chunk_expire', array( $prefix . $id, $state['path'] ) );
	} else {
		$id    = preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['upload_id'] );
		$state = get_transient( $prefix . $id );
		if ( ! is_array( $state ) || (int) $state['user'] !== get_current_user_id() || ! is_file( $state['path'] ) ) {
			return new WP_Error( 'upload_not_found', __( 'This upload is unknown or expired. Start again without upload_id.', 'monks-central' ) );
		}
	}

	// One chunk at a time per upload: a retry racing its original must not append the bytes twice.
	$lock = fopen( $state['path'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a lock on our own temp file.
	if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
		return new WP_Error( 'upload_busy', __( 'This upload is busy. Send the chunk again.', 'monks-central' ) );
	}
	try {
		clearstatcache( true, $state['path'] );
		$size   = (int) filesize( $state['path'] );
		$offset = (int) ( $input['offset'] ?? 0 );
		if ( $offset + strlen( $chunk ) === $size && $offset < $size ) {
			$chunk = ''; // This chunk arrived already: a retry.
		} elseif ( $offset !== $size ) {
			/* translators: %d: bytes received */
			return new WP_Error( 'wrong_offset', sprintf( __( 'The site has %d bytes of this upload; send the chunk at that offset.', 'monks-central' ), $size ) );
		}
		if ( $size + strlen( $chunk ) > wp_max_upload_size() ) {
			wp_delete_file( $state['path'] );
			delete_transient( $prefix . $id );
			return new WP_Error( 'file_too_large', __( 'The file is larger than this site accepts.', 'monks-central' ) );
		}
		if ( '' !== $chunk ) {
			file_put_contents( $state['path'], $chunk, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appends a chunk to a temp file; WP_Filesystem cannot append
		}
		$size += strlen( $chunk );
	} finally {
		flock( $lock, LOCK_UN );
		fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
	$state = array_merge( $state, $set );

	if ( empty( $input['done'] ) ) {
		set_transient( $prefix . $id, $state, HOUR_IN_SECONDS );
	} else {
		delete_transient( $prefix . $id );
		wp_clear_scheduled_hook( 'cm_ai_chunk_expire', array( $prefix . $id, $state['path'] ) );
	}
	return array(
		'id'    => $id,
		'state' => $state,
		'size'  => $size,
	);
}

add_action( 'cm_ai_chunk_expire', 'cm_ai_chunk_expire', 10, 2 );
function cm_ai_chunk_expire( $key, $path ) {
	if ( false === get_transient( $key ) ) {
		wp_delete_file( $path );
		return;
	}
	// Still uploading: look again later.
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cm_ai_chunk_expire', array( $key, $path ) );
}

function cm_execute_upload_media( $input ) {
	$upload = cm_ai_receive_chunk( $input, 'cm_upload_' );
	if ( is_wp_error( $upload ) ) {
		return $upload;
	}
	$id    = $upload['id'];
	$state = $upload['state'];
	$size  = $upload['size'];
	if ( empty( $input['done'] ) ) {
		return array(
			'upload_id' => $id,
			'received'  => $size,
		);
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once CM_FUNCTIONS_DIR . 'monks-central/media-import-helpers.php';

	$file = cm_ai_prepare_media_file( $state['path'], $state['name'] );
	if ( is_wp_error( $file ) ) {
		wp_delete_file( $state['path'] );
		return $file;
	}
	$post_data = array();
	if ( '' !== trim( (string) ( $input['title'] ?? '' ) ) ) {
		$post_data['post_title'] = sanitize_text_field( $input['title'] );
	}
	$attachment_id = media_handle_sideload( $file, absint( $input['post_id'] ?? 0 ), null, $post_data );
	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $state['path'] );
		return $attachment_id;
	}
	if ( '' !== trim( (string) ( $input['alt'] ?? '' ) ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
	}
	return array(
		'upload_id'     => $id,
		'received'      => $size,
		'attachment_id' => (int) $attachment_id,
		'url'           => (string) wp_get_attachment_url( $attachment_id ),
		'mime_type'     => (string) get_post_mime_type( $attachment_id ),
	);
}
