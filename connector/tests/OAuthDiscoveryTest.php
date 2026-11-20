<?php
/**
 * WPSHQ_MCP_OAuth::discovery_paths(): exact discovery paths that never claim another OAuth server's URLs.
 *
 * @package WPSitesHQ\Connector
 */

namespace WPSitesHQ\Connector\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Loads the OAuth class with WordPress stubbed, then checks the path lists.
 */
final class OAuthDiscoveryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		foreach ( array( 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ) as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
		// The file boots on include: report the table as current so install() never runs.
		Monkey\Functions\when( 'get_option' )->justReturn( '1.2.0' );
		require_once dirname( __DIR__ ) . '/mcp/class-mcp-oauth.php';
		Monkey\Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_root_install(): void {
		$p = \WPSHQ_MCP_OAuth::discovery_paths( 'https://ex.com', 'https://ex.com/wp-json/classic-monks/v1/mcp', 'https://ex.com/classic-monks/v1' );
		$this->assertSame(
			array( '/.well-known/oauth-protected-resource/wp-json/classic-monks/v1/mcp', '/.well-known/oauth-protected-resource' ),
			$p['resource']
		);
		$this->assertSame(
			array(
				'/.well-known/oauth-authorization-server/classic-monks/v1',
				'/.well-known/openid-configuration/classic-monks/v1',
				'/classic-monks/v1/.well-known/oauth-authorization-server',
				'/classic-monks/v1/.well-known/openid-configuration',
				'/.well-known/oauth-authorization-server',
			),
			$p['server']
		);
	}

	public function test_subfolder_install(): void {
		$p = \WPSHQ_MCP_OAuth::discovery_paths( 'https://ex.com/blog/', 'https://ex.com/blog/wp-json/classic-monks/v1/mcp', 'https://ex.com/blog/classic-monks/v1' );
		$this->assertSame(
			array(
				'/blog/.well-known/oauth-protected-resource/blog/wp-json/classic-monks/v1/mcp',
				'/.well-known/oauth-protected-resource/blog/wp-json/classic-monks/v1/mcp',
				'/blog/.well-known/oauth-protected-resource',
			),
			$p['resource']
		);
		$this->assertSame(
			array(
				'/.well-known/oauth-authorization-server/blog/classic-monks/v1',
				'/.well-known/openid-configuration/blog/classic-monks/v1',
				'/blog/classic-monks/v1/.well-known/oauth-authorization-server',
				'/blog/classic-monks/v1/.well-known/openid-configuration',
				'/blog/.well-known/oauth-authorization-server',
			),
			$p['server']
		);
	}

	public function test_plain_permalinks_root(): void {
		$p = \WPSHQ_MCP_OAuth::discovery_paths( 'https://ex.com', 'https://ex.com/?rest_route=/classic-monks/v1/mcp', 'https://ex.com/classic-monks/v1' );
		$this->assertSame(
			array( '/.well-known/oauth-protected-resource/classic-monks/v1/mcp', '/.well-known/oauth-protected-resource' ),
			$p['resource']
		);
		$this->assertContains( '/classic-monks/v1/.well-known/openid-configuration', $p['server'] );
	}

	public function test_plain_permalinks_subfolder(): void {
		$p = \WPSHQ_MCP_OAuth::discovery_paths( 'https://ex.com/blog', 'https://ex.com/blog/index.php?rest_route=%2Fclassic-monks%2Fv1%2Fmcp', 'https://ex.com/blog/classic-monks/v1' );
		$this->assertSame(
			array(
				'/blog/.well-known/oauth-protected-resource/blog/classic-monks/v1/mcp',
				'/.well-known/oauth-protected-resource/blog/classic-monks/v1/mcp',
				'/blog/.well-known/oauth-protected-resource',
			),
			$p['resource']
		);
	}

	public function test_novamira_paths_are_not_ours(): void {
		foreach ( array( 'https://ex.com', 'https://ex.com/blog' ) as $home ) {
			$p   = \WPSHQ_MCP_OAuth::discovery_paths( $home, "$home/wp-json/classic-monks/v1/mcp", "$home/classic-monks/v1" );
			$all = array_merge( $p['resource'], $p['server'] );
			$sub = (string) parse_url( $home, PHP_URL_PATH );
			foreach ( array(
				"/.well-known/oauth-protected-resource$sub/wp-json/novamira/v1/mcp",
				"$sub/.well-known/openid-configuration",
				"/.well-known/oauth-authorization-server$sub",
				"/.well-known/openid-configuration$sub",
			) as $theirs ) {
				if ( '' === $sub && '/.well-known/oauth-authorization-server' === $theirs ) {
					continue; // Our own back-compat bare form; Novamira answers it first when active.
				}
				$this->assertNotContains( $theirs, $all, $theirs );
			}
		}
	}
}
