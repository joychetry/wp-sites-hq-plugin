<?php
/**
 * REST endpoints for the connection screen.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Rest;

use Monks_Central_Connector;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `/monks-central/v1/connector`: the connector's data and actions for the React screen.
 * Admins only; the connector checks capabilities again for every action.
 */
final class ConnectorController {

	public const NAMESPACE = 'monks-central/v1';

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$admin = array( $this, 'can_manage' );
		$id    = array(
			'user_id' => array(
				'type'     => 'integer',
				'required' => true,
			),
			'id'      => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/connector',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_screen' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_enabled' ),
					'permission_callback' => $admin,
					'args'                => array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/connector/connections',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => $admin,
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/connector/connections/operator',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'set_operator' ),
				'permission_callback' => $admin,
				'args'                => $id + array(
					'skip' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/connector/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_activity' ),
				'permission_callback' => $admin,
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/connector/activity/(?P<row>\d+)/undo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Admins only, and only while a copy of the connector runs.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to manage Monks Central.', 'monks-central' ), array( 'status' => rest_authorization_required_code() ) );
		}
		if ( ! class_exists( 'Monks_Central_Connector' ) ) {
			return new WP_Error( 'monks_central_no_connector', __( 'The connector is not loaded.', 'monks-central' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/**
	 * State, connected apps and approval requests in one call.
	 */
	public function get_screen(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'state'       => Monks_Central_Connector::state(),
				// Whether this plugin's copy runs; otherwise the running plugin's screen manages the connection.
				'runs_here'   => ( Monks_Central_Connector::host()['file'] ?? '' ) === MONKS_CENTRAL_FILE,
				'connections' => Monks_Central_Connector::connections(),
				'pending'     => Monks_Central_Connector::pending(),
			)
		);
	}

	/**
	 * Turn the connector on or off. It applies from the next request, so the screen reloads.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_enabled( WP_REST_Request $request ) {
		$result = Monks_Central_Connector::set_enabled( (bool) $request['enabled'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'enabled' => (bool) $request['enabled'] ) );
	}

	/**
	 * Revoke a connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke( WP_REST_Request $request ) {
		return $this->respond( Monks_Central_Connector::revoke( (int) $request['user_id'], (string) $request['id'] ) );
	}

	/**
	 * Whether an operator grant also runs the always-ask tools without asking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_operator( WP_REST_Request $request ) {
		return $this->respond( Monks_Central_Connector::set_operator_skip( (int) $request['user_id'], (string) $request['id'], (bool) $request['skip'] ) );
	}

	/**
	 * A page of the activity log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_activity( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request['per_page'];
		return new WP_REST_Response(
			Monks_Central_Connector::activity(
				array(
					'limit'  => $per_page,
					'offset' => ( (int) $request['page'] - 1 ) * $per_page,
				)
			)
		);
	}

	/**
	 * Undo one logged change.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( WP_REST_Request $request ) {
		$result = Monks_Central_Connector::undo( (int) $request['row'] );
		if ( is_wp_error( $result ) && ! isset( ( (array) $result->get_error_data() )['status'] ) ) {
			$result->add_data( array( 'status' => 409 ) );
		}
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'undone' => (int) $request['row'] ) );
	}

	/**
	 * The fresh screen data after an action, or the action's error.
	 *
	 * @param true|WP_Error $result Action result.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $result ) {
		return is_wp_error( $result ) ? $result : $this->get_screen();
	}
}
