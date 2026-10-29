<?php
/**
 * Activate Theme Ability - WordPress Abilities API
 *
 * Switches the active theme. Undo switches back.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_activate_theme_ability' );
function cm_register_activate_theme_ability() {
    wp_register_ability( 'classic-monks/activate-theme', array(
        'label'       => __( 'Activate Theme', 'classic-monks' ),
        'description' => __(
            "Switches the site to another installed theme. This changes how the whole site looks. Switching away from Bricks (or a Bricks child theme) turns off the Bricks builder and every Bricks layout. The home page is then loaded logged out; on a fatal error or HTTP 5xx the previous theme is switched back on and an error is returned.\n\n## Parameters\n- **stylesheet**: The theme's stylesheet (folder name) from list-themes (required).\n\n## Returns\n**previous** and **active** theme, and **check**: passed, skipped (home page unreachable) or unchanged (already active).",
            'classic-monks'
        ),
        'category'    => 'system',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'stylesheet' => array( 'type' => 'string', 'minLength' => 1 ),
            ),
            'required'             => array( 'stylesheet' ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'previous' => array( 'type' => 'string' ),
                'active'   => array( 'type' => 'string' ),
                'check'    => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_activate_theme',
        'permission_callback' => function() {
            return current_user_can( 'switch_themes' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true,
                'idempotent'   => true,
                'instructions' => 'Changes the look of the whole site. Confirm with the user first.',
            ),
            'cm_preview_callback' => 'cm_preview_active_theme',
            // The undo data is this ability's own input with the previous theme.
            'cm_undo_callback'    => 'cm_execute_activate_theme',
            'show_in_rest'        => true,
        ),
    ) );
}

function cm_preview_active_theme( $input ) {
    return array( 'active' => get_stylesheet() );
}

function cm_execute_activate_theme( $input ) {
    // Re-checked here because this also runs as the undo callback.
    if ( ! current_user_can( 'switch_themes' ) ) {
        return new WP_Error( 'forbidden', __( 'You are not allowed to switch themes.', 'classic-monks' ) );
    }

    $theme = wp_get_theme( sanitize_text_field( $input['stylesheet'] ) );
    if ( ! $theme->exists() || ! $theme->is_allowed() ) {
        return new WP_Error( 'cm_theme_not_found', __( 'Theme not found. Use list-themes for the exact stylesheet.', 'classic-monks' ) );
    }
    if ( $theme->errors() ) {
        return new WP_Error( 'cm_theme_broken', $theme->errors()->get_error_message() );
    }

    $previous = get_stylesheet();
    $check    = 'unchanged';
    if ( $previous !== $theme->get_stylesheet() ) {
        switch_theme( $theme->get_stylesheet() );
        $check = cm_activate_theme_fatal_check();
        if ( is_wp_error( $check ) ) {
            switch_theme( $previous );
            return new WP_Error( 'cm_theme_activate_fatal', sprintf(
                /* translators: 1: error excerpt, 2: previous theme */
                __( 'The home page broke with the new theme (%1$s), so %2$s was switched back on.', 'classic-monks' ),
                $check->get_error_message(),
                $previous
            ) );
        }
        $check = true === $check ? 'passed' : 'skipped';
        if ( class_exists( 'CM_AI_Activity_Log' ) ) {
            CM_AI_Activity_Log::set_undo( 'classic-monks/activate-theme', array( 'stylesheet' => $previous ) );
        }
    }

    return array(
        'previous' => $previous,
        'active'   => get_stylesheet(),
        'check'    => $check,
    );
}

/**
 * The URL to check after a theme change. The request's host is reachable from here even when the home
 * option names another host, but a caller chooses that header: only the site's own host (any port), or any
 * host on a local site, is used.
 *
 * @param string $url   Home URL.
 * @param string $host  The request's Host header.
 * @param bool   $local Whether this is a local site.
 * @return string
 */
function cm_activate_theme_url_for_host( $url, $host, $local ) {
    $same = strtolower( (string) preg_replace( '/:\d+$/', '', $host ) ) === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    if ( preg_match( '/^[A-Za-z0-9.-]+(:\d+)?$/', $host ) && ( $same || $local ) ) {
        $url = preg_replace( '#^(https?://)[^/]+#', '${1}' . $host, $url );
    }
    return $url;
}

/**
 * Load the home page after a theme switch (from Pro's theme sandbox).
 *
 * @return true|null|WP_Error True when it rendered, null when the request failed, WP_Error on a fatal error.
 */
function cm_activate_theme_fatal_check() {
    $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
    $url  = cm_activate_theme_url_for_host( home_url( '/' ), $host, 'local' === wp_get_environment_type() );
    /**
     * Filters the URL fetched after a theme switch to detect a fatal error.
     *
     * @param string $url Home page URL.
     */
    $url = (string) apply_filters( 'cm_theme_sandbox_check_url', $url );

    $response = wp_remote_get( add_query_arg( 'cm_theme_check', wp_rand( 1000, 999999 ), $url ), array(
        'timeout'     => 20,
        'redirection' => 3,
        'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
        'headers'     => array( 'Cache-Control' => 'no-cache' ),
    ) );
    if ( is_wp_error( $response ) ) {
        return null;
    }
    $status = (int) wp_remote_retrieve_response_code( $response );
    $body   = substr( (string) wp_remote_retrieve_body( $response ), 0, 20000 );
    // A 200 with no body means the theme rendered nothing (no template found).
    if ( 200 === $status && '' === trim( $body ) ) {
        return new WP_Error( 'cm_theme_fatal', __( 'HTTP 200 with an empty page.', 'classic-monks' ) );
    }
    if ( $status < 500 && ! preg_match( '/There has been a critical error on this website|<b>Fatal error<\/b>|PHP Fatal error:|Uncaught (?:Error|Exception)/i', $body ) ) {
        return true;
    }
    $text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $body ), ENT_QUOTES, 'UTF-8' ) ) );
    $text = preg_match( '/(Fatal error:.{0,240}|Uncaught (?:Error|Exception).{0,240})/i', $text, $m ) ? $m[1] : substr( $text, 0, 240 );
    return new WP_Error( 'cm_theme_fatal', sprintf( 'HTTP %d: %s', $status, $text ) );
}
