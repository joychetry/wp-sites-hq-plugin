<?php
/**
 * Replace Media Abilities - WordPress Abilities API
 *
 * replace-media puts a new file in place of an attachment's file, sent in chunks like upload-media:
 * same ID, same URL by default, thumbnails regenerated, the old file kept as one backup.
 * restore-replaced-media swaps the backup back in (Monks Central).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_replace_media_abilities' );
function cm_register_replace_media_abilities() {
    $result = array(
        'type'       => 'object',
        'properties' => array(
            'upload_id'  => array( 'type' => 'string' ),
            'received'   => array( 'type' => 'integer' ),
            'id'         => array( 'type' => 'integer' ),
            'url'        => array( 'type' => 'string' ),
            'mime_type'  => array( 'type' => 'string' ),
            'file_name'  => array( 'type' => 'string' ),
            'width'      => array( 'type' => 'integer' ),
            'height'     => array( 'type' => 'integer' ),
            'file_size'  => array( 'type' => 'integer' ),
            'has_backup' => array( 'type' => 'boolean', 'description' => 'restore-replaced-media can swap the earlier file back.' ),
        ),
    );
    $meta = array(
        // Monks Central only, and never an agent tool: the operator picks the file in the app window.
        'cm_operator_only' => true,
        'annotations'      => array(
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ),
        'cm_undo_callback' => 'cm_undo_replace_media',
        'show_in_rest'     => true,
    );

    wp_register_ability( 'classic-monks/replace-media', array(
        'label'       => __( 'Replace Media', 'classic-monks' ),
        'description' => __(
            'Replaces a Media Library item\'s file, keeping its ID, so every post that uses it shows the new file. Chunks work as in upload-media (filename and data first, then upload_id, offset and data, done: true on the last), with id on every call. The URL stays the same unless keep_name is true or the extension changes. Images can only be replaced by images, other files by the same type. Thumbnails are made again. The old file is kept as one backup until the next replace; restore-replaced-media puts it back.',
            'classic-monks'
        ),
        'category'    => 'media',
        'input_schema' => array(
            'type'                 => 'object',
            'required'             => array( 'id', 'data' ),
            'properties'           => array(
                'id'        => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Attachment ID.' ),
                'filename'  => array( 'type' => 'string', 'description' => 'New file name, on the first call.' ),
                'data'      => array( 'type' => 'string', 'description' => 'This chunk, base64.' ),
                'upload_id' => array( 'type' => 'string', 'description' => 'From the first reply, on every later call.' ),
                'offset'    => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Bytes received before this chunk.' ),
                'done'      => array( 'type' => 'boolean', 'default' => false, 'description' => 'This is the last chunk.' ),
                'keep_name' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Use the new file\'s name (the URL changes).' ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema'       => $result,
        'execute_callback'    => 'cm_execute_replace_media',
        'permission_callback' => 'cm_can_edit_media',
        'meta'                => $meta,
    ) );

    wp_register_ability( 'classic-monks/restore-replaced-media', array(
        'label'       => __( 'Restore Replaced Media', 'classic-monks' ),
        'description' => __(
            'Puts back the file a Media Library item had before its last replace-media. The file it replaces becomes the backup, so running it again swaps back.',
            'classic-monks'
        ),
        'category'    => 'media',
        'input_schema' => array(
            'type'                 => 'object',
            'required'             => array( 'id' ),
            'properties'           => array(
                'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Attachment ID.' ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema'       => $result,
        'execute_callback'    => 'cm_execute_restore_replaced_media',
        'permission_callback' => 'cm_can_edit_media',
        'meta'                => $meta,
    ) );
}

function cm_execute_replace_media( $input ) {
    $id = absint( $input['id'] );
    // The attachment is part of the key, so an upload started for one item cannot finish on another.
    $upload = cm_ai_receive_chunk( $input, 'cm_replace_' . $id . '_' );
    if ( is_wp_error( $upload ) ) {
        return $upload;
    }
    $state = $upload['state'];
    if ( empty( $input['done'] ) ) {
        return array(
            'upload_id' => $upload['id'],
            'received'  => $upload['size'],
        );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once CM_FUNCTIONS_DIR . 'monks-central/media-import-helpers.php';

    $file = cm_ai_prepare_media_file( $state['path'], $state['name'] );
    if ( ! is_wp_error( $file ) && ! cm_media_same_family( get_post_mime_type( $id ), $file['type'] ) ) {
        $file = new WP_Error(
            'cm_media_type_mismatch',
            0 === strpos( (string) get_post_mime_type( $id ), 'image/' ) && 'image/svg+xml' !== get_post_mime_type( $id )
                /* translators: %s: the new file's MIME type */
                ? sprintf( __( 'This image can only be replaced by another image, not %s.', 'classic-monks' ), $file['type'] )
                /* translators: 1: current MIME type, 2: new MIME type */
                : sprintf( __( 'This %1$s file can only be replaced by another %1$s file, not %2$s.', 'classic-monks' ), get_post_mime_type( $id ), $file['type'] )
        );
    }
    if ( is_wp_error( $file ) ) {
        wp_delete_file( $state['path'] );
        return $file;
    }

    $name = $file['name'];
    $old  = cm_media_original_path( $id );
    if ( empty( $input['keep_name'] ) && $old ) {
        $name = pathinfo( $old, PATHINFO_FILENAME ) . '.' . pathinfo( $name, PATHINFO_EXTENSION );
    }
    $token = cm_media_swap( $id, $state['path'], $name, $file['type'] );
    if ( is_wp_error( $token ) ) {
        wp_delete_file( $state['path'] );
        return $token;
    }
    if ( $token && class_exists( 'CM_AI_Activity_Log' ) ) {
        CM_AI_Activity_Log::set_undo( 'classic-monks/replace-media', array( 'id' => $id, 'token' => $token ) );
    }
    return array_merge( array( 'upload_id' => $upload['id'], 'received' => $upload['size'] ), cm_replaced_media_result( $id ) );
}

function cm_execute_restore_replaced_media( $input ) {
    $id    = absint( $input['id'] );
    $token = cm_restore_replaced_media( $id );
    if ( is_wp_error( $token ) ) {
        return $token;
    }
    if ( class_exists( 'CM_AI_Activity_Log' ) ) {
        CM_AI_Activity_Log::set_undo( 'classic-monks/restore-replaced-media', array( 'id' => $id, 'token' => $token ) );
    }
    return cm_replaced_media_result( $id );
}

/**
 * Undo of a replace or a restore: swap the backup back, unless the file was swapped again since.
 */
function cm_undo_replace_media( $undo ) {
    $id = absint( $undo['id'] ?? 0 );
    if ( ! cm_can_edit_media( array( 'id' => $id ) ) ) {
        return new WP_Error( 'forbidden', __( 'You are not allowed to edit this media item.', 'classic-monks' ) );
    }
    $backup = get_post_meta( $id, '_cm_replaced_backup', true );
    if ( empty( $undo['token'] ) || ! is_array( $backup ) || $backup['token'] !== $undo['token'] ) {
        return new WP_Error( 'cm_undo_drift', __( 'This file was replaced or restored again since, so this change can no longer be undone.', 'classic-monks' ) );
    }
    $token = cm_restore_replaced_media( $id );
    return is_wp_error( $token ) ? $token : true;
}

/**
 * Images (not SVG) can replace each other; anything else only the same type.
 */
function cm_media_same_family( $old, $new ) {
    $raster = function ( $mime ) {
        return 0 === strpos( (string) $mime, 'image/' ) && 'image/svg+xml' !== $mime;
    };
    return $old === $new || ( $raster( $old ) && $raster( $new ) );
}

/**
 * The attachment's file before WordPress scaled it down, or its file.
 */
function cm_media_original_path( $id ) {
    $path = wp_attachment_is_image( $id ) ? wp_get_original_image_path( $id ) : get_attached_file( $id );
    return $path ? $path : '';
}

/**
 * Swaps the backup back in; the current file becomes the backup. Returns the new backup's token.
 */
function cm_restore_replaced_media( $id ) {
    $backup = get_post_meta( $id, '_cm_replaced_backup', true );
    $src    = cm_replaced_backup_path( $id, $backup );
    if ( ! $src || ! is_file( $src ) ) {
        return new WP_Error( 'cm_no_backup', __( 'This media item has no earlier file to restore.', 'classic-monks' ) );
    }
    return cm_media_swap( $id, $src, $backup['name'], $backup['mime'] );
}

/**
 * The backup's full path, only inside uploads/cm-replaced/<id>/ ('' for anything else).
 */
function cm_replaced_backup_path( $id, $backup ) {
    $file = is_array( $backup ) ? (string) ( $backup['file'] ?? '' ) : '';
    if ( 0 !== strpos( $file, 'cm-replaced/' . absint( $id ) . '/' ) || false !== strpos( $file, '..' ) ) {
        return '';
    }
    return path_join( wp_upload_dir()['basedir'], $file );
}

/**
 * One swap per attachment at a time, so two replaces can't delete each other's files.
 *
 * @return string|WP_Error As cm_media_swap_unlocked().
 */
function cm_media_swap( $id, $src, $name, $mime ) {
    $lock = 'cm_media_swap_' . absint( $id );
    if ( ! add_option( $lock, time(), '', false ) ) {
        if ( (int) get_option( $lock ) > time() - 5 * MINUTE_IN_SECONDS ) {
            return new WP_Error( 'cm_media_busy', __( 'This file is being replaced right now. Try again in a moment.', 'classic-monks' ), array( 'status' => 409 ) );
        }
        update_option( $lock, time(), false ); // A run that died left it behind.
    }
    try {
        return cm_media_swap_unlocked( $id, $src, $name, $mime );
    } finally {
        delete_option( $lock );
    }
}

/**
 * Moves $src in as attachment $id's file named $name, next to the current one. The current original is
 * copied to uploads/cm-replaced/<id>/ first (the one backup; an older backup is deleted), then the old
 * file and its sizes are deleted and the metadata and sizes are made again.
 *
 * @return string|WP_Error The backup's token, '' when there was no file to keep.
 */
function cm_media_swap_unlocked( $id, $src, $name, $mime ) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $uploads = wp_upload_dir();
    $old     = cm_media_original_path( $id );
    $dir     = $old ? dirname( $old ) : $uploads['path'];
    $before  = get_post_meta( $id, '_cm_replaced_backup', true );
    $token   = wp_generate_password( 12, false );
    $keep    = '';
    if ( $old && is_file( $old ) ) {
        $keep = $uploads['basedir'] . '/cm-replaced/' . $id . '/' . $token . '-' . wp_basename( $old );
        if ( wp_mkdir_p( dirname( $keep ) ) && ! file_exists( $uploads['basedir'] . '/cm-replaced/index.html' ) ) {
            // No folder listing of the kept files on servers that list folders.
            file_put_contents( $uploads['basedir'] . '/cm-replaced/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- an empty file in our own folder
        }
        if ( ! is_dir( dirname( $keep ) ) || ! copy( $old, $keep ) ) {
            return new WP_Error( 'cm_media_backup_failed', __( 'The current file could not be kept as a backup, so nothing was replaced.', 'classic-monks' ) );
        }
    }
    // Into the folder first under a temporary name: if that fails, nothing has been deleted yet.
    $stage = $dir . '/.cm-' . $token . '-' . $name;
    // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- a move inside uploads; copy is the fallback across filesystems
    if ( ! wp_mkdir_p( $dir ) || ! ( @rename( $src, $stage ) || ( copy( $src, $stage ) && wp_delete_file( $src ) ) ) ) {
        if ( $keep ) {
            wp_delete_file( $keep );
        }
        return new WP_Error( 'cm_media_move_failed', __( 'The new file could not be moved into the uploads folder.', 'classic-monks' ) );
    }
    $stat = stat( $dir );
    chmod( $stage, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- as wp_handle_upload(): temp files are 0600

    // The backup is recorded before anything is deleted, so a run cut short (a time limit while making
    // sizes) still points at the file it kept, and restore brings back the right one.
    $old_mime    = get_post_mime_type( $id );
    $before_path = cm_replaced_backup_path( $id, $before );
    if ( $before_path && $before_path !== $keep ) {
        wp_delete_file( $before_path );
    }
    if ( $keep ) {
        update_post_meta( $id, '_cm_replaced_backup', array(
            'token' => $token,
            'file'  => _wp_relative_upload_path( $keep ),
            'name'  => wp_basename( $old ),
            'mime'  => $old_mime,
        ) );
    } else {
        delete_post_meta( $id, '_cm_replaced_backup' );
    }

    $meta = wp_get_attachment_metadata( $id );
    wp_delete_attachment_files( $id, is_array( $meta ) ? $meta : array(), get_post_meta( $id, '_wp_attachment_backup_sizes', true ) ?: array(), (string) get_attached_file( $id ) );
    delete_post_meta( $id, '_wp_attachment_backup_sizes' );
    $dest = $dir . '/' . wp_unique_filename( $dir, $name );
    if ( ! rename( $stage, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- same folder
        // The old files are gone: put the kept original back so the item still has its file.
        if ( $keep && copy( $keep, $old ) ) {
            update_attached_file( $id, $old );
            wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $old ) );
        }
        wp_delete_file( $stage );
        return new WP_Error( 'cm_media_move_failed', __( 'The new file could not be moved into the uploads folder.', 'classic-monks' ) );
    }

    update_attached_file( $id, $dest );
    if ( $old_mime !== $mime ) {
        wp_update_post( array( 'ID' => $id, 'post_mime_type' => $mime ) );
    }
    wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
    if ( function_exists( 'cm_flush_external_caches' ) ) {
        cm_flush_external_caches( $id ); // Page caches would keep the old file's markup.
    }
    if ( class_exists( 'CM_Media_Replace' ) ) {
        CM_Media_Replace::get_instance()->update_recently_replaced( $id ); // The Replace media module's ?ver= cache busting.
    }

    return $keep ? $token : '';
}

function cm_replaced_media_result( $id ) {
    clean_post_cache( $id );
    $meta = wp_get_attachment_metadata( $id );
    $path = get_attached_file( $id );
    return array(
        'id'         => (int) $id,
        'url'        => (string) wp_get_attachment_url( $id ),
        'mime_type'  => (string) get_post_mime_type( $id ),
        'file_name'  => wp_basename( (string) $path ),
        'width'      => (int) ( $meta['width'] ?? 0 ),
        'height'     => (int) ( $meta['height'] ?? 0 ),
        'file_size'  => $path && is_file( $path ) ? (int) filesize( $path ) : 0,
        'has_backup' => is_array( get_post_meta( $id, '_cm_replaced_backup', true ) ),
    );
}

// A deleted attachment takes its backup with it.
add_action( 'delete_attachment', 'cm_delete_replaced_media_backup' );
function cm_delete_replaced_media_backup( $id ) {
    $dir = wp_upload_dir()['basedir'] . '/cm-replaced/' . absint( $id );
    foreach ( (array) glob( $dir . '/*' ) as $file ) {
        wp_delete_file( $file );
    }
    if ( is_dir( $dir ) ) {
        rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- our own empty folder
    }
}
