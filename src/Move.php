<?php
/**
 * The 0.1.4 bridge: moves the site from this folder to WP Sites HQ (`wp-sites-hq/`).
 *
 * WordPress keys a plugin by its folder, so an update cannot rename it. This notice
 * installs WP Sites HQ from its latest GitHub release, activates it and deactivates
 * this folder, in that order: grants are kept only while another connector host is active.
 *
 * @package MonksCentral
 */

namespace MonksCentral;

use WP_Error;

/**
 * The "Move this site" notice and its form handler.
 */
final class Move {

	public const REPO = 'joychetry/wp-sites-hq-plugin';
	public const ZIP  = 'wp-sites-hq.zip';
	public const OLD  = 'monks-central/monks-central.php';
	public const NEW  = 'wp-sites-hq/wp-sites-hq.php';
	// Admin-post action, nonce action and the query arg that carries a failure back.
	private const ACTION  = 'monks_central_move';
	private const SCREENS = array( 'dashboard', 'plugins', 'settings_page_monks-central', 'dashboard-network', 'plugins-network' );

	/**
	 * Add the hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'network_admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Whether this folder is network-activated, so the move happens in Network Admin.
	 */
	private static function network(): bool {
		return is_multisite() && is_plugin_active_for_network( self::OLD );
	}

	/**
	 * Whether the current user may move the site in one click.
	 */
	private static function can_click(): bool {
		$installed = file_exists( WP_PLUGIN_DIR . '/' . self::NEW );
		return current_user_can( 'activate_plugins' )
			&& ( $installed || current_user_can( 'install_plugins' ) )
			&& ( ! self::network() || current_user_can( 'manage_network_plugins' ) );
	}

	/**
	 * The notice on the Dashboard, the Plugins screen and Settings > Monks Central.
	 */
	public function notice(): void {
		$screen = get_current_screen();
		if ( null === $screen || ! in_array( $screen->id, self::SCREENS, true ) || ! current_user_can( 'activate_plugins' ) || self::network() !== is_network_admin() ) {
			return;
		}
		$errors = array(
			'unavailable' => __( 'WP Sites HQ is not available yet. Try again later.', 'monks-central' ),
			'download'    => __( 'WP Sites HQ could not be downloaded.', 'monks-central' ),
			'checksum'    => __( 'The download did not match its SHA-256 checksum, so it was not installed.', 'monks-central' ),
			'files'       => __( 'WordPress could not install plugins on this site.', 'monks-central' ),
			'activate'    => __( 'WP Sites HQ could not be activated.', 'monks-central' ),
		);
		$error  = $errors[ sanitize_key( wp_unslash( $_GET[ self::ACTION ] ?? '' ) ) ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- picks one of the fixed messages above
		$click  = self::can_click();
		$url    = 'https://github.com/' . self::REPO . '/releases/latest/download/' . self::ZIP;
		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Monks Central is now WP Sites HQ. Move this site.', 'monks-central' ); ?></strong>
				<?php esc_html_e( 'The move installs WP Sites HQ, activates it and deactivates Monks Central (old). Connections and settings stay.', 'monks-central' ); ?></p>
			<?php if ( '' !== $error ) : ?>
				<p><?php echo esc_html( __( 'The move did not finish:', 'monks-central' ) . ' ' . $error ); ?></p>
			<?php endif; ?>
			<?php if ( $click ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Move this site', 'monks-central' ); ?></button></p>
				</form>
			<?php endif; ?>
			<?php if ( ! $click || '' !== $error ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: link to wp-sites-hq.zip */
						esc_html__( 'To move by hand: download %s, upload it under Plugins > Add New Plugin > Upload Plugin, activate WP Sites HQ, then deactivate and delete Monks Central (old). Activate WP Sites HQ first: deleting Monks Central (old) while WP Sites HQ is not active removes this site\'s connections.', 'monks-central' ),
						'<a href="' . esc_url( $url ) . '">wp-sites-hq.zip</a>'
					);
					?>
				</p>
			<?php endif; ?>
			<p><?php esc_html_e( 'The Updates screen may have offered 0.2.0: this is the 0.1.4 bridge, which only does the move.', 'monks-central' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The "Move this site" form.
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! self::can_click() ) {
			wp_die( esc_html__( 'You are not allowed to move this site.', 'monks-central' ), '', array( 'response' => 403 ) );
		}
		$network = self::network();
		$result  = self::move( $network );
		$plugins = $network ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( self::ACTION, $result->get_error_code(), wp_get_referer() ? wp_get_referer() : $plugins ) );
			exit;
		}
		wp_die(
			esc_html__( 'Done. This site now runs WP Sites HQ. You can delete Monks Central (old) on the Plugins screen.', 'monks-central' ),
			esc_html__( 'Moved to WP Sites HQ', 'monks-central' ),
			array(
				'response'  => 200,
				'link_url'  => esc_url( $plugins ),
				'link_text' => esc_html__( 'Go to Plugins', 'monks-central' ),
			)
		);
	}

	/**
	 * Install WP Sites HQ if needed, activate it, then deactivate this folder.
	 *
	 * @param bool $network Network-wide, as this folder is.
	 * @return true|WP_Error
	 */
	public static function move( bool $network ) {
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . self::NEW ) ) {
			$installed = self::install();
			if ( is_wp_error( $installed ) ) {
				return $installed;
			}
		}
		// New first: with no other host active, the connector forgets every grant when this one goes.
		if ( is_wp_error( activate_plugin( self::NEW, '', $network ) ) ) {
			return new WP_Error( 'activate' );
		}
		// Silent: in this request WP Sites HQ is not loaded yet, so this folder's deactivation hook would see itself as the last host.
		deactivate_plugins( self::OLD, true, $network );
		return true;
	}

	/**
	 * Download wp-sites-hq.zip from the latest release, check its SHA-256 and install it.
	 *
	 * @return true|WP_Error
	 */
	private static function install() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		$assets   = is_array( $data ) && is_array( $data['assets'] ?? null ) ? wp_list_pluck( $data['assets'], 'browser_download_url', 'name' ) : array();
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $assets[ self::ZIP ] ) || empty( $assets[ self::ZIP . '.sha256' ] ) ) {
			return new WP_Error( 'unavailable' );
		}
		$sum      = wp_remote_get( $assets[ self::ZIP . '.sha256' ], array( 'timeout' => 15 ) );
		$expected = Updater::parse_checksum( wp_remote_retrieve_body( $sum ) );
		if ( null === $expected ) {
			return new WP_Error( 'checksum' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$file = download_url( $assets[ self::ZIP ] );
		if ( is_wp_error( $file ) ) {
			return new WP_Error( 'download' );
		}
		if ( ! Updater::matches( $file, $expected ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'checksum' );
		}
		// The quiet skin fails instead of asking for FTP details when WordPress cannot write files.
		$result = ( new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() ) )->install( $file );
		wp_delete_file( $file );
		return true === $result && file_exists( WP_PLUGIN_DIR . '/' . self::NEW ) ? true : new WP_Error( 'files' );
	}
}
