<?php
/**
 * Runtime bridge that appends new or scope-changed managed articles.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Core;

defined( 'ABSPATH' ) || exit;

final class SequenceRuntime {
	/** @var array<int,bool> */
	private static $pending = array();

	/** @var array<string,array{term_id:int,season_key:string,track_key:string}> */
	private static $pending_revisions = array();

	/** @var array<string,bool> Canonically equivalent metadata edits to ignore after write. */
	private static $unchanged_context_meta = array();

	/** @var bool */
	private static $flushing = false;

	/** @var bool */
	private static $registered = false;

	/** @var bool */
	private static $rest_registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'set_object_terms', array( __CLASS__, 'terms_changed' ), 30, 6 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'before_meta_update' ), 10, 5 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'before_meta_delete' ), 10, 5 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'meta_deleted' ), 10, 4 );
		add_action( 'save_post', array( __CLASS__, 'post_saved' ), 100, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleting' ), 20, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_hooks' ), 30 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
	}

	public static function register_rest_hooks(): void {
		if ( self::$rest_registered ) {
			return;
		}
		self::$rest_registered = true;
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $post_type ) {
			add_action( 'rest_after_insert_' . $post_type, array( __CLASS__, 'rest_post_saved' ), 100, 3 );
		}
	}

	public static function post_saved( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status || 'trash' === $post->post_status ) {
			return;
		}
		self::queue( $post_id, false );
	}

	public static function rest_post_saved( \WP_Post $post, \WP_REST_Request $request, bool $creating ): void {
		unset( $request, $creating );
		self::queue( (int) $post->ID, false );
		self::flush_post( (int) $post->ID );
	}

	public static function terms_changed( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $terms, $append );
		if ( $taxonomy !== Series::claimed_taxonomy() ) {
			return;
		}
		$new_ids = self::term_ids_from_taxonomy_ids( $tt_ids, $taxonomy );
		$old_ids = self::term_ids_from_taxonomy_ids( $old_tt_ids, $taxonomy );
		if ( $new_ids !== $old_ids ) {
			$season_key = sanitize_key( (string) get_post_meta( $object_id, Schema::META_SEASON_KEY, true ) );
			foreach ( $old_ids as $old_term_id ) {
				$old_term = get_term( $old_term_id, $taxonomy );
				if ( $old_term instanceof \WP_Term && BookStructure::is_enabled( $old_term ) ) {
					$context = BookStructure::context( $object_id, $old_term );
					self::invalidate_scope( $old_term_id, (string) $context['season_key'], (string) $context['track'] );
				} else {
					self::invalidate_scope( $old_term_id, $season_key );
				}
			}
			self::queue( $object_id, true );
		}
	}

	/** Capture the old season before WordPress replaces it. */
	public static function before_meta_update( $check, int $post_id, string $meta_key, $meta_value, $previous_value ) {
		unset( $previous_value );
		if ( in_array( $meta_key, array( Schema::META_SEASON_KEY, Schema::META_SERIES_ROLE, Schema::META_SERIES_SCOPE ), true ) ) {
			$old_value = sanitize_key( (string) get_post_meta( $post_id, $meta_key, true ) );
			$new_value = sanitize_key( is_scalar( $meta_value ) ? (string) $meta_value : '' );
			if ( $old_value !== $new_value ) {
				if ( self::context_change_is_equivalent( $post_id, $meta_key, $new_value ) ) {
					self::$unchanged_context_meta[ self::context_meta_key( $post_id, $meta_key ) ] = true;
				} else {
					self::invalidate_post_context( $post_id );
				}
			}
		}
		return $check;
	}

	/** Capture the old season before WordPress removes it. */
	public static function before_meta_delete( $check, int $post_id, string $meta_key, $meta_value, bool $delete_all ) {
		unset( $meta_value, $delete_all );
		if ( in_array( $meta_key, array( Schema::META_SEASON_KEY, Schema::META_SERIES_ROLE, Schema::META_SERIES_SCOPE ), true ) && metadata_exists( 'post', $post_id, $meta_key ) ) {
			if ( self::context_change_is_equivalent( $post_id, $meta_key, '' ) ) {
				self::$unchanged_context_meta[ self::context_meta_key( $post_id, $meta_key ) ] = true;
			} else {
				self::invalidate_post_context( $post_id );
			}
		}
		return $check;
	}

	/** @param mixed $meta_value */
	public static function meta_changed( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_id );
		if ( in_array( $meta_key, array( Schema::META_SEASON_KEY, Schema::META_SERIES_ROLE, Schema::META_SERIES_SCOPE ), true ) ) {
			$change_key = self::context_meta_key( $post_id, $meta_key );
			if ( ! empty( self::$unchanged_context_meta[ $change_key ] ) ) {
				unset( self::$unchanged_context_meta[ $change_key ] );
				return;
			}
			if ( 'added_post_meta' === current_filter() && self::context_change_is_equivalent( $post_id, $meta_key, '', true ) ) {
				return;
			}
			$term = Series::get_primary_term( $post_id );
			$force_append = Schema::META_SEASON_KEY === $meta_key || ( $term instanceof \WP_Term && BookStructure::is_enabled( $term ) );
			self::queue( $post_id, $force_append );
		}
	}

	/** @param mixed $meta_value */
	public static function meta_deleted( array $meta_ids, int $post_id, string $meta_key, $meta_value ): void {
		unset( $meta_ids, $meta_value );
		if ( in_array( $meta_key, array( Schema::META_SEASON_KEY, Schema::META_SERIES_ROLE, Schema::META_SERIES_SCOPE ), true ) ) {
			$change_key = self::context_meta_key( $post_id, $meta_key );
			if ( ! empty( self::$unchanged_context_meta[ $change_key ] ) ) {
				unset( self::$unchanged_context_meta[ $change_key ] );
				return;
			}
			$term = Series::get_primary_term( $post_id );
			$force_append = Schema::META_SEASON_KEY === $meta_key || ( $term instanceof \WP_Term && BookStructure::is_enabled( $term ) );
			self::queue( $post_id, $force_append );
		}
	}

	public static function status_changed( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		$old_editorial = self::is_editorial_status( $old_status );
		$new_editorial = self::is_editorial_status( $new_status );
		if ( $old_editorial && ! $new_editorial ) {
			self::invalidate_post_context( (int) $post->ID );
		} elseif ( ! $old_editorial && $new_editorial ) {
			self::queue( (int) $post->ID, true );
		}
	}

	public static function post_deleting( int $post_id, \WP_Post $post ): void {
		unset( $post );
		self::invalidate_post_context( $post_id );
	}

	public static function queue( int $post_id, bool $force_append ): void {
		if ( 0 < $post_id ) {
			Sequence::invalidate_runtime_cache();
			self::$pending[ $post_id ] = $force_append || ! empty( self::$pending[ $post_id ] );
		}
	}

	public static function flush(): void {
		if ( self::$flushing || ( ! self::$pending && ! self::$pending_revisions ) ) {
			return;
		}
		self::$flushing = true;
		foreach ( self::$pending_revisions as $revision ) {
			Sequence::touch_revision( (int) $revision['term_id'], (string) $revision['season_key'], (string) $revision['track_key'] );
		}
		self::$pending_revisions = array();
		foreach ( array_keys( self::$pending ) as $post_id ) {
			self::flush_post( (int) $post_id );
		}
		self::$flushing = false;
	}

	private static function invalidate_post_context( int $post_id ): void {
		$term = Series::get_primary_term( $post_id );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		if ( BookStructure::is_enabled( $term ) ) {
			$context = BookStructure::context( $post_id, $term );
			$track_key = ! empty( $context['valid'] ) ? (string) $context['track'] : '';
			if ( '' !== $track_key ) {
				self::invalidate_scope( (int) $term->term_id, (string) $context['season_key'], $track_key );
				return;
			}
		}
		self::invalidate_scope( (int) $term->term_id, sanitize_key( (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) ) );
	}

	private static function context_change_is_equivalent( int $post_id, string $meta_key, string $candidate_value, bool $candidate_is_old = false ): bool {
		$term = Series::get_primary_term( $post_id );
		if ( ! $term instanceof \WP_Term || ! BookStructure::is_enabled( $term ) ) {
			return false;
		}
		$current = BookStructure::context( $post_id, $term );
		$candidate = BookStructure::context( $post_id, $term, array( $meta_key => $candidate_value ) );
		$old = $candidate_is_old ? $candidate : $current;
		$new = $candidate_is_old ? $current : $candidate;
		return ! empty( $old['valid'] )
			&& empty( $old['ambiguous'] )
			&& ! empty( $new['valid'] )
			&& empty( $new['ambiguous'] )
			&& '' !== (string) $old['track']
			&& (string) $old['track'] === (string) $new['track'];
	}

	private static function context_meta_key( int $post_id, string $meta_key ): string {
		return $post_id . ':' . $meta_key;
	}

	private static function invalidate_scope( int $term_id, string $season_key, string $track_key = '' ): void {
		$term = get_term( $term_id, Series::claimed_taxonomy() );
		if ( ! $term instanceof \WP_Term || ( ! Sequence::is_managed( $term ) && ! BookStructure::is_enabled( $term ) ) ) {
			return;
		}
		$season_key = Series::is_seasoned( $term ) ? sanitize_key( $season_key ) : '';
		$track_key = sanitize_key( $track_key );
		$scope      = '' !== $track_key ? $track_key : Sequence::scope_key( $term, $season_key );
		if ( '' !== $scope ) {
			Sequence::invalidate_runtime_cache();
			self::$pending_revisions[ $term_id . ':' . $scope ] = array( 'term_id' => $term_id, 'season_key' => $season_key, 'track_key' => $track_key );
		}
	}

	private static function is_editorial_status( string $status ): bool {
		$status = sanitize_key( $status );
		return '' !== $status
			&& ! in_array( $status, array( 'trash', 'auto-draft', 'inherit' ), true )
			&& in_array( $status, array_values( get_post_stati( array( 'internal' => false ), 'names' ) ), true );
	}

	private static function flush_post( int $post_id ): void {
		if ( ! array_key_exists( $post_id, self::$pending ) ) {
			return;
		}
		$force = (bool) self::$pending[ $post_id ];
		unset( self::$pending[ $post_id ] );
		$result = Sequence::ensure_post_rank( $post_id, $force );
		if ( is_wp_error( $result ) ) {
			do_action( 'wptl_sequence_append_failed', $post_id, $result );
		}
	}

	/** @return int[] */
	private static function term_ids_from_taxonomy_ids( array $term_taxonomy_ids, string $taxonomy ): array {
		$term_ids = array();
		foreach ( array_filter( array_map( 'absint', $term_taxonomy_ids ) ) as $term_taxonomy_id ) {
			$term = get_term_by( 'term_taxonomy_id', $term_taxonomy_id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}
		sort( $term_ids, SORT_NUMERIC );
		return array_values( array_unique( $term_ids ) );
	}
}
