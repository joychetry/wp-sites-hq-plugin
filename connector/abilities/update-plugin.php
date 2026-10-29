<?php
/**
 * Update Plugin Ability - WordPress Abilities API
 *
 * Updates an installed WordPress plugin when an update is available.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 1.2.0
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_update_plugin_ability' );
function cm_register_update_plugin_ability() {
    wp_register_ability(
        'classic-monks/update-plugin',
        array(
            'label'       => __( 'Update Plugin', 'classic-monks' ),
            'description' => __(
                "Updates an installed WordPress plugin when an update is available. Provide the plugin file, slug, or exact installed plugin name. \n\n## Required Parameters\n- Provide one of: **plugin_file**, **slug**, or **name**\n\n## Optional Parameters\n- **force_update_check**: Refresh WordPress update data before checking (default: true)\n- **safe**: For an active plugin, check the home page and the error log right after the update (in maintenance mode) and put the previous version back if the update broke the site (default: false)\n\n## Returns\nThe plugin file, name, previous version, current version, whether an update was applied, and a status message. With safe: checked, rolled_back, reason and the home page status.",
                'classic-monks'
            ),
            'category'    => 'system',
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin_file' => array(
                        'type'        => 'string',
                        'description' => 'Installed plugin file path.',
                    ),
                    'slug' => array(
                        'type'        => 'string',
                        'description' => 'Installed plugin slug.',
                    ),
                    'name' => array(
                        'type'        => 'string',
                        'description' => 'Installed plugin name.',
                    ),
                    'force_update_check' => array(
                        'type'        => 'boolean',
                        'default'     => true,
                        'description' => 'Whether to refresh plugin update information before updating.',
                    ),
                    'safe' => array(
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Check the site right after updating an active plugin and put the previous version back if the update broke it.',
                    ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin_file' => array( 'type' => 'string' ),
                    'slug'        => array( 'type' => 'string' ),
                    'name'        => array( 'type' => 'string' ),
                    'previous_version' => array( 'type' => 'string' ),
                    'version'     => array( 'type' => 'string' ),
                    'active'      => array( 'type' => 'boolean' ),
                    'network_active' => array( 'type' => 'boolean' ),
                    'updated'     => array( 'type' => 'boolean' ),
                    'message'     => array( 'type' => 'string' ),
                    'checked'     => array( 'type' => 'boolean' ),
                    'rolled_back' => array( 'type' => 'boolean' ),
                    'reason'      => array( 'type' => 'string' ),
                    'status'      => array( 'type' => 'integer' ),
                ),
            ),
            'execute_callback'    => 'cm_execute_update_plugin',
            'permission_callback' => function() {
                return current_user_can( 'update_plugins' );
            },
            'meta' => array(
                'annotations' => array(
                    'readonly'     => false,
                    'destructive'  => true,
                    'idempotent'   => false,
                    'instructions' => 'Updates an installed plugin only when a WordPress update is available. Use get-plugins first if you need plugin files and version data.',
                ),
                'show_in_rest' => true,
            ),
        )
    );
}

/**
 * Execute the update-plugin ability.
 *
 * @since 1.2.0
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function cm_execute_update_plugin( $input ) {
    cm_ai_require_plugin_management_files();

    $resolved = cm_ai_resolve_installed_plugin( $input );
    if ( is_wp_error( $resolved ) ) {
        return $resolved;
    }

    $plugin_file = $resolved['plugin_file'];
    $plugin_data = $resolved['plugin_data'];

    if ( ! empty( $input['force_update_check'] ) ) {
        wp_update_plugins();
    }

    $updates = get_site_transient( 'update_plugins' );
    $response = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response )
        ? $updates->response
        : array();
    $previous_version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';

    if ( empty( $response[ $plugin_file ] ) ) {
        $state                     = cm_ai_get_plugin_state( $plugin_file, $plugin_data );
        $state['previous_version'] = $previous_version;
        $state['updated']          = false;
        $state['message']          = __( 'No update is currently available for this plugin.', 'classic-monks' );

        return $state;
    }

    // upgrade() deactivates the plugin and leaves turning it back on to a wp-admin page that never loads here.
    $was_active = is_plugin_active( $plugin_file );
    $network    = is_plugin_active_for_network( $plugin_file );
    // Nothing loads an inactive plugin, so safe mode only checks active ones.
    $safe       = ! empty( $input['safe'] ) && $was_active;

    $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
    if ( $safe ) {
        // As WP_Automatic_Updater: visitors wait while the new code is checked, and the check gets past the wait.
        $log_offset = cm_ai_error_log_since()['offset'];
        $upgrader->maintenance_mode( true );
        register_shutdown_function( array( $upgrader, 'maintenance_mode' ), false ); // Off even if this request dies.
    }
    $result = $upgrader->upgrade( $plugin_file );

    if ( is_wp_error( $result ) || ! $result ) {
        // upgrade() may have turned it off already; the old files stay (or come back at shutdown), so it goes back on.
        if ( $was_active ) {
            cm_ai_mark_plugin_active( $plugin_file, $network );
        }
        if ( $safe ) {
            $upgrader->maintenance_mode( false );
        }
    }

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    if ( ! $result ) {
        return new WP_Error(
            'cm_plugin_update_failed',
            __( 'Plugin update failed.', 'classic-monks' ),
            array( 'status' => 500 )
        );
    }

    wp_clean_plugins_cache( true );
    if ( $safe ) {
        // Never load the new file here: if it fatals, this request dies before it can roll back.
        cm_ai_mark_plugin_active( $plugin_file, $network );
        $check = cm_ai_update_broke_site( $log_offset );
        if ( '' !== $check['reason'] ) {
            return cm_ai_roll_back_plugin_update( $upgrader, $plugin_file, $previous_version, $check );
        }
        $upgrader->maintenance_mode( false );
        $reactivated = null;
    } else {
        // Silent, like core's reactivation after an update: the plugin was already active.
        $reactivated = $was_active ? activate_plugin( $plugin_file, '', $network, true ) : null;
    }
    wp_update_plugins();

    $all_plugins = get_plugins();
    $plugin_data = isset( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : $plugin_data;
    $state       = cm_ai_get_plugin_state( $plugin_file, $plugin_data );

    $state['previous_version'] = $previous_version;
    $state['updated']          = true;
    $state['message']          = is_wp_error( $reactivated )
        /* translators: %s: why the plugin could not be activated. */
        ? sprintf( __( 'Plugin updated, but it could not be turned back on: %s', 'classic-monks' ), $reactivated->get_error_message() )
        : __( 'Plugin updated successfully.', 'classic-monks' );
    if ( ! empty( $input['safe'] ) ) {
        $state['checked']     = $safe;
        $state['rolled_back'] = false;
        if ( $safe ) {
            $state['status'] = $check['status'];
        }
    }

    return $state;
}

/**
 * Loads the home page (with the fatal error scrape) and reads the error log after an update.
 *
 * @param int $log_offset Error log size before the update.
 * @return array status (home page) and reason ('' when the site works).
 */
function cm_ai_update_broke_site( $log_offset ) {
    sleep( 2 ); // As core: the loopback may otherwise still run the old code.
    $home = cm_ai_http_status( '/' );
    $log  = cm_ai_error_log_since( $log_offset );

    // The scrape can miss the fatal (something cleared it at shutdown); the log line then names it.
    $fatals = preg_grep( '/PHP (Fatal|Parse) error/', $log['lines'] );
    $fatal  = $home['fatal'] ?? ( $fatals ? preg_replace( array( '/^\[[^\]]*\]\s*/', '/\s+/' ), array( '', ' ' ), reset( $fatals ) ) : null );

    $reason = '';
    if ( null !== $home['error'] ) {
        /* translators: %s: why the request failed. */
        $reason = sprintf( __( 'The home page did not answer after the update: %s', 'classic-monks' ), $home['error'] );
    } elseif ( null !== $home['fatal'] || $home['status'] >= 500 ) {
        $reason = null !== $fatal
            /* translators: 1: HTTP status, 2: the PHP error. */
            ? sprintf( __( 'The home page answered %1$d after the update: %2$s', 'classic-monks' ), $home['status'], $fatal )
            /* translators: %d: HTTP status. */
            : sprintf( __( 'The home page answered %d after the update.', 'classic-monks' ), $home['status'] );
    } elseif ( $log['fatal'] ) {
        $reason = null !== $fatal
            /* translators: %s: the log line. */
            ? sprintf( __( 'The error log got a new fatal error after the update: %s', 'classic-monks' ), $fatal )
            : __( 'The error log got a new fatal error after the update.', 'classic-monks' );
    }

    return array( 'status' => $home['status'], 'reason' => $reason );
}

/**
 * Puts the files from before the update back (the plugin stays active) and ends maintenance mode.
 * When that fails, turns the plugin off so the site works.
 *
 * @param Plugin_Upgrader $upgrader         The upgrader that ran the update (its backup lasts until shutdown).
 * @param string          $plugin_file      Plugin file.
 * @param string          $previous_version Version before the update.
 * @param array           $check            cm_ai_update_broke_site() result.
 * @return array|WP_Error
 */
function cm_ai_roll_back_plugin_update( $upgrader, $plugin_file, $previous_version, $check ) {
    $slug = dirname( $plugin_file );
    // A plugin outside a folder ("hello.php") gets no backup; restoring slug "." would empty the plugins folder.
    $restored = '.' !== $slug
        && is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $slug )
        && true === $upgrader->restore_temp_backup( array( array( 'dir' => 'plugins', 'slug' => $slug, 'src' => WP_PLUGIN_DIR ) ) );
    if ( ! $restored ) {
        deactivate_plugins( $plugin_file, true );
        $upgrader->maintenance_mode( false );
        return new WP_Error(
            'cm_rollback_failed',
            /* translators: %s: what failed after the update. */
            sprintf( __( 'The update broke the site and the previous version could not be put back, so the plugin was turned off. %s', 'classic-monks' ), $check['reason'] ),
            array( 'status' => 500 )
        );
    }
    $upgrader->maintenance_mode( false );
    wp_clean_plugins_cache( true );
    wp_update_plugins(); // The upgrade emptied the update list: without this every other plugin's update is gone until the next check.

    $all_plugins = get_plugins();
    $state       = cm_ai_get_plugin_state( $plugin_file, $all_plugins[ $plugin_file ] ?? array() );

    $state['previous_version'] = $previous_version;
    $state['updated']          = false;
    $state['checked']          = true;
    $state['rolled_back']      = true;
    $state['status']           = $check['status'];
    $state['reason']           = $check['reason'];
    /* translators: %s: what failed after the update. */
    $state['message']          = sprintf( __( 'The update broke the site, so the previous version was put back. %s', 'classic-monks' ), $check['reason'] );

    return $state;
}
