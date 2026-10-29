<?php
/**
 * Update Term Ability - WordPress Abilities API
 *
 * Renames or edits an existing taxonomy term.
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
 * Register the update-term ability.
 *
 * @since 2.0.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_update_term_ability' );
function cm_register_update_term_ability() {
    wp_register_ability( 'classic-monks/update-term', array(
        'label'       => __( 'Update Term', 'classic-monks' ),
        'description' => __(
            "Updates the name, slug, description or parent of an existing taxonomy term (category, tag, or custom). \n\n## Parameters\n- **term_id**: The term ID (required)\n- **name**: New name\n- **slug**: New slug\n- **description**: New description\n- **parent**: New parent term ID, 0 for top level (hierarchical taxonomies only)\n\n## Returns\nThe term after the update (term_id, taxonomy, name, slug, description, parent) and its previous values.",
            'classic-monks'
        ),
        'category'    => 'taxonomy',
        'input_schema' => array(
            'type'       => 'object',
            'required'   => array( 'term_id' ),
            'properties' => array(
                'term_id'     => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'The term ID (required).',
                ),
                'name'        => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'description' => 'New term name.',
                ),
                'slug'        => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'description' => 'New term slug.',
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'New term description.',
                ),
                'parent'      => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'New parent term ID (hierarchical taxonomies only).',
                ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'term_id'     => array( 'type' => 'integer' ),
                'taxonomy'    => array( 'type' => 'string' ),
                'name'        => array( 'type' => 'string' ),
                'slug'        => array( 'type' => 'string' ),
                'description' => array( 'type' => 'string' ),
                'parent'      => array( 'type' => 'integer' ),
                'previous'    => array(
                    'type'       => 'object',
                    'properties' => array(
                        'name'        => array( 'type' => 'string' ),
                        'slug'        => array( 'type' => 'string' ),
                        'description' => array( 'type' => 'string' ),
                        'parent'      => array( 'type' => 'integer' ),
                    ),
                ),
            ),
        ),
        'execute_callback'    => 'cm_execute_update_term',
        'permission_callback' => function( $input ) {
            // edit_term maps to the taxonomy's own edit_terms capability.
            return ! empty( $input['term_id'] ) && current_user_can( 'edit_term', absint( $input['term_id'] ) );
        },
        'meta' => array(
            'cm_preview_callback' => 'cm_preview_update_term',
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true, // Overwrites the term's current fields
                'idempotent'   => true,
                'instructions' => 'Edits an existing term. Use get-terms to find the term ID. Only the fields you pass are changed.',
            ),
            'cm_undo_callback' => 'cm_undo_update_term',
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * The editable fields of a term.
 */
function cm_update_term_fields( $term ) {
    return array(
        'name'        => (string) $term->name,
        'slug'        => (string) $term->slug,
        'description' => (string) $term->description,
        'parent'      => (int) $term->parent,
    );
}

/**
 * Execute the update-term ability.
 *
 * @since 2.0.0
 * @param array $input Input parameters.
 * @return array|WP_Error Updated term or error.
 */
function cm_execute_update_term( $input ) {
    $term = get_term( absint( $input['term_id'] ) );
    if ( ! $term instanceof WP_Term ) {
        return new WP_Error( 'term_not_found', __( 'Term not found.', 'classic-monks' ) );
    }

    $args = array();
    if ( isset( $input['name'] ) ) {
        $args['name'] = sanitize_text_field( $input['name'] );
    }
    if ( isset( $input['slug'] ) ) {
        $args['slug'] = sanitize_title( $input['slug'] );
    }
    if ( isset( $input['description'] ) ) {
        $args['description'] = $input['description']; // Core's pre_term_description filters apply kses.
    }
    if ( isset( $input['parent'] ) ) {
        if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) {
            return new WP_Error( 'not_hierarchical', __( 'This taxonomy does not support parent terms.', 'classic-monks' ) );
        }
        $args['parent'] = absint( $input['parent'] );
    }
    if ( ! $args ) {
        return new WP_Error( 'nothing_to_update', __( 'Pass at least one of name, slug, description or parent.', 'classic-monks' ) );
    }

    $previous = cm_update_term_fields( $term );

    if ( class_exists( 'CM_AI_Activity_Log' ) ) {
        CM_AI_Activity_Log::set_undo( 'classic-monks/update-term', array_merge( $previous, array(
            'term_id'  => (int) $term->term_id,
            'taxonomy' => $term->taxonomy,
        ) ) );
    }

    // wp_update_term() expects slashed data.
    $result = wp_update_term( $term->term_id, $term->taxonomy, wp_slash( $args ) );
    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return array_merge(
        array( 'term_id' => (int) $term->term_id, 'taxonomy' => $term->taxonomy ),
        cm_update_term_fields( get_term( $term->term_id, $term->taxonomy ) ),
        array( 'previous' => $previous )
    );
}

/**
 * Current state of the term, for the approval screen.
 */
function cm_preview_update_term( $input ) {
    $term = get_term( absint( $input['term_id'] ?? 0 ) );
    if ( ! $term instanceof WP_Term ) {
        return null;
    }
    return array(
        'term_id'  => (int) $term->term_id,
        'taxonomy' => $term->taxonomy,
        'name'     => $term->name,
        'hash'     => md5( wp_json_encode( cm_update_term_fields( $term ) ) ),
    );
}

/**
 * Restore the term's previous name, slug, description and parent.
 */
function cm_undo_update_term( $undo ) {
    $term_id = (int) ( $undo['term_id'] ?? 0 );
    if ( ! current_user_can( 'edit_term', $term_id ) ) {
        return new WP_Error( 'forbidden', __( 'You are not allowed to edit this term.', 'classic-monks' ) );
    }
    $result = wp_update_term( $term_id, $undo['taxonomy'], wp_slash( array(
        'name'        => $undo['name'],
        'slug'        => $undo['slug'],
        'description' => $undo['description'],
        'parent'      => (int) $undo['parent'],
    ) ) );
    return is_wp_error( $result ) ? $result : true;
}
