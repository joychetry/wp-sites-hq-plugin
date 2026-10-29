<?php
/**
 * REST route for the example module.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Modules\Example;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use MonksCentral\Settings;

/**
 * GET `/monks-central/v1/related/<id>`. Public, but only for posts anyone can already read.
 */
final class Rest {

	/**
	 * Register the route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'monks-central/v1',
			'/related/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_items' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Allowed when the source post is public and not password protected.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function can_read( WP_REST_Request $request ): bool {
		return RelatedPosts::is_public( (int) $request['id'] );
	}

	/**
	 * Related posts for the requested post. Count comes from settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public static function get_items( WP_REST_Request $request ): WP_REST_Response {
		$count = ( new Settings() )->get_int( 'example_count' );
		return new WP_REST_Response( RelatedPosts::find( (int) $request['id'], $count ) );
	}
}
