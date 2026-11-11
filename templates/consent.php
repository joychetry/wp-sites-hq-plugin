<?php
/**
 * OAuth consent page, printed by Connector\Ui::render_consent().
 *
 * @package WPSitesHQ
 *
 * @var array<string, mixed> $view See WPSHQ_Connector_UI::render_consent().
 */

defined( 'ABSPATH' ) || exit;

$wpshq_error = $view['error'];
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html( null === $wpshq_error ? __( 'Connect an app', 'wp-sites-hq' ) : __( 'Connection failed', 'wp-sites-hq' ) ); ?> &lsaquo; <?php echo esc_html( $view['site'] ); ?></title>
	<?php wp_print_styles( array( 'buttons', 'wp-sites-hq-consent' ) ); ?>
</head>
<body class="wp-sites-hq-consent wp-core-ui">
<main class="wp-sites-hq-consent__card">
	<p class="wp-sites-hq-consent__site"><?php echo esc_html( $view['site'] ); ?></p>

<?php if ( null !== $wpshq_error ) : ?>
	<h1><?php esc_html_e( 'This connection cannot continue', 'wp-sites-hq' ); ?></h1>
	<p class="wp-sites-hq-consent__notice is-error"><?php echo esc_html( $wpshq_error ); ?></p>
	<p class="wp-sites-hq-consent__foot"><?php esc_html_e( 'Close this tab and start the connection again from the app.', 'wp-sites-hq' ); ?></p>
<?php else : ?>
	<h1>
		<?php
		/* translators: %s: app name */
		printf( esc_html__( 'Connect %s?', 'wp-sites-hq' ), '<span class="wp-sites-hq-consent__app">' . esc_html( $view['app'] ) . '</span>' );
		?>
	</h1>
	<p class="wp-sites-hq-consent__lede">
		<?php
		printf(
			/* translators: %s: username */
			esc_html__( 'It will use this site\'s WP Sites HQ tools as %s, with your permissions. It cannot sign in to the dashboard or use the rest of the REST API.', 'wp-sites-hq' ),
			'<strong>' . esc_html( $view['user'] ) . '</strong>'
		);
		?>
	</p>

	<dl class="wp-sites-hq-consent__facts">
		<div>
			<dt><?php esc_html_e( 'App', 'wp-sites-hq' ); ?></dt>
			<dd>
				<?php echo esc_html( $view['app'] ); ?>
				<?php if ( '' !== $view['verified_host'] ) : ?>
					<span class="wp-sites-hq-consent__tag is-ok">
						<?php
						/* translators: %s: domain */
						printf( esc_html__( 'published by %s', 'wp-sites-hq' ), esc_html( $view['verified_host'] ) );
						?>
					</span>
				<?php else : ?>
					<span class="wp-sites-hq-consent__tag"><?php esc_html_e( 'name not verified', 'wp-sites-hq' ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Returns you to', 'wp-sites-hq' ); ?></dt>
			<dd><code><?php echo esc_html( $view['returns_to'] ); ?></code></dd>
		</div>
	</dl>

	<?php if ( '' !== $view['warning'] ) : ?>
	<p class="wp-sites-hq-consent__notice is-warning"><?php echo esc_html( $view['warning'] ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $view['action'] ); ?>">
		<?php foreach ( $view['fields'] as $wpshq_name => $wpshq_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $wpshq_name ); ?>" value="<?php echo esc_attr( $wpshq_value ); ?>">
		<?php endforeach; ?>

		<fieldset class="wp-sites-hq-consent__group">
			<legend><?php esc_html_e( 'Access', 'wp-sites-hq' ); ?></legend>
			<?php foreach ( $view['scopes'] as $wpshq_key => $wpshq_scope ) : ?>
			<label class="wp-sites-hq-consent__choice">
				<input type="radio" name="scope" value="<?php echo esc_attr( $wpshq_key ); ?>" <?php checked( $view['default_scope'], $wpshq_key ); ?>>
				<span>
					<strong><?php echo esc_html( $wpshq_scope['label'] ); ?></strong>
					<span class="wp-sites-hq-consent__description"><?php echo esc_html( $wpshq_scope['description'] ); ?></span>
				</span>
			</label>
			<?php endforeach; ?>
		</fieldset>

		<fieldset class="wp-sites-hq-consent__group">
			<legend><?php esc_html_e( 'Tool groups', 'wp-sites-hq' ); ?></legend>
			<div class="wp-sites-hq-consent__toolsets">
			<?php foreach ( $view['toolsets'] as $wpshq_key => $wpshq_label ) : ?>
				<label><input type="checkbox" name="toolsets[]" value="<?php echo esc_attr( $wpshq_key ); ?>" <?php checked( in_array( $wpshq_key, $view['default_toolsets'], true ) ); ?>> <?php echo esc_html( $wpshq_label ); ?></label>
			<?php endforeach; ?>
			</div>
		</fieldset>

		<?php if ( $view['operator'] ) : ?>
		<fieldset class="wp-sites-hq-consent__group">
			<legend><?php esc_html_e( 'Operator', 'wp-sites-hq' ); ?></legend>
			<label class="wp-sites-hq-consent__choice">
				<input type="checkbox" name="operator" value="1">
				<span>
					<strong><?php esc_html_e( 'This is my WP Sites HQ app (operator)', 'wp-sites-hq' ); ?></strong>
					<span class="wp-sites-hq-consent__description"><?php esc_html_e( 'Full access to every tool group, and changes run without asking you first, even updates, rollbacks and deletes. It can also open the dashboard signed in as you. Every change is still logged. Never tick this for an AI agent.', 'wp-sites-hq' ); ?></span>
				</span>
			</label>
		</fieldset>
		<?php endif; ?>

		<div class="wp-sites-hq-consent__actions">
			<button type="submit" name="decision" value="deny" class="button button-large"><?php esc_html_e( 'Deny', 'wp-sites-hq' ); ?></button>
			<button type="submit" name="decision" value="approve" class="button button-primary button-large"><?php esc_html_e( 'Connect', 'wp-sites-hq' ); ?></button>
		</div>
	</form>

	<p class="wp-sites-hq-consent__foot"><?php esc_html_e( 'Destructive changes still wait for your approval. An administrator can see and revoke this connection under Settings > WP Sites HQ.', 'wp-sites-hq' ); ?></p>
<?php endif; ?>
</main>
</body>
</html>
