<?php
/**
 * cm_ability_date(): MySQL datetimes become the RFC 3339 a `date-time` output schema promises.
 *
 * @package MonksCentral\Connector
 */

namespace MonksCentral\Connector\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Loads the abilities loader with WordPress stubbed, then checks the helper.
 */
final class AbilityDateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		// Every ability file only adds hooks on include; upload-media.php reads MB_IN_BYTES.
		if ( ! defined( 'MB_IN_BYTES' ) ) {
			define( 'MB_IN_BYTES', 1048576 );
		}
		require_once dirname( __DIR__ ) . '/cm-abilities-loader.php';
		Monkey\Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Asia/Kolkata' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_site_time_gets_the_site_offset(): void {
		$this->assertSame( '2026-10-30T12:00:00+05:30', cm_ability_date( '2026-10-30 12:00:00' ) );
	}

	public function test_gmt_values_stay_utc(): void {
		$this->assertSame( '2026-10-30T12:00:00+00:00', cm_ability_date( '2026-10-30 12:00:00', true ) );
	}

	public function test_zero_and_empty_dates_are_empty(): void {
		$this->assertSame( '', cm_ability_date( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', cm_ability_date( '' ) );
	}
}
