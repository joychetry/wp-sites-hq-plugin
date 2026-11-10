<?php
/**
 * bin/connector-compat.php: connector/compat-1x.php stays generated from the prefix map, and loads.
 *
 * @package MonksCentral\Connector
 */

namespace MonksCentral\Connector\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Runs the generator as a CLI and loads the file it checks.
 */
final class CompatGeneratorTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	/**
	 * Run the generator; returns its exit code.
	 *
	 * @param string ...$args Arguments.
	 */
	private function generator( string ...$args ): int {
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::ROOT . '/bin/connector-compat.php' ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $out, $code );
		return $code;
	}

	public function test_check_passes_on_the_committed_file(): void {
		$this->assertSame( 0, $this->generator( '--check' ) );
	}

	public function test_check_fails_when_the_file_drifts(): void {
		$file = tempnam( sys_get_temp_dir(), 'compat' );
		file_put_contents( $file, file_get_contents( self::ROOT . '/connector/compat-1x.php' ) . "\n// edited by hand\n" );
		$code = $this->generator( '--check', self::ROOT . '/docs/connector-prefix-map.json', self::ROOT . '/connector', $file );
		unlink( $file );
		$this->assertSame( 1, $code );
	}

	public function test_refuses_a_by_reference_function_without_a_wrapper(): void {
		$dir = sys_get_temp_dir() . '/compat-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/a.php', '<?php function wpshq_fill( array &$out ) {}' );
		file_put_contents( $dir . '/map.json', '{"functions":{"cm_fill":"wpshq_fill"},"classes":{},"constants":{},"hooks":{}}' );
		$code = $this->generator( $dir . '/map.json', $dir, $dir . '/out.php' );
		array_map( 'unlink', glob( $dir . '/*' ) );
		rmdir( $dir );
		$this->assertSame( 2, $code );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_old_names_forward_to_the_new_ones(): void {
		Monkey\setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		require self::ROOT . '/connector/compat-1x.php';
		$this->assertSame( wpshq_ai_ip_is_public( '8.8.8.8' ), cm_ai_ip_is_public( '8.8.8.8' ) );
		$this->assertTrue( cm_ai_ip_is_public( '8.8.8.8' ) );
		$this->assertFalse( function_exists( 'cm_acf_collect_refs' ), 'skipped while its new name is not loaded' );
		$this->assertNotFalse( has_action( 'wpshq_ai_operation_logged' ) );
		$this->assertNotFalse( has_filter( 'wpshq_mcp_skills' ) );
		Monkey\tearDown();
	}
}
