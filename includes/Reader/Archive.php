<?php
/**
 * Series archive view-model service.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Sequence;
use WPTitleLayer\Core\Series;
use WPTitleLayer\Core\BookStructure;

defined( 'ABSPATH' ) || exit;

final class Archive {
	/** @var Visibility */
	private $visibility;

	public function __construct( ?Visibility $visibility = null ) {
		$this->visibility = $visibility ?: new Visibility();
	}

	/**
	 * Register the query guard separately from template rendering so the main
	 * archive query remains the single source of truth for pagination.
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'prepareQuery' ), 20 );
	}

	/**
	 * Limit the public Series archive to records this Reader can actually link.
	 * Filtering before SQL keeps found_posts and pagination in sync with the
	 * rendered list instead of producing password-protected holes in a page.
	 */
	public function prepareQuery( \WP_Query $query ): void {
		$taxonomy = Series::claimed_taxonomy();
		if ( '' === $taxonomy || is_admin() || ! $query->is_main_query() || ! $query->is_tax( $taxonomy ) || ! TemplateLoader::usesPluginArchiveTemplate( $query ) ) {
			return;
		}

		$post_types = $this->visibility->publicPostTypesForTaxonomy( $taxonomy );
		if ( empty( $post_types ) ) {
			// Never fall back to private/non-viewable attached object types.
			$query->set( 'post__in', array( 0 ) );
			return;
		}
		$query->set( 'post_type', $post_types );
		$query->set( 'post_status', 'publish' );
		$query->set( 'has_password', false );
	}

	/**
	 * Build a view model from the current page of the main archive query.
	 *
	 * Grouping only the current query page preserves WordPress pagination. A
	 * season may therefore continue on the next page, which is preferable to
	 * fetching the entire Series and silently defeating posts_per_page.
	 *
	 * @return array<string,mixed>
	 */
	public function context( $term, \WP_Query $query ): array {
		$taxonomy = Series::claimed_taxonomy();
		if ( '' === $taxonomy || ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy ) {
			return self::emptyContext();
		}

		$mode      = Series::mode( $term );
		$structure = Series::structure( $term );
		$posts     = array();
		$show_featured_images = Settings::archiveFeaturedImagesEnabled( $term );
		$show_excerpts = Settings::archiveExcerptsEnabled( $term );
		$active_season = null;
		$active_season_key = sanitize_key( (string) $query->get( Schema::QUERY_VAR_RESOLVED_SEASON ) );
		if ( '' === $active_season_key ) {
			$resolved = Sequence::resolve_season_token( $term, (string) $query->get( Schema::QUERY_VAR_SEASON ) );
			$active_season_key = is_array( $resolved ) ? (string) $resolved['season']['key'] : '';
		}
		if ( '' !== $active_season_key ) {
			$active_season = Series::season( $term, $active_season_key );
		}

		foreach ( (array) $query->posts as $post ) {
			$post_id = $post instanceof \WP_Post ? (int) $post->ID : absint( $post );
			if ( $this->visibility->isPublicPost( $post_id ) && has_term( (int) $term->term_id, $term->taxonomy, $post_id ) ) {
				$posts[] = $this->postContext( $post_id, $mode, $structure, $show_featured_images, $show_excerpts, $term );
			}
		}

		$context = array(
			'valid'          => true,
			'term'           => $term,
			'name'           => (string) $term->name,
			'series_url'     => Series::public_archive_url( $term ),
			'parent_category' => $this->parentCategoryContext( $term ),
			'status'         => Series::status( $term ),
			'status_label'   => Series::status_label( $term ),
			'active_season'  => $active_season,
			'description'    => term_description( (int) $term->term_id, $term->taxonomy ),
			'cover_id'       => $this->coverId( (int) get_term_meta( $term->term_id, Schema::TERM_META_COVER_ID, true ) ),
			'mode'           => $mode,
			'structure'      => $structure,
			'groups'         => $this->groupPosts( $term, $posts ),
			'pagination'     => $this->pagination( $query ),
			'found_posts'    => max( 0, (int) $query->found_posts ),
			'page'           => max( 1, (int) get_query_var( 'paged', 1 ) ),
			'max_pages'      => max( 0, (int) $query->max_num_pages ),
			'show_subtitles' => Settings::archiveSubtitlesEnabled( $term ),
			'show_excerpts'  => $show_excerpts,
			'show_featured_images' => $show_featured_images,
		);

		/**
		 * @param array<string,mixed> $context Archive context.
		 * @param \WP_Term            $term    Series term.
		 * @param \WP_Query           $query   Current archive query.
		 */
		$filtered = apply_filters( 'wptl_reader_archive_context', $context, $term, $query );

		return is_array( $filtered ) ? array_merge( $context, $filtered ) : $context;
	}

	/**
	 * Group current-page posts according to the term's flat/seasoned model.
	 *
	 * @param array<int,array<string,mixed>> $posts Post contexts in query order.
	 * @return array<int,array{key:string,label:string,sort:int,ordered:bool,show_sequence_labels?:bool,posts:array<int,array<string,mixed>>}>
	 */
	public function groupPosts( \WP_Term $term, array $posts ): array {
		if ( BookStructure::is_enabled( $term ) ) {
			$definitions = array();
			foreach ( BookStructure::tracks( $term ) as $track ) {
				$definitions[ (string) $track['key'] ] = $track;
			}
			$groups = array();
			foreach ( $posts as $post ) {
				$track_key = (string) ( $post['track'] ?? '' );
				if ( ! isset( $definitions[ $track_key ] ) ) {
					$track_key = 'unresolved';
					if ( ! isset( $groups[ $track_key ] ) ) {
						$groups[ $track_key ] = array(
							'key'     => $track_key,
							'label'   => __( 'Unresolved structure', 'wp-title-layer' ),
							'sort'    => PHP_INT_MAX,
							'role'    => Schema::ROLE_ARTICLE,
							'ordered' => Series::is_ordered( $term ),
							'posts'   => array(),
						);
					}
					$groups[ $track_key ]['posts'][] = $post;
					continue;
				}

				$definition = $definitions[ $track_key ];
				$scope      = (string) $definition['scope'];
				$role       = (string) $definition['role'];
				$season_key = (string) $definition['season_key'];

				// The manager keeps role-specific tracks, but the public archive
				// presents one natural chapter heading per season. Canonical query
				// order still places introductions, body articles, epilogues, and
				// appendices correctly inside the merged group.
				if ( Schema::SCOPE_SEASON === $scope ) {
					$group_key = 'season_' . $season_key;
					if ( ! isset( $groups[ $group_key ] ) ) {
						$season = Series::season( $term, $season_key );
						$groups[ $group_key ] = array(
							'key'                  => $group_key,
							'label'                => is_array( $season ) ? (string) ( $season['label'] ?? '' ) : '',
							'sort'                 => (int) array_search( $track_key, array_keys( $definitions ), true ),
							'role'                 => Schema::ROLE_ARTICLE,
							'ordered'              => Series::is_ordered( $term ),
							'show_sequence_labels' => true,
							'posts'                 => array(),
						);
					}
					$groups[ $group_key ]['posts'][] = $post;
					continue;
				}

				// A flat Series body remains one unlabeled public collection. Whole-
				// Series bookends become individual public sections so each can use
				// its editor-defined public label instead of exposing an internal role.
				if ( Schema::ROLE_ARTICLE === $role ) {
					$group_key = 'series';
					if ( ! isset( $groups[ $group_key ] ) ) {
						$groups[ $group_key ] = array(
							'key'                  => $group_key,
							'label'                => '',
							'sort'                 => (int) array_search( $track_key, array_keys( $definitions ), true ),
							'role'                 => $role,
							'ordered'              => Series::is_ordered( $term ),
							'show_sequence_labels' => true,
							'posts'                 => array(),
						);
					}
					$groups[ $group_key ]['posts'][] = $post;
					continue;
				}

				$post_id      = absint( $post['id'] ?? 0 );
				$group_key    = $track_key . '_entry_' . $post_id;
				$public_label = trim( (string) ( $post['sequence_label'] ?? '' ) );
				$groups[ $group_key ] = array(
					'key'                  => $group_key,
					'label'                => '' !== $public_label
						? sprintf(
							/* translators: %s: editor-defined public structure label, for example "Preface". */
							__( 'Series %s', 'wp-title-layer' ),
							$public_label
						)
						: __( 'Series', 'wp-title-layer' ),
					'sort'                 => (int) array_search( $track_key, array_keys( $definitions ), true ),
					'role'                 => $role,
					'ordered'              => true,
					'show_sequence_labels' => false,
					'posts'                 => array( $post ),
				);
			}
			return array_values( $groups );
		}

		if ( ! Series::is_seasoned( $term ) ) {
			return empty( $posts )
				? array()
				: array(
					array(
						'key'     => '',
						'label'   => '',
						'sort'    => 0,
						'ordered' => Series::is_ordered( $term ),
						'posts'   => array_values( $posts ),
					),
				);
		}

		$definitions = array();
		foreach ( Series::seasons( $term ) as $season ) {
			$definitions[ $season['key'] ] = $season;
		}

		$groups = array();
		foreach ( $posts as $post ) {
			$key = sanitize_key( (string) ( $post['season_key'] ?? '' ) );
			if ( ! isset( $groups[ $key ] ) ) {
				$definition = $definitions[ $key ] ?? null;
				$groups[ $key ] = array(
					'key'     => $key,
					'label'   => $definition
						? (string) $definition['label']
						: ( '' !== $key ? $key : __( 'Unassigned', 'wp-title-layer' ) ),
					'sort'    => $definition ? (int) $definition['sort'] : PHP_INT_MAX,
					'ordered' => Series::is_ordered( $term ),
					'posts'   => array(),
				);
			}
			$groups[ $key ]['posts'][] = $post;
		}

		$groups = array_values( $groups );
		usort(
			$groups,
			static function ( array $left, array $right ): int {
				if ( $left['sort'] !== $right['sort'] ) {
					return $left['sort'] <=> $right['sort'];
				}
				return strcmp( $left['key'], $right['key'] );
			}
		);

		return $groups;
	}

	/** @return array<string,mixed> */
	public function postContext( int $post_id, string $mode, string $structure, bool $include_featured_image = false, bool $include_excerpt = false, ?\WP_Term $term = null ): array {
		$subtitle = '';
		if ( metadata_exists( 'post', $post_id, Schema::META_SUBTITLE ) ) {
			$subtitle = (string) get_post_meta( $post_id, Schema::META_SUBTITLE, true );
		} elseif ( metadata_exists( 'post', $post_id, '_secondary_title' ) ) {
			$subtitle = (string) get_post_meta( $post_id, '_secondary_title', true );
			if ( 1 === preg_match( '/^field_[A-Za-z0-9_-]+$/', $subtitle ) ) {
				$subtitle = '';
			}
		}

		$position = null;
		if ( Schema::MODE_ORDERED === $mode ) {
			$term = $term instanceof \WP_Term ? $term : Series::get_primary_term( $post_id );
			if ( $term instanceof \WP_Term && Sequence::is_managed( $term ) ) {
				$ordinal  = Sequence::automatic_ordinal( $post_id, $term );
				$position = 0 < $ordinal ? $ordinal : null;
			} elseif ( metadata_exists( 'post', $post_id, Schema::META_SEQUENCE_POSITION ) ) {
				$position = max( 0, (int) get_post_meta( $post_id, Schema::META_SEQUENCE_POSITION, true ) );
			}
		}
		$label = (string) get_post_meta( $post_id, Schema::META_SEQUENCE_LABEL, true );
		if ( '' === trim( $label ) && null !== $position ) {
			$label = (string) $position;
		}

		$book_context = $term instanceof \WP_Term ? BookStructure::context( $post_id, $term ) : array();
		$role = (string) ( $book_context['role'] ?? BookStructure::role( $post_id ) );
		return array(
			'id'             => $post_id,
			'title'          => (string) get_post_field( 'post_title', $post_id, 'display' ),
			'url'            => (string) get_permalink( $post_id ),
			'subtitle'       => $subtitle,
			'excerpt'        => $include_excerpt
				? trim( wp_strip_all_tags( (string) get_the_excerpt( $post_id ), true ) )
				: '',
			'date'           => (string) get_the_date( '', $post_id ),
			'date_iso'       => (string) get_the_date( DATE_W3C, $post_id ),
			'season_key'     => Schema::STRUCTURE_SEASONED === $structure
				? sanitize_key( (string) get_post_meta( $post_id, Schema::META_SEASON_KEY, true ) )
				: '',
			'position'       => $position,
			'sequence_label' => $label,
			'role'           => $role,
			'scope'          => (string) ( $book_context['scope'] ?? '' ),
			'track'          => (string) ( $book_context['track'] ?? '' ),
			'featured_image_id' => $include_featured_image
				? $this->coverId( (int) get_post_thumbnail_id( $post_id ) )
				: 0,
		);
	}

	/** @return array{id:int,name:string,url:string}|null */
	private function parentCategoryContext( \WP_Term $term ): ?array {
		$category = Series::parent_category( $term );
		if ( ! $category instanceof \WP_Term ) {
			return null;
		}

		$url      = get_term_link( $category );
		$url      = is_wp_error( $url ) || ! is_string( $url ) ? '' : esc_url_raw( $url, array( 'http', 'https' ) );
		$taxonomy = get_taxonomy( 'category' );
		if ( ! $taxonomy instanceof \WP_Taxonomy || empty( $taxonomy->publicly_queryable ) ) {
			$url = '';
		}

		return array(
			'id'   => (int) $category->term_id,
			'name' => (string) $category->name,
			'url'  => $url,
		);
	}

	public function pagination( \WP_Query $query ): string {
		if ( 2 > (int) $query->max_num_pages ) {
			return '';
		}

		$big   = 999999999;
		$links = paginate_links(
			array(
				'base'      => str_replace( (string) $big, '%#%', get_pagenum_link( $big ) ),
				'format'    => '?paged=%#%',
				'current'   => max( 1, (int) get_query_var( 'paged', 1 ) ),
				'total'     => (int) $query->max_num_pages,
				'type'      => 'list',
				'prev_text' => '<span aria-hidden="true">&lsaquo;</span><span class="screen-reader-text">' . esc_html__( 'Previous page', 'wp-title-layer' ) . '</span>',
				'next_text' => '<span aria-hidden="true">&rsaquo;</span><span class="screen-reader-text">' . esc_html__( 'Next page', 'wp-title-layer' ) . '</span>',
			)
		);

		return is_string( $links ) ? $links : '';
	}

	public function coverId( int $attachment_id ): int {
		if ( 1 > $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return 0;
		}

		return $attachment_id;
	}

	/** @return array<string,mixed> */
	public static function emptyContext(): array {
		return array(
			'valid'          => false,
			'term'           => null,
			'name'           => '',
			'series_url'     => '',
			'parent_category' => null,
			'status'         => '',
			'status_label'   => '',
			'active_season'  => null,
			'description'    => '',
			'cover_id'       => 0,
			'mode'           => Schema::MODE_UNORDERED,
			'structure'      => Schema::STRUCTURE_FLAT,
			'groups'         => array(),
			'pagination'     => '',
			'found_posts'    => 0,
			'page'           => 1,
			'max_pages'      => 0,
			'show_subtitles' => false,
			'show_excerpts'  => false,
			'show_featured_images' => false,
		);
	}
}
