<?php
/**
 * Undo Operation Ability - WordPress Abilities API
 *
 * The Undo button of the connection screen as a tool: reverses one logged change
 * through its ability's cm_undo_callback. Destructive, so a person approves it.
 *
 * @package WPSitesHQ\Connector
 * @requires WordPress 7.1+ (the activity log and undo data)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', 'wpshq_register_undo_operation_ability' );
function wpshq_register_undo_operation_ability() {
	wp_register_ability(
		'classic-monks/undo-operation',
		array(
			'label'               => __( 'Undo Operation', 'wp-sites-hq' ),
			'description'         => __(
				"Undoes one change from the AI activity log, like the Undo button on the connection screen. Use list-ai-activity to find the row id; only rows with can_undo true can be undone. \n\n## Notes\n- Refused when the change has no undo, was already undone, or was edited again since (undoing would overwrite the newer edit).\n- Needs the same permissions as the original change.\n- An undo cannot itself be undone.",
				'wp-sites-hq'
			),
			'category'            => 'classic-monks',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Activity log row id.',
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'undone' => array( 'type' => 'integer' ),
					'tool'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'wpshq_execute_undo_operation',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => false,
					'instructions' => 'Tell the user which change will be reversed before calling.',
				),
				'cm_preview_callback' => 'wpshq_preview_undo_operation',
				'show_in_rest'        => true,
			),
		)
	);
}

function wpshq_preview_undo_operation( $input ) {
	return class_exists( 'WPSHQ_AI_Activity_Log' ) ? WPSHQ_AI_Activity_Log::undo_preview( (int) ( $input['id'] ?? 0 ) ) : null;
}

/**
 * Refuse an undo that cannot run before the approval gate (priority 30) asks a person to approve it.
 */
add_filter( 'wp_pre_execute_ability', 'wpshq_undo_operation_precheck', 25, 3 );
function wpshq_undo_operation_precheck( $pre, $name, $input ) {
	if ( 'classic-monks/undo-operation' !== $name || is_wp_error( $pre ) || ! is_int( $input['id'] ?? null ) || ! current_user_can( 'manage_options' ) || ! class_exists( 'WPSHQ_AI_Activity_Log' ) ) {
		return $pre;
	}
	$target = WPSHQ_AI_Activity_Log::undo_target( (int) $input['id'] );
	if ( ! is_wp_error( $target ) ) {
		return $pre;
	}
	WPSHQ_AI_Activity_Log::mark( $name, 'denied', $target->get_error_message() );
	return $target;
}

function wpshq_execute_undo_operation( $input ) {
	if ( ! class_exists( 'WPSHQ_AI_Activity_Log' ) ) {
		return new WP_Error( 'cm_module_disabled', __( 'The AI activity log is not available on this site.', 'wp-sites-hq' ) );
	}
	$id     = (int) $input['id'];
	$caller = WPSHQ_AI_Activity_Log::caller();
	$result = WPSHQ_AI_Activity_Log::undo( $id, $caller['label'] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$preview = WPSHQ_AI_Activity_Log::undo_preview( $id );
	return array(
		'undone' => $id,
		'tool'   => (string) $preview['tool'],
	);
}
