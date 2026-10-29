<?php
/**
 * Install Theme Ability - WordPress Abilities API
 *
 * Installs a theme from WordPress.org without activating it.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_install_theme_ability' );
function cm_register_install_theme_ability() {
    wp_register_ability( 'classic-monks/install-theme', array(
        'label'       => __( 'Install Theme', 'classic-monks' ),
        'description' => __(
            "Installs a theme from WordPress.org. It is not activated; use activate-theme for that. \n\n## Parameters\n- **slug**: WordPress.org theme slug, e.g. \"twentytwentyfive\" (required).\n\n## Returns\nThe theme stylesheet, name, version, and whether it was newly installed.",
            'classic-monks'
        ),
        'category'    => 'system',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'slug' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
            ),
            'required'             => array( 'slug' ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'stylesheet' => array( 'type' => 'string' ),
                'name'       => array( 'type' => 'string' ),
                'version'    => array( 'type' => 'string' ),
                'installed'  => array( 'type' => 'boolean' ),
                'message'    => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_install_theme',
        'permission_callback' => function() {
            return current_user_can( 'install_themes' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true,
                'idempotent'   => true,
                'instructions' => 'Installs only; the active theme does not change.',
            ),
            'show_in_rest' => true,
        ),
    ) );
}

function cm_execute_install_theme( $input ) {
    cm_ai_require_plugin_management_files();
    require_once ABSPATH . 'wp-admin/includes/theme.php';

    $slug     = sanitize_key( $input['slug'] );
    $existing = wp_get_theme( $slug );

    if ( $existing->exists() ) {
        return array(
            'stylesheet' => $slug,
            'name'       => (string) $existing->get( 'Name' ),
            'version'    => (string) $existing->get( 'Version' ),
            'installed'  => false,
            'message'    => __( 'Theme is already installed.', 'classic-monks' ),
        );
    }

    $api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
    if ( is_wp_error( $api ) ) {
        return $api;
    }

    $upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
    $result   = $upgrader->install( $api->download_link );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    if ( ! $result ) {
        return new WP_Error( 'cm_theme_install_failed', __( 'Theme installation failed.', 'classic-monks' ) );
    }

    $theme = $upgrader->theme_info();

    return array(
        'stylesheet' => $theme ? $theme->get_stylesheet() : $slug,
        'name'       => $theme ? (string) $theme->get( 'Name' ) : (string) $api->name,
        'version'    => $theme ? (string) $theme->get( 'Version' ) : (string) $api->version,
        'installed'  => true,
        'message'    => __( 'Theme installed.', 'classic-monks' ),
    );
}
