<?php
/**
 * Plugin Name:       Monks Central (old)
 * Plugin URI:        https://github.com/joychetry/monks-central-wp
 * Description:       Monks Central is now WP Sites HQ. This version only moves the site to the WP Sites HQ plugin: use Move this site on the Dashboard or the Plugins screen.
 * Version:           0.1.4
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
		// Register this copy's connector as well: with one host left, deactivating the running copy revokes every connection.
		if ( function_exists( 'monks_central_connector_register' ) ) {
			monks_central_connector_register(
				array(
					'dir'     => __DIR__ . '/connector',
					'file'    => __FILE__,
					'name'    => 'Monks Central (old)',
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
					$message = __( 'Two copies of Monks Central are active: %1$s and %2$s. Only the first one runs. Delete the other one on the Plugins screen.', 'monks-central' );
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( $message, $monks_central_other, __DIR__ ) ) );
				}
			}
		);
	}//end if
	return;
}//end if

define( 'MONKS_CENTRAL_VERSION', '0.1.4' );
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
			'name'    => 'Monks Central (old)',
			'version' => MONKS_CENTRAL_VERSION,
			'ui'      => MonksCentral\Connector\Ui::class,
		)
	);
}
