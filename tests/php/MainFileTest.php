<?php
/**
 * Main plugin file tests.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Tests;

use Brain\Monkey\Actions;

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
}
