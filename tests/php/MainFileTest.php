<?php
/**
 * Main plugin file tests.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * The bootstrap already defines the constants, as the first include of wp-sites-hq.php does.
 */
final class MainFileTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
	}

	public function test_loading_the_same_file_twice_is_silent(): void {
		Actions\expectAdded( 'admin_notices' )->never();
		Actions\expectAdded( 'plugins_loaded' )->never();

		// failOnWarning turns any "Constant ... already defined" warning into a failure.
		include MONKS_CENTRAL_FILE;
		include MONKS_CENTRAL_FILE;

		$this->assertSame( dirname( __DIR__, 2 ) . '/', MONKS_CENTRAL_DIR );
	}

	public function test_a_second_copy_in_another_folder_shows_one_notice(): void {
		$dir = sys_get_temp_dir() . '/wp-sites-hq-copy-' . uniqid();
		mkdir( $dir );
		copy( MONKS_CENTRAL_FILE, $dir . '/wp-sites-hq.php' );

		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'plugins_loaded' )->never();

		include $dir . '/wp-sites-hq.php';

		unlink( $dir . '/wp-sites-hq.php' );
		rmdir( $dir );
		$this->assertSame( MONKS_CENTRAL_DIR . 'wp-sites-hq.php', MONKS_CENTRAL_FILE );
	}

	public function test_a_second_copy_registers_its_connector(): void {
		// Otherwise deactivating the copy that runs looks like the last host leaving, which revokes every connection.
		require_once MONKS_CENTRAL_DIR . 'connector/load.php';
		$dir = sys_get_temp_dir() . '/wp-sites-hq-copy-' . uniqid();
		mkdir( $dir . '/connector', 0777, true );
		copy( MONKS_CENTRAL_FILE, $dir . '/wp-sites-hq.php' );
		copy( MONKS_CENTRAL_DIR . 'connector/version.php', $dir . '/connector/version.php' );
		Functions\when( 'get_file_data' )->justReturn( array( 'Version' => '9.0.0' ) );

		include $dir . '/wp-sites-hq.php';

		$copy = array_column( monks_central_connector_copies(), null, 'dir' )[ $dir . '/connector' ] ?? null;
		unlink( $dir . '/connector/version.php' );
		rmdir( $dir . '/connector' );
		unlink( $dir . '/wp-sites-hq.php' );
		rmdir( $dir );
		$this->assertSame( array( 'WP Sites HQ', '9.0.0', $dir . '/wp-sites-hq.php' ), array( $copy['name'] ?? null, $copy['version'] ?? null, $copy['file'] ?? null ) );
	}
}
