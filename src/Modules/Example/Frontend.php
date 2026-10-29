<?php
/**
 * Front end of the example module, built on the Interactivity API.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Modules\Example;

use MonksCentral\Plugin;
use MonksCentral\Settings;

/**
 * Prints the button under single posts and loads its script module and CSS only there.
 */
final class Frontend {

	/**
	 * Script module, style handle and build file name.
	 */
	private const HANDLE = 'monks-central-example';

	/**
	 * Whether this request shows the feature. The enqueue and the render share it.
	 */
	private static function applies(): bool {
		return is_singular( 'post' ) && RelatedPosts::is_public( (int) get_queried_object_id() );
	}

	/**
	 * Enqueue assets and store state, only on pages that render the feature.
	 */
	public static function enqueue(): void {
		$asset = Plugin::asset( 'example' );
		if ( null === $asset || ! self::applies() ) {
			return;
		}

		wp_enqueue_script_module( self::HANDLE, MONKS_CENTRAL_URL . 'build/example.js', $asset['dependencies'], $asset['version'] );
		wp_enqueue_style( self::HANDLE, MONKS_CENTRAL_URL . 'build/example.css', array(), $asset['version'] );
		// Lets core inline this small file instead of adding a render-blocking request.
		wp_style_add_data( self::HANDLE, 'path', MONKS_CENTRAL_DIR . 'build/example.css' );

		wp_interactivity_state(
			'monks-central/example',
			array(
				'endpoint'  => rest_url( 'monks-central/v1/related/' ),
				'emptyText' => __( 'No related posts yet.', 'monks-central' ),
				'errorText' => __( 'Could not load related posts. Please try again.', 'monks-central' ),
			)
		);
	}

	/**
	 * Append the server-rendered markup to the main post content.
	 *
	 * @param string $content Post content.
	 */
	public static function render( string $content ): string {
		if ( ! self::applies() || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}

		$context = array(
			'postId'   => get_the_ID(),
			'loading'  => false,
			'loaded'   => false,
			'hasPosts' => false,
			'posts'    => array(),
			'message'  => '',
		);

		ob_start();
		?>
		<div class="monks-central-related" data-wp-interactive="monks-central/example" <?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes the attribute. ?>>
			<button type="button" class="monks-central-related__button wp-element-button" data-wp-on--click="actions.load" data-wp-bind--hidden="context.loaded" data-wp-bind--disabled="context.loading" data-wp-bind--aria-busy="context.loading">
				<?php echo esc_html( ( new Settings() )->get_string( 'example_label' ) ); ?>
			</button>
			<ul class="monks-central-related__list" data-wp-bind--hidden="!context.hasPosts">
				<template data-wp-each--post="context.posts" data-wp-each-key="context.post.id">
					<li><a data-wp-bind--href="context.post.link" data-wp-text="context.post.title"></a></li>
				</template>
			</ul>
			<p class="monks-central-related__message" role="status" data-wp-text="context.message"></p>
		</div>
		<?php
		return $content . wp_interactivity_process_directives( (string) ob_get_clean() );
	}
}
