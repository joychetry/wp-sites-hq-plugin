<?php
/**
 * Shared checks for create-post and update-post (WP Sites HQ M6): terms for any
 * taxonomy, custom fields and the publish date, plus page-builder detection.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Input schema for the M6 fields, shared by create-post and update-post.
 *
 * @return array
 */
function cm_content_write_schema() {
	return array(
		'terms'      => array(
			'type'                 => 'object',
			'description'          => 'Terms by taxonomy, replacing the post\'s terms in each taxonomy given: {"category": [3, "News"], "genre": ["Jazz"]}. IDs must exist; names are created if missing and you may add terms. An empty list removes every term in that taxonomy.',
			'additionalProperties' => array(
				'type'  => 'array',
				'items' => array( 'type' => array( 'integer', 'string' ) ),
			),
		),
		'meta'       => array(
			'type'                 => 'object',
			'description'          => 'Custom fields to set: {"key": "value"}. Protected keys (starting with _) are refused. null deletes the field.',
			'additionalProperties' => array( 'type' => array( 'string', 'number', 'boolean', 'null' ) ),
		),
		'date'       => array(
			'type'        => 'string',
			'description' => 'Publish date in the site\'s time zone, "YYYY-MM-DD HH:MM:SS". A future date with status publish or future schedules the post.',
		),
		'slug'       => array(
			'type'        => 'string',
			'description' => 'The address part of the permalink. WordPress makes it unique.',
		),
		'author'     => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'User ID of the author. Someone else needs permission to edit others\' entries, and the author must be able to write this type.',
		),
		'parent'     => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => 'Parent entry of the same hierarchical type (pages); 0 for none.',
		),
		'menu_order' => array(
			'type'        => 'integer',
			'description' => 'Order among siblings (page attributes).',
		),
		'template'   => array(
			'type'        => 'string',
			'description' => 'Page template file from get-post-by-id choices.templates; "" for the default template.',
		),
		'password'   => array(
			'type'        => 'string',
			'maxLength'   => 255,
			'description' => 'A password visitors must type to read it; "" makes it public again.',
		),
	);
}

/**
 * Check the editor's post fields (slug, author, parent, order, template, password) and add them to
 * $post_data for wp_insert_post() / wp_update_post(). Nothing is written here.
 *
 * @param string $post_type Post type slug.
 * @param int    $post_id   0 when creating.
 * @param array  $input     Ability input.
 * @param array  $post_data Post array, filled in.
 * @return string[]|WP_Error Fields set.
 */
function cm_content_post_fields( $post_type, $post_id, $input, array &$post_data ) {
	$type   = get_post_type_object( $post_type );
	$fields = array();
	if ( isset( $input['slug'] ) ) {
		$post_data['post_name'] = sanitize_title( $input['slug'] );
		$fields[]               = 'slug';
	}
	if ( isset( $input['author'] ) ) {
		$author = absint( $input['author'] );
		$was    = $post_id ? (int) get_post_field( 'post_author', $post_id ) : get_current_user_id();
		if ( $author !== $was && ! current_user_can( $type->cap->edit_others_posts ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to give entries to someone else.', 'monks-central' ) );
		}
		if ( $author !== $was && ! user_can( $author, $type->cap->edit_posts ) ) {
			return new WP_Error( 'invalid_author', __( 'That user can\'t write this type of entry.', 'monks-central' ) );
		}
		$post_data['post_author'] = $author;
		$fields[]                 = 'author';
	}
	if ( isset( $input['parent'] ) ) {
		$parent = absint( $input['parent'] );
		if ( $parent && ( ! is_post_type_hierarchical( $post_type ) || get_post_type( $parent ) !== $post_type || $parent === (int) $post_id ) ) {
			return new WP_Error( 'invalid_parent', __( 'The parent must be another entry of the same type.', 'monks-central' ) );
		}
		$post_data['post_parent'] = $parent;
		$fields[]                 = 'parent';
	}
	if ( isset( $input['menu_order'] ) ) {
		$post_data['menu_order'] = (int) $input['menu_order'];
		$fields[]                = 'menu_order';
	}
	if ( isset( $input['template'] ) ) {
		$template = (string) $input['template'];
		if ( '' !== $template && 'default' !== $template && ! isset( wp_get_theme()->get_page_templates( $post_id ? get_post( $post_id ) : null, $post_type )[ $template ] ) ) {
			/* translators: %s: template file */
			return new WP_Error( 'invalid_template', sprintf( __( 'The theme has no template "%s" for this type.', 'monks-central' ), $template ) );
		}
		$post_data['page_template'] = '' === $template ? 'default' : $template;
		$fields[]                   = 'template';
	}
	if ( isset( $input['password'] ) ) {
		$post_data['post_password'] = (string) $input['password'];
		$fields[]                   = 'password';
	}
	return $fields;
}

/**
 * What the editor can choose from for this entry: authors (when the user may change the author),
 * parents (hierarchical types) and page templates.
 *
 * @param WP_Post $post Post.
 * @return array{authors: array, parents: array, templates: object}
 */
function cm_content_choices( $post ) {
	$type    = get_post_type_object( $post->post_type );
	$authors = array();
	if ( current_user_can( $type->cap->edit_others_posts ) ) {
		$users = get_users(
			array(
				'capability' => array( $type->cap->edit_posts ),
				'number'     => 200,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		foreach ( $users as $u ) {
			$authors[] = array(
				'id'   => (int) $u->ID,
				'name' => $u->display_name,
			);
		}
	}
	$parents = array();
	if ( is_post_type_hierarchical( $post->post_type ) ) {
		$pages = get_pages(
			array(
				'post_type'    => $post->post_type,
				'exclude_tree' => $post->ID,
				'post_status'  => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'number'       => 200,
				'sort_column'  => 'menu_order,post_title',
			)
		);
		foreach ( (array) $pages as $p ) {
			// Others' drafts and private entries only for users who may read them, as wp-admin does.
			if ( current_user_can( 'read_post', $p->ID ) ) {
				$parents[] = array(
					'id'    => (int) $p->ID,
					'title' => $p->post_title,
				);
			}
		}
	}
	return array(
		'authors'   => $authors,
		'parents'   => $parents,
		'templates' => (object) wp_get_theme()->get_page_templates( $post, $post->post_type ),
	);
}

/**
 * Check terms, meta and date before anything is written.
 *
 * @param string $post_type Post type slug.
 * @param int    $post_id   0 when creating.
 * @param array  $input     Ability input.
 * @return true|WP_Error
 */
function cm_content_write_check( $post_type, $post_id, $input ) {
	foreach ( (array) ( $input['terms'] ?? array() ) as $taxonomy => $items ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			/* translators: 1: taxonomy slug, 2: post type slug */
			return new WP_Error( 'invalid_taxonomy', sprintf( __( 'The taxonomy "%1$s" is not used by %2$s.', 'monks-central' ), $taxonomy, $post_type ) );
		}
		if ( ! current_user_can( $tax->cap->assign_terms ) ) {
			/* translators: %s: taxonomy label */
			return new WP_Error( 'forbidden', sprintf( __( 'You are not allowed to assign %s.', 'monks-central' ), $tax->label ) );
		}
		foreach ( (array) $items as $item ) {
			if ( is_int( $item ) ) {
				if ( ! get_term( $item, $taxonomy ) instanceof WP_Term ) {
					/* translators: 1: term ID, 2: taxonomy label */
					return new WP_Error( 'term_not_found', sprintf( __( 'There is no term %1$d in %2$s.', 'monks-central' ), $item, $tax->label ) );
				}
			} elseif ( '' === trim( (string) $item ) ) {
				return new WP_Error( 'invalid_term', __( 'A term name is empty.', 'monks-central' ) );
			} elseif ( ! term_exists( (string) $item, $taxonomy ) && ! current_user_can( $tax->cap->edit_terms ) ) {
				/* translators: 1: term name, 2: taxonomy label */
				return new WP_Error( 'forbidden', sprintf( __( '"%1$s" is not in %2$s yet, and you are not allowed to add terms there.', 'monks-central' ), $item, $tax->label ) );
			}
		}
	}

	foreach ( array_keys( (array) ( $input['meta'] ?? array() ) ) as $key ) {
		$key = (string) $key;
		if ( '' === $key || is_protected_meta( $key, 'post' ) ) {
			/* translators: %s: meta key */
			return new WP_Error( 'protected_meta', sprintf( __( 'The custom field "%s" is protected. Its plugin manages it.', 'monks-central' ), $key ) );
		}
		$cap = $post_id ? current_user_can( 'edit_post_meta', $post_id, $key ) : current_user_can( get_post_type_object( $post_type )->cap->edit_posts );
		if ( ! $cap ) {
			/* translators: %s: meta key */
			return new WP_Error( 'forbidden', sprintf( __( 'You are not allowed to change the custom field "%s".', 'monks-central' ), $key ) );
		}
	}

	if ( isset( $input['date'] ) ) {
		$d = DateTime::createFromFormat( 'Y-m-d H:i:s', (string) $input['date'] );
		if ( ! $d || $d->format( 'Y-m-d H:i:s' ) !== $input['date'] ) {
			return new WP_Error( 'invalid_date', __( 'date must look like 2026-11-02 09:30:00.', 'monks-central' ) );
		}
	}
	return true;
}

/**
 * Write terms and meta after the post exists. Checked first by cm_content_write_check().
 *
 * @param int   $post_id Post ID.
 * @param array $input   Ability input.
 * @return string[]|WP_Error Fields written.
 */
function cm_content_write_after( $post_id, $input ) {
	// On a new post the check above had no post to ask about: now ask, with the field's own
	// auth callback (register_meta), before anything is written.
	foreach ( array_keys( (array) ( $input['meta'] ?? array() ) ) as $key ) {
		if ( ! current_user_can( 'edit_post_meta', $post_id, (string) $key ) ) {
			/* translators: %s: meta key */
			return new WP_Error( 'forbidden', sprintf( __( 'You are not allowed to change the custom field "%s".', 'monks-central' ), $key ) );
		}
	}
	$written = array();
	foreach ( (array) ( $input['terms'] ?? array() ) as $taxonomy => $items ) {
		$items = array_map(
			function ( $i ) {
				return is_int( $i ) ? $i : sanitize_text_field( $i );
			},
			(array) $items
		);
		$set   = wp_set_object_terms( $post_id, $items, $taxonomy );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$written[] = 'terms:' . $taxonomy;
	}
	foreach ( (array) ( $input['meta'] ?? array() ) as $key => $value ) {
		if ( null === $value ) {
			delete_post_meta( $post_id, (string) $key );
		} else {
			update_post_meta( $post_id, wp_slash( (string) $key ), wp_slash( is_bool( $value ) ? (int) $value : $value ) );
		}
		$written[] = 'meta:' . $key;
	}
	return $written;
}

/**
 * Every term on a post, by taxonomy.
 *
 * @param WP_Post $post Post.
 * @return object Taxonomy => array{id:int, name:string, slug:string}[]; an object so no terms encode as {}.
 */
function cm_content_post_terms( $post ) {
	$out = array();
	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		$terms            = get_the_terms( $post, $taxonomy );
		$out[ $taxonomy ] = is_array( $terms ) ? array_map(
			function ( $t ) {
				return array(
					'id'   => $t->term_id,
					'name' => $t->name,
					'slug' => $t->slug,
				);
			},
			$terms
		) : array();
	}
	return (object) $out;
}

/**
 * The page builder that renders this post, if any. Its body lives in the builder's
 * own data, so editing post_content would not change the page (or would mangle it).
 *
 * @param int $post_id Post ID.
 * @return string|null 'elementor', 'bricks' or null.
 */
function cm_content_builder( $post_id ) {
	if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
		return 'elementor';
	}
	if ( get_post_meta( $post_id, '_bricks_page_content_2', true ) && 'WordPress' !== get_post_meta( $post_id, '_bricks_editor_mode', true ) ) {
		return 'bricks';
	}
	return null;
}
