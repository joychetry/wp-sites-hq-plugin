<?php
/**
 * Connect to WP Sites HQ: abilities loader
 *
 * Loads the management abilities WP Sites HQ uses. Each file hooks
 * wp_abilities_api_init and registers one classic-monks/* ability, with the
 * same name, schema and annotations as in Classic Monks Pro.
 *
 * @package ClassicMonks
 * @subpackage MonksCentral
 * @requires WordPress 7.1+ (Abilities API)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wpshq_load_ability_files() {
	$abilities_dir = __DIR__ . '/abilities/';

	$files = array(
		// Helpers first: plugin-management-helpers.php is used by the plugin abilities and get-site-snapshot.php.
		'plugin-management-helpers.php',
		// Site status and updates
		'get-hq-status.php',
		'list-pending-approvals.php',
		'get-site-info.php',
		'get-environment-info.php',
		'list-updates.php',              // Every pending update in one call
		'update-translations.php',
		'get-site-snapshot.php',         // WP Sites HQ refresh in one call; uses list-updates.php
		'create-login-link.php',         // Operator grants only
		'list-ai-activity.php',          // The activity log, with can_undo
		'undo-operation.php',            // Undo one logged change through its ability's cm_undo_callback
		'verify-core-checksums.php',
		'verify-plugin-checksums.php',   // Uses plugin-management-helpers.php
		// Plugins
		'installed-plugin-list-and-infos.php',
		'search-plugins.php',
		'install-plugin.php',
		'install-zip.php',               // WP Sites HQ M8: chunked plugin or theme zip; uses upload-media.php's chunk helper
		'activate-plugin.php',
		'deactivate-plugin.php',
		'update-plugin.php',             // Its safe mode uses the M10 helpers below
		'get-http-status.php',           // WP Sites HQ M10: loopback check with the fatal error scrape
		'get-error-log-since.php',       // WP Sites HQ M10
		'set-maintenance-mode.php',      // WP Sites HQ M10
		'uninstall-plugin.php',
		'set-plugin-auto-update.php',
		// Themes (update-theme.php holds the theme helpers)
		'active-theme-info.php',
		'list-themes.php',
		'install-theme.php',
		'activate-theme.php',
		'update-theme.php',
		'delete-theme.php',
		// Core (get-core-version.php holds the core helpers)
		'get-core-version.php',
		'update-core.php',
		'update-core-db.php',
		// Content: posts, pages and any post type
		'get-posts.php',
		'get-post-by-id.php',
		'get-post-content.php',
		'get-post-meta.php',
		'create-posts.php',
		'update-posts.php',
		'update-post-metadata.php',
		'acf-fields.php',                // get-acf-fields, update-acf-fields; registers only when ACF or Secure Custom Fields is active
		'delete-post.php',
		'list-post-types.php',           // Every type the user can edit (M6)
		'list-revisions.php',            // Also registers restore-revision
		'untrash-post.php',
		// Terms
		'list-taxonomies.php',
		'get-terms.php',
		'get-tags.php',
		'create-terms.php',
		'update-term.php',
		'delete-term.php',
		'assign-terms.php',
		// Media
		'get-media.php',
		'import-media-from-url.php',     // Loads media-import-helpers.php
		'upload-media.php',              // Chunked upload (M6); loads media-import-helpers.php. Holds the chunk helper install-zip.php uses
		'update-media.php',              // Alt, title, caption, description. Holds wpshq_can_edit_media()
		'replace-media.php',             // Also restore-replaced-media; uses the chunk helper and wpshq_can_edit_media()
		// Users (update-user.php holds the user helpers)
		'get-users.php',
		'create-user.php',
		'update-user.php',
		'delete-user.php',
		'send-password-reset.php',       // WP Sites HQ M7
		// Comments (reply-to-comment.php defines wpshq_undo_reply_to_comment(), also used by create-comment.php)
		'get-comments.php',
		'moderate-comment.php',
		'reply-to-comment.php',
		'create-comment.php',
		'delete-comment.php',
	);

	foreach ( $files as $file ) {
		require_once $abilities_dir . $file;
	}
}

wpshq_load_ability_files();

/**
 * A MySQL datetime as RFC 3339, the form a `date-time` output schema promises: strict MCP clients
 * reject "2026-10-30 12:00:00". $gmt: the value is UTC (user_registered, *_gmt), else site time.
 */
function wpshq_ability_date( $mysql, $gmt = false ) {
	// PHP reads the zero date as -0001-11-30, which is not RFC 3339.
	$date = '0000-00-00 00:00:00' === $mysql ? false : date_create_immutable_from_format( 'Y-m-d H:i:s', (string) $mysql, $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone() );
	return $date ? $date->format( DATE_RFC3339 ) : '';
}

/**
 * Ability errors without an HTTP status reach core REST as 500s, which read as
 * server crashes to REST clients. Give classic-monks errors a 4xx.
 */
function wpshq_ability_error_status( $result, $ability_name ) {
	if ( ! is_wp_error( $result ) || 0 !== strpos( $ability_name, 'classic-monks/' ) ) {
		return $result;
	}
	$data = $result->get_error_data();
	if ( is_array( $data ) && isset( $data['status'] ) ) {
		return $result;
	}
	$code   = (string) $result->get_error_code();
	$status = 400;
	if ( preg_match( '/forbidden|permission|denied|disabled|locked/', $code ) ) {
		$status = 403;
	} elseif ( preg_match( '/not_found|missing/', $code ) ) {
		$status = 404;
	}
	$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => $status ) ) );
	return $result;
}
add_filter( 'wp_ability_execute_result', 'wpshq_ability_error_status', 1000, 2 );

/**
 * Operator-only writes run for the WP Sites HQ app's operator grant only. Over MCP other connections never
 * see them; this also closes core's ability REST routes (plain app passwords). Reads stay open there.
 */
function wpshq_block_operator_only_writes( $pre, $ability_name, $input, $ability ) {
	if ( is_wp_error( $pre ) || ! wp_is_serving_rest_request() || ! $ability || ! $ability->get_meta_item( 'cm_operator_only' ) ) {
		return $pre;
	}
	$annotations = (array) $ability->get_meta_item( 'annotations' );
	if ( ! empty( $annotations['readonly'] ) || ( function_exists( 'wpshq_is_operator_request' ) && wpshq_is_operator_request() ) ) {
		return $pre;
	}
	$message = __( 'Only the WP Sites HQ app can use this tool.', 'wp-sites-hq' );
	if ( class_exists( 'WPSHQ_AI_Activity_Log' ) ) {
		WPSHQ_AI_Activity_Log::mark( $ability_name, 'denied', $message );
	}
	return new WP_Error( 'cm_operator_only', $message, array( 'status' => 403 ) );
}
add_filter( 'wp_pre_execute_ability', 'wpshq_block_operator_only_writes', 15, 4 );

/**
 * GET/DELETE runs carry input in the query string, which cannot express `{}`, so a
 * no-argument call arrives as null and fails "input is not of type object". Treat a
 * missing input as an empty object: a null can never pass an object schema anyway.
 */
function wpshq_default_ability_input( $input, $ability_name, $ability ) {
	if ( null !== $input ) {
		return $input;
	}
	$schema = $ability->get_input_schema();
	return ( $schema['type'] ?? '' ) === 'object' ? array() : $input;
}
add_filter( 'wp_ability_normalize_input', 'wpshq_default_ability_input', 10, 3 );
