<?php
/**
 * Module registry tests.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Tests;

use Brain\Monkey\Functions;
use MonksCentral\Plugin;
use MonksCentral\Settings;
use MonksCentral\Tests\Fixtures\OffModule;
use MonksCentral\Tests\Fixtures\OnModule;
use MonksCentral\Tests\Fixtures\RecordingModule;

final class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		RecordingModule::$registered = array();
		Functions\when( 'is_admin' )->justReturn( false );
	}

	public function test_only_enabled_modules_register_hooks(): void {
		$this->stored_settings(
			array(
				'modules' => array(
					'on'  => true,
					'off' => false,
				),
			)
		);

		$plugin = new Plugin( new Settings(), array( OnModule::class, OffModule::class ) );
		$plugin->register();

		$this->assertSame( array( 'on' ), RecordingModule::$registered );
		$this->assertSame( array( 'on' ), $plugin->active_modules() );
		$this->assertSame( array( 'on', 'off' ), array_keys( $plugin->modules() ) );
	}

	public function test_modules_missing_from_settings_stay_off(): void {
		$this->stored_settings( array( 'modules' => array() ) );

		$plugin = new Plugin( new Settings(), array( OnModule::class, OffModule::class ) );
		$plugin->register();

		$this->assertSame( array(), RecordingModule::$registered );
	}

	public function test_shipped_modules_are_on_by_default(): void {
		$this->stored_settings( false );

		$plugin = new Plugin( new Settings() );
		$plugin->register();

		$this->assertSame( array( 'example' ), $plugin->active_modules() );
	}
}
