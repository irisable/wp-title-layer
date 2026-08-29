<?php
/**
 * Read-only Series health administration page.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Admin;

use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Series;

defined( 'ABSPATH' ) || exit;

final class SeriesHealth {
	public const PAGE_SLUG = 'wp-title-layer-series-health';

	/** @var bool */
	private static $registered = false;

	/** @var string */
	private static $hook_suffix = '';

	public static function register(): void {
		if ( self::$registered || ! is_admin() ) {
			return;
		}

		self::$registered = true;
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function capability(): string {
		$capability = sanitize_key( (string) apply_filters( 'wptl_series_health_capability', 'manage_options' ) );
		return '' !== $capability ? $capability : 'manage_options';
	}

	public static function add_page(): void {
		$hook = add_submenu_page(
			ControlCenter::PAGE_SLUG,
			__( 'Series Content Health', 'wp-title-layer' ),
			__( 'Series Health', 'wp-title-layer' ),
			self::capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
		self::$hook_suffix = is_string( $hook ) ? $hook : '';
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		$version = defined( 'WPTL_VERSION' ) ? (string) WPTL_VERSION : '1.0.0-rc.1';
		$url     = defined( 'WPTL_URL' )
			? trailingslashit( (string) WPTL_URL ) . 'assets/admin.css'
			: plugin_dir_url( dirname( __DIR__, 2 ) . '/wp-title-layer.php' ) . 'assets/admin.css';
		wp_enqueue_style( 'wptl-series-health', $url, array(), $version );
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view Series health.', 'wp-title-layer' ) );
		}

		$term_id       = isset( $_GET['series_id'] ) ? absint( wp_unslash( $_GET['series_id'] ) ) : 0;
		$view          = isset( $_GET['health_view'] ) ? sanitize_key( wp_unslash( $_GET['health_view'] ) ) : 'issues';
		$page          = isset( $_GET['health_page'] ) ? max( 1, absint( wp_unslash( $_GET['health_page'] ) ) ) : 1;
		$series_search = isset( $_GET['series_search'] ) && is_scalar( $_GET['series_search'] )
			? sanitize_text_field( wp_unslash( (string) $_GET['series_search'] ) )
			: '';
		$service = new SeriesHealthReport();
		?>
		<div class="wrap wptl-series-health">
			<header class="wptl-health-header">
				<p class="wptl-cc-eyebrow"><?php esc_html_e( 'Read-only report', 'wp-title-layer' ); ?></p>
				<h1><?php esc_html_e( 'Series Content Health', 'wp-title-layer' ); ?></h1>
				<p><?php esc_html_e( 'Find structural gaps before they reach readers. This page never edits, publishes, hides, or reorders an article.', 'wp-title-layer' ); ?></p>
			</header>

			<?php if ( '' === Series::claimed_taxonomy() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The Series taxonomy is not currently available, so there is nothing safe to inspect.', 'wp-title-layer' ); ?></p></div>
			<?php elseif ( 0 < $term_id ) : ?>
				<?php self::render_report( $service->report( $term_id, $view, $page, 50 ) ); ?>
			<?php else : ?>
				<?php self::render_terms( $service->terms_page( $page, 20, $series_search ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string,mixed> $page Term overview page. */
	private static function render_terms( array $page ): void {
		$terms  = (array) ( $page['terms'] ?? array() );
		$search = (string) ( $page['search'] ?? '' );
		?>
		<section class="wptl-health-panel" aria-labelledby="wptl-health-series-list">
			<div class="wptl-health-panel__heading">
				<div>
					<h2 id="wptl-health-series-list"><?php esc_html_e( 'Choose a Series to inspect', 'wp-title-layer' ); ?></h2>
					<p><?php esc_html_e( 'The list itself is lightweight. Member records are checked only after you open one Series.', 'wp-title-layer' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( self::manage_series_url() ); ?>"><?php esc_html_e( 'Manage Series', 'wp-title-layer' ); ?></a>
			</div>

			<form class="wptl-health-series-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label class="screen-reader-text" for="wptl-health-series-search"><?php esc_html_e( 'Search Series', 'wp-title-layer' ); ?></label>
				<input type="search" id="wptl-health-series-search" name="series_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search Series by name or slug', 'wp-title-layer' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'wp-title-layer' ); ?></button>
				<?php if ( '' !== $search ) : ?><a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Clear search', 'wp-title-layer' ); ?></a><?php endif; ?>
			</form>

			<?php if ( ! $terms ) : ?>
				<p class="wptl-health-empty"><?php echo '' !== $search ? esc_html__( 'No Series match this search.', 'wp-title-layer' ) : esc_html__( 'No Series have been created yet.', 'wp-title-layer' ); ?></p>
			<?php else : ?>
				<table class="widefat striped wptl-health-table">
					<thead><tr>
						<th><?php esc_html_e( 'Series', 'wp-title-layer' ); ?></th>
						<th><?php esc_html_e( 'Lifecycle', 'wp-title-layer' ); ?></th>
						<th><?php esc_html_e( 'Reading model', 'wp-title-layer' ); ?></th>
						<th><?php esc_html_e( 'Published archive count', 'wp-title-layer' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Action', 'wp-title-layer' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $terms as $term ) : ?>
						<?php if ( $term instanceof \WP_Term ) : ?>
							<tr>
								<td><strong><?php echo esc_html( (string) $term->name ); ?></strong></td>
								<td><?php echo esc_html( Series::status_label( $term ) ?: __( 'Not set', 'wp-title-layer' ) ); ?></td>
								<td><?php echo esc_html( self::model_label( $term ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( max( 0, (int) $term->count ) ) ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( self::page_url( array( 'series_id' => (int) $term->term_id ) ) ); ?>"><?php esc_html_e( 'Check', 'wp-title-layer' ); ?></a></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php self::render_pagination( (int) ( $page['page'] ?? 1 ), (int) ( $page['total_pages'] ?? 0 ), '' !== $search ? array( 'series_search' => $search ) : array() ); ?>
		</section>
		<?php
	}

	/** @param array<string,mixed> $report Selected Series report. */
	private static function render_report( array $report ): void {
		if ( empty( $report['valid'] ) || ! ( $report['term'] instanceof \WP_Term ) ) {
			?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'That Series could not be inspected.', 'wp-title-layer' ); ?></p></div>
			<p><a href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Back to all Series', 'wp-title-layer' ); ?></a></p>
			<?php
			return;
		}

		$term    = $report['term'];
		$summary = (array) $report['summary'];
		$term_edit_url = current_user_can( 'edit_term', (int) $term->term_id )
			? get_edit_term_link( (int) $term->term_id, $term->taxonomy )
			: '';
		?>
		<p class="wptl-health-back"><a href="<?php echo esc_url( self::page_url() ); ?>">&larr; <?php esc_html_e( 'All Series', 'wp-title-layer' ); ?></a></p>
		<section class="wptl-health-panel wptl-health-panel--report" aria-labelledby="wptl-health-report-title">
			<div class="wptl-health-panel__heading">
				<div>
					<h2 id="wptl-health-report-title"><?php echo esc_html( (string) $term->name ); ?></h2>
					<p>
						<?php echo esc_html( self::model_label( $term ) ); ?>
						<span aria-hidden="true"> &middot; </span>
						<?php echo esc_html( $report['status_label'] ?: __( 'Lifecycle not set', 'wp-title-layer' ) ); ?>
					</p>
				</div>
				<div class="wptl-health-actions">
					<?php if ( current_user_can( SequenceManager::capability() ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( SequenceManager::page_url( array( 'series_id' => (int) $term->term_id ) ) ); ?>"><?php esc_html_e( 'Open Sequence & Structure', 'wp-title-layer' ); ?></a>
					<?php endif; ?>
					<?php if ( is_string( $term_edit_url ) && '' !== $term_edit_url ) : ?>
						<a class="button" href="<?php echo esc_url( $term_edit_url ); ?>"><?php esc_html_e( 'Edit Series definition', 'wp-title-layer' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<div class="wptl-health-summary-grid">
				<?php self::render_summary_card( __( 'Member articles', 'wp-title-layer' ), (int) $summary['members'], __( 'All saved states except transient auto-drafts.', 'wp-title-layer' ), 'neutral' ); ?>
				<?php self::render_summary_card( __( 'Structure needs review', 'wp-title-layer' ), (int) $summary['structural_issues'], __( 'Unique articles with at least one Series-structure issue.', 'wp-title-layer' ), 0 < (int) $summary['structural_issues'] ? 'warning' : 'success' ); ?>
				<?php self::render_summary_card( __( 'Published-state issues', 'wp-title-layer' ), (int) $summary['required_status_issues'], __( 'Published, scheduled, or private articles requiring attention.', 'wp-title-layer' ), 0 < (int) $summary['required_status_issues'] ? 'warning' : 'success' ); ?>
				<?php self::render_summary_card( __( 'Draft-stage gaps', 'wp-title-layer' ), (int) $summary['work_in_progress_issues'], __( 'Draft or pending work that may still be intentionally incomplete.', 'wp-title-layer' ), 'notice' ); ?>
			</div>

			<div class="wptl-health-statuses" aria-label="<?php esc_attr_e( 'Article visibility summary', 'wp-title-layer' ); ?>">
				<?php
				$status_counts = array(
					__( 'Published', 'wp-title-layer' )         => (int) $summary['published'],
					__( 'Draft', 'wp-title-layer' )             => (int) $summary['draft'],
					__( 'Scheduled', 'wp-title-layer' )         => (int) $summary['scheduled'],
					__( 'Private', 'wp-title-layer' )           => (int) $summary['private'],
					__( 'Pending review', 'wp-title-layer' )    => (int) $summary['pending'],
					__( 'Trash', 'wp-title-layer' )             => (int) $summary['trash'],
					__( 'Other', 'wp-title-layer' )             => (int) $summary['other'],
					__( 'Password protected', 'wp-title-layer' )=> (int) $summary['password_protected'],
				);
				foreach ( $status_counts as $label => $count ) :
					?>
					<span class="wptl-health-chip"><?php echo esc_html( $label . ': ' . number_format_i18n( $count ) ); ?></span>
				<?php endforeach; ?>
			</div>

			<?php self::render_view_tabs( $report ); ?>
			<?php self::render_rows( $report ); ?>
			<?php self::render_pagination( (int) $report['page'], (int) $report['total_pages'], array( 'series_id' => (int) $term->term_id, 'health_view' => (string) $report['view'] ) ); ?>

			<p class="description wptl-health-footnote"><?php echo esc_html( ! empty( $report['managed'] ) ? __( 'Draft and pending gaps are informational. Trashed articles remain visible in totals but are not treated as structural errors. Managed order checks the private canonical rank; a legacy-field warning means obsolete position data changed after initialization but does not affect readers.', 'wp-title-layer' ) : __( 'Draft and pending gaps are informational. Trashed articles remain visible in totals but are not treated as structural errors. Duplicate positions follow the same non-internal post-status scope as the editor publication check.', 'wp-title-layer' ) ); ?></p>
		</section>
		<?php
	}

	private static function render_summary_card( string $label, int $count, string $description, string $tone ): void {
		?>
		<div class="wptl-health-summary wptl-health-summary--<?php echo esc_attr( sanitize_html_class( $tone ) ); ?>">
			<span class="wptl-health-summary__number"><?php echo esc_html( number_format_i18n( max( 0, $count ) ) ); ?></span>
			<strong><?php echo esc_html( $label ); ?></strong>
			<span><?php echo esc_html( $description ); ?></span>
		</div>
		<?php
	}

	/** @param array<string,mixed> $report Selected Series report. */
	private static function render_view_tabs( array $report ): void {
		$term   = $report['term'];
		$counts = (array) $report['view_counts'];
		$tabs   = array(
			'issues'             => __( 'Needs attention', 'wp-title-layer' ),
			'all'                => __( 'All members', 'wp-title-layer' ),
			'missing_position'   => ! empty( $report['managed'] ) ? __( 'Managed order', 'wp-title-layer' ) : __( 'Position', 'wp-title-layer' ),
			'season'             => __( 'Season', 'wp-title-layer' ),
			'scope'              => __( 'Scope / role', 'wp-title-layer' ),
			'duplicate_position' => __( 'Duplicates', 'wp-title-layer' ),
			'legacy_position_change' => __( 'Legacy field changed', 'wp-title-layer' ),
			'category'           => __( 'Category branch', 'wp-title-layer' ),
			'not_public'         => __( 'Not openly published', 'wp-title-layer' ),
		);
		if ( Schema::MODE_ORDERED !== $report['mode'] && empty( $report['book_structure'] ) ) {
			unset( $tabs['missing_position'], $tabs['duplicate_position'], $tabs['legacy_position_change'] );
		} elseif ( Schema::MODE_ORDERED !== $report['mode'] ) {
			$tabs['missing_position'] = __( 'Track order', 'wp-title-layer' );
			unset( $tabs['legacy_position_change'] );
		} elseif ( empty( $report['managed'] ) ) {
			unset( $tabs['legacy_position_change'] );
		}
		if ( Schema::STRUCTURE_SEASONED !== $report['structure'] ) {
			unset( $tabs['season'] );
		}
		?>
		<nav class="nav-tab-wrapper wptl-health-tabs" aria-label="<?php esc_attr_e( 'Health report filters', 'wp-title-layer' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="nav-tab<?php echo $key === $report['view'] ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( array( 'series_id' => (int) $term->term_id, 'health_view' => $key ) ) ); ?>">
					<?php echo esc_html( $label . ' (' . number_format_i18n( (int) ( $counts[ $key ] ?? 0 ) ) . ')' ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/** @param array<string,mixed> $report Selected Series report. */
	private static function render_rows( array $report ): void {
		$rows = (array) $report['rows'];
		if ( ! $rows ) {
			?>
			<p class="wptl-health-empty"><?php echo 'issues' === $report['view'] ? esc_html__( 'No structural issues were found in this Series.', 'wp-title-layer' ) : esc_html__( 'No articles match this filter.', 'wp-title-layer' ); ?></p>
			<?php
			return;
		}
		?>
		<table class="widefat striped wptl-health-table wptl-health-table--members">
			<thead><tr>
				<th><?php esc_html_e( 'Article', 'wp-title-layer' ); ?></th>
				<th><?php esc_html_e( 'Visibility', 'wp-title-layer' ); ?></th>
				<th><?php esc_html_e( 'Season', 'wp-title-layer' ); ?></th>
				<th><?php echo esc_html( ! empty( $report['managed'] ) ? __( 'Automatic number', 'wp-title-layer' ) : ( ! empty( $report['book_structure'] ) ? __( 'Track order', 'wp-title-layer' ) : __( 'Position', 'wp-title-layer' ) ) ); ?></th>
				<th><?php esc_html_e( 'Check result', 'wp-title-layer' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td>
						<?php if ( '' !== (string) $row['edit_url'] ) : ?>
							<strong><a href="<?php echo esc_url( (string) $row['edit_url'] ); ?>"><?php echo esc_html( (string) $row['title'] ); ?></a></strong>
						<?php else : ?>
							<strong><?php echo esc_html( (string) $row['title'] ); ?></strong>
						<?php endif; ?>
						<span class="wptl-health-meta"><?php echo esc_html( sprintf( 'ID %1$d · %2$s', (int) $row['id'], (string) $row['post_type'] ) ); ?><?php if ( Schema::ROLE_ARTICLE !== (string) ( $row['role'] ?? '' ) ) : ?> · <?php echo esc_html( (string) ( $row['role_label'] ?? '' ) ); ?><?php endif; ?></span>
					</td>
					<td>
						<span class="wptl-health-badge"><?php echo esc_html( (string) $row['status_label'] ); ?></span>
						<?php if ( ! empty( $row['password_protected'] ) ) : ?><span class="wptl-health-badge wptl-health-badge--notice"><?php esc_html_e( 'Password', 'wp-title-layer' ); ?></span><?php endif; ?>
					</td>
					<td><?php echo esc_html( self::row_season( $row, (string) $report['structure'] ) ); ?></td>
					<td><?php echo esc_html( self::row_position( $row, (string) $report['mode'], ! empty( $report['managed'] ), ! empty( $report['book_structure'] ) ) ); ?></td>
					<td><?php self::render_issue_badges( $row ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string,mixed> $row Member row. */
	private static function render_issue_badges( array $row ): void {
		$managed = ! empty( $row['managed'] )
			|| ( ! empty( $row['book_structure'] ) && Schema::ROLE_ARTICLE !== (string) ( $row['role'] ?? '' ) );
		$labels = array(
			'missing_season'     => __( 'Season missing', 'wp-title-layer' ),
			'invalid_season'     => __( 'Season not defined', 'wp-title-layer' ),
			'unresolved_scope'   => __( 'Series/Season scope unresolved', 'wp-title-layer' ),
			'invalid_scope'      => __( 'Scope conflicts with role or Series structure', 'wp-title-layer' ),
			'invalid_role'       => __( 'Series role is not recognized', 'wp-title-layer' ),
			'missing_position'   => $managed ? __( 'Managed order missing', 'wp-title-layer' ) : __( 'Position missing', 'wp-title-layer' ),
			'invalid_position'   => $managed ? __( 'Managed order damaged', 'wp-title-layer' ) : __( 'Position invalid', 'wp-title-layer' ),
			'duplicate_position' => $managed ? __( 'Managed order duplicated', 'wp-title-layer' ) : __( 'Position duplicated', 'wp-title-layer' ),
			'legacy_position_changed' => __( 'Obsolete legacy position changed', 'wp-title-layer' ),
			'category_mismatch'  => __( 'Outside the Series Category branch', 'wp-title-layer' ),
		);
		$issues = (array) ( $row['issues'] ?? array() );
		if ( ! $issues ) {
			echo '<span class="wptl-health-badge wptl-health-badge--success">' . esc_html__( 'No structural issue', 'wp-title-layer' ) . '</span>';
			return;
		}

		$tone = ! empty( $row['required_now'] ) ? 'warning' : 'notice';
		foreach ( $issues as $issue ) {
			if ( isset( $labels[ $issue ] ) ) {
				echo '<span class="wptl-health-badge wptl-health-badge--' . esc_attr( $tone ) . '">' . esc_html( $labels[ $issue ] ) . '</span> ';
			}
		}
	}

	/** @param array<string,mixed> $row Member row. */
	private static function row_season( array $row, string $structure ): string {
		if ( Schema::STRUCTURE_SEASONED !== $structure ) {
			return __( 'Not used', 'wp-title-layer' );
		}
		if ( Schema::SCOPE_SERIES === (string) ( $row['scope'] ?? '' ) && Schema::ROLE_ARTICLE !== (string) ( $row['role'] ?? '' ) ) {
			return __( 'Entire Series', 'wp-title-layer' );
		}
		if ( '' !== (string) $row['season_label'] ) {
			return (string) $row['season_label'];
		}

		return __( 'Not set', 'wp-title-layer' );
	}

	/** @param array<string,mixed> $row Member row. */
	private static function row_position( array $row, string $mode, bool $managed, bool $book_structure ): string {
		if ( Schema::MODE_ORDERED !== $mode ) {
			return $book_structure && Schema::ROLE_ARTICLE !== (string) ( $row['role'] ?? '' )
				? __( 'Managed track', 'wp-title-layer' )
				: __( 'Not used', 'wp-title-layer' );
		}
		if ( $managed ) {
			return 0 < (int) ( $row['ordinal'] ?? 0 )
				? (string) (int) $row['ordinal']
				: __( 'Non-numbered role', 'wp-title-layer' );
		}
		return '' !== (string) $row['position'] ? (string) $row['position'] : __( 'Not set', 'wp-title-layer' );
	}

	private static function model_label( \WP_Term $term ): string {
		$mode = Series::is_ordered( $term ) ? __( 'Ordered', 'wp-title-layer' ) : __( 'Unordered', 'wp-title-layer' );
		$structure = Series::is_seasoned( $term ) ? __( 'Seasoned', 'wp-title-layer' ) : __( 'Flat', 'wp-title-layer' );
		return $mode . ' · ' . $structure;
	}

	/** @param array<string,mixed> $extra Query arguments. */
	public static function page_url( array $extra = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $extra ), admin_url( 'admin.php' ) );
	}

	private static function manage_series_url(): string {
		$taxonomy    = Series::claimed_taxonomy();
		$object      = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : null;
		$object_types = $object instanceof \WP_Taxonomy ? (array) $object->object_type : array();
		$first_type   = $object_types ? reset( $object_types ) : 'post';
		$post_type    = sanitize_key( (string) $first_type ) ?: 'post';
		return add_query_arg( array( 'taxonomy' => $taxonomy ?: Schema::TAXONOMY_SERIES, 'post_type' => $post_type ), admin_url( 'edit-tags.php' ) );
	}

	/** @param array<string,mixed> $base_args Persistent query arguments. */
	private static function render_pagination( int $page, int $total_pages, array $base_args ): void {
		if ( 2 > $total_pages ) {
			return;
		}
		$big  = 999999999;
		$base = self::page_url( array_merge( $base_args, array( 'health_page' => $big ) ) );
		$links = paginate_links(
			array(
				'base'      => str_replace( (string) $big, '%#%', $base ),
				'current'   => max( 1, $page ),
				'total'     => $total_pages,
				'type'      => 'list',
				'prev_text' => __( 'Previous page', 'wp-title-layer' ),
				'next_text' => __( 'Next page', 'wp-title-layer' ),
			)
		);
		if ( is_string( $links ) ) {
			echo '<nav class="wptl-health-pagination" aria-label="' . esc_attr__( 'Health report pages', 'wp-title-layer' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}
}
