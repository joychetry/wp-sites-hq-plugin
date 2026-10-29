<?php
/**
 * Settings tests.
 *
 * @package WpPluginStarter
 */

namespace WpPluginStarter\Tests;

use Brain\Monkey\Functions;
use WpPluginStarter\Settings;

final class SettingsTest extends TestCase {

	public function test_defaults_when_nothing_is_stored(): void {
		$this->stored_settings( false );

		$this->assertSame( Settings::defaults(), ( new Settings() )->all() );
	}

	public function test_sanitize_drops_unknown_keys_and_cleans_values(): void {
		$clean = Settings::sanitize(
			array(
				'example_label' => '  <b>Read more</b>  ',
				'example_count' => '99',
				'modules'       => array(
					'Example' => 1,
					'<bad>'   => true,
				),
				'unknown'       => 'dropped',
			)
		);

		$this->assertSame(
			array(
				'modules'       => array(
					'example' => true,
					'bad'     => true,
				),
				'example_label' => 'Read more',
				'example_count' => 10,
			),
			$clean
		);
	}

	public function test_sanitize_falls_back_to_defaults_for_wrong_types(): void {
		$clean = Settings::sanitize(
			array(
				'example_label' => array( 'not', 'a', 'string' ),
				'example_count' => 'many',
				'modules'       => 'all',
			)
		);

		$this->assertSame( Settings::defaults(), $clean );
	}

	public function test_sanitize_clamps_and_truncates(): void {
		$clean = Settings::sanitize(
			array(
				'example_count' => -5,
				'example_label' => str_repeat( 'a', 100 ),
			)
		);

		$this->assertSame( 1, $clean['example_count'] );
		$this->assertSame( 60, mb_strlen( $clean['example_label'] ) );
	}

	public function test_update_merges_modules_and_saves_without_autoload(): void {
		$this->stored_settings(
			array(
				'modules'       => array(
					'example' => true,
					'other'   => true,
				),
				'example_count' => 5,
			)
		);

		Functions\expect( 'update_option' )
			->once()
			->with( Settings::OPTION, \Mockery::type( 'array' ), false )
			->andReturn( true );

		$settings = new Settings();
		$saved    = $settings->update( array( 'modules' => array( 'example' => false ) ) );

		$this->assertSame(
			array(
				'example' => false,
				'other'   => true,
			),
			$saved['modules']
		);
		$this->assertSame( 5, $saved['example_count'] );
		$this->assertFalse( $settings->module_enabled( 'example' ) );
		$this->assertTrue( $settings->module_enabled( 'other' ) );
	}
}
