<?php
/**
 * REST endpoint for the settings screen.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Rest;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use MonksCentral\Settings;

/**
 * GET and POST `/monks-central/v1/settings`. Admins only. The nonce is checked by core's REST cookie auth.
 */
final class SettingsController extends WP_REST_Controller {

	/**
	 * Set up the route.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private Settings $settings ) {
		$this->namespace = 'monks-central/v1';
		$this->rest_base = 'settings';
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Only admins can read settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_item_permissions_check( $request ): bool|WP_Error {
		return $this->can_manage();
	}

	/**
	 * Only admins can change settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_item_permissions_check( $request ): bool|WP_Error {
		return $this->can_manage();
	}

	/**
	 * Return all settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_item( $request ): WP_REST_Response {
		return rest_ensure_response( $this->settings->all() );
	}

	/**
	 * Save the fields present in the request body and return all settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_item( $request ): WP_REST_Response {
		$input = array_intersect_key( $request->get_params(), Settings::fields() );
		return rest_ensure_response( $this->settings->update( $input ) );
	}

	/**
	 * JSON Schema for the settings object, built from Settings::fields().
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( ! $this->schema ) {
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'monks-central-settings',
				'type'       => 'object',
				'properties' => Settings::fields(),
			);
		}
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Capability check shared by both methods.
	 */
	private function can_manage(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'monks_central_forbidden',
			__( 'Sorry, you are not allowed to manage these settings.', 'monks-central' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
