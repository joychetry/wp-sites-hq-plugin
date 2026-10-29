<?php
/**
 * Monks Central connector: what a host plugin renders.
 *
 * The connector has no admin UI of its own. The plugin whose copy is running
 * passes a class implementing this interface to monks_central_connector_register()
 * and draws the screens from the data the connector hands it. Forms post the
 * fields given in each view; the connector checks and runs the action.
 *
 * @package MonksCentral\Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Monks_Central_Connector_UI {

	/**
	 * Admin URL of the host's connection screen (connected apps, approvals, activity).
	 * Other plugins that bundle the connector link here while this host's copy runs.
	 *
	 * @return string
	 */
	public function page_url();

	/**
	 * Print the whole OAuth consent page (a standalone HTML document), then return.
	 *
	 * @param array $view {
	 *     @type string|null $error            Why the request cannot continue, or null.
	 *     @type string      $site             Site name.
	 *     @type string      $user             Login of the signed-in user.
	 *     @type string      $app              App name.
	 *     @type string      $verified_host    Domain that publishes the app's name, or '' when unverified.
	 *     @type string      $returns_to       Where the browser returns after the decision.
	 *     @type string      $warning          A warning to show above the form, or ''.
	 *     @type string      $action           URL the form posts to.
	 *     @type array       $fields           Hidden fields (name => value), nonce included.
	 *     @type array       $scopes           Key => array( label, description ); post one as `scope`.
	 *     @type string      $default_scope    Preselected scope.
	 *     @type array       $toolsets         Key => label; post the chosen ones as `toolsets[]`.
	 *     @type string[]    $default_toolsets Preselected tool groups.
	 *     @type bool        $operator         Whether to offer the operator checkbox (`operator` = 1).
	 * }
	 * Submit `decision` = approve or deny.
	 */
	public function render_consent( array $view );

	/**
	 * Print the approval page body inside wp-admin (the admin header is already out).
	 *
	 * @param array $view {
	 *     @type int         $id          Request id.
	 *     @type string      $client      Connection label.
	 *     @type string      $user        Login the connection acts as.
	 *     @type string      $tool        Tool label.
	 *     @type string      $input       Arguments as pretty JSON.
	 *     @type string|null $preview     What it affects now, as pretty JSON, or null without a live preview.
	 *     @type int         $blind_ttl   Minutes an approval without a preview stays valid.
	 *     @type bool        $pending     Whether it can still be decided.
	 *     @type string      $status      What happened, when it is no longer pending.
	 *     @type int         $similar_max When > 0, offer `similar` = 1: approve this tool for this connection for 30 minutes, up to this many calls.
	 *     @type string      $action      URL the form posts to.
	 *     @type array       $fields      Hidden fields (name => value), nonce included.
	 * }
	 * Submit `decision` = approve or decline.
	 */
	public function render_approval( array $view );
}
