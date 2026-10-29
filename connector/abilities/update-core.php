<?php
/**
 * Update Core Ability - WordPress Abilities API
 *
 * Updates WordPress to the newest release WordPress.org offers, or to a named version and locale it offers.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_update_core_ability' );
function cm_register_update_core_ability() {
    wp_register_ability( 'classic-monks/update-core', array(
        'label'       => __( 'Update Core', 'classic-monks' ),
        'description' => __(
            "Updates WordPress itself to the newest release offered by WordPress.org, or to the version (and locale) you name. Use get-core-version or list-updates first: only versions WordPress.org offers this site can be installed. Does nothing when WordPress is already at that version. \n\n## Notes\n- Not available when the site disallows file changes (DISALLOW_FILE_MODS).\n- The site is in maintenance mode for a short time during the update.\n\n## Returns\nThe version before and after, whether it was updated, and a message.",
            'classic-monks'
        ),
        'category'    => 'system',
        'input_schema' => array(
            'type'                 => 'object',
            'properties'           => array(
                'version' => array(
                    'type'        => 'string',
                    'description' => 'Target version, e.g. a minor release instead of the newest major. Default: the newest offered.',
                    'pattern'     => '^[0-9]+\\.[0-9]+(\\.[0-9]+)?(-[A-Za-z0-9.]+)?$',
                ),
                'locale'  => array(
                    'type'        => 'string',
                    'description' => 'Package locale, e.g. de_DE. Default: the site language, else en_US. Needs version.',
                    'pattern'     => '^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$',
                ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'previous_version' => array( 'type' => 'string' ),
                'version'          => array( 'type' => 'string' ),
                'updated'          => array( 'type' => 'boolean' ),
                'message'          => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_update_core',
        // update_core is denied by core when DISALLOW_FILE_MODS is set.
        'permission_callback' => function() {
            return current_user_can( 'update_core' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true,
                'idempotent'   => false,
                'instructions' => 'Suggest a full backup first and confirm with the user. Major updates can break older plugins.',
            ),
            'cm_preview_callback' => 'cm_preview_core_version',
            'cm_always_approve'   => true,
            'show_in_rest'        => true,
        ),
    ) );
}

function cm_preview_core_version( $input ) {
    return array( 'version' => (string) get_bloginfo( 'version' ) );
}

/**
 * The offer for a named version: in the given locale, else the site's, else en_US.
 *
 * @return object|null
 */
function cm_ai_find_core_offer( $version, $locale = '' ) {
    cm_ai_get_core_update_offer(); // refreshes the update_core transient when it is stale
    $offer = find_core_update( $version, $locale ? $locale : get_locale() );
    if ( ! $offer && ! $locale ) {
        $offer = find_core_update( $version, 'en_US' );
    }
    return $offer ? $offer : null;
}

function cm_execute_update_core( $input = array() ) {
    $previous = (string) get_bloginfo( 'version' );
    $version  = (string) ( $input['version'] ?? '' );
    $locale   = (string) ( $input['locale'] ?? '' );

    if ( '' !== $locale && '' === $version ) {
        return new WP_Error( 'cm_core_locale_without_version', __( 'Name a version together with the locale.', 'classic-monks' ), array( 'status' => 400 ) );
    }
    if ( '' !== $version && $version === $previous && ( '' === $locale || get_locale() === $locale ) ) {
        $offer = null;
    } elseif ( '' !== $version ) {
        $offer = cm_ai_find_core_offer( $version, $locale );
        if ( ! $offer ) {
            $offered = array_map( function ( $o ) {
                return $o->current . ' (' . $o->locale . ')';
            }, (array) get_core_updates() );
            return new WP_Error(
                'cm_core_version_not_offered',
                /* translators: 1: version, 2: list of offered versions */
                sprintf( __( 'WordPress.org does not offer %1$s for this site. Offered: %2$s.', 'classic-monks' ), trim( $version . ' ' . $locale ), $offered ? implode( ', ', $offered ) : __( 'none', 'classic-monks' ) ),
                array( 'status' => 400 )
            );
        }
    } else {
        $offer = cm_ai_get_core_update_offer();
    }

    if ( ! $offer ) {
        return array(
            'previous_version' => $previous,
            'version'          => $previous,
            'updated'          => false,
            'message'          => '' === $version ? __( 'WordPress is already up to date.', 'classic-monks' ) : __( 'WordPress is already at that version.', 'classic-monks' ),
        );
    }

    cm_ai_require_plugin_management_files();
    $upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
    $result   = $upgrader->upgrade( $offer );

    if ( is_wp_error( $result ) ) {
        return $result;
    }
    if ( ! $result ) {
        return new WP_Error( 'cm_core_update_failed', __( 'WordPress update failed.', 'classic-monks' ) );
    }

    return array(
        'previous_version' => $previous,
        'version'          => (string) $result,
        'updated'          => true,
        'message'          => __( 'WordPress updated.', 'classic-monks' ),
    );
}
