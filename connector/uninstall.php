<?php
/**
 * Monks Central connector: cleanup when a plugin that bundles it is deleted.
 *
 * Call monks_central_connector_uninstall() from the host's uninstall.php. The
 * connector's data is shared by every plugin that bundles it, so:
 * - credentials (MCP Application Passwords, OAuth tokens, per-connection meta)
 *   go only when no other plugin that runs the connector is active, since
 *   nothing would confine them any more;
 * - the activity log and the on/off option go only when no other plugin that
 *   bundles the connector is installed, so switching plugins keeps them.
 *
 * @package MonksCentral\Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Other installed plugins that bundle the connector (or carry Classic Monks' own copy), as basenames.
 *
 * @param string $self Basename of the plugin being deleted.
 * @return string[]
 */
function monks_central_connector_other_hosts( $self ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$hosts = array();
	foreach ( array_keys( get_plugins() ) as $file ) {
		$dir = dirname( $file );
		if ( $file === $self || '.' === $dir ) {
			continue;
		}
		foreach ( array( 'connector/load.php', 'functions/monks-central/mcp/mcp-guard.php', 'functions/ai/mcp/mcp-guard.php' ) as $marker ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $dir . '/' . $marker ) ) {
				$hosts[] = $file;
				break;
			}
		}
	}
	return $hosts;
}

/**
 * Remove what the connector stored, as far as no other plugin still needs it.
 *
 * @param string $self Basename of the plugin being deleted (WP_UNINSTALL_PLUGIN).
 */
function monks_central_connector_uninstall( $self ) {
	global $wpdb;
	$others = monks_central_connector_other_hosts( $self );
	$active = array_filter(
		$others,
		function ( $file ) {
			return is_plugin_active( $file ) || is_plugin_active_for_network( $file );
		}
	);
	if ( $active ) {
		return;
	}

	// Copy of CM_MCP_APP_ID: the connector's files are not loaded during uninstall.
	$app_id = '285c2739-211b-4f6f-98fb-121e7d24d97b';
	foreach ( get_users(
		array(
			'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall only
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'blog_id'      => 0,
		)
	) as $user_id ) {
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( $app_id === ( $item['app_id'] ?? '' ) ) {
				WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );
			}
		}
	}
	foreach ( array( 'cm_mcp_scopes', 'cm_mcp_toolsets', 'cm_mcp_trusted', 'cm_mcp_operator', 'cm_mcp_namespaces' ) as $key ) {
		delete_metadata( 'user', 0, $key, '', true );
	}

	$sites = is_multisite() ? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) : array( 0 );
	foreach ( $sites as $site_id ) {
		if ( $site_id ) {
			switch_to_blog( $site_id );
		}
		// OAuth tokens are credentials: they go with the last active plugin.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'cm_mcp_oauth_tokens' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the connector's own table
		delete_option( 'cm_mcp_oauth_db_version' );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cm\\_mcp\\_oauth\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cm\\_mcp\\_oauth\\_%' OR option_name LIKE 'cm\\_ai\\_similar\\_%' OR option_name LIKE '\\_transient\\_cm\\_login\\_link\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cm\\_login\\_link\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixed patterns, uninstall only
		if ( ! $others ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'cm_ai_log' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the connector's own table
			delete_option( 'cm_ai_log_db_version' );
			delete_option( 'cm_mcp_enabled' );
		}
		if ( $site_id ) {
			restore_current_blog();
		}
	}
}
