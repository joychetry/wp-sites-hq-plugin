<?php
/**
 * Get Tags Ability - WordPress Abilities API
 *
 * Gets all post tags with filtering options.
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
 * Register the get-tags ability.
 *
 * Returns post tags with filtering and sorting.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_tags_ability' );
function cm_register_get_tags_ability() {
    wp_register_ability( 'classic-monks/get-tags', array(
        'label'       => __( 'Get Tags', 'classic-monks' ),
        'description' => __( 
            "Gets all post tags with filtering and sorting options. Use this for tag management, tag clouds, or content categorization. \n\n## Optional Parameters\n- **limit**: Number of tags to return (1-100, default 20)\n- **orderby**: Order by name, count, or id (default count)\n- **order**: ASC or DESC (default DESC)\n- **search**: Search term to filter tags\n- **hide_empty**: Whether to hide tags with no posts (default false)\n\n## Returns\nArray of tags with: id, name, slug, description, count, link.",
            'classic-monks' 
        ),
        'category'    => 'taxonomy',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'limit'      => array(
                    'type'        => 'integer',
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'description' => 'Number of tags to return (1-100).',
                ),
                'orderby'    => array(
                    'type'        => 'string',
                    'enum'        => array( 'name', 'count', 'id' ),
                    'default'     => 'count',
                    'description' => 'Field to order by.',
                ),
                'order'      => array(
                    'type'        => 'string',
                    'enum'        => array( 'ASC', 'DESC' ),
                    'default'     => 'DESC',
                    'description' => 'Sort direction.',
                ),
                'search'     => array(
                    'type'        => 'string',
                    'description' => 'Search term to filter tags.',
                ),
                'hide_empty' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Whether to hide tags with no posts.',
                ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'tags'  => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'          => array( 'type' => 'integer' ),
                            'name'        => array( 'type' => 'string' ),
                            'slug'        => array( 'type' => 'string' ),
                            'description' => array( 'type' => 'string' ),
                            'count'       => array( 'type' => 'integer' ),
                            'link'        => array( 'type' => 'string', 'format' => 'uri' ),
                        ),
                    ),
                ),
                'total' => array( 'type' => 'integer' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_get_tags',
        'permission_callback' => function() {
            return current_user_can( 'manage_categories' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => true,
                'destructive'  => false,
                'idempotent'   => true,
                'instructions' => 'Shortcut to get post_tag taxonomy terms. For other taxonomies, use get-terms with taxonomy parameter.',
            ),
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * Execute the get-tags ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array|WP_Error Tags or error.
 */
function cm_execute_get_tags( $input = array() ) {
    $args = array(
        'taxonomy'   => 'post_tag',
        'number'     => min( absint( isset( $input['limit'] ) ? $input['limit'] : 20 ), 100 ),
        'orderby'    => sanitize_key( isset( $input['orderby'] ) ? $input['orderby'] : 'count' ),
        'order'      => ( isset( $input['order'] ) && 'ASC' === strtoupper( $input['order'] ) ) ? 'ASC' : 'DESC',
        'hide_empty' => isset( $input['hide_empty'] ) ? (bool) $input['hide_empty'] : false,
    );

    if ( ! empty( $input['search'] ) ) {
        $args['search'] = sanitize_text_field( $input['search'] );
    }

    $tags = get_terms( $args );

    if ( is_wp_error( $tags ) ) {
        return $tags;
    }

    $result = array();
    foreach ( $tags as $tag ) {
        $result[] = array(
            'id'          => $tag->term_id,
            'name'        => $tag->name,
            'slug'        => $tag->slug,
            'description' => $tag->description,
            'count'       => $tag->count,
            'link'        => is_wp_error( $link = get_term_link( $tag ) ) ? '' : $link,
        );
    }

    return array(
        'tags'  => $result,
        'total' => count( $result ),
    );
}