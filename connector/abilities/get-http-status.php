<?php
/**
 * Get HTTP Status Ability - WordPress Abilities API
 *
 * Loads a page of the site from the site itself and reports its status and any fatal
 * error, the way WordPress checks a plugin update for fatal errors (Monks Central M10).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_get_http_status_ability' );
function cm_register_get_http_status_ability() {
	wp_register_ability(
		'classic-monks/get-http-status',
		array(
			'label'               => __( 'Get HTTP Status', 'monks-central' ),
			'description'         => __( 'Loads a page of this site from the site itself (no cookies) and reports the HTTP status, the time it took and any PHP fatal error, even when the page hides it. Works while the site is in maintenance mode for an update.', 'monks-central' ),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path' => array(
						'type'        => 'string',
						'default'     => '/',
						'description' => 'Path on this site, starting with "/", e.g. "/" or "/shop/".',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'url'         => array( 'type' => 'string' ),
					'status'      => array( 'type' => 'integer' ),
					'ok'          => array( 'type' => 'boolean' ),
					'ms'          => array( 'type' => 'integer' ),
					'fatal'       => array( 'type' => array( 'string', 'null' ) ),
					'error'       => array( 'type' => array( 'string', 'null' ) ),
					'maintenance' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_http_status',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				// Monks Central only: keeps AI clients under their tool caps.
				'cm_operator_only' => true,
				'annotations'      => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest'     => true,
			),
		)
	);
}

function cm_execute_get_http_status( $input = array() ) {
	$path = isset( $input['path'] ) ? (string) $input['path'] : '/';
	// Only a path on this site: no scheme, no "//host", no backslash tricks, no whitespace.
	if ( ! preg_match( '#^/(?![/\\\\])[^\s\\\\]*$#', $path ) || false !== strpos( $path, '://' ) ) {
		return new WP_Error( 'cm_invalid_path', __( 'The path must start with a single "/" and be a path on this site, like "/" or "/shop/".', 'monks-central' ) );
	}
	return cm_ai_http_status( $path );
}

/**
 * GETs home_url( $path ) from the site with WordPress' fatal error scrape, like
 * WP_Automatic_Updater::has_fatal_error(). Shared with update-plugin's safe mode.
 *
 * @param string $path Path starting with "/".
 * @return array url, status, ok, ms, fatal, error, maintenance.
 */
function cm_ai_http_status( $path = '/' ) {
	$upgrading   = cm_ai_maintenance_upgrading();
	$maintenance = null !== $upgrading && ( time() - $upgrading ) < 10 * MINUTE_IN_SECONDS;
	// In maintenance mode only the key derived from $upgrading gets past wp_maintenance().
	if ( $maintenance ) {
		$key   = md5( $upgrading );
		$nonce = (string) $upgrading;
	} else {
		$key   = md5( wp_generate_password( 32, false ) );
		$nonce = wp_generate_password( 20, false );
	}
	set_transient( 'scrape_key_' . $key, $nonce, 60 );

	$url       = home_url( $path );
	$sslverify = apply_filters( 'https_local_ssl_verify', false, $url );
	$start     = microtime( true );
	$response  = wp_remote_get(
		add_query_arg(
			array(
				'wp_scrape_key'   => $key,
				'wp_scrape_nonce' => $nonce,
			),
			$url
		),
		array(
			'timeout'     => 30,
			'redirection' => 0,
			'sslverify'   => $sslverify,
			'cookies'     => array(),
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);
	$ms        = (int) round( ( microtime( true ) - $start ) * 1000 );
	delete_transient( 'scrape_key_' . $key );

	if ( is_wp_error( $response ) ) {
		return array(
			'url'         => $url,
			'status'      => 0,
			'ok'          => false,
			'ms'          => $ms,
			'fatal'       => null,
			'error'       => $response->get_error_message(),
			'maintenance' => $maintenance,
		);
	}

	// wp_finalize_scraping_edited_file_errors() prints the last fatal between these needles.
	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = (string) wp_remote_retrieve_body( $response );
	// cm_ai_print_scrape_fatal() prints it too, before shutdown callbacks can replace it in WordPress' copy.
	$fatal = null;
	foreach ( array( 'wp_scraping_result', 'cm_scrape_fatal' ) as $name ) {
		$needle = "###### {$name}_start:$key ######";
		$at     = strpos( $body, $needle );
		if ( null !== $fatal || false === $at ) {
			continue;
		}
		$result = substr( $body, $at + strlen( $needle ) );
		$result = json_decode( trim( substr( $result, 0, (int) strpos( $result, "###### {$name}_end:$key ######" ) ) ), true );
		if ( isset( $result['type'], $result['message'] ) ) {
			$fatal = cm_ai_describe_fatal( $result );
		}
	}

	return array(
		'url'         => $url,
		'status'      => $status,
		'ok'          => $status >= 200 && $status < 400 && null === $fatal,
		'ms'          => $ms,
		'fatal'       => $fatal,
		'error'       => null,
		'maintenance' => $maintenance,
	);
}

// In the loopback itself (WordPress checked the scrape key): shutdown callbacks run before WordPress prints
// error_get_last(), and a notice from one of them (WooCommerce, Elementor) replaces the fatal there.
if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
	add_action( 'shutdown', 'cm_ai_print_scrape_fatal', PHP_INT_MIN );
}

/**
 * Prints the request's fatal error between cm_scrape_fatal needles, as wp_finalize_scraping_edited_file_errors() does.
 */
function cm_ai_print_scrape_fatal() {
	$error = error_get_last();
	if ( ! $error || ! in_array( $error['type'], array( E_CORE_ERROR, E_COMPILE_ERROR, E_ERROR, E_PARSE, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
		return;
	}
	$key = substr( sanitize_key( wp_unslash( $_REQUEST['wp_scrape_key'] ?? '' ) ), 0, 32 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key only tags the fatal error output of core's own scrape loopback
	echo "\n###### cm_scrape_fatal_start:$key ######\n" . wp_json_encode( str_replace( ABSPATH, '', $error ) ) . "\n###### cm_scrape_fatal_end:$key ######\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for core's fatal error scrape, read by the loopback, never rendered
}

/**
 * $upgrading from ABSPATH/.maintenance (null without one), as wp_is_maintenance_mode() reads it.
 */
function cm_ai_maintenance_upgrading() {
	$file = ABSPATH . '.maintenance';
	if ( ! file_exists( $file ) ) {
		return null;
	}
	// OPcache revalidates against the request start time, so a long request (an update) can get an older copy.
	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $file, true );
	}
	$upgrading = null;
	include $file;
	return is_int( $upgrading ) ? $upgrading : null;
}

/**
 * The scrape's error_get_last() as PHP logs it: "PHP Fatal error: <message> in ABSPATH/<file> on line <n>".
 */
function cm_ai_describe_fatal( array $error ) {
	$label   = E_PARSE === (int) $error['type'] ? 'PHP Parse error' : 'PHP Fatal error';
	$message = strtok( str_replace( ABSPATH, 'ABSPATH/', (string) $error['message'] ), "\n" );
	// Uncaught exceptions end their first line with " in <file>:<line>"; the file and line follow anyway.
	$message = preg_replace( '/ in \S+:\d+$/', '', (string) $message );
	$file    = (string) ( $error['file'] ?? '' );
	if ( '' !== $file ) {
		// The scrape already strips ABSPATH, leaving a relative path.
		$file     = 0 === strpos( $file, '/' ) ? str_replace( ABSPATH, 'ABSPATH/', $file ) : 'ABSPATH/' . $file;
		$message .= ' in ' . $file . ' on line ' . (int) ( $error['line'] ?? 0 );
	}
	$text = $label . ': ' . $message;
	return class_exists( 'CM_AI_Activity_Log' ) ? CM_AI_Activity_Log::redact( $text ) : $text;
}
