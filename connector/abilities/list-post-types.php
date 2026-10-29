<?php
/**
 * List Post Types Ability - WordPress Abilities API
 *
 * Every post type the current user can edit, with the taxonomies it uses (Monks Central M6).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_list_post_types_ability' );
function cm_register_list_post_types_ability() {
    wp_register_ability( 'classic-monks/list-post-types', array(
        'label'       => __( 'List Post Types', 'classic-monks' ),
        'description' => __(
            'Lists every post type you can edit (posts, pages and custom post types, including ones not shown in REST), ' .
            'with what each supports and the taxonomies it uses. Internal types (attachments, templates, navigation) are left out.',
            'classic-monks'
        ),
        'category'    => 'content',
        'input_schema' => array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'post_types' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'name'         => array( 'type' => 'string' ),
                            'label'        => array( 'type' => 'string' ),
                            'singular'     => array( 'type' => 'string' ),
                            'hierarchical' => array( 'type' => 'boolean' ),
                            'supports'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                            'can_publish'  => array( 'type' => 'boolean' ),
                            'can_delete'   => array( 'type' => 'boolean' ),
                            'taxonomies'   => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'name'         => array( 'type' => 'string' ),
                                        'label'        => array( 'type' => 'string' ),
                                        'hierarchical' => array( 'type' => 'boolean' ),
                                        'can_add'      => array( 'type' => 'boolean' ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        'execute_callback'    => 'cm_execute_list_post_types',
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
        },
        'meta' => array(
            // Monks Central only: keeps AI clients under their tool caps.
            'cm_operator_only' => true,
            'annotations' => array(
                'readonly'     => true,
                'destructive'  => false,
                'idempotent'   => true,
                'instructions' => 'Use the name with get-posts, create-post and update-post; taxonomy names go in their terms field.',
            ),
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * @return array
 */
function cm_execute_list_post_types() {
    $internal = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face' );
    $out      = array();
    foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
        // No body editor means a record, not content (WooCommerce orders and coupons, builder templates).
        if ( in_array( $type->name, $internal, true ) || ! post_type_supports( $type->name, 'editor' ) || ! current_user_can( $type->cap->edit_posts ) ) {
            continue;
        }
        $taxonomies = array();
        foreach ( get_object_taxonomies( $type->name, 'objects' ) as $tax ) {
            if ( ! $tax->show_ui || ! current_user_can( $tax->cap->assign_terms ) ) {
                continue;
            }
            $taxonomies[] = array(
                'name'         => $tax->name,
                'label'        => $tax->label,
                'hierarchical' => (bool) $tax->hierarchical,
                'can_add'      => current_user_can( $tax->cap->edit_terms ),
            );
        }
        $out[] = array(
            'name'         => $type->name,
            'label'        => $type->label,
            'singular'     => $type->labels->singular_name,
            'hierarchical' => (bool) $type->hierarchical,
            'supports'     => array_keys( get_all_post_type_supports( $type->name ) ),
            'can_publish'  => current_user_can( $type->cap->publish_posts ),
            'can_delete'   => current_user_can( $type->cap->delete_posts ),
            'taxonomies'   => $taxonomies,
        );
    }
    return array( 'post_types' => $out );
}
