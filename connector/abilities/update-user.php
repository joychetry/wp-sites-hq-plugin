<?php
/**
 * Update User Ability - WordPress Abilities API
 *
 * Changes a user's profile fields or role. Never passwords, capabilities or sessions.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_abilities_api_init', 'cm_register_update_user_ability' );
function cm_register_update_user_ability() {
    wp_register_ability( 'classic-monks/update-user', array(
        'label'       => __( 'Update User', 'classic-monks' ),
        'description' => __(
            "Updates a user's profile fields or role. Use get-users to find the user id. \n\n## Parameters\n- **user_id**: The user to change (required).\n- Any of: **display_name**, **first_name**, **last_name**, **email**, **url**, **description**, **role**.\n\n## Notes\n- Passwords, capabilities and sessions cannot be changed here.\n- You cannot change your own role or demote the last administrator.\n- Changing the email sends WordPress's usual notice to the old address.\n\n## Returns\nThe user id and the list of fields that changed.",
            'classic-monks'
        ),
        'category'    => 'users',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'user_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
                'display_name' => array( 'type' => 'string' ),
                'first_name'   => array( 'type' => 'string' ),
                'last_name'    => array( 'type' => 'string' ),
                'email'        => array( 'type' => 'string' ),
                'url'          => array( 'type' => 'string' ),
                'description'  => array( 'type' => 'string' ),
                'role'         => array( 'type' => 'string' ),
            ),
            'required'             => array( 'user_id' ),
            'minProperties'        => 2,
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'user_id' => array( 'type' => 'integer' ),
                'changed' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
            ),
        ),
        'execute_callback'    => 'cm_execute_update_user',
        'permission_callback' => function( $input ) {
            return ! empty( $input['user_id'] ) && current_user_can( 'edit_user', (int) $input['user_id'] );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => false,
                'destructive'  => true,
                'idempotent'   => true,
                'instructions' => 'Confirm the changes with the user, especially role and email changes.',
            ),
            'cm_preview_callback' => 'cm_preview_user_state',
            'cm_undo_callback'    => 'cm_undo_update_user',
            'cm_always_approve'   => true,
            'show_in_rest'        => true,
        ),
    ) );
}

/**
 * Who the change targets and their current role, for approvals and drift checks.
 */
function cm_preview_user_state( $input ) {
    $user = get_userdata( absint( $input['user_id'] ?? 0 ) );
    if ( ! $user ) {
        return null;
    }
    return array(
        'id'           => (int) $user->ID,
        'username'     => $user->user_login,
        'email'        => $user->user_email,
        'display_name' => $user->display_name,
        'roles'        => array_values( $user->roles ),
    );
}

/**
 * True when the user is the only administrator left.
 */
function cm_ai_is_last_admin( $user_id ) {
    $admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ) );
    return in_array( (int) $user_id, array_map( 'intval', $admins ), true ) && count( $admins ) < 2;
}

/**
 * A role that can manage the site (manage_options) may only be given by someone who can, as create-user requires.
 */
function cm_ai_can_assign_role( $role ) {
    $object = get_role( $role );
    return ! $object || empty( $object->capabilities['manage_options'] ) || current_user_can( 'manage_options' );
}

function cm_execute_update_user( $input ) {
    $user_id = absint( $input['user_id'] );
    $user    = get_userdata( $user_id );
    if ( ! $user ) {
        return new WP_Error( 'cm_user_not_found', __( 'User not found.', 'classic-monks' ) );
    }

    // Input field => WP_User field.
    $map  = array(
        'display_name' => 'display_name',
        'first_name'   => 'first_name',
        'last_name'    => 'last_name',
        'email'        => 'user_email',
        'url'          => 'user_url',
        'description'  => 'description',
    );
    $data = array( 'ID' => $user_id );
    $undo = array( 'user_id' => $user_id, 'fields' => array() );

    foreach ( $map as $field => $wp_field ) {
        if ( ! isset( $input[ $field ] ) ) {
            continue;
        }
        switch ( $field ) {
            case 'email':
                $value = sanitize_email( $input[ $field ] );
                if ( ! is_email( $value ) ) {
                    return new WP_Error( 'cm_user_bad_email', __( 'The email address is not valid.', 'classic-monks' ) );
                }
                break;
            case 'url':
                $value = esc_url_raw( $input[ $field ] );
                break;
            case 'description':
                $value = wp_kses_post( $input[ $field ] );
                break;
            default:
                $value = sanitize_text_field( $input[ $field ] );
        }
        $data[ $wp_field ]           = $value;
        $undo['fields'][ $wp_field ] = $user->$wp_field;
    }

    $role = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : null;
    if ( null !== $role ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if ( get_current_user_id() === $user_id ) {
            return new WP_Error( 'cm_user_own_role', __( 'You cannot change your own role.', 'classic-monks' ) );
        }
        if ( ! current_user_can( 'promote_user', $user_id ) || ! isset( get_editable_roles()[ $role ] ) ) {
            return new WP_Error( 'cm_user_role_not_allowed', __( 'That role does not exist or you are not allowed to assign it.', 'classic-monks' ) );
        }
        if ( ! cm_ai_can_assign_role( $role ) ) {
            return new WP_Error( 'cm_user_admin_role_forbidden', __( 'Only administrators can give a user this role.', 'classic-monks' ) );
        }
        if ( 'administrator' !== $role && cm_ai_is_last_admin( $user_id ) ) {
            return new WP_Error( 'cm_user_last_admin', __( 'This is the last administrator. Make someone else an administrator first.', 'classic-monks' ) );
        }
        $undo['roles'] = array_values( $user->roles );
    }

    if ( class_exists( 'CM_AI_Activity_Log' ) ) {
        CM_AI_Activity_Log::set_undo( 'classic-monks/update-user', $undo );
    }

    if ( count( $data ) > 1 ) {
        $result = wp_update_user( $data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }
    if ( null !== $role ) {
        $user->set_role( $role );
    }

    return array(
        'user_id' => $user_id,
        'changed' => array_values( array_diff( array_keys( $input ), array( 'user_id' ) ) ),
    );
}

/**
 * Put back the profile fields and roles logged before the change.
 */
function cm_undo_update_user( $undo ) {
    $user_id = absint( $undo['user_id'] ?? 0 );
    $user    = get_userdata( $user_id );
    if ( ! $user || ! current_user_can( 'edit_user', $user_id ) ) {
        return new WP_Error( 'forbidden', __( 'You are not allowed to edit this user.', 'classic-monks' ) );
    }
    // Checked before any write so a refused role restore leaves the profile untouched too.
    if ( isset( $undo['roles'] ) ) {
        if ( ! current_user_can( 'promote_user', $user_id ) ) {
            return new WP_Error( 'forbidden', __( 'You are not allowed to change this user\'s role.', 'classic-monks' ) );
        }
        foreach ( (array) $undo['roles'] as $role ) {
            if ( ! cm_ai_can_assign_role( $role ) ) {
                return new WP_Error( 'cm_user_admin_role_forbidden', __( 'Only administrators can give a user this role.', 'classic-monks' ) );
            }
        }
    }

    if ( ! empty( $undo['fields'] ) ) {
        $result = wp_update_user( array_merge( (array) $undo['fields'], array( 'ID' => $user_id ) ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }

    if ( isset( $undo['roles'] ) ) {
        $user->set_role( '' );
        foreach ( (array) $undo['roles'] as $role ) {
            $user->add_role( $role );
        }
    }

    return true;
}
