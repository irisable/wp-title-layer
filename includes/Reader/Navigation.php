<?php
/**
 * Ordered Series navigation service.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Sequence;
use WPTitleLayer\Core\Series;
use WPTitleLayer\Core\BookStructure;

defined( 'ABSPATH' ) || exit;

final class Navigation {
	/** @var Visibility */
	private $visibility;

	public function __construct( ?Visibility $visibility = null ) {
		$this->visibility = $visibility ?: new Visibility();
	}

	/**
	 * Build navigation context for a public post in an ordered Series.
	 *
	 * @return array<string,mixed>
	 */
	public function context( int $post_id ): array {
		$empty = self::emptyContext( $post_id );
		$taxonomy = Series::claimed_taxonomy();
		if ( '' === $taxonomy || ! $this->visibility->isPublicPost( $post_id ) ) {
			return $empty;
		}

		$term = Series::get_primary_term( $post_id );
		if ( ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy || ! Series::is_ordered( $term ) ) {
			return $empty;
		}

		$season_key = Series::is_seasoned( $term ) ? $this->seasonKeyForPost( $post_id ) : '';
		$scope      = Series::navigation_scope( $term );
		$season     = '' !== $season_key ? $this->seasonDefinition( $term, $season_key ) : null;
		$post_ids   = $this->scopedPostIds( $term, $season_key );
		$index      = array_search( $post_id, $post_ids, true );
		if ( false === $index ) {
			return $empty;
		}

		$total    = count( $post_ids );
		$previous = 0 < $index ? $this->postLink( $post_ids[ $index - 1 ] ) : null;
		$next     = $index + 1 < $total ? $this->postLink( $post_ids[ $index + 1 ] ) : null;
		$first    = $total ? $this->postLink( $post_ids[0] ) : null;
		$term_url       = Series::public_archive_url( $term );
		$season_context = is_array( $season )
			? array(
				'key'   => (string) $season['key'],
				'label' => (string) $season['label'],
				'url'   => Series::public_season_archive_url( $term, (string) $season['key'] ),
			)
			: null;

		$context = array(
			'enabled'     => true,
			'post_id'     => $post_id,
			'series'      => array(
				'id'   => (int) $term->term_id,
				'name' => (string) $term->name,
				'url'  => $term_url,
			),
			'previous'    => $previous,
			'next'        => $next,
			'first'       => $first,
			'position'    => $index + 1,
			'total'       => $total,
			'scope'       => $scope,
			'season_key'  => $season_key,
			'season'      => $season_context,
		);

		/**
		 * @param array<string,mixed> $context Navigation context.
		 * @param int                 $post_id Current post ID.
		 * @param \WP_Term            $term    Series term.
		 */
		$filtered = apply_filters( 'wptl_reader_navigation_context', $context, $post_id, $term );

		return is_array( $filtered ) ? array_merge( $context, $filtered ) : $context;
	}

	/**
	 * Return every public post in cross-season reading order.
	 *
	 * This method is intentionally public for integration tests and alternate
	 * renderers. It always returns an empty array for unordered Series.
	 *
	 * @return int[]
	 */
	public function orderedPostIds( \WP_Term $term ): array {
		$taxonomy = Series::claimed_taxonomy();
		if ( '' === $taxonomy || $taxonomy !== $term->taxonomy || ! Series::is_ordered( $term ) ) {
			return array();
		}

		$post_ids = Sequence::ordered_post_ids( $term, '', true );

		/**
		 * @param int[]    $post_ids Ordered public post IDs.
		 * @param \WP_Term $term     Series term.
		 */
		$filtered = apply_filters( 'wptl_reader_ordered_post_ids', $post_ids, $term );
		if ( ! is_array( $filtered ) ) {
			return $post_ids;
		}

		$allowed = array();
		foreach ( $filtered as $candidate ) {
			$candidate = absint( $candidate );
			if ( in_array( $candidate, $post_ids, true ) ) {
				$allowed[ $candidate ] = true;
			}
		}

		// Extensions may remove entries, but cannot reorder the canonical sequence.
		return array_values(
			array_filter(
				$post_ids,
				static function ( int $candidate ) use ( $allowed ): bool {
					return isset( $allowed[ $candidate ] );
				}
			)
		);
	}

	/**
	 * Return the exact public collection used by previous, next, start, and
	 * progress for one article context.
	 *
	 * Existing and flat Series always retain the full-Series collection. A
	 * current-season scope fails closed when the article has no exact defined
	 * season, rather than silently crossing the configured boundary.
	 *
	 * @return int[]
	 */
	public function scopedPostIds( \WP_Term $term, string $season_key = '' ): array {
		$post_ids = $this->orderedPostIds( $term );
		if ( Schema::NAVIGATION_SCOPE_SEASON !== Series::navigation_scope( $term ) ) {
			return $post_ids;
		}

		if ( ! $this->seasonDefinition( $term, $season_key ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$post_ids,
				function ( int $candidate_id ) use ( $season_key ): bool {
					return $season_key === $this->seasonKeyForPost( $candidate_id );
				}
			)
		);
	}

	/** @return array{id:int,title:string,url:string}|null */
	public function postLink( int $post_id ): ?array {
		if ( ! $this->visibility->isPublicPost( $post_id ) ) {
			return null;
		}

		$url = get_permalink( $post_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		return array(
			'id'    => $post_id,
			'title' => (string) get_post_field( 'post_title', $post_id, 'display' ),
			'url'   => $url,
		);
	}

	private function seasonKeyForPost( int $post_id ): string {
		$term = Series::get_primary_term( $post_id );
		if ( $term instanceof \WP_Term && BookStructure::is_enabled( $term ) ) {
			$context = BookStructure::context( $post_id, $term );
			return ! empty( $context['valid'] ) ? (string) $context['season_key'] : '';
		}
		$value = get_post_meta( $post_id, Schema::META_SEASON_KEY, true );
		return is_string( $value ) ? $value : '';
	}

	/** @return array{key:string,label:string,sort:int}|null */
	private function seasonDefinition( \WP_Term $term, string $season_key ): ?array {
		if ( '' === $season_key ) {
			return null;
		}
		foreach ( Series::seasons( $term ) as $season ) {
			if ( $season_key === $season['key'] ) {
				return $season;
			}
		}

		return null;
	}

	/** @return array<string,mixed> */
	public static function emptyContext( int $post_id = 0 ): array {
		return array(
			'enabled'     => false,
			'post_id'     => $post_id,
			'series'      => null,
			'previous'    => null,
			'next'        => null,
			'first'       => null,
			'position'    => 0,
			'total'       => 0,
			'scope'       => Schema::NAVIGATION_SCOPE_SERIES,
			'season_key' => '',
			'season'      => null,
		);
	}
}
