<?php
/**
 * Canonical ordered-Series sequence service.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Owns rank comparison, automatic ordinals, initialization, moves, revisions,
 * and value-checked recovery. No presentation layer should implement its own
 * ordered-Series comparison after a Series adopts schema version 1.
 */
final class Sequence {
	public const RANK_STEP = 1048576;
	public const BATCH_SIZE = 100;
	public const MISSING_SOURCE = '__wptl_missing__';

	private const INIT_OPTION_PREFIX = 'wptl_seq_init_';
	private const MOVE_OPTION_PREFIX = 'wptl_seq_move_';

	/** @var int Request-local cache generation. */
	private static $cache_epoch = 0;

	public static function invalidate_runtime_cache(): void {
		++self::$cache_epoch;
	}

	/** @param int|\WP_Term $term */
	public static function is_managed( $term ): bool {
		$term = self::term( $term );
		return $term instanceof \WP_Term
			&& Schema::SEQUENCE_SCHEMA_VERSION === (int) get_term_meta( $term->term_id, Schema::TERM_META_SEQUENCE_SCHEMA_VERSION, true );
	}

	/** @param int|\WP_Term $term */
	public static function scope_key( $term, string $season_key = '' ): string {
		$term = self::term( $term );
		if ( ! $term instanceof \WP_Term || ! Series::is_seasoned( $term ) ) {
			return 'series';
		}

		$season_key = sanitize_key( $season_key );
		return '' !== $season_key ? 'season_' . $season_key : '';
	}

	/** @param int|\WP_Term $term */
	public static function revision( $term, string $season_key = '' ): int {
		$term = self::term( $term );
		$scope = self::scope_key( $term, $season_key );
		if ( ! $term instanceof \WP_Term || '' === $scope ) {
			return 0;
		}

		return self::revision_for_key( $term, $scope );
	}

	/** @param int|\WP_Term $term */
	public static function track_revision( $term, string $track_key ): int {
		$term = self::term( $term );
		$track_key = sanitize_key( $track_key );
		return $term instanceof \WP_Term && BookStructure::track( $term, $track_key )
			? self::revision_for_key( $term, $track_key )
			: 0;
	}

	/** Invalidate stale manager pages and undo journals for one changed scope. */
	public static function touch_revision( int $term_id, string $season_key = '', string $track_key = '' ): int {
		$term = self::term( $term_id );
		if ( ! $term instanceof \WP_Term || ( ! self::is_managed( $term ) && ! BookStructure::is_enabled( $term ) ) ) {
			return 0;
		}

		if ( '' !== $track_key && BookStructure::track( $term, $track_key ) ) {
			return self::set_revision_for_key( $term, $track_key, self::track_revision( $term, $track_key ) + 1 );
		}
		return self::set_revision( $term, $season_key, self::revision( $term, $season_key ) + 1 );
	}

	/**
	 * Return the one comparison record used by archives, navigation, health,
	 * presentation, and the manager.
	 *
	 * @return array{managed:bool,season_rank:int,rank:int,tie_breaker:int}
	 */
	public static function sort_key( int $post_id, int $term_id = 0 ): array {
		$term = 0 < $term_id ? self::term( $term_id ) : Series::get_primary_term( $post_id );
		if ( ! $term instanceof \WP_Term ) {
			return array(
				'managed'     => false,
				'season_rank' => PHP_INT_MAX,
				'rank'        => PHP_INT_MAX,
				'tie_breaker' => $post_id,
			);
		}

		$managed     = self::is_managed( $term );
		$season_rank = Series::is_seasoned( $term )
			? Series::season_rank( (int) $term->term_id, (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) )
			: 0;
		if ( $managed ) {
			$rank = self::rank( $post_id );
		} else {
			$rank = metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_POSITION )
				? max( 0, (int) get_post_meta( $post_id, Schema::META_SEQUENCE_POSITION, true ) )
				: PHP_INT_MAX;
		}

		return array(
			'managed'     => $managed,
			'season_rank' => $season_rank,
			'rank'        => 0 < $rank || ! $managed ? $rank : PHP_INT_MAX,
			'tie_breaker' => $post_id,
		);
	}

	public static function rank( int $post_id ): int {
		return metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_RANK )
			? max( 0, (int) get_post_meta( $post_id, Schema::META_SEQUENCE_RANK, true ) )
			: 0;
	}

	/**
	 * Activate canonical ranks for a genuinely empty new ordered Series.
	 * Existing members must always use the previewed, journaled initializer.
	 *
	 * @return true|\WP_Error
	 */
	public static function activate_empty( \WP_Term $term ) {
		if ( ! Series::is_ordered( $term ) ) {
			return true;
		}
		if ( self::is_managed( $term ) ) {
			return true;
		}
		if ( self::member_ids( $term ) ) {
			return new \WP_Error( 'wptl_sequence_not_empty', __( 'Only an empty new Series can skip the sequence migration preview.', 'wp-title-layer' ) );
		}
		if ( ! self::persist_seasons( $term, Series::seasons( $term ) ) ) {
			return new \WP_Error( 'wptl_sequence_season_identity_failed', __( 'Stable Season identities could not be prepared for the new Series.', 'wp-title-layer' ) );
		}

		$revisions = array();
		foreach ( BookStructure::tracks( $term ) as $track ) {
			$revisions[ (string) $track['key'] ] = 1;
		}
		update_term_meta( (int) $term->term_id, Schema::TERM_META_SEQUENCE_REVISIONS, Meta::sanitize_sequence_revisions( $revisions ) );
		update_term_meta( (int) $term->term_id, Schema::TERM_META_SEQUENCE_SCHEMA_VERSION, Schema::SEQUENCE_SCHEMA_VERSION );
		if ( ! self::is_managed( $term ) ) {
			delete_term_meta( (int) $term->term_id, Schema::TERM_META_SEQUENCE_REVISIONS );
			return new \WP_Error( 'wptl_sequence_activation_failed', __( 'Canonical sequence order could not be activated for the new Series.', 'wp-title-layer' ) );
		}
		self::invalidate_runtime_cache();
		return true;
	}

	/**
	 * Return the canonical editorial or public sequence. A blank season means
	 * all seasons in their defined order; a concrete season is one rank scope.
	 *
	 * @return int[]
	 */
	public static function ordered_post_ids( \WP_Term $term, string $season_key = '', bool $public_only = false ): array {
		if ( ! Series::is_ordered( $term ) || $term->taxonomy !== Series::claimed_taxonomy() ) {
			return array();
		}

		$season_key = sanitize_key( $season_key );
		if ( Series::is_seasoned( $term ) && '' !== $season_key && ! Series::season( $term, $season_key ) ) {
			return array();
		}
		if ( BookStructure::is_enabled( $term ) ) {
			return BookStructure::ordered_post_ids( $term, $season_key, $public_only );
		}

		$args = array(
			'post_type'           => self::post_types( $term ),
			'post_status'         => $public_only ? array( 'publish' ) : self::editorial_statuses(),
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'orderby'             => 'none',
			'tax_query'           => array(
				array(
					'taxonomy' => $term->taxonomy,
					'field'    => 'term_id',
					'terms'    => array( (int) $term->term_id ),
				),
			),
		);
		if ( $public_only ) {
			$args['has_password'] = false;
		}
		if ( Series::is_seasoned( $term ) && '' !== $season_key ) {
			$args['meta_query'] = array(
				array(
					'key'     => Schema::META_SEASON_KEY,
					'value'   => $season_key,
					'compare' => '=',
				),
			);
		}

		$query    = new \WP_Query( $args );
		$post_ids = array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
		if ( $public_only ) {
			$post_ids = array_values(
				array_filter(
					$post_ids,
					static function ( int $post_id ): bool {
						$post = get_post( $post_id );
						return $post instanceof \WP_Post
							&& 'publish' === $post->post_status
							&& '' === (string) $post->post_password
							&& is_post_publicly_viewable( $post );
					}
				)
			);
		}

		if ( $post_ids ) {
			update_meta_cache( 'post', $post_ids );
		}
		$keys = array();
		foreach ( $post_ids as $post_id ) {
			$keys[ $post_id ] = self::sort_key( $post_id, (int) $term->term_id );
		}
		usort(
			$post_ids,
			static function ( int $left, int $right ) use ( $keys ): int {
				foreach ( array( 'season_rank', 'rank', 'tie_breaker' ) as $part ) {
					$comparison = (int) $keys[ $left ][ $part ] <=> (int) $keys[ $right ][ $part ];
					if ( 0 !== $comparison ) {
						return $comparison;
					}
				}
				return 0;
			}
		);

		return $post_ids;
	}

	/** Return the automatic main-article ordinal inside the canonical rank scope. */
	public static function automatic_ordinal( int $post_id, ?\WP_Term $term = null ): int {
		$term = $term instanceof \WP_Term ? $term : Series::get_primary_term( $post_id );
		if ( ! $term instanceof \WP_Term || ! Series::is_ordered( $term ) || ! self::is_managed( $term ) || ! self::is_main_article( $post_id ) ) {
			return 0;
		}

		$season_key = Series::is_seasoned( $term ) ? (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) : '';
		$scope_key  = self::scope_key( $term, $season_key );
		$cache_key  = self::$cache_epoch . ':' . (int) $term->term_id . ':' . $scope_key . ':' . self::revision( $term, $season_key );
		static $ordinal_maps = array();
		if ( ! isset( $ordinal_maps[ $cache_key ] ) ) {
			$ordinal_maps[ $cache_key ] = array();
			$ordinal = 0;
			foreach ( self::ordered_post_ids( $term, $season_key, false ) as $candidate_id ) {
				if ( self::is_main_article( $candidate_id ) ) {
					++$ordinal;
					$ordinal_maps[ $cache_key ][ $candidate_id ] = $ordinal;
				}
			}
		}

		return (int) ( $ordinal_maps[ $cache_key ][ $post_id ] ?? 0 );
	}

	public static function is_main_article( int $post_id ): bool {
		$role = sanitize_key( (string) get_post_meta( $post_id, Schema::META_SERIES_ROLE, true ) );
		return '' === $role || Schema::ROLE_ARTICLE === $role;
	}

	/**
	 * Inspect legacy positions without writing. Duplicate, absent, malformed,
	 * and invalid-season members remain explicitly unresolved.
	 *
	 * @return array<string,mixed>
	 */
	public static function initialization_preview( int $term_id ): array {
		$term = self::term( $term_id );
		if ( ! $term instanceof \WP_Term || ! Series::is_ordered( $term ) ) {
			return array( 'valid' => false, 'term' => null, 'scopes' => array(), 'invalid_season' => array(), 'members' => 0, 'unresolved' => 0 );
		}

		$post_ids = self::member_ids( $term );
		$records  = array();
		$invalid  = array();
		$scopes   = array();
		$seasoned = Series::is_seasoned( $term );
		$defined  = array();
		if ( $seasoned ) {
			foreach ( Series::seasons( $term ) as $season ) {
				$defined[ (string) $season['key'] ] = $season;
				$scopes[ (string) $season['key'] ] = array( 'season' => $season, 'records' => array() );
			}
		} else {
			$scopes[''] = array( 'season' => null, 'records' => array() );
		}

		foreach ( $post_ids as $post_id ) {
			$season_key = $seasoned ? sanitize_key( (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) ) : '';
			$record     = self::preview_record( $post_id, $season_key );
			$records[]  = $record;
			if ( $seasoned && ( '' === $season_key || ! isset( $defined[ $season_key ] ) ) ) {
				$invalid[] = $record;
				continue;
			}
			$scopes[ $season_key ]['records'][] = $record;
		}

		$unresolved_total = count( $invalid );
		foreach ( $scopes as $scope_key => $scope ) {
			$position_counts = array();
			foreach ( $scope['records'] as $record ) {
				if ( ! empty( $record['position_valid'] ) ) {
					$position = (int) $record['position'];
					$position_counts[ $position ] = (int) ( $position_counts[ $position ] ?? 0 ) + 1;
				}
			}

			$known      = array();
			$unresolved = array();
			foreach ( $scope['records'] as $record ) {
				$duplicate = ! empty( $record['position_valid'] ) && 1 < (int) ( $position_counts[ (int) $record['position'] ] ?? 0 );
				$record['duplicate'] = $duplicate;
				if ( ! empty( $record['position_valid'] ) && ! $duplicate ) {
					$known[] = $record;
				} else {
					$unresolved[] = $record;
				}
			}
			usort( $known, array( self::class, 'compare_preview_position' ) );
			usort( $unresolved, array( self::class, 'compare_preview_date' ) );
			$scopes[ $scope_key ] = array(
				'season'     => $scope['season'],
				'known'      => $known,
				'unresolved' => $unresolved,
				'members'    => count( $scope['records'] ),
			);
			$unresolved_total += count( $unresolved );
		}

		$seasons_before = Series::seasons( $term );
		return array(
			'valid'           => true,
			'term'            => $term,
			'managed'         => self::is_managed( $term ),
			'members'         => count( $records ),
			'unresolved'      => $unresolved_total,
			'invalid_season'  => $invalid,
			'scopes'          => $scopes,
			'seasons_before'  => $seasons_before,
			'seasons_after'   => self::with_public_ids( $term, $seasons_before ),
			'fingerprint'     => self::preview_fingerprint( $records, $term, $seasons_before ),
		);
	}

	/** Begin a resumable initialization without changing active front-end order. */
	public static function begin_initialization( int $term_id, bool $append_unresolved = false, string $expected_fingerprint = '' ) {
		$preview = self::initialization_preview( $term_id );
		$term    = $preview['term'] ?? null;
		if ( empty( $preview['valid'] ) || ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wptl_sequence_invalid_series', __( 'Choose an ordered Series before starting Sequence Manager.', 'wp-title-layer' ) );
		}
		if ( self::is_managed( $term ) ) {
			return new \WP_Error( 'wptl_sequence_already_managed', __( 'This Series is already managed by Sequence Manager.', 'wp-title-layer' ) );
		}
		if ( '' !== $expected_fingerprint && ! hash_equals( (string) $preview['fingerprint'], $expected_fingerprint ) ) {
			return new \WP_Error( 'wptl_sequence_preview_changed', __( 'The Series changed after this preview was loaded. Reload and review the initialization preview again.', 'wp-title-layer' ) );
		}
		$permission = self::authorize_manager( $term, self::member_ids( $term ) );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		if ( ! empty( $preview['invalid_season'] ) ) {
			return new \WP_Error( 'wptl_sequence_invalid_season_members', __( 'Assign every article to a defined season before initializing this Series.', 'wp-title-layer' ) );
		}
		if ( 0 < (int) $preview['unresolved'] && ! $append_unresolved ) {
			return new \WP_Error( 'wptl_sequence_unresolved_members', __( 'Resolve the legacy position conflicts, or explicitly approve appending unresolved articles by date.', 'wp-title-layer' ) );
		}

		$entries   = array();
		$revisions = Meta::sanitize_sequence_revisions( get_term_meta( $term->term_id, Schema::TERM_META_SEQUENCE_REVISIONS, true ) );
		foreach ( (array) $preview['scopes'] as $season_key => $scope ) {
			$order = array_merge( (array) $scope['known'], $append_unresolved ? (array) $scope['unresolved'] : array() );
			foreach ( array_values( $order ) as $index => $record ) {
				$post_id = (int) $record['id'];
				$entries[] = array(
					'post_id'             => $post_id,
					'scope'               => self::scope_key( $term, (string) $season_key ),
					'season_key'          => (string) $season_key,
					'source_season'       => (string) $record['season_key'],
					'source_status'       => (string) $record['status'],
					'source_date'         => (string) $record['date'],
					'source_position'     => (string) $record['source_position'],
					'old_rank_exists'     => metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_RANK ),
					'old_rank'            => self::rank( $post_id ),
					'old_baseline_exists' => metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_SOURCE_POSITION ),
					'old_baseline'        => (string) get_post_meta( $post_id, Schema::META_SEQUENCE_SOURCE_POSITION, true ),
					'new_rank'            => ( $index + 1 ) * self::RANK_STEP,
				);
			}
		}

		$journal = array(
			'journal_version'  => 1,
			'kind'             => 'initialize',
			'status'           => 'writing',
			'term_id'          => (int) $term->term_id,
			'created_at'       => time(),
			'user_id'          => get_current_user_id(),
			'append_unresolved' => $append_unresolved,
			'fingerprint'      => (string) $preview['fingerprint'],
			'cursor'           => 0,
			'commit_phase'     => 'ranks',
			'entries'          => $entries,
			'seasons_before'   => (array) $preview['seasons_before'],
			'seasons_after'    => (array) $preview['seasons_after'],
			'revisions_before' => $revisions,
			'revisions_after'  => array(),
			'error'            => '',
		);
		self::write_option( self::init_option_name( (int) $term->term_id ), $journal );
		return $journal;
	}

	/** Continue at most one bounded initialization batch. */
	public static function continue_initialization( int $term_id, int $batch_size = self::BATCH_SIZE ) {
		$term    = self::term( $term_id );
		$journal = self::initialization_journal( $term_id );
		if ( ! $term instanceof \WP_Term || ! is_array( $journal ) || 'writing' !== ( $journal['status'] ?? '' ) ) {
			return new \WP_Error( 'wptl_sequence_no_initialization', __( 'No resumable Sequence Manager initialization was found.', 'wp-title-layer' ) );
		}
		$permission = self::authorize_manager( $term, wp_list_pluck( (array) $journal['entries'], 'post_id' ) );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$entries        = array_values( (array) $journal['entries'] );
		$phase          = sanitize_key( (string) ( $journal['commit_phase'] ?? 'ranks' ) );
		$allowed_phases = array( 'ranks', 'seasons', 'revisions', 'schema' );
		if ( ! in_array( $phase, $allowed_phases, true ) ) {
			$phase = 'ranks';
		}
		$seasons_before  = (array) $journal['seasons_before'];
		$seasons_after   = (array) $journal['seasons_after'];
		$current_seasons = Series::seasons( $term );

		if ( self::is_managed( $term ) && 'schema' !== $phase ) {
			return self::initialization_conflict( $journal, __( 'The Series activation marker changed outside the initialization journal.', 'wp-title-layer' ) );
		}
		if ( 'ranks' === $phase && $current_seasons !== $seasons_before ) {
			return self::initialization_conflict( $journal, __( 'The Series definition changed after the initialization preview.', 'wp-title-layer' ) );
		}
		if ( in_array( $phase, array( 'seasons', 'revisions', 'schema' ), true ) && $current_seasons !== $seasons_before && $current_seasons !== $seasons_after ) {
			return self::initialization_conflict( $journal, __( 'The Series definition changed during the final initialization commit.', 'wp-title-layer' ) );
		}

		if ( 'ranks' === $phase ) {
			$cursor = max( 0, (int) $journal['cursor'] );
			$end    = min( count( $entries ), $cursor + min( 500, max( 1, $batch_size ) ) );
			for ( $index = $cursor; $index < $end; ++$index ) {
				$entry = $entries[ $index ];
				if ( ! self::initialization_source_matches( $term, $entry ) ) {
					return self::initialization_conflict( $journal, __( 'An article’s Series, season, status, or legacy position changed during initialization.', 'wp-title-layer' ) );
				}

				$post_id = (int) $entry['post_id'];
				update_post_meta( $post_id, Schema::META_SEQUENCE_RANK, (int) $entry['new_rank'] );
				update_post_meta( $post_id, Schema::META_SEQUENCE_SOURCE_POSITION, (string) $entry['source_position'] );
				if ( (int) $entry['new_rank'] !== self::rank( $post_id ) || (string) $entry['source_position'] !== (string) get_post_meta( $post_id, Schema::META_SEQUENCE_SOURCE_POSITION, true ) ) {
					return self::initialization_conflict( $journal, __( 'WordPress could not verify a sequence rank written during initialization.', 'wp-title-layer' ) );
				}
				$journal['cursor'] = $index + 1;
				self::write_option( self::init_option_name( $term_id ), $journal );
			}

			if ( (int) $journal['cursor'] < count( $entries ) ) {
				return $journal;
			}
		}

		foreach ( $entries as $entry ) {
			if ( ! self::initialization_source_matches( $term, $entry ) || (int) $entry['new_rank'] !== self::rank( (int) $entry['post_id'] ) ) {
				return self::initialization_conflict( $journal, __( 'The frozen initialization set no longer matches the current Series.', 'wp-title-layer' ) );
			}
		}

		$revisions_before = (array) $journal['revisions_before'];
		if ( 'ranks' === $phase ) {
			$current_revisions = Meta::sanitize_sequence_revisions( get_term_meta( $term_id, Schema::TERM_META_SEQUENCE_REVISIONS, true ) );
			if ( $current_revisions !== $revisions_before ) {
				return self::initialization_conflict( $journal, __( 'The Series sequence revision changed during initialization.', 'wp-title-layer' ) );
			}
			$journal['revisions_after'] = self::initialization_revisions_after( $term, $entries, $revisions_before );
			$journal['commit_phase']    = 'seasons';
			self::write_option( self::init_option_name( $term_id ), $journal );
			$phase = 'seasons';
		}

		$revisions_after = Meta::sanitize_sequence_revisions( (array) ( $journal['revisions_after'] ?? array() ) );
		if ( 'seasons' === $phase ) {
			if ( ! self::persist_seasons( $term, $seasons_after ) || Series::seasons( $term ) !== $seasons_after ) {
				return self::initialization_conflict( $journal, __( 'The stable Season public IDs could not be verified.', 'wp-title-layer' ) );
			}
			$journal['commit_phase'] = 'revisions';
			self::write_option( self::init_option_name( $term_id ), $journal );
			$phase = 'revisions';
		}

		if ( 'revisions' === $phase ) {
			$current_revisions = Meta::sanitize_sequence_revisions( get_term_meta( $term_id, Schema::TERM_META_SEQUENCE_REVISIONS, true ) );
			if ( $current_revisions !== $revisions_before && $current_revisions !== $revisions_after ) {
				return self::initialization_conflict( $journal, __( 'The Series sequence revision changed during the final initialization commit.', 'wp-title-layer' ) );
			}
			update_term_meta( $term_id, Schema::TERM_META_SEQUENCE_REVISIONS, $revisions_after );
			if ( Meta::sanitize_sequence_revisions( get_term_meta( $term_id, Schema::TERM_META_SEQUENCE_REVISIONS, true ) ) !== $revisions_after ) {
				return self::initialization_conflict( $journal, __( 'Sequence Manager could not verify the committed scope revisions.', 'wp-title-layer' ) );
			}
			$journal['commit_phase'] = 'schema';
			self::write_option( self::init_option_name( $term_id ), $journal );
			$phase = 'schema';
		}

		if ( 'schema' === $phase ) {
			if ( Series::seasons( $term ) !== $seasons_after || Meta::sanitize_sequence_revisions( get_term_meta( $term_id, Schema::TERM_META_SEQUENCE_REVISIONS, true ) ) !== $revisions_after ) {
				return self::initialization_conflict( $journal, __( 'The final initialization state changed before activation.', 'wp-title-layer' ) );
			}
			if ( ! self::is_managed( $term ) ) {
				update_term_meta( $term_id, Schema::TERM_META_SEQUENCE_SCHEMA_VERSION, Schema::SEQUENCE_SCHEMA_VERSION );
			}
			if ( ! self::is_managed( $term_id ) ) {
				return self::initialization_conflict( $journal, __( 'Sequence Manager could not commit the Series activation marker.', 'wp-title-layer' ) );
			}
		}

		$journal['status']          = 'complete';
		$journal['completed_at']    = time();
		$journal['revisions_after'] = $revisions_after;
		self::write_option( self::init_option_name( $term_id ), $journal );
		return $journal;
	}

	/** Value-checked rollback for an incomplete or untouched completed initialization. */
	public static function rollback_initialization( int $term_id ) {
		$term    = self::term( $term_id );
		$journal = self::initialization_journal( $term_id );
		if ( ! $term instanceof \WP_Term || ! is_array( $journal ) || ! in_array( $journal['status'] ?? '', array( 'writing', 'conflict', 'complete' ), true ) ) {
			return new \WP_Error( 'wptl_sequence_no_rollback', __( 'No Sequence Manager initialization is available to roll back.', 'wp-title-layer' ) );
		}
		if ( BookStructure::is_enabled( $term ) ) {
			return new \WP_Error( 'wptl_sequence_book_structure_active', __( 'Sequence initialization cannot be rolled back after book structure becomes active.', 'wp-title-layer' ) );
		}
		$entries    = array_values( (array) $journal['entries'] );
		$permission = self::authorize_manager( $term, wp_list_pluck( $entries, 'post_id' ) );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$complete          = 'complete' === ( $journal['status'] ?? '' );
		$phase             = sanitize_key( (string) ( $journal['commit_phase'] ?? ( $complete ? 'schema' : 'ranks' ) ) );
		$seasons_before    = (array) $journal['seasons_before'];
		$seasons_after     = (array) $journal['seasons_after'];
		$revisions_before  = Meta::sanitize_sequence_revisions( (array) $journal['revisions_before'] );
		$revisions_after   = Meta::sanitize_sequence_revisions( (array) ( $journal['revisions_after'] ?? array() ) );
		$current_revisions = Meta::sanitize_sequence_revisions( get_term_meta( $term_id, Schema::TERM_META_SEQUENCE_REVISIONS, true ) );
		$current_seasons    = Series::seasons( $term );
		$managed_incomplete = ! $complete && self::is_managed( $term );
		if ( $complete ) {
			if ( ! self::is_managed( $term ) || $current_revisions !== $revisions_after || $current_seasons !== $seasons_after ) {
				return new \WP_Error( 'wptl_sequence_rollback_stale', __( 'This Series has changed since initialization, so an automatic downgrade would be unsafe.', 'wp-title-layer' ) );
			}
		}
		if ( $managed_incomplete && ( 'schema' !== $phase || $current_revisions !== $revisions_after || $current_seasons !== $seasons_after ) ) {
			return new \WP_Error( 'wptl_sequence_rollback_stale', __( 'The interrupted activation state no longer matches its journal, so rollback would be unsafe.', 'wp-title-layer' ) );
		}
		if ( $complete || $managed_incomplete ) {
			foreach ( $entries as $entry ) {
				if ( (int) $entry['new_rank'] !== self::rank( (int) $entry['post_id'] ) ) {
					return new \WP_Error( 'wptl_sequence_rollback_rank_changed', __( 'At least one managed rank changed after initialization; no rollback was performed.', 'wp-title-layer' ) );
				}
			}
		}
		$season_commit_started   = $complete || in_array( $phase, array( 'seasons', 'revisions', 'schema' ), true );
		$revision_commit_started = $complete || in_array( $phase, array( 'revisions', 'schema' ), true );
		if ( $season_commit_started && $current_seasons !== $seasons_before && $current_seasons !== $seasons_after ) {
			return new \WP_Error( 'wptl_sequence_rollback_definition_changed', __( 'The Series definition changed during recovery, so rollback did not overwrite it.', 'wp-title-layer' ) );
		}
		if ( $revision_commit_started && $current_revisions !== $revisions_before && $current_revisions !== $revisions_after ) {
			return new \WP_Error( 'wptl_sequence_rollback_revision_changed', __( 'The sequence revision changed during recovery, so rollback did not overwrite it.', 'wp-title-layer' ) );
		}

		if ( $complete || $managed_incomplete ) {
			delete_term_meta( $term_id, Schema::TERM_META_SEQUENCE_SCHEMA_VERSION );
			if ( self::is_managed( $term_id ) ) {
				return new \WP_Error( 'wptl_sequence_rollback_marker_failed', __( 'The managed activation marker could not be removed, so article ranks were left untouched.', 'wp-title-layer' ) );
			}
		}

		$limit     = ( $complete || $managed_incomplete ) ? count( $entries ) : min( count( $entries ), max( 0, (int) $journal['cursor'] ) );
		$conflicts = array();
		for ( $index = 0; $index < $limit; ++$index ) {
			$entry   = $entries[ $index ];
			$post_id = (int) $entry['post_id'];
			if ( (int) $entry['new_rank'] !== self::rank( $post_id ) ) {
				$conflicts[] = $post_id;
				continue;
			}
			self::restore_meta( $post_id, Schema::META_SEQUENCE_RANK, ! empty( $entry['old_rank_exists'] ), (int) $entry['old_rank'] );
			self::restore_meta( $post_id, Schema::META_SEQUENCE_SOURCE_POSITION, ! empty( $entry['old_baseline_exists'] ), (string) $entry['old_baseline'] );
		}

		if ( ! $conflicts ) {
			if ( $season_commit_started && $current_seasons === $seasons_after ) {
				update_term_meta( $term_id, Schema::TERM_META_SEASONS, $seasons_before );
			}
			if ( $revision_commit_started && $current_revisions === $revisions_after ) {
				update_term_meta( $term_id, Schema::TERM_META_SEQUENCE_REVISIONS, $revisions_before );
			}
		}

		$journal['status']             = $conflicts ? 'rollback_conflict' : 'rolled_back';
		$journal['rolled_back_at']     = time();
		$journal['rollback_conflicts'] = $conflicts;
		self::write_option( self::init_option_name( $term_id ), $journal );
		return $journal;
	}

	/** Move one member before/after another, or to the start/end of its scope. */
	public static function move( int $term_id, string $season_key, int $post_id, int $anchor_id, string $placement, int $expected_revision ) {
		$term = self::term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wptl_sequence_invalid_series', __( 'The selected Series no longer exists.', 'wp-title-layer' ) );
		}
		$permission = self::authorize_manager( $term, array( $post_id, $anchor_id ) );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		return self::move_internal( $term, $season_key, $post_id, $anchor_id, $placement, $expected_revision, true );
	}

	/** Move one entry inside an explicit 0.11 book-structure track. */
	public static function move_track( int $term_id, string $track_key, int $post_id, int $anchor_id, string $placement, int $expected_revision ) {
		$term = self::term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'wptl_sequence_invalid_series', __( 'The selected Series no longer exists.', 'wp-title-layer' ) );
		}
		$permission = self::authorize_manager( $term, array( $post_id, $anchor_id ) );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		return self::move_internal( $term, '', $post_id, $anchor_id, $placement, $expected_revision, true, sanitize_key( $track_key ) );
	}

	/** Undo only the latest completed move when all written values still match. */
	public static function undo_last_move( int $term_id, string $season_key, int $expected_revision, string $track_key = '' ) {
		$term    = self::term( $term_id );
		$track_key = sanitize_key( $track_key );
		$journal = self::last_move_journal( $term_id, $season_key, $track_key );
		if ( ! $term instanceof \WP_Term || ! is_array( $journal ) || 'complete' !== ( $journal['status'] ?? '' ) || 'move' !== ( $journal['kind'] ?? '' ) ) {
			return new \WP_Error( 'wptl_sequence_nothing_to_undo', __( 'There is no completed sequence move to undo in this scope.', 'wp-title-layer' ) );
		}
		$changes    = array_values( (array) $journal['changes'] );
		$permission = self::authorize_manager( $term, wp_list_pluck( $changes, 'post_id' ) );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$current_revision = '' !== $track_key ? self::track_revision( $term, $track_key ) : self::revision( $term, $season_key );
		if ( $expected_revision !== $current_revision || $current_revision !== (int) $journal['revision_after'] ) {
			return new \WP_Error( 'wptl_sequence_revision_conflict', __( 'The sequence changed after this page loaded. Reload before undoing.', 'wp-title-layer' ) );
		}
		foreach ( $changes as $change ) {
			if ( (int) $change['new_rank'] !== self::rank( (int) $change['post_id'] ) ) {
				return new \WP_Error( 'wptl_sequence_undo_value_conflict', __( 'A rank changed after the last move, so undo did not overwrite it.', 'wp-title-layer' ) );
			}
		}
		foreach ( $changes as $change ) {
			self::restore_meta( (int) $change['post_id'], Schema::META_SEQUENCE_RANK, ! empty( $change['old_rank_exists'] ), (int) $change['old_rank'] );
		}
		$new_revision = '' !== $track_key
			? self::set_revision_for_key( $term, $track_key, $current_revision + 1 )
			: self::set_revision( $term, $season_key, $current_revision + 1 );
		$journal['status']        = 'undone';
		$journal['undone_at']     = time();
		$journal['undo_revision'] = $new_revision;
		self::write_option( self::move_option_name( $term_id, '' !== $track_key ? $track_key : self::scope_key( $term, $season_key ) ), $journal );
		return $journal;
	}

	/** Append a newly assigned/scope-changed article without exposing rank input. */
	public static function ensure_post_rank( int $post_id, bool $force_append = false ) {
		$term = Series::get_primary_term( $post_id );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}
		$book_context = BookStructure::context( $post_id, $term );
		$book_track = BookStructure::is_enabled( $term ) && ! empty( $book_context['valid'] ) && empty( $book_context['ambiguous'] )
			? BookStructure::track( $term, (string) $book_context['track'] )
			: null;
		if ( $book_track && Schema::ROLE_ARTICLE !== $book_track['role'] ) {
			if ( 0 < self::rank( $post_id ) && ! $force_append ) {
				return true;
			}
			return self::move_internal( $term, '', $post_id, 0, 'end', self::track_revision( $term, (string) $book_track['key'] ), false, (string) $book_track['key'] );
		}
		if ( ! Series::is_ordered( $term ) || ! self::is_managed( $term ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'wptl_sequence_cannot_edit_post', __( 'You cannot update the managed order for this article.', 'wp-title-layer' ) );
		}
		$season_key = Series::is_seasoned( $term ) ? sanitize_key( (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) ) : '';
		if ( Series::is_seasoned( $term ) && ! Series::season( $term, $season_key ) ) {
			return new \WP_Error( 'wptl_sequence_invalid_season', __( 'Choose a defined season before this article can be appended to managed order.', 'wp-title-layer' ) );
		}
		if ( 0 < self::rank( $post_id ) && ! $force_append ) {
			return true;
		}

		return self::move_internal( $term, $season_key, $post_id, 0, 'end', self::revision( $term, $season_key ), false );
	}

	/** Return a bounded manager page without rendering an entire long Series. */
	public static function scope_page( int $term_id, string $season_key = '', int $page = 1, int $per_page = 50, string $search = '' ): array {
		$term = self::term( $term_id );
		if ( ! $term instanceof \WP_Term || ! self::is_managed( $term ) || ! Series::is_ordered( $term ) ) {
			return array( 'valid' => false, 'term' => null, 'rows' => array(), 'total' => 0, 'page' => 1, 'total_pages' => 0, 'revision' => 0 );
		}
		if ( BookStructure::is_enabled( $term ) ) {
			$track_key = BookStructure::track_key( $term, Series::is_seasoned( $term ) ? Schema::SCOPE_SEASON : Schema::SCOPE_SERIES, $season_key, Schema::ROLE_ARTICLE );
			return self::track_page( $term_id, $track_key, $page, $per_page, $search );
		}
		$season_key = sanitize_key( $season_key );
		if ( Series::is_seasoned( $term ) && ! Series::season( $term, $season_key ) ) {
			return array( 'valid' => false, 'term' => $term, 'rows' => array(), 'total' => 0, 'page' => 1, 'total_pages' => 0, 'revision' => 0 );
		}
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$args     = array(
			'post_type'           => self::post_types( $term ),
			'post_status'         => self::editorial_statuses(),
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'ignore_sticky_posts' => true,
			'orderby'             => 'none',
			'wptl_sequence_scope_page' => true,
			'tax_query'           => array(
				array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( $term_id ) ),
			),
		);
		if ( '' !== trim( $search ) ) {
			$args['s'] = sanitize_text_field( $search );
		}
		if ( Series::is_seasoned( $term ) ) {
			$args['meta_query'] = array( array( 'key' => Schema::META_SEASON_KEY, 'value' => $season_key, 'compare' => '=' ) );
		}
		add_filter( 'posts_clauses', array( self::class, 'scope_page_clauses' ), 20, 2 );
		try {
			$query = new \WP_Query( $args );
		} finally {
			remove_filter( 'posts_clauses', array( self::class, 'scope_page_clauses' ), 20 );
		}
		$rows  = array();
		foreach ( (array) $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$rows[] = self::manager_row( $post, $term );
		}

		return array(
			'valid'       => true,
			'term'        => $term,
			'season_key'  => $season_key,
			'rows'        => $rows,
			'total'       => (int) $query->found_posts,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) $query->max_num_pages,
			'revision'    => self::revision( $term, $season_key ),
		);
	}

	/** Return a bounded view of one explicit book-structure track. */
	public static function track_page( int $term_id, string $track_key, int $page = 1, int $per_page = 50, string $search = '' ): array {
		$term = self::term( $term_id );
		$track_key = sanitize_key( $track_key );
		$track = $term instanceof \WP_Term ? BookStructure::track( $term, $track_key ) : null;
		if ( ! $term instanceof \WP_Term || ! BookStructure::is_enabled( $term ) || ! is_array( $track ) ) {
			return array( 'valid' => false, 'term' => $term, 'track' => null, 'rows' => array(), 'total' => 0, 'page' => 1, 'total_pages' => 0, 'revision' => 0 );
		}
		$page = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$role = (string) $track['role'];
		$scope = (string) $track['scope'];
		$meta_query = array(
			'relation' => 'AND',
			Schema::ROLE_ARTICLE === $role
				? self::blank_or_values_meta_query( Schema::META_SERIES_ROLE, array( Schema::ROLE_ARTICLE ) )
				: array( 'key' => Schema::META_SERIES_ROLE, 'value' => $role, 'compare' => '=' ),
		);
		if ( ! Series::is_seasoned( $term ) ) {
			$meta_query[] = self::blank_or_values_meta_query( Schema::META_SERIES_SCOPE, array( Schema::SCOPE_SERIES ) );
		} elseif ( Schema::SCOPE_SERIES === $scope ) {
			$meta_query[] = array( 'key' => Schema::META_SERIES_SCOPE, 'value' => Schema::SCOPE_SERIES, 'compare' => '=' );
		} else {
			$meta_query[] = Schema::ROLE_ARTICLE === $role
				? self::blank_or_values_meta_query( Schema::META_SERIES_SCOPE, array( Schema::SCOPE_SEASON ) )
				: array( 'key' => Schema::META_SERIES_SCOPE, 'value' => Schema::SCOPE_SEASON, 'compare' => '=' );
			$meta_query[] = array( 'key' => Schema::META_SEASON_KEY, 'value' => (string) $track['season_key'], 'compare' => '=' );
		}
		$args = array(
			'post_type'           => self::post_types( $term ),
			'post_status'         => self::editorial_statuses(),
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'ignore_sticky_posts' => true,
			'orderby'             => 'none',
			'wptl_sequence_scope_page' => true,
			'tax_query'           => array( array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( $term_id ) ) ),
			'meta_query'          => $meta_query,
		);
		$search = trim( sanitize_text_field( $search ) );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		add_filter( 'posts_clauses', array( self::class, 'scope_page_clauses' ), 20, 2 );
		try {
			$query = new \WP_Query( $args );
		} finally {
			remove_filter( 'posts_clauses', array( self::class, 'scope_page_clauses' ), 20 );
		}
		$rows = array();
		foreach ( (array) $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$rows[] = self::manager_row( $post, $term );
			}
		}
		return array(
			'valid'       => true,
			'term'        => $term,
			'track'       => $track,
			'rows'        => $rows,
			'total'       => max( 0, (int) $query->found_posts ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => max( 0, (int) $query->max_num_pages ),
			'revision'    => self::track_revision( $term, $track_key ),
		);
	}

	/** @return array<string,mixed> */
	private static function blank_or_values_meta_query( string $meta_key, array $values ): array {
		$query = array(
			'relation' => 'OR',
			array( 'key' => $meta_key, 'compare' => 'NOT EXISTS' ),
			array( 'key' => $meta_key, 'value' => '', 'compare' => '=' ),
		);
		foreach ( array_values( array_unique( array_filter( array_map( 'sanitize_key', $values ) ) ) ) as $value ) {
			$query[] = array( 'key' => $meta_key, 'value' => $value, 'compare' => '=' );
		}
		return $query;
	}

	/** Include missing/damaged ranks at the end of a bounded Manager page. */
	public static function scope_page_clauses( array $clauses, \WP_Query $query ): array {
		if ( ! $query->get( 'wptl_sequence_scope_page' ) ) {
			return $clauses;
		}
		global $wpdb;
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN (
				SELECT wptl_manager_rank_value.post_id, wptl_manager_rank_value.meta_value
				FROM {$wpdb->postmeta} AS wptl_manager_rank_value
				INNER JOIN (
					SELECT post_id, MIN(meta_id) AS meta_id
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s
					GROUP BY post_id
				) AS wptl_manager_rank_first ON wptl_manager_rank_first.meta_id = wptl_manager_rank_value.meta_id
			) AS wptl_manager_rank ON wptl_manager_rank.post_id = {$wpdb->posts}.ID",
			Schema::META_SEQUENCE_RANK
		);
		$clauses['orderby'] = "CASE
			WHEN wptl_manager_rank.meta_value IS NULL
				OR wptl_manager_rank.meta_value = ''
				OR wptl_manager_rank.meta_value NOT REGEXP '^[1-9][0-9]*$'
			THEN 1 ELSE 0 END ASC,
			CASE WHEN wptl_manager_rank.meta_value REGEXP '^[1-9][0-9]*$'
				THEN CAST(wptl_manager_rank.meta_value AS UNSIGNED) ELSE 0 END ASC,
			{$wpdb->posts}.ID ASC";
		return $clauses;
	}

	/** @return array<string,mixed>|null */
	public static function initialization_journal( int $term_id ): ?array {
		$value = get_option( self::init_option_name( $term_id ), null );
		return is_array( $value ) ? $value : null;
	}

	/** @return array<string,mixed>|null */
	public static function last_move_journal( int $term_id, string $season_key = '', string $track_key = '' ): ?array {
		$term  = self::term( $term_id );
		$scope = '' !== $track_key && $term instanceof \WP_Term && BookStructure::track( $term, $track_key )
			? sanitize_key( $track_key )
			: self::scope_key( $term, $season_key );
		$value = '' !== $scope ? get_option( self::move_option_name( $term_id, $scope ), null ) : null;
		return is_array( $value ) ? $value : null;
	}

	/** Assign stable, never-reused public IDs while preserving existing keys. */
	public static function with_public_ids( \WP_Term $term, array $seasons ): array {
		$seasons  = Meta::sanitize_seasons( $seasons );
		$existing = array();
		$used     = array();
		$highwater = max( 0, (int) get_term_meta( $term->term_id, Schema::TERM_META_SEASON_ID_HIGHWATER, true ) );
		foreach ( Series::seasons( $term ) as $season ) {
			$public_id = max( 0, (int) ( $season['public_id'] ?? 0 ) );
			if ( 0 < $public_id && ! isset( $used[ $public_id ] ) ) {
				$existing[ (string) $season['key'] ] = $public_id;
				$used[ $public_id ] = true;
				$highwater = max( $highwater, $public_id );
			}
		}

		$result = array();
		$assigned = array();
		foreach ( $seasons as $season ) {
			$public_id = max( 0, (int) ( $existing[ (string) $season['key'] ] ?? 0 ) );
			if ( 0 >= $public_id || isset( $assigned[ $public_id ] ) ) {
				do {
					++$highwater;
				} while ( isset( $used[ $highwater ] ) || isset( $assigned[ $highwater ] ) );
				$public_id = $highwater;
			}
			$highwater = max( $highwater, $public_id );
			$assigned[ $public_id ] = true;
			$season['public_id'] = $public_id;
			$result[] = $season;
		}
		return Meta::sanitize_seasons( $result );
	}

	public static function persist_seasons( \WP_Term $term, array $seasons ): bool {
		$seasons   = self::with_public_ids( $term, $seasons );
		$highwater = max( 0, (int) get_term_meta( $term->term_id, Schema::TERM_META_SEASON_ID_HIGHWATER, true ) );
		foreach ( $seasons as $season ) {
			$highwater = max( $highwater, (int) ( $season['public_id'] ?? 0 ) );
		}
		update_term_meta( $term->term_id, Schema::TERM_META_SEASONS, $seasons );
		update_term_meta( $term->term_id, Schema::TERM_META_SEASON_ID_HIGHWATER, $highwater );
		return Series::seasons( $term ) === $seasons
			&& $highwater === (int) get_term_meta( $term->term_id, Schema::TERM_META_SEASON_ID_HIGHWATER, true );
	}

	/** @return array{season:array<string,mixed>,legacy:bool}|null */
	public static function resolve_season_token( \WP_Term $term, string $token ): ?array {
		$token = trim( $token );
		if ( '' === $token || ! Series::is_seasoned( $term ) ) {
			return null;
		}
		if ( 1 === preg_match( '/^[1-9][0-9]*$/D', $token ) ) {
			$public_id = (int) $token;
			foreach ( Series::seasons( $term ) as $season ) {
				if ( $public_id === (int) ( $season['public_id'] ?? 0 ) ) {
					return array( 'season' => $season, 'legacy' => false );
				}
			}
			// A pre-0.10 site may already have used a numeric Season key. Public
			// IDs remain canonical when present; otherwise retain that old route.
			$legacy_numeric = Series::season( $term, sanitize_key( $token ) );
			return is_array( $legacy_numeric ) ? array( 'season' => $legacy_numeric, 'legacy' => true ) : null;
		}

		$season = Series::season( $term, sanitize_key( $token ) );
		return is_array( $season ) ? array( 'season' => $season, 'legacy' => true ) : null;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function move_internal( \WP_Term $term, string $season_key, int $post_id, int $anchor_id, string $placement, int $expected_revision, bool $manager_authorization, string $track_key = '' ) {
		$track_key = sanitize_key( $track_key );
		$track = '' !== $track_key ? BookStructure::track( $term, $track_key ) : null;
		if ( is_array( $track ) ) {
			if ( ! BookStructure::is_enabled( $term ) || empty( $track['movable'] ) ) {
				return new \WP_Error( 'wptl_sequence_track_not_movable', __( 'This book-structure track is not manually ordered.', 'wp-title-layer' ) );
			}
			$season_key = (string) $track['season_key'];
		} elseif ( ! Series::is_ordered( $term ) || ! self::is_managed( $term ) ) {
			return new \WP_Error( 'wptl_sequence_not_managed', __( 'Initialize this ordered Series before moving articles.', 'wp-title-layer' ) );
		}
		$season_key = Series::is_seasoned( $term ) ? sanitize_key( $season_key ) : '';
		$requires_season = ! is_array( $track ) || Schema::SCOPE_SEASON === (string) ( $track['scope'] ?? '' );
		if ( Series::is_seasoned( $term ) && $requires_season && ! Series::season( $term, $season_key ) ) {
			return new \WP_Error( 'wptl_sequence_invalid_scope', __( 'Choose a defined season before moving articles.', 'wp-title-layer' ) );
		}
		$placement = sanitize_key( $placement );
		if ( ! in_array( $placement, array( 'before', 'after', 'up', 'down', 'start', 'end' ), true ) ) {
			return new \WP_Error( 'wptl_sequence_invalid_placement', __( 'Choose whether the article belongs before or after its target.', 'wp-title-layer' ) );
		}
		$current_revision = is_array( $track ) ? self::track_revision( $term, $track_key ) : self::revision( $term, $season_key );
		if ( $expected_revision !== $current_revision ) {
			return new \WP_Error( 'wptl_sequence_revision_conflict', __( 'The sequence changed after this page loaded. Reload before saving another move.', 'wp-title-layer' ) );
		}

		if ( is_array( $track ) ) {
			$post_ids = BookStructure::track_post_ids( $term, $track, false, true );
		} elseif ( BookStructure::is_enabled( $term ) ) {
			$body_track_key = BookStructure::track_key( $term, Series::is_seasoned( $term ) ? Schema::SCOPE_SEASON : Schema::SCOPE_SERIES, $season_key, Schema::ROLE_ARTICLE );
			$body_track = BookStructure::track( $term, $body_track_key );
			$post_ids = is_array( $body_track ) ? BookStructure::track_post_ids( $term, $body_track, false, true ) : array();
		} else {
			$post_ids = self::ordered_post_ids( $term, $season_key, false );
		}
		if ( ! in_array( $post_id, $post_ids, true ) ) {
			return new \WP_Error( 'wptl_sequence_post_outside_scope', __( 'The article is no longer a member of this Series and season.', 'wp-title-layer' ) );
		}
		if ( in_array( $placement, array( 'up', 'down' ), true ) ) {
			$current_index = array_search( $post_id, $post_ids, true );
			$anchor_index  = 'up' === $placement ? (int) $current_index - 1 : (int) $current_index + 1;
			if ( false === $current_index || ! isset( $post_ids[ $anchor_index ] ) ) {
				return new \WP_Error( 'wptl_sequence_no_change', __( 'The article is already at that boundary.', 'wp-title-layer' ) );
			}
			$anchor_id = (int) $post_ids[ $anchor_index ];
			$placement = 'up' === $placement ? 'before' : 'after';
		}
		if ( in_array( $placement, array( 'before', 'after' ), true ) && ( $post_id === $anchor_id || ! in_array( $anchor_id, $post_ids, true ) ) ) {
			return new \WP_Error( 'wptl_sequence_anchor_outside_scope', __( 'The target article is no longer in the same sequence scope.', 'wp-title-layer' ) );
		}

		$ordered = array_values( array_diff( $post_ids, array( $post_id ) ) );
		if ( 'start' === $placement ) {
			array_unshift( $ordered, $post_id );
		} elseif ( 'end' === $placement ) {
			$ordered[] = $post_id;
		} else {
			$anchor_index = array_search( $anchor_id, $ordered, true );
			if ( false === $anchor_index ) {
				return new \WP_Error( 'wptl_sequence_anchor_outside_scope', __( 'The target article is no longer in the same sequence scope.', 'wp-title-layer' ) );
			}
			$insert_at = 'after' === $placement ? (int) $anchor_index + 1 : (int) $anchor_index;
			array_splice( $ordered, $insert_at, 0, array( $post_id ) );
		}

		$ranks        = array();
		$valid_ranks  = true;
		$seen_ranks   = array();
		foreach ( $ordered as $candidate_id ) {
			$rank = self::rank( $candidate_id );
			$ranks[ $candidate_id ] = $rank;
			if ( 0 >= $rank || isset( $seen_ranks[ $rank ] ) ) {
				$valid_ranks = false;
			}
			$seen_ranks[ $rank ] = true;
		}

		$changes = array();
		$index   = array_search( $post_id, $ordered, true );
		$new_rank = false !== $index && $valid_ranks ? self::rank_between_neighbors( $ordered, (int) $index, $ranks ) : 0;
		$force_rank_write = 0 >= self::rank( $post_id ) || 'end' === $placement;
		if ( 0 < $new_rank && ( $new_rank !== self::rank( $post_id ) || $force_rank_write ) ) {
			$changes[] = array( 'post_id' => $post_id, 'old_rank_exists' => metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_RANK ), 'old_rank' => self::rank( $post_id ), 'new_rank' => $new_rank );
		} elseif ( 0 >= $new_rank ) {
			foreach ( $ordered as $order_index => $candidate_id ) {
				$target_rank = ( $order_index + 1 ) * self::RANK_STEP;
				if ( self::rank( $candidate_id ) !== $target_rank ) {
					$changes[] = array( 'post_id' => $candidate_id, 'old_rank_exists' => metadata_exists( 'post', $candidate_id, Schema::META_SEQUENCE_RANK ), 'old_rank' => self::rank( $candidate_id ), 'new_rank' => $target_rank );
				}
			}
		}
		if ( ! $changes ) {
			return new \WP_Error( 'wptl_sequence_no_change', __( 'The article is already in that position.', 'wp-title-layer' ) );
		}

		if ( $manager_authorization ) {
			$permission = self::authorize_manager( $term, wp_list_pluck( $changes, 'post_id' ) );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
		} else {
			foreach ( $changes as $change ) {
				if ( ! current_user_can( 'edit_post', (int) $change['post_id'] ) ) {
					return new \WP_Error( 'wptl_sequence_cannot_rebalance', __( 'This append needs a wider rebalance that you are not allowed to perform.', 'wp-title-layer' ) );
				}
			}
		}

		$scope   = is_array( $track ) ? $track_key : self::scope_key( $term, $season_key );
		$journal = array(
			'journal_version' => 1,
			'kind'             => $manager_authorization ? 'move' : 'append',
			'status'           => 'writing',
			'term_id'          => (int) $term->term_id,
			'scope'            => $scope,
			'season_key'       => $season_key,
			'track_key'        => is_array( $track ) ? $track_key : '',
			'post_id'          => $post_id,
			'anchor_id'        => $anchor_id,
			'placement'        => $placement,
			'created_at'       => time(),
			'user_id'          => get_current_user_id(),
			'revision_before'  => $current_revision,
			'revision_after'   => 0,
			'changes'          => $changes,
		);
		$option_name = self::move_option_name( (int) $term->term_id, $scope );
		self::write_option( $option_name, $journal );

		$written = array();
		foreach ( $changes as $change ) {
			update_post_meta( (int) $change['post_id'], Schema::META_SEQUENCE_RANK, (int) $change['new_rank'] );
			if ( (int) $change['new_rank'] !== self::rank( (int) $change['post_id'] ) ) {
				self::rollback_changes( $written );
				$journal['status'] = 'failed';
				self::write_option( $option_name, $journal );
				return new \WP_Error( 'wptl_sequence_write_failed', __( 'WordPress could not verify the sequence move, so completed writes were restored.', 'wp-title-layer' ) );
			}
			$written[] = $change;
		}
		$new_revision = is_array( $track )
			? self::set_revision_for_key( $term, $track_key, $current_revision + 1 )
			: self::set_revision( $term, $season_key, $current_revision + 1 );
		if ( $new_revision !== $current_revision + 1 ) {
			self::rollback_changes( $written );
			$journal['status'] = 'failed';
			self::write_option( $option_name, $journal );
			return new \WP_Error( 'wptl_sequence_revision_write_failed', __( 'The sequence revision could not be committed, so the move was restored.', 'wp-title-layer' ) );
		}
		$journal['status']         = 'complete';
		$journal['completed_at']   = time();
		$journal['revision_after'] = $new_revision;
		self::write_option( $option_name, $journal );

		if ( ! metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_SOURCE_POSITION ) ) {
			update_post_meta( $post_id, Schema::META_SEQUENCE_SOURCE_POSITION, self::source_position( $post_id ) );
		}
		return $journal;
	}

	/** @param int[] $post_ids */
	private static function authorize_manager( \WP_Term $term, array $post_ids ) {
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy instanceof \WP_Taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) || ! current_user_can( 'edit_term', (int) $term->term_id ) ) {
			return new \WP_Error( 'wptl_sequence_cannot_manage', __( 'You are not allowed to manage this Series sequence.', 'wp-title-layer' ) );
		}
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) ) as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_Error( 'wptl_sequence_cannot_edit_member', __( 'You cannot edit every article affected by this sequence operation.', 'wp-title-layer' ) );
			}
		}
		return true;
	}

	/** @return int[] */
	private static function member_ids( \WP_Term $term ): array {
		$query = new \WP_Query(
			array(
				'post_type'           => self::post_types( $term ),
				'post_status'         => self::editorial_statuses(),
				'fields'              => 'ids',
				'posts_per_page'      => -1,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'tax_query'           => array( array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( (int) $term->term_id ) ) ),
			)
		);
		return array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
	}

	/** @return string[] */
	private static function editorial_statuses(): array {
		$statuses = array_values( array_filter( array_map( 'sanitize_key', get_post_stati( array( 'internal' => false ), 'names' ) ) ) );
		return array_values( array_diff( $statuses, array( 'trash', 'auto-draft', 'inherit' ) ) );
	}

	/** @return string[] */
	private static function post_types( \WP_Term $term ): array {
		$taxonomy = get_taxonomy( $term->taxonomy );
		return $taxonomy instanceof \WP_Taxonomy
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $taxonomy->object_type ) ) ) )
			: array();
	}

	/** @return array<string,mixed> */
	private static function preview_record( int $post_id, string $season_key ): array {
		$position_exists = metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_POSITION );
		$position_raw    = $position_exists ? get_post_meta( $post_id, Schema::META_SEQUENCE_POSITION, true ) : null;
		$position_text   = is_scalar( $position_raw ) ? trim( (string) $position_raw ) : '';
		$post            = get_post( $post_id );
		return array(
			'id'               => $post_id,
			'title'            => $post instanceof \WP_Post && '' !== trim( (string) $post->post_title ) ? (string) $post->post_title : __( '(no title)', 'wp-title-layer' ),
			'status'           => $post instanceof \WP_Post ? (string) $post->post_status : '',
			'date'             => $post instanceof \WP_Post ? (string) $post->post_date : '',
			'season_key'       => $season_key,
			'position'         => 1 === preg_match( '/^[0-9]+$/D', $position_text ) ? (int) $position_text : null,
			'position_valid'   => $position_exists && 1 === preg_match( '/^[0-9]+$/D', $position_text ),
			'source_position'  => $position_exists ? $position_text : self::MISSING_SOURCE,
			'edit_url'         => current_user_can( 'edit_post', $post_id ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
		);
	}

	private static function compare_preview_position( array $left, array $right ): int {
		$comparison = (int) $left['position'] <=> (int) $right['position'];
		return 0 !== $comparison ? $comparison : (int) $left['id'] <=> (int) $right['id'];
	}

	private static function compare_preview_date( array $left, array $right ): int {
		$comparison = strcmp( (string) $left['date'], (string) $right['date'] );
		return 0 !== $comparison ? $comparison : (int) $left['id'] <=> (int) $right['id'];
	}

	private static function preview_fingerprint( array $records, \WP_Term $term, array $seasons ): string {
		$values = array();
		foreach ( $records as $record ) {
			$values[] = array( (int) $record['id'], (string) $record['status'], (string) $record['date'], (string) $record['season_key'], (string) $record['source_position'] );
		}
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'term_id'   => (int) $term->term_id,
					'mode'      => Series::mode( $term ),
					'structure' => Series::structure( $term ),
					'seasons'   => Meta::sanitize_seasons( $seasons ),
					'members'   => $values,
				)
			)
		);
	}

	private static function initialization_source_matches( \WP_Term $term, array $entry ): bool {
		$post_id = absint( $entry['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_status, self::editorial_statuses(), true ) || ! has_term( (int) $term->term_id, $term->taxonomy, $post_id ) ) {
			return false;
		}
		$current_season = Series::is_seasoned( $term ) ? sanitize_key( (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) ) : '';
		return (string) $post->post_status === (string) ( $entry['source_status'] ?? '' )
			&& (string) $post->post_date === (string) ( $entry['source_date'] ?? '' )
			&& $current_season === (string) ( $entry['source_season'] ?? '' )
			&& self::source_position( $post_id ) === (string) ( $entry['source_position'] ?? '' );
	}

	private static function source_position( int $post_id ): string {
		if ( ! metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_POSITION ) ) {
			return self::MISSING_SOURCE;
		}
		$value = get_post_meta( $post_id, Schema::META_SEQUENCE_POSITION, true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function initialization_conflict( array $journal, string $message ) {
		$journal['status'] = 'conflict';
		$journal['error']  = $message;
		self::write_option( self::init_option_name( (int) $journal['term_id'] ), $journal );
		return new \WP_Error( 'wptl_sequence_initialization_conflict', $message );
	}

	/**
	 * Compute the exact optimistic-concurrency versions that the final commit
	 * will write, before any Series term metadata changes.
	 *
	 * @param array<int,array<string,mixed>> $entries Frozen initialization rows.
	 * @param array<string,int>              $revisions_before Original revisions.
	 * @return array<string,int>
	 */
	private static function initialization_revisions_after( \WP_Term $term, array $entries, array $revisions_before ): array {
		$revisions_after    = Meta::sanitize_sequence_revisions( $revisions_before );
		$initialized_scopes = array();
		foreach ( $entries as $entry ) {
			$scope = sanitize_key( (string) ( $entry['scope'] ?? '' ) );
			if ( '' !== $scope ) {
				$initialized_scopes[ $scope ] = true;
			}
		}
		foreach ( array_keys( $initialized_scopes ) as $scope ) {
			$revisions_after[ $scope ] = max( 0, (int) ( $revisions_before[ $scope ] ?? 0 ) ) + 1;
		}
		if ( ! $entries ) {
			foreach ( Series::seasons( $term ) as $season ) {
				$scope = self::scope_key( $term, (string) ( $season['key'] ?? '' ) );
				if ( '' !== $scope ) {
					$revisions_after[ $scope ] = max( 1, (int) ( $revisions_before[ $scope ] ?? 0 ) + 1 );
				}
			}
			if ( ! Series::is_seasoned( $term ) ) {
				$revisions_after['series'] = max( 1, (int) ( $revisions_before['series'] ?? 0 ) + 1 );
			}
		}
		return Meta::sanitize_sequence_revisions( $revisions_after );
	}

	private static function rank_between_neighbors( array $ordered, int $index, array $ranks ): int {
		$previous = 0 < $index ? (int) $ranks[ $ordered[ $index - 1 ] ] : 0;
		$next     = $index + 1 < count( $ordered ) ? (int) $ranks[ $ordered[ $index + 1 ] ] : 0;
		if ( 0 < $previous && 0 < $next ) {
			return 1 < $next - $previous ? (int) floor( ( $previous + $next ) / 2 ) : 0;
		}
		if ( 0 < $next ) {
			return 1 < $next ? (int) floor( $next / 2 ) : 0;
		}
		if ( 0 < $previous ) {
			return $previous <= PHP_INT_MAX - self::RANK_STEP ? $previous + self::RANK_STEP : 0;
		}
		return self::RANK_STEP;
	}

	private static function set_revision( \WP_Term $term, string $season_key, int $revision ): int {
		$scope = self::scope_key( $term, $season_key );
		if ( '' === $scope ) {
			return 0;
		}
		return self::set_revision_for_key( $term, $scope, $revision );
	}

	private static function revision_for_key( \WP_Term $term, string $scope ): int {
		$scope = sanitize_key( $scope );
		if ( '' === $scope ) {
			return 0;
		}
		$revisions = Meta::sanitize_sequence_revisions( get_term_meta( $term->term_id, Schema::TERM_META_SEQUENCE_REVISIONS, true ) );
		return max( 0, (int) ( $revisions[ $scope ] ?? 0 ) );
	}

	private static function set_revision_for_key( \WP_Term $term, string $scope, int $revision ): int {
		$scope = sanitize_key( $scope );
		if ( '' === $scope ) {
			return 0;
		}
		$revisions = Meta::sanitize_sequence_revisions( get_term_meta( $term->term_id, Schema::TERM_META_SEQUENCE_REVISIONS, true ) );
		$revisions[ $scope ] = max( 0, $revision );
		update_term_meta( $term->term_id, Schema::TERM_META_SEQUENCE_REVISIONS, $revisions );
		return self::revision_for_key( $term, $scope );
	}

	private static function rollback_changes( array $changes ): void {
		foreach ( array_reverse( $changes ) as $change ) {
			$post_id = (int) $change['post_id'];
			if ( (int) $change['new_rank'] === self::rank( $post_id ) ) {
				self::restore_meta( $post_id, Schema::META_SEQUENCE_RANK, ! empty( $change['old_rank_exists'] ), (int) $change['old_rank'] );
			}
		}
	}

	/** @param int|string $value */
	private static function restore_meta( int $post_id, string $key, bool $existed, $value ): void {
		if ( $existed ) {
			update_post_meta( $post_id, $key, $value );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}

	/** @return array<string,mixed> */
	private static function manager_row( \WP_Post $post, \WP_Term $term ): array {
		$role   = sanitize_key( (string) get_post_meta( $post->ID, Schema::META_SERIES_ROLE, true ) );
		$status = get_post_status_object( $post->post_status );
		return array(
			'id'          => (int) $post->ID,
			'title'       => '' !== trim( (string) $post->post_title ) ? (string) $post->post_title : __( '(no title)', 'wp-title-layer' ),
			'status'      => (string) $post->post_status,
			'status_label'=> $status ? (string) $status->label : (string) $post->post_status,
			'date'        => (string) mysql2date( get_option( 'date_format' ), $post->post_date ),
			'ordinal'     => self::automatic_ordinal( (int) $post->ID, $term ),
			'label'       => (string) get_post_meta( $post->ID, Schema::META_SEQUENCE_LABEL, true ),
			'role'        => $role,
			'edit_url'    => current_user_can( 'edit_post', (int) $post->ID ) ? (string) get_edit_post_link( (int) $post->ID, 'raw' ) : '',
		);
	}

	/** @param int|\WP_Term|null $term */
	private static function term( $term ): ?\WP_Term {
		if ( $term instanceof \WP_Term ) {
			return $term->taxonomy === Series::claimed_taxonomy() ? $term : null;
		}
		$taxonomy = Series::claimed_taxonomy();
		$value    = '' !== $taxonomy ? get_term( absint( $term ), $taxonomy ) : null;
		return $value instanceof \WP_Term ? $value : null;
	}

	private static function init_option_name( int $term_id ): string {
		return self::INIT_OPTION_PREFIX . max( 0, $term_id );
	}

	private static function move_option_name( int $term_id, string $scope ): string {
		return self::MOVE_OPTION_PREFIX . max( 0, $term_id ) . '_' . substr( md5( $scope ), 0, 16 );
	}

	private static function write_option( string $name, array $value ): void {
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', false );
			return;
		}
		update_option( $name, $value, false );
	}
}
