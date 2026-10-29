<?php
/**
 * Assign Terms Ability - WordPress Abilities API
 *
 * Sets or adds taxonomy terms on a post.
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
 * Register the assign-terms ability.
 *
 * @since 2.0.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_assign_terms_ability' );
function cm_register_assign_terms_ability() {
    wp_register_ability( 'classic-monks/assign-terms', array(
        'label'       => __( 'Assign Terms', 'classic-monks' ),
        'description' => __(
            "Assigns existing taxonomy terms to a post. By default the given terms REPLACE the post's current terms in that taxonomy; pass append=true to add them instead. \n\n## Parameters\n- **post_id**: The post ID (required)\n- **taxonomy**: Taxonomy slug, e.g. category or post_tag (required)\n- **term_ids**: Existing term IDs in that taxonomy, max 100 (required; an empty list with append=false removes all terms)\n- **append**: Add to the current terms instead of replacing them (default false)\n\n## Returns\npost_id, taxonomy, the post's term_ids after the change and its previous_term_ids.",
            'classic-monks'
        ),
        'category'    => 'taxonomy',
        'input_schema' => array(
            'type'       => 'object',
            'required'   => array( 'post_id', 'taxonomy', 'term_ids' ),
            'properties' => array(
                'post_id'  => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'The post ID (required).',
                ),
                'taxonomy' => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'description' => 'Taxonomy slug (required).',
                ),
                'term_ids' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
                    'maxItems'    => 100,
                    'description' => 'Existing term IDs to assign.',
                ),
                'append'   => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Add to current terms instead of replacing them.',
                ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'post_id'           => array( 'type' => 'integer' ),
                'taxonomy'          => array( 'type' => 'string' ),
                'term_ids'          => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                'previous_term_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
            ),
        ),
        'execute_callback'    => 'cm_execute_assign_terms',
        'permission_callback' => function( $input ) {
            $tax = get_taxonomy( sanitize_key( $input['taxonomy'] ?? '' ) );
            if ( ! $tax || empty( $input['post_id'] ) ) {
                return false;
            }
            return current_user_can( 'edit_post', absint( $input['post_id'] ) ) && current_user_can( $tax->cap->assign_terms );
        },
        'meta' => array(
            'cm_preview_callback' => 'cm_preview_assign_terms',
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true, // Replaces the current terms unless append=true
                'idempotent'   => true,
                'instructions' => 'Assigns term IDs to a post. Replaces existing terms unless append=true. Use get-terms or create-term to get IDs first.',
            ),
            'cm_undo_callback' => 'cm_undo_assign_terms',
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * Execute the assign-terms ability.
 *
 * @since 2.0.0
 * @param array $input Input parameters.
 * @return array|WP_Error Assignment result or error.
 */
function cm_execute_assign_terms( $input ) {
    $post_id  = absint( $input['post_id'] );
    $taxonomy = sanitize_key( $input['taxonomy'] );
    $post     = get_post( $post_id );

    if ( ! $post ) {
        return new WP_Error( 'post_not_found', __( 'Post not found.', 'classic-monks' ) );
    }
    if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
        /* translators: %s: taxonomy name */
        return new WP_Error( 'invalid_taxonomy', sprintf( __( 'Taxonomy "%s" is not registered for this post type.', 'classic-monks' ), $taxonomy ) );
    }

    $term_ids = array_values( array_unique( array_map( 'absint', (array) $input['term_ids'] ) ) );
    foreach ( $term_ids as $term_id ) {
        if ( ! term_exists( $term_id, $taxonomy ) ) {
            /* translators: 1: term ID, 2: taxonomy name */
            return new WP_Error( 'term_not_found', sprintf( __( 'Term %1$d does not exist in "%2$s".', 'classic-monks' ), $term_id, $taxonomy ) );
        }
    }

    $previous = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
    if ( is_wp_error( $previous ) ) {
        return $previous;
    }
    $previous = array_map( 'intval', $previous );

    if ( class_exists( 'CM_AI_Activity_Log' ) ) {
        CM_AI_Activity_Log::set_undo( 'classic-monks/assign-terms', array(
            'post_id'  => $post_id,
            'taxonomy' => $taxonomy,
            'term_ids' => $previous,
        ) );
    }

    $result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, ! empty( $input['append'] ) );
    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return array(
        'post_id'           => $post_id,
        'taxonomy'          => $taxonomy,
        'term_ids'          => array_map( 'intval', wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ) ),
        'previous_term_ids' => $previous,
    );
}

/**
 * The post's current terms in the taxonomy, for the approval screen.
 */
function cm_preview_assign_terms( $input ) {
    $ids = wp_get_object_terms( absint( $input['post_id'] ?? 0 ), sanitize_key( $input['taxonomy'] ?? '' ), array( 'fields' => 'ids' ) );
    if ( is_wp_error( $ids ) ) {
        return null;
    }
    $ids = array_map( 'intval', $ids );
    sort( $ids );
    return array( 'post_id' => absint( $input['post_id'] ), 'taxonomy' => sanitize_key( $input['taxonomy'] ), 'term_ids' => $ids );
}

/**
 * Restore the post's previous terms in that taxonomy.
 */
function cm_undo_assign_terms( $undo ) {
    $tax = get_taxonomy( $undo['taxonomy'] ?? '' );
    if ( ! $tax || ! current_user_can( 'edit_post', (int) $undo['post_id'] ) || ! current_user_can( $tax->cap->assign_terms ) ) {
        return new WP_Error( 'forbidden', __( 'You are not allowed to change this post\'s terms.', 'classic-monks' ) );
    }
    $result = wp_set_object_terms( (int) $undo['post_id'], array_map( 'intval', (array) $undo['term_ids'] ), $tax->name, false );
    return is_wp_error( $result ) ? $result : true;
}
