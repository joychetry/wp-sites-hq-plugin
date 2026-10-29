<?php
/**
 * Create Post Ability - WordPress Abilities API
 *
 * Creates new WordPress posts, pages, or custom post types.
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

require_once __DIR__ . '/content-write-helpers.php';

/**
 * Register the create-post ability.
 *
 * Creates new content in WordPress with comprehensive metadata support.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_create_post_ability' );
function cm_register_create_post_ability() {
    wp_register_ability( 'classic-monks/create-post', array(
        'label'       => __( 'Create Post', 'classic-monks' ),
        'description' => __( 
            "Creates a new WordPress post, page, or custom post type content. Use this ability to generate new content in the WordPress database. \n\n## Required Parameters\n- **title**: The post title (required)\n\n## Optional Parameters\n- **content**: Post body content (HTML allowed)\n- **excerpt**: Post excerpt/summary\n- **post_type**: post, page, or custom post type slug (default: post)\n- **status**: draft, publish, pending, private, future (default: draft)\n- **categories**: Array of category IDs (for post type only)\n- **tags**: Array of tag names or IDs\n- **featured_image**: Attachment ID for featured image\n- **terms**: Terms for any taxonomy, by ID or name: {\"genre\": [\"Jazz\"]}\n- **meta**: Custom fields (protected keys refused)\n- **date**: Publish date, \"YYYY-MM-DD HH:MM:SS\" (a future date schedules it)\n\n## Returns\nCreated post ID, title, permalink, and admin edit link.",
            'classic-monks' 
        ),
        'category'    => 'content',
        'input_schema' => array(
            'type'       => 'object',
            'required'   => array( 'title' ),
            'properties' => array(
                'title'          => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'description' => 'Post title (required).',
                ),
                'content'        => array(
                    'type'        => 'string',
                    'description' => 'Post body content. HTML is allowed and sanitized.',
                ),
                'excerpt'        => array(
                    'type'        => 'string',
                    'description' => 'Post excerpt or summary.',
                ),
                'post_type'      => array(
                    'type'        => 'string',
                    'default'     => 'post',
                    'description' => 'Post type slug: post, page, or any registered CPT.',
                ),
                'status'         => array(
                    'type'        => 'string',
                    'enum'        => array( 'draft', 'publish', 'pending', 'private', 'future' ),
                    'default'     => 'draft',
                    'description' => 'Post status. Default draft to prevent accidental publishing.',
                ),
                'categories'     => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'integer' ),
                    'description' => 'Array of category IDs. Only applies to "post" post type.',
                ),
                'tags'           => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'description' => 'Array of tag names.',
                ),
                'featured_image' => array(
                    'type'        => 'integer',
                    'description' => 'Attachment ID to use as featured image.',
                ),
            ) + cm_content_write_schema(),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'id'        => array(
                    'type'        => 'integer',
                    'description' => 'Created post ID.',
                ),
                'title'     => array(
                    'type'        => 'string',
                    'description' => 'Post title as saved.',
                ),
                'slug'      => array(
                    'type'        => 'string',
                    'description' => 'Post slug/URL name.',
                ),
                'status'    => array(
                    'type'        => 'string',
                    'description' => 'Post status.',
                ),
                'permalink' => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => 'Full URL to the post.',
                ),
                'edit_link' => array(
                    'type'        => 'string',
                    'format'      => 'uri',
                    'description' => 'Admin URL to edit the post.',
                ),
            ),
        ),
        'execute_callback'    => 'cm_execute_create_post',
        'permission_callback' => function( $input ) {
            $post_type = isset( $input['post_type'] ) ? $input['post_type'] : 'post';
            $post_type_obj = get_post_type_object( $post_type );
            if ( ! $post_type_obj ) {
                return false;
            }
            if ( in_array( $input['status'] ?? 'draft', array( 'publish', 'private', 'future' ), true ) && ! current_user_can( $post_type_obj->cap->publish_posts ) ) {
                return false;
            }
            return current_user_can( $post_type_obj->cap->create_posts );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => false, // Creates new content, doesn't destroy
                'idempotent'   => false, // Each call creates a new post
                'instructions' => 'Creates new content. Defaults to draft status. Use update-post to modify existing.',
            ),
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * Execute the create-post ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Created post info or error.
 */
function cm_execute_create_post( $input ) {
    // Validate post type exists
    $post_type = sanitize_key( isset( $input['post_type'] ) ? $input['post_type'] : 'post' );
    if ( ! post_type_exists( $post_type ) ) {
        return new WP_Error( 
            'invalid_post_type', 
            /* translators: %s: post type name */
            sprintf( __( 'Post type "%s" does not exist.', 'classic-monks' ), $post_type )
        );
    }

    $checked = cm_content_write_check( $post_type, 0, $input );
    if ( is_wp_error( $checked ) ) {
        return $checked;
    }

    // Build post data
    $post_data = array(
        'post_title'   => sanitize_text_field( $input['title'] ),
        'post_type'    => $post_type,
        'post_status'  => isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft',
        'post_content' => isset( $input['content'] ) ? $input['content'] : '',
        'post_excerpt' => isset( $input['excerpt'] ) ? sanitize_textarea_field( $input['excerpt'] ) : '',
    );

    // Categories (only for 'post' type)
    if ( ! empty( $input['categories'] ) && 'post' === $post_type ) {
        $post_data['post_category'] = array_map( 'absint', (array) $input['categories'] );
    }

    // Tags
    if ( ! empty( $input['tags'] ) ) {
        $post_data['tags_input'] = array_map( 'sanitize_text_field', (array) $input['tags'] );
    }

    if ( isset( $input['date'] ) ) {
        $post_data['post_date'] = $input['date'];
    }

    $more = cm_content_post_fields( $post_type, 0, $input, $post_data );
    if ( is_wp_error( $more ) ) {
        return $more;
    }

    // Insert the post. wp_insert_post() unslashes, and runs kses for users without unfiltered_html.
    $post_id = wp_insert_post( wp_slash( $post_data ), true );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    $written = cm_content_write_after( $post_id, $input );
    if ( is_wp_error( $written ) ) {
        // Nothing half made: the post was created just now, for this call.
        wp_delete_post( $post_id, true );
        return $written;
    }

    // Set featured image if provided
    if ( ! empty( $input['featured_image'] ) ) {
        $attachment_id = absint( $input['featured_image'] );
        if ( wp_attachment_is_image( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    // Get the created post
    $post = get_post( $post_id );

    return array(
        'id'        => $post_id,
        'title'     => $post->post_title,
        'slug'      => $post->post_name,
        'status'    => $post->post_status,
        'permalink' => get_permalink( $post_id ),
        'edit_link' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
    );
}