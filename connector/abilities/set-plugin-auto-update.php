<?php
/**
 * Set Plugin Auto-Update Ability - WordPress Abilities API
 *
 * Turns WordPress automatic updates on or off for one installed plugin.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_set_plugin_auto_update_ability' );
function cm_register_set_plugin_auto_update_ability() {
    wp_register_ability( 'classic-monks/set-plugin-auto-update', array(
        'label'       => __( 'Set Plugin Auto-Update', 'classic-monks' ),
        'description' => __(
            "Turns automatic updates on or off for one installed plugin. \n\n## Parameters\n- One of **plugin_file**, **slug** or **name** to pick the plugin.\n- **enabled**: true to turn auto-updates on, false to turn them off (required).\n\n## Returns\nThe plugin file and its auto-update setting before and after.",
            'classic-monks'
        ),
        'category'    => 'system',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'plugin_file' => array( 'type' => 'string', 'description' => 'Installed plugin file path.' ),
                'slug'        => array( 'type' => 'string', 'description' => 'Installed plugin slug.' ),
                'name'        => array( 'type' => 'string', 'description' => 'Installed plugin name.' ),
                'enabled'     => array( 'type' => 'boolean' ),
            ),
            'required'             => array( 'enabled' ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'plugin_file' => array( 'type' => 'string' ),
                'previous'    => array( 'type' => 'boolean' ),
                'enabled'     => array( 'type' => 'boolean' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_set_plugin_auto_update',
        'permission_callback' => function() {
            return current_user_can( 'update_plugins' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => false,
                'idempotent'   => true,
                'instructions' => 'Changes only the auto-update setting; it does not update the plugin now.',
            ),
            // The undo data is this ability's own input with the old setting.
            'cm_undo_callback' => 'cm_execute_set_plugin_auto_update',
            'show_in_rest'     => true,
        ),
    ) );
}

function cm_execute_set_plugin_auto_update( $input ) {
    // Re-checked here because this also runs as the undo callback.
    if ( ! current_user_can( 'update_plugins' ) ) {
        return new WP_Error( 'forbidden', __( 'You are not allowed to update plugins.', 'classic-monks' ) );
    }
    require_once ABSPATH . 'wp-admin/includes/update.php';
    if ( ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
        return new WP_Error( 'cm_auto_updates_disabled', __( 'Plugin auto-updates are turned off for this site.', 'classic-monks' ) );
    }

    $resolved = cm_ai_resolve_installed_plugin( $input );
    if ( is_wp_error( $resolved ) ) {
        return $resolved;
    }

    $file     = $resolved['plugin_file'];
    $list     = (array) get_site_option( 'auto_update_plugins', array() );
    $previous = in_array( $file, $list, true );
    $enabled  = (bool) $input['enabled'];

    if ( class_exists( 'CM_AI_Activity_Log' ) ) {
        CM_AI_Activity_Log::set_undo( 'classic-monks/set-plugin-auto-update', array( 'plugin_file' => $file, 'enabled' => $previous ) );
    }

    $list = array_values( array_diff( $list, array( $file ) ) );
    if ( $enabled ) {
        $list[] = $file;
    }
    update_site_option( 'auto_update_plugins', $list );

    return array(
        'plugin_file' => $file,
        'previous'    => $previous,
        'enabled'     => $enabled,
    );
}
