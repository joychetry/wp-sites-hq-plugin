<?php
/**
 * Contract every feature module implements.
 *
 * @package WpPluginStarter
 */

namespace WpPluginStarter\Contracts;

use WpPluginStarter\Settings;

/**
 * A self-contained feature. The registry asks is_enabled() first and calls register() only
 * when it returns true, so a disabled module adds no hooks and loads none of its other classes.
 */
interface Module {

	/**
	 * Stable machine id, used as the key in the `modules` setting. Never change it after release.
	 */
	public function id(): string;

	/**
	 * Human-readable name for the admin modules list.
	 */
	public function label(): string;

	/**
	 * One-line description for the admin modules list.
	 */
	public function description(): string;

	/**
	 * Whether the module should run on this request. Keep it cheap: no queries beyond Settings.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function is_enabled( Settings $settings ): bool;

	/**
	 * Add the module's hooks. Do the real work inside the hook callbacks, not here.
	 */
	public function register(): void;
}
