<?php
/**
 * Accessible administration UI for canonical Series order.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Admin;

use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Sequence;
use WPTitleLayer\Core\Series;
use WPTitleLayer\Core\BookStructure;

defined( 'ABSPATH' ) || exit;

final class SequenceManager {
	public const PAGE_SLUG = 'wp-title-layer-sequence-manager';

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
		add_action( 'admin_post_wptl_sequence_initialize', array( __CLASS__, 'handle_initialize' ) );
		add_action( 'admin_post_wptl_sequence_continue', array( __CLASS__, 'handle_continue' ) );
		add_action( 'admin_post_wptl_sequence_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'admin_post_wptl_sequence_move', array( __CLASS__, 'handle_move' ) );
		add_action( 'admin_post_wptl_sequence_undo', array( __CLASS__, 'handle_undo' ) );
		add_action( 'admin_post_wptl_book_structure_enable', array( __CLASS__, 'handle_book_enable' ) );
		add_action( 'wp_ajax_wptl_sequence_move_ajax', array( __CLASS__, 'ajax_move' ) );
		add_action( 'wp_ajax_wptl_sequence_search', array( __CLASS__, 'ajax_search' ) );
	}

	public static function capability(): string {
		$capability = sanitize_key( (string) apply_filters( 'wptl_sequence_manager_capability', 'manage_options' ) );
		return '' !== $capability ? $capability : 'manage_options';
	}

	public static function add_page(): void {
		$hook = add_submenu_page(
			ControlCenter::PAGE_SLUG,
			__( 'Series Sequence and Structure Manager', 'wp-title-layer' ),
			__( 'Sequence & Structure', 'wp-title-layer' ),
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
		$base    = defined( 'WPTL_URL' ) ? trailingslashit( (string) WPTL_URL ) . 'assets/' : plugin_dir_url( dirname( __DIR__, 2 ) . '/wp-title-layer.php' ) . 'assets/';
		wp_enqueue_style( 'wptl-admin', $base . 'admin.css', array(), $version );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wptl-sequence-manager', $base . 'sequence-manager.css', array( 'wptl-admin', 'wp-components' ), $version );
		wp_enqueue_script( 'wptl-sequence-manager', $base . 'sequence-manager.js', array( 'wp-components', 'wp-element' ), $version, true );

		$term_id      = self::request_int( 'series_id' );
		$season_key   = self::request_key( 'season_key' );
		$track_key    = self::request_key( 'track' );
		$manager_view = self::request_key( 'manager_view' );
		$term          = 0 < $term_id ? get_term( $term_id, Series::claimed_taxonomy() ) : null;
		if ( $term instanceof \WP_Term ) {
			if ( ! Series::is_ordered( $term ) ) {
				$manager_view = 'structure';
			} elseif ( ! in_array( $manager_view, array( 'sequence', 'structure' ), true ) ) {
				$manager_view = '' !== $track_key ? 'structure' : 'sequence';
			}
		}
		if ( $term instanceof \WP_Term && Series::is_seasoned( $term ) && ! Series::season( $term, $season_key ) ) {
			$seasons = Series::seasons( $term );
			$season_key = isset( $seasons[0]['key'] ) ? (string) $seasons[0]['key'] : '';
		}
		if ( 'structure' !== $manager_view ) {
			$track_key = '';
		} elseif ( $term instanceof \WP_Term && BookStructure::is_enabled( $term ) ) {
			$track_key = BookStructure::track( $term, $track_key ) ? $track_key : BookStructure::default_track_key( $term );
		}
		$revision = $term instanceof \WP_Term && '' !== $track_key
			? Sequence::track_revision( $term, $track_key )
			: ( $term instanceof \WP_Term ? Sequence::revision( $term, $season_key ) : 0 );
		wp_add_inline_script(
			'wptl-sequence-manager',
			'window.WPTitleLayerSequenceManager = ' . wp_json_encode(
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'termId'      => $term_id,
					'seasonKey'   => $season_key,
					'trackKey'    => $track_key,
					'revision'    => $revision,
					'nonce'       => 0 < $term_id ? wp_create_nonce( 'wptl_sequence_move_' . $term_id ) : '',
					'messages'    => array(
						'moving'       => __( 'Saving the new article position…', 'wp-title-layer' ),
						'moved'        => __( 'Article moved. Reloading the canonical order…', 'wp-title-layer' ),
						'failed'       => __( 'The move could not be saved. Reload and try again.', 'wp-title-layer' ),
						'searching'    => __( 'Searching this sequence…', 'wp-title-layer' ),
						'noResults'    => __( 'No matching article was found in this scope.', 'wp-title-layer' ),
						'targetChosen' => __( 'Target article selected:', 'wp-title-layer' ),
					),
				)
			) . ';',
			'before'
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Series sequences.', 'wp-title-layer' ) );
		}

		$term_id      = self::request_int( 'series_id' );
		$season_key   = self::request_key( 'season_key' );
		$track_key    = self::request_key( 'track' );
		$manager_view = self::request_key( 'manager_view' );
		$page          = max( 1, self::request_int( 'sequence_page' ) );
		$search        = self::request_text( 'sequence_search' );
		$term          = 0 < $term_id ? get_term( $term_id, Series::claimed_taxonomy() ) : null;
		$terms         = self::series_terms();
		if ( $term instanceof \WP_Term ) {
			if ( ! Series::is_ordered( $term ) ) {
				$manager_view = 'structure';
			} elseif ( ! in_array( $manager_view, array( 'sequence', 'structure' ), true ) ) {
				$manager_view = '' !== $track_key ? 'structure' : 'sequence';
			}
		}
		?>
		<div class="wrap wptl-sequence-manager">
			<header class="wptl-health-header">
				<p class="wptl-cc-eyebrow"><?php esc_html_e( 'Canonical Series order and structure', 'wp-title-layer' ); ?></p>
				<h1><?php esc_html_e( 'Series Sequence and Structure Manager', 'wp-title-layer' ); ?></h1>
				<p><?php esc_html_e( 'Manage the reading order of every ordered Series independently. Enable advanced structure only when a Series also needs introductions, epilogues, appendices, or Series-wide and season tracks.', 'wp-title-layer' ); ?></p>
			</header>

			<?php self::render_notice(); ?>
			<?php self::render_selector( $terms, $term_id ); ?>
			<?php if ( $term instanceof \WP_Term ) : ?>
				<?php self::render_manager_navigation( $term, $manager_view ); ?>
			<?php endif; ?>

			<?php if ( 0 < $term_id && ! $term instanceof \WP_Term ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'That Series no longer exists.', 'wp-title-layer' ); ?></p></div>
			<?php elseif ( $term instanceof \WP_Term && 'sequence' === $manager_view ) : ?>
				<?php $initialization_journal = Sequence::initialization_journal( (int) $term->term_id ); ?>
				<?php if ( ! Sequence::is_managed( $term ) ) : ?>
					<?php self::render_initialization( $term ); ?>
				<?php elseif ( is_array( $initialization_journal ) && in_array( $initialization_journal['status'] ?? '', array( 'writing', 'conflict' ), true ) ) : ?>
					<?php self::render_initialization_progress( $term, $initialization_journal ); ?>
				<?php else : ?>
					<?php self::render_managed_scope( $term, $season_key, $page, $search ); ?>
				<?php endif; ?>
			<?php elseif ( $term instanceof \WP_Term && ! BookStructure::is_enabled( $term ) ) : ?>
				<?php self::render_book_preview( $term ); ?>
			<?php elseif ( $term instanceof \WP_Term ) : ?>
				<?php self::render_structure_tracks( $term, $track_key, $page, $search ); ?>
			<?php else : ?>
				<section class="wptl-health-panel wptl-sequence-empty">
					<h2><?php esc_html_e( 'Choose a Series', 'wp-title-layer' ); ?></h2>
					<p><?php esc_html_e( 'Nothing is written until you inspect one Series and explicitly confirm its compatibility preview.', 'wp-title-layer' ); ?></p>
				</section>
			<?php endif; ?>
			<div class="screen-reader-text" aria-live="polite" aria-atomic="true" data-wptl-sequence-live></div>
		</div>
		<?php
	}

	/** @param \WP_Term[] $terms */
	private static function render_selector( array $terms, int $term_id ): void {
		?>
		<form class="wptl-sequence-selector wptl-series-selector" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label for="wptl-sequence-series"><strong><?php esc_html_e( 'Search or choose a Series', 'wp-title-layer' ); ?></strong></label>
			<div class="wptl-series-combobox" data-wptl-series-combobox data-label="<?php esc_attr_e( 'Search or choose a Series', 'wp-title-layer' ); ?>"></div>
			<select id="wptl-sequence-series" name="series_id" data-wptl-series-select>
				<option value="0"><?php esc_html_e( 'Choose a Series', 'wp-title-layer' ); ?></option>
				<?php foreach ( $terms as $term ) : ?>
					<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $term_id, (int) $term->term_id ); ?>><?php echo esc_html( (string) $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button button-secondary" data-wptl-series-open><?php esc_html_e( 'Open', 'wp-title-layer' ); ?></button>
		</form>
		<?php
	}

	private static function render_manager_navigation( \WP_Term $term, string $manager_view ): void {
		$sequence_managed = Sequence::is_managed( $term );
		$structure_enabled = BookStructure::is_enabled( $term );
		?>
		<div class="wptl-manager-state" aria-label="<?php esc_attr_e( 'Series management status', 'wp-title-layer' ); ?>">
			<?php if ( Series::is_ordered( $term ) ) : ?>
				<span class="wptl-health-chip"><?php echo esc_html( $sequence_managed ? __( 'Sequence managed', 'wp-title-layer' ) : __( 'Sequence not initialized', 'wp-title-layer' ) ); ?></span>
			<?php else : ?>
				<span class="wptl-health-chip"><?php esc_html_e( 'Unordered Series', 'wp-title-layer' ); ?></span>
			<?php endif; ?>
			<span class="wptl-health-chip"><?php echo esc_html( $structure_enabled ? __( 'Advanced structure active', 'wp-title-layer' ) : __( 'Advanced structure not enabled', 'wp-title-layer' ) ); ?></span>
		</div>
		<nav class="nav-tab-wrapper wptl-manager-tabs" aria-label="<?php esc_attr_e( 'Series manager views', 'wp-title-layer' ); ?>">
			<?php if ( Series::is_ordered( $term ) ) : ?>
				<a class="nav-tab<?php echo 'sequence' === $manager_view ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( array( 'series_id' => (int) $term->term_id, 'manager_view' => 'sequence' ) ) ); ?>"><?php esc_html_e( 'Sequence', 'wp-title-layer' ); ?></a>
			<?php endif; ?>
			<a class="nav-tab<?php echo 'structure' === $manager_view ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( array( 'series_id' => (int) $term->term_id, 'manager_view' => 'structure' ) ) ); ?>"><?php esc_html_e( 'Structure tracks', 'wp-title-layer' ); ?></a>
		</nav>
		<?php
	}

	private static function render_initialization( \WP_Term $term ): void {
		$journal = Sequence::initialization_journal( (int) $term->term_id );
		if ( is_array( $journal ) && in_array( $journal['status'] ?? '', array( 'writing', 'conflict' ), true ) ) {
			self::render_initialization_progress( $term, $journal );
			return;
		}

		$preview = Sequence::initialization_preview( (int) $term->term_id );
		?>
		<section class="wptl-health-panel" aria-labelledby="wptl-sequence-preview-title">
			<?php if ( is_array( $journal ) && 'rollback_conflict' === ( $journal['status'] ?? '' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'A previous rollback protected ranks that had changed later. Those private values remain ignored while this Series uses legacy order; this fresh preview can safely begin a new initialization.', 'wp-title-layer' ); ?></p></div>
			<?php endif; ?>
			<div class="wptl-health-panel__heading">
				<div>
					<p class="wptl-cc-eyebrow"><?php esc_html_e( 'Read-only preview', 'wp-title-layer' ); ?></p>
					<h2 id="wptl-sequence-preview-title"><?php echo esc_html( (string) $term->name ); ?></h2>
					<p><?php esc_html_e( 'The live site still uses legacy sequence positions. Initialization writes private ranks in batches and activates them only after every frozen value has been verified.', 'wp-title-layer' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( get_edit_term_link( (int) $term->term_id, $term->taxonomy ) ); ?>"><?php esc_html_e( 'Edit Series definition', 'wp-title-layer' ); ?></a>
			</div>

			<div class="wptl-health-summary-grid">
				<?php self::summary_card( __( 'Articles in initialization set', 'wp-title-layer' ), (int) $preview['members'], __( 'Published and work-in-progress states; trash and transient revisions are excluded.', 'wp-title-layer' ), 'neutral' ); ?>
				<?php self::summary_card( __( 'Needs an explicit decision', 'wp-title-layer' ), (int) $preview['unresolved'], __( 'Missing, malformed, duplicated, or invalid-scope legacy values are never guessed silently.', 'wp-title-layer' ), 0 < (int) $preview['unresolved'] ? 'warning' : 'success' ); ?>
			</div>

			<?php if ( ! empty( $preview['invalid_season'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Some articles have no defined season. Fix those assignments before initialization; date append cannot repair an invalid scope.', 'wp-title-layer' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped wptl-health-table">
				<thead><tr><th><?php esc_html_e( 'Scope', 'wp-title-layer' ); ?></th><th><?php esc_html_e( 'Members', 'wp-title-layer' ); ?></th><th><?php esc_html_e( 'Legacy order is unambiguous', 'wp-title-layer' ); ?></th><th><?php esc_html_e( 'Unresolved', 'wp-title-layer' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) $preview['scopes'] as $season_key => $scope ) : ?>
					<tr>
						<td><strong><?php echo esc_html( is_array( $scope['season'] ) ? (string) $scope['season']['label'] : __( 'Entire flat Series', 'wp-title-layer' ) ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) $scope['members'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( count( (array) $scope['known'] ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( count( (array) $scope['unresolved'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php self::render_unresolved_samples( $preview ); ?>
			<?php self::render_season_id_preview( $preview ); ?>

			<?php if ( empty( $preview['invalid_season'] ) ) : ?>
				<form class="wptl-sequence-confirm" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wptl_sequence_initialize">
					<input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
					<input type="hidden" name="preview_fingerprint" value="<?php echo esc_attr( (string) $preview['fingerprint'] ); ?>">
					<?php wp_nonce_field( 'wptl_sequence_initialize_' . (int) $term->term_id ); ?>
					<?php if ( 0 < (int) $preview['unresolved'] ) : ?>
						<label><input type="checkbox" name="append_unresolved" value="1" required> <?php esc_html_e( 'I explicitly approve appending unresolved articles by article date, then post ID.', 'wp-title-layer' ); ?></label>
					<?php endif; ?>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Initialize Sequence Manager', 'wp-title-layer' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'This does not delete or rewrite legacy positions. The Series activation marker is committed last.', 'wp-title-layer' ); ?></p>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_initialization_progress( \WP_Term $term, array $journal ): void {
		$total  = count( (array) ( $journal['entries'] ?? array() ) );
		$cursor = min( $total, max( 0, (int) ( $journal['cursor'] ?? 0 ) ) );
		$status = (string) ( $journal['status'] ?? '' );
		?>
		<section class="wptl-health-panel" aria-labelledby="wptl-sequence-progress-title">
			<h2 id="wptl-sequence-progress-title"><?php esc_html_e( 'Initialization journal', 'wp-title-layer' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( '%1$d of %2$d private ranks have been value-checked and written.', 'wp-title-layer' ), $cursor, $total ) ); ?></p>
			<progress max="<?php echo esc_attr( (string) max( 1, $total ) ); ?>" value="<?php echo esc_attr( (string) $cursor ); ?>"><?php echo esc_html( (string) $cursor ); ?></progress>
			<?php if ( 'conflict' === $status ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( (string) ( $journal['error'] ?? __( 'The frozen source values changed.', 'wp-title-layer' ) ) ); ?></p></div>
			<?php elseif ( 'rollback_conflict' === $status ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Rollback restored only value-matching writes. A later edit protected one or more ranks from being overwritten.', 'wp-title-layer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'writing' === $status ) : ?>
				<?php self::simple_action_form( 'wptl_sequence_continue', $term, __( 'Continue next batch', 'wp-title-layer' ), 'button button-primary' ); ?>
			<?php endif; ?>
			<?php if ( in_array( $status, array( 'writing', 'conflict' ), true ) ) : ?>
				<?php self::simple_action_form( 'wptl_sequence_rollback', $term, __( 'Roll back value-matching writes', 'wp-title-layer' ), 'button button-secondary' ); ?>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( Sequence::is_managed( $term ) ? __( 'The activation marker is already present; finish this journal before making sequence moves.', 'wp-title-layer' ) : __( 'Until completion, the front end continues to read the old sequence positions.', 'wp-title-layer' ) ); ?></p>
		</section>
		<?php
	}

	private static function render_managed_scope( \WP_Term $term, string $season_key, int $page, string $search ): void {
		$seasons = Series::seasons( $term );
		if ( Series::is_seasoned( $term ) ) {
			if ( ! Series::season( $term, $season_key ) && isset( $seasons[0]['key'] ) ) {
				$season_key = (string) $seasons[0]['key'];
			}
			if ( ! Series::season( $term, $season_key ) ) {
				?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Define at least one season before managing this Series order.', 'wp-title-layer' ); ?></p></div>
				<?php
				return;
			}
		}

		$view = Sequence::scope_page( (int) $term->term_id, $season_key, $page, 50, $search );
		?>
		<section class="wptl-health-panel" aria-labelledby="wptl-managed-sequence-title">
			<div class="wptl-health-panel__heading">
				<div>
					<p class="wptl-cc-eyebrow"><?php esc_html_e( 'Managed sequence', 'wp-title-layer' ); ?></p>
					<h2 id="wptl-managed-sequence-title"><?php echo esc_html( (string) $term->name ); ?></h2>
					<p><?php echo esc_html( sprintf( __( 'Revision %d. Every move is checked against this revision before writing.', 'wp-title-layer' ), (int) $view['revision'] ) ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( get_edit_term_link( (int) $term->term_id, $term->taxonomy ) ); ?>"><?php esc_html_e( 'Edit Series definition', 'wp-title-layer' ); ?></a>
			</div>

			<?php if ( Series::is_seasoned( $term ) ) : ?>
				<nav class="nav-tab-wrapper wptl-health-tabs" aria-label="<?php esc_attr_e( 'Sequence season scopes', 'wp-title-layer' ); ?>">
					<?php foreach ( $seasons as $season ) : ?>
						<a class="nav-tab<?php echo $season_key === (string) $season['key'] ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( array( 'series_id' => (int) $term->term_id, 'manager_view' => 'sequence', 'season_key' => (string) $season['key'] ) ) ); ?>"><?php echo esc_html( (string) $season['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<form class="wptl-sequence-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
				<input type="hidden" name="manager_view" value="sequence">
				<input type="hidden" name="season_key" value="<?php echo esc_attr( $season_key ); ?>">
				<label class="screen-reader-text" for="wptl-sequence-search"><?php esc_html_e( 'Search articles in this sequence', 'wp-title-layer' ); ?></label>
				<input type="search" id="wptl-sequence-search" name="sequence_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search article title', 'wp-title-layer' ); ?>">
				<button class="button" type="submit"><?php esc_html_e( 'Search', 'wp-title-layer' ); ?></button>
			</form>

			<?php self::render_undo( $term, $season_key, (int) $view['revision'] ); ?>
			<?php self::render_sequence_rows( $term, $season_key, $view, true ); ?>
			<?php self::render_scope_pagination( $term, $season_key, (int) $view['page'], (int) $view['total_pages'], $search ); ?>

			<?php $initialization_journal = Sequence::initialization_journal( (int) $term->term_id ); ?>
			<?php if ( is_array( $initialization_journal ) && 'complete' === ( $initialization_journal['status'] ?? '' ) && ! BookStructure::is_enabled( $term ) ) : ?>
				<details class="wptl-sequence-recovery">
					<summary><?php esc_html_e( 'Initialization recovery', 'wp-title-layer' ); ?></summary>
					<p><?php esc_html_e( 'Rollback is available only while every initialized rank, Season definition, and revision still matches the original journal. Once the sequence has been edited, it fails closed.', 'wp-title-layer' ); ?></p>
					<?php self::simple_action_form( 'wptl_sequence_rollback', $term, __( 'Check and roll back initialization', 'wp-title-layer' ), 'button button-secondary' ); ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_book_preview( \WP_Term $term ): void {
		$preview = BookStructure::preview( (int) $term->term_id );
		$review_rows = array_merge( (array) $preview['ambiguous'], (array) $preview['invalid'] );
		$blocked = count( $review_rows );
		$sequence_required = Series::is_ordered( $term ) && ! Sequence::is_managed( $term );
		$tracks = BookStructure::tracks( $term );
		$populated_tracks = array_values(
			array_filter(
				$tracks,
				static function ( array $track ) use ( $preview ): bool {
					return 0 < (int) ( $preview['tracks'][ $track['key'] ] ?? 0 );
				}
			)
		);
		?>
		<section class="wptl-health-panel" aria-labelledby="wptl-book-preview-title">
			<div class="wptl-health-panel__heading">
				<div>
					<p class="wptl-cc-eyebrow"><?php esc_html_e( 'Read-only structure compatibility check', 'wp-title-layer' ); ?></p>
					<h2 id="wptl-book-preview-title"><?php echo esc_html( (string) $term->name ); ?></h2>
					<p><?php esc_html_e( 'Sequence management remains available separately. Advanced structure is optional and stays read-only until every legacy scope is explicit and this Series is enabled.', 'wp-title-layer' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( get_edit_term_link( (int) $term->term_id, $term->taxonomy ) ); ?>"><?php esc_html_e( 'Edit Series definition', 'wp-title-layer' ); ?></a>
			</div>
			<div class="wptl-health-summary-grid">
				<?php self::summary_card( __( 'Series entries', 'wp-title-layer' ), (int) $preview['members'], __( 'No article content or legacy field is changed by this preview.', 'wp-title-layer' ), 'neutral' ); ?>
				<?php self::summary_card( __( 'Needs scope review', 'wp-title-layer' ), count( (array) $preview['ambiguous'] ), __( 'Legacy non-main roles are never silently promoted to Series-wide or season scope.', 'wp-title-layer' ), empty( $preview['ambiguous'] ) ? 'success' : 'warning' ); ?>
				<?php self::summary_card( __( 'Invalid structure', 'wp-title-layer' ), count( (array) $preview['invalid'] ), __( 'Missing Seasons or incompatible scope choices must be corrected first.', 'wp-title-layer' ), empty( $preview['invalid'] ) ? 'success' : 'warning' ); ?>
			</div>
			<?php if ( $review_rows ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php echo esc_html( sprintf( _n( '%d entry needs a scope or structure decision before activation.', '%d entries need a scope or structure decision before activation.', count( $review_rows ), 'wp-title-layer' ), count( $review_rows ) ) ); ?></strong></p></div>
				<h3><?php esc_html_e( 'Resolve these entries first', 'wp-title-layer' ); ?></h3>
				<ul class="wptl-sequence-review-list">
				<?php foreach ( array_slice( $review_rows, 0, 50 ) as $row ) : ?>
					<li><?php if ( '' !== (string) $row['edit_url'] ) : ?><a href="<?php echo esc_url( (string) $row['edit_url'] ); ?>"><?php echo esc_html( (string) $row['title'] ); ?></a><?php else : ?><?php echo esc_html( (string) $row['title'] ); ?><?php endif; ?> <span class="description"><?php echo esc_html( sprintf( 'ID %1$d · %2$s', (int) $row['id'], self::book_issue_label( (string) $row['issue'] ) ) ); ?></span></li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<form class="wptl-sequence-confirm" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wptl_book_structure_enable">
				<input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
				<input type="hidden" name="preview_fingerprint" value="<?php echo esc_attr( (string) $preview['fingerprint'] ); ?>">
				<?php wp_nonce_field( 'wptl_book_structure_enable_' . (int) $term->term_id ); ?>
				<p><button type="submit" class="button button-primary" <?php disabled( 0 < $blocked || $sequence_required ); ?>><?php esc_html_e( 'Enable advanced structure for this Series', 'wp-title-layer' ); ?></button></p>
				<?php if ( $sequence_required ) : ?>
					<p class="description"><?php esc_html_e( 'Initialize this ordered Series in the Sequence view before enabling advanced structure.', 'wp-title-layer' ); ?></p>
				<?php elseif ( 0 < $blocked ) : ?>
					<p class="description"><?php echo esc_html( sprintf( _n( 'Activation is unavailable until %d entry above is resolved.', 'Activation is unavailable until all %d entries above are resolved.', $blocked, 'wp-title-layer' ), $blocked ) ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Non-main entries receive private per-track ranks. Main-article numbers, public labels, old positions, article content, and taxonomy relationships are preserved.', 'wp-title-layer' ); ?></p>
				<?php endif; ?>
			</form>

			<?php if ( $populated_tracks ) : ?>
				<h3><?php esc_html_e( 'Populated structure tracks', 'wp-title-layer' ); ?></h3>
				<?php self::render_track_preview_table( $populated_tracks, $preview ); ?>
			<?php endif; ?>
			<details class="wptl-structure-track-preview">
				<summary><?php echo esc_html( sprintf( __( 'Show all available tracks (%d)', 'wp-title-layer' ), count( $tracks ) ) ); ?></summary>
				<?php self::render_track_preview_table( $tracks, $preview ); ?>
			</details>
		</section>
		<?php
	}

	/** @param array<int,array<string,mixed>> $tracks */
	private static function render_track_preview_table( array $tracks, array $preview ): void {
		?>
		<table class="widefat striped wptl-health-table">
			<thead><tr><th><?php esc_html_e( 'Track', 'wp-title-layer' ); ?></th><th><?php esc_html_e( 'Entries', 'wp-title-layer' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $tracks as $track ) : ?>
				<tr><td><?php echo esc_html( (string) $track['label'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) ( $preview['tracks'][ $track['key'] ] ?? 0 ) ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function book_issue_label( string $issue ): string {
		$labels = array(
			'scope_unresolved'              => __( 'Choose Series-wide or season scope', 'wp-title-layer' ),
			'scope_and_season_unresolved'   => __( 'Choose scope and a valid Season', 'wp-title-layer' ),
			'season_missing_or_invalid'     => __( 'Choose a defined Season', 'wp-title-layer' ),
			'main_article_series_scope'     => __( 'Main articles cannot be Series-wide', 'wp-title-layer' ),
			'season_scope_on_flat_series'   => __( 'Flat Series cannot use season scope', 'wp-title-layer' ),
			'role_invalid'                  => __( 'Replace the unrecognized Series role', 'wp-title-layer' ),
			'scope_invalid'                 => __( 'Replace the unrecognized structure scope', 'wp-title-layer' ),
		);
		return (string) ( $labels[ $issue ] ?? $issue );
	}

	private static function render_structure_tracks( \WP_Term $term, string $track_key, int $page, string $search ): void {
		$tracks = BookStructure::tracks( $term );
		$track = BookStructure::track( $term, $track_key );
		if ( ! is_array( $track ) ) {
			$track_key = BookStructure::default_track_key( $term );
			$track = BookStructure::track( $term, $track_key );
		}
		if ( ! is_array( $track ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No valid structure track is available. Define the required seasons first.', 'wp-title-layer' ) . '</p></div>';
			return;
		}
		$view = Sequence::track_page( (int) $term->term_id, $track_key, $page, 50, $search );
		?>
		<section class="wptl-health-panel" aria-labelledby="wptl-managed-sequence-title">
			<div class="wptl-health-panel__heading">
				<div>
					<p class="wptl-cc-eyebrow"><?php echo esc_html( Series::is_ordered( $term ) ? __( 'Ordered Series', 'wp-title-layer' ) : __( 'Unordered Series', 'wp-title-layer' ) ); ?></p>
					<h2 id="wptl-managed-sequence-title"><?php echo esc_html( (string) $term->name ); ?></h2>
					<p><strong><?php echo esc_html( (string) $track['label'] ); ?></strong> · <?php echo esc_html( sprintf( __( 'Revision %d. Every move is checked against this track revision before writing.', 'wp-title-layer' ), (int) $view['revision'] ) ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( get_edit_term_link( (int) $term->term_id, $term->taxonomy ) ); ?>"><?php esc_html_e( 'Edit Series definition', 'wp-title-layer' ); ?></a>
			</div>

			<form class="wptl-sequence-selector wptl-track-selector" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
				<input type="hidden" name="manager_view" value="structure">
				<label for="wptl-book-track"><strong><?php esc_html_e( 'Structure track', 'wp-title-layer' ); ?></strong></label>
				<select id="wptl-book-track" name="track"><?php foreach ( $tracks as $choice ) : ?><option value="<?php echo esc_attr( (string) $choice['key'] ); ?>" <?php selected( $track_key, (string) $choice['key'] ); ?>><?php echo esc_html( (string) $choice['label'] ); ?></option><?php endforeach; ?></select>
				<button class="button" type="submit"><?php esc_html_e( 'Open track', 'wp-title-layer' ); ?></button>
			</form>
			<?php if ( empty( $track['movable'] ) ) : ?><div class="notice notice-info inline"><p><?php esc_html_e( 'Main articles in an unordered Series keep the Series archive sort setting. This track is shown for context but is not manually rearranged.', 'wp-title-layer' ); ?></p></div><?php endif; ?>

			<form class="wptl-sequence-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
				<input type="hidden" name="manager_view" value="structure">
				<input type="hidden" name="track" value="<?php echo esc_attr( $track_key ); ?>">
				<label class="screen-reader-text" for="wptl-sequence-search"><?php esc_html_e( 'Search articles in this sequence', 'wp-title-layer' ); ?></label>
				<input type="search" id="wptl-sequence-search" name="sequence_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search article title', 'wp-title-layer' ); ?>">
				<button class="button" type="submit"><?php esc_html_e( 'Search', 'wp-title-layer' ); ?></button>
			</form>

			<?php if ( ! empty( $track['movable'] ) ) : ?><?php self::render_undo( $term, $track_key, (int) $view['revision'], true ); ?><?php endif; ?>
			<?php self::render_sequence_rows( $term, $track_key, $view, ! empty( $track['movable'] ), true ); ?>
			<?php self::render_track_pagination( $term, $track_key, (int) $view['page'], (int) $view['total_pages'], $search ); ?>

		</section>
		<?php
	}

	private static function render_sequence_rows( \WP_Term $term, string $scope_key, array $view, bool $movable, bool $book_track = false ): void {
		$rows      = (array) $view['rows'];
		$revision  = (int) $view['revision'];
		$highlight = self::request_int( 'highlight' );
		if ( ! $rows ) {
			echo '<p class="wptl-health-empty">' . esc_html__( 'No managed articles match this scope and search.', 'wp-title-layer' ) . '</p>';
			return;
		}
		$role_labels = Schema::role_labels();
		?>
		<table class="widefat striped wptl-sequence-table">
			<thead><tr>
				<?php if ( $movable ) : ?><th class="wptl-sequence-drag-column"><span class="screen-reader-text"><?php esc_html_e( 'Drag', 'wp-title-layer' ); ?></span></th><?php endif; ?>
				<th><?php esc_html_e( 'Automatic number', 'wp-title-layer' ); ?></th>
				<th><?php esc_html_e( 'Article', 'wp-title-layer' ); ?></th>
				<th><?php esc_html_e( 'Status and date', 'wp-title-layer' ); ?></th>
				<th><?php esc_html_e( 'Public label', 'wp-title-layer' ); ?></th>
				<?php if ( $movable ) : ?><th><?php esc_html_e( 'Move', 'wp-title-layer' ); ?></th><?php endif; ?>
			</tr></thead>
			<tbody data-wptl-sequence-rows data-revision="<?php echo esc_attr( (string) $revision ); ?>">
			<?php foreach ( $rows as $row ) : ?>
				<tr<?php echo $movable ? ' draggable="true"' : ''; ?> data-post-id="<?php echo esc_attr( (string) $row['id'] ); ?>"<?php echo $highlight === (int) $row['id'] ? ' class="wptl-sequence-highlight"' : ''; ?>>
					<?php if ( $movable ) : ?><td class="wptl-sequence-drag-column"><span class="dashicons dashicons-move" aria-hidden="true"></span></td><?php endif; ?>
					<td data-label="<?php esc_attr_e( 'Automatic number', 'wp-title-layer' ); ?>"><strong><?php echo 0 < (int) $row['ordinal'] ? esc_html( (string) $row['ordinal'] ) : '&mdash;'; ?></strong></td>
					<td data-label="<?php esc_attr_e( 'Article', 'wp-title-layer' ); ?>">
						<?php if ( '' !== (string) $row['edit_url'] ) : ?><strong><a href="<?php echo esc_url( (string) $row['edit_url'] ); ?>"><?php echo esc_html( (string) $row['title'] ); ?></a></strong><?php else : ?><strong><?php echo esc_html( (string) $row['title'] ); ?></strong><?php endif; ?>
						<span class="wptl-health-meta"><?php echo esc_html( sprintf( 'ID %d', (int) $row['id'] ) ); ?><?php if ( '' !== (string) $row['role'] ) : ?> · <?php echo esc_html( (string) ( $role_labels[ $row['role'] ] ?? $row['role'] ) ); ?><?php endif; ?></span>
					</td>
					<td data-label="<?php esc_attr_e( 'Status and date', 'wp-title-layer' ); ?>"><span class="wptl-health-badge"><?php echo esc_html( (string) $row['status_label'] ); ?></span><span class="wptl-health-meta"><?php echo esc_html( (string) $row['date'] ); ?></span></td>
					<td data-label="<?php esc_attr_e( 'Public label', 'wp-title-layer' ); ?>"><?php echo '' !== trim( (string) $row['label'] ) ? esc_html( (string) $row['label'] ) : '<span class="description">' . esc_html__( 'Automatic', 'wp-title-layer' ) . '</span>'; ?></td>
					<?php if ( $movable ) : ?><td data-label="<?php esc_attr_e( 'Move', 'wp-title-layer' ); ?>"><?php self::render_row_actions( $term, $scope_key, $row, $revision, $book_track ); ?></td><?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $movable ) : ?><p class="description"><?php esc_html_e( 'Drag within the visible page, use the keyboard-friendly step buttons, or search any page for an exact before/after target.', 'wp-title-layer' ); ?></p><?php endif; ?>
		<?php
	}

	private static function render_row_actions( \WP_Term $term, string $scope_key, array $row, int $revision, bool $book_track ): void {
		?>
		<div class="wptl-sequence-quick-actions">
			<?php self::move_button( $term, $scope_key, (int) $row['id'], 'up', $revision, __( 'Up', 'wp-title-layer' ), $book_track ); ?>
			<?php self::move_button( $term, $scope_key, (int) $row['id'], 'down', $revision, __( 'Down', 'wp-title-layer' ), $book_track ); ?>
			<?php self::move_button( $term, $scope_key, (int) $row['id'], 'start', $revision, __( 'First', 'wp-title-layer' ), $book_track ); ?>
			<?php self::move_button( $term, $scope_key, (int) $row['id'], 'end', $revision, __( 'Last', 'wp-title-layer' ), $book_track ); ?>
		</div>
		<details class="wptl-sequence-precise">
			<summary><?php esc_html_e( 'Place before / after…', 'wp-title-layer' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wptl-precise-form>
				<input type="hidden" name="action" value="wptl_sequence_move">
				<input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $book_track ? 'track' : 'season_key' ); ?>" value="<?php echo esc_attr( $scope_key ); ?>">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
				<input type="hidden" name="sequence_revision" value="<?php echo esc_attr( (string) $revision ); ?>">
				<input type="hidden" name="anchor_id" value="" data-wptl-anchor-id>
				<?php wp_nonce_field( 'wptl_sequence_move_' . (int) $term->term_id ); ?>
				<label><span class="screen-reader-text"><?php esc_html_e( 'Search target article', 'wp-title-layer' ); ?></span><input type="search" data-wptl-anchor-search placeholder="<?php esc_attr_e( 'Type target article title', 'wp-title-layer' ); ?>" autocomplete="off"></label>
				<div class="wptl-sequence-search-results" data-wptl-anchor-results></div>
				<label><select name="placement"><option value="before"><?php esc_html_e( 'Before target', 'wp-title-layer' ); ?></option><option value="after"><?php esc_html_e( 'After target', 'wp-title-layer' ); ?></option></select></label>
				<button type="submit" class="button button-small" disabled data-wptl-precise-submit><?php esc_html_e( 'Move article', 'wp-title-layer' ); ?></button>
				<noscript><p><label><?php esc_html_e( 'Target article ID', 'wp-title-layer' ); ?> <input type="number" name="anchor_id_fallback" min="1"></label></p></noscript>
			</form>
		</details>
		<?php
	}

	private static function move_button( \WP_Term $term, string $scope_key, int $post_id, string $placement, int $revision, string $label, bool $book_track ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wptl_sequence_move">
			<input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $book_track ? 'track' : 'season_key' ); ?>" value="<?php echo esc_attr( $scope_key ); ?>">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<input type="hidden" name="placement" value="<?php echo esc_attr( $placement ); ?>">
			<input type="hidden" name="sequence_revision" value="<?php echo esc_attr( (string) $revision ); ?>">
			<?php wp_nonce_field( 'wptl_sequence_move_' . (int) $term->term_id ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_undo( \WP_Term $term, string $scope_key, int $revision, bool $book_track = false ): void {
		$journal = Sequence::last_move_journal( (int) $term->term_id, $book_track ? '' : $scope_key, $book_track ? $scope_key : '' );
		if ( ! is_array( $journal ) || 'complete' !== ( $journal['status'] ?? '' ) || 'move' !== ( $journal['kind'] ?? '' ) || $revision !== (int) ( $journal['revision_after'] ?? -1 ) ) {
			return;
		}
		?>
		<div class="notice notice-info inline wptl-sequence-undo">
			<p><?php esc_html_e( 'The last manual move can still be undone because its written ranks and revision are unchanged.', 'wp-title-layer' ); ?></p>
			<?php self::simple_action_form( 'wptl_sequence_undo', $term, __( 'Undo last move', 'wp-title-layer' ), 'button button-small', $book_track ? '' : $scope_key, $revision, $book_track ? $scope_key : '' ); ?>
		</div>
		<?php
	}

	private static function render_unresolved_samples( array $preview ): void {
		$samples = array();
		foreach ( (array) $preview['invalid_season'] as $record ) {
			$samples[] = $record;
		}
		foreach ( (array) $preview['scopes'] as $scope ) {
			foreach ( (array) $scope['unresolved'] as $record ) {
				$samples[] = $record;
			}
		}
		if ( ! $samples ) {
			return;
		}
		?>
		<details class="wptl-sequence-unresolved">
			<summary><?php echo esc_html( sprintf( __( 'Review unresolved articles (%d)', 'wp-title-layer' ), count( $samples ) ) ); ?></summary>
			<ul>
			<?php foreach ( array_slice( $samples, 0, 25 ) as $record ) : ?>
				<li><?php if ( '' !== (string) $record['edit_url'] ) : ?><a href="<?php echo esc_url( (string) $record['edit_url'] ); ?>"><?php echo esc_html( (string) $record['title'] ); ?></a><?php else : ?><?php echo esc_html( (string) $record['title'] ); ?><?php endif; ?> <span class="description"><?php echo esc_html( sprintf( 'ID %1$d · %2$s', (int) $record['id'], (string) $record['source_position'] ) ); ?></span></li>
			<?php endforeach; ?>
			</ul>
			<?php if ( 25 < count( $samples ) ) : ?><p class="description"><?php esc_html_e( 'Only the first 25 are shown here; the frozen initialization set still includes every member.', 'wp-title-layer' ); ?></p><?php endif; ?>
		</details>
		<?php
	}

	private static function render_season_id_preview( array $preview ): void {
		$before = (array) $preview['seasons_before'];
		$after  = (array) $preview['seasons_after'];
		if ( ! $after ) {
			return;
		}
		?>
		<details class="wptl-sequence-season-ids">
			<summary><?php esc_html_e( 'Stable Season URL preview', 'wp-title-layer' ); ?></summary>
			<p><?php esc_html_e( 'These numeric identities survive season renames and reordering. Deleted numbers are not reused.', 'wp-title-layer' ); ?></p>
			<ul>
			<?php foreach ( $after as $index => $season ) : ?>
				<li><strong><?php echo esc_html( (string) $season['label'] ); ?></strong>: <code>wptl_season=<?php echo esc_html( (string) $season['public_id'] ); ?></code><?php if ( empty( $before[ $index ]['public_id'] ) ) : ?> <span class="wptl-health-badge wptl-health-badge--notice"><?php esc_html_e( 'New', 'wp-title-layer' ); ?></span><?php endif; ?></li>
			<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	private static function summary_card( string $label, int $count, string $description, string $tone ): void {
		?>
		<div class="wptl-health-summary wptl-health-summary--<?php echo esc_attr( sanitize_html_class( $tone ) ); ?>">
			<span class="wptl-health-summary__number"><?php echo esc_html( number_format_i18n( max( 0, $count ) ) ); ?></span>
			<strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( $description ); ?></span>
		</div>
		<?php
	}

	private static function simple_action_form( string $action, \WP_Term $term, string $label, string $class, string $season_key = '', int $revision = 0, string $track_key = '' ): void {
		?>
		<form class="wptl-sequence-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $term->term_id ); ?>">
			<input type="hidden" name="season_key" value="<?php echo esc_attr( $season_key ); ?>">
			<?php if ( '' !== $track_key ) : ?><input type="hidden" name="track" value="<?php echo esc_attr( $track_key ); ?>"><?php endif; ?>
			<input type="hidden" name="sequence_revision" value="<?php echo esc_attr( (string) $revision ); ?>">
			<?php wp_nonce_field( $action . '_' . (int) $term->term_id ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private static function render_track_pagination( \WP_Term $term, string $track_key, int $page, int $total_pages, string $search ): void {
		if ( 2 > $total_pages ) {
			return;
		}
		$big   = 999999999;
		$base  = self::page_url( array( 'series_id' => (int) $term->term_id, 'manager_view' => 'structure', 'track' => $track_key, 'sequence_search' => $search, 'sequence_page' => $big ) );
		$links = paginate_links( array( 'base' => str_replace( (string) $big, '%#%', $base ), 'current' => max( 1, $page ), 'total' => $total_pages, 'type' => 'list' ) );
		if ( is_string( $links ) ) {
			echo '<nav class="wptl-health-pagination" aria-label="' . esc_attr__( 'Sequence pages', 'wp-title-layer' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	private static function render_scope_pagination( \WP_Term $term, string $season_key, int $page, int $total_pages, string $search ): void {
		if ( 2 > $total_pages ) {
			return;
		}
		$big   = 999999999;
		$base  = self::page_url( array( 'series_id' => (int) $term->term_id, 'manager_view' => 'sequence', 'season_key' => $season_key, 'sequence_search' => $search, 'sequence_page' => $big ) );
		$links = paginate_links( array( 'base' => str_replace( (string) $big, '%#%', $base ), 'current' => max( 1, $page ), 'total' => $total_pages, 'type' => 'list' ) );
		if ( is_string( $links ) ) {
			echo '<nav class="wptl-health-pagination" aria-label="' . esc_attr__( 'Sequence pages', 'wp-title-layer' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	private static function render_notice(): void {
		$key    = 'wptl_sequence_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		$type = in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	public static function handle_initialize(): void {
		$term_id = self::request_int( 'series_id', true );
		self::verify_action( 'wptl_sequence_initialize', $term_id );
		$result = Sequence::begin_initialization( $term_id, ! empty( $_POST['append_unresolved'] ), self::request_text( 'preview_fingerprint', true ) );
		if ( ! is_wp_error( $result ) ) {
			$result = Sequence::continue_initialization( $term_id );
		}
		self::finish_action( $result, $term_id, '', __( 'Sequence initialization started and its first batch was verified.', 'wp-title-layer' ) );
	}

	public static function handle_continue(): void {
		$term_id = self::request_int( 'series_id', true );
		self::verify_action( 'wptl_sequence_continue', $term_id );
		self::finish_action( Sequence::continue_initialization( $term_id ), $term_id, '', __( 'The next initialization batch was verified.', 'wp-title-layer' ) );
	}

	public static function handle_rollback(): void {
		$term_id = self::request_int( 'series_id', true );
		self::verify_action( 'wptl_sequence_rollback', $term_id );
		self::finish_action( Sequence::rollback_initialization( $term_id ), $term_id, self::request_key( 'season_key', true ), __( 'Value-matching initialization writes were rolled back.', 'wp-title-layer' ) );
	}

	public static function handle_book_enable(): void {
		$term_id = self::request_int( 'series_id', true );
		self::verify_action( 'wptl_book_structure_enable', $term_id );
		$result = BookStructure::enable( $term_id, self::request_text( 'preview_fingerprint', true ) );
		self::finish_action( $result, $term_id, '', __( 'Advanced structure is now active for this Series.', 'wp-title-layer' ), '', 'structure' );
	}

	public static function handle_move(): void {
		$term_id = self::request_int( 'series_id', true );
		self::verify_action( 'wptl_sequence_move', $term_id );
		$anchor_id = self::request_int( 'anchor_id', true );
		if ( 0 >= $anchor_id ) {
			$anchor_id = self::request_int( 'anchor_id_fallback', true );
		}
		$season_key = self::request_key( 'season_key', true );
		$track_key = self::request_key( 'track', true );
		$result = '' !== $track_key ? Sequence::move_track(
			$term_id,
			$track_key,
			self::request_int( 'post_id', true ),
			$anchor_id,
			self::request_key( 'placement', true ),
			self::request_int( 'sequence_revision', true )
		) : Sequence::move(
			$term_id,
			$season_key,
			self::request_int( 'post_id', true ),
			$anchor_id,
			self::request_key( 'placement', true ),
			self::request_int( 'sequence_revision', true )
		);
		self::finish_action( $result, $term_id, $season_key, __( 'The article was moved in canonical track order.', 'wp-title-layer' ), $track_key );
	}

	public static function handle_undo(): void {
		$term_id = self::request_int( 'series_id', true );
		self::verify_action( 'wptl_sequence_undo', $term_id );
		$season_key = self::request_key( 'season_key', true );
		$track_key = self::request_key( 'track', true );
		$result = Sequence::undo_last_move( $term_id, $season_key, self::request_int( 'sequence_revision', true ), $track_key );
		self::finish_action( $result, $term_id, $season_key, __( 'The last track move was undone without overwriting later edits.', 'wp-title-layer' ), $track_key );
	}

	public static function ajax_move(): void {
		$term_id = self::request_int( 'series_id', true );
		self::verify_ajax( $term_id );
		$season_key = self::request_key( 'season_key', true );
		$track_key = self::request_key( 'track', true );
		$result = '' !== $track_key
			? Sequence::move_track( $term_id, $track_key, self::request_int( 'post_id', true ), self::request_int( 'anchor_id', true ), self::request_key( 'placement', true ), self::request_int( 'sequence_revision', true ) )
			: Sequence::move( $term_id, $season_key, self::request_int( 'post_id', true ), self::request_int( 'anchor_id', true ), self::request_key( 'placement', true ), self::request_int( 'sequence_revision', true ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 409 );
		}
		wp_send_json_success( array( 'message' => __( 'Article moved.', 'wp-title-layer' ), 'revision' => (int) ( $result['revision_after'] ?? 0 ) ) );
	}

	public static function ajax_search(): void {
		$term_id = self::request_int( 'series_id', true );
		self::verify_ajax( $term_id );
		$search = self::request_text( 'search', true );
		$track_key = self::request_key( 'track', true );
		$view   = '' !== $track_key
			? Sequence::track_page( $term_id, $track_key, 1, 20, $search )
			: Sequence::scope_page( $term_id, self::request_key( 'season_key', true ), 1, 20, $search );
		$rows   = array();
		foreach ( (array) ( $view['rows'] ?? array() ) as $row ) {
			$rows[] = array( 'id' => (int) $row['id'], 'title' => (string) $row['title'], 'status' => (string) $row['status_label'], 'ordinal' => (int) $row['ordinal'] );
		}
		wp_send_json_success( array( 'rows' => $rows ) );
	}

	private static function verify_action( string $action, int $term_id ): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Series sequences.', 'wp-title-layer' ) );
		}
		check_admin_referer( $action . '_' . $term_id );
	}

	private static function verify_ajax( int $term_id ): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage Series sequences.', 'wp-title-layer' ) ), 403 );
		}
		check_ajax_referer( 'wptl_sequence_move_' . $term_id, 'nonce' );
	}

	/** @param array<string,mixed>|true|\WP_Error $result */
	private static function finish_action( $result, int $term_id, string $season_key, string $success_message, string $track_key = '', string $manager_view = '' ): void {
		if ( is_wp_error( $result ) ) {
			self::store_notice( 'error', $result->get_error_message() );
		} else {
			$status = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '';
			if ( 'writing' === $status ) {
				self::store_notice( 'info', __( 'The Series is still using legacy order. Continue the next bounded initialization batch.', 'wp-title-layer' ) );
			} else {
				self::store_notice( 'success', $success_message );
			}
		}
		$args = array(
			'series_id'    => $term_id,
			'manager_view' => in_array( $manager_view, array( 'sequence', 'structure' ), true )
				? $manager_view
				: ( '' !== $track_key ? 'structure' : 'sequence' ),
		);
		if ( '' !== $track_key ) {
			$args['track'] = $track_key;
		} elseif ( '' !== $season_key ) {
			$args['season_key'] = $season_key;
		}
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	private static function store_notice( string $type, string $message ): void {
		set_transient( 'wptl_sequence_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
	}

	/** @return \WP_Term[] */
	private static function series_terms(): array {
		$taxonomy = Series::claimed_taxonomy();
		if ( '' === $taxonomy ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		return is_wp_error( $terms ) ? array() : array_values( $terms );
	}

	/** @param array<string,mixed> $extra */
	public static function page_url( array $extra = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $extra ), admin_url( 'admin.php' ) );
	}

	private static function request_int( string $key, bool $post = false ): int {
		$source = $post ? $_POST : $_GET;
		return isset( $source[ $key ] ) ? absint( wp_unslash( $source[ $key ] ) ) : 0;
	}

	private static function request_key( string $key, bool $post = false ): string {
		$source = $post ? $_POST : $_GET;
		return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? sanitize_key( wp_unslash( (string) $source[ $key ] ) ) : '';
	}

	private static function request_text( string $key, bool $post = false ): string {
		$source = $post ? $_POST : $_GET;
		return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $source[ $key ] ) ) : '';
	}
}
