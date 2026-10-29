<?php
/**
 * OAuth consent page, printed by Connector\Ui::render_consent().
 *
 * @package MonksCentral
 *
 * @var array<string, mixed> $view See Monks_Central_Connector_UI::render_consent().
 */

defined( 'ABSPATH' ) || exit;

$monks_central_error = $view['error'];
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html( null === $monks_central_error ? __( 'Connect an app', 'monks-central' ) : __( 'Connection failed', 'monks-central' ) ); ?> &lsaquo; <?php echo esc_html( $view['site'] ); ?></title>
	<?php wp_print_styles( array( 'buttons', 'monks-central-consent' ) ); ?>
</head>
<body class="monks-central-consent">
<main class="monks-central-consent__card">
	<p class="monks-central-consent__site"><?php echo esc_html( $view['site'] ); ?></p>

<?php if ( null !== $monks_central_error ) : ?>
	<h1><?php esc_html_e( 'This connection cannot continue', 'monks-central' ); ?></h1>
	<p class="monks-central-consent__notice is-error"><?php echo esc_html( $monks_central_error ); ?></p>
	<p class="monks-central-consent__foot"><?php esc_html_e( 'Close this tab and start the connection again from the app.', 'monks-central' ); ?></p>
<?php else : ?>
	<h1>
		<?php
		/* translators: %s: app name */
		printf( esc_html__( 'Connect %s?', 'monks-central' ), '<span class="monks-central-consent__app">' . esc_html( $view['app'] ) . '</span>' );
		?>
	</h1>
	<p class="monks-central-consent__lede">
		<?php
		printf(
			/* translators: %s: username */
			esc_html__( 'It will use this site\'s Monks Central tools as %s, with your permissions. It cannot sign in to the dashboard or use the rest of the REST API.', 'monks-central' ),
			'<strong>' . esc_html( $view['user'] ) . '</strong>'
		);
		?>
	</p>

	<dl class="monks-central-consent__facts">
		<div>
			<dt><?php esc_html_e( 'App', 'monks-central' ); ?></dt>
			<dd>
				<?php echo esc_html( $view['app'] ); ?>
				<?php if ( '' !== $view['verified_host'] ) : ?>
					<span class="monks-central-consent__tag is-ok">
						<?php
						/* translators: %s: domain */
						printf( esc_html__( 'published by %s', 'monks-central' ), esc_html( $view['verified_host'] ) );
						?>
					</span>
				<?php else : ?>
					<span class="monks-central-consent__tag"><?php esc_html_e( 'name not verified', 'monks-central' ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Returns you to', 'monks-central' ); ?></dt>
			<dd><code><?php echo esc_html( $view['returns_to'] ); ?></code></dd>
		</div>
	</dl>

	<?php if ( '' !== $view['warning'] ) : ?>
	<p class="monks-central-consent__notice is-warning"><?php echo esc_html( $view['warning'] ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $view['action'] ); ?>">
		<?php foreach ( $view['fields'] as $monks_central_name => $monks_central_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $monks_central_name ); ?>" value="<?php echo esc_attr( $monks_central_value ); ?>">
		<?php endforeach; ?>

		<fieldset class="monks-central-consent__group">
			<legend><?php esc_html_e( 'Access', 'monks-central' ); ?></legend>
			<?php foreach ( $view['scopes'] as $monks_central_key => $monks_central_scope ) : ?>
			<label class="monks-central-consent__choice">
				<input type="radio" name="scope" value="<?php echo esc_attr( $monks_central_key ); ?>" <?php checked( $view['default_scope'], $monks_central_key ); ?>>
				<span>
					<strong><?php echo esc_html( $monks_central_scope['label'] ); ?></strong>
					<span class="monks-central-consent__description"><?php echo esc_html( $monks_central_scope['description'] ); ?></span>
				</span>
			</label>
			<?php endforeach; ?>
		</fieldset>

		<fieldset class="monks-central-consent__group">
			<legend><?php esc_html_e( 'Tool groups', 'monks-central' ); ?></legend>
			<div class="monks-central-consent__toolsets">
			<?php foreach ( $view['toolsets'] as $monks_central_key => $monks_central_label ) : ?>
				<label><input type="checkbox" name="toolsets[]" value="<?php echo esc_attr( $monks_central_key ); ?>" <?php checked( in_array( $monks_central_key, $view['default_toolsets'], true ) ); ?>> <?php echo esc_html( $monks_central_label ); ?></label>
			<?php endforeach; ?>
			</div>
		</fieldset>

		<?php if ( $view['operator'] ) : ?>
		<fieldset class="monks-central-consent__group">
			<legend><?php esc_html_e( 'Operator', 'monks-central' ); ?></legend>
			<label class="monks-central-consent__choice">
				<input type="checkbox" name="operator" value="1">
				<span>
					<strong><?php esc_html_e( 'This is my Monks Central app (operator)', 'monks-central' ); ?></strong>
					<span class="monks-central-consent__description"><?php esc_html_e( 'Full access to every tool group, and changes run without asking you first, even updates, rollbacks and deletes. It can also open the dashboard signed in as you. Every change is still logged. Never tick this for an AI agent.', 'monks-central' ); ?></span>
				</span>
			</label>
		</fieldset>
		<?php endif; ?>

		<div class="monks-central-consent__actions">
			<button type="submit" name="decision" value="deny" class="button button-large"><?php esc_html_e( 'Deny', 'monks-central' ); ?></button>
			<button type="submit" name="decision" value="approve" class="button button-primary button-large"><?php esc_html_e( 'Connect', 'monks-central' ); ?></button>
		</div>
	</form>

	<p class="monks-central-consent__foot"><?php esc_html_e( 'Destructive changes still wait for your approval. An administrator can see and revoke this connection under Settings > Monks Central.', 'monks-central' ); ?></p>
<?php endif; ?>
</main>
</body>
</html>
