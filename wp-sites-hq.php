<?php
/**
 * Plugin Name:       WP Sites HQ
 * Plugin URI:        https://wpsiteshq.com
 * Description:       Connects this site to the WP Sites HQ app, so you can manage it with your other WordPress sites from one place.
 * Version:           0.2.0
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Author:            Good Monks
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-sites-hq
 *
 * @package MonksCentral
 */

defined( 'ABSPATH' ) || exit;

// WordPress includes this file a second time after an update (plugin_sandbox_scrape), or two copies are active.
if ( defined( 'MONKS_CENTRAL_VERSION' ) ) {
	$monks_central_other = dirname( (string) MONKS_CENTRAL_FILE );
	if ( realpath( $monks_central_other ) !== realpath( __DIR__ ) ) {
		// Register this copy's connector as well: with one host left, deactivating the running copy revokes every connection.
		if ( function_exists( 'monks_central_connector_register' ) ) {
			monks_central_connector_register(
				array(
					'dir'     => __DIR__ . '/connector',
					'file'    => __FILE__,
					'name'    => 'WP Sites HQ',
					'version' => get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'],
					'ui'      => MonksCentral\Connector\Ui::class,
				)
			);
		}
		add_action(
			'admin_notices',
			static function () use ( $monks_central_other ) {
				if ( current_user_can( 'activate_plugins' ) ) {
					/* translators: 1: folder of the copy that runs, 2: folder of the duplicate copy. */
					$message = sprintf( __( 'Two copies of WP Sites HQ are active: %1$s and %2$s. Only the first one runs. Delete the other one on the Plugins screen.', 'wp-sites-hq' ), $monks_central_other, __DIR__ );
					// The old folder sorts first in active_plugins, so it runs until the move deactivates it.
					if ( 'monks-central' === basename( $monks_central_other ) ) {
						$message = __( 'Deactivate Monks Central (old) to finish the move to WP Sites HQ.', 'wp-sites-hq' );
					}
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
				}
			}
		);
	}//end if
	return;
}//end if

define( 'MONKS_CENTRAL_VERSION', '0.2.0' );
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
			'name'    => 'WP Sites HQ',
			'version' => MONKS_CENTRAL_VERSION,
			'ui'      => MonksCentral\Connector\Ui::class,
		)
	);
}
