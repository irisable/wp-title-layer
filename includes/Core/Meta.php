<?php
/**
 * Registration and sanitization for canonical post and term metadata.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Core;

defined( 'ABSPATH' ) || exit;

final class Meta {
	public static function register(): void {
		self::register_post_meta();
		self::register_term_meta();
	}

	private static function register_post_meta(): void {
		self::register_post_field( Schema::META_SUBTITLE, 'string', [ self::class, 'sanitize_text' ], '' );
		self::register_post_field( Schema::META_SEASON_KEY, 'string', [ self::class, 'sanitize_key' ], '' );
		self::register_post_field( Schema::META_SEQUENCE_POSITION, 'integer', [ self::class, 'sanitize_nonnegative_integer' ], 0 );
		self::register_private_post_field( Schema::META_SEQUENCE_RANK, 'integer', [ self::class, 'sanitize_positive_integer' ], 0 );
		self::register_private_post_field( Schema::META_SEQUENCE_SOURCE_POSITION, 'string', [ self::class, 'sanitize_source_position' ], '' );
		self::register_post_field( Schema::META_SEQUENCE_LABEL, 'string', [ self::class, 'sanitize_text' ], '' );
		self::register_post_field( Schema::META_SERIES_ROLE, 'string', [ self::class, 'sanitize_role' ], '' );
		self::register_post_field( Schema::META_SERIES_SCOPE, 'string', [ self::class, 'sanitize_content_scope' ], '' );
		self::register_post_field( Schema::META_TEMPLATE_OVERRIDE, 'string', [ self::class, 'sanitize_key' ], '' );
		self::register_post_field( Schema::META_KICKER_OVERRIDE, 'string', [ self::class, 'sanitize_text' ], '' );
	}

	private static function register_private_post_field( string $key, string $type, callable $sanitize, $default ): void {
		register_post_meta(
			'',
			$key,
			[
				'type'              => $type,
				'single'            => true,
				'default'           => $default,
				'show_in_rest'      => false,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => [ self::class, 'authorize_post_meta' ],
			]
		);
	}

	private static function register_post_field( string $key, string $type, callable $sanitize, $default ): void {
		register_post_meta(
			'',
			$key,
			[
				'type'              => $type,
				'single'            => true,
				'default'           => $default,
				'show_in_rest'      => true,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => [ self::class, 'authorize_post_meta' ],
			]
		);
	}

	private static function register_term_meta(): void {
		$taxonomy = Series::claimed_taxonomy();
		if ( '' === $taxonomy ) {
			return;
		}

		self::register_term_field( $taxonomy, Schema::TERM_META_SERIES_MODE, 'string', [ self::class, 'sanitize_mode' ], Schema::MODE_UNORDERED );
		self::register_term_field( $taxonomy, Schema::TERM_META_SERIES_STATUS, 'string', [ self::class, 'sanitize_status' ], '' );
		self::register_term_field( $taxonomy, Schema::TERM_META_SERIES_STRUCTURE, 'string', [ self::class, 'sanitize_structure' ], Schema::STRUCTURE_FLAT );
		self::register_term_field( $taxonomy, Schema::TERM_META_NAVIGATION_SCOPE, 'string', [ self::class, 'sanitize_navigation_scope' ], Schema::NAVIGATION_SCOPE_SERIES );
		self::register_term_field( $taxonomy, Schema::TERM_META_ARCHIVE_SORT, 'string', [ self::class, 'sanitize_archive_sort' ], Schema::ARCHIVE_SORT_DATE_DESC );
		self::register_term_field( $taxonomy, Schema::TERM_META_DEFAULT_TEMPLATE, 'string', [ self::class, 'sanitize_key' ], '' );
		self::register_term_field( $taxonomy, Schema::TERM_META_SHORT_LABEL, 'string', [ self::class, 'sanitize_text' ], '' );
		self::register_term_field( $taxonomy, Schema::TERM_META_COVER_ID, 'integer', [ self::class, 'sanitize_nonnegative_integer' ], 0 );
		self::register_term_field( $taxonomy, Schema::TERM_META_ICON_ID, 'integer', [ self::class, 'sanitize_nonnegative_integer' ], 0 );
		self::register_term_field( $taxonomy, Schema::TERM_META_PARENT_CATEGORY_ID, 'integer', [ self::class, 'sanitize_nonnegative_integer' ], 0 );
		self::register_term_field( $taxonomy, Schema::TERM_META_ARCHIVE_LAYOUT, 'string', [ self::class, 'sanitize_archive_layout' ], Schema::ARCHIVE_LAYOUT_INHERIT );
		self::register_term_field( $taxonomy, Schema::TERM_META_ARCHIVE_SUBTITLES, 'string', [ self::class, 'sanitize_archive_visibility' ], Schema::ARCHIVE_VISIBILITY_INHERIT );
		self::register_term_field( $taxonomy, Schema::TERM_META_ARCHIVE_EXCERPTS, 'string', [ self::class, 'sanitize_archive_visibility' ], Schema::ARCHIVE_VISIBILITY_INHERIT );
		self::register_term_field( $taxonomy, Schema::TERM_META_ARCHIVE_FEATURED_IMAGES, 'string', [ self::class, 'sanitize_archive_visibility' ], Schema::ARCHIVE_VISIBILITY_INHERIT );
		self::register_term_field( $taxonomy, Schema::TERM_META_TITLE_ICON, 'string', [ self::class, 'sanitize_archive_visibility' ], Schema::ARCHIVE_VISIBILITY_INHERIT );
		self::register_private_term_field( $taxonomy, Schema::TERM_META_SEQUENCE_SCHEMA_VERSION, 'integer', [ self::class, 'sanitize_nonnegative_integer' ], 0 );
		self::register_private_term_field( $taxonomy, Schema::TERM_META_SEQUENCE_REVISIONS, 'array', [ self::class, 'sanitize_sequence_revisions' ], [] );
		self::register_private_term_field( $taxonomy, Schema::TERM_META_SEASON_ID_HIGHWATER, 'integer', [ self::class, 'sanitize_nonnegative_integer' ], 0 );
		self::register_private_term_field( $taxonomy, Schema::TERM_META_BOOK_STRUCTURE_VERSION, 'integer', [ self::class, 'sanitize_nonnegative_integer' ], 0 );

		register_term_meta(
			$taxonomy,
			Schema::TERM_META_SEASONS,
			[
				'type'              => 'array',
				'single'            => true,
				'default'           => [],
				'sanitize_callback' => [ self::class, 'sanitize_seasons' ],
				'auth_callback'     => [ self::class, 'authorize_term_meta' ],
				'show_in_rest'      => [
					'schema' => [
						'type'    => 'array',
						'default' => [],
						'items'   => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => [ 'key', 'label', 'sort' ],
							'properties'           => [
								'key'       => [ 'type' => 'string' ],
								'label'     => [ 'type' => 'string' ],
								'sort'      => [ 'type' => 'integer' ],
								'public_id' => [ 'type' => 'integer' ],
							],
						],
					],
				],
			]
		);
	}

	private static function register_private_term_field( string $taxonomy, string $key, string $type, callable $sanitize, $default ): void {
		register_term_meta(
			$taxonomy,
			$key,
			[
				'type'              => $type,
				'single'            => true,
				'default'           => $default,
				'show_in_rest'      => false,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => [ self::class, 'authorize_term_meta' ],
			]
		);
	}

	private static function register_term_field( string $taxonomy, string $key, string $type, callable $sanitize, $default ): void {
		register_term_meta(
			$taxonomy,
			$key,
			[
				'type'              => $type,
				'single'            => true,
				'default'           => $default,
				'show_in_rest'      => true,
				'sanitize_callback' => $sanitize,
				'auth_callback'     => [ self::class, 'authorize_term_meta' ],
			]
		);
	}

	public static function authorize_post_meta( $allowed, string $meta_key, int $post_id ): bool {
		return 0 < $post_id && current_user_can( 'edit_post', $post_id );
	}

	public static function authorize_term_meta( $allowed, string $meta_key, int $term_id ): bool {
		return 0 < $term_id && current_user_can( 'edit_term', $term_id );
	}

	public static function sanitize_text( $value ): string {
		return sanitize_text_field( (string) $value );
	}

	public static function sanitize_key( $value ): string {
		return sanitize_key( (string) $value );
	}

	public static function sanitize_nonnegative_integer( $value ): int {
		return max( 0, (int) $value );
	}

	public static function sanitize_positive_integer( $value ): int {
		return max( 0, (int) $value );
	}

	public static function sanitize_source_position( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return 128 >= strlen( $value ) ? $value : substr( $value, 0, 128 );
	}

	/** @return array<string,int> */
	public static function sanitize_sequence_revisions( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$revisions = [];
		foreach ( $value as $scope => $revision ) {
			$scope = sanitize_key( (string) $scope );
			if ( '' !== $scope ) {
				$revisions[ $scope ] = max( 0, (int) $revision );
			}
		}
		return $revisions;
	}

	public static function sanitize_mode( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::modes(), true ) ? $value : Schema::MODE_UNORDERED;
	}

	public static function sanitize_status( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::statuses(), true ) ? $value : '';
	}

	public static function sanitize_structure( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::structures(), true ) ? $value : Schema::STRUCTURE_FLAT;
	}

	public static function sanitize_navigation_scope( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::navigation_scopes(), true ) ? $value : Schema::NAVIGATION_SCOPE_SERIES;
	}

	public static function sanitize_archive_sort( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::archive_sorts(), true ) ? $value : Schema::ARCHIVE_SORT_DATE_DESC;
	}

	public static function sanitize_archive_layout( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::archive_layouts(), true ) ? $value : Schema::ARCHIVE_LAYOUT_INHERIT;
	}

	public static function sanitize_archive_visibility( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::archive_visibility_overrides(), true ) ? $value : Schema::ARCHIVE_VISIBILITY_INHERIT;
	}

	public static function sanitize_role( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::roles(), true ) ? $value : '';
	}

	public static function sanitize_content_scope( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Schema::content_scopes(), true ) ? $value : '';
	}

	/**
	 * @param mixed $value Raw REST or metadata value.
	 * @return array<int,array{key:string,label:string,sort:int,public_id?:int}>
	 */
	public static function sanitize_seasons( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$seasons = [];
		$seen    = [];
		foreach ( array_values( $value ) as $index => $season ) {
			if ( ! is_array( $season ) ) {
				continue;
			}

			$key = sanitize_key( isset( $season['key'] ) ? (string) $season['key'] : '' );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$normalized = [
				'key'   => $key,
				'label' => sanitize_text_field( isset( $season['label'] ) ? (string) $season['label'] : $key ),
				'sort'  => isset( $season['sort'] ) ? (int) $season['sort'] : ( $index + 1 ) * 10,
			];
			$public_id = isset( $season['public_id'] ) ? max( 0, (int) $season['public_id'] ) : 0;
			if ( 0 < $public_id ) {
				$normalized['public_id'] = $public_id;
			}
			$seasons[] = $normalized;
		}

		usort(
			$seasons,
			static function ( array $left, array $right ): int {
				return $left['sort'] === $right['sort']
					? strcmp( $left['key'], $right['key'] )
					: $left['sort'] <=> $right['sort'];
			}
		);

		return array_values( $seasons );
	}
}
