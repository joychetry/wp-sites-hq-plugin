<?php
/**
 * Plugin Name:       Monks Central
 * Plugin URI:        https://github.com/joychetry/monks-central-wp
 * Description:       Connects this site to the Monks Central app, so you can manage it with your other WordPress sites from one place.
 * Version:           0.1.3
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

// WordPress includes this file a second time after an update (plugin_sandbox_scrape), or two copies are active.
if ( defined( 'MONKS_CENTRAL_VERSION' ) ) {
	$monks_central_other = dirname( (string) MONKS_CENTRAL_FILE );
	if ( realpath( $monks_central_other ) !== realpath( __DIR__ ) ) {
		add_action(
			'admin_notices',
			static function () use ( $monks_central_other ) {
				if ( current_user_can( 'activate_plugins' ) ) {
					/* translators: 1: folder of the copy that runs, 2: folder of the duplicate copy. */
					$message = __( 'Two copies of Monks Central are active: %1$s and %2$s. Only the first one runs. Delete the other one on the Plugins screen.', 'monks-central' );
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( $message, $monks_central_other, __DIR__ ) ) );
				}
			}
		);
	}
	return;
}

define( 'MONKS_CENTRAL_VERSION', '0.1.3' );
define( 'MONKS_CENTRAL_FILE', __FILE__ );
define( 'MONKS_CENTRAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'MONKS_CENTRAL_URL', plugin_dir_url( __FILE__ ) );

// vendor/ only holds the Composer autoloader in the release zip. Run `composer install` in a git checkout.
if ( is_readable( MONKS_CENTRAL_DIR . 'vendor/autoload.php' ) ) {
	require MONKS_CENTRAL_DIR . 'vendor/autoload.php';
	add_action( 'plugins_loaded', array( MonksCentral\Plugin::class, 'boot' ) );

	// The shared connector: every plugin that bundles it registers its copy; the highest version runs.
	require_once MONKS_CENTRAL_DIR . 'connector/load.php';
	monks_central_connector_register(
		array(
			'dir'     => MONKS_CENTRAL_DIR . 'connector',
			'file'    => __FILE__,
			'name'    => 'Monks Central',
			'version' => MONKS_CENTRAL_VERSION,
			'ui'      => MonksCentral\Connector\Ui::class,
		)
	);
}
