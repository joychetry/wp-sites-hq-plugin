<?php
/**
 * Delete Term Ability - WordPress Abilities API
 *
 * Deletes a taxonomy term.
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
 * Register the delete-term ability.
 *
 * @since 2.0.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_delete_term_ability' );
function cm_register_delete_term_ability() {
    wp_register_ability( 'classic-monks/delete-term', array(
        'label'       => __( 'Delete Term', 'classic-monks' ),
        'description' => __(
            "Deletes a taxonomy term (category, tag, or custom) and removes it from every item that uses it. Child terms move up to the deleted term's parent. The default category cannot be deleted, nor can terms used by more than 500 items. \n\n## Parameters\n- **term_id**: The term ID (required)\n\n## Returns\nThe deleted term (term_id, taxonomy, name, slug) and how many items it was removed from.",
            'classic-monks'
        ),
        'category'    => 'taxonomy',
        'input_schema' => array(
            'type'       => 'object',
            'required'   => array( 'term_id' ),
            'properties' => array(
                'term_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'The term ID (required).',
                ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'term_id'  => array( 'type' => 'integer' ),
                'taxonomy' => array( 'type' => 'string' ),
                'name'     => array( 'type' => 'string' ),
                'slug'     => array( 'type' => 'string' ),
                'objects'  => array( 'type' => 'integer' ),
                'deleted'  => array( 'type' => 'boolean' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_delete_term',
        'permission_callback' => function( $input ) {
            // delete_term maps to the taxonomy's own delete_terms capability.
            return ! empty( $input['term_id'] ) && current_user_can( 'delete_term', absint( $input['term_id'] ) );
        },
        'meta' => array(
            'cm_preview_callback' => 'cm_preview_delete_term',
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true,
                'idempotent'   => true,
                'instructions' => 'Deletes a term permanently. Confirm with the user first. Use get-terms to check the term and its post count.',
            ),
            'cm_undo_callback' => 'cm_undo_delete_term',
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * Execute the delete-term ability.
 *
 * @since 2.0.0
 * @param array $input Input parameters.
 * @return array|WP_Error Deleted term info or error.
 */
function cm_execute_delete_term( $input ) {
    $term = get_term( absint( $input['term_id'] ) );
    if ( ! $term instanceof WP_Term ) {
        return new WP_Error( 'term_not_found', __( 'Term not found.', 'classic-monks' ) );
    }

    $objects = get_objects_in_term( $term->term_id, $term->taxonomy );
    if ( is_wp_error( $objects ) ) {
        return $objects;
    }
    // Undo has to re-assign every item, so keep the set bounded.
    if ( count( $objects ) > 500 ) {
        return new WP_Error( 'too_many_objects', __( 'This term is used by more than 500 items. Delete it from the dashboard instead.', 'classic-monks' ) );
    }

    $children = get_terms( array(
        'taxonomy'   => $term->taxonomy,
        'parent'     => $term->term_id,
        'hide_empty' => false,
        'fields'     => 'ids',
    ) );

    if ( class_exists( 'CM_AI_Activity_Log' ) ) {
        CM_AI_Activity_Log::set_undo( 'classic-monks/delete-term', array(
            'taxonomy'    => $term->taxonomy,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => (int) $term->parent,
            'objects'     => array_map( 'intval', $objects ),
            'children'    => is_wp_error( $children ) ? array() : array_map( 'intval', $children ),
        ) );
    }

    $result = wp_delete_term( $term->term_id, $term->taxonomy );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    if ( 0 === $result ) {
        return new WP_Error( 'default_term', __( 'The default term of a taxonomy cannot be deleted.', 'classic-monks' ) );
    }
    if ( ! $result ) {
        return new WP_Error( 'delete_failed', __( 'Could not delete the term.', 'classic-monks' ) );
    }

    return array(
        'term_id'  => (int) $term->term_id,
        'taxonomy' => $term->taxonomy,
        'name'     => $term->name,
        'slug'     => $term->slug,
        'objects'  => count( $objects ),
        'deleted'  => true,
    );
}

/**
 * Current state of the term, for the approval screen.
 */
function cm_preview_delete_term( $input ) {
    $term = get_term( absint( $input['term_id'] ?? 0 ) );
    if ( ! $term instanceof WP_Term ) {
        return null;
    }
    return array(
        'term_id'  => (int) $term->term_id,
        'taxonomy' => $term->taxonomy,
        'name'     => $term->name,
        'slug'     => $term->slug,
        'count'    => (int) $term->count,
    );
}

/**
 * Recreate the term (new ID) and re-assign it to the items and child terms it had.
 */
function cm_undo_delete_term( $undo ) {
    $tax = get_taxonomy( $undo['taxonomy'] ?? '' );
    if ( ! $tax || ! current_user_can( $tax->cap->manage_terms ) || ! current_user_can( $tax->cap->assign_terms ) ) {
        return new WP_Error( 'forbidden', __( 'You are not allowed to restore this term.', 'classic-monks' ) );
    }

    $parent = (int) $undo['parent'];
    $result = wp_insert_term( wp_slash( $undo['name'] ), $tax->name, wp_slash( array(
        'slug'        => $undo['slug'],
        'description' => $undo['description'],
        'parent'      => $parent && term_exists( $parent, $tax->name ) ? $parent : 0,
    ) ) );
    if ( is_wp_error( $result ) ) {
        return $result;
    }

    $term_id = (int) $result['term_id'];
    foreach ( (array) $undo['objects'] as $object_id ) {
        wp_set_object_terms( (int) $object_id, $term_id, $tax->name, true );
    }
    foreach ( (array) $undo['children'] as $child_id ) {
        if ( term_exists( (int) $child_id, $tax->name ) ) {
            wp_update_term( (int) $child_id, $tax->name, array( 'parent' => $term_id ) );
        }
    }
    return true;
}
