<?php
/**
 * PHPUnit bootstrap: the autoloader, the constants the main plugin file defines, and a small WP_Error.
 *
 * @package WPSitesHQ
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'WPSHQ_VERSION', '0.2.1' );
define( 'WPSHQ_FILE', dirname( __DIR__, 2 ) . '/wp-sites-hq.php' );
define( 'WPSHQ_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'WPSHQ_URL', 'https://example.org/wp-content/plugins/wp-sites-hq/' );

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Enough of core's WP_Error for code under test that returns one.
	 */
	class WP_Error { // phpcs:ignore
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
