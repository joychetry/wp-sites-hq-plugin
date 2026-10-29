<?php
/**
 * Create Login Link Ability - WordPress Abilities API
 *
 * One-click wp-admin login for Monks Central (M3). Only an operator grant may call it
 * (MCP hides it from every other caller), and the link signs in the grant's own user:
 * single use, 60 seconds, HTTPS or a local site, spent on POST so plain link fetchers (previews,
 * mail scanners without JavaScript) can't use it. The app opens it in the browser at once; it is never mailed.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const CM_LOGIN_LINK_TTL        = 60;
const CM_LOGIN_LINK_RATE_LIMIT = 5; // links per user per 10 minutes

add_action( 'wp_abilities_api_init', 'cm_register_create_login_link_ability' );
function cm_register_create_login_link_ability() {
    wp_register_ability( 'classic-monks/create-login-link', array(
        'label'       => __( 'Create Login Link', 'classic-monks' ),
        'description' => __(
            'Monks Central only. Returns a link that signs the connected user into wp-admin. It works once, within 60 seconds, and only over HTTPS (or on a local site). Open it in the browser right away; never share or store it.',
            'classic-monks'
        ),
        'category'    => 'system',
        'input_schema' => array(
            'type'                 => 'object',
            'properties'           => array(),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'url'        => array( 'type' => 'string' ),
                'expires_in' => array( 'type' => 'integer' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_create_login_link',
        'permission_callback' => 'cm_is_operator_request',
        'meta' => array(
            'annotations' => array(
                'readonly'    => false,
                'destructive' => false,
                'idempotent'  => false,
            ),
            'cm_operator_only' => true,
        ),
    ) );
}

/**
 * The current request comes from a Monks Central operator grant.
 */
function cm_is_operator_request() {
    $connection = function_exists( 'cm_mcp_is_mcp_credential' ) && cm_mcp_is_mcp_credential() ? CM_MCP_Server::current_connection() : null;
    return $connection && null !== $connection['operator'];
}

function cm_execute_create_login_link() {
    if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
        return new WP_Error( 'cm_login_link_insecure', __( 'Login links need HTTPS on this site.', 'classic-monks' ), array( 'status' => 400 ) );
    }

    $user_id = get_current_user_id();
    $rate    = 'cm_login_link_rate_' . $user_id;
    $count   = (int) get_transient( $rate );
    if ( $count >= CM_LOGIN_LINK_RATE_LIMIT ) {
        return new WP_Error( 'cm_login_link_rate_limited', __( 'Too many login links in a short time. Wait a few minutes and try again.', 'classic-monks' ), array( 'status' => 429 ) );
    }
    // shortcut: each new link restarts the 10-minute window, and parallel calls can share a count; fine for one app per user.
    set_transient( $rate, $count + 1, 10 * MINUTE_IN_SECONDS );

    // The cmll_ shape is masked by CM_AI_Activity_Log::redact(), so the logged result never holds a usable link.
    $token = 'cmll_' . bin2hex( random_bytes( 32 ) );
    set_transient( 'cm_login_link_' . hash( 'sha256', $token ), array(
        'user_id'    => $user_id,
        'connection' => CM_MCP_Server::current_connection()['id'],
        'label'      => CM_AI_Activity_Log::caller()['label'],
        'expires'    => time() + CM_LOGIN_LINK_TTL,
    ), CM_LOGIN_LINK_TTL );

    return array(
        'url'        => add_query_arg( array( 'action' => 'cm_login_link', 'token' => $token ), wp_login_url() ),
        'expires_in' => CM_LOGIN_LINK_TTL,
    );
}

add_action( 'login_form_cm_login_link', 'cm_handle_login_link' );
function cm_handle_login_link() {
    nocache_headers();
    header( 'Referrer-Policy: no-referrer' );
    $token = sanitize_text_field( wp_unslash( $_REQUEST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- the single-use token is the credential

    // A GET never spends the link (link previews fetch URLs); the page posts it back.
    if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title><?php esc_html_e( 'Signing in', 'classic-monks' ); ?></title>
</head>
<body>
    <form method="post" id="cm-login-link">
        <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
        <button type="submit"><?php esc_html_e( 'Continue to the dashboard', 'classic-monks' ); ?></button>
    </form>
    <script>document.getElementById( 'cm-login-link' ).submit();</script>
</body>
</html>
        <?php
        exit;
    }

    $key  = 'cm_login_link_' . hash( 'sha256', $token );
    $data = get_transient( $key );
    // Single use: only the request that actually deletes the token may sign in. The grant must still be an operator.
    $valid = is_array( $data ) && delete_transient( $key ) && $data['expires'] >= time() && get_userdata( $data['user_id'] )
        && class_exists( 'CM_MCP_Server' ) && CM_MCP_Server::connection_exists( $data['user_id'], $data['connection'] )
        && null !== CM_MCP_Approvals::operator( $data['user_id'], $data['connection'] );
    if ( ! $valid ) {
        wp_die( esc_html__( 'This login link is invalid, expired or already used. Open the site from Monks Central again.', 'classic-monks' ), esc_html__( 'Login link', 'classic-monks' ), array( 'response' => 403 ) );
    }

    $user = get_userdata( $data['user_id'] );
    wp_set_auth_cookie( $user->ID, false, is_ssl() );
    CM_AI_Activity_Log::record( $user->ID, $data['connection'], $data['label'] ?? '', 'classic-monks/use-login-link', __( 'Signed in to wp-admin with a one-time login link.', 'classic-monks' ) );
    // Core fires this after every sign-in; last-login columns and similar tracking rely on it.
    do_action( 'wp_login', $user->user_login, $user );
    wp_safe_redirect( admin_url() );
    exit;
}
