<?php
/**
 * Runs when the plugin is deleted from wp-admin. Removes everything the plugin stored.
 *
 * @package WPSitesHQ
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpshq_settings' );
delete_site_transient( 'wpshq_release' );

// The connector's data is shared with other plugins that bundle it; it decides what may go.
require_once __DIR__ . '/connector/uninstall.php';
wpshq_connector_uninstall( WP_UNINSTALL_PLUGIN );
