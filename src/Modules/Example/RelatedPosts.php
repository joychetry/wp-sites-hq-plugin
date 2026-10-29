<?php
/**
 * The one query behind the example module, shared by REST and the ability.
 *
 * @package MonksCentral
 */

namespace MonksCentral\Modules\Example;

use WP_Query;

/**
 * Finds published posts that share a category with a given post.
 */
final class RelatedPosts {

	/**
	 * Related posts, newest first.
	 *
	 * @param int $post_id Post to find relatives of.
	 * @param int $count   Maximum number of posts.
	 * @return list<array{id: int, title: string, link: string}>
	 */
	public static function find( int $post_id, int $count ): array {
		$categories = wp_get_post_categories( $post_id );
		if ( ! is_array( $categories ) || array() === $categories ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'category__in'           => $categories,
				// One extra instead of post__not_in, which is slow on big sites. The source post is dropped below.
				'posts_per_page'         => $count + 1,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$posts = array();
		foreach ( $query->posts as $id ) {
			$id = (int) $id;
			if ( $id === $post_id || count( $posts ) === $count ) {
				continue;
			}
			$posts[] = array(
				'id'    => $id,
				// Plain text: the front end prints it with data-wp-text, which never parses HTML.
				'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' ),
				'link'  => (string) get_permalink( $id ),
			);
		}
		return $posts;
	}

	/**
	 * Whether anyone may see the related posts of this post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function is_public( int $post_id ): bool {
		return is_post_publicly_viewable( $post_id ) && ! post_password_required( $post_id );
	}
}
