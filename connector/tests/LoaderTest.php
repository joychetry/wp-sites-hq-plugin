<?php
/**
 * The version-wins loader: which bundled copy boots.
 *
 * @package MonksCentral\Connector
 */

namespace MonksCentral\Connector\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Loads connector/load.php once and drives its registry with fake copies.
 */
final class LoaderTest extends TestCase {

	/**
	 * Temp folders standing in for bundled connector/ folders.
	 *
	 * @var string[]
	 */
	private array $dirs = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		require_once dirname( __DIR__ ) . '/load.php';
	}

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			unlink( $dir . '/version.php' );
			rmdir( $dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A fake bundled copy with this version.
	 *
	 * @param string $version Library version.
	 */
	private function copy( string $version ): string {
		$dir = sys_get_temp_dir() . '/mc-connector-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/version.php', "<?php return '{$version}';" );
		$this->dirs[] = $dir;
		return $dir;
	}

	public function test_version_file_is_a_version(): void {
		$version = include dirname( __DIR__ ) . '/version.php';
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $version );
	}

	public function test_highest_version_wins_whatever_the_order(): void {
		$copies = array(
			array( 'dir' => 'a', 'connector' => '1.0.0' ),
			array( 'dir' => 'b', 'connector' => '1.10.0' ),
			array( 'dir' => 'c', 'connector' => '1.2.0' ),
		);
		$this->assertSame( 'b', monks_central_connector_pick( $copies )['dir'] );
		$this->assertSame( 'b', monks_central_connector_pick( array_reverse( $copies ) )['dir'] );
	}

	public function test_first_registered_wins_a_tie(): void {
		$copies = array(
			array( 'dir' => 'free', 'connector' => '1.0.0' ),
			array( 'dir' => 'monks-central', 'connector' => '1.0.0' ),
		);
		$this->assertSame( 'free', monks_central_connector_pick( $copies )['dir'] );
	}

	public function test_nothing_registered_boots_nothing(): void {
		$this->assertNull( monks_central_connector_pick( array() ) );
	}

	public function test_register_reads_each_copys_version_once_per_folder(): void {
		$old = $this->copy( '1.0.0' );
		$new = $this->copy( '1.1.0' );
		monks_central_connector_register(
			array(
				'dir'     => $old . '/',
				'file'    => 'free/free.php',
				'name'    => 'Old',
				'version' => '2.3.4',
			)
		);
		monks_central_connector_register(
			array(
				'dir'  => $new,
				'file' => 'pro/pro.php',
				'name' => 'New',
			)
		);
		monks_central_connector_register(
			array(
				'dir'  => $new,
				'file' => 'pro/pro.php',
				'name' => 'New',
			)
		);

		$mine = array_values(
			array_filter(
				monks_central_connector_copies(),
				fn( $copy ) => in_array( $copy['dir'], array( $old, $new ), true )
			)
		);
		$this->assertCount( 2, $mine );
		$this->assertSame( '1.0.0', $mine[0]['connector'] );
		$this->assertSame( '2.3.4', $mine[0]['version'], 'the host keeps its own version' );
		$this->assertSame( 'New', monks_central_connector_pick( $mine )['name'] );
	}
}
