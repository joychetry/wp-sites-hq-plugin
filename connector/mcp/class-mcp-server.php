<?php
/**
 * Classic Monks MCP Server (Connect to Monks Central)
 *
 * Stateless MCP endpoint (Streamable HTTP, JSON responses only, no SSE) that
 * exposes the classic-monks/* abilities as tools. Monks Central (or another MCP
 * client) signs in over OAuth (class-mcp-oauth.php), so every connection acts as
 * a real WordPress user and every ability keeps its own permission check.
 * Ported from Classic Monks Pro; same names and data, so Pro takes over on upgrade.
 *
 * @package ClassicMonks
 * @subpackage MonksCentral
 * @requires WordPress 7.1+ (Abilities API, wp_pre_execute_ability)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CM_MCP_Server {

    const REST_NAMESPACE = 'classic-monks/v1';
    const REST_ROUTE     = '/mcp';

    // Tags the Application Passwords minted here; only these may call the endpoint.
    const APP_ID = CM_MCP_APP_ID;

    const ABILITY_PREFIX = 'classic-monks/';

    // User meta on the connection owner: app-password uuid => scope key.
    const SCOPES_META = 'cm_mcp_scopes';

    // Categories each scope adds on top of read-only tools. 'full' allows everything.
    const SCOPE_CATEGORIES = array(
        'read'    => array(),
        'content' => array( 'content', 'content-analysis', 'taxonomy', 'comments', 'media', 'seo', 'bricks', 'blocks' ),
        'site'    => array( 'content', 'content-analysis', 'taxonomy', 'comments', 'media', 'seo', 'bricks', 'blocks', 'system', 'classic-monks' ),
    );

    // User meta on the connection owner: app-password uuid => list of toolset keys.
    const TOOLSETS_META = 'cm_mcp_toolsets';

    // Ability category => toolset. Unmapped categories fall in 'modules'.
    const TOOLSET_OF_CATEGORY = array(
        'content'          => 'content',
        'content-analysis' => 'content',
        'taxonomy'         => 'content',
        'comments'         => 'content',
        'blocks'           => 'content',
        'media'            => 'media',
        'seo'              => 'seo',
        'bricks'           => 'bricks',
        'classic-monks'    => 'modules',
        'system'           => 'site',
        'users'            => 'site',
    );

    // New connections stay under client tool caps (VS Code: 128); Site admin is opt-in.
    const DEFAULT_TOOLSETS = array( 'content', 'media', 'modules' );

    // User meta on the connection owner: connection id => other plugins' ability namespaces it may use (R-9).
    const NAMESPACES_META = 'cm_mcp_namespaces';

    // Never offered as "other plugins": ours (toolsets cover them) and core's.
    const OWN_NAMESPACES = array( 'classic-monks', 'core' );

    // Stateless versions: each request carries its version and capabilities in _meta, no initialize.
    const MODERN_VERSIONS = array( '2026-07-28' );

    // Newest first: the first entry is offered when the client asks for an unknown version.
    const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

    // How long modern clients may cache tools/list. Private: the list depends on the connection's scope.
    const LIST_TTL_MS = 300000;

    /** @var bool Whether the current request uses a modern (2026-07-28+) protocol version. */
    private static $modern = false;

    /** @var array Client capabilities declared in the current modern request's _meta. */
    private static $client_caps = array();

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
        add_filter( 'wp_pre_execute_ability', array( __CLASS__, 'enforce_scope' ), 20, 4 );
    }

    /* ------------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------------ */

    public static function scopes() {
        return array(
            'read'    => __( 'Read only', 'classic-monks' ),
            'content' => __( 'Content', 'classic-monks' ),
            'site'    => __( 'Site admin', 'classic-monks' ),
            'full'    => __( 'Full', 'classic-monks' ),
        );
    }

    public static function scope_descriptions() {
        return array(
            'read'    => __( 'Can look but not change anything.', 'classic-monks' ),
            'content' => __( 'Posts, pages, media, terms and comments.', 'classic-monks' ),
            'site'    => __( 'Content, plus plugins, themes, updates and site info.', 'classic-monks' ),
            'full'    => __( 'Every enabled tool, including user tools.', 'classic-monks' ),
        );
    }

    public static function toolsets() {
        return array(
            'content' => __( 'Content', 'classic-monks' ),
            'media'   => __( 'Media', 'classic-monks' ),
            'modules' => __( 'Classic Monks status', 'classic-monks' ),
            'site'    => __( 'Site admin', 'classic-monks' ),
        );
    }

    /**
     * No saved toolsets (a password left by Classic Monks Pro, or a lost write) gets the defaults, never every group.
     */
    public static function connection_toolsets( $user_id, $uuid ) {
        $all = get_user_meta( $user_id, self::TOOLSETS_META, true );
        return is_array( $all ) && isset( $all[ $uuid ] ) ? (array) $all[ $uuid ] : self::DEFAULT_TOOLSETS;
    }

    private static function in_toolsets( WP_Ability $ability, array $toolsets ) {
        return in_array( self::TOOLSET_OF_CATEGORY[ $ability->get_category() ] ?? 'modules', $toolsets, true );
    }

    private static function current_toolsets() {
        $connection = self::current_connection();
        return $connection ? $connection['toolsets'] : array_keys( self::toolsets() );
    }

    public static function sanitize_toolsets( $raw ) {
        return array_values( array_intersect( array_map( 'sanitize_key', (array) $raw ), array_keys( self::toolsets() ) ) );
    }

    /**
     * Other plugins' namespaces this connection may use. None unless an admin allowed them.
     */
    public static function connection_namespaces( $user_id, $id ) {
        $all = get_user_meta( $user_id, self::NAMESPACES_META, true );
        return is_array( $all ) && isset( $all[ $id ] ) ? (array) $all[ $id ] : array();
    }

    private static function current_namespaces() {
        $connection = self::current_connection();
        return $connection ? $connection['namespaces'] : array();
    }

    /**
     * Other plugins' abilities that offer themselves to MCP (the MCP adapter's meta.mcp.public, or core's
     * meta.public) as tools, optionally limited to some namespaces.
     *
     * @param string[]|null $namespaces Null for every namespace.
     * @return array<string, WP_Ability>
     */
    public static function external_abilities( $namespaces = null ) {
        // Once per request: the registry is complete after the first wp_get_abilities() call.
        static $all = null;
        if ( array() === $namespaces || ! function_exists( 'wp_get_abilities' ) ) {
            return array();
        }
        if ( null === $all ) {
            $all = array();
            foreach ( wp_get_abilities() as $ability ) {
                $name = $ability->get_name();
                $mcp  = (array) $ability->get_meta_item( 'mcp', array() );
                if ( ! in_array( strtok( $name, '/' ), self::OWN_NAMESPACES, true ) && ( ! empty( $mcp['public'] ) || true === $ability->get_meta_item( 'public' ) ) && 'tool' === ( $mcp['type'] ?? 'tool' ) ) {
                    $all[ $name ] = $ability;
                }
            }
        }
        if ( null === $namespaces ) {
            return $all;
        }
        return array_filter( $all, function ( $name ) use ( $namespaces ) {
            return in_array( strtok( $name, '/' ), $namespaces, true );
        }, ARRAY_FILTER_USE_KEY );
    }

    /**
     * Namespaces an admin can allow, for the connections list.
     *
     * @return string[]
     */
    public static function available_namespaces() {
        $namespaces = array_unique( array_map( function ( $name ) {
            return strtok( $name, '/' );
        }, array_keys( self::external_abilities() ) ) );
        sort( $namespaces );
        return $namespaces;
    }

    public static function sanitize_namespaces( $raw ) {
        return array_values( array_intersect( array_map( 'sanitize_key', (array) $raw ), self::available_namespaces() ) );
    }

    /**
     * Annotations the trust layer acts on. Other plugins' abilities get safe defaults:
     * read-only only when they say so, destructive unless they say they are not.
     */
    public static function annotations( WP_Ability $ability ) {
        $annotations = (array) $ability->get_meta_item( 'annotations', array() );
        if ( 0 === strpos( $ability->get_name(), self::ABILITY_PREFIX ) ) {
            return $annotations;
        }
        $annotations['readonly']    = true === ( $annotations['readonly'] ?? null );
        $annotations['destructive'] = ! $annotations['readonly'] && false !== ( $annotations['destructive'] ?? null );
        return $annotations;
    }

    /**
     * MCP tool name: classic-monks/get-posts => get-posts; other plugins keep their namespace,
     * woocommerce/products-list => woocommerce_products-list. Ability names never contain "_".
     */
    private static function tool_name( $ability_name ) {
        return 0 === strpos( $ability_name, self::ABILITY_PREFIX ) ? substr( $ability_name, strlen( self::ABILITY_PREFIX ) ) : str_replace( '/', '_', $ability_name );
    }

    /**
     * No saved scope (a password left by Classic Monks Pro, or a lost write) is read-only, never full.
     */
    public static function connection_scope( $user_id, $uuid ) {
        $scopes = get_user_meta( $user_id, self::SCOPES_META, true );
        $scope  = is_array( $scopes ) ? ( $scopes[ $uuid ] ?? 'read' ) : 'read';
        return isset( self::scopes()[ $scope ] ) ? $scope : 'read';
    }

    private static function current_scope() {
        $connection = self::current_connection();
        return $connection ? $connection['scope'] : 'full';
    }

    /**
     * The MCP connection behind this request (D3): an MCP Application Password or an OAuth grant.
     * Scope, toolsets and trust are user meta keyed by the connection id (uuid, or "oauth:<family>").
     *
     * @return array{kind:string, id:string, user_id:int, label:string, scope:string, toolsets:string[], namespaces:string[], trusted:bool, operator:bool|null}|null
     */
    public static function current_connection() {
        $user_id = get_current_user_id();
        $oauth   = class_exists( 'CM_MCP_OAuth' ) ? CM_MCP_OAuth::current() : null;
        if ( $oauth ) {
            $kind  = 'oauth';
            $id    = $oauth['id'];
            $label = $oauth['label'];
        } else {
            $id   = (string) rest_get_authenticated_app_password();
            $item = $id ? WP_Application_Passwords::get_user_application_password( $user_id, $id ) : null;
            if ( ! $item || self::APP_ID !== ( $item['app_id'] ?? '' ) ) {
                return null;
            }
            $kind  = 'app_password';
            $label = $item['name'];
        }
        return array(
            'kind'     => $kind,
            'id'       => $id,
            'user_id'  => $user_id,
            'label'    => $label,
            'scope'    => self::connection_scope( $user_id, $id ),
            'toolsets' => self::connection_toolsets( $user_id, $id ),
            'namespaces' => self::connection_namespaces( $user_id, $id ),
            'trusted'  => CM_MCP_Approvals::is_trusted( $user_id, $id ),
            // Null unless an operator grant; then whether it also skips cm_always_approve.
            'operator' => CM_MCP_Approvals::operator( $user_id, $id ),
        );
    }

    /**
     * How many tools a connection lists, so the dashboard can flag ones past client caps (R-9).
     */
    private static function tool_count( $user_id, $id ) {
        return count( self::exposed_abilities( self::connection_scope( $user_id, $id ), self::connection_toolsets( $user_id, $id ), self::connection_namespaces( $user_id, $id ), null !== CM_MCP_Approvals::operator( $user_id, $id ) ) );
    }

    public static function connection_exists( $user_id, $id ) {
        return CM_MCP_OAuth::is_oauth_id( $id ) ? CM_MCP_OAuth::family_exists( $user_id, $id ) : self::is_mcp_password( $user_id, $id );
    }

    public static function save_connection_settings( $user_id, $id, $scope, array $toolsets ) {
        self::save_scope( $user_id, $id, $scope );
        self::save_toolsets( $user_id, $id, $toolsets );
    }

    /**
     * Drop a revoked connection's settings.
     */
    public static function forget_connection( $user_id, $id ) {
        self::save_scope( $user_id, $id, null );
        self::save_toolsets( $user_id, $id, null );
        self::save_namespaces( $user_id, $id, null );
        CM_MCP_Approvals::forget_similar( $id );
        CM_MCP_Approvals::save_flag( CM_MCP_Approvals::TRUSTED_META, $user_id, $id, null );
        CM_MCP_Approvals::save_flag( CM_MCP_Approvals::OPERATOR_META, $user_id, $id, null );
    }

    public static function scope_allows( $scope, WP_Ability $ability ) {
        if ( 'full' === $scope ) {
            return true;
        }
        $min   = $ability->get_meta_item( 'cm_min_scope' );
        $order = array_keys( self::scopes() );
        if ( $min && array_search( $scope, $order, true ) < array_search( $min, $order, true ) ) {
            return false;
        }
        $annotations = self::annotations( $ability );
        if ( ! empty( $annotations['readonly'] ) ) {
            return true;
        }
        // Other plugins' categories mean nothing to our scopes: below Full they get read-only tools only.
        if ( 0 !== strpos( $ability->get_name(), self::ABILITY_PREFIX ) ) {
            return false;
        }
        return in_array( $ability->get_category(), self::SCOPE_CATEGORIES[ $scope ] ?? array(), true );
    }

    /**
     * Runs for every caller; only MCP connections carry a scope.
     */
    public static function enforce_scope( $pre, $name, $input, $ability ) {
        if ( is_wp_error( $pre ) || ! cm_mcp_is_mcp_credential() ) {
            return $pre;
        }
        $scope = self::current_scope();
        if ( self::scope_allows( $scope, $ability ) ) {
            return $pre;
        }
        $message = sprintf(
            /* translators: 1: tool name, 2: scope label */
            __( 'This connection has the "%2$s" scope, which does not include %1$s. Connect again with more access, or ask an administrator.', 'classic-monks' ),
            $name,
            self::scopes()[ $scope ]
        );
        if ( class_exists( 'CM_AI_Activity_Log' ) ) {
            CM_AI_Activity_Log::mark( $name, 'denied', $message );
        }
        return new WP_Error( 'cm_mcp_scope_denied', $message, array( 'status' => 403 ) );
    }

    public static function endpoint_url() {
        return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
    }

    /* ------------------------------------------------------------------
     * MCP endpoint
     * ------------------------------------------------------------------ */

    public static function register_route() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'handle' ),
                    'permission_callback' => array( __CLASS__, 'can_connect' ),
                ),
                array(
                    // No SSE stream or sessions: the spec answer for GET/DELETE is 405.
                    'methods'             => 'GET, DELETE',
                    'callback'            => function () {
                        return new WP_REST_Response( null, 405, array( 'Allow' => 'POST' ) );
                    },
                    'permission_callback' => '__return_true',
                ),
            )
        );
    }

    public static function can_connect( WP_REST_Request $request ) {
        // Spec: reject browser requests from other sites (DNS rebinding). CLI and desktop clients send no Origin.
        $origin = $request->get_header( 'origin' );
        if ( $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
            return new WP_Error( 'cm_mcp_origin', __( 'Origin not allowed.', 'classic-monks' ), array( 'status' => 403 ) );
        }

        $uuid = rest_get_authenticated_app_password();
        if ( ! self::current_connection() ) {
            $message = __( 'Connect over OAuth: add this site in Monks Central, or in an MCP client that supports OAuth.', 'classic-monks' );
            if ( CM_MCP_OAuth::bearer_failed() ) {
                $message = __( 'The access token is invalid or expired. Refresh it, or connect again.', 'classic-monks' );
            } elseif ( $uuid ) {
                $message = __( 'Application Passwords cannot use this endpoint. Connect over OAuth instead.', 'classic-monks' );
            } elseif ( ! $request->get_header( 'authorization' ) && empty( $_SERVER['HTTP_X_CM_AUTHORIZATION'] ) ) {
                $message .= ' ' . __( 'No credentials reached WordPress. If your client sends an Authorization header, your host may be stripping it: send the same value in X-CM-Authorization.', 'classic-monks' );
            }
            return new WP_Error(
                'cm_mcp_unauthorized',
                $message,
                array( 'status' => 401, 'app_passwords_available' => wp_is_application_passwords_available() )
            );
        }

        return true;
    }

    public static function handle( WP_REST_Request $request ) {
        $message = json_decode( $request->get_body(), true );

        if ( ! is_array( $message ) ) {
            return self::error( null, -32700, 'Parse error' );
        }

        // JSON-RPC batch: 2025-03-26 requires accepting one (later versions dropped batching). One level only.
        static $in_batch = false;
        if ( $message && wp_is_numeric_array( $message ) && ! $in_batch ) {
            $in_batch = true;
            $replies  = array();
            foreach ( $message as $item ) {
                $sub = clone $request;
                $sub->set_body( (string) wp_json_encode( $item ) );
                $reply = self::handle( $sub )->get_data();
                if ( null !== $reply ) {
                    $replies[] = $reply;
                }
            }
            $in_batch = false;
            return $replies ? new WP_REST_Response( $replies ) : new WP_REST_Response( null, 202 );
        }

        if ( '2.0' !==( $message['jsonrpc'] ?? '' ) || empty( $message['method'] ) || ! is_string( $message['method'] ) ) {
            return self::error( $message['id'] ?? null, -32600, 'Invalid Request' );
        }

        // Notifications (no id) get no reply.
        if ( ! array_key_exists( 'id', $message ) ) {
            return new WP_REST_Response( null, 202 );
        }

        $id     = $message['id'];
        $method = $message['method'];
        $params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
        $meta   = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();

        // Per message: a modern item earlier in a batch must not change how a legacy one is answered.
        self::$modern      = false;
        self::$client_caps = array();

        // A modern request declares its version in _meta; initialize always means a legacy client.
        if ( isset( $meta['io.modelcontextprotocol/protocolVersion'] ) && is_string( $meta['io.modelcontextprotocol/protocolVersion'] ) && 'initialize' !== $method ) {
            $invalid = self::check_modern_request( $request, $id, $method, $params, (string) $meta['io.modelcontextprotocol/protocolVersion'] );
            if ( $invalid ) {
                return $invalid;
            }
            self::$modern      = true;
            self::$client_caps = (array) ( $meta['io.modelcontextprotocol/clientCapabilities'] ?? array() );
        }

        switch ( $method ) {
            case 'initialize':
                $requested = $params['protocolVersion'] ?? '';
                return self::result( $id, array(
                    'protocolVersion' => in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0],
                    'capabilities'    => self::capabilities(),
                    'serverInfo'      => self::server_info(),
                    'instructions'    => self::instructions(),
                ) );

            case 'server/discover':
                return self::result( $id, array(
                    'supportedVersions' => array_merge( self::MODERN_VERSIONS, self::PROTOCOL_VERSIONS ),
                    'capabilities'      => self::capabilities(),
                    'instructions'      => self::instructions(),
                    'ttlMs'             => self::LIST_TTL_MS,
                    'cacheScope'        => 'private',
                ) );

            case 'tools/list':
                return self::result( $id, self::cacheable( array( 'tools' => self::tools() ) ) );

            case 'tools/call':
                return self::call_tool( $id, $params );

            case 'prompts/list':
            case 'resources/list':
                $key   = 'prompts/list' === $method ? 'prompts' : 'resources';
                $items = array();
                foreach ( self::skills() as $slug => $skill ) {
                    $items[] = 'prompts' === $key
                        ? array( 'name' => $slug, 'title' => $skill['title'], 'description' => $skill['description'] )
                        : array( 'uri' => self::SKILL_URI . $slug, 'name' => $slug, 'title' => $skill['title'], 'description' => $skill['description'], 'mimeType' => 'text/markdown' );
                }
                return self::result( $id, self::cacheable( array( $key => $items ) ) );

            case 'prompts/get':
            case 'resources/read':
                $slug  = 'prompts/get' === $method
                    ? (string) ( $params['name'] ?? '' )
                    : substr( (string) ( $params['uri'] ?? '' ), strlen( self::SKILL_URI ) );
                $skill = self::skills()[ $slug ] ?? null;
                if ( ! $skill ) {
                    // 2026-07-28 moved "not found" to Invalid Params.
                    return self::error( $id, self::$modern ? -32602 : -32002, 'Not found' );
                }
                if ( 'prompts/get' === $method ) {
                    return self::result( $id, array(
                        'description' => $skill['description'],
                        'messages'    => array( array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => $skill['body'] ) ) ),
                    ) );
                }
                return self::result( $id, self::cacheable( array(
                    'contents' => array( array( 'uri' => self::SKILL_URI . $slug, 'mimeType' => 'text/markdown', 'text' => $skill['body'] ) ),
                ) ) );
        }

        // ping was removed in 2026-07-28.
        if ( 'ping' === $method && ! self::$modern ) {
            return self::result( $id, new stdClass() );
        }

        return self::error( $id, -32601, 'Method not found', self::$modern ? 404 : null );
    }

    /**
     * Streamable HTTP rules for 2026-07-28: a supported version, and headers that match the body.
     *
     * @return WP_REST_Response|null Error response, or null when the request is valid.
     */
    private static function check_modern_request( WP_REST_Request $request, $id, $method, array $params, $version ) {
        if ( ! in_array( $version, self::MODERN_VERSIONS, true ) ) {
            return self::error( $id, -32022, 'Unsupported protocol version', 400, array(
                'supported' => array_merge( self::MODERN_VERSIONS, self::PROTOCOL_VERSIONS ),
                'requested' => $version,
            ) );
        }

        $expected = array(
            'mcp-protocol-version' => $version,
            'mcp-method'           => $method,
        );
        if ( in_array( $method, array( 'tools/call', 'prompts/get' ), true ) ) {
            $expected['mcp-name'] = (string) ( $params['name'] ?? '' );
        } elseif ( 'resources/read' === $method ) {
            $expected['mcp-name'] = (string) ( $params['uri'] ?? '' );
        }

        foreach ( $expected as $header => $value ) {
            if ( (string) $request->get_header( $header ) !== $value ) {
                return self::error( $id, -32020, sprintf( 'Header mismatch: %s', $header ), 400 );
            }
        }

        return null;
    }

    private static function capabilities() {
        return array(
            'tools'     => array( 'listChanged' => false ),
            'prompts'   => array( 'listChanged' => false ),
            'resources' => array( 'listChanged' => false ),
        );
    }

    const SKILL_URI = 'cm://skills/';

    /**
     * Workflow guides in skills/*.md, offered as prompts and resources.
     *
     * @return array<string, array{title:string, description:string, body:string}>
     */
    public static function skills() {
        $skills = array();
        foreach ( (array) glob( __DIR__ . '/skills/*.md' ) as $file ) {
            $raw = (string) file_get_contents( $file );
            if ( ! preg_match( '/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $m ) ) {
                continue;
            }
            preg_match( '/^title:\s*(.+)$/m', $m[1], $title );
            preg_match( '/^description:\s*(.+)$/m', $m[1], $description );
            $skills[ basename( $file, '.md' ) ] = array(
                'title'       => trim( $title[1] ?? '' ),
                'description' => trim( $description[1] ?? '' ),
                'body'        => trim( $m[2] ),
            );
        }
        /**
         * Filter the MCP skills (prompts/resources). Add entries keyed by slug.
         */
        return apply_filters( 'cm_mcp_skills', $skills );
    }

    /**
     * 2026-07-28 list/read results carry cache hints.
     */
    private static function cacheable( array $result ) {
        if ( self::$modern ) {
            $result['ttlMs']      = self::LIST_TTL_MS;
            $result['cacheScope'] = 'private';
        }
        return $result;
    }

    private static function server_info() {
        return array(
            'name'    => sanitize_title( cm_get_plugin_name() ),
            'title'   => cm_get_plugin_name(),
            'version' => CM_VERSION,
        );
    }

    private static function instructions() {
        return sprintf(
            /* translators: 1: site URL, 2: username */
            __( 'These tools act on the WordPress site %1$s as the user "%2$s", with that user\'s permissions. Destructive tools need the user\'s approval: give the user approve_url, then call the same tool again with the same arguments.', 'classic-monks' ),
            home_url(),
            wp_get_current_user()->user_login
        );
    }

    /**
     * Abilities a connection lists as tools. Other plugins' abilities are picked by the
     * connection's namespace allowlist, not by toolsets.
     *
     * @return array<string, WP_Ability>
     */
    private static function exposed_abilities( $scope, array $toolsets, array $namespaces, $operator = false ) {
        static $cm = null; // Once per request, as in external_abilities(): the connections list calls this per row.
        $cm      = $cm ?? array_filter( wp_get_abilities(), function ( $name ) {
            return 0 === strpos( $name, self::ABILITY_PREFIX );
        }, ARRAY_FILTER_USE_KEY );
        $exposed = array();
        foreach ( $cm + self::external_abilities( $namespaces ) as $name => $ability ) {
            $own = 0 === strpos( $name, self::ABILITY_PREFIX );
            if ( self::scope_allows( $scope, $ability ) && ( ! $own || self::in_toolsets( $ability, $toolsets ) ) && ! self::lacks_approval_gate( $ability ) && ( $operator || ! $ability->get_meta_item( 'cm_operator_only' ) ) ) {
                $exposed[ $name ] = $ability;
            }
        }
        return $exposed;
    }

    private static function tools() {
        $tools = array();

        $connection = self::current_connection();
        foreach ( self::exposed_abilities( self::current_scope(), self::current_toolsets(), self::current_namespaces(), $connection && null !== $connection['operator'] ) as $name => $ability ) {

            $annotations = self::annotations( $ability );
            $hints       = array_filter(
                array(
                    'readOnlyHint'    => $annotations['readonly'] ?? null,
                    'destructiveHint' => $annotations['destructive'] ?? null,
                    'idempotentHint'  => $annotations['idempotent'] ?? null,
                ),
                'is_bool'
            );

            $tool = array(
                'name'        => self::tool_name( $name ),
                'title'       => $ability->get_label(),
                'description' => $ability->get_description(),
                'inputSchema' => self::object_schema( $ability->get_input_schema() ),
            );
            if ( $hints ) {
                $tool['annotations'] = $hints;
            }
            // Core validates output against this schema before returning it, so it is safe to advertise.
            $output_schema = $ability->get_output_schema();
            if ( 'object' === ( $output_schema['type'] ?? '' ) ) {
                $tool['outputSchema'] = self::object_schema( $output_schema );
            }
            $tools[] = $tool;
        }

        // Stable order keeps client and prompt caches warm.
        usort( $tools, function ( $a, $b ) {
            return strcmp( $a['name'], $b['name'] );
        } );

        return $tools;
    }

    /**
     * PHP encodes empty arrays as JSON lists; turn them into {} where the schema expects an object,
     * so clients validating structuredContent against outputSchema accept it.
     */
    private static function cast_to_schema( $value, $schema ) {
        if ( ! is_array( $value ) || ! is_array( $schema ) ) {
            return $value;
        }
        $types = (array) ( $schema['type'] ?? array() );
        if ( in_array( 'object', $types, true ) && ( array() === $value || ! wp_is_numeric_array( $value ) ) ) {
            foreach ( $value as $key => $item ) {
                $child         = $schema['properties'][ $key ] ?? ( is_array( $schema['additionalProperties'] ?? null ) ? $schema['additionalProperties'] : null );
                $value[ $key ] = self::cast_to_schema( $item, $child );
            }
            return $value ? $value : new stdClass();
        }
        if ( in_array( 'array', $types, true ) && isset( $schema['items'] ) ) {
            return array_map( function ( $item ) use ( $schema ) {
                return self::cast_to_schema( $item, $schema['items'] );
            }, $value );
        }
        return $value;
    }

    /**
     * MCP needs a plain "object" schema; empty PHP arrays would encode as JSON lists.
     */
    private static function object_schema( array $schema ) {
        if ( empty( $schema ) ) {
            return array( 'type' => 'object', 'properties' => new stdClass() );
        }
        // Some abilities declare ["object", "null"]; clients reject the whole tool list over it.
        $schema['type'] = 'object';
        if ( array_key_exists( 'properties', $schema ) && empty( $schema['properties'] ) ) {
            $schema['properties'] = new stdClass();
        }
        return $schema;
    }

    private static function call_tool( $id, array $params ) {
        $tool     = is_string( $params['name'] ?? null ) ? $params['name'] : '';
        $external = false !== strpos( $tool, '_' );
        $name     = $external ? preg_replace( '/_/', '/', $tool, 1 ) : self::ABILITY_PREFIX . $tool;
        $ability  = $external ? ( self::external_abilities( self::current_namespaces() )[ $name ] ?? null ) : wp_get_ability( $name );

        $connection = self::current_connection();
        $hidden     = $ability && $ability->get_meta_item( 'cm_operator_only' ) && ! ( $connection && null !== $connection['operator'] );
        if ( ! $ability || $hidden || ( ! $external && ! self::in_toolsets( $ability, self::current_toolsets() ) ) || self::lacks_approval_gate( $ability ) ) {
            return self::error( $id, -32602, sprintf( 'Unknown tool: %s', (string) ( $params['name'] ?? '' ) ) );
        }

        $arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
        // Abilities without an input schema reject any input, including an empty object.
        $input = $ability->get_input_schema() ? $arguments : ( $arguments ? $arguments : null );

        // WP < 7.1 never fires wp_pre_execute_ability, where enforce_scope normally runs (and gets logged).
        $output = CM_MCP_Approvals::supported() ? null : self::enforce_scope( null, $name, $input, $ability );
        if ( ! is_wp_error( $output ) ) {
            try {
                $output = $ability->execute( $input );
            } catch ( Throwable $e ) {
                // The message can hold paths or SQL: keep it in the server log, not the agent's reply.
                error_log( sprintf( 'Classic Monks MCP: %s threw %s: %s in %s:%d', $name, get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                $output = new WP_Error( 'cm_mcp_tool_exception', __( 'The tool failed with an internal error. Details are in the site\'s PHP error log.', 'classic-monks' ) );
            }
        }

        if ( is_wp_error( $output ) && 'cm_approval_required' === $output->get_error_code() && self::$modern && isset( self::$client_caps['elicitation']['url'] ) ) {
            $answer = $params['inputResponses']['approval']['action'] ?? null;
            if ( null === $answer || 'accept' === $answer ) {
                return self::approval_elicitation( $id, $output );
            }
            // Only an explicit decline closes the request; "cancel" (e.g. a headless client that can't show the
            // URL) falls through to the plain approve_url result so the agent can hand the link to the user.
            if ( 'decline' === $answer ) {
                CM_MCP_Approvals::decline( (int) ( $output->get_error_data()['op_id'] ?? 0 ) );
                $output = new WP_Error( 'cm_approval_declined', __( 'The user declined this change.', 'classic-monks' ) );
            }
        }

        if ( is_wp_error( $output ) ) {
            $error = self::describe_error( $output );
            return self::result( $id, array(
                'content'           => array( array( 'type' => 'text', 'text' => (string) wp_json_encode( $error ) ) ),
                'structuredContent' => $error,
                'isError'           => true,
            ) );
        }

        $result = array(
            'content' => array( array(
                'type' => 'text',
                'text' => is_string( $output ) ? $output : (string) wp_json_encode( $output ),
            ) ),
        );
        $output_schema = $ability->get_output_schema();
        if ( is_array( $output ) && 'object' === ( $output_schema['type'] ?? '' ) ) {
            $result['structuredContent'] = self::cast_to_schema( $output, $output_schema );
        }
        return self::result( $id, $result );
    }

    /**
     * 2026-07-28 clients that support URL elicitation open the approval page themselves,
     * then retry the call; the approval gate decides on the retry.
     */
    private static function approval_elicitation( $id, WP_Error $error ) {
        $data = (array) $error->get_error_data();
        return self::result( $id, array(
            'resultType'    => 'input_required',
            'inputRequests' => array(
                'approval' => array(
                    'method' => 'elicitation/create',
                    'params' => array(
                        'mode'    => 'url',
                        'message' => __( 'This change needs your approval in WordPress. Open the page, approve or decline, then come back.', 'classic-monks' ),
                        'url'     => $data['approve_url'],
                    ),
                ),
            ),
        ) );
    }

    /**
     * Without WP 7.1's pre-execute hook, destructive tools can't be gated, so MCP doesn't offer them.
     */
    private static function lacks_approval_gate( WP_Ability $ability ) {
        return ! CM_MCP_Approvals::supported() && CM_MCP_Approvals::needs_approval( $ability );
    }

    /**
     * Tell the agent why a call failed and whether retrying can help.
     */
    private static function describe_error( WP_Error $error ) {
        $causes = array(
            'cm_mcp_scope_denied'         => array( 'scope', false, 'This connection may not use this tool. Do not retry; tell the user.' ),
            'ability_invalid_permissions' => array( 'capability', false, 'The connected WordPress user lacks the capability for this. Do not retry.' ),
            'ability_invalid_input'       => array( 'validation', true, 'Fix the arguments to match the tool input schema, then retry.' ),
            'cm_approval_required'        => array( 'approval', true, 'Give the user approve_url. After they approve, call the same tool again with exactly the same arguments.' ),
            'cm_approval_drift'           => array( 'approval', true, 'The target changed after approval. Re-read it, then call again to request a new approval.' ),
            'cm_approval_declined'        => array( 'approval', false, 'The user declined. Do not retry unless they ask again.' ),
        );
        $code = (string) $error->get_error_code();
        list( $cause, $retry_ok, $hint ) = $causes[ $code ] ?? array( 'tool', true, 'Check the message; retry once if it looks transient.' );

        $described = array(
            'error'    => $error->get_error_message(),
            'code'     => $code,
            'cause'    => $cause,
            'retry_ok' => $retry_ok,
            'hint'     => $hint,
        );
        $data = $error->get_error_data();
        if ( 'cm_approval_required' === $code && is_array( $data ) ) {
            $described += array_intersect_key( $data, array_flip( array( 'op_id', 'approve_url', 'preview', 'expires_in' ) ) );
        }
        return $described;
    }

    private static function result( $id, $result ) {
        if ( self::$modern ) {
            $result = (array) $result;
            $result += array( 'resultType' => 'complete' );
            $result['_meta'] = array( 'io.modelcontextprotocol/serverInfo' => self::server_info() );
        }
        return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ) );
    }

    private static function error( $id, $code, $message, $status = null, $data = null ) {
        $error = array( 'code' => $code, 'message' => $message );
        if ( null !== $data ) {
            $error['data'] = $data;
        }
        return new WP_REST_Response(
            array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => $error ),
            $status ?? ( null === $id ? 400 : 200 )
        );
    }

    private static function is_mcp_password( $user_id, $uuid ) {
        $item = WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
        return $item && self::APP_ID === ( $item['app_id'] ?? '' );
    }

    /* ------------------------------------------------------------------
     * Connection settings
     * ------------------------------------------------------------------ */

    /**
     * @param string[]|null $namespaces Null forgets the connection.
     */
    private static function save_namespaces( $user_id, $uuid, $namespaces ) {
        $all = get_user_meta( $user_id, self::NAMESPACES_META, true );
        $all = is_array( $all ) ? $all : array();
        if ( null === $namespaces ) {
            unset( $all[ $uuid ] );
        } else {
            $all[ $uuid ] = $namespaces;
        }
        update_user_meta( $user_id, self::NAMESPACES_META, $all );
    }

    /**
     * @param string[]|null $toolsets Null forgets the connection.
     */
    private static function save_toolsets( $user_id, $uuid, $toolsets ) {
        $all = get_user_meta( $user_id, self::TOOLSETS_META, true );
        $all = is_array( $all ) ? $all : array();
        if ( null === $toolsets ) {
            unset( $all[ $uuid ] );
        } else {
            $all[ $uuid ] = $toolsets;
        }
        update_user_meta( $user_id, self::TOOLSETS_META, $all );
    }

    /**
     * @param string|null $scope Null forgets the connection.
     */
    private static function save_scope( $user_id, $uuid, $scope ) {
        $scopes = get_user_meta( $user_id, self::SCOPES_META, true );
        $scopes = is_array( $scopes ) ? $scopes : array();
        if ( null === $scope ) {
            unset( $scopes[ $uuid ] );
        } else {
            $scopes[ $uuid ] = $scope;
        }
        update_user_meta( $user_id, self::SCOPES_META, $scopes );
    }

    public static function connections() {
        $rows  = array();
        $users = get_users( array(
            'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin screen only; core has no index of users with Application Passwords
            'meta_compare' => 'EXISTS',
        ) );

        foreach ( $users as $user ) {
            foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
                if ( self::APP_ID !== ( $item['app_id'] ?? '' ) ) {
                    continue;
                }
                // The prefix labels it on the profile screen; redundant inside the MCP table.
                $name   = preg_replace( '/^' . preg_quote( cm_get_plugin_name() . ' MCP · ', '/' ) . '/u', '', $item['name'] );
                $rows[] = self::connection_row( 'app_password', $user, $item['uuid'], $name, $item['created'], $item['last_used'] );
            }
        }

        foreach ( CM_MCP_OAuth::grants() as $grant ) {
            $user = get_userdata( $grant->user_id );
            if ( ! $user ) {
                continue;
            }
            /* translators: %s: app name */
            $name   = sprintf( __( '%s (OAuth)', 'classic-monks' ), $grant->client_name );
            $rows[] = self::connection_row( 'oauth', $user, CM_MCP_OAuth::ID_PREFIX . $grant->family, $name, (int) $grant->created, (int) $grant->last_used );
        }

        return $rows;
    }

    private static function connection_row( $kind, WP_User $user, $id, $name, $created, $last_used ) {
        return array(
            'kind'        => $kind,
            'user_id'     => $user->ID,
            'uuid'        => $id,
            'name'        => $name,
            'user'        => $user->user_login,
            'role'        => implode( ', ', array_map( function ( $role ) {
                return translate_user_role( wp_roles()->role_names[ $role ] ?? $role );
            }, $user->roles ) ),
            'created'     => date_i18n( get_option( 'date_format' ), $created ),
            'last_used'   => $last_used
                /* translators: %s: human time difference */
                ? sprintf( __( '%s ago', 'classic-monks' ), human_time_diff( $last_used ) )
                : __( 'Never', 'classic-monks' ),
            'scope'       => self::connection_scope( $user->ID, $id ),
            'trusted'     => CM_MCP_Approvals::is_trusted( $user->ID, $id ),
            'operator'    => CM_MCP_Approvals::operator( $user->ID, $id ),
            'toolsets'    => self::connection_toolsets( $user->ID, $id ),
            'namespaces'  => self::connection_namespaces( $user->ID, $id ),
            'tool_count'  => self::tool_count( $user->ID, $id ),
            'last_action' => class_exists( 'CM_AI_Activity_Log' ) ? CM_AI_Activity_Log::last_for_connection( $id ) : null,
        );
    }
}

require_once __DIR__ . '/class-mcp-approvals.php';
require_once __DIR__ . '/class-mcp-oauth.php';

CM_MCP_Server::init();
