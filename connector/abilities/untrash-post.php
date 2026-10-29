<?php
/**
 * Untrash Post Ability - WordPress Abilities API
 *
 * Brings a post back from the trash (Monks Central M6).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_untrash_post_ability' );
function cm_register_untrash_post_ability() {
    wp_register_ability( 'classic-monks/untrash-post', array(
        'label'       => __( 'Restore Post from Trash', 'classic-monks' ),
        'description' => __( 'Brings a trashed post, page or custom post type entry back. WordPress restores it as a draft unless the site says otherwise; the status it got is returned.', 'classic-monks' ),
        'category'    => 'content',
        'input_schema' => array(
            'type'                 => 'object',
            'required'             => array( 'post_id' ),
            'properties'           => array(
                'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'post_id' => array( 'type' => 'integer' ),
                'status'  => array( 'type' => 'string' ),
                'message' => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_untrash_post',
        'permission_callback' => function( $input ) {
            // Core's own untrash check (wp-admin/post.php) is delete_post.
            return ! empty( $input['post_id'] ) && current_user_can( 'delete_post', $input['post_id'] );
        },
        'meta' => array(
            // Monks Central only: keeps AI clients under their tool caps.
            'cm_operator_only' => true,
            'cm_preview_callback' => 'cm_preview_post_state',
            'annotations'         => array(
                'readonly'     => false,
                'destructive'  => false,
                'idempotent'   => true,
                'instructions' => 'Only posts in the trash can be restored. Publish it again with update-post if needed.',
            ),
            'show_in_rest' => true,
        ),
    ) );
}

function cm_execute_untrash_post( $input ) {
    $post = get_post( absint( $input['post_id'] ) );
    if ( ! $post ) {
        return new WP_Error( 'post_not_found', __( 'Post not found.', 'classic-monks' ) );
    }
    if ( 'trash' !== $post->post_status ) {
        return new WP_Error( 'not_in_trash', __( 'This post is not in the trash.', 'classic-monks' ) );
    }
    if ( ! wp_untrash_post( $post->ID ) ) {
        return new WP_Error( 'untrash_failed', __( 'The post could not be restored.', 'classic-monks' ) );
    }
    $status = get_post_status( $post->ID );
    return array(
        'post_id' => $post->ID,
        'status'  => $status,
        /* translators: %s: post status */
        'message' => sprintf( __( 'Restored from the trash as %s.', 'classic-monks' ), $status ),
    );
}
