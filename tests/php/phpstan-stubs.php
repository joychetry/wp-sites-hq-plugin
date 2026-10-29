<?php
/**
 * Signatures of the optional plugin APIs the connector calls when those plugins are active, for PHPStan.
 *
 * @package MonksCentral
 */

// Advanced Custom Fields / Secure Custom Fields.
function acf_get_field_groups( array $filter = array() ): array {}
function acf_get_fields( $parent ): array {}
function acf_get_field( $selector, $post_id = false ) {}
function acf_prepare_field( $field ) {}
function acf_get_value( $post_id, $field ) {}
function acf_get_metadata( $post_id = 0, $name = '', $hidden = false ) {}
function acf_reset_validation_errors(): void {}
function acf_validate_value( $value, $field, $input ): bool {}
function acf_get_validation_errors() {}
function acf_flush_value_cache( $post_id = 0, $field_name = '' ): void {}
function update_field( $selector, $value, $post_id = false ): bool {}
function delete_field( $selector, $post_id = false ): bool {}

// Classic Monks Pro's SVG sanitizer.
function cm_sanitize_svg( string $svg ) {}
