<?php
/**
 * Get Site Snapshot Ability - WordPress Abilities API
 *
 * Everything Monks Central refreshes for a site, in one call (M4): core, PHP, database,
 * plugins, themes, pending updates with translations, Site Health, environment, and
 * whether installs need file-system credentials.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_get_site_snapshot_ability' );
function cm_register_get_site_snapshot_ability() {
    wp_register_ability( 'classic-monks/get-site-snapshot', array(
        'label'       => __( 'Get Site Snapshot', 'classic-monks' ),
        'description' => __(
            'One call for a site overview: WordPress core (version, database version), PHP, database server, every plugin and theme (version, active, auto-update, available update), pending updates including translations (as list-updates), the last Site Health result, environment flags, and the file-system method: when filesystem.needs_credentials is true, installs and updates cannot run without FTP/SSH credentials, through these tools or any other.',
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
                'generated_at' => array( 'type' => 'integer' ),
                'site'         => array( 'type' => 'object' ),
                'core'         => array( 'type' => 'object' ),
                'php'          => array( 'type' => 'object' ),
                'database'     => array( 'type' => 'object' ),
                'plugins'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
                'themes'       => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
                'updates'      => array( 'type' => 'object' ),
                'health'       => array( 'type' => array( 'object', 'null' ) ),
                'environment'  => array( 'type' => 'object' ),
                'filesystem'   => array( 'type' => 'object' ),
            ),
        ),
        'execute_callback'    => 'cm_execute_get_site_snapshot',
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ),
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * How WordPress would write files, and whether that needs credentials nobody stored (FS_METHOD, FTP_* constants).
 */
function cm_ai_snapshot_filesystem() {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $method = get_filesystem_method();
    $stored = defined( 'FTP_HOST' ) && defined( 'FTP_USER' ) && ( defined( 'FTP_PASS' ) || defined( 'FTP_PRIKEY' ) );
    return array(
        'method'            => $method,
        'fs_method_defined' => defined( 'FS_METHOD' ),
        'needs_credentials' => 'direct' !== $method && ! $stored,
        'file_mods_allowed' => wp_is_file_mod_allowed( 'cm_site_snapshot' ),
    );
}

function cm_execute_get_site_snapshot() {
    global $wpdb;
    $updates = cm_ai_list_updates(); // also loads the plugin, update and theme admin files

    $plugin_updates = array_column( $updates['plugins'], 'new_version', 'plugin' );
    $auto_plugins   = (array) get_site_option( 'auto_update_plugins', array() );
    $plugins        = array();
    foreach ( get_plugins() as $file => $data ) {
        $plugins[] = array(
            'plugin'         => $file,
            'name'           => $data['Name'],
            'version'        => $data['Version'],
            'active'         => is_plugin_active( $file ),
            'network_active' => is_multisite() && is_plugin_active_for_network( $file ),
            'auto_update'    => in_array( $file, $auto_plugins, true ),
            'update'         => $plugin_updates[ $file ] ?? null,
        );
    }

    $theme_updates = array_column( $updates['themes'], 'new_version', 'stylesheet' );
    $auto_themes   = (array) get_site_option( 'auto_update_themes', array() );
    $themes        = array();
    foreach ( wp_get_themes() as $stylesheet => $theme ) {
        $themes[] = array(
            'stylesheet'  => $stylesheet,
            'name'        => $theme->get( 'Name' ),
            'version'     => $theme->get( 'Version' ),
            'active'      => get_stylesheet() === $stylesheet,
            'parent'      => $theme->parent() ? $theme->get_template() : null,
            'auto_update' => in_array( $stylesheet, $auto_themes, true ),
            'update'      => $theme_updates[ $stylesheet ] ?? null,
        );
    }

    // Site Health's last background or screen run; running the tests here would take seconds.
    $health = json_decode( (string) get_transient( 'health-check-site-status-result' ), true );

    return array(
        'generated_at' => time(),
        'site'         => array(
            'name'      => get_bloginfo( 'name' ),
            'url'       => home_url(),
            'admin_url' => admin_url(),
            'multisite' => is_multisite(),
            'language'  => get_locale(),
            'timezone'  => wp_timezone_string(),
        ),
        'core'         => array(
            'version'    => (string) get_bloginfo( 'version' ),
            'db_version' => (int) get_option( 'db_version' ),
            'update'     => $updates['core'] ? $updates['core'][0]['version'] : null,
        ),
        'php'          => array(
            'version'            => PHP_VERSION,
            'memory_limit'       => ini_get( 'memory_limit' ),
            'max_execution_time' => (int) ini_get( 'max_execution_time' ),
            'upload_max_size'    => ini_get( 'upload_max_filesize' ),
        ),
        'database'     => array(
            'server_version' => $wpdb->db_version(),
            'server_info'    => (string) $wpdb->db_server_info(),
            'charset'        => $wpdb->charset,
        ),
        'plugins'      => $plugins,
        'themes'       => $themes,
        'updates'      => $updates,
        'health'       => is_array( $health ) ? array(
            'good'        => (int) ( $health['good'] ?? 0 ),
            'recommended' => (int) ( $health['recommended'] ?? 0 ),
            'critical'    => (int) ( $health['critical'] ?? 0 ),
        ) : null,
        'environment'  => array(
            'type'               => wp_get_environment_type(),
            'ssl'                => is_ssl(),
            'debug'              => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'debug_log'          => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
            'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
            'disallow_file_mods' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
            'auto_updates'       => ! ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ),
            'object_cache'       => (bool) wp_using_ext_object_cache(),
            'cron_disabled'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'memory_limit'       => WP_MEMORY_LIMIT,
        ),
        'filesystem'   => cm_ai_snapshot_filesystem(),
    );
}
