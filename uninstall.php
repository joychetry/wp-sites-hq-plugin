<?php
/**
 * Runs when the plugin is deleted from wp-admin. Removes everything the plugin stored.
 *
 * @package MonksCentral
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'monks_central_settings' );
delete_site_transient( 'monks_central_release' );

// The connector's data is shared with other plugins that bundle it; it decides what may go.
require_once __DIR__ . '/connector/uninstall.php';
monks_central_connector_uninstall( WP_UNINSTALL_PLUGIN );
