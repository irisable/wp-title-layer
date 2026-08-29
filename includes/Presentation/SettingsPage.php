<?php
/**
 * Presentation settings UI.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	public const PAGE_SLUG = 'wp-title-layer';

	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'registerSettings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'addPage' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueueAssets' ) );
	}

	public static function registerSettings(): void {
		register_setting(
			'wptl_settings_group',
			Schema::settingsOption(),
			array(
				'type'              => 'object',
				'sanitize_callback' => array( __CLASS__, 'sanitizeSettings' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'wptl_frontend_output_section',
			__( 'Front-end placement', 'wp-title-layer' ),
			array( __CLASS__, 'renderOutputSectionIntro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wptl_display_mode',
			__( 'How should the Title Layer appear?', 'wp-title-layer' ),
			array( __CLASS__, 'renderDisplayModeField' ),
			self::PAGE_SLUG,
			'wptl_frontend_output_section'
		);

		add_settings_field(
			'wptl_auto_post_types',
			__( 'Automatic placement applies to', 'wp-title-layer' ),
			array( __CLASS__, 'renderAutoPostTypesField' ),
			self::PAGE_SLUG,
			'wptl_frontend_output_section'
		);

		add_settings_field(
			'wptl_auto_heading_tag',
			__( 'Main title element', 'wp-title-layer' ),
			array( __CLASS__, 'renderAutoHeadingField' ),
			self::PAGE_SLUG,
			'wptl_frontend_output_section'
		);

		add_settings_field(
			'wptl_auto_theme_title_disabled',
			__( 'Theme title safety check', 'wp-title-layer' ),
			array( __CLASS__, 'renderThemeTitleConfirmationField' ),
			self::PAGE_SLUG,
			'wptl_frontend_output_section'
		);

		add_settings_section(
			'wptl_presentation_section',
			__( 'Title presentation', 'wp-title-layer' ),
			array( __CLASS__, 'renderSectionIntro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wptl_default_template',
			__( 'Default template', 'wp-title-layer' ),
			array( __CLASS__, 'renderDefaultTemplateField' ),
			self::PAGE_SLUG,
			'wptl_presentation_section'
		);

		add_settings_field(
			'wptl_series_icons',
			__( 'Series icons in article title layers', 'wp-title-layer' ),
			array( __CLASS__, 'renderSeriesIconsField' ),
			self::PAGE_SLUG,
			'wptl_presentation_section'
		);

		add_settings_field(
			'wptl_category_rules',
			__( 'Category rules', 'wp-title-layer' ),
			array( __CLASS__, 'renderCategoryRulesField' ),
			self::PAGE_SLUG,
			'wptl_presentation_section'
		);

		add_settings_field(
			'wptl_preset_customizations',
			__( 'Customize the four templates', 'wp-title-layer' ),
			array( __CLASS__, 'renderPresetCustomizationsField' ),
			self::PAGE_SLUG,
			'wptl_presentation_section'
		);
	}

	public static function addPage(): void {
		add_options_page(
			__( 'WP Title Layer', 'wp-title-layer' ),
			__( 'WP Title Layer', 'wp-title-layer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'renderPage' )
		);
	}

	/** Load the small shared admin stylesheet only on this settings screen. */
	public static function enqueueAssets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$base_url = defined( 'WPTL_URL' )
			? trailingslashit( (string) WPTL_URL ) . 'assets/'
			: plugin_dir_url( dirname( __DIR__, 2 ) . '/wp-title-layer.php' ) . 'assets/';
		$version = defined( 'WPTL_VERSION' ) ? (string) WPTL_VERSION : '1.0.0-rc.1';
		wp_enqueue_style( 'wptl-settings', $base_url . 'admin.css', array(), $version );
		wp_enqueue_script( 'wptl-settings-tabs', $base_url . 'settings.js', array(), $version, true );
	}

	public static function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sections = self::settingsSections();
		?>
		<div class="wrap wptl-settings" data-wptl-settings-tabs>
			<header class="wptl-settings__hero">
				<h1><?php esc_html_e( 'WP Title Layer', 'wp-title-layer' ); ?></h1>
				<p><?php esc_html_e( 'Choose where the Title Layer appears and how its parts are arranged. The safe default is manual placement.', 'wp-title-layer' ); ?></p>

				<?php if ( ! empty( $sections ) ) : ?>
					<nav class="wptl-settings__nav" aria-label="<?php esc_attr_e( 'Settings sections', 'wp-title-layer' ); ?>" data-wptl-settings-tablist>
						<ol>
							<?php foreach ( $sections as $index => $section ) : ?>
								<?php
								$section_id    = sanitize_key( (string) ( $section['id'] ?? '' ) );
								$section_title = wp_strip_all_tags( (string) ( $section['title'] ?? '' ) );
								?>
								<li>
									<a id="<?php echo esc_attr( 'wptl-settings-tab-' . $section_id ); ?>" href="#<?php echo esc_attr( 'wptl-settings-group-' . $section_id ); ?>" data-wptl-settings-tab aria-controls="<?php echo esc_attr( 'wptl-settings-group-' . $section_id ); ?>">
										<span class="wptl-settings__nav-number" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $index + 1 ) ); ?></span>
										<span><?php echo esc_html( $section_title ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ol>
					</nav>
				<?php endif; ?>
			</header>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'wptl_settings_group' );
				self::renderSettingsSections( $sections );
				?>
				<div class="wptl-settings__actions">
					<?php submit_button( null, 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Return every section registered on this shared settings screen in its
	 * native registration order. Integration modules can therefore add a
	 * section without duplicating this page shell.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function settingsSections(): array {
		global $wp_settings_sections;

		if ( ! isset( $wp_settings_sections[ self::PAGE_SLUG ] ) || ! is_array( $wp_settings_sections[ self::PAGE_SLUG ] ) ) {
			return array();
		}

		return array_values( $wp_settings_sections[ self::PAGE_SLUG ] );
	}

	/**
	 * Render native Settings API callbacks and fields inside visual groups.
	 * Field names, callbacks, nonces, and the single option save path remain
	 * unchanged; only this plugin page's information hierarchy is different.
	 *
	 * @param array<int,array<string,mixed>> $sections Registered sections.
	 */
	private static function renderSettingsSections( array $sections ): void {
		global $wp_settings_fields;

		echo '<div class="wptl-settings__groups">';
		foreach ( $sections as $index => $section ) {
			$section_id    = sanitize_key( (string) ( $section['id'] ?? '' ) );
			$section_title = (string) ( $section['title'] ?? '' );
			$heading_id    = 'wptl-settings-heading-' . $section_id;
			$fields        = $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] ?? array();

			echo '<section id="' . esc_attr( 'wptl-settings-group-' . $section_id ) . '" class="wptl-settings-group" aria-labelledby="' . esc_attr( $heading_id ) . '" data-wptl-settings-panel data-wptl-settings-tab-label="' . esc_attr( 'wptl-settings-tab-' . $section_id ) . '">';
			echo '<header class="wptl-settings-group__header">';
			echo '<span class="wptl-settings-group__number" aria-hidden="true">' . esc_html( sprintf( '%02d', $index + 1 ) ) . '</span>';
			echo '<div class="wptl-settings-group__intro">';
			echo '<h2 id="' . esc_attr( $heading_id ) . '">' . wp_kses_post( $section_title ) . '</h2>';

			if ( isset( $section['callback'] ) && is_callable( $section['callback'] ) ) {
				call_user_func( $section['callback'], $section );
			}

			echo '</div>';
			echo '</header>';

			if ( ! empty( $fields ) ) {
				echo '<div class="wptl-settings-group__fields">';
				echo '<table class="form-table" role="presentation">';
				do_settings_fields( self::PAGE_SLUG, $section_id );
				echo '</table>';
				echo '</div>';
			}

			echo '</section>';
		}
		echo '</div>';
	}

	public static function renderSectionIntro(): void {
		echo '<p>' . esc_html__( 'Resolution order: post override, Series default, category rule, then the global default.', 'wp-title-layer' ) . '</p>';
	}

	public static function renderOutputSectionIntro(): void {
		$support = ThemeTitleIntegration::support();
		echo '<p>' . esc_html__( 'Theme-title takeover replaces a verified title slot instead of adding a second title inside the article body. Manual placement remains the universal fallback.', 'wp-title-layer' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Detected integration:', 'wp-title-layer' ) . '</strong> ' . esc_html( $support['label'] ) . '</p>';
	}

	public static function renderDisplayModeField(): void {
		$settings = self::presentationSettings();
		$name     = Schema::settingsOption() . '[presentation][display_mode]';
		$support  = ThemeTitleIntegration::support();
		?>
		<fieldset>
			<label>
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( ThemeTitleIntegration::MODE_REPLACE ); ?>" <?php checked( $settings['display_mode'], ThemeTitleIntegration::MODE_REPLACE ); ?> <?php disabled( empty( $support['supported'] ) ); ?>>
				<strong><?php esc_html_e( 'Replace the theme post title (recommended)', 'wp-title-layer' ); ?></strong>
			</label>
			<p class="description">
				<?php echo esc_html( ! empty( $support['supported'] )
					? __( 'Uses the verified theme title position, keeps one heading, and leaves the article body untouched.', 'wp-title-layer' )
					: __( 'The current classic theme has no verified adapter. Use Manual, or integrate the theme API in its title template.', 'wp-title-layer' ) ); ?>
			</p>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( AutoDisplay::MODE_MANUAL ); ?>" <?php checked( $settings['display_mode'], AutoDisplay::MODE_MANUAL ); ?>>
				<strong><?php esc_html_e( 'Manual block or theme API', 'wp-title-layer' ); ?></strong>
			</label>
			<p class="description"><?php esc_html_e( 'Add the Title Layer block or shortcode exactly where you want it. Nothing is inserted automatically.', 'wp-title-layer' ); ?></p>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( AutoDisplay::MODE_AUTO_PREPEND ); ?>" <?php checked( $settings['display_mode'], AutoDisplay::MODE_AUTO_PREPEND ); ?>>
				<strong><?php esc_html_e( 'Legacy fallback: add before the article body', 'wp-title-layer' ); ?></strong>
			</label>
			<p class="description"><?php esc_html_e( 'This does not remove the theme title. Use it only after the theme title has been disabled separately.', 'wp-title-layer' ); ?></p>
		</fieldset>
		<?php
	}

	public static function renderAutoPostTypesField(): void {
		$settings   = self::presentationSettings();
		$name       = Schema::settingsOption() . '[presentation][auto_post_types][]';
		$post_types = self::availableAutoPostTypes();

		if ( empty( $post_types ) ) {
			echo '<p>' . esc_html__( 'No public content types are available for automatic placement.', 'wp-title-layer' ) . '</p>';
			return;
		}
		?>
		<fieldset>
			<?php foreach ( $post_types as $post_type => $label ) : ?>
				<label style="display:block;margin-bottom:6px">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $settings['auto_post_types'], true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php esc_html_e( 'These content types apply to theme-title takeover and the legacy body fallback.', 'wp-title-layer' ); ?></p>
		<?php
	}

	public static function renderAutoHeadingField(): void {
		$settings = self::presentationSettings();
		$name     = Schema::settingsOption() . '[presentation][auto_heading_tag]';
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value="h2" <?php selected( $settings['auto_heading_tag'], 'h2' ); ?>><?php esc_html_e( 'H2 — the theme already supplies the page H1 elsewhere', 'wp-title-layer' ); ?></option>
			<option value="div" <?php selected( $settings['auto_heading_tag'], 'div' ); ?>><?php esc_html_e( 'DIV — visual title only, no heading level', 'wp-title-layer' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Used only by the legacy body fallback. Theme-title takeover inherits the heading level already chosen by the theme.', 'wp-title-layer' ); ?></p>
		<?php
	}

	public static function renderThemeTitleConfirmationField(): void {
		$settings = self::presentationSettings();
		$name     = Schema::settingsOption() . '[presentation][auto_theme_title_disabled]';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $settings['auto_theme_title_disabled'] ); ?>>
			<strong><?php esc_html_e( 'For the legacy body fallback, I have disabled the theme\'s original post title.', 'wp-title-layer' ); ?></strong>
		</label>
		<p class="description"><?php esc_html_e( 'This confirmation is ignored by true theme-title takeover. It exists only to prevent duplicate titles in the older body-insertion mode.', 'wp-title-layer' ); ?></p>
		<?php
	}

	public static function renderDefaultTemplateField(): void {
		$settings = self::presentationSettings();
		$name     = Schema::settingsOption() . '[presentation][default_template]';
		?>
		<select name="<?php echo esc_attr( $name ); ?>" id="wptl-default-template">
			<?php foreach ( Presets::choices() as $choice ) : ?>
				<option value="<?php echo esc_attr( $choice['value'] ); ?>" <?php selected( $settings['default_template'], $choice['value'] ); ?>>
					<?php echo esc_html( $choice['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Used when no more specific valid rule applies.', 'wp-title-layer' ); ?></p>
		<?php
	}

	public static function renderSeriesIconsField(): void {
		$settings = self::presentationSettings();
		$name     = Schema::settingsOption() . '[presentation][series_icons_enabled]';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $settings['series_icons_enabled'] ); ?>>
			<?php esc_html_e( 'Show a Series icon beside its linked name when that Series has chosen an icon.', 'wp-title-layer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Off by default. Each Series can inherit, show, or hide its icon. The decorative image never becomes part of the H1, SEO title, or social title.', 'wp-title-layer' ); ?></p>
		<?php
	}

	public static function renderCategoryRulesField(): void {
		$settings   = self::presentationSettings();
		$categories = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		$choices = Presets::choices();

		if ( empty( $categories ) ) {
			echo '<p>' . esc_html__( 'No categories are available.', 'wp-title-layer' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped wptl-category-rules">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Category', 'wp-title-layer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Template', 'wp-title-layer' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $categories as $category ) : ?>
				<?php $name = Schema::settingsOption() . '[presentation][category_rules][' . (int) $category->term_id . ']'; ?>
				<tr>
					<th scope="row"><?php echo esc_html( $category->name ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>">
							<option value=""><?php esc_html_e( 'Use global default', 'wp-title-layer' ); ?></option>
							<?php foreach ( $choices as $choice ) : ?>
								<option value="<?php echo esc_attr( $choice['value'] ); ?>" <?php selected( $settings['category_rules'][ $category->term_id ] ?? '', $choice['value'] ); ?>>
									<?php echo esc_html( $choice['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'When several assigned categories have rules, the first saved rule in this table wins.', 'wp-title-layer' ); ?></p>
		<?php
	}

	/** Render bounded, non-code controls for each built-in presentation preset. */
	public static function renderPresetCustomizationsField(): void {
		$settings   = self::presentationSettings();
		$presets    = Presets::all();
		$separators = Presets::localeSeparators();
		?>
		<div class="wptl-preset-customizer">
			<p class="description"><?php esc_html_e( 'These controls change the chosen template everywhere it is used. They never accept HTML, CSS, or PHP.', 'wp-title-layer' ); ?></p>
			<?php foreach ( Presets::customizableIds() as $index => $preset_id ) : ?>
				<?php
				$values      = $settings['preset_customizations'][ $preset_id ];
				$label       = isset( $presets[ $preset_id ] ) ? (string) $presets[ $preset_id ]['label'] : $preset_id;
				$description = isset( $presets[ $preset_id ] ) ? (string) $presets[ $preset_id ]['description'] : '';
				$base        = Schema::settingsOption() . '[presentation][preset_customizations][' . $preset_id . ']';
				?>
				<details class="wptl-preset-customizer__panel" <?php echo 0 === $index ? 'open' : ''; ?>>
					<summary>
						<strong><?php echo esc_html( $label ); ?></strong>
						<span><?php echo esc_html( $description ); ?></span>
					</summary>
					<div class="wptl-preset-customizer__body">
						<div class="wptl-preset-customizer__grid">
							<label>
								<span><?php esc_html_e( 'Series context', 'wp-title-layer' ); ?></span>
								<select name="<?php echo esc_attr( $base . '[series_position]' ); ?>">
									<option value="above" <?php selected( $values['series_position'], 'above' ); ?>><?php esc_html_e( 'Show above the title when available', 'wp-title-layer' ); ?></option>
									<option value="hidden" <?php selected( $values['series_position'], 'hidden' ); ?>><?php esc_html_e( 'Do not show', 'wp-title-layer' ); ?></option>
								</select>
							</label>

							<label>
								<span><?php esc_html_e( 'Subtitle', 'wp-title-layer' ); ?></span>
								<select name="<?php echo esc_attr( $base . '[subtitle_position]' ); ?>">
									<option value="stacked" <?php selected( $values['subtitle_position'], 'stacked' ); ?>><?php esc_html_e( 'Show on a new line below the title', 'wp-title-layer' ); ?></option>
									<option value="inline" <?php selected( $values['subtitle_position'], 'inline' ); ?>><?php esc_html_e( 'Show beside the title', 'wp-title-layer' ); ?></option>
									<option value="hidden" <?php selected( $values['subtitle_position'], 'hidden' ); ?>><?php esc_html_e( 'Do not show', 'wp-title-layer' ); ?></option>
								</select>
							</label>

							<label>
								<span><?php esc_html_e( 'Same-line separator', 'wp-title-layer' ); ?></span>
								<select name="<?php echo esc_attr( $base . '[separator_style]' ); ?>">
									<option value="colon" <?php selected( $values['separator_style'], 'colon' ); ?>><?php echo esc_html( sprintf( __( 'Colon %s', 'wp-title-layer' ), trim( $separators['colon'] ) ) ); ?></option>
									<option value="dash" <?php selected( $values['separator_style'], 'dash' ); ?>><?php echo esc_html( sprintf( __( 'Em dash %s', 'wp-title-layer' ), trim( $separators['dash'] ) ) ); ?></option>
									<option value="pipe" <?php selected( $values['separator_style'], 'pipe' ); ?>><?php echo esc_html( sprintf( __( 'Vertical bar %s', 'wp-title-layer' ), trim( $separators['pipe'] ) ) ); ?></option>
									<option value="custom" <?php selected( $values['separator_style'], 'custom' ); ?>><?php esc_html_e( 'Custom text below', 'wp-title-layer' ); ?></option>
								</select>
							</label>

							<label>
								<span><?php esc_html_e( 'Custom separator', 'wp-title-layer' ); ?></span>
								<input type="text" name="<?php echo esc_attr( $base . '[custom_separator]' ); ?>" value="<?php echo esc_attr( (string) $values['custom_separator'] ); ?>" maxlength="8" placeholder="<?php esc_attr_e( 'For example: ·', 'wp-title-layer' ); ?>">
								<small><?php esc_html_e( 'Plain text only, up to 8 characters. An empty value uses the locale’s colon separator.', 'wp-title-layer' ); ?></small>
							</label>
						</div>

						<label class="wptl-preset-customizer__reset">
							<input type="checkbox" name="<?php echo esc_attr( $base . '[_reset]' ); ?>" value="1">
							<?php esc_html_e( 'Restore this template to its original defaults when I save', 'wp-title-layer' ); ?>
						</label>
					</div>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Sanitize only this module's subtree while preserving all settings owned by
	 * other modules in the shared option.
	 *
	 * @param mixed $submitted Submitted option value.
	 * @return array<string,mixed>
	 */
	public static function sanitizeSettings( $submitted ): array {
		$old       = get_option( Schema::settingsOption(), array() );
		$old       = is_array( $old ) ? $old : array();
		$submitted = is_array( $submitted ) ? $submitted : array();
		$has_presentation = isset( $submitted['presentation'] ) && is_array( $submitted['presentation'] );
		$incoming  = $has_presentation
			? $submitted['presentation']
			: array();
		$presets   = Presets::all();
		$old_presentation = isset( $old['presentation'] ) && is_array( $old['presentation'] )
			? $old['presentation']
			: array();
		if ( ! $has_presentation ) {
			$filtered = apply_filters( 'wptl_sanitize_settings', $old, $submitted );
			return is_array( $filtered ) ? $filtered : $old;
		}

		$default = sanitize_key( (string) ( $incoming['default_template'] ?? Presets::DEFAULT_ID ) );
		if ( ! isset( $presets[ $default ] ) ) {
			$default = Presets::DEFAULT_ID;
		}

		$category_rules = array();
		foreach ( (array) ( $incoming['category_rules'] ?? array() ) as $category_id => $preset_id ) {
			$category_id = absint( $category_id );
			$preset_id   = sanitize_key( (string) $preset_id );
			if ( $category_id > 0 && isset( $presets[ $preset_id ] ) && term_exists( $category_id, 'category' ) ) {
				$category_rules[ $category_id ] = $preset_id;
			}
		}

		$display_mode = sanitize_key( (string) ( $incoming['display_mode'] ?? AutoDisplay::MODE_MANUAL ) );
		if ( ! in_array( $display_mode, array( AutoDisplay::MODE_MANUAL, ThemeTitleIntegration::MODE_REPLACE, AutoDisplay::MODE_AUTO_PREPEND ), true ) ) {
			$display_mode = AutoDisplay::MODE_MANUAL;
		}
		if ( ThemeTitleIntegration::MODE_REPLACE === $display_mode && empty( ThemeTitleIntegration::support()['supported'] ) ) {
			$display_mode = AutoDisplay::MODE_MANUAL;
			add_settings_error(
				Schema::settingsOption(),
				'wptl_theme_takeover_unsupported',
				__( 'Theme-title takeover was not enabled because the current classic theme has no verified adapter. Manual placement remains active.', 'wp-title-layer' ),
				'warning'
			);
		}

		$confirmed = ! empty( $incoming['auto_theme_title_disabled'] );
		if ( AutoDisplay::MODE_AUTO_PREPEND === $display_mode && ! $confirmed ) {
			$display_mode = AutoDisplay::MODE_MANUAL;
			add_settings_error(
				Schema::settingsOption(),
				'wptl_auto_confirmation_required',
				__( 'Automatic placement was not enabled because the theme-title confirmation was not checked. Manual placement remains active.', 'wp-title-layer' ),
				'warning'
			);
		}
		$confirmed = AutoDisplay::MODE_AUTO_PREPEND === $display_mode && $confirmed;

		$available_post_types = array_keys( self::availableAutoPostTypes() );
		$auto_post_types      = array_values(
			array_unique(
				array_intersect(
					$available_post_types,
					array_filter( array_map( 'sanitize_key', (array) ( $incoming['auto_post_types'] ?? array() ) ) )
				)
			)
		);
		$heading_tag = self::sanitizeAutoHeadingTag( (string) ( $incoming['auto_heading_tag'] ?? 'div' ) );
		$recipes_supported = Presets::hasSupportedRecipeVersion( $old_presentation );
		$recipe_updates    = array();
		if ( $recipes_supported ) {
			$recipe_updates = array(
				'preset_schema_version'  => Presets::RECIPE_SCHEMA_VERSION,
				'preset_customizations' => self::mergeRecipeOverrides(
					$old_presentation['preset_customizations'] ?? array(),
					$incoming['preset_customizations'] ?? null
				),
			);
		}

		$old['presentation'] = array_merge(
			$old_presentation,
			array(
				'default_template'                  => $default,
				'series_icons_enabled'              => ! empty( $incoming['series_icons_enabled'] ),
				'category_rules'                    => $category_rules,
				'display_mode'                      => $display_mode,
				'auto_post_types'                   => $auto_post_types,
				'auto_heading_tag'                  => $heading_tag,
				'auto_theme_title_disabled'         => $confirmed,
			),
			$recipe_updates
		);

		/**
		 * Let modules sharing this option sanitize only their own subtree after
		 * Presentation has preserved all unrelated stored keys.
		 *
		 * @param array<string,mixed> $old       Sanitized shared settings.
		 * @param array<string,mixed> $submitted Submitted shared settings.
		 */
		$filtered = apply_filters( 'wptl_sanitize_settings', $old, $submitted );

		return is_array( $filtered ) ? $filtered : $old;
	}

	/**
	 * Get normalized presentation settings.
	 *
	 * @return array{default_template:string,series_icons_enabled:bool,category_rules:array<int,string>,display_mode:string,auto_post_types:array<int,string>,auto_heading_tag:string,auto_theme_title_disabled:bool,preset_schema_version:int,preset_customizations:array<string,array<string,string>>}
	 */
	public static function presentationSettings(): array {
		$all          = get_option( Schema::settingsOption(), array() );
		$presentation = is_array( $all ) && isset( $all['presentation'] ) && is_array( $all['presentation'] )
			? $all['presentation']
			: array();
		$presets      = Presets::all();
		$default      = sanitize_key( (string) ( $presentation['default_template'] ?? Presets::DEFAULT_ID ) );
		if ( ! isset( $presets[ $default ] ) ) {
			$default = Presets::DEFAULT_ID;
		}

		$rules = array();
		foreach ( (array) ( $presentation['category_rules'] ?? array() ) as $category_id => $preset_id ) {
			$category_id = absint( $category_id );
			$preset_id   = sanitize_key( (string) $preset_id );
			if ( $category_id > 0 && isset( $presets[ $preset_id ] ) ) {
				$rules[ $category_id ] = $preset_id;
			}
		}

		$display_mode = sanitize_key( (string) ( $presentation['display_mode'] ?? AutoDisplay::MODE_MANUAL ) );
		if ( ! in_array( $display_mode, array( AutoDisplay::MODE_MANUAL, ThemeTitleIntegration::MODE_REPLACE, AutoDisplay::MODE_AUTO_PREPEND ), true ) ) {
			$display_mode = AutoDisplay::MODE_MANUAL;
		}
		if ( ThemeTitleIntegration::MODE_REPLACE === $display_mode && empty( ThemeTitleIntegration::support()['supported'] ) ) {
			$display_mode = AutoDisplay::MODE_MANUAL;
		}
		$confirmed = ! empty( $presentation['auto_theme_title_disabled'] );
		if ( AutoDisplay::MODE_AUTO_PREPEND === $display_mode && ! $confirmed ) {
			$display_mode = AutoDisplay::MODE_MANUAL;
		}
		$confirmed = AutoDisplay::MODE_AUTO_PREPEND === $display_mode && $confirmed;

		$available_post_types = array_keys( self::availableAutoPostTypes() );
		$default_post_types   = in_array( 'post', $available_post_types, true ) ? array( 'post' ) : array();
		$auto_post_types      = array_values(
			array_unique(
				array_intersect(
					$available_post_types,
					array_filter( array_map( 'sanitize_key', (array) ( $presentation['auto_post_types'] ?? $default_post_types ) ) )
				)
			)
		);
		$stored_recipes = Presets::hasSupportedRecipeVersion( $presentation )
			? ( $presentation['preset_customizations'] ?? array() )
			: array();

		return array(
			'default_template'                  => $default,
			'series_icons_enabled'              => ! empty( $presentation['series_icons_enabled'] ),
			'category_rules'                    => $rules,
			'display_mode'                      => $display_mode,
			'auto_post_types'                   => $auto_post_types,
			'auto_heading_tag'                  => self::sanitizeAutoHeadingTag( (string) ( $presentation['auto_heading_tag'] ?? 'div' ) ),
			'auto_theme_title_disabled'         => $confirmed,
			'preset_schema_version'              => Presets::RECIPE_SCHEMA_VERSION,
			'preset_customizations'             => Presets::normalizedRecipes( $stored_recipes ),
		);
	}

	public static function seriesIconEnabled( ?\WP_Term $term = null ): bool {
		$enabled = self::presentationSettings()['series_icons_enabled'];
		if ( $term instanceof \WP_Term && class_exists( '\\WPTitleLayer\\Core\\Series' ) ) {
			$override = \WPTitleLayer\Core\Series::title_icon( $term );
			if ( \WPTitleLayer\Core\Schema::ARCHIVE_VISIBILITY_SHOW === $override ) {
				$enabled = true;
			} elseif ( \WPTitleLayer\Core\Schema::ARCHIVE_VISIBILITY_HIDE === $override ) {
				$enabled = false;
			}
		}

		/**
		 * @param bool          $enabled Whether the Series icon may appear in article title layers.
		 * @param \WP_Term|null $term    Current Series, when resolving a term override.
		 */
		return (bool) apply_filters( 'wptl_series_title_icon_enabled', $enabled, $term );
	}

	/**
	 * Merge only complete, valid recipe records; malformed partial requests do
	 * not erase a previously saved preset. An explicit reset is authoritative.
	 *
	 * @param mixed $stored Existing recipe overrides.
	 * @param mixed $incoming Submitted recipe overrides.
	 * @return array<string,array<string,string>>
	 */
	private static function mergeRecipeOverrides( $stored, $incoming ): array {
		$merged = Presets::sanitizeRecipeOverrides( $stored );
		if ( ! is_array( $incoming ) ) {
			return $merged;
		}

		foreach ( Presets::customizableIds() as $preset_id ) {
			if ( ! array_key_exists( $preset_id, $incoming ) || ! is_array( $incoming[ $preset_id ] ) ) {
				continue;
			}
			if ( ! empty( $incoming[ $preset_id ]['_reset'] ) ) {
				unset( $merged[ $preset_id ] );
				continue;
			}
			if ( ! Presets::isValidRecipeInput( $incoming[ $preset_id ] ) ) {
				continue;
			}

			$recipe = Presets::normalizeRecipe( $preset_id, $incoming[ $preset_id ] );
			if ( $recipe === Presets::defaultRecipe( $preset_id ) ) {
				unset( $merged[ $preset_id ] );
			} else {
				$merged[ $preset_id ] = $recipe;
			}
		}

		return $merged;
	}

	/**
	 * Public, front-end content types eligible for opt-in automatic placement.
	 *
	 * @return array<string,string> Post type name => human label.
	 */
	public static function availableAutoPostTypes(): array {
		$objects = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		$choices = array();
		foreach ( (array) $objects as $name => $object ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name || 'attachment' === $name || ! is_object( $object ) ) {
				continue;
			}
			$label = isset( $object->labels->name ) ? (string) $object->labels->name : $name;
			$choices[ $name ] = sanitize_text_field( $label );
		}

		/** @param array<string,string> $choices Eligible post type labels. */
		$filtered = apply_filters( 'wptl_auto_prepend_post_types', $choices );
		if ( ! is_array( $filtered ) ) {
			return $choices;
		}

		$normalized = array();
		foreach ( $filtered as $name => $label ) {
			$name = sanitize_key( (string) $name );
			if ( '' !== $name && post_type_exists( $name ) && 'attachment' !== $name ) {
				$normalized[ $name ] = sanitize_text_field( (string) $label );
			}
		}

		return $normalized;
	}

	/**
	 * Normalize the deliberately narrow heading choice used by auto placement.
	 *
	 * @param string $heading_tag Submitted element name.
	 * @return string
	 */
	public static function sanitizeAutoHeadingTag( string $heading_tag ): string {
		$heading_tag = strtolower( sanitize_key( $heading_tag ) );

		return in_array( $heading_tag, array( 'h2', 'div' ), true ) ? $heading_tag : 'div';
	}
}
