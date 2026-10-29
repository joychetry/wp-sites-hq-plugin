<?php
/**
 * Reply To Comment Ability - WordPress Abilities API
 *
 * Posts a reply to a comment as the current user.
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
 * Register the reply-to-comment ability.
 *
 * @since 2.0.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_reply_to_comment_ability' );
function cm_register_reply_to_comment_ability() {
    wp_register_ability( 'classic-monks/reply-to-comment', array(
        'label'       => __( 'Reply To Comment', 'classic-monks' ),
        'description' => __(
            "Posts a reply to an existing comment as the current user. The reply is approved immediately when the user can moderate comments, otherwise it is held for moderation. \n\n## Parameters\n- **comment_id**: ID of the comment to reply to (required)\n- **content**: Reply text; basic HTML allowed (required)\n\n## Returns\nThe new reply: comment_id, parent_id, post_id, status, link.",
            'classic-monks'
        ),
        'category'    => 'comments',
        'input_schema' => array(
            'type'       => 'object',
            'required'   => array( 'comment_id', 'content' ),
            'properties' => array(
                'comment_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'ID of the comment to reply to.',
                ),
                'content'    => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'description' => 'Reply text.',
                ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'comment_id' => array( 'type' => 'integer' ),
                'parent_id'  => array( 'type' => 'integer' ),
                'post_id'    => array( 'type' => 'integer' ),
                'status'     => array( 'type' => 'string' ),
                'link'       => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_reply_to_comment',
        'permission_callback' => function( $input ) {
            $parent = get_comment( absint( $input['comment_id'] ?? 0 ) );
            // Same check core's admin "Reply" uses.
            return $parent && current_user_can( 'edit_post', (int) $parent->comment_post_ID );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => false,
                'idempotent'   => false, // Each call posts a new reply
                'instructions' => 'Replies to a comment as the current user. Read the thread with get-comments first and keep the reply on topic.',
            ),
            'cm_undo_callback' => 'cm_undo_reply_to_comment',
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * Execute the reply-to-comment ability.
 *
 * @since 2.0.0
 * @param array $input Input parameters.
 * @return array|WP_Error Reply info or error.
 */
function cm_execute_reply_to_comment( $input ) {
    $parent = get_comment( absint( $input['comment_id'] ) );
    if ( ! $parent ) {
        return new WP_Error( 'comment_not_found', __( 'Comment not found.', 'classic-monks' ) );
    }

    $content = trim( (string) $input['content'] );
    if ( ! current_user_can( 'unfiltered_html' ) ) {
        $content = wp_kses_data( $content );
    }
    if ( '' === $content ) {
        return new WP_Error( 'empty_content', __( 'Reply content is empty.', 'classic-monks' ) );
    }

    $user = wp_get_current_user();

    // wp_insert_comment() expects slashed data.
    $reply_id = wp_insert_comment( wp_slash( array(
        'comment_post_ID'      => (int) $parent->comment_post_ID,
        'comment_parent'       => (int) $parent->comment_ID,
        'comment_content'      => $content,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_author_url'   => $user->user_url,
        'user_id'              => $user->ID,
        'comment_approved'     => current_user_can( 'moderate_comments' ) ? 1 : 0,
        'comment_type'         => 'comment',
    ) ) );

    if ( ! $reply_id ) {
        return new WP_Error( 'reply_failed', __( 'Could not save the reply.', 'classic-monks' ) );
    }

    if ( class_exists( 'CM_AI_Activity_Log' ) ) {
        CM_AI_Activity_Log::set_undo( 'classic-monks/reply-to-comment', array( 'comment_id' => (int) $reply_id ) );
    }

    return array(
        'comment_id' => (int) $reply_id,
        'parent_id'  => (int) $parent->comment_ID,
        'post_id'    => (int) $parent->comment_post_ID,
        'status'     => (string) wp_get_comment_status( $reply_id ),
        'link'       => (string) get_comment_link( $reply_id ),
    );
}

/**
 * Remove a reply posted by this ability (moves it to the trash).
 */
function cm_undo_reply_to_comment( $undo ) {
    $id = (int) ( $undo['comment_id'] ?? 0 );
    if ( ! current_user_can( 'edit_comment', $id ) ) {
        return new WP_Error( 'forbidden', __( 'You are not allowed to remove this reply.', 'classic-monks' ) );
    }
    if ( ! get_comment( $id ) ) {
        return true;
    }
    return wp_trash_comment( $id ) ? true : new WP_Error( 'undo_failed', __( 'Could not remove the reply.', 'classic-monks' ) );
}
