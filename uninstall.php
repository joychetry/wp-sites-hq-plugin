<?php
/**
 * Runs when the plugin is deleted from wp-admin. Removes everything the plugin stored.
 *
 * @package WpPluginStarter
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wps_settings' );
