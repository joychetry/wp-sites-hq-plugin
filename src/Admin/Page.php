<?php
/**
 * The plugin's admin screen under Settings.
 *
 * @package WPSitesHQ
 */

namespace WPSitesHQ\Admin;

use WPSitesHQ\Plugin;
use WPSitesHQ\Rest\ConnectorController;

/**
 * Adds Settings > WP Sites HQ and loads the React app on that screen only.
 */
final class Page {

	public const SLUG = 'wp-sites-hq';

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
		// The screen sits under Settings, where nobody looks after installing. Link it from the plugin's row too.
		add_filter( 'plugin_action_links_' . plugin_basename( WPSHQ_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the menu item. Admins only.
	 */
	public function add_page(): void {
		$this->hook_suffix = (string) add_options_page(
			__( 'WP Sites HQ', 'wp-sites-hq' ),
			__( 'WP Sites HQ', 'wp-sites-hq' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Put a Settings link before Deactivate on the Plugins screen.
	 *
	 * Only for users who can open the screen (same capability as the menu item),
	 * so nobody gets a link to a page that refuses them. Keyed `settings`, so other
	 * code can find or remove it. Network Admin uses a different filter and has no
	 * such screen, so the link shows on each site's Plugins screen only.
	 *
	 * @param array<string|int, string> $links Action links WordPress built for the row.
	 * @return array<string|int, string>
	 */
	public function action_links( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
			esc_html__( 'Settings', 'wp-sites-hq' )
		);

		return array( 'settings' => $settings ) + $links;
	}

	/**
	 * Page shell. The React app mounts into #wp-sites-hq-admin.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="wrap"><h1>%s</h1><div id="wp-sites-hq-admin"></div><noscript>%s</noscript></div>',
			esc_html( get_admin_page_title() ),
			esc_html__( 'This screen needs JavaScript.', 'wp-sites-hq' )
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

		wp_enqueue_script( 'wp-sites-hq-admin', WPSHQ_URL . 'build/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'wp-sites-hq-admin', WPSHQ_URL . 'build/admin.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( 'wp-sites-hq-admin', 'rtl', 'replace' );
		wp_set_script_translations( 'wp-sites-hq-admin', 'wp-sites-hq' );

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
			'wp-sites-hq-admin',
			'window.wpshqAdmin = ' . wp_json_encode(
				array(
					'modules'   => $modules,
					'settings'  => $this->plugin->settings()->all(),
					'connector' => class_exists( 'WPSHQ_Connector' ) ? ( new ConnectorController() )->get_screen()->get_data() : null,
				)
			) . ';',
			'before'
		);
	}
}
