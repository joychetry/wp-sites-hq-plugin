<?php
/**
 * Updates from GitHub Releases.
 *
 * The wp.org build leaves this file out (`bin/build-zip.sh --wporg`): wp.org
 * forbids plugins that update themselves, and Plugin::register() only hooks it
 * when the class exists.
 *
 * @package MonksCentral
 */

namespace MonksCentral;

use WP_Error;

/**
 * Offers the latest non-pre-release GitHub release as a plugin update and
 * installs its zip only when the zip's SHA-256 matches the published checksum.
 *
 * A release carries two assets: `wp-sites-hq.zip` and `wp-sites-hq.zip.sha256`
 * (the hex digest, optionally followed by the file name, as `sha256sum` prints it).
 */
final class Updater {

	public const REPO  = 'joychetry/wp-sites-hq-plugin';
	public const ZIP   = 'wp-sites-hq.zip';
	public const CACHE = 'monks_central_release';
	private const SLUG = 'wp-sites-hq';
	// 12 hours.
	private const CHECKED = 43200;

	/**
	 * Add the hooks.
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'offer' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 2 );
	}

	/**
	 * Put the latest release into the update check.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function offer( $transient ) {
		if ( ! $transient instanceof \stdClass ) {
			return $transient;
		}
		$release = $this->release();
		if ( null === $release ) {
			return $transient;
		}
		$file = plugin_basename( MONKS_CENTRAL_FILE );
		$item = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $file,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '7.1',
			'requires_php' => '8.1',
		);
		// Core fills `checked` from disk; the constant is stale in the request that just installed the update.
		$installed = (string) ( $transient->checked[ $file ] ?? MONKS_CENTRAL_VERSION );
		if ( version_compare( $release['version'], $installed, '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	/**
	 * "View details" in wp-admin, from the release notes.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action plugins_api action.
	 * @param mixed  $args   Request args.
	 * @return mixed
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( is_object( $args ) ? ( $args->slug ?? '' ) : '' ) ) {
			return $result;
		}
		$release = $this->release();
		if ( null === $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'WP Sites HQ',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'requires'      => '7.1',
			'requires_php'  => '8.1',
			// Markdown list items wrap onto indented lines; join them so they don't break mid-sentence.
			'sections'      => array( 'changelog' => wpautop( esc_html( (string) preg_replace( '/\n[ \t]+(?=\S)/', ' ', $release['notes'] ) ) ) ),
		);
	}

	/**
	 * Download our package ourselves and refuse it unless its SHA-256 matches the release's checksum.
	 *
	 * @param mixed  $reply   False to let core download, or a file path / WP_Error.
	 * @param string $package Package URL.
	 * @return mixed
	 */
	public function download( $reply, $package ) {
		$release = $this->release();
		if ( false !== $reply || null === $release || $package !== $release['package'] ) {
			return $reply;
		}
		$expected = $this->checksum( $release['checksum'] );
		if ( null === $expected ) {
			return new WP_Error( 'monks_central_no_checksum', __( 'The update has no SHA-256 checksum, so it was not installed.', 'wp-sites-hq' ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! self::matches( $file, $expected ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'monks_central_checksum_mismatch', __( 'The downloaded update does not match its SHA-256 checksum, so it was not installed.', 'wp-sites-hq' ) );
		}
		return $file;
	}

	/**
	 * Whether a file's SHA-256 is the expected hex digest.
	 *
	 * @param string $file     Local file.
	 * @param string $expected Hex digest.
	 */
	public static function matches( string $file, string $expected ): bool {
		$actual = hash_file( 'sha256', $file );
		return false !== $actual && hash_equals( strtolower( $expected ), $actual );
	}

	/**
	 * The hex digest from a .sha256 asset, or null.
	 *
	 * @param string $url Asset URL.
	 */
	private function checksum( string $url ): ?string {
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return self::parse_checksum( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * The digest at the start of `sha256sum` output, or null.
	 *
	 * @param string $text Asset body.
	 */
	public static function parse_checksum( string $text ): ?string {
		return preg_match( '/^\s*([a-f0-9]{64})\b/i', $text, $m ) ? strtolower( $m[1] ) : null;
	}

	/**
	 * The latest non-pre-release release with both assets, cached for 12 hours (failures too).
	 *
	 * @return array{version: string, package: string, checksum: string, url: string, notes: string}|null
	 */
	public function release(): ?array {
		$cached = get_site_transient( self::CACHE );
		// "Check again" on Dashboard > Updates asks GitHub now, as core asks wp.org.
		$forced = doing_action( 'load-update-core.php' ) && ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only refresh, as core does
		if ( is_array( $cached ) && ! $forced ) {
			return $cached['release'] ?? null;
		}
		$release  = null;
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data    = json_decode( wp_remote_retrieve_body( $response ), true );
			$release = is_array( $data ) ? self::parse_release( $data ) : null;
		}
		set_site_transient( self::CACHE, array( 'release' => $release ), self::CHECKED );
		return $release;
	}

	/**
	 * Pick what the updater needs from a GitHub release.
	 *
	 * @param array<string, mixed> $data Release JSON.
	 * @return array{version: string, package: string, checksum: string, url: string, notes: string}|null
	 */
	public static function parse_release( array $data ): ?array {
		// releases/latest never returns these, but a proxy or a later API change might.
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
		$assets  = array();
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'], $asset['browser_download_url'] ) ) {
				$assets[ (string) $asset['name'] ] = (string) $asset['browser_download_url'];
			}
		}
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) || ! isset( $assets[ self::ZIP ], $assets[ self::ZIP . '.sha256' ] ) ) {
			return null;
		}
		return array(
			'version'  => $version,
			'package'  => $assets[ self::ZIP ],
			'checksum' => $assets[ self::ZIP . '.sha256' ],
			'url'      => (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO . '/releases' ),
			'notes'    => (string) ( $data['body'] ?? '' ),
		);
	}
}
