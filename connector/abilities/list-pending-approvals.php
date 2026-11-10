<?php
/**
 * List Pending Approvals Ability - WordPress Abilities API
 *
 * Approval requests waiting for a person, for an inbox across sites. Read only:
 * deciding stays a click on the approve page in wp-admin.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (approvals)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'wpshq_register_list_pending_approvals_ability' );
function wpshq_register_list_pending_approvals_ability() {
	wp_register_ability(
		'classic-monks/list-pending-approvals',
		array(
			'label'               => __( 'List Pending Approvals', 'wp-sites-hq' ),
			'description'         => __(
				'Lists approval requests that are still waiting and that you may decide: the tool, the connection that asked, the arguments, the preview a person will see, seconds until it expires and the approve_url. There is no tool to approve or decline; a person does that on the approve page.',
				'wp-sites-hq'
			),
			'category'            => 'classic-monks',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'count'     => array( 'type' => 'integer' ),
					'approvals' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'wpshq_execute_list_pending_approvals',
			// Connection owners may decide their own requests, whatever their role (see the approve page).
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		)
	);
}

function wpshq_execute_list_pending_approvals( $input = array() ) {
	$approvals = array();
	$rows      = class_exists( 'WPSHQ_MCP_Approvals' ) ? WPSHQ_MCP_Approvals::pending_for_current_user() : array();
	foreach ( $rows as $row ) {
		$user        = get_userdata( (int) $row['user_id'] );
		$approvals[] = array(
			'op_id'        => (int) $row['id'],
			'tool'         => preg_replace( '#^classic-monks/#', '', $row['ability'] ),
			'connection'   => $row['connection_uuid'],
			'client_label' => $row['client_label'],
			'user'         => $user ? $user->user_login : '',
			'created_at'   => $row['created_at'],
			'expires_in'   => max( 0, (int) $row['approval']['expires'] - time() ),
			'approve_url'  => WPSHQ_MCP_Approvals::approve_url( $row['id'] ),
			'input'        => $row['approval']['input'] ?? null,
			'preview'      => $row['approval']['preview'] ?? null,
		);
	}
	return array(
		'count'     => count( $approvals ),
		'approvals' => $approvals,
	);
}
