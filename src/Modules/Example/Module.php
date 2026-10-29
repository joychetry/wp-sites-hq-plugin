<?php
/**
 * Example module: a "Show related posts" button under single posts.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Modules\Example;

use MonksCentral\Contracts\Module as ModuleContract;
use MonksCentral\Settings;

/**
 * Wires the example feature. Copy this folder to start a new module.
 */
final class Module implements ModuleContract {

	/**
	 * Stable id, also the key in the `modules` setting.
	 */
	public function id(): string {
		return 'example';
	}

	/**
	 * Name for the admin modules list.
	 */
	public function label(): string {
		return __( 'Related posts', 'monks-central' );
	}

	/**
	 * Description for the admin modules list.
	 */
	public function description(): string {
		return __( 'Adds a button under single posts that loads posts from the same categories.', 'monks-central' );
	}

	/**
	 * On when switched on in settings.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function is_enabled( Settings $settings ): bool {
		return $settings->module_enabled( $this->id() );
	}

	/**
	 * Hooks only. Each class loads when its hook first fires.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( Frontend::class, 'enqueue' ) );
		add_filter( 'the_content', array( Frontend::class, 'render' ) );

		// Abilities API is core since 6.9. The guard keeps the module safe if the floor is lowered.
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_init', array( Ability::class, 'register' ) );
		}
	}
}
