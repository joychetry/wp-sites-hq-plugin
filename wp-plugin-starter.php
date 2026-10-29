<?php
/**
 * Plugin Name:       WP Plugin Starter
 * Plugin URI:        https://github.com/joychetry/wp-plugin-starter
 * Description:       A lean, framework-free starter for WordPress plugins.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Good Monks
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-plugin-starter
 *
 * @package WpPluginStarter
 */

defined( 'ABSPATH' ) || exit;

define( 'WPS_VERSION', '0.1.0' );
define( 'WPS_FILE', __FILE__ );
define( 'WPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPS_URL', plugin_dir_url( __FILE__ ) );

// vendor/ only holds the Composer autoloader in the release zip. Run `composer install` in a git checkout.
if ( is_readable( WPS_DIR . 'vendor/autoload.php' ) ) {
	require WPS_DIR . 'vendor/autoload.php';
	add_action( 'plugins_loaded', array( WpPluginStarter\Plugin::class, 'boot' ) );
}
