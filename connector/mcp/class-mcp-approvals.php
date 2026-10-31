<?php
/**
 * MCP approvals
 *
 * A destructive tool called over MCP stops at an approval request instead of
 * running. A person approves it in wp-admin, and the agent's next call with the
 * same arguments runs once. Operator grants (WP Sites HQ) and trusted connections skip it.
 *
 * Approval requests are rows in the AI activity log (status pending → approved |
 * declined → used), keyed by a hash of connection + tool + arguments.
 *
 * @package ClassicMonks
 * @subpackage MonksCentral
 * @requires WordPress 7.1+ (wp_pre_execute_ability)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CM_MCP_Approvals {

	const PAGE          = 'cm-ai-approve';
	const TTL           = 15 * MINUTE_IN_SECONDS;
	const SIMILAR_TTL   = 30 * MINUTE_IN_SECONDS;
	const BLIND_RUN_TTL = 5 * MINUTE_IN_SECONDS;
	const SIMILAR_MAX   = 25;
	const TRUSTED_META  = 'cm_mcp_trusted';
	// Operator grants (WP Sites HQ): connection id => whether cm_always_approve abilities skip approval too.
	const OPERATOR_META = 'cm_mcp_operator';

	public static function init() {
		add_filter( 'wp_pre_execute_ability', array( __CLASS__, 'gate' ), 30, 4 );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_cm_ai_decide', array( __CLASS__, 'handle_decision' ) );
	}

	public static function supported() {
		return version_compare( get_bloginfo( 'version' ), '7.1', '>=' );
	}

	public static function needs_approval( WP_Ability $ability ) {
		$annotations = CM_MCP_Server::annotations( $ability );
		return ! empty( $annotations['destructive'] );
	}

	/**
	 * cm_always_approve is true, or the name of a function that decides from the input (delete-post: force only).
	 */
	public static function always_asks( WP_Ability $ability, $input ) {
		$always = $ability->get_meta_item( 'cm_always_approve', false );
		return is_string( $always ) && is_callable( $always ) ? (bool) call_user_func( $always, $input ) : (bool) $always;
	}

	public static function is_trusted( $user_id, $uuid ) {
		$trusted = get_user_meta( $user_id, self::TRUSTED_META, true );
		return is_array( $trusted ) && ! empty( $trusted[ $uuid ] );
	}

	/**
	 * Operator grant settings: null for any other connection, else whether it also skips cm_always_approve.
	 */
	public static function operator( $user_id, $id ) {
		$operator = get_user_meta( $user_id, self::OPERATOR_META, true );
		return is_array( $operator ) && isset( $operator[ $id ] ) ? (bool) $operator[ $id ] : null;
	}

	/**
	 * Set a per-connection flag in TRUSTED_META or OPERATOR_META; null removes it.
	 */
	public static function save_flag( $meta, $user_id, $id, $value ) {
		$all = get_user_meta( $user_id, $meta, true );
		$all = is_array( $all ) ? $all : array();
		if ( null === $value ) {
			if ( ! isset( $all[ $id ] ) ) {
				return;
			}
			unset( $all[ $id ] );
		} else {
			$all[ $id ] = (bool) $value;
		}
		update_user_meta( $user_id, $meta, $all );
	}

	/**
	 * The human ticked "This is my WP Sites HQ app" on the consent page: trusted, and the
	 * cm_always_approve abilities skip approval too until the owner turns that off.
	 */
	public static function make_operator( $user_id, $id ) {
		self::save_flag( self::TRUSTED_META, $user_id, $id, true );
		self::save_flag( self::OPERATOR_META, $user_id, $id, true );
	}

	public static function approve_url( $id ) {
		return admin_url( 'admin.php?page=' . self::PAGE . '&op=' . (int) $id );
	}

	private static function hash( $uuid, $name, $input ) {
		return hash( 'sha256', $uuid . '|' . $name . '|' . wp_json_encode( $input ) );
	}

	// Connection hash first, so forget_similar() can drop all of one connection's grants by prefix.
	private static function similar_key( $uuid, $name ) {
		return 'cm_ai_similar_' . md5( $uuid ) . '_' . md5( $name );
	}

	/**
	 * Drop approve-similar grants: one connection's, or every connection's on this site.
	 */
	public static function forget_similar( $uuid = null ) {
		global $wpdb;
		$prefix = 'cm_ai_similar_' . ( null === $uuid ? '' : md5( $uuid ) . '_' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- this module's own cm_ai_similar_* options; core has no prefix delete or atomic decrement
	}

	/**
	 * An approve-similar grant is an option (calls left) plus its expiry, not a transient,
	 * so one conditional UPDATE can hand each call to exactly one of several parallel requests.
	 */
	private static function grant_similar( $uuid, $name ) {
		$key = self::similar_key( $uuid, $name );
		update_option( $key . '_until', time() + self::SIMILAR_TTL, false );
		update_option( $key, self::SIMILAR_MAX, false );
	}

	private static function take_similar( $uuid, $name ) {
		global $wpdb;
		$key = self::similar_key( $uuid, $name );
		if ( (int) get_option( $key . '_until' ) < time() ) {
			delete_option( $key );
			delete_option( $key . '_until' );
			return false;
		}
		$taken = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = option_value - 1 WHERE option_name = %s AND option_value > 0', $wpdb->options, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- this module's own cm_ai_similar_* options; core has no prefix delete or atomic decrement
		wp_cache_delete( $key, 'options' );
		return 1 === (int) $taken;
	}

	/**
	 * State the approver saw, recomputed before running to catch drift.
	 */
	private static function preview( WP_Ability $ability, $input ) {
		$callback = $ability->get_meta_item( 'cm_preview_callback' );
		return is_callable( $callback ) ? call_user_func( $callback, $input ) : null;
	}

	/*
	------------------------------------------------------------------
	 * Gate
	 * ------------------------------------------------------------------ */

	public static function gate( $pre, $name, $input, $ability ) {
		// Any namespace: an MCP credential only reaches other plugins' abilities through the allowlist (R-9).
		if ( is_wp_error( $pre ) || ! self::needs_approval( $ability ) || ! cm_mcp_is_mcp_credential() ) {
			return $pre;
		}

		// Bad input or a missing capability fails on its own; don't ask a person to approve it first.
		$normalized = $ability->normalize_input( $input );
		if ( is_wp_error( $normalized ) || true !== $ability->validate_input( $normalized ) || true !== $ability->check_permissions( $normalized ) ) {
			return $pre;
		}

		$connection = CM_MCP_Server::current_connection();
		$uuid       = $connection['id'];
		$always     = self::always_asks( $ability, $input );

		if ( $connection['trusted'] && ( ! $always || $connection['operator'] ) ) {
			CM_AI_Activity_Log::tag( $name, null === $connection['operator'] ? '[trusted]' : '[operator]' );
			return $pre;
		}

		if ( ! $always && self::take_similar( $uuid, $name ) ) {
			CM_AI_Activity_Log::tag( $name, '[approved similar]' );
			return $pre;
		}

		global $wpdb;
		$table = CM_AI_Activity_Log::table();
		$hash  = self::hash( $uuid, $name, $input );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
			$wpdb->prepare(
				"SELECT id, status, approval_json FROM %i WHERE connection_uuid = %s AND ability = %s AND status IN ('pending', 'approved') ORDER BY id DESC LIMIT 20",
				$table,
				$uuid,
				$name
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$approval = json_decode( (string) $row['approval_json'], true );
			if ( ! is_array( $approval ) || $approval['hash'] !== $hash ) {
				continue;
			}
			if ( $approval['expires'] < time() ) {
				$wpdb->update( $table, array( 'status' => 'expired' ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
				continue;
			}
			if ( 'pending' === $row['status'] ) {
				// The request row already says it is waiting; a retry adds no row of its own.
				CM_AI_Activity_Log::continue_on( $name, 0 );
				return self::required_error( (int) $row['id'], $approval );
			}
			// Approved: the state must still match what the approver saw, and it runs once.
			if ( self::preview( $ability, $input ) !== $approval['preview'] ) {
				$wpdb->update( $table, array( 'status' => 'expired' ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
				$message = __( 'The content changed after it was approved, so the approval no longer applies. Call the tool again to request a fresh approval.', 'monks-central' );
				CM_AI_Activity_Log::mark( $name, 'denied', $message );
				return new WP_Error( 'cm_approval_drift', $message, array( 'status' => 409 ) );
			}
			// Conditional, so parallel retries can't both spend one approval; the loser asks again.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
			if ( 1 !== (int) $wpdb->update(
				$table,
				array( 'status' => 'used' ),
				array(
					'id'     => $row['id'],
					'status' => 'approved',
				)
			) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
				continue;
			}
			// The request row carries the run's result, so the log shows one entry: requested, approved, done.
			CM_AI_Activity_Log::continue_on( $name, (int) $row['id'] );
			/* translators: %s: username of the approver */
			CM_AI_Activity_Log::tag( $name, sprintf( __( '[approved by %s]', 'monks-central' ), $approval['approved_by'] ?? '?' ) );
			return $pre;
		}

		// New request: this call's own log row becomes the approval request.
		$approval = array(
			'hash'    => $hash,
			'preview' => self::preview( $ability, $input ),
			// The log row's input_json is cut at 8000 bytes; the approver needs all of it.
			'input'   => CM_AI_Activity_Log::redact( $input ),
			'expires' => time() + self::TTL,
		);
		$id       = CM_AI_Activity_Log::request_approval( $name, $approval, __( 'Waiting for approval in the dashboard.', 'monks-central' ) );
		return self::required_error( $id, $approval );
	}

	private static function required_error( $id, array $approval ) {
		$url = self::approve_url( $id );
		return new WP_Error(
			'cm_approval_required',
			sprintf(
				/* translators: %s: approval URL */
				__( 'This change needs approval. Ask the user to open %s and approve it, then call the same tool again with exactly the same arguments.', 'monks-central' ),
				$url
			),
			array(
				'status'      => 202,
				'op_id'       => $id,
				'approve_url' => $url,
				'preview'     => $approval['preview'],
				'expires_in'  => max( 0, $approval['expires'] - time() ),
			)
		);
	}

	/**
	 * Mark a pending request declined (the user refused it in their MCP client).
	 */
	public static function decline( $id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
			CM_AI_Activity_Log::table(),
			array(
				'status'         => 'declined',
				'result_summary' => __( 'Declined in the MCP client.', 'monks-central' ),
			),
			array(
				'id'     => $id,
				'status' => 'pending',
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Approve page
	 * ------------------------------------------------------------------ */

	public static function register_page() {
		// Hidden page (no parent menu); access is checked per request.
		$hook = add_submenu_page( '', __( 'Approve AI change', 'monks-central' ), '', 'read', self::PAGE, array( __CLASS__, 'render_page' ) );
		// Parentless pages get no admin title of their own.
		add_action(
			'load-' . $hook,
			function () {
				$GLOBALS['title'] = __( 'Approve AI change', 'monks-central' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the hidden approval page has no menu title, so admin-header.php needs one
			}
		);
	}

	private static function load_request( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', CM_AI_Activity_Log::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
		if ( ! $row || empty( $row['approval_json'] ) ) {
			return null;
		}
		$row['approval'] = json_decode( $row['approval_json'], true );
		return $row;
	}

	private static function can_decide( array $row ) {
		if ( get_current_user_id() === (int) $row['user_id'] || is_super_admin() ) {
			return true;
		}
		// Another admin may decide only what they could run themselves (a sub-site admin can't approve a network-wide change).
		$ability = wp_get_ability( $row['ability'] );
		if ( ! $ability || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$input = $ability->normalize_input( $row['approval']['input'] ?? array() );
		return ! is_wp_error( $input ) && true === $ability->check_permissions( $input );
	}

	/**
	 * Unexpired pending requests the current user may decide on the approve page, newest first.
	 *
	 * @return array[] Log rows, each with its decoded `approval`.
	 */
	public static function pending_for_current_user() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'pending' ORDER BY id DESC LIMIT 500", CM_AI_Activity_Log::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
		$out  = array();
		foreach ( $rows as $row ) {
			$row['approval'] = json_decode( (string) $row['approval_json'], true );
			if ( is_array( $row['approval'] ) && $row['approval']['expires'] >= time() && self::can_decide( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public static function render_page() {
		$id  = absint( $_GET['op'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view; can_decide() checks the user and the decision form is nonce-checked
		$row = self::load_request( $id );

		if ( ! $row || ! self::can_decide( $row ) ) {
			wp_die( esc_html__( 'This approval request does not exist, or you cannot decide it.', 'monks-central' ) );
		}

		$ability = wp_get_ability( $row['ability'] );
		$user    = get_userdata( (int) $row['user_id'] );
		$pending = 'pending' === $row['status'] && $row['approval']['expires'] >= time();
		// Requests made before 'input' was stored fall back to the (possibly truncated) log copy.
		$input = array_key_exists( 'input', $row['approval'] ) ? wp_json_encode( $row['approval']['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : (string) $row['input_json'];

		$states = array(
			'approved' => __( 'Approved. The agent can now run it once.', 'monks-central' ),
			'declined' => __( 'Declined.', 'monks-central' ),
			'used'     => __( 'Approved and already run.', 'monks-central' ),
			'ok'       => __( 'Approved and already run.', 'monks-central' ),
			'error'    => __( 'Approved and run, but it failed. The activity log on the WP Sites HQ connection screen has the reason.', 'monks-central' ),
			'undone'   => __( 'Approved and run, then undone.', 'monks-central' ),
			'expired'  => __( 'This request expired. The agent can ask again.', 'monks-central' ),
		);

		$ui = Monks_Central_Connector::ui();
		if ( ! $ui ) {
			wp_die( esc_html__( 'The plugin running the connection has no approval page.', 'monks-central' ), '', 500 );
		}
		$ui->render_approval(
			array(
				'id'          => $id,
				'client'      => $row['client_label'],
				'user'        => $user ? $user->user_login : '?',
				'tool'        => $ability ? $ability->get_label() : $row['ability'],
				'input'       => $input,
				'preview'     => null !== $row['approval']['preview'] ? wp_json_encode( $row['approval']['preview'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : null,
				'blind_ttl'   => (int) ( self::BLIND_RUN_TTL / MINUTE_IN_SECONDS ),
				'pending'     => $pending,
				'status'      => $states[ $row['status'] ] ?? $states['expired'],
				'similar_max' => $ability && self::always_asks( $ability, $row['approval']['input'] ?? array() ) ? 0 : self::SIMILAR_MAX,
				'action'      => admin_url( 'admin-post.php' ),
				'fields'      => array(
					'action'   => 'cm_ai_decide',
					'op'       => $id,
					'_wpnonce' => wp_create_nonce( 'cm_ai_decide_' . $id ),
				),
			)
		);
	}

	public static function handle_decision() {
		$id = absint( $_POST['op'] ?? 0 );
		check_admin_referer( 'cm_ai_decide_' . $id );

		$row = self::load_request( $id );
		if ( ! $row || ! self::can_decide( $row ) ) {
			wp_die( esc_html__( 'This approval request does not exist, or you cannot decide it.', 'monks-central' ) );
		}

		if ( 'pending' === $row['status'] && $row['approval']['expires'] >= time() ) {
			global $wpdb;
			$approve = 'approve' === sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
			$update  = array(
				'status'         => $approve ? 'approved' : 'declined',
				/* translators: %s: username */
				'result_summary' => sprintf( $approve ? __( 'Approved by %s.', 'monks-central' ) : __( 'Declined by %s.', 'monks-central' ), wp_get_current_user()->user_login ),
			);
			// No preview means no drift check at run time, so keep the gap between approving and running short.
			$ability = wp_get_ability( $row['ability'] );
			if ( $approve && ! ( $ability && $ability->get_meta_item( 'cm_preview_callback' ) ) ) {
				$row['approval']['expires'] = min( $row['approval']['expires'], time() + self::BLIND_RUN_TTL );
			}
			if ( $approve ) {
				// The run overwrites the summary, so the approver is kept here for the log label.
				$row['approval']['approved_by'] = wp_get_current_user()->user_login;
				$update['approval_json']        = wp_json_encode( $row['approval'] );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
			$decided = $wpdb->update(
				CM_AI_Activity_Log::table(),
				$update,
				array(
					'id'     => $id,
					'status' => 'pending',
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call

			if ( $approve && 1 === (int) $decided && ! empty( $_POST['similar'] ) ) {
				// The approved call itself consumes the request row, so the grant covers the calls after it.
				self::grant_similar( $row['connection_uuid'], $row['ability'] );
			}
		}

		wp_safe_redirect( self::approve_url( $id ) );
		exit;
	}
}

CM_MCP_Approvals::init();
