<?php
/**
 * GitHub release updates and the SHA-256 check.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Tests;

use Brain\Monkey\Functions;
use MonksCentral\Updater;

/**
 * Release parsing, the update offer and the checksum gate.
 */
final class UpdaterTest extends TestCase {

	private const ZIP = 'https://github.com/joychetry/monks-central-wp/releases/download/v9.0.0/monks-central.zip';

	/**
	 * A GitHub release as the API returns it.
	 *
	 * @param array<string, mixed> $over Fields to replace.
	 * @return array<string, mixed>
	 */
	private function release_json( array $over = array() ): array {
		return $over + array(
			'tag_name'   => 'v9.0.0',
			'draft'      => false,
			'prerelease' => false,
			'html_url'   => 'https://github.com/joychetry/monks-central-wp/releases/tag/v9.0.0',
			'body'       => 'Notes',
			'assets'     => array(
				array(
					'name'                 => 'monks-central.zip',
					'browser_download_url' => self::ZIP,
				),
				array(
					'name'                 => 'monks-central.zip.sha256',
					'browser_download_url' => self::ZIP . '.sha256',
				),
			),
		);
	}

	public function test_a_release_with_zip_and_checksum_is_offered(): void {
		$release = Updater::parse_release( $this->release_json() );
		$this->assertSame( '9.0.0', $release['version'] ?? null );
		$this->assertSame( self::ZIP, $release['package'] ?? null );
		$this->assertSame( self::ZIP . '.sha256', $release['checksum'] ?? null );
	}

	public function test_prereleases_drafts_and_releases_without_checksum_are_skipped(): void {
		$this->assertNull( Updater::parse_release( $this->release_json( array( 'prerelease' => true ) ) ) );
		$this->assertNull( Updater::parse_release( $this->release_json( array( 'draft' => true ) ) ) );
		$this->assertNull( Updater::parse_release( $this->release_json( array( 'assets' => array( $this->release_json()['assets'][0] ) ) ) ) );
		$this->assertNull( Updater::parse_release( $this->release_json( array( 'tag_name' => 'nightly' ) ) ) );
	}

	public function test_checksum_is_read_from_sha256sum_output(): void {
		$hex = str_repeat( 'ab', 32 );
		$this->assertSame( $hex, Updater::parse_checksum( strtoupper( $hex ) . "  monks-central.zip\n" ) );
		$this->assertNull( Updater::parse_checksum( 'not a digest' ) );
	}

	public function test_only_a_matching_file_passes(): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'mc' );
		file_put_contents( $file, 'zip bytes' );
		$this->assertTrue( Updater::matches( $file, hash( 'sha256', 'zip bytes' ) ) );
		$this->assertFalse( Updater::matches( $file, hash( 'sha256', 'other bytes' ) ) );
		unlink( $file );
	}

	/**
	 * Serve this release from the cache.
	 */
	private function cached_release(): void {
		Functions\when( 'get_site_transient' )->justReturn( array( 'release' => Updater::parse_release( $this->release_json() ) ) );
	}

	public function test_a_newer_release_is_offered_as_an_update(): void {
		$this->cached_release();
		Functions\when( 'plugin_basename' )->justReturn( 'monks-central/monks-central.php' );
		$transient = ( new Updater() )->offer( new \stdClass() );
		$this->assertSame( '9.0.0', $transient->response['monks-central/monks-central.php']->new_version );
		$this->assertSame( self::ZIP, $transient->response['monks-central/monks-central.php']->package );
	}

	public function test_the_installed_version_on_disk_decides_over_the_loaded_constant(): void {
		$this->cached_release();
		Functions\when( 'plugin_basename' )->justReturn( 'monks-central/monks-central.php' );
		$transient          = new \stdClass();
		$transient->checked = array( 'monks-central/monks-central.php' => '9.0.0' );
		$transient          = ( new Updater() )->offer( $transient );
		$this->assertArrayNotHasKey( 'monks-central/monks-central.php', $transient->response ?? array() );
		$this->assertSame( '9.0.0', $transient->no_update['monks-central/monks-central.php']->new_version );
	}

	public function test_check_again_on_dashboard_updates_skips_the_cache(): void {
		$this->cached_release();
		Functions\when( 'doing_action' )->alias( fn( $hook ) => 'load-update-core.php' === $hook );
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( (string) wp_json_encode( $this->release_json( array( 'tag_name' => 'v9.1.0' ) ) ) );
		Functions\when( 'set_site_transient' )->justReturn( true );
		$_GET['force-check'] = '1';
		$release             = ( new Updater() )->release();
		unset( $_GET['force-check'] );
		$this->assertSame( '9.1.0', $release['version'] ?? null );
	}

	public function test_wrapped_release_notes_read_as_whole_lines(): void {
		Functions\when( 'get_site_transient' )->justReturn( array( 'release' => Updater::parse_release( $this->release_json( array( 'body' => "- One item that\n  wraps.\n- Two" ) ) ) ) );
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		$info = ( new Updater() )->details( false, 'plugin_information', (object) array( 'slug' => 'monks-central' ) );
		$this->assertSame( "- One item that wraps.\n- Two", $info->sections['changelog'] );
	}

	public function test_a_download_that_does_not_match_its_checksum_is_refused(): void {
		$this->cached_release();
		$file = (string) tempnam( sys_get_temp_dir(), 'mc' );
		file_put_contents( $file, 'tampered' );
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( hash( 'sha256', 'original' ) . '  monks-central.zip' );
		Functions\when( 'download_url' )->justReturn( $file );
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );

		$result = ( new Updater() )->download( false, self::ZIP );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'monks_central_checksum_mismatch', $result->get_error_code() );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_a_matching_download_is_handed_to_the_upgrader(): void {
		$this->cached_release();
		$file = (string) tempnam( sys_get_temp_dir(), 'mc' );
		file_put_contents( $file, 'original' );
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( hash( 'sha256', 'original' ) );
		Functions\when( 'download_url' )->justReturn( $file );

		$this->assertSame( $file, ( new Updater() )->download( false, self::ZIP ) );
		unlink( $file );
	}

	public function test_other_packages_are_left_to_core(): void {
		$this->cached_release();
		$this->assertFalse( ( new Updater() )->download( false, 'https://downloads.wordpress.org/plugin/akismet.zip' ) );
	}
}
