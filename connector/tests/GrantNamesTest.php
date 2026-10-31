<?php
/**
 * D8: grants the app made as Monks Central are renamed once, nothing else.
 *
 * @package MonksCentral\Connector
 */

namespace MonksCentral\Connector\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Drives CM_MCP_OAuth::rename_app_grants() against an in-memory client_name column.
 */
final class GrantNamesTest extends TestCase {

	public function test_only_the_two_app_names_change(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		// The class file boots itself on include; the table is current, so init() only adds hooks.
		Monkey\setUp();
		Monkey\Functions\when( 'get_option' )->justReturn( '1.1.0' );
		require_once dirname( __DIR__ ) . '/mcp/class-mcp-oauth.php';
		Monkey\tearDown();

		$wpdb = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Rows: id => client_name.
			 *
			 * @var array<int, string>
			 */
			public $rows = array( 'Monks Central', 'Monks Central (AI agents)', 'Claude', 'Monks Central fan club', 'WP Sites HQ' );

			/**
			 * Enough of wpdb::update() for one WHERE column.
			 *
			 * @param string                $table Table.
			 * @param array<string, string> $data  New values.
			 * @param array<string, string> $where Match.
			 */
			public function update( $table, $data, $where ): int {
				TestCase::assertSame( 'wp_cm_mcp_oauth_tokens', $table );
				$n = 0;
				foreach ( $this->rows as $i => $name ) {
					if ( $name === $where['client_name'] ) {
						$this->rows[ $i ] = $data['client_name'];
						++$n;
					}
				}
				return $n;
			}
		};
		$GLOBALS['wpdb'] = $wpdb;

		\CM_MCP_OAuth::rename_app_grants();
		\CM_MCP_OAuth::rename_app_grants();

		$this->assertSame( array( 'WP Sites HQ', 'WP Sites HQ (AI agents)', 'Claude', 'Monks Central fan club', 'WP Sites HQ' ), $wpdb->rows );
		unset( $GLOBALS['wpdb'] );
	}
}
