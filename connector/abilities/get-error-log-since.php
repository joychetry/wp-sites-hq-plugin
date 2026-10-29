<?php
/**
 * Get Error Log Since Ability - WordPress Abilities API
 *
 * Reads what the PHP error log gained after a marker, so a change can be checked for new
 * fatal errors and warnings (Monks Central M10).
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_get_error_log_since_ability' );
function cm_register_get_error_log_since_ability() {
	wp_register_ability(
		'classic-monks/get-error-log-since',
		array(
			'label'               => __( 'Get Error Log Since', 'monks-central' ),
			'description'         => __( 'Without offset: returns the size of the PHP error log as a marker. With offset (a marker from before): returns the lines written since then (at most 50, newest last) and how many are fatal errors and warnings. Secrets are redacted and the install path is shown as ABSPATH/.', 'monks-central' ),
			'category'            => 'system',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'offset' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'The offset a previous call returned.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'available' => array( 'type' => 'boolean' ),
					'offset'    => array( 'type' => 'integer' ),
					'lines'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'fatal'     => array( 'type' => 'integer' ),
					'warnings'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'cm_execute_get_error_log_since',
			'permission_callback' => function () {
				// Network-wide reach: super admin only on multisite, as read-debug-log.
				return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
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

function cm_execute_get_error_log_since( $input = array() ) {
	return cm_ai_error_log_since( isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : null );
}

/**
 * The PHP error log: WP_DEBUG_LOG when WordPress logs there, else PHP's error_log file. '' when there is none.
 */
function cm_ai_error_log_path() {
	// wp_debug_mode() honours WP_DEBUG_LOG only with WP_DEBUG on.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		$path = is_string( WP_DEBUG_LOG ) && ! in_array( strtolower( WP_DEBUG_LOG ), array( 'true', '1' ), true ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
		// Not written yet is fine: it starts empty.
		return ! file_exists( $path ) || ( is_file( $path ) && is_readable( $path ) ) ? $path : '';
	}
	$path = (string) ini_get( 'error_log' );
	return '' !== $path && is_file( $path ) && is_readable( $path ) ? $path : '';
}

/**
 * Lines the error log gained after $offset (null: only the marker). Shared with update-plugin's safe mode.
 *
 * @param int|null $offset Byte offset from an earlier call.
 * @return array available, offset (the size now), lines, fatal, warnings.
 */
function cm_ai_error_log_since( $offset = null ) {
	$out  = array(
		'available' => false,
		'offset'    => 0,
		'lines'     => array(),
		'fatal'     => 0,
		'warnings'  => 0,
	);
	$path = cm_ai_error_log_path();
	if ( '' === $path ) {
		return $out;
	}
	clearstatcache( true, $path );
	$size             = file_exists( $path ) ? (int) filesize( $path ) : 0;
	$out['available'] = true;
	$out['offset']    = $size;
	if ( null === $offset ) {
		return $out;
	}
	// Smaller than the marker: the log was rotated or cleared, so all of it is new.
	$from  = $offset > $size ? 0 : $offset;
	$bytes = 65536;
	$start = max( $from, $size - $bytes );
	if ( $size <= $start ) {
		return $out;
	}
	$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads a byte range of the PHP error log; WP_Filesystem has no seek
	if ( false === $handle ) {
		return $out;
	}
	fseek( $handle, $start );
	$content = (string) fread( $handle, $size - $start ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reads a byte range of the PHP error log; WP_Filesystem has no seek
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- reads a byte range of the PHP error log; WP_Filesystem has no seek

	$lines = explode( "\n", rtrim( $content, "\n" ) );
	if ( $start > $from ) {
		array_shift( $lines ); // Partial first line: more than 64 KB was written.
	}
	$lines = array_values( array_filter( $lines, 'strlen' ) );
	foreach ( $lines as $line ) {
		if ( false !== strpos( $line, 'PHP Fatal error' ) || false !== strpos( $line, 'PHP Parse error' ) ) {
			++$out['fatal'];
		} elseif ( false !== strpos( $line, 'PHP Warning' ) ) {
			++$out['warnings'];
		}
	}
	$lines = str_replace( ABSPATH, 'ABSPATH/', array_slice( $lines, -50 ) );
	if ( class_exists( 'CM_AI_Activity_Log' ) ) {
		$lines = CM_AI_Activity_Log::redact( $lines );
	}
	$out['lines'] = array_values( $lines );
	return $out;
}
