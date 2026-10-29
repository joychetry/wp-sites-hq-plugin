<?php
/**
 * MCP OAuth 2.1 authorization server
 *
 * Lets remote connectors (claude.ai, ChatGPT, Claude Code, Cursor) sign in to
 * the MCP endpoint per user, without an Application Password:
 * RFC 9728 resource metadata, RFC 8414 server metadata, authorization code +
 * PKCE (S256 only), RFC 8707 resource binding, RFC 9207 iss, rotating refresh
 * tokens with reuse detection, Client ID Metadata Documents and RFC 7591 DCR.
 *
 * Tokens are opaque, stored as sha256 hashes, and only authenticate requests to
 * the MCP route. Each grant ("family") is an MCP connection with id
 * "oauth:<family>", so scopes, toolsets and trust live in the same user meta as
 * Application Password connections.
 *
 * Loaded by class-mcp-server.php, so none of it exists while MCP is off.
 *
 * @package ClassicMonks
 * @subpackage MonksCentral
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CM_MCP_OAuth {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'cm_mcp_oauth_db_version';
	const PAGE              = 'cm-mcp-authorize';
	const ID_PREFIX         = 'oauth:';
	const ACCESS_TTL        = HOUR_IN_SECONDS;
	const REFRESH_TTL       = 30 * DAY_IN_SECONDS;
	const CODE_TTL          = 60;
	const REPLAY_GRACE      = 60;
	const CAPABILITY        = 'edit_posts';

	/** @var object|null Access-token row behind the current request. */
	private static $current = null;

	/** @var bool A bearer token reached the MCP route but was not valid. */
	private static $bearer_failed = false;

	public static function init() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
		// After core's cookie (10) and Application Password (20) resolvers.
		add_filter( 'determine_current_user', array( __CLASS__, 'resolve_bearer' ), 30 );
		add_action( 'parse_request', array( __CLASS__, 'serve_well_known' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'challenge' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_cm_mcp_oauth_decide', array( __CLASS__, 'handle_decision' ) );
		add_action( 'deleted_user', array( __CLASS__, 'revoke_user' ) );
		// A new password signs the user out of connected apps too.
		add_action( 'after_password_reset', array( __CLASS__, 'revoke_user' ) );
		add_action( 'profile_update', array( __CLASS__, 'revoke_on_password_change' ), 10, 3 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cm_mcp_oauth_tokens';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// client_key is sha256(client_id): DCR ids carry their metadata and can be long.
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash char(64) NOT NULL,
            type varchar(10) NOT NULL,
            family char(32) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            client_key char(64) NOT NULL,
            client_name varchar(190) NOT NULL DEFAULT '',
            resource varchar(255) NOT NULL DEFAULT '',
            created_at bigint(20) unsigned NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            last_used bigint(20) unsigned NOT NULL DEFAULT 0,
            used tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY family (family),
            KEY expires_at (expires_at)
        ) " . $wpdb->get_charset_collate() . ';'
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/*
	------------------------------------------------------------------
	 * Identity of this server
	 * ------------------------------------------------------------------ */

	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/** The MCP endpoint URL is the protected resource (RFC 8707 audience). */
	public static function resource() {
		return CM_MCP_Server::endpoint_url();
	}

	public static function resource_metadata_url() {
		// A site in a subfolder never receives requests to the host root: point at its own path instead.
		if ( self::home_path() ) {
			return home_url( '/.well-known/oauth-protected-resource' );
		}
		$parts = wp_parse_url( self::resource() );
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $port . '/.well-known/oauth-protected-resource' . ( $parts['path'] ?? '' );
	}

	private static function endpoint( $name ) {
		return rest_url( CM_MCP_Server::REST_NAMESPACE . '/oauth/' . $name );
	}

	/** Lowercase scheme and host, no trailing slash, before comparing resource indicators. */
	private static function same_resource( $value ) {
		$normalize = function ( $url ) {
			$p = wp_parse_url( $url );
			if ( empty( $p['scheme'] ) || empty( $p['host'] ) || isset( $p['fragment'] ) ) {
				return null;
			}
			return strtolower( $p['scheme'] . '://' . $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' )
				. untrailingslashit( $p['path'] ?? '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		};
		$given     = $normalize( (string) $value );
		return null !== $given && $given === $normalize( self::resource() );
	}

	/*
	------------------------------------------------------------------
	 * Discovery (/.well-known/* live at the site root, outside /wp-json)
	 * ------------------------------------------------------------------ */

	/** Path of a site that lives in a subfolder (e.g. "/blog"), or "". */
	private static function home_path() {
		return untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	}

	public static function serve_well_known() {
		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		$home = self::home_path();
		if ( $home && 0 === strpos( $path, $home . '/.well-known/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		if ( 0 !== strpos( $path, '/.well-known/oauth-' ) ) {
			return;
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			$doc = array(
				'resource'                 => self::resource(),
				'authorization_servers'    => array( self::issuer() ),
				'bearer_methods_supported' => array( 'header' ),
				'resource_name'            => get_bloginfo( 'name' ),
			);
		} elseif ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) ) {
			$doc = self::server_metadata();
		} else {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function server_metadata() {
		return array(
			'issuer'                                     => self::issuer(),
			'authorization_endpoint'                     => self::endpoint( 'authorize' ),
			'token_endpoint'                             => self::endpoint( 'token' ),
			'registration_endpoint'                      => self::endpoint( 'register' ),
			'revocation_endpoint'                        => self::endpoint( 'revoke' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'response_types_supported'                   => array( 'code' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			// Claude only uses CIMD when "none" is listed: its CIMD client is public.
			'token_endpoint_auth_methods_supported'      => array( 'none' ),
			'client_id_metadata_document_supported'      => true,
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	/*
	------------------------------------------------------------------
	 * REST routes
	 * ------------------------------------------------------------------ */

	public static function register_routes() {
		$ns = CM_MCP_Server::REST_NAMESPACE;
		register_rest_route(
			$ns,
			'/oauth/authorize',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_authorize' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_revoke' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The consent screen needs the cookie session, which REST drops without a nonce,
	 * so the public endpoint forwards the request to a wp-admin page (login first if needed).
	 */
	public static function rest_authorize( WP_REST_Request $request ) {
		$known  = array_flip( array( 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'resource', 'scope' ) );
		$params = array_map( 'rawurlencode', array_filter( array_intersect_key( $request->get_query_params(), $known ), 'is_string' ) );
		$url    = add_query_arg( $params, admin_url( 'admin.php?page=' . self::PAGE ) );
		return new WP_REST_Response(
			null,
			302,
			array(
				'Location'      => $url,
				'Cache-Control' => 'no-store',
			)
		);
	}

	private static function oauth_error( $error, $description, $status = 400 ) {
		return new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status,
			array(
				'Cache-Control' => 'no-store',
				'Pragma'        => 'no-cache',
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Clients: Client ID Metadata Documents, or stateless DCR
	 * ------------------------------------------------------------------ */

	/**
	 * RFC 7591. Nothing is stored: the client_id carries its own metadata, signed,
	 * so open registration can't fill the database.
	 */
	public static function rest_register( WP_REST_Request $request ) {
		$meta = (array) $request->get_json_params();
		$uris = array_values( array_filter( (array) ( $meta['redirect_uris'] ?? array() ), 'is_string' ) );

		if ( ! $uris || count( $uris ) > 5 ) {
			return self::oauth_error( 'invalid_redirect_uri', 'Send between one and five redirect_uris.' );
		}
		foreach ( $uris as $uri ) {
			if ( ! self::valid_redirect_uri( $uri ) ) {
				return self::oauth_error( 'invalid_redirect_uri', sprintf( 'Redirect URI not allowed: %s. Use https, or http on localhost/127.0.0.1.', $uri ) );
			}
		}
		foreach ( (array) ( $meta['grant_types'] ?? array() ) as $grant ) {
			if ( ! in_array( $grant, array( 'authorization_code', 'refresh_token' ), true ) ) {
				return self::oauth_error( 'invalid_client_metadata', 'Only authorization_code and refresh_token are supported.' );
			}
		}

		$name    = substr( sanitize_text_field( (string) ( $meta['client_name'] ?? '' ) ), 0, 100 );
		$payload = self::b64url(
			(string) wp_json_encode(
				array(
					'n' => $name,
					'r' => $uris,
					't' => time(),
				),
				JSON_UNESCAPED_SLASHES
			)
		);
		$id      = 'cmc_' . $payload . '.' . self::sign( $payload );

		return new WP_REST_Response(
			array(
				'client_id'                  => $id,
				'client_id_issued_at'        => time(),
				'client_name'                => $name,
				'redirect_uris'              => $uris,
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			),
			201,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	private static function b64url( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url PKCE and token encoding
	}

	private static function sign( $payload ) {
		return self::b64url( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'cm_mcp_oauth_dcr', true ) );
	}

	/**
	 * @return array{name:string, redirect_uris:string[], verified_host:string}|WP_Error
	 */
	public static function client( $client_id ) {
		$client_id = (string) $client_id;

		if ( 0 === strpos( $client_id, 'cmc_' ) ) {
			$parts = explode( '.', substr( $client_id, 4 ), 2 );
			if ( 2 !== count( $parts ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
				return new WP_Error( 'invalid_client', __( 'Unknown client. Ask the app to connect again.', 'monks-central' ) );
			}
			$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64url PKCE and token encoding
			return array(
				'name'          => (string) ( $data['n'] ?? '' ),
				'redirect_uris' => (array) ( $data['r'] ?? array() ),
				'verified_host' => '',
			);
		}

		if ( 0 === strpos( $client_id, 'https://' ) ) {
			return self::fetch_metadata_document( $client_id );
		}

		return new WP_Error( 'invalid_client', __( 'Unknown client. Ask the app to connect again.', 'monks-central' ) );
	}

	/**
	 * CIMD: the client_id is an https URL serving the client's metadata.
	 */
	private static function fetch_metadata_document( $url ) {
		$parts = wp_parse_url( $url );
		if ( strlen( $url ) > 512 || empty( $parts['host'] ) || empty( $parts['path'] ) || '/' === $parts['path'] || isset( $parts['fragment'] ) || isset( $parts['user'] ) ) {
			return new WP_Error( 'invalid_client', __( 'The client ID is not a valid metadata document URL.', 'monks-central' ) );
		}

		$key    = 'cm_mcp_oauth_cimd_' . hash( 'sha256', $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return isset( $cached['error'] ) ? new WP_Error( 'invalid_client', $cached['error'] ) : $cached;
		}

		// wp_safe_remote_get refuses private and loopback addresses (SSRF).
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 16 * KB_IN_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		$doc      = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( wp_remote_retrieve_body( $response ), true );

		$error = null;
		if ( ! is_array( $doc ) ) {
			$error = __( 'Could not read the client metadata document.', 'monks-central' );
		} elseif ( ( $doc['client_id'] ?? '' ) !== $url ) {
			$error = __( 'The client metadata document does not name itself as this client.', 'monks-central' );
		} else {
			$uris = array_values( array_filter( (array) ( $doc['redirect_uris'] ?? array() ), array( __CLASS__, 'valid_redirect_uri' ) ) );
			if ( ! $uris ) {
				$error = __( 'The client metadata document lists no usable redirect URIs.', 'monks-central' );
			}
		}

		if ( $error ) {
			// Short negative cache: a failing URL can't make us fetch it on every request.
			set_transient( $key, array( 'error' => $error ), 5 * MINUTE_IN_SECONDS );
			return new WP_Error( 'invalid_client', $error );
		}

		// shortcut: fixed one-hour cache, ignoring the document's HTTP cache headers.
		$client = array(
			'name'          => substr( sanitize_text_field( (string) ( $doc['client_name'] ?? $parts['host'] ) ), 0, 100 ),
			'redirect_uris' => $uris,
			'verified_host' => strtolower( $parts['host'] ),
		);
		set_transient( $key, $client, HOUR_IN_SECONDS );
		return $client;
	}

	/**
	 * https anywhere; plain http only on loopback; private-use app schemes (cursor://) for native clients.
	 */
	public static function valid_redirect_uri( $uri ) {
		$p = wp_parse_url( (string) $uri );
		if ( ! $p || empty( $p['scheme'] ) || isset( $p['fragment'] ) || isset( $p['user'] ) || strlen( $uri ) > 500 ) {
			return false;
		}
		$scheme = strtolower( $p['scheme'] );
		if ( 'https' === $scheme ) {
			return ! empty( $p['host'] );
		}
		if ( 'http' === $scheme ) {
			return self::is_loopback( $p['host'] ?? '' );
		}
		// Native apps' own schemes (cursor://, vscode://). Never ones that run or fetch things on the computer.
		$blocked = array( 'javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss', 'mailto', 'tel', 'sms', 'intent', 'search', 'search-ms', 'shell', 'smb', 'nfs', 'jar', 'res', 'view-source', 'chrome', 'resource' );
		return (bool) preg_match( '/^[a-z][a-z0-9+.-]*$/', $scheme ) && ! in_array( $scheme, $blocked, true ) && 0 !== strpos( $scheme, 'ms-' ) && false !== strpos( $uri, '://' );
	}

	/**
	 * Operator grants (M2) go only to an app on this computer's loopback, as Monks Central is.
	 * A web client that names itself "Monks Central" can't be ticked as operator.
	 */
	public static function operator_possible( $redirect_uri ) {
		$u = wp_parse_url( (string) $redirect_uri );
		return is_array( $u ) && 'http' === strtolower( $u['scheme'] ?? '' ) && self::is_loopback( $u['host'] ?? '' );
	}

	private static function is_loopback( $host ) {
		return in_array( strtolower( trim( $host, '[]' ) ), array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Exact match, except loopback URIs ignore the port (RFC 8252 7.3; native clients use ephemeral ports).
	 */
	public static function redirect_allowed( array $registered, $uri ) {
		if ( in_array( $uri, $registered, true ) ) {
			return true;
		}
		$u = wp_parse_url( $uri );
		if ( ! $u || 'http' !== strtolower( $u['scheme'] ?? '' ) || ! self::is_loopback( $u['host'] ?? '' ) ) {
			return false;
		}
		foreach ( $registered as $reg ) {
			$r = wp_parse_url( $reg );
			if ( $r && 'http' === strtolower( $r['scheme'] ?? '' ) && strtolower( $r['host'] ?? '' ) === strtolower( $u['host'] )
				&& ( $r['path'] ?? '' ) === ( $u['path'] ?? '' ) && ( $r['query'] ?? '' ) === ( $u['query'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/*
	------------------------------------------------------------------
	 * Authorization request + consent screen
	 * ------------------------------------------------------------------ */

	/**
	 * Every error renders on the consent page, never redirects: with open DCR and CIMD any
	 * https URI can be registered, so redirecting errors would make this an open redirector
	 * (RFC 9700 4.11.2, OAuth 2.1 7.12.2). Only the user's own Deny goes back to the client.
	 *
	 * @return array|WP_Error
	 */
	/**
	 * A request field as a string: an array (client_id[]=x) reads as empty, never as "Array" with a warning.
	 */
	private static function param( WP_REST_Request $request, $key ) {
		$v = $request->get_param( $key );
		return is_string( $v ) ? $v : '';
	}

	private static function validate_request( array $q ) {
		// Only strings: array fields (state[]=x) would warn on every cast below.
		$q         = array_filter( $q, 'is_string' );
		$client_id = (string) ( $q['client_id'] ?? '' );
		$client    = self::client( $client_id );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$redirect = (string) ( $q['redirect_uri'] ?? '' );
		if ( '' === $redirect || ! self::redirect_allowed( $client['redirect_uris'], $redirect ) ) {
			return new WP_Error( 'invalid_request', __( 'This app asked to return to an address it did not register, so the request was stopped.', 'monks-central' ) );
		}

		$ctx = array(
			'client_id'      => $client_id,
			'client'         => $client,
			'redirect_uri'   => $redirect,
			'state'          => isset( $q['state'] ) ? (string) $q['state'] : null,
			'code_challenge' => (string) ( $q['code_challenge'] ?? '' ),
			'resource'       => self::resource(),
		);
		if ( 'code' !== ( $q['response_type'] ?? '' ) ) {
			return new WP_Error( 'unsupported_response_type', __( 'This app asked for a sign-in type this site does not support (only response_type=code).', 'monks-central' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $ctx['code_challenge'] ) || 'S256' !== ( $q['code_challenge_method'] ?? '' ) ) {
			return new WP_Error( 'invalid_request', __( 'This app did not use PKCE with code_challenge_method=S256, which this site requires.', 'monks-central' ) );
		}
		if ( isset( $q['resource'] ) && ! self::same_resource( $q['resource'] ) ) {
			return new WP_Error( 'invalid_target', __( 'This app asked for access to a different server than this site\'s MCP endpoint.', 'monks-central' ) );
		}
		return $ctx;
	}

	private static function redirect_with( array $ctx, array $params ) {
		if ( null !== $ctx['state'] ) {
			$params['state'] = $ctx['state'];
		}
		$params['iss'] = self::issuer();
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $params ), $ctx['redirect_uri'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated against the client's registered URIs
		exit;
	}

	public static function register_page() {
		$hook = add_submenu_page( '', __( 'Connect an AI app', 'monks-central' ), '', 'read', self::PAGE, '__return_null' );
		add_action( 'load-' . $hook, array( __CLASS__, 'render_consent' ) );
	}

	private static function can_consent() {
		// shortcut: Contributors and up only; readers have nothing for an agent to do.
		return current_user_can( self::CAPABILITY );
	}

	/** The consent nonce covers every parameter the code gets bound to. */
	public static function nonce_action( array $q ) {
		$q     = array_filter( $q, 'is_string' );
		$bound = array();
		foreach ( array( 'client_id', 'redirect_uri', 'code_challenge', 'state', 'resource' ) as $k ) {
			$bound[] = (string) ( $q[ $k ] ?? '' );
		}
		return 'cm_mcp_oauth_' . hash( 'sha256', implode( "\n", $bound ) );
	}

	public static function render_consent() {
		// Capability first, so a subscriber can't make the site fetch metadata URLs.
		$ctx = self::can_consent()
			? self::validate_request( wp_unslash( $_GET ) ) // phpcs:ignore WordPress.Security.NonceVerification -- read-only render; the decision is nonce-checked
			: new WP_Error( 'access_denied', __( 'Your account cannot connect AI apps to this site. Sign in as an author, editor or administrator.', 'monks-central' ) );

		$ui = Monks_Central_Connector::ui();
		if ( ! $ui ) {
			wp_die( esc_html__( 'The plugin running the connection has no consent page.', 'monks-central' ), '', 500 );
		}
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( 'Content-Security-Policy: frame-ancestors \'none\'' );
		$ui->render_consent( self::consent_view( $ctx, wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only render; the decision is nonce-checked
		exit;
	}

	/**
	 * What the host's consent page shows and posts.
	 *
	 * @param array|WP_Error $ctx Validated authorization request, or why it can't proceed.
	 * @param array          $q   The request's query.
	 * @return array See Monks_Central_Connector_UI::render_consent().
	 */
	private static function consent_view( $ctx, array $q ) {
		$view = array(
			'error' => is_wp_error( $ctx ) ? $ctx->get_error_message() : null,
			'site'  => get_bloginfo( 'name' ),
			'user'  => wp_get_current_user()->user_login,
		);
		if ( is_wp_error( $ctx ) ) {
			return $view;
		}
		$client = $ctx['client'];
		$host   = (string) wp_parse_url( $ctx['redirect_uri'], PHP_URL_HOST );
		$scheme = strtolower( (string) wp_parse_url( $ctx['redirect_uri'], PHP_URL_SCHEME ) );
		$local  = 'http' === $scheme;
		$app    = ! in_array( $scheme, array( 'http', 'https' ), true );
		$fields = array( 'action' => 'cm_mcp_oauth_decide' );
		foreach ( array( 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'resource', 'response_type' ) as $key ) {
			if ( isset( $q[ $key ] ) && is_string( $q[ $key ] ) ) {
				$fields[ $key ] = $q[ $key ];
			}
		}
		$fields['_wpnonce'] = wp_create_nonce( self::nonce_action( $q ) );
		$scopes             = array();
		foreach ( CM_MCP_Server::scopes() as $key => $label ) {
			$scopes[ $key ] = array(
				'label'       => $label,
				'description' => CM_MCP_Server::scope_descriptions()[ $key ] ?? '',
			);
		}
		$warning = '';
		if ( $local ) {
			$warning = __( 'This app returns to a program on your own computer. Only continue if you just started this connection yourself, from an app you trust.', 'monks-central' );
		} elseif ( $app ) {
			$warning = __( 'This app returns to an app installed on your computer. Only continue if you just started this connection yourself.', 'monks-central' );
		}
		return $view + array(
			'app'              => $client['name'] ? $client['name'] : ( $host ? $host : $scheme ),
			'verified_host'    => (string) $client['verified_host'],
			'returns_to'       => $app ? $scheme . '://' . $host : $host,
			'warning'          => $warning,
			'action'           => admin_url( 'admin-post.php' ),
			'fields'           => $fields,
			'scopes'           => $scopes,
			'default_scope'    => 'content',
			'toolsets'         => CM_MCP_Server::toolsets(),
			'default_toolsets' => CM_MCP_Server::DEFAULT_TOOLSETS,
			'operator'         => current_user_can( 'manage_options' ) && self::operator_possible( $ctx['redirect_uri'] ),
		);
	}

	public static function handle_decision() {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'This connection request is no longer valid. Start again from the app.', 'monks-central' ), '', 405 );
		}
		$q = wp_unslash( $_POST );
		check_admin_referer( self::nonce_action( $q ) );

		$ctx = self::validate_request( $q );
		if ( is_wp_error( $ctx ) || ! self::can_consent() ) {
			wp_die( esc_html__( 'This connection request is no longer valid. Start again from the app.', 'monks-central' ) );
		}
		if ( 'approve' !== ( $q['decision'] ?? '' ) ) {
			self::redirect_with(
				$ctx,
				array(
					'error'             => 'access_denied',
					'error_description' => 'The user declined.',
				)
			);
		}

		$scope = sanitize_key( (string) ( $q['scope'] ?? '' ) );
		if ( ! isset( CM_MCP_Server::scopes()[ $scope ] ) ) {
			$scope = 'content';
		}
		$toolsets = CM_MCP_Server::sanitize_toolsets( $q['toolsets'] ?? array() );
		// Operator (Monks Central, M2): the human's own word on this page, never the client's self-chosen name.
		$operator = ! empty( $q['operator'] ) && current_user_can( 'manage_options' ) && self::operator_possible( $ctx['redirect_uri'] );
		if ( $operator ) {
			// The app manages the whole site, Site admin and HQ tools included.
			$scope    = 'full';
			$toolsets = array_keys( CM_MCP_Server::toolsets() );
		}
		$code = bin2hex( random_bytes( 32 ) );
		set_transient(
			'cm_mcp_oauth_code_' . hash( 'sha256', $code ),
			array(
				'client_key'     => hash( 'sha256', $ctx['client_id'] ),
				'client_name'    => $ctx['client']['name'] ? $ctx['client']['name'] : wp_parse_url( $ctx['redirect_uri'], PHP_URL_HOST ),
				'redirect_uri'   => $ctx['redirect_uri'],
				'code_challenge' => $ctx['code_challenge'],
				'resource'       => $ctx['resource'],
				'user_id'        => get_current_user_id(),
				'scope'          => $scope,
				'toolsets'       => $toolsets,
				'operator'       => $operator,
				'expires'        => time() + self::CODE_TTL,
			),
			self::CODE_TTL
		);

		self::redirect_with( $ctx, array( 'code' => $code ) );
	}

	/*
	------------------------------------------------------------------
	 * Token endpoint
	 * ------------------------------------------------------------------ */

	public static function rest_token( WP_REST_Request $request ) {
		// Same bar core sets for Application Passwords: https, or a local environment.
		if ( ! wp_is_application_passwords_supported() ) {
			return self::oauth_error( 'invalid_request', 'Tokens are only issued over HTTPS.' );
		}
		$resource = $request->get_param( 'resource' );
		if ( null !== $resource && ( ! is_string( $resource ) || ! self::same_resource( $resource ) ) ) {
			return self::oauth_error( 'invalid_target', 'The resource must be this site\'s MCP endpoint.' );
		}
		$client_key = hash( 'sha256', self::param( $request, 'client_id' ) );

		switch ( $request->get_param( 'grant_type' ) ) {
			case 'authorization_code':
				return self::exchange_code( $request, $client_key );
			case 'refresh_token':
				return self::refresh( self::param( $request, 'refresh_token' ), $client_key );
		}
		return self::oauth_error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
	}

	/**
	 * RFC 7009. Revokes the token's whole grant (every access and refresh token of the family, and the
	 * connection's settings) when the token belongs to the calling client. Unknown, expired or another
	 * client's tokens still get 200, so the endpoint says nothing about which tokens exist.
	 */
	public static function rest_revoke( WP_REST_Request $request ) {
		// Same bar as the token endpoint.
		if ( ! wp_is_application_passwords_supported() ) {
			return self::oauth_error( 'invalid_request', 'Tokens are only revoked over HTTPS.' );
		}
		$token = self::param( $request, 'token' );
		if ( '' === $token ) {
			return self::oauth_error( 'invalid_request', 'The token parameter is required.' );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT family, client_key FROM %i WHERE token_hash = %s', self::table(), hash( 'sha256', $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
		if ( $row && hash_equals( $row->client_key, hash( 'sha256', self::param( $request, 'client_id' ) ) ) ) {
			self::revoke_family( $row->family );
		}
		return new WP_REST_Response(
			null,
			200,
			array(
				'Cache-Control' => 'no-store',
				'Pragma'        => 'no-cache',
			)
		);
	}

	private static function exchange_code( WP_REST_Request $request, $client_key ) {
		$key  = 'cm_mcp_oauth_code_' . hash( 'sha256', self::param( $request, 'code' ) );
		$data = get_transient( $key );
		// Single use: only the request that actually deletes the code may redeem it.
		if ( ! is_array( $data ) || ! delete_transient( $key ) || $data['expires'] < time() ) {
			return self::oauth_error( 'invalid_grant', 'The authorization code is invalid, expired or already used.' );
		}
		$verifier  = self::param( $request, 'code_verifier' );
		$challenge = self::b64url( hash( 'sha256', $verifier, true ) );
		if ( ! hash_equals( $data['client_key'], $client_key )
			|| self::param( $request, 'redirect_uri' ) !== $data['redirect_uri']
			|| ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier )
			|| ! hash_equals( $data['code_challenge'], $challenge ) ) {
			return self::oauth_error( 'invalid_grant', 'The code does not match this client, redirect_uri or code_verifier.' );
		}
		if ( ! user_can( $data['user_id'], self::CAPABILITY ) ) {
			return self::oauth_error( 'invalid_grant', 'The user can no longer connect AI apps.' );
		}

		$family = bin2hex( random_bytes( 16 ) );
		$id     = self::ID_PREFIX . $family;
		CM_MCP_Server::save_connection_settings( $data['user_id'], $id, $data['scope'], $data['toolsets'] );
		if ( ! empty( $data['operator'] ) ) {
			CM_MCP_Approvals::make_operator( $data['user_id'], $id );
		}
		return self::issue( $family, $data['user_id'], $data['client_key'], $data['client_name'], $data['scope'] );
	}

	private static function refresh( $token, $client_key ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE token_hash = %s AND type = 'refresh'", $table, hash( 'sha256', $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache

		if ( ! $row || (int) $row->expires_at < time() || ! hash_equals( $row->client_key, $client_key ) ) {
			return self::oauth_error( 'invalid_grant', 'The refresh token is invalid or expired.' );
		}
		// Rotation: the conditional update lets exactly one caller spend it. A second use means the token leaked,
		// unless it is a replay grace retry (the client lost the response).
		if ( 1 !== (int) $wpdb->update(
			$table,
			array(
				'used'      => 1,
				'last_used' => time(),
			),
			array(
				'id'   => $row->id,
				'used' => 0,
			)
		) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
			if ( ! self::replay_grace( (int) $row->id, $row->family ) ) {
				self::revoke_family( $row->family );
				return self::oauth_error( 'invalid_grant', 'This refresh token was already used, so the whole grant was revoked. Connect again.' );
			}
		}
		if ( ! user_can( (int) $row->user_id, self::CAPABILITY ) ) {
			return self::oauth_error( 'invalid_grant', 'The user can no longer connect AI apps.' );
		}
		$scope = CM_MCP_Server::connection_scope( (int) $row->user_id, self::ID_PREFIX . $row->family );
		return self::issue( $row->family, (int) $row->user_id, $row->client_key, $row->client_name, $scope );
	}

	/**
	 * A spent refresh token sent again within REPLAY_GRACE seconds of its rotation, while nothing the rotation
	 * issued has been used yet, means the client never got the response. The grace is claimed once (a second
	 * replay revokes), the lost access token is deleted and the lost refresh token is marked spent, so a thief
	 * who replayed first gets caught when the real client later presents it: that reuse revokes the family.
	 */
	private static function replay_grace( $id, $family ) {
		global $wpdb;
		$table = self::table();
		// A rotation still running hasn't inserted its tokens: wait for them (2 s at most). Granting now would
		// hand out a second live chain beside the one that rotation is about to insert. None came: no grace.
		$issued = "SELECT 1 FROM {$table} WHERE family = %s AND id > %d AND type = 'refresh' LIMIT 1";
		for ( $i = 0; ! $wpdb->get_var( $wpdb->prepare( $issued, $family, $id ) ); $i++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $issued is a fixed query string prepared here; own token table
			if ( $i >= 20 ) {
				return false;
			}
			usleep( 100000 );
		}
		// Rows of a family are inserted in order, so the rotation's tokens have higher ids.
		$used = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE family = %s AND id > %d AND ( used = 1 OR last_used > 0 ) LIMIT 1', $table, $family, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
		// Fresh read in the conditional update: a parallel replay or a still-running rotation can't both pass.
		if ( $used || 1 !== (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_used = 0 WHERE id = %d AND used = 1 AND last_used >= %d', $table, $id, time() - self::REPLAY_GRACE ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE family = %s AND id > %d AND type = 'access'", $table, $family, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET used = 1, last_used = 0 WHERE family = %s AND id > %d AND type = 'refresh'", $table, $family, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
		return true;
	}

	private static function issue( $family, $user_id, $client_key, $client_name, $scope ) {
		global $wpdb;
		$table = self::table();
		$now   = time();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d', $table, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache

		$tokens = array(
			'access'  => array( 'cmat_' . bin2hex( random_bytes( 32 ) ), self::ACCESS_TTL ),
			'refresh' => array( 'cmrt_' . bin2hex( random_bytes( 32 ) ), self::REFRESH_TTL ),
		);
		foreach ( $tokens as $type => list( $token, $ttl ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
			$wpdb->insert(
				$table,
				array(
					'token_hash'  => hash( 'sha256', $token ),
					'type'        => $type,
					'family'      => $family,
					'user_id'     => $user_id,
					'client_key'  => $client_key,
					'client_name' => substr( (string) $client_name, 0, 190 ),
					'resource'    => self::resource(),
					'created_at'  => $now,
					'expires_at'  => $now + $ttl,
				)
			);
		}

		return new WP_REST_Response(
			array(
				'access_token'  => $tokens['access'][0],
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $tokens['refresh'][0],
				'scope'         => $scope,
			),
			200,
			array(
				'Cache-Control' => 'no-store',
				'Pragma'        => 'no-cache',
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Bearer authentication (MCP route only)
	 * ------------------------------------------------------------------ */

	private static function bearer_token() {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_CM_AUTHORIZATION' ) as $header ) {
			if ( isset( $_SERVER[ $header ] ) && preg_match( '/^Bearer\s+(cmat_[a-f0-9]{64})$/i', trim( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ), $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/**
	 * Strict: the path must be exactly the MCP route (pretty or ?rest_route= on the front index),
	 * so a token can never sign anyone in to wp-admin or another endpoint.
	 * Also gates the X-CM-Authorization fallback (mcp-guard.php).
	 */
	public static function targets_mcp() {
		$uri   = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$path  = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$home  = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$route = CM_MCP_Server::REST_NAMESPACE . CM_MCP_Server::REST_ROUTE;
		$rest  = rest_get_url_prefix();

		// WP::parse_request lets a POST body rest_route override the path, so the path alone proves nothing.
		if ( isset( $_POST['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return is_string( $_GET['rest_route'] ) && in_array( $path, array( $home, $home . '/index.php' ), true ) && '/' . $route === untrailingslashit( $_GET['rest_route'] ); // phpcs:ignore
		}
		return in_array( $path, array( "$home/$rest/$route", "$home/index.php/$rest/$route" ), true );
	}

	public static function resolve_bearer( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$token = self::bearer_token();
		if ( '' === $token || ! self::targets_mcp() ) {
			return $user_id;
		}

		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE token_hash = %s AND type = 'access'", $table, hash( 'sha256', $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache

		// Audience and capability are checked in current(): rest_url() may not work this early.
		if ( ! $row || (int) $row->expires_at < time() || ! get_userdata( $row->user_id ) || ! wp_is_application_passwords_supported() ) {
			self::$bearer_failed = true;
			return $user_id;
		}
		if ( (int) $row->last_used < time() - MINUTE_IN_SECONDS ) {
			$wpdb->update( $table, array( 'last_used' => time() ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
		}
		self::$current = $row;
		return (int) $row->user_id;
	}

	/**
	 * The OAuth grant behind the current request. cm_mcp_confine_credentials() calls this
	 * before every REST handler, so a token refused here signs its user back out.
	 *
	 * @return array{id:string, user_id:int, label:string}|null
	 */
	public static function current() {
		if ( ! self::$current || get_current_user_id() !== (int) self::$current->user_id ) {
			return null;
		}
		// RFC 8707 audience, and the user may have been demoted since consenting.
		if ( ! self::same_resource( self::$current->resource ) || ! user_can( (int) self::$current->user_id, self::CAPABILITY ) ) {
			self::$current       = null;
			self::$bearer_failed = true;
			wp_set_current_user( 0 );
			return null;
		}
		return array(
			'id'      => self::ID_PREFIX . self::$current->family,
			'user_id' => (int) self::$current->user_id,
			'label'   => self::$current->client_name,
		);
	}

	/**
	 * 401s from the MCP route tell OAuth clients where to start (RFC 9728 5.1).
	 */
	public static function challenge( $response, $server, $request ) {
		if ( CM_MCP_ROUTE === $request->get_route() && $response instanceof WP_HTTP_Response && 401 === $response->get_status() ) {
			$value = sprintf( 'Bearer resource_metadata="%s"', self::resource_metadata_url() );
			if ( self::$bearer_failed ) {
				$value .= ', error="invalid_token", error_description="The access token is invalid or expired"';
			}
			$response->header( 'WWW-Authenticate', $value );
		}
		return $response;
	}

	public static function bearer_failed() {
		return self::$bearer_failed;
	}

	/*
	------------------------------------------------------------------
	 * Grants, for the Connections list
	 * ------------------------------------------------------------------ */

	public static function is_oauth_id( $id ) {
		return (bool) preg_match( '/^oauth:[a-f0-9]{32}$/', (string) $id );
	}

	public static function family_exists( $user_id, $id ) {
		global $wpdb;
		$table = self::table();
		return self::is_oauth_id( $id ) && (bool) $wpdb->get_var(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
				'SELECT 1 FROM %i WHERE family = %s AND user_id = %d AND expires_at > %d LIMIT 1',
				$table,
				substr( $id, strlen( self::ID_PREFIX ) ),
				$user_id,
				time()
			)
		);
	}

	/**
	 * @return object[] {family, user_id, client_name, created, last_used}
	 */
	public static function grants() {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
				'SELECT family, user_id, MAX(client_name) AS client_name, MIN(created_at) AS created, MAX(last_used) AS last_used FROM %i WHERE expires_at > %d GROUP BY family, user_id ORDER BY created',
				$table,
				time()
			)
		);
	}

	public static function revoke_family( $family ) {
		global $wpdb;
		$user_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i WHERE family = %s LIMIT 1', self::table(), $family ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
		$wpdb->delete( self::table(), array( 'family' => $family ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
		if ( $user_id ) {
			CM_MCP_Server::forget_connection( $user_id, self::ID_PREFIX . $family );
		}
	}

	/** @param int|WP_User $user */
	public static function revoke_user( $user ) {
		global $wpdb;
		$user_id = $user instanceof WP_User ? $user->ID : (int) $user;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT family FROM %i WHERE user_id = %d', self::table(), $user_id ) ) as $family ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own token table; tokens must never be read from a cache
			self::revoke_family( $family );
		}
	}

	public static function revoke_on_password_change( $user_id, $old_user_data, $userdata = array() ) {
		if ( isset( $userdata['user_pass'] ) && $old_user_data instanceof WP_User && $userdata['user_pass'] !== $old_user_data->user_pass ) {
			self::revoke_user( $user_id );
		}
	}
}

CM_MCP_OAuth::init();
