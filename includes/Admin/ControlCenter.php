<?php
/**
 * Read-only administration control center.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Gives non-technical administrators one safe starting point without moving or
 * duplicating any of the existing settings and migration workflows.
 */
final class ControlCenter {
	public const PAGE_SLUG = 'wp-title-layer-home';

	/** @var bool */
	private static $registered = false;

	/** @var bool */
	private static $legacy_active = false;

	/**
	 * Register this class before the legacy-plugin early return so the page exists
	 * in both normal and migration-only modes.
	 */
	public static function register( bool $legacy_active = false ): void {
		if ( self::$registered || ! is_admin() ) {
			return;
		}
		self::$registered = true;
		self::$legacy_active = $legacy_active;

		add_action( 'admin_menu', array( __CLASS__, 'addPage' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueueAssets' ) );
		if ( defined( 'WPTL_FILE' ) ) {
			add_filter( 'plugin_action_links_' . plugin_basename( WPTL_FILE ), array( __CLASS__, 'pluginActionLinks' ) );
		}
	}

	/** @param array<int,string> $links Existing plugin-row links. */
	public static function pluginActionLinks( array $links ): array {
		$control_center = '<a href="' . esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Control Center', 'wp-title-layer' ) . '</a>';
		array_unshift( $links, $control_center );
		return $links;
	}

	public static function addPage(): void {
		add_menu_page(
			__( 'WP Title Layer Control Center', 'wp-title-layer' ),
			__( 'WP Title Layer', 'wp-title-layer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			self::menuIcon(),
			58
		);
	}

	/** Monochrome title-and-layers mark; WordPress recolors SVG menu icons. */
	private static function menuIcon(): string {
		$svg = '<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill="#a7aaad" d="M3 3h14v2h-6v5H9V5H3V3zm1 9h12v2H4v-2zm2 4h8v2H6v-2z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function enqueueAssets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$version = defined( 'WPTL_VERSION' ) ? (string) WPTL_VERSION : '1.0.0-rc.1';
		$url     = defined( 'WPTL_URL' )
			? trailingslashit( (string) WPTL_URL ) . 'assets/admin.css'
			: plugin_dir_url( dirname( __DIR__, 2 ) . '/wp-title-layer.php' ) . 'assets/admin.css';

		wp_enqueue_style( 'wptl-control-center', $url, array(), $version );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view WP Title Layer settings.', 'wp-title-layer' ) );
		}

		$snapshot = self::snapshot();
		$overall  = self::overallState( $snapshot );
		$cards    = self::cards( $snapshot );
		$steps    = self::guideSteps( $snapshot );
		?>
		<div class="wrap wptl-control-center">
			<section class="wptl-cc-hero wptl-cc-tone-<?php echo esc_attr( $overall['tone'] ); ?>" aria-labelledby="wptl-control-center-title">
				<div class="wptl-cc-hero__body">
					<p class="wptl-cc-eyebrow"><?php esc_html_e( 'Control Center', 'wp-title-layer' ); ?></p>
					<h1 id="wptl-control-center-title"><?php esc_html_e( 'WP Title Layer', 'wp-title-layer' ); ?></h1>
					<p class="wptl-cc-hero__status"><strong><?php echo esc_html( $overall['label'] ); ?></strong></p>
					<p class="wptl-cc-hero__summary"><?php echo esc_html( $overall['summary'] ); ?></p>
				</div>
				<?php self::renderAction( $overall['action'], 'button button-primary button-hero' ); ?>
			</section>

			<div class="wptl-cc-card-grid">
				<?php foreach ( $cards as $card ) : ?>
					<?php self::renderCard( $card ); ?>
				<?php endforeach; ?>
			</div>

			<section class="wptl-cc-guide" aria-labelledby="wptl-cc-guide-title">
				<div class="wptl-cc-section-heading">
					<div>
						<p class="wptl-cc-eyebrow"><?php esc_html_e( 'A safe first run', 'wp-title-layer' ); ?></p>
						<h2 id="wptl-cc-guide-title"><?php esc_html_e( 'Start here', 'wp-title-layer' ); ?></h2>
					</div>
					<p><?php esc_html_e( 'Work through these steps with one sample article before changing the whole site.', 'wp-title-layer' ); ?></p>
				</div>
				<ol class="wptl-cc-steps">
					<?php foreach ( $steps as $index => $step ) : ?>
						<li class="wptl-cc-step">
							<span class="wptl-cc-step__number" aria-hidden="true"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
							<div class="wptl-cc-step__body">
								<div class="wptl-cc-step__heading">
									<h3><?php echo esc_html( $step['title'] ); ?></h3>
									<span class="wptl-cc-step__status"><?php echo esc_html( $step['status'] ); ?></span>
								</div>
								<p><?php echo esc_html( $step['description'] ); ?></p>
								<?php self::renderAction( $step['action'], 'button button-secondary' ); ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>

			<aside class="wptl-cc-boundary" aria-labelledby="wptl-cc-acf-title">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<div>
					<h2 id="wptl-cc-acf-title"><?php esc_html_e( 'About ACF', 'wp-title-layer' ); ?></h2>
					<p><?php echo esc_html( self::acfBoundaryText( $snapshot['migration'] ) ); ?></p>
					<p><?php esc_html_e( 'This report cannot confirm whether your theme or another plugin uses other ACF fields. Check those separately before removing ACF.', 'wp-title-layer' ); ?></p>
				</div>
			</aside>
		</div>
		<?php
	}

	/**
	 * Build the complete read-only view model.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		// Capture this before WP Title Layer loads its own compatibility helpers.
		// A later function_exists() check would mistake those helpers for the
		// legacy plugin and lock every normal installation.
		$legacy_active = self::$legacy_active;
		$taxonomy      = self::taxonomySnapshot();

		return array(
			'legacy_active' => $legacy_active,
			'urls'          => self::urls( $taxonomy ),
			'migration'     => self::migrationSnapshot( $legacy_active ),
			'presentation'  => self::presentationSnapshot( $legacy_active ),
			'series'        => self::seriesSnapshot( $taxonomy, $legacy_active ),
			'reader'        => self::readerSnapshot( $legacy_active ),
		);
	}

	/**
	 * @param array<string,mixed> $snapshot Complete dashboard state.
	 * @return array<string,mixed>
	 */
	private static function overallState( array $snapshot ): array {
		$migration = $snapshot['migration'];
		$urls      = $snapshot['urls'];
		$state     = (string) ( $migration['state'] ?? 'unavailable' );

		if ( in_array( $state, array( 'running', 'rollback_running' ), true ) ) {
			$is_rollback = 'rollback_running' === $state;
			return array(
				'tone'    => 'warning',
				'label'   => $is_rollback ? __( 'A rollback is still in progress', 'wp-title-layer' ) : __( 'A migration is still in progress', 'wp-title-layer' ),
				'summary' => __( 'Completed batches are already saved. Return to the migration screen to continue the same run.', 'wp-title-layer' ),
				'action'  => self::action( $is_rollback ? __( 'Continue rollback', 'wp-title-layer' ) : __( 'Continue migration', 'wp-title-layer' ), $urls['migration'] ),
			);
		}

		if ( ! empty( $snapshot['legacy_active'] ) ) {
			return array(
				'tone'    => 'warning',
				'label'   => __( 'Finish the old-title migration first', 'wp-title-layer' ),
				'summary' => __( 'Secondary Title is still active, so WP Title Layer is keeping its editor and front-end output safely paused.', 'wp-title-layer' ),
				'action'  => self::action( __( 'Check and migrate old data', 'wp-title-layer' ), $urls['migration'] ),
			);
		}

		if ( empty( $migration['available'] ) ) {
			return array(
				'tone'    => 'warning',
				'label'   => __( 'Some status information is unavailable', 'wp-title-layer' ),
				'summary' => __( 'WP Title Layer could not read the migration module. No settings were changed; open the migration screen to check the installation.', 'wp-title-layer' ),
				'action'  => self::action( __( 'Check migration', 'wp-title-layer' ), $urls['migration'] ),
			);
		}

		if ( 'scan_incomplete' === $state ) {
			return array(
				'tone'    => 'warning',
				'label'   => __( 'The old-data preview is incomplete', 'wp-title-layer' ),
				'summary' => __( 'Run a complete read-only scan before starting migration.', 'wp-title-layer' ),
				'action'  => self::action( __( 'Run a complete scan', 'wp-title-layer' ), $urls['migration'] ),
			);
		}

		if ( 'not_scanned' === $state ) {
			return array(
				'tone'    => 'notice',
				'label'   => __( 'Old title data has not been scanned', 'wp-title-layer' ),
				'summary' => __( 'Run the read-only scan to see what can be copied and what needs review.', 'wp-title-layer' ),
				'action'  => self::action( __( 'Scan old data', 'wp-title-layer' ), $urls['migration'] ),
			);
		}

		if ( 'ready_to_copy' === $state ) {
			return array(
				'tone'    => 'notice',
				'label'   => __( 'Old title data is ready to copy', 'wp-title-layer' ),
				'summary' => __( 'Review the read-only report before copying. Existing WP Title Layer subtitles will not be overwritten.', 'wp-title-layer' ),
				'action'  => self::action( __( 'Review migration', 'wp-title-layer' ), $urls['migration'] ),
			);
		}

		if ( 'conflicts' === $state ) {
			return array(
				'tone'    => 'warning',
				'label'   => __( 'Some old title data needs review', 'wp-title-layer' ),
				'summary' => __( 'Conflicting source or target values were left unchanged. Review the migration report before proceeding.', 'wp-title-layer' ),
				'action'  => self::action( __( 'Review conflicts', 'wp-title-layer' ), $urls['migration'] ),
			);
		}

		return array(
			'tone'    => 'success',
			'label'   => __( 'WP Title Layer is ready to use', 'wp-title-layer' ),
			'summary' => __( 'Choose how titles should appear, then test one ordinary article and one Series article before wider use.', 'wp-title-layer' ),
			'action'  => self::action( __( 'Set up title display', 'wp-title-layer' ), $urls['settings'] ),
		);
	}

	/**
	 * @param array<string,mixed> $snapshot Complete dashboard state.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cards( array $snapshot ): array {
		$migration   = $snapshot['migration'];
		$presentation = $snapshot['presentation'];
		$series       = $snapshot['series'];
		$reader       = $snapshot['reader'];
		$urls         = $snapshot['urls'];
		$locked       = ! empty( $snapshot['legacy_active'] );
		$migration_state = (string) ( $migration['state'] ?? 'unavailable' );
		$run_active      = in_array( $migration_state, array( 'running', 'rollback_running' ), true );

		$migration_status = __( 'No old data found', 'wp-title-layer' );
		$migration_tone   = 'success';
		if ( $run_active ) {
			$migration_status = 'rollback_running' === $migration_state ? __( 'Rollback in progress', 'wp-title-layer' ) : __( 'Migration in progress', 'wp-title-layer' );
			$migration_tone   = 'warning';
		} elseif ( 'unavailable' === $migration_state ) {
			$migration_status = __( 'Status unavailable', 'wp-title-layer' );
			$migration_tone   = 'muted';
		} elseif ( 'scan_incomplete' === $migration_state ) {
			$migration_status = __( 'Scan incomplete', 'wp-title-layer' );
			$migration_tone   = 'warning';
		} elseif ( 'conflicts' === $migration_state ) {
			$migration_status = __( 'Items need review', 'wp-title-layer' );
			$migration_tone   = 'warning';
		} elseif ( 'ready_to_copy' === $migration_state ) {
			$migration_status = __( 'Ready to copy', 'wp-title-layer' );
			$migration_tone   = 'notice';
		} elseif ( 'not_scanned' === $migration_state ) {
			$migration_status = __( 'Not scanned yet', 'wp-title-layer' );
			$migration_tone   = 'notice';
		} elseif ( 'copied' === $migration_state ) {
			$migration_status = __( 'Already copied', 'wp-title-layer' );
		}

		if ( $run_active || 'run' === (string) ( $migration['state_source'] ?? '' ) ) {
			$migration_facts = array(
				__( 'Processed in latest run', 'wp-title-layer' )   => $migration['run_processed'],
				__( 'Copied in latest run', 'wp-title-layer' )      => $migration['run_migrated'],
				__( 'Already equal in latest run', 'wp-title-layer' ) => $migration['run_same'],
				__( 'Need review in latest run', 'wp-title-layer' ) => $migration['run_conflicts'] + $migration['run_errors'],
			);
		} elseif ( empty( $migration['available'] ) ) {
			$migration_facts = array( __( 'Migration report', 'wp-title-layer' ) => __( 'Unavailable', 'wp-title-layer' ) );
		} else {
			$source_label = $migration['has_scan'] ? __( 'Posts in last scan', 'wp-title-layer' ) : __( 'Detected source matches', 'wp-title-layer' );
			$migration_facts = array(
				$source_label                                => $migration['source_posts'],
				__( 'Ready in last scan', 'wp-title-layer' ) => $migration['scan_ready'],
				__( 'Already equal', 'wp-title-layer' )      => $migration['scan_same'],
				__( 'Need review', 'wp-title-layer' )        => $migration['scan_conflicts'],
			);
		}

		$display_mode = (string) $presentation['display_mode'];
		$display_auto = 'auto-prepend' === $display_mode;
		$display_takeover = 'replace-theme-title' === $display_mode;
		$display_status = $locked
			? __( 'Available after migration', 'wp-title-layer' )
			: ( ! $presentation['available']
				? __( 'Status unavailable', 'wp-title-layer' )
				: ( $display_takeover ? __( 'Theme title takeover', 'wp-title-layer' ) : ( $display_auto ? __( 'Legacy body insertion', 'wp-title-layer' ) : __( 'Manual placement', 'wp-title-layer' ) ) ) );
		$display_summary = $locked
			? __( 'Title output is paused while Secondary Title remains active.', 'wp-title-layer' )
			: ( ! $presentation['available']
				? __( 'The control center could not read the saved title-display settings.', 'wp-title-layer' )
				: ( $display_takeover
					? sprintf(
					/* translators: 1: theme adapter, 2: content type labels. */
					__( '%1$s replaces the verified title position for: %2$s. The article body is not used as the title slot.', 'wp-title-layer' ),
					$presentation['theme_support_label'],
					$presentation['auto_types_label']
				)
				: ( $display_auto
				? sprintf(
					/* translators: %s: comma-separated content type labels. */
					__( 'The Title Layer is added before the article body for: %s. The theme title must be disabled separately.', 'wp-title-layer' ),
					$presentation['auto_types_label']
				)
					: __( 'Nothing is inserted automatically. Add the Title Layer block or theme API call where the title should appear.', 'wp-title-layer' ) ) ) );
		$display_facts = $presentation['available']
			? array(
				__( 'Default template', 'wp-title-layer' ) => $presentation['default_template_label'],
				__( 'Category rules', 'wp-title-layer' )   => $presentation['category_rule_count'],
				__( 'Title element', 'wp-title-layer' )    => $display_takeover ? __( 'Inherited from theme', 'wp-title-layer' ) : ( $display_auto ? strtoupper( $presentation['heading_tag'] ) : __( 'Chosen at placement', 'wp-title-layer' ) ),
			)
			: array( __( 'Display settings', 'wp-title-layer' ) => __( 'Unavailable', 'wp-title-layer' ) );

		$series_status = $locked
			? __( 'Available after migration', 'wp-title-layer' )
			: ( ! $series['available'] ? __( 'Status unavailable', 'wp-title-layer' ) : ( 0 < $series['total'] ? __( 'Series available', 'wp-title-layer' ) : __( 'Not created yet (optional)', 'wp-title-layer' ) ) );
		$series_summary = $locked
			? __( 'Finish migration before assigning Series in the article editor.', 'wp-title-layer' )
			: ( ! $series['available']
				? __( 'The Series definition is not currently available.', 'wp-title-layer' )
				: ( 0 < $series['total']
					? __( 'Each article can belong to one Series. Ordered and unordered Series remain separate reading models.', 'wp-title-layer' )
					: __( 'Create a Series only when several articles belong together. Ordinary articles do not require one.', 'wp-title-layer' ) ) );
		$series_facts = $series['available']
			? array(
				__( 'All Series', 'wp-title-layer' )             => $series['total'],
				__( 'With reading order', 'wp-title-layer' )     => $series['ordered'],
				__( 'Using insertable sequence order', 'wp-title-layer' ) => $series['managed'],
				__( 'Using book structure', 'wp-title-layer' )   => $series['book_structure'],
				__( 'Divided into seasons', 'wp-title-layer' )   => $series['seasoned'],
			)
			: array( __( 'Series definition', 'wp-title-layer' ) => __( 'Unavailable', 'wp-title-layer' ) );

		$reader_status = $locked
			? __( 'Available after migration', 'wp-title-layer' )
			: ( ! $reader['available'] ? __( 'Status unavailable', 'wp-title-layer' ) : ( $reader['archive_enabled'] || $reader['after_content_enabled'] ? __( 'Automatic features enabled', 'wp-title-layer' ) : __( 'Optional features are off', 'wp-title-layer' ) ) );
		$reader_facts = $reader['available']
			? array(
				__( 'Series archive', 'wp-title-layer' )            => $reader['archive_label'],
				__( 'Structured archive subtitles', 'wp-title-layer' ) => $reader['archive_enabled']
					? self::onOff( $reader['archive_subtitles_enabled'] )
					: __( 'Structured layout only', 'wp-title-layer' ),
				__( 'After-article navigation', 'wp-title-layer' )  => self::onOff( $reader['after_content_enabled'] ),
				__( 'Manual shortcode', 'wp-title-layer' )          => self::onOff( $reader['shortcode_enabled'] ),
			)
			: array( __( 'Reader settings', 'wp-title-layer' ) => __( 'Unavailable', 'wp-title-layer' ) );

		return array(
			array(
				'id'      => 'migration',
				'icon'    => 'database-import',
				'title'   => __( 'Content migration', 'wp-title-layer' ),
				'status'  => $migration_status,
				'tone'    => $migration_tone,
				'summary' => empty( $migration['available'] )
					? __( 'The migration module did not return a readable status. No data was changed here.', 'wp-title-layer' )
					: __( 'Secondary Title and verified ACF subtitle data are copied conservatively. Source data is retained.', 'wp-title-layer' ),
				'facts'   => $migration_facts,
				'note'    => self::acfShortText( $migration ),
				'action'  => self::action( __( 'Open migration', 'wp-title-layer' ), $urls['migration'] ),
			),
			array(
				'id'      => 'display',
				'icon'    => 'editor-textcolor',
				'title'   => __( 'Title display', 'wp-title-layer' ),
				'status'  => $display_status,
				'tone'    => $locked || ! $presentation['available'] ? 'muted' : ( $display_takeover ? 'success' : ( $display_auto ? 'warning' : 'notice' ) ),
				'summary' => $display_summary,
				'facts'   => $display_facts,
				'note'    => '',
				'action'  => self::action( __( 'Set title display', 'wp-title-layer' ), $urls['settings'], ! $locked && $presentation['available'] ),
			),
			array(
				'id'      => 'series',
				'icon'    => 'book-alt',
				'title'   => __( 'Series', 'wp-title-layer' ),
				'status'  => $series_status,
				'tone'    => $locked || ! $series['available'] ? 'muted' : ( 0 < $series['total'] ? 'success' : 'notice' ),
				'summary' => $series_summary,
				'facts'   => $series_facts,
				'note'    => '',
				'action'  => 0 < $series['total']
					? self::action( __( 'Check Series health', 'wp-title-layer' ), $urls['series_health'], ! $locked && $series['can_manage'] )
					: self::action( __( 'Manage Series', 'wp-title-layer' ), $urls['series'], ! $locked && $series['can_manage'] ),
			),
			array(
				'id'      => 'reader',
				'icon'    => 'controls-forward',
				'title'   => __( 'Reading experience', 'wp-title-layer' ),
				'status'  => $reader_status,
				'tone'    => $locked || ! $reader['available'] ? 'muted' : ( $reader['archive_enabled'] || $reader['after_content_enabled'] ? 'success' : 'notice' ),
				'summary' => $locked
					? __( 'Reading features are paused with the rest of WP Title Layer output.', 'wp-title-layer' )
					: ( ! $reader['available']
						? __( 'The control center could not read the saved reading-experience settings.', 'wp-title-layer' )
						: __( 'Ordered Series can offer an archive, previous/next links, a start link, and reading progress.', 'wp-title-layer' ) ),
				'facts'   => $reader_facts,
				'note'    => __( 'Unordered Series never receive invented previous/next navigation.', 'wp-title-layer' ),
				'action'  => self::action( __( 'Set reading experience', 'wp-title-layer' ), $urls['settings'], ! $locked && $reader['available'] ),
			),
			array(
				'id'      => 'channels',
				'icon'    => 'networking',
				'title'   => __( 'Landing pages and channels', 'wp-title-layer' ),
				'status'  => $locked ? __( 'Available after migration', 'wp-title-layer' ) : __( 'Read-only check available', 'wp-title-layer' ),
				'tone'    => $locked ? 'muted' : 'notice',
				'summary' => __( 'Compare Series and Category entry points, then inspect visible, search, social, canonical, robots, and sitemap ownership.', 'wp-title-layer' ),
				'facts'   => array(
					__( 'Makes SEO changes', 'wp-title-layer' )    => __( 'No', 'wp-title-layer' ),
					__( 'Removes Categories', 'wp-title-layer' )  => __( 'No', 'wp-title-layer' ),
					__( 'Supported provider audit', 'wp-title-layer' ) => __( 'Rank Math', 'wp-title-layer' ),
				),
				'note'    => __( 'Other providers are identified as owners but their private settings are not interpreted.', 'wp-title-layer' ),
				'action'  => self::action( __( 'Check landing & channels', 'wp-title-layer' ), $urls['channel_health'], ! $locked && current_user_can( ChannelHealth::capability() ) ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $snapshot Complete dashboard state.
	 * @return array<int,array<string,mixed>>
	 */
	private static function guideSteps( array $snapshot ): array {
		$migration = $snapshot['migration'];
		$locked    = ! empty( $snapshot['legacy_active'] );
		$migration_state = (string) ( $migration['state'] ?? 'unavailable' );
		$active    = in_array( $migration_state, array( 'running', 'rollback_running' ), true );
		$migration_available = ! empty( $migration['available'] );
		$needs_migration = $locked || in_array(
			$migration_state,
			array( 'unavailable', 'running', 'rollback_running', 'scan_incomplete', 'not_scanned', 'ready_to_copy', 'conflicts' ),
			true
		);
		$series    = $snapshot['series'];
		$display   = $snapshot['presentation'];
		$urls      = $snapshot['urls'];

		$migration_status = $needs_migration ? __( 'Needs attention', 'wp-title-layer' ) : __( 'No migration needed', 'wp-title-layer' );
		if ( $active ) {
			$migration_status = __( 'In progress', 'wp-title-layer' );
		} elseif ( ! $migration_available ) {
			$migration_status = __( 'Status unavailable', 'wp-title-layer' );
		} elseif ( 'copied' === $migration_state ) {
			$migration_status = __( 'Migration complete', 'wp-title-layer' );
		}

		if ( ! $migration_available ) {
			$migration_description = __( 'Open the migration screen to check why its read-only status could not be loaded.', 'wp-title-layer' );
		} elseif ( 'copied' === $migration_state ) {
			$migration_description = __( 'Canonical subtitles are already present or the latest migration completed. The old source metadata may remain by design.', 'wp-title-layer' );
		} elseif ( 'conflicts' === $migration_state ) {
			$migration_description = __( 'Review the values that were left unchanged because their source or target data conflicted.', 'wp-title-layer' );
		} elseif ( 'ready_to_copy' === $migration_state ) {
			$migration_description = __( 'Review the read-only scan, then copy the ready subtitles without overwriting existing WP Title Layer values.', 'wp-title-layer' );
		} elseif ( 'not_scanned' === $migration_state || 'scan_incomplete' === $migration_state ) {
			$migration_description = __( 'Run a complete read-only scan before starting a migration.', 'wp-title-layer' );
		} elseif ( $active ) {
			$migration_description = __( 'Return to the migration screen to continue the saved run.', 'wp-title-layer' );
		} else {
			$migration_description = __( 'No Secondary Title or verified ACF subtitle values currently require migration.', 'wp-title-layer' );
		}

		return array(
			array(
				'title'       => __( 'Check old title data', 'wp-title-layer' ),
				'status'      => $migration_status,
				'description' => $migration_description,
				'action'      => self::action( __( 'Open migration', 'wp-title-layer' ), $urls['migration'], $needs_migration ),
			),
			array(
				'title'       => __( 'Choose how titles appear', 'wp-title-layer' ),
				'status'      => $locked
					? __( 'Waiting for migration', 'wp-title-layer' )
					: ( ! $display['available'] ? __( 'Status unavailable', 'wp-title-layer' ) : ( 'replace-theme-title' === $display['display_mode'] ? __( 'Theme title takeover selected', 'wp-title-layer' ) : ( 'auto-prepend' === $display['display_mode'] ? __( 'Legacy body insertion selected', 'wp-title-layer' ) : __( 'Manual placement selected', 'wp-title-layer' ) ) ) ),
				'description' => __( 'Use verified theme-title takeover when available. Manual placement is the universal fallback; body insertion does not remove the theme title.', 'wp-title-layer' ),
				'action'      => self::action( __( 'Review title display', 'wp-title-layer' ), $urls['settings'], ! $locked && $display['available'] ),
			),
			array(
				'title'       => __( 'Create a Series if needed', 'wp-title-layer' ),
				'status'      => $locked
					? __( 'Waiting for migration', 'wp-title-layer' )
					: ( ! $series['available'] ? __( 'Status unavailable', 'wp-title-layer' ) : ( 0 < $series['total'] ? sprintf( __( '%s created', 'wp-title-layer' ), number_format_i18n( $series['total'] ) ) : __( 'Optional', 'wp-title-layer' ) ) ),
				'description' => __( 'Choose ordered or unordered, then add seasons only when the collection really needs them.', 'wp-title-layer' ),
				'action'      => self::action( __( 'Manage Series', 'wp-title-layer' ), $urls['series'], ! $locked && $series['can_manage'] ),
			),
			array(
				'title'       => __( 'Edit one sample article', 'wp-title-layer' ),
				'status'      => $locked ? __( 'Waiting for migration', 'wp-title-layer' ) : __( 'Recommended next step', 'wp-title-layer' ),
				'description' => __( 'Use the Title Layer panel in the editor sidebar to set a subtitle, Series, and optional per-article template.', 'wp-title-layer' ),
				'action'      => self::action( __( 'Open articles', 'wp-title-layer' ), $urls['articles'], ! $locked && ! empty( $urls['can_edit_posts'] ) ),
			),
			array(
				'title'       => __( 'Review Series structure', 'wp-title-layer' ),
				'status'      => $locked ? __( 'Waiting for migration', 'wp-title-layer' ) : ( 0 < $series['total'] ? __( 'Ready to review', 'wp-title-layer' ) : __( 'Optional', 'wp-title-layer' ) ),
				'description' => __( 'Sequence & Structure initializes insertable order where needed, previews legacy scope without guessing, and arranges each introduction, main, epilogue, or appendix track.', 'wp-title-layer' ),
				'action'      => self::action( __( 'Open Sequence & Structure', 'wp-title-layer' ), $urls['sequence_manager'], ! $locked && 0 < $series['total'] && current_user_can( SequenceManager::capability() ) ),
			),
			array(
				'title'       => __( 'Check the public result', 'wp-title-layer' ),
				'status'      => __( 'Manual check', 'wp-title-layer' ),
				'description' => __( 'Check desktop and mobile, and confirm the page has one visible main title and exactly one intended H1.', 'wp-title-layer' ),
				'action'      => self::action( __( 'View a published sample', 'wp-title-layer' ), $urls['sample'], ! $locked && '' !== $urls['sample'], true ),
			),
			array(
				'title'       => __( 'Check landing pages and title channels', 'wp-title-layer' ),
				'status'      => $locked ? __( 'Waiting for migration', 'wp-title-layer' ) : __( 'Read-only review', 'wp-title-layer' ),
				'description' => __( 'Compare Series and Category archives, then confirm canonical, robots, sitemap, search-title, and social-title ownership before changing SEO settings.', 'wp-title-layer' ),
				'action'      => self::action( __( 'Open channel report', 'wp-title-layer' ), $urls['channel_health'], ! $locked && current_user_can( ChannelHealth::capability() ) ),
			),
		);
	}

	/** @return array<string,mixed> */
	private static function migrationSnapshot( bool $legacy_active ): array {
		$detection = array();
		$available = false;
		$detector  = '\\WPTitleLayer\\Migration\\Detector';
		if ( class_exists( $detector ) ) {
			try {
				$instance = new $detector();
				if ( is_callable( array( $instance, 'detect' ) ) ) {
					$candidate = $instance->detect();
					if ( is_array( $candidate ) ) {
						$detection = $candidate;
						$available = true;
					}
				}
			} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$detection = array();
				$available = false;
			}
		}

		$last_scan_option = self::classConstant( '\\WPTitleLayer\\Migration\\Config', 'LAST_SCAN_OPTION', 'wptl_migration_last_scan' );
		$active_option    = self::classConstant( '\\WPTitleLayer\\Migration\\Config', 'ACTIVE_RUN_OPTION', 'wptl_migration_active_run' );
		$run_index_option = self::classConstant( '\\WPTitleLayer\\Migration\\Config', 'RUN_INDEX_OPTION', 'wptl_migration_runs' );
		$scan             = get_option( $last_scan_option, null );
		$has_scan         = is_array( $scan );
		$scan             = $has_scan ? $scan : array();
		$scan_stats       = isset( $scan['stats'] ) && is_array( $scan['stats'] ) ? $scan['stats'] : array();

		$config       = '\\WPTitleLayer\\Migration\\Config';
		$store        = '\\WPTitleLayer\\Migration\\Store';
		$active_id    = self::cleanMigrationRunId( get_option( $active_option, '' ), $config );
		$active_run   = self::loadMigrationRun( $active_id, $store );
		$latest_run   = array();
		$indexed_runs = get_option( $run_index_option, array() );
		foreach ( is_array( $indexed_runs ) ? $indexed_runs : array() as $indexed_id ) {
			$indexed_id = self::cleanMigrationRunId( $indexed_id, $config );
			if ( '' === $indexed_id ) {
				continue;
			}

			$latest_run = self::loadMigrationRun( $indexed_id, $store );
			if ( $latest_run ) {
				break;
			}
		}

		$active_status = sanitize_key( (string) ( $active_run['status'] ?? '' ) );
		$run           = in_array( $active_status, array( 'running', 'rollback_running' ), true )
			? $active_run
			: ( $latest_run ? $latest_run : $active_run );

		$legacy_posts = max( 0, (int) ( $detection['legacy_data']['candidate_posts'] ?? 0 ) );
		$acf_posts    = max( 0, (int) ( $detection['acf']['acf_subtitle_posts'] ?? 0 ) );
		$acf_series_assignments = 0;
		$fields = self::classConstant( '\\WPTitleLayer\\Migration\\Config', 'ACF_SERIES_FIELDS', array( 'series_key', 'series_title', 'series_order', 'series_has_order', 'series_stage' ) );
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			$acf_series_assignments += max( 0, (int) ( $detection['acf']['series_fields'][ $field ]['value_posts'] ?? 0 ) );
		}
		$acf_series_posts = max( 0, (int) ( $detection['acf']['series_value_posts'] ?? 0 ) );

		$source_posts   = $has_scan ? max( 0, (int) ( $scan_stats['candidate_posts'] ?? 0 ) ) : $legacy_posts + $acf_posts;
		$scan_ready     = max( 0, (int) ( $scan_stats['ready'] ?? 0 ) );
		$scan_same      = max( 0, (int) ( $scan_stats['existing_same'] ?? 0 ) );
		$scan_conflicts = max( 0, (int) ( $scan_stats['conflicts'] ?? 0 ) );
		$scan_truncated = ! empty( $scan['truncated'] );
		$run_counts     = isset( $run['counts'] ) && is_array( $run['counts'] ) ? $run['counts'] : array();
		$run_status     = sanitize_key( (string) ( $run['status'] ?? '' ) );
		$run_conflicts  = max( 0, (int) ( $run_counts['conflict'] ?? 0 ) );
		$run_errors     = max( 0, (int) ( $run_counts['errors'] ?? 0 ) );
		$scan_time      = self::migrationTimestamp( $scan['generated_at'] ?? '' );
		$run_time       = self::migrationRunTimestamp( $run );
		$run_after_scan = ! empty( $run ) && ( ! $has_scan || 0 === $scan_time || ( 0 < $run_time && $run_time >= $scan_time ) );

		$state        = 'no_data';
		$state_source = $has_scan ? 'scan' : 'detection';
		if ( ! $available ) {
			$state        = 'unavailable';
			$state_source = 'module';
		} elseif ( in_array( $run_status, array( 'running', 'rollback_running' ), true ) ) {
			$state        = $run_status;
			$state_source = 'run';
		} elseif ( 'complete' === $run_status && $run_after_scan ) {
			$state        = 0 < $run_conflicts + $run_errors ? 'conflicts' : 'copied';
			$state_source = 'run';
		} elseif ( in_array( $run_status, array( 'rolled_back', 'rollback_complete' ), true ) && $run_after_scan ) {
			// A rollback makes its earlier scan historical. Require a fresh,
			// read-only scan instead of presenting old ready counts as current.
			$state        = 0 < $source_posts ? 'not_scanned' : 'no_data';
			$state_source = 'detection';
		} elseif ( ! $has_scan ) {
			$state = 0 < $source_posts ? 'not_scanned' : 'no_data';
		} elseif ( $scan_truncated ) {
			$state = 'scan_incomplete';
		} elseif ( 0 < $scan_conflicts ) {
			$state = 'conflicts';
		} elseif ( 0 < $scan_ready ) {
			$state = 'ready_to_copy';
		} elseif ( 0 < $scan_same ) {
			$state = 'copied';
		} elseif ( 0 < $source_posts ) {
			// A well-formed scan accounts for every candidate as ready, equal, or
			// conflict. Treat an unaccounted legacy scan conservatively.
			$state = 'not_scanned';
		}

		return array(
			'available'              => $available,
			'legacy_active'          => $legacy_active || ! empty( $detection['secondary_title']['active'] ),
			'state'                  => $state,
			'state_source'           => $state_source,
			'has_scan'               => $has_scan,
			'source_posts'           => $source_posts,
			'scan_ready'             => $scan_ready,
			'scan_same'              => $scan_same,
			'scan_conflicts'         => $scan_conflicts,
			'scan_truncated'         => $scan_truncated,
			'scan_generated_at'      => is_scalar( $scan['generated_at'] ?? null ) ? (string) $scan['generated_at'] : '',
			'run_id'                 => sanitize_key( (string) ( $run['id'] ?? '' ) ),
			'run_status'             => $run_status,
			'run_terminal'           => in_array( $run_status, array( 'complete', 'rolled_back', 'rollback_complete', 'initialization_failed', 'abandoned' ), true ),
			'run_is_newer_than_scan' => $run_after_scan,
			'run_processed'          => max( 0, (int) ( $run_counts['processed'] ?? 0 ) ),
			'run_migrated'           => max( 0, (int) ( $run_counts['migrated'] ?? 0 ) ),
			'run_same'               => max( 0, (int) ( $run_counts['existing_same'] ?? 0 ) ),
			'run_conflicts'          => $run_conflicts,
			'run_errors'             => $run_errors,
			'acf_subtitle_posts'     => $acf_posts,
			'acf_series_assignments' => $acf_series_assignments,
			'acf_series_posts'       => $acf_series_posts,
		);
	}

	/**
	 * Normalize an option value into the same run ID accepted by Migration\Config.
	 *
	 * @param mixed  $value  Stored option value.
	 * @param string $config Migration config class.
	 */
	private static function cleanMigrationRunId( $value, string $config ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( class_exists( $config ) && is_callable( array( $config, 'clean_run_id' ) ) ) {
			return (string) call_user_func( array( $config, 'clean_run_id' ), $value );
		}

		return substr( preg_replace( '/[^a-f0-9]/', '', strtolower( $value ) ) ?: '', 0, 32 );
	}

	/** @return array<string,mixed> */
	private static function loadMigrationRun( string $run_id, string $store ): array {
		if ( '' === $run_id ) {
			return array();
		}

		if ( class_exists( $store ) ) {
			try {
				$instance = new $store();
				if ( is_callable( array( $instance, 'get_run' ) ) ) {
					$candidate = $instance->get_run( $run_id );
					return is_array( $candidate ) ? $candidate : array();
				}
			} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return array();
			}
		}

		$candidate = get_option( 'wptl_migration_run_' . $run_id, null );
		return is_array( $candidate ) ? $candidate : array();
	}

	/** @param mixed $value ISO-8601 date-like value. */
	private static function migrationTimestamp( $value ): int {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return 0;
		}

		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? 0 : $timestamp;
	}

	/** @param array<string,mixed> $run Migration run journal. */
	private static function migrationRunTimestamp( array $run ): int {
		$rollback = isset( $run['rollback'] ) && is_array( $run['rollback'] ) ? $run['rollback'] : array();

		return max(
			self::migrationTimestamp( $rollback['completed_at'] ?? '' ),
			self::migrationTimestamp( $run['completed_at'] ?? '' ),
			self::migrationTimestamp( $run['updated_at'] ?? '' ),
			self::migrationTimestamp( $run['created_at'] ?? '' )
		);
	}

	/** @return array<string,mixed> */
	private static function presentationSnapshot( bool $locked ): array {
		$settings = array(
			'display_mode'      => 'manual',
			'default_template'  => 'standard',
			'category_rules'    => array(),
			'auto_post_types'   => array(),
			'auto_heading_tag'  => 'div',
		);
		$available = false;
		$class     = '\\WPTitleLayer\\Presentation\\SettingsPage';
		if ( class_exists( $class ) && is_callable( array( $class, 'presentationSettings' ) ) ) {
			try {
				$candidate = call_user_func( array( $class, 'presentationSettings' ) );
				if ( is_array( $candidate ) ) {
					$settings  = array_merge( $settings, $candidate );
					$available = true;
				}
			} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$available = false;
			}
		}

		$template_label = sanitize_text_field( (string) $settings['default_template'] );
		$presets        = '\\WPTitleLayer\\Presentation\\Presets';
		if ( class_exists( $presets ) && is_callable( array( $presets, 'choices' ) ) ) {
			try {
				foreach ( (array) call_user_func( array( $presets, 'choices' ) ) as $choice ) {
					if ( is_array( $choice ) && (string) ( $choice['value'] ?? '' ) === (string) $settings['default_template'] ) {
						$template_label = sanitize_text_field( (string) ( $choice['label'] ?? $template_label ) );
						break;
					}
				}
			} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// The stored preset ID remains a safe fallback label.
			}
		}

		$type_labels = array();
		foreach ( (array) $settings['auto_post_types'] as $post_type ) {
			$object = get_post_type_object( sanitize_key( (string) $post_type ) );
			if ( $object ) {
				$type_labels[] = (string) $object->labels->name;
			}
		}

		$display_mode = sanitize_key( (string) $settings['display_mode'] );
		if ( ! in_array( $display_mode, array( 'manual', 'replace-theme-title', 'auto-prepend' ), true ) ) {
			$display_mode = 'manual';
		}
		$theme_support_label = __( 'No verified theme adapter', 'wp-title-layer' );
		$integration         = '\\WPTitleLayer\\Presentation\\ThemeTitleIntegration';
		if ( class_exists( $integration ) && is_callable( array( $integration, 'support' ) ) ) {
			try {
				$support = call_user_func( array( $integration, 'support' ) );
				if ( is_array( $support ) && ! empty( $support['label'] ) ) {
					$theme_support_label = sanitize_text_field( (string) $support['label'] );
				}
			} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Keep the conservative fallback label.
			}
		}

		return array(
			'available'              => $available && ! $locked,
			'display_mode'           => $display_mode,
			'default_template_label' => $template_label,
			'category_rule_count'    => count( (array) $settings['category_rules'] ),
			'auto_types_label'       => $type_labels ? implode( ', ', $type_labels ) : __( 'no content types', 'wp-title-layer' ),
			'heading_tag'            => in_array( $settings['auto_heading_tag'], array( 'h2', 'div' ), true ) ? $settings['auto_heading_tag'] : 'div',
			'theme_support_label'     => $theme_support_label,
		);
	}

	/** @return array<string,mixed> */
	private static function taxonomySnapshot(): array {
		$taxonomy = 'wptl_series';
		$schema   = '\\WPTitleLayer\\Core\\Schema';
		if ( class_exists( $schema ) && is_callable( array( $schema, 'taxonomy' ) ) ) {
			try {
				$candidate = call_user_func( array( $schema, 'taxonomy' ) );
				if ( is_string( $candidate ) && '' !== sanitize_key( $candidate ) ) {
					$taxonomy = sanitize_key( $candidate );
				}
			} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$taxonomy = 'wptl_series';
			}
		}

		$object    = get_taxonomy( $taxonomy );
		$post_type = 'post';
		if ( $object && ! empty( $object->object_type ) ) {
			$types = array_values( array_filter( array_map( 'sanitize_key', (array) $object->object_type ) ) );
			if ( in_array( 'post', $types, true ) ) {
				$post_type = 'post';
			} elseif ( ! empty( $types ) ) {
				$post_type = $types[0];
			}
		}

		return array(
			'key'       => $taxonomy,
			'object'    => $object,
			'post_type' => $post_type,
		);
	}

	/**
	 * @param array<string,mixed> $taxonomy Taxonomy state.
	 * @return array<string,mixed>
	 */
	private static function seriesSnapshot( array $taxonomy, bool $locked ): array {
		$object     = $taxonomy['object'];
		$term_ids   = array();
		$can_manage = false;
		if ( $object ) {
			$capability = isset( $object->cap->manage_terms ) ? (string) $object->cap->manage_terms : 'manage_categories';
			$can_manage = current_user_can( $capability );
			$terms      = get_terms(
				array(
					'taxonomy'   => $taxonomy['key'],
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			$term_ids = is_wp_error( $terms ) ? array() : array_map( 'absint', (array) $terms );
		}

		$ordered  = 0;
		$managed  = 0;
		$seasoned = 0;
		$book_structure = 0;
		foreach ( $term_ids as $term_id ) {
			if ( 'ordered' === (string) get_term_meta( $term_id, 'wptl_series_mode', true ) ) {
				++$ordered;
			}
			if ( 1 === (int) get_term_meta( $term_id, 'wptl_sequence_schema_version', true ) ) {
				++$managed;
			}
			if ( 'seasoned' === (string) get_term_meta( $term_id, 'wptl_series_structure', true ) ) {
				++$seasoned;
			}
			if ( 1 === (int) get_term_meta( $term_id, 'wptl_book_structure_version', true ) ) {
				++$book_structure;
			}
		}

		return array(
			'available'  => (bool) $object,
			'can_manage' => (bool) $object && $can_manage && ! $locked,
			'total'      => count( $term_ids ),
			'ordered'    => $ordered,
			'managed'    => $managed,
			'seasoned'   => $seasoned,
			'book_structure' => $book_structure,
		);
	}

	/** @return array<string,mixed> */
	private static function readerSnapshot( bool $locked ): array {
		$settings = array(
			'archive_template_enabled' => false,
			'archive_show_subtitles'   => true,
			'after_content_enabled'     => false,
			'shortcode_enabled'         => true,
		);
		$available = false;
		$class     = '\\WPTitleLayer\\Reader\\Settings';
		if ( class_exists( $class ) && is_callable( array( $class, 'all' ) ) ) {
			try {
				$candidate = call_user_func( array( $class, 'all' ) );
				if ( is_array( $candidate ) ) {
					$settings  = array_merge( $settings, $candidate );
					$available = true;
				}
			} catch ( \Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$available = false;
			}
		}

		$block_theme    = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$archive_enabled = ! $block_theme && ! empty( $settings['archive_template_enabled'] );

		return array(
			'available'                 => $available && ! $locked,
			'archive_enabled'           => $archive_enabled,
			'archive_label'             => $block_theme ? __( 'Controlled by the block theme', 'wp-title-layer' ) : self::onOff( $archive_enabled ),
			'archive_subtitles_enabled' => ! empty( $settings['archive_show_subtitles'] ),
			'after_content_enabled'     => ! empty( $settings['after_content_enabled'] ),
			'shortcode_enabled'         => ! empty( $settings['shortcode_enabled'] ),
		);
	}

	/**
	 * @param array<string,mixed> $taxonomy Taxonomy state.
	 * @return array<string,mixed>
	 */
	private static function urls( array $taxonomy ): array {
		$post_type = sanitize_key( (string) $taxonomy['post_type'] );
		$post_obj  = get_post_type_object( $post_type );
		$can_edit  = $post_obj && current_user_can( $post_obj->cap->edit_posts );

		$sample = '';
		if ( $can_edit ) {
			$sample_ids = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'orderby'                => 'date',
					'order'                  => 'DESC',
				)
			);
			if ( ! empty( $sample_ids ) ) {
				$permalink = get_permalink( (int) $sample_ids[0] );
				$sample    = is_string( $permalink ) ? $permalink : '';
			}
		}

		return array(
			'migration'     => add_query_arg( 'page', 'wp-title-layer-migration', admin_url( 'tools.php' ) ),
			'settings'      => add_query_arg( 'page', 'wp-title-layer', admin_url( 'options-general.php' ) ),
			'series'        => add_query_arg(
				array(
					'taxonomy' => sanitize_key( (string) $taxonomy['key'] ),
					'post_type' => $post_type,
				),
				admin_url( 'edit-tags.php' )
			),
			'series_health' => add_query_arg( 'page', SeriesHealth::PAGE_SLUG, admin_url( 'admin.php' ) ),
			'sequence_manager' => add_query_arg( 'page', SequenceManager::PAGE_SLUG, admin_url( 'admin.php' ) ),
			'channel_health'=> add_query_arg( 'page', ChannelHealth::PAGE_SLUG, admin_url( 'admin.php' ) ),
			'articles'      => add_query_arg( 'post_type', $post_type, admin_url( 'edit.php' ) ),
			'sample'        => $sample,
			'can_edit_posts'=> $can_edit,
		);
	}

	/** @param array<string,mixed> $card Card view model. */
	private static function renderCard( array $card ): void {
		$title_id = 'wptl-cc-card-' . sanitize_html_class( (string) $card['id'] );
		?>
		<section class="wptl-cc-card" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
			<div class="wptl-cc-card__header">
				<span class="dashicons dashicons-<?php echo esc_attr( sanitize_html_class( (string) $card['icon'] ) ); ?>" aria-hidden="true"></span>
				<div>
					<h2 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $card['title'] ); ?></h2>
					<span class="wptl-cc-status wptl-cc-status--<?php echo esc_attr( $card['tone'] ); ?>"><?php echo esc_html( $card['status'] ); ?></span>
				</div>
			</div>
			<p class="wptl-cc-card__summary"><?php echo esc_html( $card['summary'] ); ?></p>
			<dl class="wptl-cc-facts">
				<?php foreach ( $card['facts'] as $label => $value ) : ?>
					<div>
						<dt><?php echo esc_html( $label ); ?></dt>
						<dd><?php echo esc_html( is_int( $value ) ? number_format_i18n( $value ) : (string) $value ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
			<?php if ( '' !== (string) $card['note'] ) : ?>
				<p class="wptl-cc-card__note"><?php echo esc_html( $card['note'] ); ?></p>
			<?php endif; ?>
			<div class="wptl-cc-card__footer">
				<?php self::renderAction( $card['action'], 'button button-secondary' ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<string,mixed> $action Action view model.
	 * @param string              $class  CSS classes for an enabled link.
	 */
	private static function renderAction( array $action, string $class ): void {
		if ( empty( $action['label'] ) ) {
			return;
		}
		if ( empty( $action['enabled'] ) || empty( $action['url'] ) ) {
			?>
			<span class="wptl-cc-action-disabled" aria-disabled="true"><?php echo esc_html( $action['label'] ); ?></span>
			<?php
			return;
		}
		?>
		<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $action['url'] ); ?>"<?php echo ! empty( $action['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo esc_html( $action['label'] ); ?></a>
		<?php
	}

	/** @return array<string,mixed> */
	private static function action( string $label, string $url, bool $enabled = true, bool $external = false ): array {
		return array(
			'label'    => $label,
			'url'      => $url,
			'enabled'  => $enabled,
			'external' => $external,
		);
	}

	/** @param array<string,mixed> $migration Migration state. */
	private static function acfShortText( array $migration ): string {
		if ( empty( $migration['available'] ) ) {
			return __( 'The old ACF Series fields could not be audited from this screen.', 'wp-title-layer' );
		}

		if ( 0 < (int) $migration['acf_series_assignments'] ) {
			return sprintf(
				/* translators: 1: non-empty field-post values, 2: unique posts. */
				__( '%1$s non-empty old ACF field values across %2$s post IDs can include defaults and are not confirmed Series use.', 'wp-title-layer' ),
				number_format_i18n( (int) $migration['acf_series_assignments'] ),
				number_format_i18n( (int) ( $migration['acf_series_posts'] ?? 0 ) )
			);
		}

		return __( 'No non-empty values were found in the five audited old ACF Series fields.', 'wp-title-layer' );
	}

	/** @param array<string,mixed> $migration Migration state. */
	private static function acfBoundaryText( array $migration ): string {
		if ( empty( $migration['available'] ) ) {
			return __( 'WP Title Layer does not require ACF, but the five old Series fields could not be audited from this screen. Check the migration report before deciding whether to remove ACF.', 'wp-title-layer' );
		}

		if ( 0 < (int) $migration['acf_series_assignments'] ) {
			return sprintf(
				/* translators: 1: non-empty field-post values, 2: unique posts. */
				__( 'WP Title Layer does not require ACF. The audit found %1$s non-empty field-post values across %2$s post IDs in the five old Series fields. This can include ACF-saved defaults, excludes exact empty strings, and is not proof of Series use; none of these fields is converted automatically. Check unrelated ACF fields separately before uninstalling ACF.', 'wp-title-layer' ),
				number_format_i18n( (int) $migration['acf_series_assignments'] ),
				number_format_i18n( (int) ( $migration['acf_series_posts'] ?? 0 ) )
			);
		}

		return __( 'WP Title Layer does not require ACF. The five audited old Series fields currently contain no non-empty stored values; exact empty strings are not counted.', 'wp-title-layer' );
	}

	private static function onOff( bool $enabled ): string {
		return $enabled ? __( 'On', 'wp-title-layer' ) : __( 'Off', 'wp-title-layer' );
	}

	/**
	 * Read an optional class constant without requiring that module to be active.
	 *
	 * @param mixed $fallback Safe fallback value.
	 * @return mixed
	 */
	private static function classConstant( string $class, string $constant_name, $fallback ) {
		$qualified = $class . '::' . $constant_name;
		if ( class_exists( $class ) && defined( $qualified ) ) {
			return constant( $qualified );
		}

		return $fallback;
	}
}
