<?php
/**
 * Test double that records register() calls.
 *
 * @package WPSitesHQ
 */

namespace WPSitesHQ\Tests\Fixtures;

use WPSitesHQ\Contracts\Module;
use WPSitesHQ\Settings;

abstract class RecordingModule implements Module {

	/**
	 * Ids of modules whose register() ran.
	 *
	 * @var list<string>
	 */
	public static array $registered = array();

	public function label(): string {
		return $this->id();
	}

	public function description(): string {
		return '';
	}

	public function is_enabled( Settings $settings ): bool {
		return $settings->module_enabled( $this->id() );
	}

	public function register(): void {
		self::$registered[] = $this->id();
	}
}
