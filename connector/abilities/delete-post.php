<?php
/**
 * Delete Post Ability - WordPress Abilities API
 *
 * Deletes WordPress posts, pages, or custom post types.
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
 * Register the delete-post ability.
 *
 * Deletes content from WordPress (moves to trash or permanently).
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_delete_post_ability' );
function cm_register_delete_post_ability() {
    wp_register_ability( 'classic-monks/delete-post', array(
        'label'       => __( 'Delete Post', 'classic-monks' ),
        'description' => __( 
            "Deletes a WordPress post, page, or custom post type content. By default, moves content to trash. Use force=true to permanently delete. \n\n## ⚠️ DESTRUCTIVE OPERATION\nThis ability modifies the database. Use with caution.\n\n## Required Parameters\n- **post_id**: The ID of the post to delete (required)\n\n## Optional Parameters\n- **force**: Set to true to skip trash and permanently delete (default: false)\n\n## Behavior\n- `force=false` (default): Moves to trash, can be restored\n- `force=true`: Permanently deletes, cannot be restored\n\n## Returns\nConfirmation of deletion with post ID and status message.",
            'classic-monks' 
        ),
        'category'    => 'content',
        'input_schema' => array(
            'type'       => 'object',
            'required'   => array( 'post_id' ),
            'properties' => array(
                'post_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'The ID of the post to delete (required).',
                ),
                'force'   => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Skip trash and permanently delete. Default false (moves to trash).',
                ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'post_id'    => array(
                    'type'        => 'integer',
                    'description' => 'The ID of the deleted post.',
                ),
                'deleted'    => array(
                    'type'        => 'boolean',
                    'description' => 'Whether deletion was successful.',
                ),
                'permanent'  => array(
                    'type'        => 'boolean',
                    'description' => 'Whether this was a permanent deletion.',
                ),
                'message'    => array(
                    'type'        => 'string',
                    'description' => 'Status message describing the action taken.',
                ),
                'post_title' => array(
                    'type'        => 'string',
                    'description' => 'Title of the deleted post.',
                ),
            ),
        ),
        'execute_callback'    => 'cm_execute_delete_post',
        'permission_callback' => function( $input ) {
            if ( empty( $input['post_id'] ) ) {
                return false;
            }
            return current_user_can( 'delete_post', $input['post_id'] );
        },
        'meta' => array(
            'cm_preview_callback' => 'cm_preview_post_state',
            'cm_always_approve'   => 'cm_delete_post_always_approve',
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true, // This is a destructive operation
                'idempotent'   => true, // Deleting same post twice has same result
                'instructions' => 'WARNING: Destructive operation. Always confirm with user before deleting. Default moves to trash (recoverable). Only use force=true when explicitly requested.',
            ),
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * Execute the delete-post ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Deletion result or error.
 */
function cm_execute_delete_post( $input ) {
    $post_id = absint( $input['post_id'] );
    $force   = isset( $input['force'] ) && $input['force'] === true;

    // Get the post first (before deletion)
    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 
            'post_not_found', 
            __( 'Post not found.', 'classic-monks' )
        );
    }

    // Store title before deletion
    $post_title = $post->post_title;

    // Perform deletion
    $result = wp_delete_post( $post_id, $force );

    if ( ! $result ) {
        return new WP_Error( 
            'delete_failed', 
            __( 'Failed to delete post.', 'classic-monks' )
        );
    }

    // Determine message based on force flag
    $message = $force 
        ? __( 'Post permanently deleted.', 'classic-monks' )
        : __( 'Post moved to trash.', 'classic-monks' );

    return array(
        'post_id'    => $post_id,
        'deleted'    => true,
        'permanent'  => $force,
        'message'    => $message,
        'post_title' => $post_title,
    );
}

/**
 * What an approver sees for a post change, and the drift check before it runs.
 */
function cm_preview_post_state( $input ) {
    $post = get_post( absint( $input['post_id'] ?? $input['id'] ?? 0 ) );
    if ( ! $post ) {
        return null;
    }
    return array(
        'id'       => $post->ID,
        'title'    => $post->post_title,
        'type'     => $post->post_type,
        'status'   => $post->post_status,
        // Drafts keep a zero GMT date, so the local one is the reliable change marker.
        'modified' => $post->post_modified,
    );
}

/**
 * A permanent delete can't be undone, so trusted connections still ask; trashing doesn't.
 */
function cm_delete_post_always_approve( $input ) {
    return ! empty( $input['force'] );
}
