<?php
/**
 * Just enough of WP_REST_Response and WPSHQ_MCP_Server for the OAuth discovery tests.
 *
 * @package WPSitesHQ\Connector
 */

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { // phpcs:ignore
		public function __construct( public $data = null, public $status = 200, public $headers = array() ) {}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_headers() {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WPSHQ_MCP_Server' ) ) {
	class WPSHQ_MCP_Server { // phpcs:ignore
		const REST_NAMESPACE = 'classic-monks/v1';

		public static function endpoint_url() {
			return rest_url( self::REST_NAMESPACE . '/mcp' );
		}
	}
}
