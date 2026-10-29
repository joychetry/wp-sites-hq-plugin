<?php
/**
 * Get HQ Status Ability - WordPress Abilities API
 *
 * One cheap call for a Monks Central site card: versions, modules, the
 * caller's role and connection, and pending approvals.
 *
 * @package ClassicMonks
 * @subpackage MonksCentral
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_get_hq_status_ability' );
function cm_register_get_hq_status_ability() {
    wp_register_ability( 'classic-monks/get-hq-status', array(
        'label'       => __( 'Get HQ Status', 'classic-monks' ),
        'description' => __(
            'Returns a short status of this site for a dashboard that manages several sites: Classic Monks, WordPress and PHP versions, MCP protocol versions, whether approvals work, which modules (WooCommerce, Bricks) are on, your role, this connection\'s access level and tool groups, and how many approval requests you can decide.',
            'classic-monks'
        ),
        'category'    => 'classic-monks',
        'input_schema' => array(
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'plugin'            => array( 'type' => 'object' ),
                'wordpress'         => array( 'type' => 'object' ),
                'php'               => array( 'type' => 'object' ),
                'mcp'               => array( 'type' => 'object' ),
                'modules'           => array( 'type' => 'object' ),
                'user'              => array( 'type' => 'object' ),
                'connection'        => array( 'type' => array( 'object', 'null' ) ),
                'pending_approvals' => array( 'type' => 'integer' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_get_hq_status',
        // Anyone who may connect an AI app (the OAuth consent capability), so HQ can report a non-admin connection.
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ),
            'show_in_rest' => true,
        ),
    ) );
}

function cm_execute_get_hq_status( $input = array() ) {
    $user       = wp_get_current_user();
    $mcp        = class_exists( 'CM_MCP_Server' );
    $connection = $mcp ? CM_MCP_Server::current_connection() : null;

    return array(
        'plugin'            => array( 'version' => CM_VERSION ),
        'wordpress'         => array(
            'version'     => get_bloginfo( 'version' ),
            'multisite'   => is_multisite(),
            'environment' => wp_get_environment_type(),
        ),
        'php'               => array( 'version' => PHP_VERSION ),
        'mcp'               => array(
            'enabled'           => $mcp,
            'protocol_versions' => $mcp ? array_merge( CM_MCP_Server::MODERN_VERSIONS, CM_MCP_Server::PROTOCOL_VERSIONS ) : array(),
            // Approvals, undo and the activity log need the 7.1 ability hooks.
            'approvals'         => version_compare( get_bloginfo( 'version' ), '7.1', '>=' ),
        ),
        'modules'           => array(
            'woocommerce' => cm_is_tab_enabled_woocommerce(),
            'bricks'      => cm_is_tab_enabled_bricks(),
        ),
        'user'              => array(
            'id'                 => $user->ID,
            'login'              => $user->user_login,
            'roles'              => array_values( $user->roles ),
            'can_manage_options' => current_user_can( 'manage_options' ),
        ),
        'connection'        => $connection ? array(
            'kind'     => $connection['kind'],
            'label'    => $connection['label'],
            'scope'    => $connection['scope'],
            'toolsets' => array_values( $connection['toolsets'] ),
            'trusted'  => $connection['trusted'],
            // Monks Central operator grant (M2), and whether it also skips the always-ask tools.
            'operator'                  => null !== $connection['operator'],
            'operator_skips_always_ask' => true === $connection['operator'],
        ) : null,
        'pending_approvals' => class_exists( 'CM_MCP_Approvals' ) ? count( CM_MCP_Approvals::pending_for_current_user() ) : 0,
    );
}
