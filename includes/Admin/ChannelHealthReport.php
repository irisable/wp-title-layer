<?php
/**
 * Read-only landing-page and title-channel reporting.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Admin;

use WPTitleLayer\Core\Series;
use WPTitleLayer\Presentation\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects public entry points and SEO-provider settings without changing them.
 */
final class ChannelHealthReport {
	private const RANK_MATH_TITLES_OPTION = 'rank-math-options-titles';
	private const RANK_MATH_SITEMAP_OPTION = 'rank-math-options-sitemap';
	private const RANK_MATH_MODULES_OPTION = 'rank_math_modules';

	/** @var \wpdb */
	private $db;

	/** @param \wpdb|null $database Optional database dependency for tests. */
	public function __construct( $database = null ) {
		global $wpdb;
		$this->db = $database instanceof \wpdb ? $database : $wpdb;
	}

	/**
	 * Return one bounded page of Series definitions.
	 *
	 * @return array<string,mixed>
	 */
	public function terms_page( int $page = 1, int $per_page = 20 ): array {
		$taxonomy = Series::claimed_taxonomy();
		$page      = max( 1, $page );
		$per_page  = min( 50, max( 1, $per_page ) );
		if ( '' === $taxonomy ) {
			return array(
				'valid'       => false,
				'taxonomy'    => '',
				'terms'       => array(),
				'total'       => 0,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => 0,
			);
		}

		$count       = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		$total       = is_wp_error( $count ) ? 0 : max( 0, (int) $count );
		$total_pages = 0 < $total ? (int) ceil( $total / $per_page ) : 0;
		if ( 0 < $total_pages && $page > $total_pages ) {
			$page = $total_pages;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
			)
		);

		return array(
			'valid'       => true,
			'taxonomy'    => $taxonomy,
			'terms'       => is_wp_error( $terms ) ? array() : array_values( $terms ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Build site-level title-channel facts.
	 *
	 * @return array<string,mixed>
	 */
	public function overview(): array {
		$taxonomy = Series::claimed_taxonomy();
		$provider = self::provider_state();
		$object   = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : null;
		$object_types = $object instanceof \WP_Taxonomy ? (array) $object->object_type : array();
		$post_type   = $object_types ? sanitize_key( (string) reset( $object_types ) ) : 'post';

		return array(
			'valid'        => '' !== $taxonomy && $object instanceof \WP_Taxonomy,
			'taxonomy'     => $taxonomy,
			'post_type'    => $post_type,
			'provider'     => $provider,
			'visible'      => self::visible_title_channel(),
			'rank_math'    => self::rank_math_overview( $taxonomy, $post_type, 'rank_math' === (string) ( $provider['state'] ?? '' ) ),
			'settings_urls'=> array(
				'titles'  => add_query_arg( 'page', 'rank-math-options-titles', admin_url( 'admin.php' ) ),
				'sitemap' => add_query_arg( 'page', 'rank-math-options-sitemap', admin_url( 'admin.php' ) ),
			),
		);
	}

	/**
	 * Inspect one Series, its duplicate Category entry points, and its channels.
	 *
	 * @return array<string,mixed>
	 */
	public function report( int $term_id, int $page = 1, int $per_page = 50 ): array {
		$taxonomy = Series::claimed_taxonomy();
		$term     = '' !== $taxonomy ? get_term( $term_id, $taxonomy ) : null;
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		if ( ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy ) {
			return array(
				'valid'       => false,
				'term'        => null,
				'overlaps'    => array(),
				'overlap_total'=> 0,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => 0,
			);
		}

		$overview = $this->overview();
		$channels = $this->term_channels( $term, $overview );
		$overlaps = $this->category_overlaps( $term, $page, $per_page, $overview, $channels );

		return array_merge(
			array(
				'valid'   => true,
				'term'    => $term,
				'overview'=> $overview,
				'channels'=> $channels,
				'article_overrides' => $this->article_override_counts( $term ),
			),
			$overlaps
		);
	}

	/**
	 * Detect the active owner of SEO and social metadata.
	 *
	 * Stored options alone are deliberately not treated as an active provider.
	 *
	 * @return array<string,mixed>
	 */
	public static function provider_state(): array {
		$providers = array();
		if ( defined( 'RANK_MATH_VERSION' ) || function_exists( 'rank_math' ) || class_exists( '\\RankMath\\Helper' ) ) {
			$providers['rank_math'] = 'Rank Math';
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( '\\WPSEO_Options' ) ) {
			$providers['yoast'] = 'Yoast SEO';
		}
		if ( defined( 'AIOSEO_VERSION' ) || class_exists( '\\AIOSEO\\Plugin\\Common\\Main' ) ) {
			$providers['aioseo'] = 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) ) {
			$providers['seopress'] = 'SEOPress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) ) {
			$providers['the_seo_framework'] = 'The SEO Framework';
		}
		if ( defined( 'SLIM_SEO_VERSION' ) || class_exists( '\\SlimSEO\\Plugin' ) ) {
			$providers['slim_seo'] = 'Slim SEO';
		}

		/**
		 * Add an active SEO provider that owns title/canonical/social metadata.
		 *
		 * @param array<string,string> $providers Provider key => display label.
		 */
		$filtered = apply_filters( 'wptl_channel_health_providers', $providers );
		$providers = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $key => $label ) {
			$key   = is_scalar( $key ) ? sanitize_key( (string) $key ) : '';
			$label = is_scalar( $label ) ? sanitize_text_field( (string) $label ) : '';
			if ( '' !== $key && '' !== $label ) {
				$providers[ $key ] = $label;
			}
		}

		$count = count( $providers );
		$state = 'none';
		if ( 1 === $count && isset( $providers['rank_math'] ) ) {
			$state = 'rank_math';
		} elseif ( 1 === $count ) {
			$state = 'other_provider';
		} elseif ( 1 < $count ) {
			$state = 'multiple_providers';
		}

		return array(
			'state'            => $state,
			'providers'        => $providers,
			'labels'           => array_values( $providers ),
			'rank_math_active' => isset( $providers['rank_math'] ),
		);
	}

	/** @return array<string,mixed> */
	private static function visible_title_channel(): array {
		$mode = 'manual';
		try {
			$settings = class_exists( SettingsPage::class ) ? SettingsPage::presentationSettings() : array();
			$mode     = sanitize_key( (string) ( $settings['display_mode'] ?? 'manual' ) );
		} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$mode = 'unavailable';
		}

		$labels = array(
			'manual'              => __( 'Manual placement', 'wp-title-layer' ),
			'replace-theme-title' => __( 'Verified theme title takeover', 'wp-title-layer' ),
			'auto-prepend'        => __( 'Legacy body insertion', 'wp-title-layer' ),
			'unavailable'         => __( 'Unavailable', 'wp-title-layer' ),
		);

		return array(
			'mode'  => $mode,
			'label' => $labels[ $mode ] ?? $labels['manual'],
			'owner' => __( 'WP Title Layer and the active theme', 'wp-title-layer' ),
		);
	}

	/** @return array<string,mixed> */
	private static function rank_math_overview( string $taxonomy, string $post_type, bool $active ): array {
		$titles  = get_option( self::RANK_MATH_TITLES_OPTION, array() );
		$sitemap = get_option( self::RANK_MATH_SITEMAP_OPTION, array() );
		$modules = get_option( self::RANK_MATH_MODULES_OPTION, array() );
		$titles  = is_array( $titles ) ? $titles : array();
		$sitemap = is_array( $sitemap ) ? $sitemap : array();

		$tax_title_key       = 'tax_' . $taxonomy . '_title';
		$tax_description_key = 'tax_' . $taxonomy . '_description';
		$tax_custom_key      = 'tax_' . $taxonomy . '_custom_robots';
		$tax_robots_key      = 'tax_' . $taxonomy . '_robots';
		$tax_sitemap_key     = 'tax_' . $taxonomy . '_sitemap';
		$post_title_key      = 'pt_' . $post_type . '_title';
		$title_configured    = array_key_exists( $tax_title_key, $titles );
		$description_configured = array_key_exists( $tax_description_key, $titles );
		$post_configured     = array_key_exists( $post_title_key, $titles );
		$tax_title           = self::scalar_text( $titles[ $tax_title_key ] ?? '%term% Archives %page% %sep% %sitename%' );
		$tax_description     = self::scalar_text( $titles[ $tax_description_key ] ?? '%term_description%' );
		$post_title          = self::scalar_text( $titles[ $post_title_key ] ?? '%title% %page% %sep% %sitename%' );
		$tax_custom          = self::truthy( $titles[ $tax_custom_key ] ?? false );
		$tax_robots          = self::robots( $titles[ $tax_robots_key ] ?? array() );
		$global_robots       = self::robots( $titles['robots_global'] ?? array( 'index' ) );
		$effective_robots    = $tax_custom && $tax_robots ? $tax_robots : ( $global_robots ?: array( 'index' ) );

		return array(
			'active' => $active,
			'taxonomy_title' => array(
				'configured' => $title_configured,
				'value'      => $tax_title,
				'source'     => $title_configured ? 'taxonomy_option' : 'provider_default',
				'has_series_variable' => self::contains_variable( $tax_title, array( 'wptl_series', 'term' ) ),
			),
			'taxonomy_description' => array(
				'configured' => $description_configured,
				'value'      => $tax_description,
				'source'     => $description_configured ? 'taxonomy_option' : 'provider_default',
			),
			'robots' => array(
				'custom'    => $tax_custom,
				'values'    => $effective_robots,
				'source'    => $tax_custom && $tax_robots ? 'taxonomy_option' : 'global',
				'indexable' => ! in_array( 'noindex', $effective_robots, true ),
			),
			'sitemap' => array(
				'module_active' => in_array( 'sitemap', (array) $modules, true ),
				'configured'    => array_key_exists( $tax_sitemap_key, $sitemap ),
				'enabled'       => self::truthy( $sitemap[ $tax_sitemap_key ] ?? false ),
				'excluded_terms'=> self::integer_list( $sitemap['exclude_terms'] ?? array() ),
			),
			'article_title' => array(
				'configured' => $post_configured,
				'value'      => $post_title,
				'source'     => $post_configured ? 'post_type_option' : 'provider_default',
				'policy'     => self::title_layer_policy( $post_title ),
			),
			'social_fallback' => __( 'Rank Math social titles inherit the SEO title unless an object has its own Facebook or Twitter title.', 'wp-title-layer' ),
		);
	}

	/**
	 * @param \WP_Term          $term     Selected Series.
	 * @param array<string,mixed> $overview Site overview.
	 * @return array<string,mixed>
	 */
	private function term_channels( \WP_Term $term, array $overview ): array {
		$rank_math = (array) ( $overview['rank_math'] ?? array() );
		$active    = ! empty( $rank_math['active'] );
		$url       = Series::public_archive_url( $term );
		$title_override       = self::term_meta_text( $term->term_id, 'rank_math_title' );
		$description_override = self::term_meta_text( $term->term_id, 'rank_math_description' );
		$canonical_override   = self::term_meta_text( $term->term_id, 'rank_math_canonical_url' );
		$facebook_override    = self::term_meta_text( $term->term_id, 'rank_math_facebook_title' );
		$twitter_override     = self::term_meta_text( $term->term_id, 'rank_math_twitter_title' );
		$robots_override      = self::robots( get_term_meta( $term->term_id, 'rank_math_robots', true ) );
		$taxonomy_robots      = (array) ( $rank_math['robots'] ?? array() );
		$sitemap              = (array) ( $rank_math['sitemap'] ?? array() );
		$canonical_state      = 'unverified';
		$canonical_value      = '';
		if ( '' !== $canonical_override ) {
			$canonical_value = self::http_url( $canonical_override );
			$canonical_state = '' !== $canonical_value ? 'explicit' : 'invalid_override';
		} elseif ( $active && '' !== $url ) {
			$canonical_state = 'provider_self';
			$canonical_value = $url;
		}

		$title_fallback       = (array) ( $rank_math['taxonomy_title'] ?? array() );
		$description_fallback = (array) ( $rank_math['taxonomy_description'] ?? array() );
		$effective_robots     = $robots_override ?: (array) ( $taxonomy_robots['values'] ?? array( 'index' ) );

		return array(
			'visible' => array(
				'value'  => trim( wp_strip_all_tags( (string) $term->name, true ) ),
				'source' => 'wordpress_term',
				'url'    => $url,
			),
			'search_title' => array(
				'value'  => '' !== $title_override ? $title_override : (string) ( $title_fallback['value'] ?? '' ),
				'source' => '' !== $title_override ? 'term_override' : (string) ( $title_fallback['source'] ?? 'unverified' ),
				'policy' => self::series_title_policy( '' !== $title_override ? $title_override : (string) ( $title_fallback['value'] ?? '' ) ),
			),
			'description' => array(
				'value'  => '' !== $description_override ? $description_override : (string) ( $description_fallback['value'] ?? '' ),
				'source' => '' !== $description_override ? 'term_override' : (string) ( $description_fallback['source'] ?? 'unverified' ),
			),
			'canonical' => array(
				'state' => $canonical_state,
				'value' => $canonical_value,
			),
			'robots' => array(
				'values'    => $effective_robots,
				'source'    => $robots_override ? 'term_override' : (string) ( $taxonomy_robots['source'] ?? 'unverified' ),
				'indexable' => ! in_array( 'noindex', $effective_robots, true ),
			),
			'sitemap' => array(
				'module_active' => ! empty( $sitemap['module_active'] ),
				'taxonomy_enabled' => ! empty( $sitemap['enabled'] ),
				'term_excluded' => in_array( (int) $term->term_id, (array) ( $sitemap['excluded_terms'] ?? array() ), true ),
				'included'      => ! empty( $sitemap['module_active'] ) && ! empty( $sitemap['enabled'] ) && ! in_array( (int) $term->term_id, (array) ( $sitemap['excluded_terms'] ?? array() ), true ),
			),
			'facebook' => array(
				'value'  => $facebook_override,
				'source' => '' !== $facebook_override ? 'term_override' : 'inherits_search_title',
				'policy' => self::title_layer_policy( $facebook_override ),
			),
			'twitter' => array(
				'value'  => $twitter_override,
				'source' => '' !== $twitter_override ? 'term_override' : 'inherits_search_title',
				'policy' => self::title_layer_policy( $twitter_override ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function category_overlaps( \WP_Term $term, int $page, int $per_page, array $overview, array $channels ): array {
		$post_types = $this->eligible_post_types( $term->taxonomy );
		if ( ! $post_types || ! taxonomy_exists( 'category' ) ) {
			return array(
				'series_count' => 0,
				'overlaps' => array(),
				'overlap_total' => 0,
				'exact_count' => 0,
				'partial_count' => 0,
				'page' => $page,
				'per_page' => $per_page,
				'total_pages' => 0,
			);
		}

		$series_count = $this->eligible_term_count( $term->taxonomy, (int) $term->term_id, $post_types );
		$total         = $this->overlap_term_count( $term, $post_types );
		$total_pages   = 0 < $total ? (int) ceil( $total / $per_page ) : 0;
		if ( 0 < $total_pages && $page > $total_pages ) {
			$page = $total_pages;
		}
		$rows = $this->overlap_rows( $term, $post_types, $page, $per_page );
		$category_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'term_id' ) ) ) );
		$category_counts = $this->eligible_category_counts( $category_ids, $post_types );
		if ( $category_ids ) {
			update_meta_cache( 'term', $category_ids );
		}
		$exact = 0;
		$rank_math_authoritative = ! empty( $overview['rank_math']['active'] );
		$series_url              = Series::public_archive_url( $term );
		$series_canonical        = (array) ( $channels['canonical'] ?? array() );
		foreach ( $rows as &$row ) {
			$category_count = (int) ( $category_counts[ (int) $row['term_id'] ] ?? 0 );
			$row['category_count'] = $category_count;
			$row['state'] = 0 < (int) $row['shared_count'] && (int) $row['shared_count'] === $series_count && $category_count === $series_count
				? 'exact'
				: 'partial';
			if ( 'exact' === $row['state'] ) {
				++$exact;
			}
			$row['edit_url'] = get_edit_term_link( (int) $row['term_id'], 'category' );
			$category_url = get_term_link( (int) $row['term_id'], 'category' );
			$row['public_url'] = is_wp_error( $category_url ) ? '' : self::http_url( (string) $category_url );
			$row['canonical_url']   = '';
			$row['canonical_state'] = 'unverified';
			if ( $rank_math_authoritative ) {
				$category_canonical = self::term_meta_text( (int) $row['term_id'], 'rank_math_canonical_url' );
				if ( '' === $category_canonical ) {
					$row['canonical_state'] = 'self';
					$row['canonical_url']   = (string) $row['public_url'];
				} else {
					$row['canonical_url'] = self::http_url( $category_canonical );
					$row['canonical_state'] = '' === $row['canonical_url']
						? 'invalid'
						: ( self::same_url( (string) $row['canonical_url'], $series_url ) ? 'points_to_series' : 'explicit_other' );
				}
			}
			$row['risk'] = self::overlap_risk( $row, $series_url, $series_canonical, $rank_math_authoritative );
		}
		unset( $row );

		return array(
			'series_count'  => $series_count,
			'overlaps'      => $rows,
			'overlap_total' => $total,
			'exact_count'   => $exact,
			'partial_count' => max( 0, count( $rows ) - $exact ),
			'page'          => $page,
			'per_page'      => $per_page,
			'total_pages'   => $total_pages,
		);
	}

	/** @return string[] */
	private function eligible_post_types( string $taxonomy ): array {
		$series  = get_taxonomy( $taxonomy );
		$category = get_taxonomy( 'category' );
		if ( ! $series instanceof \WP_Taxonomy || ! $category instanceof \WP_Taxonomy ) {
			return array();
		}

		$types = array_intersect( (array) $series->object_type, (array) $category->object_type );
		return array_values(
			array_filter(
				array_unique( array_map( 'sanitize_key', $types ) ),
				static function ( string $post_type ): bool {
					$object = get_post_type_object( $post_type );
					return $object instanceof \WP_Post_Type && is_post_type_viewable( $object ) && 'attachment' !== $post_type;
				}
			)
		);
	}

	/** @param string[] $post_types */
	private function eligible_term_count( string $taxonomy, int $term_id, array $post_types ): int {
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = "SELECT COUNT(DISTINCT p.ID)
			FROM {$this->db->posts} p
			INNER JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = %s AND tt.term_id = %d
			AND p.post_status = 'publish' AND p.post_password = ''
			AND p.post_type IN ({$placeholders})";
		$args = array_merge( array( $taxonomy, $term_id ), $post_types );
		return max( 0, (int) $this->db->get_var( $this->prepare( $sql, $args ) ) );
	}

	/** @param string[] $post_types */
	private function overlap_term_count( \WP_Term $term, array $post_types ): int {
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = "SELECT COUNT(DISTINCT ctt.term_id)
			FROM {$this->db->posts} p
			INNER JOIN {$this->db->term_relationships} sr ON sr.object_id = p.ID
			INNER JOIN {$this->db->term_taxonomy} stt ON stt.term_taxonomy_id = sr.term_taxonomy_id
			INNER JOIN {$this->db->term_relationships} cr ON cr.object_id = p.ID
			INNER JOIN {$this->db->term_taxonomy} ctt ON ctt.term_taxonomy_id = cr.term_taxonomy_id
			WHERE stt.taxonomy = %s AND stt.term_id = %d AND ctt.taxonomy = 'category'
			AND p.post_status = 'publish' AND p.post_password = ''
			AND p.post_type IN ({$placeholders})";
		$args = array_merge( array( $term->taxonomy, (int) $term->term_id ), $post_types );
		return max( 0, (int) $this->db->get_var( $this->prepare( $sql, $args ) ) );
	}

	/**
	 * @param string[] $post_types
	 * @return array<int,array<string,mixed>>
	 */
	private function overlap_rows( \WP_Term $term, array $post_types, int $page, int $per_page ): array {
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = "SELECT ctt.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS shared_count
			FROM {$this->db->posts} p
			INNER JOIN {$this->db->term_relationships} sr ON sr.object_id = p.ID
			INNER JOIN {$this->db->term_taxonomy} stt ON stt.term_taxonomy_id = sr.term_taxonomy_id
			INNER JOIN {$this->db->term_relationships} cr ON cr.object_id = p.ID
			INNER JOIN {$this->db->term_taxonomy} ctt ON ctt.term_taxonomy_id = cr.term_taxonomy_id
			INNER JOIN {$this->db->terms} t ON t.term_id = ctt.term_id
			WHERE stt.taxonomy = %s AND stt.term_id = %d AND ctt.taxonomy = 'category'
			AND p.post_status = 'publish' AND p.post_password = ''
			AND p.post_type IN ({$placeholders})
			GROUP BY ctt.term_id, t.name, t.slug
			ORDER BY shared_count DESC, t.name ASC, ctt.term_id ASC
			LIMIT %d OFFSET %d";
		$args = array_merge( array( $term->taxonomy, (int) $term->term_id ), $post_types, array( $per_page, ( $page - 1 ) * $per_page ) );
		$records = $this->db->get_results( $this->prepare( $sql, $args ), ARRAY_A );
		$rows = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$rows[] = array(
				'term_id'      => absint( $record['term_id'] ?? 0 ),
				'name'         => sanitize_text_field( (string) ( $record['name'] ?? '' ) ),
				'slug'         => sanitize_title( (string) ( $record['slug'] ?? '' ) ),
				'shared_count' => max( 0, (int) ( $record['shared_count'] ?? 0 ) ),
			);
		}
		return $rows;
	}

	/**
	 * @param int[]    $term_ids
	 * @param string[] $post_types
	 * @return array<int,int>
	 */
	private function eligible_category_counts( array $term_ids, array $post_types ): array {
		$term_ids = array_values( array_filter( array_unique( array_map( 'absint', $term_ids ) ) ) );
		if ( ! $term_ids ) {
			return array();
		}
		$id_placeholders   = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = "SELECT tt.term_id, COUNT(DISTINCT p.ID) AS total_count
			FROM {$this->db->posts} p
			INNER JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = 'category' AND tt.term_id IN ({$id_placeholders})
			AND p.post_status = 'publish' AND p.post_password = ''
			AND p.post_type IN ({$type_placeholders})
			GROUP BY tt.term_id";
		$records = $this->db->get_results( $this->prepare( $sql, array_merge( $term_ids, $post_types ) ), ARRAY_A );
		$counts = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$counts[ absint( $record['term_id'] ?? 0 ) ] = max( 0, (int) ( $record['total_count'] ?? 0 ) );
		}
		return $counts;
	}

	/** @return array<string,int> */
	private function article_override_counts( \WP_Term $term ): array {
		$post_types = $this->eligible_post_types( $term->taxonomy );
		if ( ! $post_types ) {
			$post_types = array( 'post' );
		}
		$keys = array(
			'seo_titles'      => 'rank_math_title',
			'facebook_titles' => 'rank_math_facebook_title',
			'twitter_titles'  => 'rank_math_twitter_title',
		);
		$counts = array( 'members' => $this->eligible_term_count( $term->taxonomy, (int) $term->term_id, $post_types ) );
		foreach ( $keys as $label => $meta_key ) {
			$counts[ $label ] = $this->count_member_meta( $term, $post_types, $meta_key, '' );
			$counts[ $label . '_with_wptl' ] = $this->count_member_meta( $term, $post_types, $meta_key, '%wptl_' );
		}
		return $counts;
	}

	/** @param string[] $post_types */
	private function count_member_meta( \WP_Term $term, array $post_types, string $meta_key, string $contains ): int {
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$like = '' !== $contains ? ' AND pm.meta_value LIKE %s' : '';
		$sql = "SELECT COUNT(DISTINCT p.ID)
			FROM {$this->db->posts} p
			INNER JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> ''{$like}
			WHERE tt.taxonomy = %s AND tt.term_id = %d
			AND p.post_status = 'publish' AND p.post_password = ''
			AND p.post_type IN ({$placeholders})";
		$args = array( $meta_key );
		if ( '' !== $contains ) {
			$args[] = '%' . $this->db->esc_like( $contains ) . '%';
		}
		$args = array_merge( $args, array( $term->taxonomy, (int) $term->term_id ), $post_types );
		return max( 0, (int) $this->db->get_var( $this->prepare( $sql, $args ) ) );
	}

	/** @param array<int,mixed> $args */
	private function prepare( string $sql, array $args ): string {
		return (string) $this->db->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @param mixed $value */
	private static function scalar_text( $value ): string {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	private static function term_meta_text( int $term_id, string $key ): string {
		return self::scalar_text( get_term_meta( $term_id, $key, true ) );
	}

	/** @param mixed $value */
	private static function truthy( $value ): bool {
		return in_array( strtolower( trim( (string) ( is_scalar( $value ) ? $value : '' ) ) ), array( '1', 'true', 'yes', 'on' ), true ) || true === $value || 1 === $value;
	}

	/** @param mixed $value @return string[] */
	private static function robots( $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) ( is_scalar( $value ) ? $value : '' ) );
		$allowed = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' );
		$clean = array();
		foreach ( (array) $values as $item ) {
			if ( is_scalar( $item ) ) {
				$item = sanitize_key( (string) $item );
				if ( in_array( $item, $allowed, true ) ) {
					$clean[] = $item;
				}
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/** @param mixed $value @return int[] */
	private static function integer_list( $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) ( is_scalar( $value ) ? $value : '' ) );
		$clean = array();
		foreach ( (array) $values as $item ) {
			if ( is_scalar( $item ) ) {
				$item = absint( $item );
				if ( 0 < $item ) {
					$clean[] = $item;
				}
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/** @param string[] $variables */
	private static function contains_variable( string $value, array $variables ): bool {
		foreach ( $variables as $variable ) {
			if ( false !== strpos( $value, '%' . $variable . '%' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function title_layer_policy( string $value ): string {
		if ( self::contains_variable( $value, array( 'wptl_title_with_subtitle' ) ) ) {
			return 'title_with_subtitle';
		}
		if ( self::contains_variable( $value, array( 'wptl_subtitle' ) ) ) {
			return 'subtitle';
		}
		if ( self::contains_variable( $value, array( 'wptl_series' ) ) ) {
			return 'series';
		}
		return 'unchanged';
	}

	private static function series_title_policy( string $value ): string {
		if ( self::contains_variable( $value, array( 'wptl_series', 'term' ) ) ) {
			return 'series_term';
		}
		return self::title_layer_policy( $value );
	}

	private static function http_url( string $url ): string {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		return is_string( $url ) ? $url : '';
	}

	private static function same_url( string $left, string $right ): bool {
		$left  = untrailingslashit( trim( self::http_url( $left ) ) );
		$right = untrailingslashit( trim( self::http_url( $right ) ) );
		return '' !== $left && $left === $right;
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $series_canonical */
	private static function overlap_risk( array $row, string $series_url, array $series_canonical, bool $rank_math_authoritative ): string {
		if ( 'exact' !== (string) ( $row['state'] ?? '' ) ) {
			return 'partial_overlap';
		}
		if ( ! $rank_math_authoritative ) {
			return 'provider_unverified';
		}
		if ( 'points_to_series' === (string) ( $row['canonical_state'] ?? '' ) ) {
			return 'category_points_to_series';
		}
		if (
			'explicit' === (string) ( $series_canonical['state'] ?? '' )
			&& self::same_url( (string) ( $series_canonical['value'] ?? '' ), (string) ( $row['public_url'] ?? '' ) )
		) {
			return 'series_points_to_category';
		}
		if (
			in_array( (string) ( $series_canonical['state'] ?? '' ), array( 'provider_self', 'explicit' ), true )
			&& self::same_url( (string) ( $series_canonical['value'] ?? '' ), $series_url )
			&& 'self' === (string) ( $row['canonical_state'] ?? '' )
		) {
			return 'parallel_self_canonicals';
		}
		return 'canonical_review';
	}
}
