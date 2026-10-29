<?php
/**
 * Update Theme Ability - WordPress Abilities API
 *
 * Updates an installed theme when WordPress reports an update.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_update_theme_ability' );
function cm_register_update_theme_ability() {
    wp_register_ability( 'classic-monks/update-theme', array(
        'label'       => __( 'Update Theme', 'classic-monks' ),
        'description' => __(
            "Updates an installed theme to the latest version when an update is available. \n\n## Parameters\n- **stylesheet**: The theme's stylesheet from list-themes (required).\n\n## Returns\nThe theme, its version before and after, whether it was updated, and a message.",
            'classic-monks'
        ),
        'category'    => 'system',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'stylesheet' => array( 'type' => 'string', 'minLength' => 1 ),
            ),
            'required'             => array( 'stylesheet' ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'stylesheet'       => array( 'type' => 'string' ),
                'previous_version' => array( 'type' => 'string' ),
                'version'          => array( 'type' => 'string' ),
                'updated'          => array( 'type' => 'boolean' ),
                'message'          => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_update_theme',
        'permission_callback' => function() {
            return current_user_can( 'update_themes' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true,
                'idempotent'   => false,
                'instructions' => 'Overwrites the theme\'s files; edits made directly in the theme are lost. Suggest a backup first.',
            ),
            'cm_preview_callback' => 'cm_preview_theme_state',
            'show_in_rest'        => true,
        ),
    ) );
}

/**
 * The theme about to change, for approvals and drift checks.
 */
function cm_preview_theme_state( $input ) {
    $theme = wp_get_theme( sanitize_text_field( $input['stylesheet'] ?? '' ) );
    if ( ! $theme->exists() ) {
        return null;
    }
    return array(
        'stylesheet' => $theme->get_stylesheet(),
        'version'    => (string) $theme->get( 'Version' ),
        'active'     => get_stylesheet() === $theme->get_stylesheet(),
    );
}

function cm_execute_update_theme( $input ) {
    cm_ai_require_plugin_management_files();
    require_once ABSPATH . 'wp-admin/includes/theme.php';

    $theme = wp_get_theme( sanitize_text_field( $input['stylesheet'] ) );
    if ( ! $theme->exists() ) {
        return new WP_Error( 'cm_theme_not_found', __( 'Theme not found. Use list-themes for the exact stylesheet.', 'classic-monks' ) );
    }

    $stylesheet = $theme->get_stylesheet();
    $previous   = (string) $theme->get( 'Version' );

    wp_update_themes();
    $updates = get_site_transient( 'update_themes' );

    if ( empty( $updates->response[ $stylesheet ] ) ) {
        return array(
            'stylesheet'       => $stylesheet,
            'previous_version' => $previous,
            'version'          => $previous,
            'updated'          => false,
            'message'          => __( 'No update is available for this theme.', 'classic-monks' ),
        );
    }

    $upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
    $result   = $upgrader->upgrade( $stylesheet );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    if ( ! $result ) {
        return new WP_Error( 'cm_theme_update_failed', __( 'Theme update failed.', 'classic-monks' ) );
    }

    wp_clean_themes_cache();

    return array(
        'stylesheet'       => $stylesheet,
        'previous_version' => $previous,
        'version'          => (string) wp_get_theme( $stylesheet )->get( 'Version' ),
        'updated'          => true,
        'message'          => __( 'Theme updated.', 'classic-monks' ),
    );
}
