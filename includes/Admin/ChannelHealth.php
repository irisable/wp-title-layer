<?php
/**
 * Read-only landing-page and title-channel administration page.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Admin;

use WPTitleLayer\Core\Series;

defined( 'ABSPATH' ) || exit;

final class ChannelHealth {
	public const PAGE_SLUG = 'wp-title-layer-channel-health';

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
		$capability = sanitize_key( (string) apply_filters( 'wptl_channel_health_capability', 'manage_options' ) );
		return '' !== $capability ? $capability : 'manage_options';
	}

	public static function add_page(): void {
		$hook = add_submenu_page(
			ControlCenter::PAGE_SLUG,
			__( 'Landing Pages and Title Channels', 'wp-title-layer' ),
			__( 'Landing & Channels', 'wp-title-layer' ),
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
		wp_enqueue_style( 'wptl-channel-health', $url, array(), $version );
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view landing-page and title-channel checks.', 'wp-title-layer' ) );
		}

		$raw_term_id = isset( $_GET['series_id'] ) ? wp_unslash( $_GET['series_id'] ) : 0;
		$raw_page    = isset( $_GET['channel_page'] ) ? wp_unslash( $_GET['channel_page'] ) : 1;
		$term_id     = is_scalar( $raw_term_id ) ? absint( $raw_term_id ) : 0;
		$page        = is_scalar( $raw_page ) ? max( 1, absint( $raw_page ) ) : 1;
		$service = new ChannelHealthReport();
		$overview = $service->overview();
		?>
		<div class="wrap wptl-series-health wptl-channel-health">
			<header class="wptl-health-header">
				<p class="wptl-cc-eyebrow"><?php esc_html_e( 'Read-only governance report', 'wp-title-layer' ); ?></p>
				<h1><?php esc_html_e( 'Landing Pages and Title Channels', 'wp-title-layer' ); ?></h1>
				<p><?php esc_html_e( 'Compare public Series and Category entry points, then inspect which system owns visible, search, and social titles. Nothing on this page changes SEO metadata, taxonomy relationships, robots, canonical URLs, or sitemap settings.', 'wp-title-layer' ); ?></p>
			</header>

			<?php self::render_overview( $overview ); ?>

			<?php if ( '' === Series::claimed_taxonomy() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The Series taxonomy is not currently available, so Series landing pages cannot be compared safely.', 'wp-title-layer' ); ?></p></div>
			<?php elseif ( 0 < $term_id ) : ?>
				<?php self::render_report( $service->report( $term_id, $page, 50 ) ); ?>
			<?php else : ?>
				<?php self::render_terms( $service->terms_page( $page, 20 ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string,mixed> $overview Site channel facts. */
	private static function render_overview( array $overview ): void {
		$provider  = (array) ( $overview['provider'] ?? array() );
		$rank_math = (array) ( $overview['rank_math'] ?? array() );
		$visible   = (array) ( $overview['visible'] ?? array() );
		$title     = (array) ( $rank_math['taxonomy_title'] ?? array() );
		$robots    = (array) ( $rank_math['robots'] ?? array() );
		$sitemap   = (array) ( $rank_math['sitemap'] ?? array() );
		$article   = (array) ( $rank_math['article_title'] ?? array() );
		$state     = (string) ( $provider['state'] ?? 'none' );
		$provider_tone = 'rank_math' === $state ? 'success' : ( 'none' === $state ? 'notice' : 'warning' );
		?>
		<section class="wptl-health-panel wptl-channel-overview" aria-labelledby="wptl-channel-overview-title">
			<div class="wptl-health-panel__heading">
				<div>
					<h2 id="wptl-channel-overview-title"><?php esc_html_e( 'Channel ownership at a glance', 'wp-title-layer' ); ?></h2>
					<p><?php esc_html_e( 'A visible page title is not automatically the same as the search-result or social-sharing title. This report keeps those channels separate.', 'wp-title-layer' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'page', 'wp-title-layer', admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Title display settings', 'wp-title-layer' ); ?></a>
			</div>

			<div class="wptl-health-summary-grid">
				<?php self::summary_card( __( 'Visible page title', 'wp-title-layer' ), (string) ( $visible['label'] ?? __( 'Unavailable', 'wp-title-layer' ) ), __( 'Owned by WP Title Layer and the active theme.', 'wp-title-layer' ), 'neutral' ); ?>
				<?php self::summary_card( __( 'SEO provider', 'wp-title-layer' ), self::provider_label( $provider ), self::provider_description( $provider ), $provider_tone ); ?>
				<?php self::summary_card( __( 'Series search title', 'wp-title-layer' ), ! empty( $rank_math['active'] ) ? self::source_label( (string) ( $title['source'] ?? '' ) ) : __( 'Not verified here', 'wp-title-layer' ), self::value_preview( (string) ( $title['value'] ?? '' ) ), ! empty( $rank_math['active'] ) ? 'notice' : 'neutral' ); ?>
				<?php self::summary_card( __( 'Article search title', 'wp-title-layer' ), ! empty( $rank_math['active'] ) ? self::policy_label( (string) ( $article['policy'] ?? '' ) ) : __( 'Not verified here', 'wp-title-layer' ), self::value_preview( (string) ( $article['value'] ?? '' ) ), ! empty( $rank_math['active'] ) ? 'notice' : 'neutral' ); ?>
			</div>

			<?php if ( ! empty( $rank_math['active'] ) ) : ?>
				<div class="wptl-health-statuses">
					<span class="wptl-health-chip"><?php echo esc_html( sprintf( __( 'Series robots: %s', 'wp-title-layer' ), implode( ', ', (array) ( $robots['values'] ?? array() ) ) ) ); ?></span>
					<span class="wptl-health-chip"><?php echo esc_html( ! empty( $sitemap['module_active'] ) ? __( 'Rank Math sitemap module: on', 'wp-title-layer' ) : __( 'Rank Math sitemap module: off', 'wp-title-layer' ) ); ?></span>
					<span class="wptl-health-chip"><?php echo esc_html( ! empty( $sitemap['enabled'] ) ? __( 'Series taxonomy in sitemap: on', 'wp-title-layer' ) : __( 'Series taxonomy in sitemap: off', 'wp-title-layer' ) ); ?></span>
				</div>
				<p class="description">
					<?php esc_html_e( 'Rank Math is active, so the values above are read from its current local settings. Provider defaults are labelled separately from settings you explicitly saved.', 'wp-title-layer' ); ?>
					<a href="<?php echo esc_url( (string) ( $overview['settings_urls']['titles'] ?? '' ) ); ?>"><?php esc_html_e( 'Review Titles & Meta', 'wp-title-layer' ); ?></a>
					<span aria-hidden="true"> · </span>
					<a href="<?php echo esc_url( (string) ( $overview['settings_urls']['sitemap'] ?? '' ) ); ?>"><?php esc_html_e( 'Review Sitemap Settings', 'wp-title-layer' ); ?></a>
				</p>
			<?php else : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html( self::provider_boundary( $provider ) ); ?></p></div>
			<?php endif; ?>
		</section>
		<?php
	}

	/** @param array<string,mixed> $page Term overview page. */
	private static function render_terms( array $page ): void {
		$terms = (array) ( $page['terms'] ?? array() );
		?>
		<section class="wptl-health-panel" aria-labelledby="wptl-channel-series-list">
			<div class="wptl-health-panel__heading">
				<div>
					<h2 id="wptl-channel-series-list"><?php esc_html_e( 'Choose a Series landing page', 'wp-title-layer' ); ?></h2>
					<p><?php esc_html_e( 'Open one Series to compare its public article set with Category archives and inspect term-level SEO, canonical, robots, sitemap, and social-title settings.', 'wp-title-layer' ); ?></p>
				</div>
			</div>

			<?php if ( ! $terms ) : ?>
				<p class="wptl-health-empty"><?php esc_html_e( 'No Series have been created yet.', 'wp-title-layer' ); ?></p>
			<?php else : ?>
				<table class="widefat striped wptl-health-table">
					<thead><tr>
						<th><?php esc_html_e( 'Series', 'wp-title-layer' ); ?></th>
						<th><?php esc_html_e( 'WordPress term count', 'wp-title-layer' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Action', 'wp-title-layer' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $terms as $term ) : ?>
						<?php if ( $term instanceof \WP_Term ) : ?>
							<tr>
								<td><strong><?php echo esc_html( (string) $term->name ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( max( 0, (int) $term->count ) ) ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( self::page_url( array( 'series_id' => (int) $term->term_id ) ) ); ?>"><?php esc_html_e( 'Inspect channels', 'wp-title-layer' ); ?></a></td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php self::pagination( (int) ( $page['page'] ?? 1 ), (int) ( $page['total_pages'] ?? 0 ), array() ); ?>
		</section>
		<?php
	}

	/** @param array<string,mixed> $report Selected Series report. */
	private static function render_report( array $report ): void {
		if ( empty( $report['valid'] ) || ! ( $report['term'] instanceof \WP_Term ) ) {
			?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'That Series landing page could not be inspected.', 'wp-title-layer' ); ?></p></div>
			<p><a href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Back to all Series', 'wp-title-layer' ); ?></a></p>
			<?php
			return;
		}

		$term      = $report['term'];
		$channels  = (array) $report['channels'];
		$overlaps  = (array) $report['overlaps'];
		$overview  = (array) $report['overview'];
		$provider  = (array) ( $overview['provider'] ?? array() );
		$overlap_total = (int) ( $report['overlap_total'] ?? 0 );
		$edit_url = current_user_can( 'edit_term', (int) $term->term_id ) ? get_edit_term_link( (int) $term->term_id, $term->taxonomy ) : '';
		?>
		<p class="wptl-health-back"><a href="<?php echo esc_url( self::page_url() ); ?>">&larr; <?php esc_html_e( 'All Series landing pages', 'wp-title-layer' ); ?></a></p>

		<section class="wptl-health-panel" aria-labelledby="wptl-channel-report-title">
			<div class="wptl-health-panel__heading">
				<div>
					<h2 id="wptl-channel-report-title"><?php echo esc_html( (string) $term->name ); ?></h2>
					<p><?php esc_html_e( 'Public landing-page overlap and channel ownership for this Series.', 'wp-title-layer' ); ?></p>
				</div>
				<?php if ( is_string( $edit_url ) && '' !== $edit_url ) : ?>
					<a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit Series', 'wp-title-layer' ); ?></a>
				<?php endif; ?>
			</div>

			<div class="wptl-health-summary-grid">
				<?php self::summary_card( __( 'Public Series articles', 'wp-title-layer' ), number_format_i18n( (int) ( $report['series_count'] ?? 0 ) ), __( 'Published, public post types, without passwords.', 'wp-title-layer' ), 'neutral' ); ?>
				<?php self::summary_card( __( 'Overlapping Categories', 'wp-title-layer' ), number_format_i18n( $overlap_total ), __( 'Categories sharing at least one public article.', 'wp-title-layer' ), 0 < $overlap_total ? 'notice' : 'success' ); ?>
				<?php self::summary_card( __( 'Exact matches on this page', 'wp-title-layer' ), number_format_i18n( (int) ( $report['exact_count'] ?? 0 ) ), __( 'Same complete public article set as this Series.', 'wp-title-layer' ), 0 < (int) ( $report['exact_count'] ?? 0 ) ? 'warning' : 'success' ); ?>
				<?php self::summary_card( __( 'Partial overlaps on this page', 'wp-title-layer' ), number_format_i18n( (int) ( $report['partial_count'] ?? 0 ) ), __( 'Shared members, but not the same complete landing page.', 'wp-title-layer' ), 'neutral' ); ?>
			</div>

			<?php self::render_channel_table( $channels, $provider ); ?>

			<h3 class="wptl-channel-subheading"><?php esc_html_e( 'Series and Category entry points', 'wp-title-layer' ); ?></h3>
			<?php if ( ! $overlaps ) : ?>
				<p class="wptl-health-empty"><?php esc_html_e( 'No public Category currently shares an eligible published article with this Series.', 'wp-title-layer' ); ?></p>
			<?php else : ?>
				<table class="widefat striped wptl-health-table">
					<thead><tr>
						<th><?php esc_html_e( 'Category', 'wp-title-layer' ); ?></th>
						<th><?php esc_html_e( 'Relationship', 'wp-title-layer' ); ?></th>
						<th><?php esc_html_e( 'Shared / Series / Category', 'wp-title-layer' ); ?></th>
						<th><?php esc_html_e( 'Category canonical', 'wp-title-layer' ); ?></th>
						<th><?php esc_html_e( 'Review', 'wp-title-layer' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $overlaps as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></strong></td>
							<td><?php self::badge( 'exact' === (string) ( $row['state'] ?? '' ) ? __( 'Exact public set', 'wp-title-layer' ) : __( 'Partial overlap', 'wp-title-layer' ), 'exact' === (string) ( $row['state'] ?? '' ) ? 'warning' : 'notice' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $row['shared_count'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $report['series_count'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $row['category_count'] ?? 0 ) ) ); ?></td>
							<td>
								<?php echo esc_html( self::category_canonical_label( (string) ( $row['canonical_state'] ?? '' ) ) ); ?>
								<span class="wptl-health-meta"><?php echo esc_html( self::overlap_risk_label( (string) ( $row['risk'] ?? '' ) ) ); ?></span>
							</td>
							<td>
								<?php if ( ! empty( $row['public_url'] ) ) : ?><a href="<?php echo esc_url( (string) $row['public_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'wp-title-layer' ); ?></a><?php endif; ?>
								<?php if ( ! empty( $row['edit_url'] ) ) : ?><span aria-hidden="true"> · </span><a href="<?php echo esc_url( (string) $row['edit_url'] ); ?>"><?php esc_html_e( 'Edit Category', 'wp-title-layer' ); ?></a><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php self::pagination( (int) $report['page'], (int) $report['total_pages'], array( 'series_id' => (int) $term->term_id ) ); ?>

			<?php self::render_article_override_summary( (array) ( $report['article_overrides'] ?? array() ), $provider ); ?>

			<p class="description wptl-health-footnote"><?php esc_html_e( 'An exact match is evidence of two public entry points, not an instruction to delete one. Choose the preferred landing page and canonical policy in WordPress and your SEO provider after checking inbound links, navigation, and historical URLs.', 'wp-title-layer' ); ?></p>
		</section>
		<?php
	}

	/** @param array<string,mixed> $channels @param array<string,mixed> $provider */
	private static function render_channel_table( array $channels, array $provider ): void {
		$visible     = (array) ( $channels['visible'] ?? array() );
		$search      = (array) ( $channels['search_title'] ?? array() );
		$description = (array) ( $channels['description'] ?? array() );
		$canonical   = (array) ( $channels['canonical'] ?? array() );
		$robots      = (array) ( $channels['robots'] ?? array() );
		$sitemap     = (array) ( $channels['sitemap'] ?? array() );
		$facebook    = (array) ( $channels['facebook'] ?? array() );
		$twitter     = (array) ( $channels['twitter'] ?? array() );
		$rank_math   = 'rank_math' === (string) ( $provider['state'] ?? '' );
		?>
		<h3 class="wptl-channel-subheading"><?php esc_html_e( 'This landing page’s channels', 'wp-title-layer' ); ?></h3>
		<table class="widefat striped wptl-health-table wptl-channel-table">
			<thead><tr>
				<th><?php esc_html_e( 'Channel', 'wp-title-layer' ); ?></th>
				<th><?php esc_html_e( 'Current source', 'wp-title-layer' ); ?></th>
				<th><?php esc_html_e( 'Current result or template', 'wp-title-layer' ); ?></th>
				<th><?php esc_html_e( 'Interpretation', 'wp-title-layer' ); ?></th>
			</tr></thead>
			<tbody>
				<?php self::channel_row( __( 'Visible archive title', 'wp-title-layer' ), self::source_label( (string) ( $visible['source'] ?? '' ) ), (string) ( $visible['value'] ?? '' ), __( 'WordPress term name; WP Title Layer does not use this field as an SEO override.', 'wp-title-layer' ) ); ?>
				<?php self::channel_row( __( 'Search-result title', 'wp-title-layer' ), $rank_math ? self::source_label( (string) ( $search['source'] ?? '' ) ) : __( 'Other or no supported provider', 'wp-title-layer' ), $rank_math ? (string) ( $search['value'] ?? '' ) : '', $rank_math ? self::policy_label( (string) ( $search['policy'] ?? '' ) ) : __( 'Inspect the active provider or rendered page source.', 'wp-title-layer' ) ); ?>
				<?php self::channel_row( __( 'Search description', 'wp-title-layer' ), $rank_math ? self::source_label( (string) ( $description['source'] ?? '' ) ) : __( 'Other or no supported provider', 'wp-title-layer' ), $rank_math ? (string) ( $description['value'] ?? '' ) : '', $rank_math ? __( 'A variable template may still resolve to an empty description if the Series description is empty.', 'wp-title-layer' ) : __( 'Not verified by WP Title Layer.', 'wp-title-layer' ) ); ?>
				<?php self::channel_row( __( 'Canonical URL', 'wp-title-layer' ), $rank_math ? self::canonical_label( (string) ( $canonical['state'] ?? '' ) ) : __( 'Other or no supported provider', 'wp-title-layer' ), $rank_math ? (string) ( $canonical['value'] ?? '' ) : '', self::canonical_help( (string) ( $canonical['state'] ?? '' ), $rank_math ) ); ?>
				<?php self::channel_row( __( 'Robots', 'wp-title-layer' ), $rank_math ? self::source_label( (string) ( $robots['source'] ?? '' ) ) : __( 'Other or no supported provider', 'wp-title-layer' ), $rank_math ? implode( ', ', (array) ( $robots['values'] ?? array() ) ) : '', $rank_math ? ( ! empty( $robots['indexable'] ) ? __( 'Indexing is allowed by this effective setting.', 'wp-title-layer' ) : __( 'This landing page is currently noindex.', 'wp-title-layer' ) ) : __( 'Not verified by WP Title Layer.', 'wp-title-layer' ) ); ?>
				<?php self::channel_row( __( 'XML sitemap', 'wp-title-layer' ), $rank_math ? __( 'Rank Math', 'wp-title-layer' ) : __( 'Other or no supported provider', 'wp-title-layer' ), $rank_math ? ( ! empty( $sitemap['included'] ) ? __( 'Included', 'wp-title-layer' ) : __( 'Not included', 'wp-title-layer' ) ) : '', $rank_math ? self::sitemap_help( $sitemap ) : __( 'Not verified by WP Title Layer.', 'wp-title-layer' ) ); ?>
				<?php self::channel_row( __( 'Facebook title', 'wp-title-layer' ), $rank_math ? self::source_label( (string) ( $facebook['source'] ?? '' ) ) : __( 'Other or no supported provider', 'wp-title-layer' ), $rank_math ? (string) ( $facebook['value'] ?? '' ) : '', $rank_math ? self::social_help( $facebook ) : __( 'Not verified by WP Title Layer.', 'wp-title-layer' ) ); ?>
				<?php self::channel_row( __( 'Twitter title', 'wp-title-layer' ), $rank_math ? self::source_label( (string) ( $twitter['source'] ?? '' ) ) : __( 'Other or no supported provider', 'wp-title-layer' ), $rank_math ? (string) ( $twitter['value'] ?? '' ) : '', $rank_math ? self::social_help( $twitter ) : __( 'Not verified by WP Title Layer.', 'wp-title-layer' ) ); ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string,int> $counts @param array<string,mixed> $provider */
	private static function render_article_override_summary( array $counts, array $provider ): void {
		?>
		<div class="wptl-channel-article-summary">
			<h3><?php esc_html_e( 'Article-level search and sharing overrides', 'wp-title-layer' ); ?></h3>
			<?php if ( 'rank_math' !== (string) ( $provider['state'] ?? '' ) ) : ?>
				<p><?php esc_html_e( 'Rank Math is not the sole verified provider here, so WP Title Layer does not interpret stored Rank Math post metadata as the current channel policy.', 'wp-title-layer' ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( __( 'Among %1$s public members: %2$s have an SEO-title override (%3$s use a WP Title Layer variable), %4$s have a Facebook-title override (%5$s use a WP Title Layer variable), and %6$s have a Twitter-title override (%7$s use a WP Title Layer variable).', 'wp-title-layer' ), number_format_i18n( (int) ( $counts['members'] ?? 0 ) ), number_format_i18n( (int) ( $counts['seo_titles'] ?? 0 ) ), number_format_i18n( (int) ( $counts['seo_titles_with_wptl'] ?? 0 ) ), number_format_i18n( (int) ( $counts['facebook_titles'] ?? 0 ) ), number_format_i18n( (int) ( $counts['facebook_titles_with_wptl'] ?? 0 ) ), number_format_i18n( (int) ( $counts['twitter_titles'] ?? 0 ) ), number_format_i18n( (int) ( $counts['twitter_titles_with_wptl'] ?? 0 ) ) ) ); ?></p>
				<p class="description"><?php esc_html_e( 'These counts describe explicit overrides; they do not judge whether every article should include a subtitle. Edit those titles in Rank Math, not on this report.', 'wp-title-layer' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function channel_row( string $channel, string $source, string $value, string $help ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $channel ); ?></th>
			<td><?php echo esc_html( $source ); ?></td>
			<td><?php echo '' !== trim( $value ) ? '<code>' . esc_html( self::value_preview( $value ) ) . '</code>' : '<span class="wptl-health-meta">' . esc_html__( 'No explicit value', 'wp-title-layer' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			<td><?php echo esc_html( $help ); ?></td>
		</tr>
		<?php
	}

	private static function summary_card( string $label, string $value, string $description, string $tone ): void {
		?>
		<div class="wptl-health-summary wptl-health-summary--<?php echo esc_attr( sanitize_html_class( $tone ) ); ?>">
			<span class="wptl-health-summary__value"><?php echo esc_html( $value ); ?></span>
			<strong><?php echo esc_html( $label ); ?></strong>
			<span><?php echo esc_html( $description ); ?></span>
		</div>
		<?php
	}

	private static function badge( string $label, string $tone ): void {
		echo '<span class="wptl-health-badge wptl-health-badge--' . esc_attr( sanitize_html_class( $tone ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/** @param array<string,mixed> $provider */
	private static function provider_label( array $provider ): string {
		$state = (string) ( $provider['state'] ?? 'none' );
		if ( 'rank_math' === $state ) {
			return __( 'Rank Math', 'wp-title-layer' );
		}
		if ( 'multiple_providers' === $state ) {
			return __( 'Multiple providers detected', 'wp-title-layer' );
		}
		if ( 'other_provider' === $state ) {
			return implode( ', ', (array) ( $provider['labels'] ?? array() ) );
		}
		return __( 'No supported provider detected', 'wp-title-layer' );
	}

	/** @param array<string,mixed> $provider */
	private static function provider_description( array $provider ): string {
		$state = (string) ( $provider['state'] ?? 'none' );
		if ( 'rank_math' === $state ) {
			return __( 'Local Rank Math settings can be interpreted.', 'wp-title-layer' );
		}
		if ( 'multiple_providers' === $state ) {
			return __( 'Confirm which provider emits the final tags.', 'wp-title-layer' );
		}
		if ( 'other_provider' === $state ) {
			return __( 'Its metadata is not interpreted here.', 'wp-title-layer' );
		}
		return __( 'Rendered source remains the final check.', 'wp-title-layer' );
	}

	/** @param array<string,mixed> $provider */
	private static function provider_boundary( array $provider ): string {
		$state = (string) ( $provider['state'] ?? 'none' );
		if ( 'multiple_providers' === $state ) {
			return __( 'More than one SEO provider appears active. WP Title Layer will not guess which one emits the final canonical, robots, search title, or social tags; confirm ownership in the providers and rendered page source.', 'wp-title-layer' );
		}
		if ( 'other_provider' === $state ) {
			return __( 'Another SEO provider appears active. This report identifies the hand-off but does not interpret that provider’s private option schema; confirm the final tags in its interface and rendered page source.', 'wp-title-layer' );
		}
		return __( 'No supported SEO provider is currently detected. Stored settings from an inactive plugin are not treated as authoritative; inspect the rendered page source before deciding that a channel is unconfigured.', 'wp-title-layer' );
	}

	private static function source_label( string $source ): string {
		$labels = array(
			'wordpress_term'       => __( 'WordPress Series name', 'wp-title-layer' ),
			'term_override'        => __( 'Term-level Rank Math override', 'wp-title-layer' ),
			'taxonomy_option'      => __( 'Rank Math taxonomy setting', 'wp-title-layer' ),
			'post_type_option'     => __( 'Rank Math post-type setting', 'wp-title-layer' ),
			'provider_default'     => __( 'Rank Math default', 'wp-title-layer' ),
			'global'               => __( 'Rank Math global setting', 'wp-title-layer' ),
			'inherits_search_title'=> __( 'Inherits the SEO title', 'wp-title-layer' ),
			'unverified'           => __( 'Unverified', 'wp-title-layer' ),
		);
		return $labels[ $source ] ?? __( 'Unverified', 'wp-title-layer' );
	}

	private static function policy_label( string $policy ): string {
		$labels = array(
			'title_with_subtitle' => __( 'Title and conditional subtitle', 'wp-title-layer' ),
			'subtitle'            => __( 'Subtitle variable included', 'wp-title-layer' ),
			'series'              => __( 'Series variable included', 'wp-title-layer' ),
			'series_term'         => __( 'Series term variable included', 'wp-title-layer' ),
			'unchanged'           => __( 'No WP Title Layer variable', 'wp-title-layer' ),
		);
		return $labels[ $policy ] ?? $labels['unchanged'];
	}

	private static function canonical_label( string $state ): string {
		$labels = array(
			'explicit'         => __( 'Explicit Rank Math override', 'wp-title-layer' ),
			'provider_self'    => __( 'Rank Math self-canonical default', 'wp-title-layer' ),
			'invalid_override' => __( 'Invalid explicit override', 'wp-title-layer' ),
			'unverified'       => __( 'Unverified', 'wp-title-layer' ),
		);
		return $labels[ $state ] ?? $labels['unverified'];
	}

	private static function category_canonical_label( string $state ): string {
		$labels = array(
			'self'             => __( 'Self-canonical', 'wp-title-layer' ),
			'points_to_series' => __( 'Points to this Series', 'wp-title-layer' ),
			'explicit_other'   => __( 'Explicit other target', 'wp-title-layer' ),
			'invalid'          => __( 'Invalid override', 'wp-title-layer' ),
			'unverified'       => __( 'Provider not verified', 'wp-title-layer' ),
		);
		return $labels[ $state ] ?? $labels['unverified'];
	}

	private static function overlap_risk_label( string $risk ): string {
		$labels = array(
			'category_points_to_series' => __( 'Configured to consolidate on Series.', 'wp-title-layer' ),
			'series_points_to_category' => __( 'Series is configured to consolidate on Category.', 'wp-title-layer' ),
			'parallel_self_canonicals'  => __( 'Both exact entry points appear self-canonical.', 'wp-title-layer' ),
			'canonical_review'          => __( 'Exact duplicate; canonical direction needs review.', 'wp-title-layer' ),
			'provider_unverified'       => __( 'Exact duplicate; verify the active provider.', 'wp-title-layer' ),
			'partial_overlap'           => __( 'Partial overlap alone does not determine canonical direction.', 'wp-title-layer' ),
		);
		return $labels[ $risk ] ?? __( 'Review in the active SEO provider.', 'wp-title-layer' );
	}

	private static function canonical_help( string $state, bool $rank_math ): string {
		if ( ! $rank_math ) {
			return __( 'Confirm the canonical emitted by the active provider or page source.', 'wp-title-layer' );
		}
		if ( 'explicit' === $state ) {
			return __( 'Confirm this target is the preferred landing page before changing relationships or redirects.', 'wp-title-layer' );
		}
		if ( 'provider_self' === $state ) {
			return __( 'No term override is stored; Rank Math should use this Series archive URL.', 'wp-title-layer' );
		}
		if ( 'invalid_override' === $state ) {
			return __( 'The stored override is not a valid HTTP(S) URL and needs review in Rank Math.', 'wp-title-layer' );
		}
		return __( 'The effective canonical could not be verified from the supported provider.', 'wp-title-layer' );
	}

	/** @param array<string,mixed> $sitemap */
	private static function sitemap_help( array $sitemap ): string {
		if ( empty( $sitemap['module_active'] ) ) {
			return __( 'The Rank Math sitemap module is disabled.', 'wp-title-layer' );
		}
		if ( empty( $sitemap['taxonomy_enabled'] ) ) {
			return __( 'The Series taxonomy is disabled in Rank Math sitemaps.', 'wp-title-layer' );
		}
		if ( ! empty( $sitemap['term_excluded'] ) ) {
			return __( 'This Series term is explicitly excluded from the sitemap.', 'wp-title-layer' );
		}
		return __( 'The sitemap module, taxonomy, and this term all allow inclusion.', 'wp-title-layer' );
	}

	/** @param array<string,mixed> $social */
	private static function social_help( array $social ): string {
		if ( 'inherits_search_title' === (string) ( $social['source'] ?? '' ) ) {
			return __( 'No term-level social title is stored, so Rank Math inherits the effective SEO title.', 'wp-title-layer' );
		}
		return self::policy_label( (string) ( $social['policy'] ?? '' ) );
	}

	private static function value_preview( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value, true ) ) ?: '' );
		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $value, 0, 180, '…', 'UTF-8' ) : wp_html_excerpt( $value, 180, '…' );
	}

	/** @param array<string,int> $args */
	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/** @param array<string,int> $args */
	private static function pagination( int $page, int $total_pages, array $args ): void {
		if ( $total_pages < 2 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'channel_page' => '%#%' ), $args ), admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => max( 1, $page ),
				'total'     => max( 1, $total_pages ),
				'type'      => 'list',
				'prev_text' => __( 'Previous page', 'wp-title-layer' ),
				'next_text' => __( 'Next page', 'wp-title-layer' ),
			)
		);
		if ( is_string( $links ) ) {
			echo '<nav class="wptl-health-pagination" aria-label="' . esc_attr__( 'Report pages', 'wp-title-layer' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}
}
