<?php
/**
 * The plugin's admin screen under Settings.
 *
 * @package WpPluginStarter
 */

namespace WpPluginStarter\Admin;

use WpPluginStarter\Plugin;

/**
 * Adds Settings > WP Plugin Starter and loads the React app on that screen only.
 */
final class Page {

	public const SLUG = 'wp-plugin-starter';

	/**
	 * Hook suffix of the page, set on `admin_menu`.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Set up the page.
	 *
	 * @param Plugin $plugin Booted plugin, for the module list and settings.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Add the hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the menu item. Admins only.
	 */
	public function add_page(): void {
		$this->hook_suffix = (string) add_options_page(
			__( 'WP Plugin Starter', 'wp-plugin-starter' ),
			__( 'WP Plugin Starter', 'wp-plugin-starter' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Page shell. The React app mounts into #wps-admin.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="wrap"><h1>%s</h1><div id="wps-admin"></div><noscript>%s</noscript></div>',
			esc_html( get_admin_page_title() ),
			esc_html__( 'This screen needs JavaScript.', 'wp-plugin-starter' )
		);
	}

	/**
	 * Enqueue the app on this screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		$asset = Plugin::asset( 'admin' );
		if ( $hook_suffix !== $this->hook_suffix || null === $asset ) {
			return;
		}

		wp_enqueue_script( 'wps-admin', WPS_URL . 'build/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'wps-admin', WPS_URL . 'build/admin.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( 'wps-admin', 'rtl', 'replace' );
		wp_set_script_translations( 'wps-admin', 'wp-plugin-starter' );

		$modules = array();
		foreach ( $this->plugin->modules() as $id => $module ) {
			$modules[] = array(
				'id'          => $id,
				'label'       => $module->label(),
				'description' => $module->description(),
			);
		}

		// Initial data, so the screen renders without a first REST round trip.
		wp_add_inline_script(
			'wps-admin',
			'window.wpsAdmin = ' . wp_json_encode(
				array(
					'modules'  => $modules,
					'settings' => $this->plugin->settings()->all(),
				)
			) . ';',
			'before'
		);
	}
}
