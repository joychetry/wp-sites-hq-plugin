<?php
/**
 * Classic Monks Ability Categories Registration
 *
 * Registers ability categories using the WordPress Abilities API.
 * These categories organize AI abilities for better discoverability.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 1.2.0
 * @requires WordPress 6.9+ (Abilities API)
 *
 * @see https://developer.wordpress.org/reference/functions/wp_register_ability_category/
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register ability categories for Classic Monks.
 *
 * Categories must be registered on the wp_abilities_api_categories_init hook
 * before abilities that reference them. Uses wp_has_ability_category() to
 * prevent duplicate registration conflicts.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_categories_init', 'cm_register_ability_categories' );
function cm_register_ability_categories() {

	/**
	 * Content Management Category
	 *
	 * Abilities for creating, reading, updating, and deleting WordPress content.
	 * Includes posts, pages, custom post types, and related metadata.
	 */
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'content' ) ) {
		wp_register_ability_category(
			'content',
			array(
				'label'       => __( 'Content Management', 'monks-central' ),
				'description' => __(
					'Abilities for creating, reading, updating, and deleting WordPress content. Includes posts, pages, custom post types, and related metadata operations.',
					'monks-central'
				),
			)
		);
	}

	/**
	 * System Information Category
	 *
	 * Abilities that retrieve information about the WordPress installation.
	 * Includes site details, plugins, themes, server info, and diagnostics.
	 */
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'system' ) ) {
		wp_register_ability_category(
			'system',
			array(
				'label'       => __( 'System Information', 'monks-central' ),
				'description' => __(
					'Abilities that retrieve information about the WordPress installation. Includes site details, plugins, themes, server environment, and diagnostics.',
					'monks-central'
				),
			)
		);
	}

	/**
	 * Taxonomy Management Category
	 *
	 * Abilities for managing categories, tags, and custom taxonomies.
	 * Includes term creation, retrieval, and organization.
	 */
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'taxonomy' ) ) {
		wp_register_ability_category(
			'taxonomy',
			array(
				'label'       => __( 'Taxonomy Management', 'monks-central' ),
				'description' => __(
					'Abilities for managing categories, tags, and custom taxonomies. Includes term creation, retrieval, hierarchy management, and organization.',
					'monks-central'
				),
			)
		);
	}

	/**
	 * Comments Management Category
	 *
	 * Abilities for managing WordPress comments.
	 * Includes comment retrieval, moderation, and analysis.
	 */
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'comments' ) ) {
		wp_register_ability_category(
			'comments',
			array(
				'label'       => __( 'Comments Management', 'monks-central' ),
				'description' => __(
					'Abilities for managing WordPress comments. Includes comment retrieval, moderation status, and comment analysis.',
					'monks-central'
				),
			)
		);
	}

	/**
	 * User Management Category
	 *
	 * Abilities for managing users and user data.
	 * Includes user retrieval, role information, and user statistics.
	 */
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'users' ) ) {
		wp_register_ability_category(
			'users',
			array(
				'label'       => __( 'User Management', 'monks-central' ),
				'description' => __(
					'Abilities for managing users and user data. Includes user retrieval, role information, capabilities, and user statistics.',
					'monks-central'
				),
			)
		);
	}

	/**
	 * Media Management Category
	 *
	 * Abilities for managing media files and attachments.
	 * Includes image retrieval and AI-powered alt text generation.
	 */
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'media' ) ) {
		wp_register_ability_category(
			'media',
			array(
				'label'       => __( 'Media Management', 'monks-central' ),
				'description' => __(
					'Abilities for managing media files and attachments. Includes image retrieval, metadata, and AI-powered alt text generation.',
					'monks-central'
				),
			)
		);
	}

	/**
	 * Classic Monks Native Category
	 *
	 * Abilities specific to Classic Monks plugin features.
	 * Includes settings suggestions, code generation, and review.
	 */
	if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( 'classic-monks' ) ) {
		wp_register_ability_category(
			'classic-monks',
			array(
				'label'       => __( 'Monks Central', 'monks-central' ),
				'description' => __(
					'Site status for Monks Central and the approval requests waiting for you.',
					'monks-central'
				),
			)
		);
	}
}
