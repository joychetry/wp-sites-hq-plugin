<?php
/**
 * Media import hardening shared by the AI image import abilities.
 *
 * - Remote downloads resolve the host's A/AAAA records, refuse any private,
 *   reserved, loopback or link-local address, and pin the checked IP for the
 *   request (CURLOPT_RESOLVE) so DNS rebinding cannot swap it. Redirects are
 *   followed by hand so every hop gets the same check.
 * - The file extension comes from the sniffed content, never the URL or the
 *   declared type; non-images and SVG are refused.
 *
 * @package ClassicMonks
 * @subpackage AI
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether an IP address is publicly routable (IPv4, IPv6 and IPv4-mapped IPv6).
 *
 * @param string $ip IP address.
 * @return bool
 */
function cm_ai_ip_is_public( $ip ) {
	$bin = @inet_pton( trim( (string) $ip, '[]' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup returns false, checked below
	if ( false === $bin ) {
		return false;
	}
	// ::ffff:a.b.c.d is checked as the IPv4 address it maps to.
	if ( 16 === strlen( $bin ) && 0 === strncmp( $bin, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) {
		$bin = substr( $bin, 12 );
	}

	$blocked = 4 === strlen( $bin )
		? array( '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3' )
		: array( '::/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8' );

	foreach ( $blocked as $cidr ) {
		list( $net, $bits ) = explode( '/', $cidr );
		$net                = inet_pton( $net );
		$bits               = (int) $bits;
		$bytes              = intdiv( $bits, 8 );
		$rem                = $bits % 8;
		if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			continue;
		}
		if ( $rem && ( ( ord( $bin[ $bytes ] ) ^ ord( $net[ $bytes ] ) ) & ( 0xff << ( 8 - $rem ) ) & 0xff ) ) {
			continue;
		}
		return false;
	}

	return true;
}

/**
 * Resolve a URL's host and return one IP to pin, refusing non-public hosts.
 *
 * @param string $url URL.
 * @return string|WP_Error IP address.
 */
function cm_ai_resolve_public_ip( $url ) {
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	$host   = trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' );

	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
		return new WP_Error( 'cm_ai_invalid_url', __( 'Only http(s) image URLs can be imported.', 'monks-central' ) );
	}

	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$ips = array( $host );
	} else {
		// DNS records plus the system resolver (which also reads /etc/hosts).
		$ips = array();
		foreach ( (array) @dns_get_record( $host, DNS_A | DNS_AAAA ) as $record ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup returns false, checked below
			$ips[] = $record['ip'] ?? $record['ipv6'] ?? '';
		}
		$ips = array_filter( array_unique( array_merge( $ips, (array) @gethostbynamel( $host ) ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup returns false, checked below
	}

	if ( empty( $ips ) ) {
		return new WP_Error( 'cm_ai_unresolvable_host', __( 'The image host could not be resolved.', 'monks-central' ) );
	}

	foreach ( $ips as $ip ) {
		if ( ! cm_ai_ip_is_public( $ip ) ) {
			return new WP_Error( 'cm_ai_private_host', __( 'The image URL points to a private or reserved network address and cannot be imported.', 'monks-central' ) );
		}
	}

	return reset( $ips );
}

/**
 * Download a remote file with the resolved IP pinned for each request and hop.
 *
 * @param string $url     URL.
 * @param int    $timeout Timeout in seconds.
 * @return string|WP_Error Path to the downloaded temp file.
 */
function cm_ai_safe_download( $url, $timeout = 30 ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	for ( $hop = 0; $hop <= 5; $hop++ ) {
		$ip = cm_ai_resolve_public_ip( $url );
		if ( is_wp_error( $ip ) ) {
			return $ip;
		}

		$host   = trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' );
		$port   = wp_parse_url( $url, PHP_URL_PORT ) ?: ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80 );
		$pin    = $host . ':' . $port . ':' . ( false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip );
		$pinned = false;
		$pinner = static function ( $handle, $args, $request_url ) use ( $url, $pin, &$pinned ) {
			if ( $request_url === $url ) {
				// Pins the vetted IP so DNS cannot rebind between the check and the fetch; wp_remote_get() has no option for it.
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $pin ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				$pinned = true;
			}
		};

		$tmp = wp_tempnam( 'cm-ai-import' );
		add_action( 'http_api_curl', $pinner, 10, 3 );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => wp_max_upload_size(),
			)
		);
		remove_action( 'http_api_curl', $pinner, 10 );

		if ( is_wp_error( $response ) || ! $pinned ) {
			wp_delete_file( $tmp );
			return is_wp_error( $response )
				? new WP_Error( 'download_failed', $response->get_error_message() )
				// Without cURL the IP cannot be pinned, so DNS rebinding cannot be ruled out.
				: new WP_Error( 'download_failed', __( 'The image could not be downloaded safely (the cURL HTTP transport is required).', 'monks-central' ) );
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( $code >= 300 && $code < 400 && $location ) {
			wp_delete_file( $tmp );
			$url = WP_Http::make_absolute_url( is_array( $location ) ? end( $location ) : $location, $url );
			continue;
		}

		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			/* translators: %d: HTTP status code */
			return new WP_Error( 'download_failed', sprintf( __( 'The image download failed with HTTP status %d.', 'monks-central' ), $code ) );
		}

		// limit_response_size truncates silently; a file that reached the cap is too large.
		if ( filesize( $tmp ) >= wp_max_upload_size() ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'cm_ai_file_too_large', __( 'The file is larger than the maximum upload size.', 'monks-central' ) );
		}

		return $tmp;
	}

	return new WP_Error( 'download_failed', __( 'The image URL redirected too many times.', 'monks-central' ) );
}

/**
 * Identify an image file by its content and prepare it for the media library.
 *
 * Refuses SVG, anything that is not an image, and types the site does not allow.
 *
 * @param string $path Temp file path.
 * @param string $stem Filename without extension.
 * @return array|WP_Error { name, tmp_name, type } for media_handle_sideload().
 */
function cm_ai_prepare_image_file( $path, $stem ) {
	$mime = wp_get_image_mime( $path );

	if ( ! $mime ) {
		$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file
		// Root element must be <svg>: only a BOM, XML declaration, comments and a DOCTYPE may come first.
		if ( preg_match( '/^(?:\xEF\xBB\xBF)?\s*(?:(?:<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>\[]*(?:\[.*?\])?\s*>)\s*)*<svg[\s>\/]/is', $head ) ) {
			$mime = 'image/svg+xml';
		}
	}

	// Free has no full SVG sanitizer (Pro does), so remote SVG is never imported.
	if ( 'image/svg+xml' === $mime ) {
		return new WP_Error( 'cm_ai_not_an_image', __( 'SVG files cannot be imported or uploaded here: Classic Monks Free has no SVG cleaner. Upload them in the Media Library instead.', 'monks-central' ) );
	}

	$extension = $mime ? wp_get_default_extension_for_mime_type( $mime ) : false;

	if ( ! $extension || ! in_array( $mime, get_allowed_mime_types(), true ) ) {
		return new WP_Error( 'cm_ai_not_an_image', __( 'The file is not an image type this site accepts.', 'monks-central' ) );
	}

	$stem = sanitize_file_name( preg_replace( '/\.[a-z0-9]{2,5}$/i', '', (string) $stem ) );

	return array(
		'name'     => ( '' !== $stem ? $stem : 'image' ) . '.' . $extension,
		'tmp_name' => $path,
		'type'     => $mime,
	);
}

/**
 * Identify any file the site accepts: images as cm_ai_prepare_image_file() does, other types
 * (PDF, video, documents) by core's extension and content check.
 *
 * @param string $path Temp file path.
 * @param string $name File name as sent.
 * @return array|WP_Error { name, tmp_name, type } for media_handle_sideload().
 */
function cm_ai_prepare_media_file( $path, $name ) {
	$name  = sanitize_file_name( (string) $name );
	$check = wp_check_filetype_and_ext( $path, $name );
	if ( $check['type'] && 0 !== strpos( $check['type'], 'image/' ) ) {
		return array(
			'name'     => $check['proper_filename'] ? $check['proper_filename'] : $name,
			'tmp_name' => $path,
			'type'     => $check['type'],
		);
	}
	$image = cm_ai_prepare_image_file( $path, $name );
	if ( is_wp_error( $image ) && 'cm_ai_not_an_image' === $image->get_error_code() && 'image/svg+xml' !== wp_check_filetype( $name )['type'] ) {
		return new WP_Error( 'cm_ai_type_not_allowed', __( 'This file type is not allowed on this site, or the file does not match its extension.', 'monks-central' ) );
	}
	return $image;
}
