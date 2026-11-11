<?php
/**
 * Non-technical Reader settings on the shared Irisable Title Layer screen.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

use WPTitleLayer\Core\Schema;

defined( 'ABSPATH' ) || exit;

final class AdminSettings {
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'addFields' ), 20 );
		add_filter( 'wptl_sanitize_settings', array( __CLASS__, 'sanitize' ), 10, 2 );
	}

	public static function addFields(): void {
		add_settings_section(
			'wptl_reader_section',
			__( 'Series reading experience', 'irisable-title-layer' ),
			array( __CLASS__, 'renderIntro' ),
			'wp-title-layer'
		);

		add_settings_field(
			'wptl_reader_archive',
			__( 'Series archive layout', 'irisable-title-layer' ),
			array( __CLASS__, 'renderArchive' ),
			'wp-title-layer',
			'wptl_reader_section'
		);
		add_settings_field(
			'wptl_reader_archive_subtitles',
			__( 'Archive subtitles', 'irisable-title-layer' ),
			array( __CLASS__, 'renderArchiveSubtitles' ),
			'wp-title-layer',
			'wptl_reader_section'
		);
		add_settings_field(
			'wptl_reader_theme_archive_subtitles',
			__( 'Theme archive card subtitles', 'irisable-title-layer' ),
			array( __CLASS__, 'renderThemeArchiveSubtitles' ),
			'wp-title-layer',
			'wptl_reader_section'
		);
		add_settings_field(
			'wptl_reader_archive_excerpts',
			__( 'Structured archive excerpts', 'irisable-title-layer' ),
			array( __CLASS__, 'renderArchiveExcerpts' ),
			'wp-title-layer',
			'wptl_reader_section'
		);
		add_settings_field(
			'wptl_reader_archive_featured_images',
			__( 'Structured archive featured images', 'irisable-title-layer' ),
			array( __CLASS__, 'renderArchiveFeaturedImages' ),
			'wp-title-layer',
			'wptl_reader_section'
		);
		add_settings_field(
			'wptl_reader_after_content',
			__( 'Previous / next navigation', 'irisable-title-layer' ),
			array( __CLASS__, 'renderAfterContent' ),
			'wp-title-layer',
			'wptl_reader_section'
		);
		add_settings_field(
			'wptl_reader_shortcode',
			__( 'Manual navigation shortcode', 'irisable-title-layer' ),
			array( __CLASS__, 'renderShortcode' ),
			'wp-title-layer',
			'wptl_reader_section'
		);
	}

	public static function renderIntro(): void {
		echo '<input type="hidden" name="' . esc_attr( Schema::OPTION_SETTINGS . '[reader][_present]' ) . '" value="1">';
		echo '<p>' . esc_html__( 'These features use the ordered / unordered rule stored on each Series. Unordered Series never show reading-progress navigation. For an ordered Series with seasons, its edit screen also decides whether navigation may cross season boundaries.', 'irisable-title-layer' ) . '</p>';
	}

	public static function renderArchive(): void {
		$settings    = Settings::all();
		$name        = Schema::OPTION_SETTINGS . '[reader][' . Settings::ARCHIVE_TEMPLATE_ENABLED . ']';
		$block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$enabled     = ! $block_theme && ! empty( $settings[ Settings::ARCHIVE_TEMPLATE_ENABLED ] );
		?>
		<fieldset>
			<label style="display:block;margin-bottom:8px">
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="0" <?php checked( ! $enabled ); ?>>
				<strong><?php esc_html_e( 'Use the active theme’s archive layout (recommended for theme consistency)', 'irisable-title-layer' ); ?></strong>
			</label>
			<label style="display:block">
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $enabled ); ?> <?php disabled( $block_theme ); ?>>
				<?php esc_html_e( 'Use Irisable Title Layer’s structured Series layout with seasons and sequence labels', 'irisable-title-layer' ); ?>
			</label>
		</fieldset>
		<?php if ( $block_theme ) : ?>
			<p class="description"><?php esc_html_e( 'Your current block theme keeps control of this archive. Block-theme archive replacement is not enabled in this version.', 'irisable-title-layer' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Theme mode normally matches the site’s Category archive styling while Irisable Title Layer still supplies the Series ordering. Structured mode is useful when the theme cannot present seasons and reading order. Category, tag, and other archives are never replaced.', 'irisable-title-layer' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function renderArchiveSubtitles(): void {
		$settings = Settings::all();
		$name     = Schema::OPTION_SETTINGS . '[reader][' . Settings::ARCHIVE_SHOW_SUBTITLES . ']';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $settings[ Settings::ARCHIVE_SHOW_SUBTITLES ] ); ?>>
			<?php esc_html_e( 'Show each article’s subtitle in Irisable Title Layer’s structured Series archive.', 'irisable-title-layer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'This affects only the plugin-owned structured layout. In theme mode, the active theme decides what appears on archive cards.', 'irisable-title-layer' ); ?></p>
		<?php
	}

	public static function renderThemeArchiveSubtitles(): void {
		$settings = Settings::all();
		$name     = Schema::OPTION_SETTINGS . '[reader][' . Settings::THEME_ARCHIVE_SHOW_SUBTITLES . ']';
		$support  = ThemeArchiveIntegration::support();
		$enabled  = ! empty( $settings[ Settings::THEME_ARCHIVE_SHOW_SUBTITLES ] );
		if ( empty( $support['supported'] ) ) {
			// Keep a stored preference intact while another theme is active.
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $enabled ? '1' : '0' ) . '">';
		}
		?>
		<label>
			<?php if ( ! empty( $support['supported'] ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
			<?php endif; ?>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $enabled ); ?> <?php disabled( empty( $support['supported'] ) ); ?>>
			<?php esc_html_e( 'Show each article’s Subtitle in supported theme-owned Series archive cards.', 'irisable-title-layer' ); ?>
		</label>
		<p class="description">
			<?php
			echo esc_html( (string) $support['label'] ) . ' — ';
			esc_html_e( 'Opt-in and limited to an exact verified theme hook. Category, search, home, and unsupported theme archives remain unchanged.', 'irisable-title-layer' );
			?>
		</p>
		<?php
	}

	public static function renderArchiveExcerpts(): void {
		$settings = Settings::all();
		$name     = Schema::OPTION_SETTINGS . '[reader][' . Settings::ARCHIVE_SHOW_EXCERPTS . ']';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $settings[ Settings::ARCHIVE_SHOW_EXCERPTS ] ); ?>>
			<?php esc_html_e( 'Show each article’s WordPress excerpt in Irisable Title Layer’s structured Series archive.', 'irisable-title-layer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Off by default. Each Series can inherit, show, or hide excerpts on its own edit screen. Theme archives, Category, and Search are unchanged.', 'irisable-title-layer' ); ?></p>
		<?php
	}

	public static function renderArchiveFeaturedImages(): void {
		$settings = Settings::all();
		$name     = Schema::OPTION_SETTINGS . '[reader][' . Settings::ARCHIVE_SHOW_FEATURED_IMAGES . ']';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $settings[ Settings::ARCHIVE_SHOW_FEATURED_IMAGES ] ); ?>>
			<?php esc_html_e( 'Show a compact featured image beside each article in Irisable Title Layer’s structured Series archive.', 'irisable-title-layer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Off by default to preserve existing layouts. Each Series can inherit, show, or hide these images on its own edit screen.', 'irisable-title-layer' ); ?></p>
		<?php
	}

	public static function renderAfterContent(): void {
		$settings = Settings::all();
		$name     = Schema::OPTION_SETTINGS . '[reader][' . Settings::AFTER_CONTENT_ENABLED . ']';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $settings[ Settings::AFTER_CONTENT_ENABLED ] ); ?>>
			<?php esc_html_e( 'Append previous, next, “read from the beginning,” and progress after articles in an ordered Series.', 'irisable-title-layer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Only public, published, non-password-protected articles are included. For an ordered Series with seasons, choose Entire Series or Current Season on that Series’ edit screen. Existing Series default to Entire Series.', 'irisable-title-layer' ); ?></p>
		<?php
	}

	public static function renderShortcode(): void {
		$settings = Settings::all();
		$name     = Schema::OPTION_SETTINGS . '[reader][' . Settings::SHORTCODE_ENABLED . ']';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $settings[ Settings::SHORTCODE_ENABLED ] ); ?>>
			<?php esc_html_e( 'Enable [wptl_series_navigation] for manual placement.', 'irisable-title-layer' ); ?>
		</label>
		<?php
	}

	/**
	 * @param mixed $settings  Settings already sanitized by the owner screen.
	 * @param mixed $submitted Raw submitted shared option.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $settings, $submitted ): array {
		$settings  = is_array( $settings ) ? $settings : array();
		$submitted = is_array( $submitted ) ? $submitted : array();
		$incoming  = isset( $submitted[ Settings::SUBTREE ] ) && is_array( $submitted[ Settings::SUBTREE ] )
			? $submitted[ Settings::SUBTREE ]
			: array();
		if ( empty( $incoming['_present'] ) ) {
			return $settings;
		}

		$archive = ! empty( $incoming[ Settings::ARCHIVE_TEMPLATE_ENABLED ] );
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$archive = false;
		}

		$settings[ Settings::SUBTREE ] = array(
			Settings::ARCHIVE_TEMPLATE_ENABLED => $archive,
			Settings::ARCHIVE_SHOW_SUBTITLES   => ! empty( $incoming[ Settings::ARCHIVE_SHOW_SUBTITLES ] ),
			Settings::ARCHIVE_SHOW_EXCERPTS    => ! empty( $incoming[ Settings::ARCHIVE_SHOW_EXCERPTS ] ),
			Settings::THEME_ARCHIVE_SHOW_SUBTITLES => ! empty( $incoming[ Settings::THEME_ARCHIVE_SHOW_SUBTITLES ] ),
			Settings::ARCHIVE_SHOW_FEATURED_IMAGES => ! empty( $incoming[ Settings::ARCHIVE_SHOW_FEATURED_IMAGES ] ),
			Settings::AFTER_CONTENT_ENABLED     => ! empty( $incoming[ Settings::AFTER_CONTENT_ENABLED ] ),
			Settings::SHORTCODE_ENABLED         => ! empty( $incoming[ Settings::SHORTCODE_ENABLED ] ),
		);

		return $settings;
	}
}
