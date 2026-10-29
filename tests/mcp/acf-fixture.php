<?php
/**
 * ACF test fixture: a field group on posts with one field of every type Monks Central edits, plus a few it
 * shows read-only, and optionally a post filled in. Used by acf.sh and to set up Studio sites.
 * Run: wp eval-file tests/mcp/acf-fixture.php "<group title>" [<post ID to fill>]
 * Prints the group key. Types missing from the active ACF build (repeater, gallery, flexible content in ACF free) are skipped.
 */

$title = $args[0] ?? 'Monks test fields';
$slug  = sanitize_key( str_replace( ' ', '_', strtolower( $title ) ) );
$f     = function ( $type, $extra = array() ) use ( $slug ) {
    return array_merge( array( 'key' => "field_{$slug}_{$type}", 'name' => "{$slug}_{$type}", 'label' => ucfirst( str_replace( '_', ' ', $type ) ), 'type' => $type ), $extra );
};
$fields = array(
    $f( 'tab', array( 'label' => 'Main' ) ),
    $f( 'text', array( 'required' => 1, 'instructions' => 'Required.' ) ),
    $f( 'textarea' ),
    $f( 'number', array( 'min' => 1, 'max' => 10 ) ),
    $f( 'range', array( 'min' => 0, 'max' => 100 ) ),
    $f( 'email' ),
    $f( 'url' ),
    $f( 'password' ),
    $f( 'select', array( 'choices' => array( 'red' => 'Red', 'green' => 'Green', 'blue' => 'Blue' ) ) ),
    $f( 'checkbox', array( 'choices' => array( 'a' => 'A', 'b' => 'B', 'c' => 'C' ) ) ),
    $f( 'radio', array( 'choices' => array( 's' => 'Small', 'm' => 'Medium', 'l' => 'Large' ) ) ),
    $f( 'button_group', array( 'choices' => array( 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ) ) ),
    $f( 'true_false', array( 'ui' => 1 ) ),
    $f( 'wysiwyg' ),
    $f( 'image', array( 'return_format' => 'id' ) ),
    $f( 'file', array( 'return_format' => 'id' ) ),
    $f( 'date_picker' ),
    $f( 'date_time_picker' ),
    $f( 'time_picker' ),
    $f( 'color_picker' ),
    $f( 'link' ),
    $f( 'post_object', array( 'post_type' => array( 'post' ) ) ),
    $f( 'page_link', array( 'post_type' => array( 'page' ) ) ),
    $f( 'relationship', array( 'post_type' => array( 'post' ) ) ),
    $f( 'taxonomy', array( 'taxonomy' => 'category', 'field_type' => 'checkbox' ) ),
    $f( 'user' ),
    $f( 'group', array( 'sub_fields' => array( $f( 'group_line', array( 'name' => 'line', 'label' => 'Line', 'type' => 'text' ) ), $f( 'group_count', array( 'name' => 'count', 'label' => 'Count', 'type' => 'number', 'max' => 5 ) ) ) ) ),
    $f( 'oembed' ),
    $f( 'message', array( 'message' => 'Fields Monks Central edits are above.' ) ),
);
if ( acf_get_field_type( 'repeater' ) ) {
    $fields[] = $f( 'repeater', array( 'sub_fields' => array( $f( 'repeater_label', array( 'name' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => 1 ) ), $f( 'repeater_photo', array( 'name' => 'photo', 'label' => 'Photo', 'type' => 'image' ) ) ) ) );
    $fields[] = $f( 'gallery' );
    $fields[] = $f( 'flexible_content', array( 'layouts' => array( 'layout_hero' => array( 'key' => 'layout_hero', 'name' => 'hero', 'label' => 'Hero', 'display' => 'block', 'sub_fields' => array( $f( 'hero_heading', array( 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ) ) ) ) ) ) );
}
$existing = acf_get_field_group( "group_{$slug}" );
$group    = acf_import_field_group( array(
    'ID'       => $existing ? $existing['ID'] : 0,
    'key'      => "group_{$slug}",
    'title'    => $title,
    'fields'   => $fields,
    'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
    'active'   => true,
) );

if ( ! empty( $args[1] ) ) {
    $post  = (int) $args[1];
    $image = (int) ( get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 1, 'fields' => 'ids' ) )[0] ?? 0 );
    $other = (int) ( get_posts( array( 'numberposts' => 1, 'exclude' => array( $post ), 'fields' => 'ids' ) )[0] ?? 0 );
    $page  = (int) ( get_posts( array( 'post_type' => 'page', 'numberposts' => 1, 'fields' => 'ids' ) )[0] ?? 0 );
    $values = array(
        'text'             => 'Hello from Monks Central',
        'textarea'         => "Two\nlines",
        'number'           => 7,
        'range'            => 40,
        'email'            => 'test@example.com',
        'url'              => 'https://example.com/',
        'password'         => 'not-a-real-secret',
        'select'           => 'green',
        'checkbox'         => array( 'a', 'c' ),
        'radio'            => 'm',
        'button_group'     => 'center',
        'true_false'       => 1,
        'wysiwyg'          => '<p>Some <strong>rich</strong> text.</p>',
        'image'            => $image,
        'file'             => $image,
        'date_picker'      => gmdate( 'Ymd' ),
        'date_time_picker' => gmdate( 'Y-m-d H:i:s' ),
        'time_picker'      => '09:30:00',
        'color_picker'     => '#7a5af8',
        'link'             => array( 'title' => 'Example', 'url' => 'https://example.com/', 'target' => '_blank' ),
        'post_object'      => $other,
        'page_link'        => $page,
        'relationship'     => array_filter( array( $other ) ),
        'taxonomy'         => array( (int) get_option( 'default_category' ) ),
        'user'             => 1,
        'group'            => array( "field_{$slug}_group_line" => 'Inside a group', "field_{$slug}_group_count" => 3 ),
        'oembed'           => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    );
    if ( acf_get_field_type( 'repeater' ) ) {
        $values['repeater'] = array(
            array( "field_{$slug}_repeater_label" => 'First row', "field_{$slug}_repeater_photo" => $image ),
            array( "field_{$slug}_repeater_label" => 'Second row', "field_{$slug}_repeater_photo" => '' ),
        );
        $values['gallery']  = array_filter( array( $image ) );
    }
    foreach ( $values as $type => $value ) {
        update_field( "field_{$slug}_{$type}", $value, $post );
    }
}
echo $group['key'], "\n";
