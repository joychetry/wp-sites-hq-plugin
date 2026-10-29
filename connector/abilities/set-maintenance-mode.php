<?php
/**
 * Set Maintenance Mode Ability - WordPress Abilities API
 *
 * Turns WordPress' own maintenance mode (the .maintenance file updates use) on or off
 * (Monks Central M10).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_set_maintenance_mode_ability' );
function cm_register_set_maintenance_mode_ability() {
    wp_register_ability( 'classic-monks/set-maintenance-mode', array(
        'label'       => __( 'Set Maintenance Mode', 'classic-monks' ),
        'description' => __( 'Turns WordPress maintenance mode on or off, as during updates: every request to the site gets "Briefly unavailable for scheduled maintenance" (503), this connection\'s too. To keep working while it is on (and to turn it off), add the returned bypass query string to the MCP URL. WordPress ends it by itself after 10 minutes.', 'classic-monks' ),
        'category'    => 'system',
        'input_schema' => array(
            'type'                 => 'object',
            'required'             => array( 'on' ),
            'properties'           => array(
                'on' => array( 'type' => 'boolean' ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'on'     => array( 'type' => 'boolean' ),
                'bypass' => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_set_maintenance_mode',
        'permission_callback' => function() {
            return current_user_can( 'update_plugins' );
        },
        'meta' => array(
            // Monks Central only: keeps AI clients under their tool caps.
            'cm_operator_only' => true,
            'annotations'      => array(
                'readonly'     => false,
                'destructive'  => false,
                'idempotent'   => true,
                'instructions' => 'Turn it off again as soon as the work is done.',
            ),
            // Not in the REST list: the wp-admin chat loads its tools from there.
        ),
    ) );
}

function cm_execute_set_maintenance_mode( $input ) {
    // Monks Central's own connection only: it installs or takes down code for the whole site.
    if ( ! cm_is_operator_request() ) {
        return new WP_Error( 'cm_operator_only', __( 'Only the Monks Central app can use this tool.', 'classic-monks' ), array( 'status' => 403 ) );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $on = ! empty( $input['on'] );
    ( new WP_Upgrader( new Automatic_Upgrader_Skin() ) )->maintenance_mode( $on );
    if ( file_exists( ABSPATH . '.maintenance' ) !== $on ) {
        return new WP_Error( 'cm_maintenance_failed', __( 'WordPress could not write to its own folder, so maintenance mode did not change.', 'classic-monks' ) );
    }
    if ( ! $on ) {
        return array( 'on' => false );
    }
    // wp_maintenance() lets through requests that carry the key WordPress' own update check uses.
    $upgrading = (int) cm_ai_maintenance_upgrading();
    return array( 'on' => true, 'bypass' => 'wp_scrape_key=' . md5( $upgrading ) . '&wp_scrape_nonce=' . $upgrading );
}
