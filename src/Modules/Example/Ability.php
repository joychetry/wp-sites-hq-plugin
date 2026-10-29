<?php
/**
 * Abilities API registration for the example module.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Modules\Example;

use MonksCentral\Plugin;
use MonksCentral\Settings;

/**
 * `monks-central/related-posts`: lets AI clients (through the MCP Adapter) and REST callers
 * find related posts. Same query and permission rule as the REST route.
 */
final class Ability {

	/**
	 * Register the ability. Runs on `wp_abilities_api_init`.
	 */
	public static function register(): void {
		$post_shape = array(
			'type'       => 'object',
			'properties' => array(
				'id'    => array( 'type' => 'integer' ),
				'title' => array( 'type' => 'string' ),
				'link'  => array(
					'type'   => 'string',
					'format' => 'uri',
				),
			),
		);

		wp_register_ability(
			'monks-central/related-posts',
			array(
				'label'               => __( 'Find related posts', 'monks-central' ),
				'description'         => __( 'Lists published posts that share a category with the given post, newest first.', 'monks-central' ),
				'category'            => Plugin::ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Id of a published post.', 'monks-central' ),
							'minimum'     => 1,
						),
						'count'   => array(
							'type'        => 'integer',
							'description' => __( 'Maximum number of posts. Defaults to the plugin setting.', 'monks-central' ),
							'minimum'     => 1,
							'maximum'     => 10,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => $post_shape,
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'can_execute' ),
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

	/**
	 * Run the ability. Input is already validated against input_schema by core.
	 *
	 * @param array{post_id: int, count?: int} $input Ability input.
	 * @return list<array{id: int, title: string, link: string}>
	 */
	public static function execute( array $input ): array {
		$count = $input['count'] ?? ( new Settings() )->get_int( 'example_count' );
		return RelatedPosts::find( $input['post_id'], $count );
	}

	/**
	 * Same rule as the REST route: only for posts anyone can already read.
	 *
	 * @param array{post_id?: int} $input Ability input.
	 */
	public static function can_execute( array $input ): bool {
		return RelatedPosts::is_public( (int) ( $input['post_id'] ?? 0 ) );
	}
}
