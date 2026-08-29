<?php
/**
 * Read-only, paginated Series content-health reporting.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Admin;

use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Sequence;
use WPTitleLayer\Core\Series;
use WPTitleLayer\Core\BookStructure;

defined( 'ABSPATH' ) || exit;

/**
 * Builds bounded reports without loading an entire Series into PHP memory.
 */
final class SeriesHealthReport {
	/** @var \wpdb */
	private $db;

	/** @param \wpdb|null $database Optional database dependency for tests. */
	public function __construct( $database = null ) {
		global $wpdb;
		$this->db = $database instanceof \wpdb ? $database : $wpdb;
	}

	/**
	 * Return one bounded page of Series definitions without scanning members.
	 *
	 * @return array<string,mixed>
	 */
	public function terms_page( int $page = 1, int $per_page = 20, string $search = '' ): array {
		$taxonomy = Series::claimed_taxonomy();
		$page      = max( 1, $page );
		$per_page  = min( 50, max( 1, $per_page ) );
		$search    = trim( sanitize_text_field( $search ) );
		if ( '' === $taxonomy ) {
			return array(
				'valid'       => false,
				'taxonomy'    => '',
				'terms'       => array(),
				'total'       => 0,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => 0,
				'search'      => $search,
			);
		}

		$count_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);
		if ( '' !== $search ) {
			$count_args['search'] = $search;
		}
		$count = wp_count_terms( $count_args );
		$total       = is_wp_error( $count ) ? 0 : max( 0, (int) $count );
		$total_pages = 0 < $total ? (int) ceil( $total / $per_page ) : 0;
		if ( 0 === $total_pages ) {
			$page = 1;
		} elseif ( $page > $total_pages ) {
			$page = $total_pages;
		}
		$term_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
		);
		if ( '' !== $search ) {
			$term_args['search'] = $search;
		}
		$terms = get_terms( $term_args );

		return array(
			'valid'       => true,
			'taxonomy'    => $taxonomy,
			'terms'       => is_wp_error( $terms ) ? array() : array_values( $terms ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
			'search'      => $search,
		);
	}

	/**
	 * Inspect one Series and return a bounded member page plus whole-Series
	 * aggregate counts.
	 *
	 * @return array<string,mixed>
	 */
	public function report( int $term_id, string $view = 'issues', int $page = 1, int $per_page = 50 ): array {
		$taxonomy = Series::claimed_taxonomy();
		$term     = '' !== $taxonomy ? get_term( $term_id, $taxonomy ) : null;
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$view     = in_array( $view, self::views(), true ) ? $view : 'issues';
		if ( ! $term instanceof \WP_Term || $taxonomy !== $term->taxonomy ) {
			return self::empty_report( $view, $page, $per_page );
		}

		$context = $this->sql_context( $term );
		$summary = $this->summary( $context );
		$counts  = array(
			'all'                => $summary['members'],
			'issues'             => $summary['structural_issues'],
			'missing_position'   => $summary['position_issues'],
			'season'             => $summary['season_issues'],
			'scope'              => $summary['scope_issues'],
			'duplicate_position' => $summary['duplicate_positions'],
			'legacy_position_change' => $summary['legacy_position_changes'],
			'category'           => $summary['category_mismatches'],
			'not_public'         => max( 0, $summary['members'] - $summary['published_without_password'] ),
		);
		$total   = max( 0, (int) ( $counts[ $view ] ?? 0 ) );
		$pages   = 0 < $total ? (int) ceil( $total / $per_page ) : 0;
		if ( 0 < $pages && $page > $pages ) {
			$page = $pages;
		}

		$rows = $this->rows( $context, $view, $page, $per_page );

		return array(
			'valid'       => true,
			'term'        => $term,
			'mode'        => Series::mode( $term ),
			'structure'   => Series::structure( $term ),
			'status'      => Series::status( $term ),
			'status_label' => Series::status_label( $term ),
			'managed'     => Sequence::is_managed( $term ),
			'book_structure' => BookStructure::is_enabled( $term ),
			'summary'     => $summary,
			'view_counts' => $counts,
			'view'        => $view,
			'rows'        => $rows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $pages,
		);
	}

	/** @return string[] */
	public static function views(): array {
		return array( 'issues', 'all', 'missing_position', 'season', 'scope', 'duplicate_position', 'legacy_position_change', 'category', 'not_public' );
	}

	/** @return array<string,mixed> */
	private static function empty_report( string $view, int $page, int $per_page ): array {
		return array(
			'valid'        => false,
			'term'         => null,
			'mode'         => Schema::MODE_UNORDERED,
			'structure'    => Schema::STRUCTURE_FLAT,
			'status'       => '',
			'status_label' => '',
			'managed'      => false,
			'book_structure' => false,
			'summary'      => self::empty_summary(),
			'view_counts'  => array_fill_keys( self::views(), 0 ),
			'view'         => $view,
			'rows'         => array(),
			'total'        => 0,
			'page'         => $page,
			'per_page'     => $per_page,
			'total_pages'  => 0,
		);
	}

	/** @return array<string,int> */
	private static function empty_summary(): array {
		return array(
			'members'                  => 0,
			'published_without_password' => 0,
			'published'                => 0,
			'draft'                    => 0,
			'scheduled'                => 0,
			'private'                  => 0,
			'pending'                  => 0,
			'trash'                    => 0,
			'other'                    => 0,
			'password_protected'       => 0,
			'missing_positions'        => 0,
			'invalid_positions'        => 0,
			'position_issues'          => 0,
			'missing_seasons'          => 0,
			'invalid_seasons'          => 0,
			'season_issues'            => 0,
			'scope_issues'             => 0,
			'duplicate_positions'      => 0,
			'legacy_position_changes'  => 0,
			'category_mismatches'      => 0,
			'structural_issues'        => 0,
			'required_status_issues'   => 0,
			'work_in_progress_issues'  => 0,
		);
	}

	/**
	 * @param array<string,mixed> $context Prepared SQL fragments.
	 * @return array<string,int>
	 */
	private function summary( array $context ): array {
		$p = $context['aliases']['post'];
		$sql = "SELECT
			COUNT(*) AS members,
			SUM(CASE WHEN {$p}.post_status = 'publish' AND {$p}.post_password = '' THEN 1 ELSE 0 END) AS published_without_password,
			SUM(CASE WHEN {$p}.post_status = 'publish' THEN 1 ELSE 0 END) AS published,
			SUM(CASE WHEN {$p}.post_status = 'draft' THEN 1 ELSE 0 END) AS draft,
			SUM(CASE WHEN {$p}.post_status = 'future' THEN 1 ELSE 0 END) AS scheduled,
			SUM(CASE WHEN {$p}.post_status = 'private' THEN 1 ELSE 0 END) AS private_posts,
			SUM(CASE WHEN {$p}.post_status = 'pending' THEN 1 ELSE 0 END) AS pending,
			SUM(CASE WHEN {$p}.post_status = 'trash' THEN 1 ELSE 0 END) AS trash,
			SUM(CASE WHEN {$p}.post_status NOT IN ('publish','draft','future','private','pending','trash') THEN 1 ELSE 0 END) AS other_posts,
			SUM(CASE WHEN {$p}.post_password <> '' THEN 1 ELSE 0 END) AS password_protected,
			SUM(CASE WHEN {$context['missing_position']} THEN 1 ELSE 0 END) AS missing_positions,
			SUM(CASE WHEN {$context['invalid_position']} THEN 1 ELSE 0 END) AS invalid_positions,
			SUM(CASE WHEN {$context['position_issues']} THEN 1 ELSE 0 END) AS position_issues,
			SUM(CASE WHEN {$context['missing_season']} THEN 1 ELSE 0 END) AS missing_seasons,
			SUM(CASE WHEN {$context['invalid_season']} THEN 1 ELSE 0 END) AS invalid_seasons,
			SUM(CASE WHEN {$context['season_issues']} THEN 1 ELSE 0 END) AS season_issues,
			SUM(CASE WHEN {$context['scope_issues']} THEN 1 ELSE 0 END) AS scope_issues,
			SUM(CASE WHEN {$context['duplicate_position']} THEN 1 ELSE 0 END) AS duplicate_positions,
			SUM(CASE WHEN {$context['legacy_position_changed']} THEN 1 ELSE 0 END) AS legacy_position_changes,
			SUM(CASE WHEN {$context['category_mismatch']} THEN 1 ELSE 0 END) AS category_mismatches,
			SUM(CASE WHEN {$context['issues']} THEN 1 ELSE 0 END) AS structural_issues,
			SUM(CASE WHEN ({$context['issues']}) AND {$p}.post_status IN ('publish','future','private') THEN 1 ELSE 0 END) AS required_status_issues,
			SUM(CASE WHEN ({$context['issues']}) AND {$p}.post_status NOT IN ('publish','future','private') THEN 1 ELSE 0 END) AS work_in_progress_issues
			{$context['from']}
			{$context['where']}";

		$row     = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$summary = self::empty_summary();
		if ( ! is_array( $row ) ) {
			return $summary;
		}

		$map = array(
			'members'                 => 'members',
			'published_without_password' => 'published_without_password',
			'published'               => 'published',
			'draft'                   => 'draft',
			'scheduled'               => 'scheduled',
			'private'                 => 'private_posts',
			'pending'                 => 'pending',
			'trash'                   => 'trash',
			'other'                   => 'other_posts',
			'password_protected'      => 'password_protected',
			'missing_positions'       => 'missing_positions',
			'invalid_positions'       => 'invalid_positions',
			'position_issues'         => 'position_issues',
			'missing_seasons'         => 'missing_seasons',
			'invalid_seasons'         => 'invalid_seasons',
			'season_issues'           => 'season_issues',
			'scope_issues'            => 'scope_issues',
			'duplicate_positions'     => 'duplicate_positions',
			'legacy_position_changes' => 'legacy_position_changes',
			'category_mismatches'     => 'category_mismatches',
			'structural_issues'       => 'structural_issues',
			'required_status_issues'  => 'required_status_issues',
			'work_in_progress_issues' => 'work_in_progress_issues',
		);
		foreach ( $map as $target => $source ) {
			$summary[ $target ] = max( 0, (int) ( $row[ $source ] ?? 0 ) );
		}

		return $summary;
	}

	/**
	 * @param array<string,mixed> $context Prepared SQL fragments.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( array $context, string $view, int $page, int $per_page ): array {
		$p        = $context['aliases']['post'];
		$season   = $context['aliases']['season'];
		$position = $context['aliases']['position'];
		$role     = $context['aliases']['role'];
		$scope    = $context['aliases']['scope'];
		$condition = $context['views'][ $view ] ?? $context['issues'];
		$offset    = ( $page - 1 ) * $per_page;
		$sql       = "SELECT
			{$p}.ID,
			{$p}.post_title,
			{$p}.post_status,
			{$p}.post_type,
			CASE WHEN {$p}.post_password <> '' THEN 1 ELSE 0 END AS password_protected,
			{$season}.meta_value AS season_key,
			{$position}.meta_value AS sequence_position,
			{$role}.meta_value AS series_role,
			{$scope}.meta_value AS series_scope,
			CASE WHEN {$context['missing_position']} THEN 1 ELSE 0 END AS missing_position,
			CASE WHEN {$context['invalid_position']} THEN 1 ELSE 0 END AS invalid_position,
			CASE WHEN {$context['missing_season']} THEN 1 ELSE 0 END AS missing_season,
			CASE WHEN {$context['invalid_season']} THEN 1 ELSE 0 END AS invalid_season,
			CASE WHEN {$context['unresolved_scope']} THEN 1 ELSE 0 END AS unresolved_scope,
			CASE WHEN {$context['invalid_scope']} THEN 1 ELSE 0 END AS invalid_scope,
			CASE WHEN {$context['invalid_role']} THEN 1 ELSE 0 END AS invalid_role,
			CASE WHEN {$context['duplicate_position']} THEN 1 ELSE 0 END AS duplicate_position
			,CASE WHEN {$context['legacy_position_changed']} THEN 1 ELSE 0 END AS legacy_position_changed
			,CASE WHEN {$context['category_mismatch']} THEN 1 ELSE 0 END AS category_mismatch
			{$context['from']}
			{$context['where']} AND ({$condition})
			ORDER BY {$context['order_by']}
			LIMIT %d OFFSET %d";
		$sql       = $this->prepare( $sql, array( $per_page, $offset ) );
		$records   = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows      = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$rows[] = $this->normalize_row( $record, $context );
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $record Raw database record.
	 * @param array<string,mixed> $context Prepared report context.
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $record, array $context ): array {
		$post_id = absint( $record['ID'] ?? 0 );
		$status  = sanitize_key( (string) ( $record['post_status'] ?? '' ) );
		$raw_season_key = trim( (string) ( $record['season_key'] ?? '' ) );
		$season          = isset( $context['season_map'][ $raw_season_key ] ) ? $context['season_map'][ $raw_season_key ] : null;
		$issues = array();
		foreach ( array( 'missing_season', 'invalid_season', 'unresolved_scope', 'invalid_scope', 'invalid_role', 'missing_position', 'invalid_position', 'duplicate_position', 'legacy_position_changed', 'category_mismatch' ) as $issue ) {
			if ( ! empty( $record[ $issue ] ) ) {
				$issues[] = $issue;
			}
		}

		$title = trim( (string) ( $record['post_title'] ?? '' ) );
		if ( '' === $title ) {
			$title = __( '(no title)', 'wp-title-layer' );
		}

		$season_key = sanitize_text_field( $raw_season_key );
		$position   = isset( $record['sequence_position'] ) ? sanitize_text_field( trim( (string) $record['sequence_position'] ) ) : '';
		$stored_role = sanitize_key( (string) ( $record['series_role'] ?? '' ) );
		$role = in_array( $stored_role, Schema::roles(), true ) ? $stored_role : Schema::ROLE_ARTICLE;
		$scope = sanitize_key( (string) ( $record['series_scope'] ?? '' ) );
		$ordinal    = ! empty( $context['managed'] ) ? Sequence::automatic_ordinal( $post_id, $context['term'] ) : 0;
		return array(
			'id'                 => $post_id,
			'title'              => $title,
			'post_type'          => sanitize_key( (string) ( $record['post_type'] ?? '' ) ),
			'status'             => $status,
			'status_label'       => self::post_status_label( $status ),
			'password_protected' => ! empty( $record['password_protected'] ),
			'season_key'         => $season_key,
			'season_label'       => is_array( $season ) ? (string) $season['label'] : $season_key,
			'position'           => $position,
			'role'               => $role,
			'role_label'         => (string) ( Schema::role_labels()[ $role ] ?? $role ),
			'scope'              => $scope,
			'ordinal'            => $ordinal,
			'managed'            => ! empty( $context['managed'] ),
			'book_structure'     => ! empty( $context['book_structure'] ),
			'issues'             => $issues,
			'required_now'       => in_array( $status, array( 'publish', 'future', 'private' ), true ),
			'edit_url'           => current_user_can( 'edit_post', $post_id ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
		);
	}

	private static function post_status_label( string $status ): string {
		$labels = array(
			'publish' => __( 'Published', 'wp-title-layer' ),
			'draft'   => __( 'Draft', 'wp-title-layer' ),
			'future'  => __( 'Scheduled', 'wp-title-layer' ),
			'private' => __( 'Private', 'wp-title-layer' ),
			'pending' => __( 'Pending review', 'wp-title-layer' ),
			'trash'   => __( 'Trash', 'wp-title-layer' ),
		);

		return $labels[ $status ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $status ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sql_context( \WP_Term $term ): array {
		$outer      = $this->base_sql( $term, 'wptl_health' );
		$mode       = Series::mode( $term );
		$structure  = Series::structure( $term );
		$managed    = Sequence::is_managed( $term );
		$book_structure = BookStructure::is_enabled( $term );
		$seasons    = Series::seasons( $term );
		$season_map = array();
		foreach ( $seasons as $season ) {
			$season_map[ $season['key'] ] = $season;
		}

		$eligible          = $this->structural_eligibility( $outer['aliases']['post'] );
		$position_present  = "{$outer['aliases']['position']}.meta_value IS NOT NULL AND {$outer['aliases']['position']}.meta_value <> ''";
		$position_pattern  = $managed || $book_structure ? '^[1-9][0-9]*$' : '^[0-9]+$';
		$position_valid    = "({$position_present} AND {$outer['aliases']['position']}.meta_value REGEXP '{$position_pattern}')";
		$season_present    = "{$outer['aliases']['season']}.meta_value IS NOT NULL AND {$outer['aliases']['season']}.meta_value <> ''";
		$season_valid      = $this->season_validity( $outer['aliases']['season'], $seasons, Schema::STRUCTURE_SEASONED === $structure );
		$role_alias        = $outer['aliases']['role'];
		$scope_alias       = $outer['aliases']['scope'];
		$role_value        = "CASE WHEN {$role_alias}.meta_value IN ('intro','article','epilogue','appendix') THEN {$role_alias}.meta_value ELSE 'article' END";
		$scope_value       = "COALESCE({$scope_alias}.meta_value, '')";
		$non_main          = "{$role_value} <> 'article'";
		$series_bookend    = Schema::STRUCTURE_SEASONED === $structure ? "({$non_main} AND {$scope_value} = 'series')" : '0=1';
		$invalid_role      = "({$eligible} AND {$role_alias}.meta_value IS NOT NULL AND {$role_alias}.meta_value <> '' AND {$role_alias}.meta_value NOT IN ('intro','article','epilogue','appendix'))";

		$order_required = Schema::MODE_ORDERED === $mode
			? $eligible
			: ( $book_structure ? "({$eligible} AND {$non_main})" : '0=1' );
		$missing_position = "({$order_required} AND NOT ({$position_present}))";
		$invalid_position = "({$order_required} AND {$position_present} AND NOT ({$position_valid}))";
		$position_issues  = "({$missing_position} OR {$invalid_position})";
		$missing_season   = Schema::STRUCTURE_SEASONED === $structure ? "({$eligible} AND NOT ({$series_bookend}) AND NOT ({$season_present}))" : '0=1';
		$invalid_season   = Schema::STRUCTURE_SEASONED === $structure ? "({$eligible} AND NOT ({$series_bookend}) AND {$season_present} AND NOT ({$season_valid}))" : '0=1';
		$season_issues    = Schema::STRUCTURE_SEASONED === $structure ? "({$missing_season} OR {$invalid_season})" : '0=1';
		$unresolved_scope = Schema::STRUCTURE_SEASONED === $structure ? "({$eligible} AND {$non_main} AND ({$scope_alias}.meta_value IS NULL OR {$scope_alias}.meta_value = ''))" : '0=1';
		$invalid_scope    = Schema::STRUCTURE_SEASONED === $structure
			? "({$eligible} AND (({$role_value} = 'article' AND {$scope_value} <> '' AND {$scope_value} <> 'season') OR ({$non_main} AND {$scope_value} <> '' AND {$scope_value} NOT IN ('series','season'))))"
			: "({$eligible} AND {$scope_value} <> '' AND {$scope_value} <> 'series')";
		$scope_issues     = "({$unresolved_scope} OR {$invalid_scope} OR {$invalid_role})";
		$duplicate        = Schema::MODE_ORDERED === $mode || $book_structure
			? $this->duplicate_expression( $term, $outer, $seasons, Schema::STRUCTURE_SEASONED === $structure, $position_valid, $season_valid, $book_structure )
			: '0=1';
		$legacy_position_changed = $managed ? $this->legacy_position_changed_expression( $outer, $eligible ) : '0=1';
		$p                = $outer['aliases']['post'];
		$category_mismatch = $this->category_mismatch_expression( $p, Series::parent_category_id( $term ) );
		$issues           = "({$position_issues} OR {$season_issues} OR {$scope_issues} OR {$duplicate} OR {$legacy_position_changed} OR {$category_mismatch})";

		return array(
			'aliases'            => $outer['aliases'],
			'from'               => $outer['from'],
			'where'              => $outer['where'],
			'mode'               => $mode,
			'structure'          => $structure,
			'managed'            => $managed,
			'book_structure'     => $book_structure,
			'term'               => $term,
			'season_map'         => $season_map,
			'missing_position'   => $missing_position,
			'invalid_position'   => $invalid_position,
			'position_issues'    => $position_issues,
			'missing_season'     => $missing_season,
			'invalid_season'     => $invalid_season,
			'season_issues'      => $season_issues,
			'unresolved_scope'   => $unresolved_scope,
			'invalid_scope'      => $invalid_scope,
			'invalid_role'       => $invalid_role,
			'scope_issues'       => $scope_issues,
			'duplicate_position' => $duplicate,
			'legacy_position_changed' => $legacy_position_changed,
			'category_mismatch'   => $category_mismatch,
			'issues'             => $issues,
			'views'              => array(
				'issues'             => $issues,
				'all'                => '1=1',
				'missing_position'   => $position_issues,
				'season'             => $season_issues,
				'scope'              => $scope_issues,
				'duplicate_position' => $duplicate,
				'legacy_position_change' => $legacy_position_changed,
				'category'           => $category_mismatch,
				'not_public'         => "({$p}.post_status <> 'publish' OR {$p}.post_password <> '')",
			),
			'order_by'          => $this->order_by( $outer['aliases'], $mode, $structure, $seasons, $position_valid ),
		);
	}

	/**
	 * A managed Series never reads the legacy public-position field again, but
	 * changing it after initialization is useful evidence of an old integration
	 * or editor still writing obsolete data.
	 *
	 * @param array<string,mixed> $outer Outer-query fragments.
	 */
	private function legacy_position_changed_expression( array $outer, string $eligible ): string {
		$legacy   = $outer['aliases']['legacy_position'];
		$baseline = $outer['aliases']['source_position'];
		$legacy_present   = "{$legacy}.meta_value IS NOT NULL";
		$baseline_present = "{$baseline}.meta_value IS NOT NULL";
		$missing_source   = $this->prepare( "BINARY {$baseline}.meta_value = BINARY %s", array( Sequence::MISSING_SOURCE ) );
		$known_source     = $this->prepare( "BINARY {$baseline}.meta_value <> BINARY %s", array( Sequence::MISSING_SOURCE ) );

		return "({$eligible} AND {$baseline_present} AND (
			({$missing_source} AND {$legacy_present})
			OR ({$known_source} AND (NOT ({$legacy_present}) OR BINARY {$legacy}.meta_value <> BINARY {$baseline}.meta_value))
		))";
	}

	private function category_mismatch_expression( string $post_alias, int $category_id ): string {
		if ( 0 >= $category_id ) {
			return '0=1';
		}

		$children = get_term_children( $category_id, 'category' );
		$branch   = array( $category_id );
		if ( ! is_wp_error( $children ) ) {
			$branch = array_merge( $branch, array_map( 'absint', $children ) );
		}
		$branch = array_values( array_unique( array_filter( array_map( 'absint', $branch ) ) ) );
		if ( ! $branch ) {
			return '0=1';
		}

		$term_ids = implode( ',', $branch );
		$eligible = $this->structural_eligibility( $post_alias );
		return "({$eligible} AND NOT EXISTS (
			SELECT 1
			FROM {$this->db->term_relationships} AS wptl_health_category_rel
			INNER JOIN {$this->db->term_taxonomy} AS wptl_health_category_tt
				ON wptl_health_category_tt.term_taxonomy_id = wptl_health_category_rel.term_taxonomy_id
			WHERE wptl_health_category_rel.object_id = {$post_alias}.ID
				AND wptl_health_category_tt.taxonomy = 'category'
				AND wptl_health_category_tt.term_id IN ({$term_ids})
		))";
	}

	/**
	 * @param array<string,mixed> $outer Outer-query fragments.
	 * @param array<int,array{key:string,label:string,sort:int}> $seasons Season definitions.
	 */
	private function duplicate_expression( \WP_Term $term, array $outer, array $seasons, bool $seasoned, string $outer_position_valid, string $outer_season_valid, bool $book_structure ): string {
		$inner = $this->base_sql( $term, 'wptl_health_other' );
		$op    = $outer['aliases']['post'];
		$os    = $outer['aliases']['season'];
		$opo   = $outer['aliases']['position'];
		$ip    = $inner['aliases']['post'];
		$is    = $inner['aliases']['season'];
		$ipo   = $inner['aliases']['position'];
		$position_pattern     = Sequence::is_managed( $term ) || $book_structure ? '^[1-9][0-9]*$' : '^[0-9]+$';
		$inner_position_valid = "({$ipo}.meta_value IS NOT NULL AND {$ipo}.meta_value <> '' AND {$ipo}.meta_value REGEXP '{$position_pattern}')";
		$inner_season_valid   = $this->season_validity( $is, $seasons, $seasoned );
		$season_match         = $seasoned ? " AND BINARY {$is}.meta_value = BINARY {$os}.meta_value" : '';
		$outer_season_gate    = $seasoned ? " AND ({$outer_season_valid})" : '';
		$inner_season_gate    = $seasoned ? " AND ({$inner_season_valid})" : '';
		$track_match          = '';
		if ( $book_structure ) {
			$outer_role_alias = $outer['aliases']['role'];
			$outer_scope_alias = $outer['aliases']['scope'];
			$inner_role_alias = $inner['aliases']['role'];
			$inner_scope_alias = $inner['aliases']['scope'];
			$outer_role = "CASE WHEN {$outer_role_alias}.meta_value IN ('intro','article','epilogue','appendix') THEN {$outer_role_alias}.meta_value ELSE 'article' END";
			$inner_role = "CASE WHEN {$inner_role_alias}.meta_value IN ('intro','article','epilogue','appendix') THEN {$inner_role_alias}.meta_value ELSE 'article' END";
			$outer_scope = $seasoned ? "CASE WHEN {$outer_role} = 'article' THEN 'season' ELSE COALESCE({$outer_scope_alias}.meta_value, '') END" : "'series'";
			$inner_scope = $seasoned ? "CASE WHEN {$inner_role} = 'article' THEN 'season' ELSE COALESCE({$inner_scope_alias}.meta_value, '') END" : "'series'";
			$outer_structure_valid = $seasoned
				? "(({$outer_scope} = 'series' AND {$outer_role} <> 'article') OR ({$outer_scope} = 'season' AND ({$outer_season_valid})))"
				: '1=1';
			$inner_structure_valid = $seasoned
				? "(({$inner_scope} = 'series' AND {$inner_role} <> 'article') OR ({$inner_scope} = 'season' AND ({$inner_season_valid})))"
				: '1=1';
			$track_match = " AND BINARY {$inner_role} = BINARY {$outer_role}
				AND BINARY {$inner_scope} = BINARY {$outer_scope}
				AND ( {$outer_scope} <> 'season' OR BINARY {$is}.meta_value = BINARY {$os}.meta_value )";
			$season_match = '';
			$outer_season_gate = " AND ({$outer_structure_valid})";
			$inner_season_gate = " AND ({$inner_structure_valid})";
		}

		return "({$this->structural_eligibility( $op )}
			AND ({$outer_position_valid}){$outer_season_gate}
			AND EXISTS (
				SELECT 1
				{$inner['from']}
				{$inner['where']}
				AND {$ip}.ID <> {$op}.ID
				AND {$this->structural_eligibility( $ip )}
				AND {$inner_position_valid}{$inner_season_gate}
				AND CAST({$ipo}.meta_value AS UNSIGNED) = CAST({$opo}.meta_value AS UNSIGNED)
				{$season_match}{$track_match}
			))";
	}

	/**
	 * Build aliases, canonical first-meta joins, and a term/object-type boundary.
	 * Prefixes are internal constants, never request data.
	 *
	 * @return array<string,mixed>
	 */
	private function base_sql( \WP_Term $term, string $prefix ): array {
		$p        = $prefix . '_posts';
		$rel      = $prefix . '_rel';
		$tt       = $prefix . '_tt';
		$season   = $prefix . '_season';
		$position = $prefix . '_position';
		$legacy_position = $prefix . '_legacy_position';
		$source_position = $prefix . '_source_position';
		$role            = $prefix . '_role';
		$scope           = $prefix . '_scope';
		$position_key = Sequence::is_managed( $term ) || BookStructure::is_enabled( $term ) ? Schema::META_SEQUENCE_RANK : Schema::META_SEQUENCE_POSITION;
		$from     = "FROM {$this->db->posts} AS {$p}
			INNER JOIN {$this->db->term_relationships} AS {$rel} ON {$rel}.object_id = {$p}.ID
			INNER JOIN {$this->db->term_taxonomy} AS {$tt} ON {$tt}.term_taxonomy_id = {$rel}.term_taxonomy_id
			" . $this->canonical_meta_join( $p, $season, $prefix . '_season_value', $prefix . '_season_first', Schema::META_SEASON_KEY ) . "
			" . $this->canonical_meta_join( $p, $position, $prefix . '_position_value', $prefix . '_position_first', $position_key ) . "
			" . $this->canonical_meta_join( $p, $legacy_position, $prefix . '_legacy_position_value', $prefix . '_legacy_position_first', Schema::META_SEQUENCE_POSITION ) . "
			" . $this->canonical_meta_join( $p, $source_position, $prefix . '_source_position_value', $prefix . '_source_position_first', Schema::META_SEQUENCE_SOURCE_POSITION );
		$from .= " " . $this->canonical_meta_join( $p, $role, $prefix . '_role_value', $prefix . '_role_first', Schema::META_SERIES_ROLE );
		$from .= " " . $this->canonical_meta_join( $p, $scope, $prefix . '_scope_value', $prefix . '_scope_first', Schema::META_SERIES_SCOPE );

		$taxonomy = get_taxonomy( $term->taxonomy );
		$post_types = $taxonomy instanceof \WP_Taxonomy
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $taxonomy->object_type ) ) ) )
			: array();
		$type_condition = '0=1';
		if ( $post_types ) {
			$placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$type_condition = $this->prepare( "{$p}.post_type IN ({$placeholders})", $post_types );
		}
		$where = $this->prepare(
			"WHERE {$tt}.taxonomy = %s AND {$tt}.term_id = %d
			AND {$type_condition}
			AND {$p}.post_status NOT IN ('auto-draft','inherit')",
			array( $term->taxonomy, (int) $term->term_id )
		);

		return array(
			'from'    => $from,
			'where'   => $where,
			'aliases' => array(
				'post'            => $p,
				'season'          => $season,
				'position'        => $position,
				'legacy_position' => $legacy_position,
				'source_position' => $source_position,
				'role'            => $role,
				'scope'           => $scope,
			),
		);
	}

	private function canonical_meta_join( string $post_alias, string $result_alias, string $value_alias, string $first_alias, string $meta_key ): string {
		return $this->prepare(
			"LEFT JOIN (
				SELECT {$value_alias}.post_id, {$value_alias}.meta_value
				FROM {$this->db->postmeta} AS {$value_alias}
				INNER JOIN (
					SELECT post_id, MIN(meta_id) AS meta_id
					FROM {$this->db->postmeta}
					WHERE meta_key = %s
					GROUP BY post_id
				) AS {$first_alias} ON {$first_alias}.meta_id = {$value_alias}.meta_id
			) AS {$result_alias} ON {$result_alias}.post_id = {$post_alias}.ID",
			array( $meta_key )
		);
	}

	private function structural_eligibility( string $post_alias ): string {
		$statuses = array_values( array_filter( array_map( 'sanitize_key', get_post_stati( array( 'internal' => false ), 'names' ) ) ) );
		if ( ! $statuses ) {
			return '0=1';
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return $this->prepare( "{$post_alias}.post_status IN ({$placeholders})", $statuses );
	}

	/** @param array<int,array{key:string,label:string,sort:int}> $seasons */
	private function season_validity( string $season_alias, array $seasons, bool $seasoned ): string {
		if ( ! $seasoned ) {
			return '1=1';
		}
		if ( ! $seasons ) {
			return '0=1';
		}

		$matches = array();
		foreach ( $seasons as $season ) {
			$matches[] = $this->prepare( "BINARY {$season_alias}.meta_value = BINARY %s", array( $season['key'] ) );
		}

		return "({$season_alias}.meta_value IS NOT NULL AND {$season_alias}.meta_value <> '' AND (" . implode( ' OR ', $matches ) . '))';
	}

	/**
	 * @param array<string,string> $aliases SQL aliases.
	 * @param array<int,array{key:string,label:string,sort:int}> $seasons Season definitions.
	 */
	private function order_by( array $aliases, string $mode, string $structure, array $seasons, string $position_valid ): string {
		$p        = $aliases['post'];
		$season   = $aliases['season'];
		$position = $aliases['position'];
		$order     = array();
		if ( Schema::STRUCTURE_SEASONED === $structure ) {
			$cases = array();
			foreach ( $seasons as $definition ) {
				$cases[] = $this->prepare( 'WHEN %s THEN %d', array( $definition['key'], (int) $definition['sort'] ) );
			}
			$order[] = $cases
				? '(CASE ' . $season . '.meta_value ' . implode( ' ', $cases ) . ' ELSE 2147483647 END) ASC'
				: "{$season}.meta_value ASC";
		}
		if ( Schema::MODE_ORDERED === $mode ) {
			$order[] = "CASE WHEN {$position_valid} THEN 0 ELSE 1 END ASC";
			$order[] = "CAST({$position}.meta_value AS UNSIGNED) ASC";
		} else {
			$order[] = "{$p}.post_date DESC";
		}
		$order[] = "{$p}.ID ASC";

		return implode( ', ', $order );
	}

	/** @param array<int,mixed> $arguments */
	private function prepare( string $query, array $arguments ): string {
		if ( ! $arguments ) {
			return $query;
		}

		return (string) call_user_func_array( array( $this->db, 'prepare' ), array_merge( array( $query ), $arguments ) );
	}
}
