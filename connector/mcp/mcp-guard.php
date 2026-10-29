<?php
/**
 * Monks Central connector: MCP credential guard
 *
 * Always loaded by the running copy, even when the connector is off: an MCP Application
 * Password minted earlier (by Classic Monks Pro) still exists and core would
 * otherwise accept it on every REST route and over XML-RPC.
 *
 * @package ClassicMonks
 * @subpackage MonksCentral
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Tags the Application Passwords Classic Monks Pro mints for MCP. Shared with CM_MCP_Server and uninstall.php.
const CM_MCP_APP_ID = '285c2739-211b-4f6f-98fb-121e7d24d97b';

const CM_MCP_ROUTE = '/classic-monks/v1/mcp';

/**
 * Whether the current request authenticated with an MCP Application Password or an MCP OAuth token.
 */
function cm_mcp_is_mcp_credential() {
	if ( class_exists( 'CM_MCP_OAuth' ) && CM_MCP_OAuth::current() ) {
		return true;
	}
	$uuid = rest_get_authenticated_app_password();
	if ( ! $uuid ) {
		return false;
	}
	$item = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
	return $item && CM_MCP_APP_ID === ( $item['app_id'] ?? '' );
}

/**
 * MCP passwords and OAuth tokens open the MCP endpoint only, and only while MCP is on.
 * Runs after auth and route matching, before any handler.
 */
function cm_mcp_confine_credentials( $response, $handler, $request ) {
	if ( null !== $response || ! cm_mcp_is_mcp_credential() ) {
		return $response;
	}
	// The route only exists while the module is on, so turning it off locks these credentials out everywhere.
	if ( CM_MCP_ROUTE === $request->get_route() ) {
		return $response;
	}
	return new WP_Error(
		'cm_mcp_scope',
		__( 'This MCP connection only works on the MCP endpoint. The rest of the REST API is off limits to it.', 'monks-central' ),
		array( 'status' => 403 )
	);
}
add_filter( 'rest_request_before_callbacks', 'cm_mcp_confine_credentials', 10, 3 );

/**
 * Core also opens Application Passwords to XML-RPC, and plugins can add more surfaces through
 * `application_password_is_api_request`. MCP passwords work on REST only, where the filter above confines them.
 */
function cm_mcp_block_xmlrpc( $error, $user, $item ) {
	if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && CM_MCP_APP_ID === ( $item['app_id'] ?? '' ) ) {
		$error->add( 'cm_mcp_scope', __( 'MCP connections only work on the MCP endpoint.', 'monks-central' ) );
	}
}
add_action( 'wp_authenticate_application_password_errors', 'cm_mcp_block_xmlrpc', 10, 3 );

/**
 * Some hosts strip the Authorization header. Accept the same Basic value from
 * X-CM-Authorization on the MCP route. Runs before core's app-password check (20).
 */
function cm_mcp_auth_header_fallback( $user_id ) {
	if ( $user_id || ! empty( $_SERVER['PHP_AUTH_USER'] ) || empty( $_SERVER['HTTP_X_CM_AUTHORIZATION'] ) ) {
		return $user_id;
	}
	if ( defined( 'CM_MCP_DISABLE_AUTH_FALLBACK' ) && CM_MCP_DISABLE_AUTH_FALLBACK ) {
		return $user_id;
	}
	// Exact route match: a substring check let "?x=classic-monks/v1/mcp" enable this on any route.
	if ( ! class_exists( 'CM_MCP_OAuth' ) || ! CM_MCP_OAuth::targets_mcp() ) {
		return $user_id;
	}
	if ( ! preg_match( '#^Basic ([A-Za-z0-9+/]+={0,2})$#', trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CM_AUTHORIZATION'] ) ) ), $m ) ) {
		return $user_id;
	}
	$decoded = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic credentials
	if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
		return $user_id;
	}
	list( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) = explode( ':', $decoded, 2 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- writes the decoded credentials for core's Application Password check, reads nothing
	return $user_id;
}
add_filter( 'determine_current_user', 'cm_mcp_auth_header_fallback', 19 );

/**
 * A tool that echoes (notices, debug output) would corrupt the JSON-RPC body.
 */
function cm_mcp_buffer_start( $result, $server, $request ) {
	if ( CM_MCP_ROUTE === $request->get_route() ) {
		ob_start();
		$GLOBALS['cm_mcp_buffer_level'] = ob_get_level();
	}
	return $result;
}
add_filter( 'rest_pre_dispatch', 'cm_mcp_buffer_start', 10, 3 );

function cm_mcp_buffer_end( $response, $server, $request ) {
	if ( CM_MCP_ROUTE === $request->get_route() && ! empty( $GLOBALS['cm_mcp_buffer_level'] ) ) {
		// Unwind to below our own buffer, so one a tool left open can't leave ours (and its output) behind.
		$stray = '';
		while ( ob_get_level() >= $GLOBALS['cm_mcp_buffer_level'] ) {
			$chunk = ob_get_clean();
			if ( false === $chunk ) {
				break; // A buffer that can't be removed; leave it rather than loop forever.
			}
			$stray = $chunk . $stray;
		}
		$GLOBALS['cm_mcp_buffer_level'] = 0;
		if ( '' !== $stray && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Classic Monks MCP: discarded stray output: ' . substr( $stray, 0, 500 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
	return $response;
}
add_filter( 'rest_post_dispatch', 'cm_mcp_buffer_end', 10, 3 );

/**
 * Every MCP Application Password as [user_id, uuid]. App passwords are user meta, so network-wide on multisite.
 */
function cm_mcp_app_passwords() {
	$found = array();
	foreach ( get_users(
		array(
			'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one query over the users who have app passwords
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'blog_id'      => 0,
		)
	) as $user_id ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin screens and deactivation only; core has no index of users with Application Passwords
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( CM_MCP_APP_ID === ( $item['app_id'] ?? '' ) ) {
				$found[] = array( (int) $user_id, $item['uuid'] );
			}
		}
	}
	return $found;
}

/**
 * The current site's OAuth token table, or '' if MCP OAuth never created it.
 */
function cm_mcp_oauth_table() {
	global $wpdb;
	$table = $wpdb->prefix . 'cm_mcp_oauth_tokens';
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ? $table : ''; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existence check of the own token table, which Pro or an older Free may have left
}

/**
 * Once deactivated, this guard stops confining MCP passwords, so revoke every MCP connection.
 * Multisite: passwords and per-connection meta are network-wide user meta, and a connection
 * without scope meta would default to full, so OAuth tokens go on every site too.
 */
function cm_mcp_revoke_all_connections() {
	global $wpdb;
	foreach ( cm_mcp_app_passwords() as list( $user_id, $uuid ) ) {
		WP_Application_Passwords::delete_application_password( $user_id, $uuid );
	}
	foreach ( is_multisite() ? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) : array( 0 ) as $site_id ) {
		if ( $site_id ) {
			switch_to_blog( $site_id );
		}
		$table = cm_mcp_oauth_table();
		if ( $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
		}
		if ( class_exists( 'CM_MCP_Approvals' ) ) {
			CM_MCP_Approvals::forget_similar();
		}
		if ( $site_id ) {
			restore_current_blog();
		}
	}
	foreach ( array( 'cm_mcp_scopes', 'cm_mcp_toolsets', 'cm_mcp_trusted', 'cm_mcp_operator', 'cm_mcp_namespaces' ) as $key ) {
		delete_metadata( 'user', 0, $key, '', true );
	}
}

/**
 * Warn on the Plugins screen that deactivating disconnects the AI agents.
 * Only while one plugin bundles the connector: with another one active, connections stay.
 */
function cm_mcp_deactivation_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || count( monks_central_connector_copies() ) > 1 ) {
		return;
	}
	global $wpdb;
	$count = count( cm_mcp_app_passwords() );
	$table = cm_mcp_oauth_table();
	if ( $table ) {
		$count += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT family) FROM %i WHERE expires_at > %d', $table, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
	}
	if ( ! $count ) {
		return;
	}
	$message = sprintf(
		/* translators: 1: plugin name, 2: number of connections */
		_n(
			'Deactivating %1$s will disconnect %2$d AI agent (MCP) connection. It will need to be connected again afterwards.',
			'Deactivating %1$s will disconnect %2$d AI agent (MCP) connections. They will need to be connected again afterwards.',
			$count,
			'monks-central'
		),
		Monks_Central_Connector::host()['name'],
		$count
	);
	if ( is_multisite() ) {
		// cm_mcp_revoke_all_connections() is network-wide: app passwords are shared by every site.
		$message .= ' ' . __( 'This includes every AI agent connection on the other sites in this network.', 'monks-central' );
	}
	printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $message ) );
}
add_action(
	'all_admin_notices',
	function () {
		if ( in_array( get_current_screen()->id ?? '', array( 'plugins', 'plugins-network' ), true ) ) {
			cm_mcp_deactivation_notice();
		}
	}
);
