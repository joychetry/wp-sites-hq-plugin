<?php
/**
 * Base test case: Brain Monkey stubs WordPress functions, so no WordPress install is needed.
 *
 * @package WpPluginStarter
 */

namespace WpPluginStarter\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use WpPluginStarter\Settings;

/**
 * Sets up and tears down Brain Monkey around every test.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'sanitize_text_field' => static fn( string $value ): string => trim( strip_tags( $value ) ),
				'sanitize_key'        => static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ),
			)
		);
		Settings::flush();
	}

	protected function tearDown(): void {
		Settings::flush();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Make get_option() return this value for the settings option.
	 *
	 * @param mixed $stored Stored option value.
	 */
	protected function stored_settings( mixed $stored ): void {
		Functions\when( 'get_option' )->justReturn( $stored );
	}
}
