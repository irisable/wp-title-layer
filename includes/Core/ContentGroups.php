<?php
/** Optional editorial groups; ranks and reading order remain owned by Sequence. */
namespace WPTitleLayer\Core;

defined( 'ABSPATH' ) || exit;

final class ContentGroups {
	public const TAXONOMY = 'wptl_content_group';
	public const QUERY_VAR = 'wptl_group';
	private static $pending = array();

	public static function register_taxonomy(): void {
		register_taxonomy( self::TAXONOMY, array(), array(
			'public' => false, 'show_ui' => false, 'show_in_rest' => false,
			'rewrite' => false, 'query_var' => false, 'hierarchical' => false,
		) );
	}

	public static function register(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'meta_changed' ), 30, 3 );
		}
		add_action( 'set_object_terms', array( __CLASS__, 'terms_changed' ), 40, 4 );
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'series_structure_changed' ), 40, 3 );
		}
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 6 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ), 40 );
		add_filter( 'query_vars', static function ( $vars ) { $vars[] = self::QUERY_VAR; return $vars; } );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ), PHP_INT_MAX );
	}

	public static function meta_changed( $meta_id, $post_id, $key ): void {
		if ( in_array( $key, array( Schema::META_SERIES_GROUP, Schema::META_SEASON_KEY, Schema::META_SERIES_SCOPE, Schema::META_SERIES_ROLE ), true ) ) {
			self::$pending[ (int) $post_id ] = true;
		}
	}

	public static function terms_changed( $post_id, $terms, $tt_ids, $taxonomy ): void {
		if ( Series::claimed_taxonomy() === $taxonomy ) { self::$pending[ (int) $post_id ] = true; }
	}

	public static function flush(): void {
		$ids = array_keys( self::$pending );
		self::$pending = array();
		foreach ( $ids as $id ) { self::sync( $id ); }
	}

	/** Reconcile existing memberships when the editor changes flat/seasoned structure. */
	public static function series_structure_changed( $meta_id, $term_id, $key ): void {
		if ( Schema::TERM_META_SERIES_STRUCTURE !== $key || '' === Series::claimed_taxonomy() ) { return; }
		$ids = get_objects_in_term( (int) $term_id, Series::claimed_taxonomy() );
		if ( is_wp_error( $ids ) ) { return; }
		foreach ( $ids as $id ) {
			if ( '' !== (string) get_post_meta( (int) $id, Schema::META_SERIES_GROUP, true ) ) { self::$pending[ (int) $id ] = true; }
		}
	}

	/** Groups belong to one Series and one effective season (empty for Series-wide entries). */
	private static function scope( \WP_Term $series, string $season ): string {
		return $series->term_id . ':' . $season;
	}

	public static function post_scope( int $post_id, \WP_Term $series ): ?string {
		if ( ! Series::is_seasoned( $series ) ) { return ''; }
		$role = BookStructure::role( $post_id );
		if ( Schema::ROLE_ARTICLE !== $role && Schema::SCOPE_SERIES === get_post_meta( $post_id, Schema::META_SERIES_SCOPE, true ) ) { return ''; }
		$key = (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true );
		return Series::season( $series, $key ) ? $key : null;
	}

	private static function matches( \WP_Term $group, string $scope, string $label ): bool {
		return $scope === get_term_meta( $group->term_id, '_wptl_scope', true )
			&& ( $label === $group->name || in_array( $label, (array) get_term_meta( $group->term_id, '_wptl_aliases', true ), true ) );
	}

	/** Read only. A stale private ID never overrides changed text or scope. */
	public static function for_post( int $post_id, ?\WP_Term $series = null ): ?\WP_Term {
		$series = $series ?: Series::get_primary_term( $post_id );
		$label = trim( (string) get_post_meta( $post_id, Schema::META_SERIES_GROUP, true ) );
		if ( ! $series || '' === $label ) { return null; }
		$season = self::post_scope( $post_id, $series );
		if ( null === $season ) { return null; }
		$scope = self::scope( $series, $season );
		$group = get_term( (int) get_post_meta( $post_id, Schema::META_GROUP_ID, true ), self::TAXONOMY );
		if ( $group instanceof \WP_Term && self::matches( $group, $scope, $label ) ) { return $group; }
		return self::find( $series, $season, $label );
	}

	private static function find( \WP_Term $series, string $season, string $label ): ?\WP_Term {
		$scope = self::scope( $series, $season );
		// Original names keep their deterministic slug after a rename.
		$group = get_term_by( 'slug', 'g-' . hash( 'sha256', $scope . ':' . $label ), self::TAXONOMY );
		if ( $group instanceof \WP_Term && self::matches( $group, $scope, $label ) ) { return $group; }
		foreach ( self::groups( $series, $season ) as $candidate ) {
			if ( self::matches( $candidate, $scope, $label ) ) { return $candidate; }
		}
		return null;
	}

	public static function groups( \WP_Term $series, string $season ): array {
		$groups = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false,
			'meta_key' => '_wptl_scope', 'meta_value' => self::scope( $series, $season ), 'orderby' => 'name' ) );
		return is_wp_error( $groups ) ? array() : $groups;
	}

	public static function sync( int $post_id ): void {
		unset( self::$pending[ $post_id ] );
		if ( wp_is_post_revision( $post_id ) || ! get_post( $post_id ) ) { return; }
		$series = Series::get_primary_term( $post_id );
		$label = trim( (string) get_post_meta( $post_id, Schema::META_SERIES_GROUP, true ) );
		$season = $series ? self::post_scope( $post_id, $series ) : null;
		if ( ! $series || '' === $label || null === $season ) {
			delete_post_meta( $post_id, Schema::META_GROUP_ID );
			return;
		}
		$group = self::for_post( $post_id, $series );
		if ( ! $group ) {
			$scope = self::scope( $series, $season );
			$slug = 'g-' . hash( 'sha256', $scope . ':' . $label );
			$result = wp_insert_term( $label, self::TAXONOMY, array( 'slug' => $slug ) );
			if ( is_wp_error( $result ) ) {
				// Concurrent publishers may have inserted the same scoped name.
				$existing = get_term( (int) $result->get_error_data( 'term_exists' ), self::TAXONOMY );
				if ( ! $existing instanceof \WP_Term || $slug !== $existing->slug ) { return; }
				$result = array( 'term_id' => $existing->term_id );
			}
			update_term_meta( $result['term_id'], '_wptl_scope', $scope );
			update_term_meta( $result['term_id'], '_wptl_series', $series->term_id );
			update_term_meta( $result['term_id'], '_wptl_season', $season );
			$group = get_term( $result['term_id'], self::TAXONOMY );
		}
		if ( $group instanceof \WP_Term ) { update_post_meta( $post_id, Schema::META_GROUP_ID, $group->term_id ); }
	}

	public static function url( \WP_Term $group, \WP_Term $series ): string {
		$season = (string) get_term_meta( $group->term_id, '_wptl_season', true );
		$url = '' !== $season ? Series::public_season_archive_url( $series, $season ) : Series::public_archive_url( $series );
		return $url ? (string) add_query_arg( self::QUERY_VAR, $group->term_id, $url ) : '';
	}

	public static function filter_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( Series::claimed_taxonomy() ) ) { return; }
		$raw_token = $query->get( self::QUERY_VAR );
		$token = is_scalar( $raw_token ) ? (string) $raw_token : 'invalid';
		if ( '' === $token ) { return; }
		$series = $query->get_queried_object();
		$group = ctype_digit( $token ) ? get_term( (int) $token, self::TAXONOMY ) : null;
		$season = $group instanceof \WP_Term ? (string) get_term_meta( $group->term_id, '_wptl_season', true ) : '';
		$raw_season = $query->get( Schema::QUERY_VAR_SEASON );
		$season_token = is_scalar( $raw_season ) ? (string) $raw_season : 'invalid';
		$resolved = '' !== $season_token && $series instanceof \WP_Term ? Sequence::resolve_season_token( $series, $season_token ) : null;
		if ( ! $series instanceof \WP_Term || ! $group instanceof \WP_Term
			|| (int) $series->term_id !== (int) get_term_meta( $group->term_id, '_wptl_series', true )
			|| ( '' !== $season && ! Series::season( $series, $season ) )
			|| ( '' !== $season_token && ( ! is_array( $resolved ) || $season !== $resolved['season']['key'] ) ) ) {
			$query->set( 'post__in', array( 0 ) ); return;
		}
		$query->set( 'wptl_active_group', $group );
		if ( '' !== $season ) { $query->set( Schema::QUERY_VAR_RESOLVED_SEASON, $season ); }
		// AND with existing filters; never replace theme or WordPress restrictions.
		$meta = array( 'relation' => 'AND', array( 'key' => Schema::META_GROUP_ID, 'value' => $group->term_id, 'compare' => '=', 'type' => 'NUMERIC' ) );
		if ( $query->get( 'meta_query' ) ) { $meta[] = $query->get( 'meta_query' ); }
		if ( Series::is_seasoned( $series ) ) {
			$meta[] = '' !== $season
				? array( 'key' => Schema::META_SEASON_KEY, 'value' => $season )
				: array( 'key' => Schema::META_SERIES_SCOPE, 'value' => Schema::SCOPE_SERIES );
		}
		$query->set( 'meta_query', $meta );
	}

	public static function rest_routes(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
			add_action( 'rest_after_insert_' . $type, static function ( $post ) { self::sync( (int) $post->ID ); }, 110 );
			add_filter( 'rest_prepare_' . $type, static function ( $response, $post ) {
				$data = $response->get_data();
				if ( isset( $data['meta'] ) && array_key_exists( Schema::META_SERIES_GROUP, $data['meta'] ) ) {
					$group = self::for_post( (int) $post->ID );
					if ( $group ) { $data['meta'][ Schema::META_SERIES_GROUP ] = $group->name; $response->set_data( $data ); }
				}
				return $response;
			}, 110, 2 );
		}
		register_rest_route( 'wp-title-layer/v1', '/groups', array(
			'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'can_list' ),
			'callback' => static function ( $request ) {
				$series = get_term( (int) $request['series'], Series::claimed_taxonomy() );
				$season = sanitize_key( (string) $request['season'] );
				if ( ! $series instanceof \WP_Term ) { return array(); }
				return array_map( static function ( $group ) { return array( 'id' => $group->term_id, 'label' => $group->name, 'aliases' => array_values( array_filter( (array) get_term_meta( $group->term_id, '_wptl_aliases', true ), static function ( $value ) { return '' !== (string) $value; } ) ) ); }, self::groups( $series, $season ) );
			},
		) );
		register_rest_route( 'wp-title-layer/v1', '/groups/(?P<id>\d+)', array(
			'methods' => 'POST',
			'permission_callback' => static function () {
				$taxonomy = get_taxonomy( Series::claimed_taxonomy() );
				return $taxonomy && current_user_can( $taxonomy->cap->edit_terms );
			},
			'callback' => array( __CLASS__, 'rename' ),
			'args' => array( 'label' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
	}

	public static function can_list(): bool {
		$taxonomy = get_taxonomy( Series::claimed_taxonomy() );
		return $taxonomy && current_user_can( $taxonomy->cap->assign_terms );
	}

	public static function rename( \WP_REST_Request $request ) {
		$group = get_term( (int) $request['id'], self::TAXONOMY );
		$label = trim( (string) $request['label'] );
		if ( ! $group instanceof \WP_Term || '' === $label ) { return new \WP_Error( 'wptl_group_invalid', __( 'Choose a group and a nonempty name.', 'wp-title-layer' ), array( 'status' => 400 ) ); }
		$series = get_term( (int) get_term_meta( $group->term_id, '_wptl_series', true ), Series::claimed_taxonomy() );
		if ( ! $series instanceof \WP_Term ) { return new \WP_Error( 'wptl_group_invalid', __( 'Choose an existing Series.', 'wp-title-layer' ), array( 'status' => 400 ) ); }
		$existing = self::find( $series, (string) get_term_meta( $group->term_id, '_wptl_season', true ), $label );
		if ( $existing && $existing->term_id !== $group->term_id ) { return new \WP_Error( 'wptl_group_conflict', __( 'This group name is already in use.', 'wp-title-layer' ), array( 'status' => 409 ) ); }
		$aliases = (array) get_term_meta( $group->term_id, '_wptl_aliases', true );
		$aliases[] = $group->name;
		$result = wp_update_term( $group->term_id, self::TAXONOMY, array( 'name' => $label ) );
		if ( is_wp_error( $result ) ) { return $result; }
		update_term_meta( $group->term_id, '_wptl_aliases', array_values( array_unique( array_filter( $aliases, static function ( $value ) { return '' !== (string) $value; } ) ) ) );
		return array( 'id' => $group->term_id, 'label' => $label );
	}
}
