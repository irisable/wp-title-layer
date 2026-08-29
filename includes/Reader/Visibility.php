<?php
/**
 * Public-read visibility checks shared by archive and navigation services.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

defined( 'ABSPATH' ) || exit;

final class Visibility {
	/**
	 * Reader links deliberately include only publicly reachable, published,
	 * non-password-protected posts. Logged-in privileges do not broaden this
	 * list, so navigation cannot disclose drafts, private posts, or schedules.
	 */
	public function isPublicPost( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! is_post_type_viewable( $post_type ) ) {
			return false;
		}

		$url = get_permalink( $post );
		return is_string( $url ) && '' !== $url;
	}

	/** @return string[] */
	public function publicPostTypesForTaxonomy( string $taxonomy ): array {
		$taxonomy_object = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_object ) {
			return array();
		}

		$types = array();
		foreach ( (array) $taxonomy_object->object_type as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object && is_post_type_viewable( $object ) ) {
				$types[] = $post_type;
			}
		}

		return array_values( array_unique( $types ) );
	}
}
