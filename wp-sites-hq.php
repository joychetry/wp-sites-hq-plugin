<?php
/**
 * Plugin Name:       WP Sites HQ
 * Plugin URI:        https://wpsiteshq.com
 * Description:       Connects this site to the WP Sites HQ app, so you can manage it with your other WordPress sites from one place.
 * Version:           0.2.1
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Author:            Good Monks
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-sites-hq
 *
 * @package WPSitesHQ
 */

defined( 'ABSPATH' ) || exit;

// WordPress includes this file a second time after an update (plugin_sandbox_scrape), or two copies are active.
if ( defined( 'WPSHQ_VERSION' ) ) {
	$wpshq_other = dirname( (string) WPSHQ_FILE );
	if ( realpath( $wpshq_other ) !== realpath( __DIR__ ) ) {
		// Register this copy's connector as well: with one host left, deactivating the running copy revokes every connection.
		if ( function_exists( 'wpshq_connector_register' ) ) {
			wpshq_connector_register(
				array(
					'dir'     => __DIR__ . '/connector',
					'file'    => __FILE__,
					'name'    => 'WP Sites HQ',
					'version' => get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'],
					'ui'      => WPSitesHQ\Connector\Ui::class,
				)
			);
		}
		$wpshq_notice = static function () use ( $wpshq_other ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				/* translators: 1: folder of the copy that runs, 2: folder of the duplicate copy. */
				$message = sprintf( __( 'Two copies of WP Sites HQ are active: %1$s and %2$s. Only the first one runs. Delete the other one on the Plugins screen.', 'wp-sites-hq' ), $wpshq_other, __DIR__ );
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
			}
		};
		// Network Admin too: a network-activated pair is finished there.
		add_action( 'admin_notices', $wpshq_notice );
		add_action( 'network_admin_notices', $wpshq_notice );
	}//end if
	return;
}//end if

define( 'WPSHQ_VERSION', '0.2.1' );
define( 'WPSHQ_FILE', __FILE__ );
define( 'WPSHQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSHQ_URL', plugin_dir_url( __FILE__ ) );

// vendor/ only holds the Composer autoloader in the release zip. Run `composer install` in a git checkout.
if ( is_readable( WPSHQ_DIR . 'vendor/autoload.php' ) ) {
	require WPSHQ_DIR . 'vendor/autoload.php';
	add_action( 'plugins_loaded', array( WPSitesHQ\Plugin::class, 'boot' ) );

	// The shared connector: every plugin that bundles it registers its copy; the highest version runs.
	require_once WPSHQ_DIR . 'connector/load.php';
	wpshq_connector_register(
		array(
			'dir'     => WPSHQ_DIR . 'connector',
			'file'    => __FILE__,
			'name'    => 'WP Sites HQ',
			'version' => WPSHQ_VERSION,
			'ui'      => WPSitesHQ\Connector\Ui::class,
		)
	);
}
