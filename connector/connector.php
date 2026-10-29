<?php
/**
 * Monks Central connector: the running copy.
 *
 * Lets the Monks Central app, or another MCP client that signs in with OAuth,
 * manage this site. Loaded by load.php for the winning copy only. Its tables,
 * options and user meta are the ones Classic Monks has always used, so grants
 * survive when plugins that bundle the connector are added, removed or switched.
 *
 * Monks_Central_Connector is the data and actions the host screens use; the
 * host renders them (Monks_Central_Connector_UI).
 *
 * @package MonksCentral\Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-monks-central-connector-ui.php';

/**
 * Boots the running copy and serves the host screens.
 */
final class Monks_Central_Connector {

	// Same option as Classic Monks: on/off for the MCP endpoint and every ability.
	const OPTION = 'cm_mcp_enabled';

	/**
	 * The copy that is running.
	 *
	 * @var array
	 */
	private static $copy = array();

	/**
	 * Whether a Classic Monks build with its own connector is serving the site instead.
	 *
	 * @var bool
	 */
	private static $legacy = false;

	/**
	 * Load the connector. Runs on plugins_loaded (priority 1).
	 *
	 * @param array $copy The winning copy, as registered.
	 */
	public static function boot( array $copy ) {
		self::$copy = $copy;
		// Classic Monks builds from before this library carry the same code and names; let them serve.
		if ( defined( 'CM_FUNCTIONS_DIR' ) && ( is_readable( CM_FUNCTIONS_DIR . 'ai/mcp/mcp-guard.php' ) || is_readable( CM_FUNCTIONS_DIR . 'monks-central/mcp/mcp-guard.php' ) ) ) {
			self::$legacy = true;
			return;
		}

		// The guard confines MCP credentials even while the connector is off.
		require_once __DIR__ . '/mcp/mcp-guard.php';
		foreach ( monks_central_connector_copies() as $host ) {
			register_deactivation_hook( $host['file'], array( __CLASS__, 'on_host_deactivated' ) );
		}
		if ( ! self::enabled() ) {
			return;
		}
		require_once __DIR__ . '/cm-abilities-categories.php';
		require_once __DIR__ . '/class-ai-activity-log.php';
		require_once __DIR__ . '/cm-abilities-loader.php';
		require_once __DIR__ . '/mcp/class-mcp-server.php';
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_host_abilities' ), 20 );
	}

	/**
	 * Let the plugins that bundle the connector add their abilities, whichever copy runs.
	 * Abilities named classic-monks/* are tools of every connection; others need their namespace allowed.
	 */
	public static function register_host_abilities() {
		do_action( 'monks_central_register_abilities', MONKS_CENTRAL_CONNECTOR_VERSION );
	}

	/**
	 * Revoke every connection when the last plugin that bundles the connector is deactivated:
	 * once no copy runs, nothing confines MCP credentials any more.
	 */
	public static function on_host_deactivated() {
		if ( count( monks_central_connector_copies() ) < 2 ) {
			cm_mcp_revoke_all_connections();
		}
	}

	/**
	 * WordPress 7.1 brings the ability hooks the approval gate and the activity log need.
	 *
	 * @return bool
	 */
	public static function supported() {
		return version_compare( get_bloginfo( 'version' ), '7.1', '>=' ) && function_exists( 'wp_register_ability' );
	}

	/**
	 * Whether the connector serves the MCP endpoint and abilities on this request.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return ! self::$legacy && (bool) get_option( self::OPTION ) && self::supported();
	}

	/**
	 * The running copy: dir, file, name, version (the host's), ui. Its library version is MONKS_CENTRAL_CONNECTOR_VERSION.
	 *
	 * @return array
	 */
	public static function host() {
		return self::$copy;
	}

	/**
	 * The running host's screens, or null when its UI class is missing.
	 *
	 * @return Monks_Central_Connector_UI|null
	 */
	public static function ui() {
		static $ui = null;
		$class     = self::$copy['ui'] ?? '';
		if ( null === $ui && is_string( $class ) && class_exists( $class ) && is_subclass_of( $class, 'Monks_Central_Connector_UI' ) ) {
			$ui = new $class();
		}
		return $ui;
	}

	/**
	 * What the connection screen shows at the top.
	 *
	 * @return array
	 */
	public static function state() {
		$enabled = self::enabled();
		return array(
			'version'   => MONKS_CENTRAL_CONNECTOR_VERSION,
			'host'      => self::$copy['name'] ?? '',
			'hosts'     => array_map(
				function ( $copy ) {
					return array(
						'name'      => $copy['name'],
						'version'   => $copy['version'],
						'connector' => (string) include $copy['dir'] . '/version.php',
					);
				},
				monks_central_connector_copies()
			),
			'page_url'  => self::ui() ? self::ui()->page_url() : '',
			'legacy'    => self::$legacy,
			'supported' => self::supported(),
			'enabled'   => $enabled,
			'site'      => home_url(),
			'endpoint'  => $enabled ? CM_MCP_Server::endpoint_url() : '',
			'https'     => wp_is_application_passwords_supported(),
		);
	}

	/**
	 * Turn the connector on or off. Takes effect on the next request.
	 *
	 * @param bool $on On or off.
	 * @return true|WP_Error
	 */
	public static function set_enabled( $on ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'cm_forbidden', __( 'You are not allowed to change this setting.', 'monks-central' ), array( 'status' => 403 ) );
		}
		if ( $on && ! self::supported() ) {
			return new WP_Error( 'cm_unsupported', __( 'Monks Central needs WordPress 7.1 or newer.', 'monks-central' ), array( 'status' => 400 ) );
		}
		update_option( self::OPTION, $on ? 1 : 0, false );
		return true;
	}

	/**
	 * Connected apps, each with kind, user_id, uuid (the connection id), name, user, role, created,
	 * last_used, scope, scope_label, trusted, operator (null, or whether it skips the always-ask tools)
	 * and last_action. Empty while the connector is off.
	 *
	 * @return array[]
	 */
	public static function connections() {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$scopes = CM_MCP_Server::scopes();
		return array_map(
			function ( $row ) use ( $scopes ) {
				return $row + array( 'scope_label' => $scopes[ $row['scope'] ] ?? $row['scope'] );
			},
			CM_MCP_Server::connections()
		);
	}

	/**
	 * Check that a connection exists and the current user may change it.
	 *
	 * @param int    $user_id Owner.
	 * @param string $id      Connection id.
	 * @param string $cap     delete_app_password or edit_app_password.
	 * @return true|WP_Error
	 */
	private static function check_connection( $user_id, $id, $cap ) {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) || ! CM_MCP_Server::connection_exists( $user_id, $id ) ) {
			return new WP_Error( 'cm_connection_not_found', __( 'That connection no longer exists, or you cannot change it.', 'monks-central' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( $cap, $user_id, $id ) ) {
			return new WP_Error( 'cm_forbidden', __( 'You are not allowed to change this connection.', 'monks-central' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Revoke a connection: the app is signed out at once.
	 *
	 * @param int    $user_id Owner.
	 * @param string $id      Connection id.
	 * @return true|WP_Error
	 */
	public static function revoke( $user_id, $id ) {
		$check = self::check_connection( $user_id, $id, 'delete_app_password' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( CM_MCP_OAuth::is_oauth_id( $id ) ) {
			CM_MCP_OAuth::revoke_family( substr( $id, strlen( CM_MCP_OAuth::ID_PREFIX ) ) );
		} else {
			// An MCP Application Password left by Classic Monks Pro.
			WP_Application_Passwords::delete_application_password( $user_id, $id );
			CM_MCP_Server::forget_connection( $user_id, $id );
		}
		return true;
	}

	/**
	 * Whether an operator grant also runs the always-ask tools (updates, user changes, deletes) without asking.
	 *
	 * @param int    $user_id Owner.
	 * @param string $id      Connection id.
	 * @param bool   $skip    Skip the always-ask approvals.
	 * @return true|WP_Error
	 */
	public static function set_operator_skip( $user_id, $id, $skip ) {
		$check = self::check_connection( $user_id, $id, 'edit_app_password' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( null === CM_MCP_Approvals::operator( $user_id, $id ) ) {
			return new WP_Error( 'cm_not_operator', __( 'This connection is not an operator grant.', 'monks-central' ), array( 'status' => 400 ) );
		}
		CM_MCP_Approvals::save_flag( CM_MCP_Approvals::OPERATOR_META, $user_id, $id, (bool) $skip );
		return true;
	}

	/**
	 * Approval requests the current user may decide: id, tool, client, user, created_at, approve_url.
	 *
	 * @return array[]
	 */
	public static function pending() {
		if ( ! self::enabled() ) {
			return array();
		}
		$out = array();
		foreach ( CM_MCP_Approvals::pending_for_current_user() as $row ) {
			$user  = get_userdata( (int) $row['user_id'] );
			$out[] = array(
				'id'          => (int) $row['id'],
				'tool'        => preg_replace( '#^classic-monks/#', '', $row['ability'] ),
				'client'      => $row['client_label'],
				'user'        => $user ? $user->user_login : '',
				'created_at'  => $row['created_at'],
				'approve_url' => CM_MCP_Approvals::approve_url( $row['id'] ),
			);
		}
		return $out;
	}

	/**
	 * Activity log rows, newest first. Admins only.
	 *
	 * @param array $args filter ('' = all, or a connection id), ability, status, limit (max 100), offset.
	 * @return array{rows: array[], total: int}
	 */
	public static function activity( array $args = array() ) {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		return CM_AI_Activity_Log::list_rows( $args );
	}

	/**
	 * Undo one logged change (rows with can_undo). Needs the permissions of the original change.
	 *
	 * @param int $id Log row id.
	 * @return true|WP_Error
	 */
	public static function undo( $id ) {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'cm_forbidden', __( 'You are not allowed to undo changes.', 'monks-central' ), array( 'status' => 403 ) );
		}
		return CM_AI_Activity_Log::undo( $id );
	}
}
