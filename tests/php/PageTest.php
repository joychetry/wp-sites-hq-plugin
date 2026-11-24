<?php
/**
 * Admin screen tests: the Settings link on the Plugins screen.
 *
 * @package WPSitesHQ
 */

namespace WPSitesHQ\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WPSitesHQ\Admin\Page;
use WPSitesHQ\Plugin;
use WPSitesHQ\Settings;

final class PageTest extends TestCase {

	private Page $page;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.org/wp-admin/' . $path );
		$this->page = new Page( new Plugin( new Settings(), array() ) );
	}

	public function test_register_hooks_the_row_of_this_plugin(): void {
		Functions\expect( 'plugin_basename' )->once()->with( WPSHQ_FILE )->andReturn( 'wp-sites-hq/wp-sites-hq.php' );
		Filters\expectAdded( 'plugin_action_links_wp-sites-hq/wp-sites-hq.php' )->once()->with( array( $this->page, 'action_links' ) );

		$this->page->register();
	}

	public function test_admins_get_a_settings_link_before_deactivate(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$links = $this->page->action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertSame( '<a href="https://example.org/wp-admin/options-general.php?page=wp-sites-hq">Settings</a>', $links['settings'] );
	}

	public function test_users_who_cannot_open_the_screen_get_no_link(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		$links = array( 'deactivate' => '<a href="#">Deactivate</a>' );

		$this->assertSame( $links, $this->page->action_links( $links ) );
	}
}
