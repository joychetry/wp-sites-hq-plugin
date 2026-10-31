<?php
/**
 * ACF Fields Abilities - WordPress Abilities API
 *
 * get-acf-fields lists the Advanced Custom Fields groups that apply to an entry, with their fields and
 * values; update-acf-fields saves through update_field() after ACF's own validation (WP Sites HQ).
 * Registered only when ACF (free or PRO) or Secure Custom Fields is active.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 7.1+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Field types WP Sites HQ can edit; others (flexible content, clone, maps, add-ons) are read-only there.
const CM_ACF_EDITABLE = array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'password', 'select', 'checkbox', 'radio', 'button_group', 'true_false', 'wysiwyg', 'image', 'file', 'gallery', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'link', 'post_object', 'page_link', 'relationship', 'taxonomy', 'user', 'group', 'repeater' );
// Layout-only types: they hold no value.
const CM_ACF_LAYOUT = array( 'tab', 'accordion', 'message', 'separator' );
// Field settings an editor needs to draw the input; the rest of ACF's field array stays on the site.
const CM_ACF_SETTINGS = array( 'placeholder', 'prepend', 'append', 'maxlength', 'rows', 'min', 'max', 'step', 'choices', 'multiple', 'allow_null', 'ui', 'layout', 'default_value', 'display_format', 'post_type', 'taxonomy', 'field_type', 'role', 'mime_types', 'toolbar', 'media_upload', 'button_label', 'ui_on_text', 'ui_off_text' );

add_action( 'wp_abilities_api_init', 'cm_register_acf_fields_abilities' );
function cm_register_acf_fields_abilities() {
	if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'update_field' ) ) {
		return;
	}
	$post_id = array(
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => 'Post, page or other entry ID.',
	);

	wp_register_ability(
		'classic-monks/get-acf-fields',
		array(
			'label'               => __( 'Get ACF Fields', 'wp-sites-hq' ),
			'description'         => __(
				'Lists the Advanced Custom Fields groups shown on an entry, with each field\'s key, name, label, type, instructions, required flag, settings (choices, limits, formats) and current value as ACF stores it (image and post fields hold IDs, dates Ymd, sub-fields of groups and repeater rows are keyed by field key). editable is false for types to change in WordPress (flexible content, clone, others). refs gives titles and URLs for the media, posts, terms and users the values point at.',
				'wp-sites-hq'
			),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id' ),
				'properties'           => array( 'post_id' => $post_id ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'groups'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'key'    => array( 'type' => 'string' ),
								'title'  => array( 'type' => 'string' ),
								'fields' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'object' ),
								),
							),
						),
					),
					'refs'    => array(
						'type'        => 'object',
						'description' => 'media, posts, terms and users by ID.',
					),
				),
			),
			'execute_callback'    => 'cm_execute_get_acf_fields',
			'permission_callback' => 'cm_can_edit_acf_entry',
			'meta'                => array(
				'annotations'  => array(
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Read before update-acf-fields: it needs the field keys.',
				),
				'show_in_rest' => true,
			),
		)
	);

	wp_register_ability(
		'classic-monks/update-acf-fields',
		array(
			'label'               => __( 'Update ACF Fields', 'wp-sites-hq' ),
			'description'         => __(
				'Saves Advanced Custom Fields values on an entry through ACF (update_field), so its formatting and hooks run. fields maps field keys (or names) from get-acf-fields to values in the same shape get-acf-fields returns. ACF validates every value first; if any fails, nothing is saved and the messages say why.',
				'wp-sites-hq'
			),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'fields' ),
				'properties'           => array(
					'post_id' => $post_id,
					'fields'  => array(
						'type'        => 'object',
						'description' => 'Field key or name => value.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'updated' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'values'  => array(
						'type'        => 'object',
						'description' => 'The saved values by field key, as get-acf-fields gives them.',
					),
				),
			),
			'execute_callback'    => 'cm_execute_update_acf_fields',
			'permission_callback' => 'cm_can_edit_acf_entry',
			'meta'                => array(
				// WP Sites HQ only: the operator edits from the app window.
				'cm_operator_only'    => true,
				'annotations'         => array(
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => 'Call get-acf-fields first for the keys and value shapes.',
				),
				'cm_undo_callback'    => 'cm_undo_update_acf_fields',
				// Undo is refused when a field was changed again since (it would overwrite the newer value).
				'cm_preview_callback' => 'cm_preview_update_acf_fields',
				'show_in_rest'        => true,
			),
		)
	);
}

function cm_can_edit_acf_entry( $input ) {
	$id = absint( $input['post_id'] ?? 0 );
	return $id && current_user_can( 'edit_post', $id );
}

/**
 * The field groups ACF shows on this entry's edit screen.
 */
function cm_acf_groups( WP_Post $post ) {
	return acf_get_field_groups(
		array(
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
		)
	);
}

/**
 * Top-level value fields of those groups, by key.
 */
function cm_acf_entry_fields( WP_Post $post ) {
	$fields = array();
	foreach ( cm_acf_groups( $post ) as $group ) {
		foreach ( (array) acf_get_fields( $group ) as $field ) {
			if ( ! in_array( $field['type'], CM_ACF_LAYOUT, true ) && cm_acf_shown( $field ) ) {
				$fields[ $field['key'] ] = $field;
			}
		}
	}
	return $fields;
}

/**
 * False when the site hides the field from this user (acf/prepare_field returns false), as its edit screen does.
 */
function cm_acf_shown( array $field ) {
	return false !== acf_prepare_field( $field );
}

function cm_acf_editable( array $field ) {
	if ( ! in_array( $field['type'], CM_ACF_EDITABLE, true ) ) {
		return false;
	}
	foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
		if ( ! in_array( $sub['type'], CM_ACF_LAYOUT, true ) && ! cm_acf_editable( $sub ) ) {
			return false;
		}
	}
	return true;
}

/**
 * A field as get-acf-fields lists it; $value is passed for top-level fields only (sub-field values live in their parent's).
 */
function cm_acf_describe( array $field, $with_value, $value = null ) {
	$out = array(
		'key'          => $field['key'],
		'name'         => $field['name'],
		'label'        => $field['label'],
		'type'         => $field['type'],
		'instructions' => (string) ( $field['instructions'] ?? '' ),
		'required'     => ! empty( $field['required'] ),
		'editable'     => cm_acf_editable( $field ),
		'settings'     => (object) array_intersect_key( $field, array_flip( CM_ACF_SETTINGS ) ),
	);
	if ( $with_value ) {
		$out['value'] = 'repeater' === $field['type'] && ! is_array( $value ) ? array() : $value;
	}
	if ( ! empty( $field['sub_fields'] ) ) {
		$out['sub_fields'] = array();
		foreach ( $field['sub_fields'] as $sub ) {
			if ( ! in_array( $sub['type'], CM_ACF_LAYOUT, true ) ) {
				$out['sub_fields'][] = cm_acf_describe( $sub, false );
			}
		}
	}
	return $out;
}

/**
 * Collects the IDs a value points at, by kind, walking groups and repeater rows.
 */
function cm_acf_collect_refs( array $field, $value, array &$ids ) {
	$kinds = array(
		'image'        => 'media',
		'file'         => 'media',
		'gallery'      => 'media',
		'post_object'  => 'posts',
		'relationship' => 'posts',
		'page_link'    => 'posts',
		'taxonomy'     => 'terms',
		'user'         => 'users',
	);
	if ( isset( $kinds[ $field['type'] ] ) ) {
		foreach ( (array) $value as $id ) {
			if ( is_numeric( $id ) && $id > 0 ) {
				$ids[ $kinds[ $field['type'] ] ][ (int) $id ] = true;
			}
		}
		return;
	}
	$rows = 'repeater' === $field['type'] ? (array) $value : ( 'group' === $field['type'] ? array( $value ) : array() );
	foreach ( $rows as $row ) {
		foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
			if ( is_array( $row ) && isset( $row[ $sub['key'] ] ) ) {
				cm_acf_collect_refs( $sub, $row[ $sub['key'] ], $ids );
			}
		}
	}
}

/**
 * Titles and URLs for the collected IDs, leaving out what the user may not read.
 */
function cm_acf_refs( array $ids ) {
	$refs = array(
		'media' => array(),
		'posts' => array(),
		'terms' => array(),
		'users' => array(),
	);
	foreach ( array_keys( $ids['media'] ?? array() ) as $id ) {
		if ( 'attachment' === get_post_type( $id ) && current_user_can( 'read_post', $id ) ) {
			$thumb                = wp_get_attachment_image_src( $id, 'thumbnail' );
			$refs['media'][ $id ] = array(
				'title'     => get_the_title( $id ),
				'url'       => (string) wp_get_attachment_url( $id ),
				'thumbnail' => $thumb ? $thumb[0] : '',
				'mime_type' => (string) get_post_mime_type( $id ),
			);
		}
	}
	foreach ( array_keys( $ids['posts'] ?? array() ) as $id ) {
		if ( get_post( $id ) && current_user_can( 'read_post', $id ) ) {
			$refs['posts'][ $id ] = array(
				'title' => get_the_title( $id ),
				'type'  => get_post_type( $id ),
			);
		}
	}
	foreach ( array_keys( $ids['terms'] ?? array() ) as $id ) {
		$term = get_term( $id );
		if ( $term instanceof WP_Term ) {
			$refs['terms'][ $id ] = array(
				'name'     => $term->name,
				'taxonomy' => $term->taxonomy,
			);
		}
	}
	foreach ( array_keys( $ids['users'] ?? array() ) as $id ) {
		$user = get_userdata( $id );
		if ( $user ) {
			$refs['users'][ $id ] = array( 'name' => $user->display_name );
		}
	}
	return array_map(
		function ( $kind ) {
			return (object) $kind;
		},
		$refs
	);
}

function cm_execute_get_acf_fields( $input ) {
	$post = get_post( absint( $input['post_id'] ) );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'wp-sites-hq' ) );
	}
	$groups = array();
	$ids    = array();
	foreach ( cm_acf_groups( $post ) as $group ) {
		$fields = array();
		foreach ( (array) acf_get_fields( $group ) as $field ) {
			if ( in_array( $field['type'], CM_ACF_LAYOUT, true ) || ! cm_acf_shown( $field ) ) {
				continue;
			}
			$value    = acf_get_value( $post->ID, $field );
			$fields[] = cm_acf_describe( $field, true, $value );
			cm_acf_collect_refs( $field, $value, $ids );
		}
		$groups[] = array(
			'key'    => $group['key'],
			'title'  => $group['title'],
			'fields' => $fields,
		);
	}
	return array(
		'post_id' => $post->ID,
		'groups'  => $groups,
		'refs'    => (object) cm_acf_refs( $ids ),
	);
}

function cm_execute_update_acf_fields( $input ) {
	$post = get_post( absint( $input['post_id'] ) );
	if ( ! $post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'wp-sites-hq' ) );
	}
	$known = cm_acf_entry_fields( $post );
	$names = array_column( $known, 'key', 'name' );
	$set   = array();
	foreach ( (array) $input['fields'] as $selector => $value ) {
		$field = $known[ $selector ] ?? ( isset( $names[ $selector ] ) ? $known[ $names[ $selector ] ] : null );
		if ( ! $field ) {
			/* translators: %s: field key or name */
			return new WP_Error( 'acf_field_not_found', sprintf( __( '%s is not an ACF field of this entry.', 'wp-sites-hq' ), $selector ) );
		}
		if ( ! cm_acf_editable( $field ) ) {
			/* translators: 1: field label, 2: field type */
			return new WP_Error( 'acf_field_read_only', sprintf( __( '%1$s (%2$s) can only be changed in WordPress.', 'wp-sites-hq' ), $field['label'], $field['type'] ) );
		}
		// Booleans as ACF's true/false stores them; HTML filtered for users who may not post it.
		$set[ $field['key'] ] = map_deep(
			$value,
			function ( $v ) {
				if ( is_bool( $v ) ) {
					return (int) $v;
				}
				return is_string( $v ) && ! current_user_can( 'unfiltered_html' ) ? wp_kses_post( $v ) : $v;
			}
		);
	}
	if ( ! $set ) {
		return new WP_Error( 'missing_fields', __( 'Send at least one field.', 'wp-sites-hq' ) );
	}

	acf_reset_validation_errors();
	foreach ( $set as $key => $value ) {
		acf_validate_value( $value, $known[ $key ], 'acf[' . $key . ']' );
	}
	$errors = acf_get_validation_errors() ?: array(); // false when there are none
	acf_reset_validation_errors();
	if ( $errors ) {
		$plain = array();
		foreach ( $errors as $error ) {
			// The input path ends at the field that failed: acf[field_a][0][field_b].
			preg_match_all( '/\[(field_[^\]]+)\]/', (string) $error['input'], $m );
			$leaf    = acf_get_field( (string) end( $m[1] ) );
			$label   = $leaf ? $leaf['label'] : '';
			$message = trim( wp_strip_all_tags( html_entity_decode( (string) $error['message'], ENT_QUOTES ) ) );
			if ( '' !== $label && false === strpos( $message, $label ) ) {
				$message = $label . ': ' . $message;
			}
			$plain[] = array(
				'field'   => $leaf ? $leaf['key'] : '',
				'message' => $message,
			);
		}
		return new WP_Error( 'acf_invalid', implode( "\n", array_column( $plain, 'message' ) ), array( 'errors' => $plain ) );
	}

	$undo = array();
	foreach ( $set as $key => $value ) {
		$undo[ $key ] = array(
			'existed' => null !== acf_get_metadata( $post->ID, $known[ $key ]['name'] ),
			'value'   => acf_get_value( $post->ID, $known[ $key ] ),
		);
	}
	if ( class_exists( 'CM_AI_Activity_Log' ) ) {
		CM_AI_Activity_Log::set_undo(
			'classic-monks/update-acf-fields',
			array(
				'post_id' => $post->ID,
				'fields'  => $undo,
			)
		);
	}
	$values = array();
	foreach ( $set as $key => $value ) {
		update_field( $key, $value, $post->ID );
		acf_flush_value_cache( $post->ID, $known[ $key ]['name'] );
		$values[ $key ] = acf_get_value( $post->ID, $known[ $key ] );
	}
	return array(
		'post_id' => $post->ID,
		'updated' => array_keys( $set ),
		'values'  => (object) $values,
	);
}

/**
 * The current values of the fields a save names, for the undo drift check.
 */
function cm_preview_update_acf_fields( $input ) {
	$post = get_post( absint( $input['post_id'] ?? 0 ) );
	if ( ! $post || ! function_exists( 'acf_get_value' ) ) {
		return array();
	}
	$known  = cm_acf_entry_fields( $post );
	$names  = array_column( $known, 'key', 'name' );
	$values = array();
	foreach ( array_keys( (array) ( $input['fields'] ?? array() ) ) as $selector ) {
		$key = isset( $known[ $selector ] ) ? $selector : ( $names[ $selector ] ?? '' );
		if ( $key ) {
			acf_flush_value_cache( $post->ID, $known[ $key ]['name'] );
			$values[ $key ] = acf_get_value( $post->ID, $known[ $key ] );
		}
	}
	return $values;
}

/**
 * Puts the fields back as they were, deleting the ones that had no value before.
 */
function cm_undo_update_acf_fields( $undo ) {
	$post_id = absint( $undo['post_id'] ?? 0 );
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'wp-sites-hq' ) );
	}
	if ( ! function_exists( 'update_field' ) ) {
		return new WP_Error( 'cm_acf_missing', __( 'Advanced Custom Fields is not active on this site.', 'wp-sites-hq' ) );
	}
	foreach ( (array) ( $undo['fields'] ?? array() ) as $key => $old ) {
		if ( empty( $old['existed'] ) ) {
			delete_field( $key, $post_id );
		} else {
			update_field( $key, $old['value'], $post_id );
		}
	}
	return true;
}
