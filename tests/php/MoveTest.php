<?php
/**
 * The 0.1.4 bridge: the swap order and the uninstall rule that keep grants and settings.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Tests;

use Brain\Monkey\Functions;
use MonksCentral\Move;

/**
 * WP_PLUGIN_DIR is a temp folder; WP Sites HQ counts as installed when its main file is there.
 */
final class MoveTest extends TestCase {

	private const PLUGINS = '/mc-move-test-plugins';

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'ABSPATH' => '/', 'WP_PLUGIN_DIR' => sys_get_temp_dir() . self::PLUGINS, 'WP_UNINSTALL_PLUGIN' => Move::OLD ) as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
		@mkdir( WP_PLUGIN_DIR . '/wp-sites-hq', 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- may exist from an earlier test
	}

	protected function tearDown(): void {
		@unlink( WP_PLUGIN_DIR . '/' . Move::NEW ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- absent in some tests
		parent::tearDown();
	}

	/**
	 * Mark WP Sites HQ installed.
	 */
	private function install_new(): void {
		touch( WP_PLUGIN_DIR . '/' . Move::NEW );
	}

	public function test_activates_the_new_folder_before_it_deactivates_the_old_one(): void {
		$this->install_new();
		$calls = array();
		Functions\when( 'activate_plugin' )->alias(
			function ( $plugin, $redirect, $network ) use ( &$calls ) {
				$calls[] = array( 'activate', $plugin, $network );
				return null;
			}
		);
		Functions\when( 'deactivate_plugins' )->alias(
			function ( $plugin, $silent, $network ) use ( &$calls ) {
				$calls[] = array( 'deactivate', $plugin, $silent, $network );
			}
		);

		$this->assertTrue( Move::move( true ) );
		// Silent, so the old folder's deactivation hook cannot revoke connections.
		$this->assertSame( array( array( 'activate', Move::NEW, true ), array( 'deactivate', Move::OLD, true, true ) ), $calls );
	}

	public function test_keeps_the_old_folder_active_when_activation_fails(): void {
		$this->install_new();
		Functions\when( 'activate_plugin' )->justReturn( new \WP_Error( 'plugin_invalid' ) );
		Functions\expect( 'deactivate_plugins' )->never();

		$result = Move::move( false );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'activate', $result->get_error_code() );
	}

	public function test_without_a_release_nothing_changes(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"message":"Not Found"}' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\expect( 'activate_plugin' )->never();
		Functions\expect( 'deactivate_plugins' )->never();

		$this->assertSame( 'unavailable', Move::move( false )->get_error_code() );
	}

	/**
	 * Run uninstall.php with the connector's cleanup stubbed out.
	 */
	private function uninstall(): void {
		require_once dirname( __DIR__, 2 ) . '/connector/uninstall.php';
		Functions\when( 'monks_central_connector_uninstall' )->justReturn( null );
		Functions\when( 'delete_site_transient' )->justReturn( true );
		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	public function test_uninstall_keeps_settings_while_wp_sites_hq_is_installed(): void {
		$this->install_new();
		Functions\expect( 'delete_option' )->never();
		$this->uninstall();
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . Move::NEW );
	}

	public function test_uninstall_deletes_settings_without_wp_sites_hq(): void {
		Functions\expect( 'delete_option' )->once()->with( 'monks_central_settings' );
		$this->uninstall();
		$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/' . Move::NEW );
	}
}
