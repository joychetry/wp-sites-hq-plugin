<?php
/**
 * Plugin Management Ability Helpers
 *
 * Shared helper functions for AI abilities that manage WordPress plugins.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 1.2.0
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Load the WordPress admin files required for plugin management.
 *
 * @since 1.2.0
 * @return void
 */
function cm_ai_require_plugin_management_files() {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-includes/update.php';
}

/**
 * Get a stable slug from a plugin file path.
 *
 * @since 1.2.0
 * @param string $plugin_file Plugin file.
 * @return string
 */
function cm_ai_get_plugin_slug_from_file( $plugin_file ) {
    $plugin_file = plugin_basename( (string) $plugin_file );
    $parts       = explode( '/', $plugin_file );

    return sanitize_key( $parts[0] ?? '' );
}

/**
 * Build a normalized plugin state payload.
 *
 * @since 1.2.0
 * @param string $plugin_file Plugin file.
 * @param array  $plugin_data Plugin header data.
 * @return array
 */
function cm_ai_get_plugin_state( $plugin_file, $plugin_data ) {
    cm_ai_require_plugin_management_files();

    $plugin_file       = plugin_basename( (string) $plugin_file );
    $site_active       = is_plugin_active( $plugin_file );
    $network_active    = is_multisite() && function_exists( 'is_plugin_active_for_network' )
        ? is_plugin_active_for_network( $plugin_file )
        : false;
    $plugin_name       = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : $plugin_file;
    $plugin_version    = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
    $plugin_slug       = cm_ai_get_plugin_slug_from_file( $plugin_file );

    return array(
        'plugin_file'    => $plugin_file,
        'slug'           => $plugin_slug,
        'name'           => $plugin_name,
        'version'        => $plugin_version,
        'active'         => (bool) ( $site_active || $network_active ),
        'network_active' => (bool) $network_active,
    );
}

/**
 * Resolve an installed plugin from file, slug, or name input.
 *
 * @since 1.2.0
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function cm_ai_resolve_installed_plugin( $input ) {
    cm_ai_require_plugin_management_files();

    $all_plugins  = get_plugins();
    $plugin_file  = isset( $input['plugin_file'] ) ? plugin_basename( sanitize_text_field( wp_unslash( $input['plugin_file'] ) ) ) : '';
    $slug         = isset( $input['slug'] ) ? sanitize_key( $input['slug'] ) : '';
    $name         = isset( $input['name'] ) ? sanitize_text_field( wp_unslash( $input['name'] ) ) : '';

    if ( $plugin_file && isset( $all_plugins[ $plugin_file ] ) ) {
        return array(
            'plugin_file' => $plugin_file,
            'plugin_data' => $all_plugins[ $plugin_file ],
        );
    }

    $matches = array();

    foreach ( $all_plugins as $installed_file => $plugin_data ) {
        $installed_slug = cm_ai_get_plugin_slug_from_file( $installed_file );
        $plugin_name    = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '';
        $exact_match    = false;
        $score          = 0;

        if ( $slug ) {
            if ( $installed_slug === $slug ) {
                $exact_match = true;
                $score       = 300;
            } elseif ( strpos( $installed_file, $slug . '/' ) === 0 ) {
                $score = 220;
            }
        }

        if ( $name ) {
            $normalized_name        = strtolower( $name );
            $normalized_plugin_name = strtolower( $plugin_name );

            if ( $normalized_plugin_name === $normalized_name ) {
                $exact_match = true;
                $score       = max( $score, 260 );
            } elseif ( false !== strpos( $normalized_plugin_name, $normalized_name ) ) {
                $score = max( $score, 160 );
            }
        }

        if ( $score > 0 ) {
            $matches[] = array(
                'plugin_file' => $installed_file,
                'plugin_data' => $plugin_data,
                'score'       => $score,
                'exact'       => $exact_match,
            );
        }
    }

    if ( empty( $matches ) ) {
        return new WP_Error(
            'cm_plugin_not_found',
            __( 'No installed plugin matched the provided plugin file, slug, or name.', 'classic-monks' ),
            array( 'status' => 404 )
        );
    }

    usort(
        $matches,
        static function( $left, $right ) {
            return $right['score'] <=> $left['score'];
        }
    );

    if ( count( $matches ) > 1 && $matches[0]['score'] === $matches[1]['score'] && empty( $matches[0]['exact'] ) ) {
        $candidate_names = array_slice(
            array_map(
                static function( $match ) {
                    return isset( $match['plugin_data']['Name'] ) ? (string) $match['plugin_data']['Name'] : $match['plugin_file'];
                },
                $matches
            ),
            0,
            5
        );

        return new WP_Error(
            'cm_plugin_ambiguous',
            sprintf(
                /* translators: %s: comma-separated plugin names */
                __( 'Multiple installed plugins matched the request: %s. Provide the plugin_file or exact slug.', 'classic-monks' ),
                implode( ', ', $candidate_names )
            ),
            array( 'status' => 409 )
        );
    }

    return array(
        'plugin_file' => $matches[0]['plugin_file'],
        'plugin_data' => $matches[0]['plugin_data'],
    );
}

/**
 * Resolve a WordPress.org download source from slug or plugin name.
 *
 * @since 1.2.0
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function cm_ai_resolve_plugin_install_source( $input ) {
    cm_ai_require_plugin_management_files();

    $slug = isset( $input['slug'] ) ? sanitize_key( $input['slug'] ) : '';
    $name = isset( $input['name'] ) ? sanitize_text_field( wp_unslash( $input['name'] ) ) : '';

    if ( $slug ) {
        $api = plugins_api(
            'plugin_information',
            array(
                'slug'   => $slug,
                'fields' => array( 'sections' => false ),
            )
        );

        if ( is_wp_error( $api ) ) {
            return $api;
        }

        return array(
            'source'        => 'wordpress.org',
            'download_link' => (string) $api->download_link,
            'slug'          => (string) $api->slug,
            'name'          => (string) $api->name,
        );
    }

    if ( ! $name ) {
        return new WP_Error(
            'cm_plugin_install_missing_target',
            __( 'Provide a WordPress.org plugin slug or plugin name to install a plugin.', 'classic-monks' ),
            array( 'status' => 400 )
        );
    }

    $search = plugins_api(
        'query_plugins',
        array(
            'search'   => $name,
            'per_page' => 5,
            'page'     => 1,
            'fields'   => array(
                'sections' => false,
            ),
        )
    );

    if ( is_wp_error( $search ) ) {
        return $search;
    }

    $plugins = isset( $search->plugins ) && is_array( $search->plugins ) ? $search->plugins : array();

    if ( empty( $plugins ) ) {
        return new WP_Error(
            'cm_plugin_install_not_found',
            __( 'No WordPress.org plugin matched the requested name.', 'classic-monks' ),
            array( 'status' => 404 )
        );
    }

    $normalized_name = strtolower( $name );
    $match           = null;

    foreach ( $plugins as $candidate ) {
        $candidate_name = isset( $candidate->name ) ? strtolower( (string) $candidate->name ) : '';
        $candidate_slug = isset( $candidate->slug ) ? strtolower( (string) $candidate->slug ) : '';

        if ( $candidate_name === $normalized_name || $candidate_slug === $normalized_name ) {
            $match = $candidate;
            break;
        }
    }

    if ( ! $match ) {
        if ( count( $plugins ) > 1 ) {
            return new WP_Error(
                'cm_plugin_install_ambiguous',
                __( 'Multiple WordPress.org plugins matched the provided name. Provide the exact slug to install the correct plugin.', 'classic-monks' ),
                array( 'status' => 409 )
            );
        }

        $match = $plugins[0];
    }

    return array(
        'source'        => 'wordpress.org',
        'download_link' => (string) $match->download_link,
        'slug'          => (string) $match->slug,
        'name'          => (string) $match->name,
    );
}

/**
 * Check whether the target plugin is the current Classic Monks plugin.
 *
 * @since 1.2.0
 * @param string $plugin_file Plugin file.
 * @return bool
 */
function cm_ai_is_current_plugin( $plugin_file ) {
    return plugin_basename( (string) $plugin_file ) === plugin_basename( CM_PLUGIN_FILE );
}

/**
 * Put a plugin back in the active list by writing the option, without loading its file
 * (a failed or unchecked update may have left broken code there).
 *
 * @param string $plugin_file Plugin file.
 * @param bool   $network     Network-active (multisite).
 * @return void
 */
function cm_ai_mark_plugin_active( $plugin_file, $network ) {
    if ( $network ) {
        $active                 = (array) get_site_option( 'active_sitewide_plugins', array() );
        $active[ $plugin_file ] = $active[ $plugin_file ] ?? time();
        update_site_option( 'active_sitewide_plugins', $active );
        return;
    }
    $active = (array) get_option( 'active_plugins', array() );
    if ( ! in_array( $plugin_file, $active, true ) ) {
        $active[] = $plugin_file;
        sort( $active );
        update_option( 'active_plugins', $active );
    }
}
