<?php
/**
 * PHPUnit bootstrap: the autoloader, the constants the main plugin file defines, and a small WP_Error.
 *
 * @package MonksCentral
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'MONKS_CENTRAL_VERSION', '0.1.0' );
define( 'MONKS_CENTRAL_FILE', dirname( __DIR__, 2 ) . '/monks-central.php' );
define( 'MONKS_CENTRAL_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'MONKS_CENTRAL_URL', 'https://example.org/wp-content/plugins/monks-central/' );

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
