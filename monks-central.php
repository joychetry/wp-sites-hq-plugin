<?php
/**
 * Plugin Name:       Monks Central
 * Plugin URI:        https://github.com/joychetry/monks-central
 * Description:       A lean, framework-free starter for WordPress plugins.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Author:            Good Monks
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       monks-central
 *
 * @package MonksCentral
 */

defined( 'ABSPATH' ) || exit;

define( 'MONKS_CENTRAL_VERSION', '0.1.0' );
define( 'MONKS_CENTRAL_FILE', __FILE__ );
define( 'MONKS_CENTRAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'MONKS_CENTRAL_URL', plugin_dir_url( __FILE__ ) );

// vendor/ only holds the Composer autoloader in the release zip. Run `composer install` in a git checkout.
if ( is_readable( MONKS_CENTRAL_DIR . 'vendor/autoload.php' ) ) {
	require MONKS_CENTRAL_DIR . 'vendor/autoload.php';
	add_action( 'plugins_loaded', array( MonksCentral\Plugin::class, 'boot' ) );
}
