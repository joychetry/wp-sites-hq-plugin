<?php
/**
 * Settings tests.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Tests;

use Brain\Monkey\Functions;
use MonksCentral\Settings;

final class SettingsTest extends TestCase {

	public function test_defaults_when_nothing_is_stored(): void {
		$this->stored_settings( false );

		$this->assertSame( Settings::defaults(), ( new Settings() )->all() );
	}

	public function test_sanitize_drops_unknown_keys_and_cleans_values(): void {
		$clean = Settings::sanitize(
			array(
				'modules' => array(
					'Upper' => 1,
					'<bad>'   => true,
				),
				'unknown' => 'dropped',
			)
		);

		$this->assertSame(
			array(
				'modules' => array(
					'upper'   => true,
					'bad'     => true,
				),
			),
			$clean
		);
	}

	public function test_sanitize_falls_back_to_defaults_for_wrong_types(): void {
		$this->assertSame( Settings::defaults(), Settings::sanitize( array( 'modules' => 'all' ) ) );
	}

	public function test_update_merges_modules_and_saves_without_autoload(): void {
		$this->stored_settings(
			array(
				'modules' => array(
					'one' => true,
					'two' => true,
				),
			)
		);

		Functions\expect( 'update_option' )
			->once()
			->with( Settings::OPTION, \Mockery::type( 'array' ), false )
			->andReturn( true );

		$settings = new Settings();
		$saved    = $settings->update( array( 'modules' => array( 'one' => false ) ) );

		$this->assertSame(
			array(
				'one' => false,
				'two' => true,
			),
			$saved['modules']
		);
		$this->assertFalse( $settings->module_enabled( 'one' ) );
		$this->assertTrue( $settings->module_enabled( 'two' ) );
	}
}
