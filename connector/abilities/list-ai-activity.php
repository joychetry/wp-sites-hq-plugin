<?php
/**
 * List AI Activity Ability - WordPress Abilities API
 *
 * Pages through the AI activity log: what each AI connection changed,
 * was refused, or is waiting on.
 *
 * @package MonksCentral\Connector
 * @requires WordPress 7.1+ for rows to exist (the log hooks are 7.1+)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_list_ai_activity_ability' );
function cm_register_list_ai_activity_ability() {
	wp_register_ability(
		'classic-monks/list-ai-activity',
		array(
			'label'               => __( 'List AI Activity', 'wp-sites-hq' ),
			'description'         => __(
				"Lists the AI activity log, newest first: every change an AI connection made, refused calls and approval requests. Read-only tools are only logged when the site turns read logging on (reads_logged). \n\n## Parameters\n- **connection**: Only one connection (its id from this list).\n- **tool**: Only one tool, e.g. \"update-posts\".\n- **status**: ok, error, denied, pending, approved, declined, used, undone or expired.\n- **page** / **per_page**: Paging (per_page up to 100).\n\n## Returns\nRows with id, time, user, source, connection, tool, status, summary, the redacted input, can_undo (use undo-operation) and approve_url for pending requests.",
				'wp-sites-hq'
			),
			'category'            => 'classic-monks',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'connection' => array( 'type' => 'string' ),
					'tool'       => array( 'type' => 'string' ),
					'status'     => array(
						'type' => 'string',
						'enum' => array( 'ok', 'error', 'denied', 'pending', 'approved', 'declined', 'used', 'undone', 'expired' ),
					),
					'page'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'total'        => array( 'type' => 'integer' ),
					'page'         => array( 'type' => 'integer' ),
					'per_page'     => array( 'type' => 'integer' ),
					'reads_logged' => array( 'type' => 'boolean' ),
					'rows'         => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'cm_execute_list_ai_activity',
			// The same bar as the connection screen.
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
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

function cm_execute_list_ai_activity( $input = array() ) {
	if ( ! class_exists( 'CM_AI_Activity_Log' ) ) {
		return new WP_Error( 'cm_module_disabled', __( 'The AI activity log is not available on this site.', 'wp-sites-hq' ) );
	}
	$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
	$per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) );
	$tool     = (string) ( $input['tool'] ?? '' );

	$list = CM_AI_Activity_Log::list_rows(
		array(
			'filter'  => (string) ( $input['connection'] ?? '' ),
			'ability' => '' === $tool || false !== strpos( $tool, '/' ) ? $tool : 'classic-monks/' . $tool,
			'status'  => (string) ( $input['status'] ?? '' ),
			'limit'   => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
		)
	);

	$rows = array();
	foreach ( $list['rows'] as $row ) {
		$input_data = json_decode( (string) $row['input_json'], true );
		$rows[]     = array(
			'id'           => (int) $row['id'],
			'created_at'   => $row['created_at'],
			'user'         => $row['user'],
			'source'       => $row['source'],
			'connection'   => $row['connection_uuid'],
			'client_label' => $row['client_label'],
			'tool'         => $row['tool'],
			'status'       => $row['status'],
			'summary'      => (string) $row['result_summary'],
			// Redacted when logged; null when it was cut at 8000 bytes.
			'input'        => is_array( $input_data ) ? $input_data : null,
			'can_undo'     => $row['can_undo'],
			'approve_url'  => $row['approve_url'] ?? null,
		);
	}

	return array(
		'total'        => $list['total'],
		'page'         => $page,
		'per_page'     => $per_page,
		'reads_logged' => (bool) apply_filters( 'cm_ai_log_reads', false ),
		'rows'         => $rows,
	);
}
