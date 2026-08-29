<?php
/**
 * Explicit Series/Season scope and book-like role tracks.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps scope separate from role and activates the new ordering model only
 * after one Series has passed a read-only compatibility preview.
 */
final class BookStructure {
	public const VERSION = 1;
	private const JOURNAL_PREFIX = 'wptl_book_structure_';

	/** @param int|\WP_Term $term */
	public static function is_enabled( $term ): bool {
		$term_id = $term instanceof \WP_Term ? (int) $term->term_id : absint( $term );
		return 0 < $term_id
			&& self::VERSION === (int) get_term_meta( $term_id, Schema::TERM_META_BOOK_STRUCTURE_VERSION, true );
	}

	public static function stored_role( int $post_id ): string {
		return Meta::sanitize_role( get_post_meta( $post_id, Schema::META_SERIES_ROLE, true ) );
	}

	public static function role( int $post_id ): string {
		$role = self::stored_role( $post_id );
		return '' === $role ? Schema::ROLE_ARTICLE : $role;
	}

	public static function stored_scope( int $post_id ): string {
		return Meta::sanitize_content_scope( get_post_meta( $post_id, Schema::META_SERIES_SCOPE, true ) );
	}

	/**
	 * Return one canonical structural interpretation without writing metadata.
	 * Blank scope on a seasoned non-main role remains explicitly ambiguous, but
	 * is rendered in its old Season as a compatibility fallback until reviewed.
	 *
	 * @return array<string,mixed>
	 */
	public static function context( int $post_id, ?\WP_Term $term = null, array $meta_overrides = array() ): array {
		$term = $term instanceof \WP_Term ? $term : Series::get_primary_term( $post_id );
		$raw_role     = sanitize_key( (string) ( array_key_exists( Schema::META_SERIES_ROLE, $meta_overrides ) ? $meta_overrides[ Schema::META_SERIES_ROLE ] : get_post_meta( $post_id, Schema::META_SERIES_ROLE, true ) ) );
		$raw_scope    = sanitize_key( (string) ( array_key_exists( Schema::META_SERIES_SCOPE, $meta_overrides ) ? $meta_overrides[ Schema::META_SERIES_SCOPE ] : get_post_meta( $post_id, Schema::META_SERIES_SCOPE, true ) ) );
		$stored_role  = Meta::sanitize_role( $raw_role );
		$stored_scope = Meta::sanitize_content_scope( $raw_scope );
		$role         = '' === $stored_role ? Schema::ROLE_ARTICLE : $stored_role;
		$season_key   = sanitize_key( (string) ( array_key_exists( Schema::META_SEASON_KEY, $meta_overrides ) ? $meta_overrides[ Schema::META_SEASON_KEY ] : get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) ) );
		$season_valid = $term instanceof \WP_Term && is_array( Series::season( $term, $season_key ) );
		$seasoned     = $term instanceof \WP_Term && Series::is_seasoned( $term );
		$main         = Schema::ROLE_ARTICLE === $role;
		$scope        = Schema::SCOPE_SERIES;
		$ambiguous    = false;
		$valid        = $term instanceof \WP_Term;
		$issue        = '';

		if ( $seasoned ) {
			if ( $main ) {
				$scope = Schema::SCOPE_SEASON;
				$valid = $season_valid && Schema::SCOPE_SERIES !== $stored_scope;
				$issue = $valid ? '' : ( Schema::SCOPE_SERIES === $stored_scope ? 'main_article_series_scope' : 'season_missing_or_invalid' );
			} elseif ( Schema::SCOPE_SERIES === $stored_scope ) {
				$scope = Schema::SCOPE_SERIES;
			} elseif ( Schema::SCOPE_SEASON === $stored_scope ) {
				$scope = Schema::SCOPE_SEASON;
				$valid = $season_valid;
				$issue = $valid ? '' : 'season_missing_or_invalid';
			} else {
				// Compatibility display only: this does not silently persist a scope.
				$scope     = $season_valid ? Schema::SCOPE_SEASON : '';
				$ambiguous = true;
				$valid     = $season_valid;
				$issue     = $season_valid ? 'scope_unresolved' : 'scope_and_season_unresolved';
			}
		} elseif ( Schema::SCOPE_SEASON === $stored_scope ) {
			$valid = false;
			$issue = 'season_scope_on_flat_series';
		}
		if ( '' !== $raw_role && '' === $stored_role ) {
			$ambiguous = false;
			$valid     = false;
			$issue     = 'role_invalid';
		} elseif ( '' !== $raw_scope && '' === $stored_scope ) {
			$ambiguous = false;
			$valid     = false;
			$issue     = 'scope_invalid';
		}

		$track = $valid && '' !== $scope
			? self::track_key( $term, $scope, $season_key, $role )
			: '';

		return array(
			'post_id'       => $post_id,
			'term'          => $term,
			'role'          => $role,
			'stored_role'   => $stored_role,
			'raw_role'      => $raw_role,
			'scope'         => $scope,
			'stored_scope'  => $stored_scope,
			'raw_scope'     => $raw_scope,
			'season_key'    => Schema::SCOPE_SEASON === $scope ? $season_key : '',
			'ambiguous'     => $ambiguous,
			'valid'         => $valid,
			'issue'         => $issue,
			'track'         => $track,
			'main'          => $main,
		);
	}

	/** @param \WP_Term|null $term */
	public static function track_key( $term, string $scope, string $season_key, string $role ): string {
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$scope = Meta::sanitize_content_scope( $scope );
		$role  = Meta::sanitize_role( $role );
		$role  = '' === $role ? Schema::ROLE_ARTICLE : $role;
		if ( Schema::SCOPE_SEASON === $scope ) {
			$season_key = sanitize_key( $season_key );
			if ( ! Series::is_seasoned( $term ) || ! Series::season( $term, $season_key ) ) {
				return '';
			}
			$base = 'season_' . $season_key;
			return Schema::ROLE_ARTICLE === $role ? $base : $base . '_' . $role;
		}
		if ( Schema::SCOPE_SERIES !== $scope ) {
			return '';
		}
		if ( Series::is_seasoned( $term ) && Schema::ROLE_ARTICLE === $role ) {
			return '';
		}
		return Schema::ROLE_ARTICLE === $role ? 'series' : 'series_' . $role;
	}

	/** @return array<int,array<string,mixed>> */
	public static function tracks( \WP_Term $term ): array {
		$tracks = array();
		$add = static function ( array &$items, \WP_Term $series, string $scope, string $season_key, string $role, string $label, bool $movable ): void {
			$key = self::track_key( $series, $scope, $season_key, $role );
			if ( '' !== $key ) {
				$items[] = array(
					'key'        => $key,
					'scope'      => $scope,
					'season_key' => $season_key,
					'role'       => $role,
					'label'      => $label,
					'movable'    => $movable,
					'numbered'   => Schema::ROLE_ARTICLE === $role && Series::is_ordered( $series ),
				);
			}
		};

		$add( $tracks, $term, Schema::SCOPE_SERIES, '', Schema::ROLE_INTRO, __( 'Series introductions', 'wp-title-layer' ), true );
		if ( Series::is_seasoned( $term ) ) {
			foreach ( Series::seasons( $term ) as $season ) {
				$prefix = (string) $season['label'] . ' — ';
				$add( $tracks, $term, Schema::SCOPE_SEASON, (string) $season['key'], Schema::ROLE_INTRO, $prefix . __( 'Introductions', 'wp-title-layer' ), true );
				$add( $tracks, $term, Schema::SCOPE_SEASON, (string) $season['key'], Schema::ROLE_ARTICLE, $prefix . __( 'Main articles', 'wp-title-layer' ), Series::is_ordered( $term ) && Sequence::is_managed( $term ) );
				$add( $tracks, $term, Schema::SCOPE_SEASON, (string) $season['key'], Schema::ROLE_EPILOGUE, $prefix . __( 'Epilogues', 'wp-title-layer' ), true );
				$add( $tracks, $term, Schema::SCOPE_SEASON, (string) $season['key'], Schema::ROLE_APPENDIX, $prefix . __( 'Appendices', 'wp-title-layer' ), true );
			}
		} else {
			$add( $tracks, $term, Schema::SCOPE_SERIES, '', Schema::ROLE_ARTICLE, __( 'Main articles', 'wp-title-layer' ), Series::is_ordered( $term ) && Sequence::is_managed( $term ) );
		}
		$add( $tracks, $term, Schema::SCOPE_SERIES, '', Schema::ROLE_EPILOGUE, __( 'Series epilogues', 'wp-title-layer' ), true );
		$add( $tracks, $term, Schema::SCOPE_SERIES, '', Schema::ROLE_APPENDIX, __( 'Series appendices', 'wp-title-layer' ), true );
		return $tracks;
	}

	/** @return array<string,mixed>|null */
	public static function track( \WP_Term $term, string $track_key ): ?array {
		foreach ( self::tracks( $term ) as $track ) {
			if ( $track_key === $track['key'] ) {
				return $track;
			}
		}
		return null;
	}

	public static function default_track_key( \WP_Term $term ): string {
		foreach ( self::tracks( $term ) as $track ) {
			if ( Schema::ROLE_ARTICLE === $track['role'] ) {
				return (string) $track['key'];
			}
		}
		$tracks = self::tracks( $term );
		return isset( $tracks[0]['key'] ) ? (string) $tracks[0]['key'] : '';
	}

	public static function matches_track( int $post_id, \WP_Term $term, array $track ): bool {
		$context = self::context( $post_id, $term );
		return ! empty( $context['valid'] )
			&& empty( $context['ambiguous'] )
			&& (string) $context['track'] === (string) ( $track['key'] ?? '' );
	}

	/** @return array<string,mixed> */
	public static function preview( int $term_id ): array {
		$term = get_term( $term_id, Series::claimed_taxonomy() );
		if ( ! $term instanceof \WP_Term ) {
			return array( 'valid' => false, 'term' => null, 'members' => 0, 'ambiguous' => array(), 'invalid' => array(), 'tracks' => array() );
		}
		$post_ids = self::member_ids( $term );
		sort( $post_ids, SORT_NUMERIC );
		$ambiguous = array();
		$invalid   = array();
		$member_signatures = array();
		$counts    = array_fill_keys( wp_list_pluck( self::tracks( $term ), 'key' ), 0 );
		foreach ( $post_ids as $post_id ) {
			$context = self::context( $post_id, $term );
			$member_signatures[] = array(
				'id'              => $post_id,
				'role'            => (string) $context['raw_role'],
				'scope'           => (string) $context['raw_scope'],
				'season'          => sanitize_key( (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) ),
				'track'           => (string) $context['track'],
				'valid'           => ! empty( $context['valid'] ),
				'ambiguous'       => ! empty( $context['ambiguous'] ),
				'issue'           => (string) $context['issue'],
				'rank_exists'     => metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_RANK ),
				'rank'            => Sequence::rank( $post_id ),
				'position_exists' => metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_POSITION ),
				'position'        => (string) get_post_meta( $post_id, Schema::META_SEQUENCE_POSITION, true ),
				'date_gmt'        => (string) get_post_field( 'post_date_gmt', $post_id ),
			);
			$row = array(
				'id'         => $post_id,
				'title'      => (string) get_post_field( 'post_title', $post_id, 'display' ),
				'edit_url'   => (string) get_edit_post_link( $post_id, 'raw' ),
				'role'       => (string) $context['role'],
				'season_key' => (string) $context['season_key'],
				'issue'      => (string) $context['issue'],
			);
			if ( ! empty( $context['ambiguous'] ) ) {
				$ambiguous[] = $row;
			} elseif ( empty( $context['valid'] ) || '' === (string) $context['track'] ) {
				$invalid[] = $row;
			} elseif ( isset( $counts[ (string) $context['track'] ] ) ) {
				++$counts[ (string) $context['track'] ];
			}
		}
		return array(
			'valid'     => true,
			'term'      => $term,
			'members'   => count( $post_ids ),
			'ambiguous' => $ambiguous,
			'invalid'   => $invalid,
			'tracks'    => $counts,
			'fingerprint' => hash(
				'sha256',
				wp_json_encode(
					array(
						'term_id'   => $term_id,
						'mode'      => Series::mode( $term ),
						'structure' => Series::structure( $term ),
						'seasons'   => Series::seasons( $term ),
						'members'   => $member_signatures,
						'counts'    => $counts,
					)
				)
			),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function enable( int $term_id, string $expected_fingerprint = '' ) {
		$preview = self::preview( $term_id );
		$term = $preview['term'] ?? null;
		if ( empty( $preview['valid'] ) || ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wptl_book_invalid_series', __( 'The selected Series no longer exists.', 'wp-title-layer' ) );
		}
		if ( self::is_enabled( $term ) ) {
			return new \WP_Error( 'wptl_book_already_enabled', __( 'Book structure is already enabled for this Series.', 'wp-title-layer' ) );
		}
		if ( Series::is_ordered( $term ) && ! Sequence::is_managed( $term ) ) {
			return new \WP_Error( 'wptl_book_sequence_required', __( 'Initialize this ordered Series in Sequence Manager before enabling book structure.', 'wp-title-layer' ) );
		}
		if ( '' !== $expected_fingerprint && ! hash_equals( (string) $preview['fingerprint'], $expected_fingerprint ) ) {
			return new \WP_Error( 'wptl_book_preview_changed', __( 'The Series changed after the structure preview loaded. Reload and review it again.', 'wp-title-layer' ) );
		}
		if ( ! empty( $preview['ambiguous'] ) || ! empty( $preview['invalid'] ) ) {
			return new \WP_Error( 'wptl_book_unresolved', __( 'Resolve every scope and Season issue before enabling book structure.', 'wp-title-layer' ) );
		}
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy instanceof \WP_Taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return new \WP_Error( 'wptl_book_forbidden', __( 'You are not allowed to enable book structure for this Series.', 'wp-title-layer' ) );
		}

		$changes = array();
		foreach ( self::tracks( $term ) as $track ) {
			if ( Schema::ROLE_ARTICLE === $track['role'] ) {
				continue;
			}
			$ids = self::track_post_ids( $term, $track, false, true );
			foreach ( $ids as $index => $post_id ) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new \WP_Error( 'wptl_book_member_forbidden', __( 'You cannot edit every article affected by this structure activation.', 'wp-title-layer' ) );
				}
				$new_rank = ( $index + 1 ) * Sequence::RANK_STEP;
				$changes[] = array(
					'post_id'    => $post_id,
					'old_exists' => metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_RANK ),
					'old_rank'   => Sequence::rank( $post_id ),
					'new_rank'   => $new_rank,
				);
			}
		}
		$journal = array(
			'journal_version' => 1,
			'status'          => 'writing',
			'term_id'         => $term_id,
			'created_at'      => time(),
			'user_id'         => get_current_user_id(),
			'changes'         => $changes,
		);
		update_option( self::journal_name( $term_id ), $journal, false );
		$written = array();
		foreach ( $changes as $change ) {
			update_post_meta( (int) $change['post_id'], Schema::META_SEQUENCE_RANK, (int) $change['new_rank'] );
			if ( (int) $change['new_rank'] !== Sequence::rank( (int) $change['post_id'] ) ) {
				self::restore_changes( $written );
				$journal['status'] = 'failed';
				update_option( self::journal_name( $term_id ), $journal, false );
				return new \WP_Error( 'wptl_book_rank_write_failed', __( 'A structure rank could not be verified, so completed writes were restored.', 'wp-title-layer' ) );
			}
			$written[] = $change;
		}
		update_term_meta( $term_id, Schema::TERM_META_BOOK_STRUCTURE_VERSION, self::VERSION );
		if ( ! self::is_enabled( $term ) ) {
			self::restore_changes( $written );
			$journal['status'] = 'failed';
			update_option( self::journal_name( $term_id ), $journal, false );
			return new \WP_Error( 'wptl_book_activation_failed', __( 'Book structure could not be activated, so rank writes were restored.', 'wp-title-layer' ) );
		}
		$journal['status']       = 'complete';
		$journal['completed_at'] = time();
		update_option( self::journal_name( $term_id ), $journal, false );
		Sequence::invalidate_runtime_cache();
		return $journal;
	}

	/** @return int[] */
	public static function track_post_ids( \WP_Term $term, array $track, bool $public_only = false, bool $fallback_order = false ): array {
		$ids = self::member_ids( $term, $public_only );
		$ids = array_values( array_filter( $ids, static function ( int $post_id ) use ( $term, $track ): bool {
			return self::matches_track( $post_id, $term, $track );
		} ) );
		usort( $ids, static function ( int $left, int $right ) use ( $fallback_order ): int {
			$left_rank  = Sequence::rank( $left );
			$right_rank = Sequence::rank( $right );
			if ( 0 < $left_rank && 0 < $right_rank && $left_rank !== $right_rank ) {
				return $left_rank <=> $right_rank;
			}
			// A damaged or interrupted rank belongs at the end of an active track;
			// legacy date/position fallback is meaningful only when both are absent.
			if ( ( 0 < $left_rank ) !== ( 0 < $right_rank ) ) {
				return 0 < $left_rank ? -1 : 1;
			}
			if ( $fallback_order ) {
				$left_position  = metadata_exists( 'post', $left, Schema::META_SEQUENCE_POSITION ) ? (int) get_post_meta( $left, Schema::META_SEQUENCE_POSITION, true ) : PHP_INT_MAX;
				$right_position = metadata_exists( 'post', $right, Schema::META_SEQUENCE_POSITION ) ? (int) get_post_meta( $right, Schema::META_SEQUENCE_POSITION, true ) : PHP_INT_MAX;
				if ( $left_position !== $right_position ) {
					return $left_position <=> $right_position;
				}
				$date_compare = strcmp( (string) get_post_field( 'post_date_gmt', $left ), (string) get_post_field( 'post_date_gmt', $right ) );
				if ( 0 !== $date_compare ) {
					return $date_compare;
				}
			}
			return $left <=> $right;
		} );
		return $ids;
	}

	/** @return int[] */
	public static function ordered_post_ids( \WP_Term $term, string $season_key = '', bool $public_only = false ): array {
		$season_key = sanitize_key( $season_key );
		$ids = self::member_ids( $term, $public_only );
		$track_order = array();
		foreach ( self::tracks( $term ) as $index => $track ) {
			$track_order[ (string) $track['key'] ] = $index;
		}
		$keys = array();
		foreach ( $ids as $post_id ) {
			$context = self::context( $post_id, $term );
			if ( empty( $context['valid'] ) || ! empty( $context['ambiguous'] ) ) {
				continue;
			}
			if ( '' !== $season_key && ( Schema::SCOPE_SEASON !== $context['scope'] || $season_key !== $context['season_key'] ) ) {
				continue;
			}
			$track = (string) $context['track'];
			if ( ! isset( $track_order[ $track ] ) ) {
				continue;
			}
			$keys[ $post_id ] = array( $track_order[ $track ], Sequence::rank( $post_id ) ?: PHP_INT_MAX, $post_id );
		}
		$ids = array_values( array_intersect( $ids, array_keys( $keys ) ) );
		usort( $ids, static function ( int $left, int $right ) use ( $keys ): int {
			foreach ( array( 0, 1, 2 ) as $part ) {
				$comparison = (int) $keys[ $left ][ $part ] <=> (int) $keys[ $right ][ $part ];
				if ( 0 !== $comparison ) {
					return $comparison;
				}
			}
			return 0;
		} );
		return $ids;
	}

	/** @return array<string,mixed>|null */
	public static function journal( int $term_id ): ?array {
		$value = get_option( self::journal_name( $term_id ), null );
		return is_array( $value ) ? $value : null;
	}

	private static function journal_name( int $term_id ): string {
		return self::JOURNAL_PREFIX . max( 0, $term_id );
	}

	/** @param array<int,array<string,mixed>> $changes */
	private static function restore_changes( array $changes ): void {
		foreach ( array_reverse( $changes ) as $change ) {
			if ( ! empty( $change['old_exists'] ) ) {
				update_post_meta( (int) $change['post_id'], Schema::META_SEQUENCE_RANK, (int) $change['old_rank'] );
			} else {
				delete_post_meta( (int) $change['post_id'], Schema::META_SEQUENCE_RANK );
			}
		}
	}

	/** @return int[] */
	private static function member_ids( \WP_Term $term, bool $public_only = false ): array {
		$taxonomy = get_taxonomy( $term->taxonomy );
		$post_types = $taxonomy instanceof \WP_Taxonomy ? array_values( (array) $taxonomy->object_type ) : array( 'post' );
		$args = array(
			'post_type'           => $post_types,
			'post_status'         => $public_only ? array( 'publish' ) : array_values( get_post_stati( array( 'internal' => false ), 'names' ) ),
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'orderby'             => 'none',
			'tax_query'           => array( array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( (int) $term->term_id ) ) ),
		);
		if ( $public_only ) {
			$args['has_password'] = false;
		}
		$query = new \WP_Query( $args );
		$ids = array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
		if ( $public_only ) {
			$ids = array_values( array_filter( $ids, static function ( int $post_id ): bool {
				$post = get_post( $post_id );
				return $post instanceof \WP_Post && 'publish' === $post->post_status && '' === (string) $post->post_password && is_post_publicly_viewable( $post );
			} ) );
		}
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		return $ids;
	}
}
