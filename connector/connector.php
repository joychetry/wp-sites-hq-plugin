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
		$enabled = self::enabled();
		/**
		 * Whether the abilities and the activity log load while the endpoint is off, for a host's own
		 * UI that runs them (Classic Monks Pro's chat). Runs on plugins_loaded at priority 1.
		 */
		if ( ! $enabled && ! ( self::supported() && apply_filters( 'monks_central_load_abilities', false ) ) ) {
			return;
		}
		require_once __DIR__ . '/cm-abilities-categories.php';
		require_once __DIR__ . '/class-ai-activity-log.php';
		require_once __DIR__ . '/cm-abilities-loader.php';
		if ( $enabled ) {
			require_once __DIR__ . '/mcp/class-mcp-server.php';
		}
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
	 * Whether the endpoint and abilities are loaded on this request (enabled() can turn true mid-request).
	 *
	 * @return bool
	 */
	private static function running() {
		return self::enabled() && class_exists( 'CM_MCP_Server' );
	}

	/**
	 * The running copy: dir, file, name, version (the host's), ui, connector (the library version).
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
						'connector' => $copy['connector'],
					);
				},
				monks_central_connector_copies()
			),
			'page_url'  => self::ui() ? self::ui()->page_url() : '',
			'legacy'    => self::$legacy,
			'supported' => self::supported(),
			'enabled'   => $enabled,
			'site'      => home_url(),
			'endpoint'  => self::running() ? CM_MCP_Server::endpoint_url() : '',
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
	 * last_action and agent_user (revoking it also deletes the host's agent account). Empty while the connector is off.
	 *
	 * @return array[]
	 */
	public static function connections() {
		if ( ! self::running() || ! current_user_can( 'manage_options' ) ) {
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
	 * What a connection can be given: scopes (key => label, description), tool groups (key => label),
	 * the groups new connections start with, and other plugins' namespaces. Empty while the connector is off.
	 *
	 * @return array
	 */
	public static function options() {
		if ( ! self::running() ) {
			return array();
		}
		$help = CM_MCP_Server::scope_descriptions();
		return array(
			'scopes'           => array_map(
				function ( $label, $key ) use ( $help ) {
					return array(
						'key'         => $key,
						'label'       => $label,
						'description' => $help[ $key ] ?? '',
					);
				},
				CM_MCP_Server::scopes(),
				array_keys( CM_MCP_Server::scopes() )
			),
			'toolsets'         => CM_MCP_Server::toolsets(),
			'default_toolsets' => CM_MCP_Server::default_toolsets(),
			'namespaces'       => CM_MCP_Server::available_namespaces(),
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
		if ( ! self::running() || ! current_user_can( 'manage_options' ) || ! CM_MCP_Server::connection_exists( $user_id, $id ) ) {
			return new WP_Error( 'cm_connection_not_found', __( 'That connection no longer exists, or you cannot change it.', 'monks-central' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( $cap, $user_id, $id ) ) {
			return new WP_Error( 'cm_forbidden', __( 'You are not allowed to change this connection.', 'monks-central' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Revoke a connection: the app is signed out at once.
	 * Revoking the last connection of an agent account the host created (CM_MCP_Server::AGENT_META) deletes
	 * that account too, its content reassigned to the current user.
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
			if ( CM_MCP_Server::is_lone_agent( $user_id ) && current_user_can( 'delete_users' ) && get_current_user_id() !== $user_id ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				// Its posts go to the admin revoking it; nothing the agent made is lost.
				wp_delete_user( $user_id, get_current_user_id() );
			}
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
	 * Change what a connection may do. Each key is optional: scope (a key of options()['scopes']),
	 * toolsets and namespaces (lists; unknown entries are dropped), trusted (skips approvals).
	 *
	 * @param int    $user_id Owner.
	 * @param string $id      Connection id.
	 * @param array  $changes What to change.
	 * @return true|WP_Error
	 */
	public static function set_permissions( $user_id, $id, array $changes ) {
		$check = self::check_connection( $user_id, $id, 'edit_app_password' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( isset( $changes['scope'] ) && ! isset( CM_MCP_Server::scopes()[ $changes['scope'] ] ) ) {
			return new WP_Error( 'cm_invalid_scope', __( 'Pick one of the access levels.', 'monks-central' ), array( 'status' => 400 ) );
		}
		if ( isset( $changes['scope'] ) ) {
			CM_MCP_Server::save_scope( $user_id, $id, $changes['scope'] );
		}
		if ( isset( $changes['toolsets'] ) ) {
			CM_MCP_Server::save_toolsets( $user_id, $id, CM_MCP_Server::sanitize_toolsets( $changes['toolsets'] ) );
		}
		if ( isset( $changes['namespaces'] ) ) {
			CM_MCP_Server::save_namespaces( $user_id, $id, CM_MCP_Server::sanitize_namespaces( $changes['namespaces'] ) );
		}
		if ( isset( $changes['trusted'] ) ) {
			CM_MCP_Approvals::save_flag( CM_MCP_Approvals::TRUSTED_META, $user_id, $id, $changes['trusted'] ? true : null );
		}
		return true;
	}

	/**
	 * Approval requests the current user may decide: id, tool, client, user, created_at, approve_url.
	 *
	 * @return array[]
	 */
	public static function pending() {
		if ( ! self::running() ) {
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
		// The log also runs with the endpoint off when a host loads the abilities for its own UI.
		if ( ! class_exists( 'CM_AI_Activity_Log' ) || ! current_user_can( 'manage_options' ) ) {
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
		if ( ! class_exists( 'CM_AI_Activity_Log' ) || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'cm_forbidden', __( 'You are not allowed to undo changes.', 'monks-central' ), array( 'status' => 403 ) );
		}
		return CM_AI_Activity_Log::undo( $id );
	}
}
