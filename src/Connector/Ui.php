<?php
/**
 * The connector's screens in this plugin.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Connector;

use MonksCentral\Admin\Page;
use MonksCentral\Plugin;

/**
 * Renders the OAuth consent page and the approval page from the connector's view data.
 * The connection screen itself is the React app on Settings > Monks Central.
 */
final class Ui implements \Monks_Central_Connector_UI {

	/**
	 * Settings > Monks Central.
	 *
	 * @return string
	 */
	public function page_url() {
		return admin_url( 'options-general.php?page=' . Page::SLUG );
	}

	/**
	 * Print the consent page.
	 *
	 * @param array<string, mixed> $view See the interface.
	 * @return void
	 */
	public function render_consent( array $view ) {
		$asset = Plugin::asset( 'consent' );
		wp_register_style( 'monks-central-consent', MONKS_CENTRAL_URL . 'build/consent.css', array(), $asset['version'] ?? MONKS_CENTRAL_VERSION );
		require MONKS_CENTRAL_DIR . 'templates/consent.php';
	}

	/**
	 * Print the approval page body.
	 *
	 * @param array<string, mixed> $view See the interface.
	 * @return void
	 */
	public function render_approval( array $view ) {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Approve change', 'monks-central' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: connection name, 2: username, 3: tool name */
					esc_html__( '%1$s, signed in as %2$s, wants to run %3$s.', 'monks-central' ),
					'<strong>' . esc_html( $view['client'] ) . '</strong>',
					'<strong>' . esc_html( $view['user'] ) . '</strong>',
					'<strong>' . esc_html( $view['tool'] ) . '</strong>'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Arguments', 'monks-central' ); ?></h2>
			<pre><?php echo esc_html( $view['input'] ); ?></pre>

			<?php if ( null !== $view['preview'] ) : ?>
			<h2><?php esc_html_e( 'What it affects now', 'monks-central' ); ?></h2>
			<pre><?php echo esc_html( $view['preview'] ); ?></pre>
			<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %d: minutes */
					esc_html__( 'No live preview for this tool. You are approving these arguments only, and the site may have changed since the request. Once approved, it must run within %d minutes.', 'monks-central' ),
					(int) $view['blind_ttl']
				);
				?>
			</p>
			<?php endif; ?>

			<?php if ( $view['pending'] ) : ?>
			<form method="post" action="<?php echo esc_url( $view['action'] ); ?>">
				<?php foreach ( $view['fields'] as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
				<?php endforeach; ?>
				<?php if ( $view['similar_max'] > 0 ) : ?>
				<p>
					<label>
						<input type="checkbox" name="similar" value="1">
						<?php
						printf(
							/* translators: %d: number of calls */
							esc_html__( 'Also approve this tool for this connection for 30 minutes (up to %d calls).', 'monks-central' ),
							(int) $view['similar_max']
						);
						?>
					</label>
				</p>
				<?php endif; ?>
				<p>
					<button type="submit" name="decision" value="approve" class="button button-primary"><?php esc_html_e( 'Approve', 'monks-central' ); ?></button>
					<button type="submit" name="decision" value="decline" class="button"><?php esc_html_e( 'Decline', 'monks-central' ); ?></button>
				</p>
			</form>
			<?php else : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $view['status'] ); ?></p></div>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( $this->page_url() ); ?>"><?php esc_html_e( 'Back to Monks Central', 'monks-central' ); ?></a></p>
		</div>
		<?php
	}
}
