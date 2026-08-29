<?php
/**
 * Built-in presentation templates.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class Presets {
	public const DEFAULT_ID = 'standard';
	public const RECIPE_SCHEMA_VERSION = 1;
	private const CUSTOM_SEPARATOR_LENGTH = 8;

	/** @var string[] */
	private const CUSTOMIZABLE_IDS = array( 'standard', 'editorial', 'inline', 'minimal' );

	/**
	 * Get all available presets.
	 *
	 * The `template` value is trusted plugin configuration, never post content.
	 * Values interpolated into it are escaped separately by Renderer.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$presets = array(
			'standard' => array(
				'label'       => __( 'Standard title', 'wp-title-layer' ),
				'description' => __( 'Neutral and theme-led for most articles: Series context above, subtitle below.', 'wp-title-layer' ),
				'template'    => '<header class="wptl-title-layer wptl-title-layer--standard">{{eyebrow_block}}<h1 class="wptl-title wptl-title-heading">{{title}}</h1>{{subtitle_block}}</header>',
			),
			'editorial' => array(
				'label'       => __( 'Editorial feature', 'wp-title-layer' ),
				'description' => __( 'For long-form and Series features: the same hierarchy with a restrained accent rail.', 'wp-title-layer' ),
				'template'    => '<header class="wptl-title-layer wptl-title-layer--editorial">{{eyebrow_block}}<h1 class="wptl-title wptl-title-heading">{{title}}</h1>{{subtitle_block}}</header>',
			),
			'inline' => array(
				'label'       => __( 'Inline title', 'wp-title-layer' ),
				'description' => __( 'For short titles and compact Series entries: keep Series context above while title and subtitle share one visual line.', 'wp-title-layer' ),
				'template'    => '<header class="wptl-title-layer wptl-title-layer--inline">{{eyebrow_block}}<div class="wptl-title-row"><h1 class="wptl-title-heading">{{title_content}}</h1>{{subtitle_inline}}</div>{{subtitle_block}}</header>',
			),
			'minimal' => array(
				'label'       => __( 'Title only', 'wp-title-layer' ),
				'description' => __( 'For ordinary posts or strict theme compatibility: only the WordPress title is shown.', 'wp-title-layer' ),
				'template'    => '<h1 class="wptl-title-layer wptl-title-layer--minimal wptl-title-heading">{{title}}</h1>',
			),
		);

		/**
		 * Filter presentation presets.
		 *
		 * Extensions should use a unique, sanitize_key-compatible ID. Renderer
		 * applies a strict HTML allow-list even to filtered templates.
		 *
		 * @param array<string,array<string,mixed>> $presets Preset definitions.
		 */
		$presets = apply_filters( 'wptl_presentation_presets', $presets );

		return self::normalize( is_array( $presets ) ? $presets : array() );
	}

	/**
	 * Get one preset, falling back to the built-in default.
	 *
	 * @param string $preset_id Preset ID.
	 * @return array<string,mixed>
	 */
	public static function get( string $preset_id ): array {
		$presets   = self::all();
		$preset_id = sanitize_key( $preset_id );

		if ( isset( $presets[ $preset_id ] ) ) {
			return $presets[ $preset_id ];
		}

		if ( isset( $presets[ self::DEFAULT_ID ] ) ) {
			return $presets[ self::DEFAULT_ID ];
		}

		return array(
			'id'          => self::DEFAULT_ID,
			'label'       => __( 'Standard title', 'wp-title-layer' ),
			'description' => '',
			'template'    => '<header class="wptl-title-layer"><h1 class="wptl-title wptl-title-heading">{{title}}</h1>{{subtitle_block}}</header>',
		);
	}

	/**
	 * Presets formatted for controls and JavaScript.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function choices(): array {
		$choices = array();

		foreach ( self::all() as $id => $preset ) {
			$choices[] = array(
				'value'       => $id,
				'label'       => (string) $preset['label'],
				'description' => (string) $preset['description'],
			);
		}

		return $choices;
	}

	/**
	 * IDs whose Series/subtitle recipe can be customized through settings.
	 *
	 * Filtered third-party presets remain code-owned and are deliberately not
	 * rewritten by the settings UI.
	 *
	 * @return string[]
	 */
	public static function customizableIds(): array {
		return self::CUSTOMIZABLE_IDS;
	}

	/**
	 * Default structured recipe for one built-in preset.
	 *
	 * @return array<string,string>
	 */
	public static function defaultRecipe( string $preset_id ): array {
		$preset_id = sanitize_key( $preset_id );
		$defaults  = array(
			'series_position'   => 'above',
			'subtitle_position' => 'stacked',
			'separator_style'   => 'colon',
			'custom_separator'  => '',
		);

		if ( 'inline' === $preset_id ) {
			$defaults['subtitle_position'] = 'inline';
		}
		if ( 'minimal' === $preset_id ) {
			$defaults['series_position']   = 'hidden';
			$defaults['subtitle_position'] = 'hidden';
		}

		return $defaults;
	}

	/**
	 * Normalize one recipe. Any invalid enum fails the complete record closed to
	 * that preset's defaults instead of creating a partly surprising recipe.
	 *
	 * @param mixed $submitted Raw recipe value.
	 * @return array<string,string>
	 */
	public static function normalizeRecipe( string $preset_id, $submitted ): array {
		$defaults = self::defaultRecipe( $preset_id );
		if ( ! self::isValidRecipeInput( $submitted ) ) {
			return $defaults;
		}

		$series_position   = sanitize_key( (string) ( $submitted['series_position'] ?? '' ) );
		$subtitle_position = sanitize_key( (string) ( $submitted['subtitle_position'] ?? '' ) );
		$separator_style   = sanitize_key( (string) ( $submitted['separator_style'] ?? '' ) );
		$custom_separator  = (string) ( $submitted['custom_separator'] ?? '' );
		if (
			! in_array( $series_position, array( 'above', 'hidden' ), true )
			|| ! in_array( $subtitle_position, array( 'stacked', 'inline', 'hidden' ), true )
			|| ! in_array( $separator_style, array( 'colon', 'dash', 'pipe', 'custom' ), true )
			|| $custom_separator !== wp_strip_all_tags( $custom_separator )
			|| 1 === preg_match( '/[\r\n\t]/u', $custom_separator )
		) {
			return $defaults;
		}

		return array(
			'series_position'   => $series_position,
			'subtitle_position' => $subtitle_position,
			'separator_style'   => $separator_style,
			'custom_separator'  => self::sanitizeSeparator( $custom_separator ),
		);
	}

	/** Determine whether a complete submitted recipe can replace stored data. */
	public static function isValidRecipeInput( $submitted ): bool {
		if ( ! is_array( $submitted ) ) {
			return false;
		}
		foreach ( array( 'series_position', 'subtitle_position', 'separator_style' ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $submitted ) || ! is_scalar( $submitted[ $required_key ] ) ) {
				return false;
			}
		}
		if ( isset( $submitted['custom_separator'] ) && ! is_scalar( $submitted['custom_separator'] ) ) {
			return false;
		}

		$series_position   = sanitize_key( (string) $submitted['series_position'] );
		$subtitle_position = sanitize_key( (string) $submitted['subtitle_position'] );
		$separator_style   = sanitize_key( (string) $submitted['separator_style'] );
		$custom_separator  = (string) ( $submitted['custom_separator'] ?? '' );

		return in_array( $series_position, array( 'above', 'hidden' ), true )
			&& in_array( $subtitle_position, array( 'stacked', 'inline', 'hidden' ), true )
			&& in_array( $separator_style, array( 'colon', 'dash', 'pipe', 'custom' ), true )
			&& $custom_separator === wp_strip_all_tags( $custom_separator )
			&& 1 !== preg_match( '/[\r\n\t]/u', $custom_separator );
	}

	/** Treat missing recipe versions as v1 while rejecting malformed/future data. */
	public static function hasSupportedRecipeVersion( array $presentation ): bool {
		if ( ! array_key_exists( 'preset_schema_version', $presentation ) ) {
			return true;
		}

		$version = $presentation['preset_schema_version'];
		return ( is_int( $version ) && self::RECIPE_SCHEMA_VERSION === $version )
			|| ( is_string( $version ) && (string) self::RECIPE_SCHEMA_VERSION === $version );
	}

	/**
	 * Sanitize submitted recipe overrides. Defaults and explicit resets are not
	 * stored, so upgrades can retain the plugin's canonical built-in behavior.
	 *
	 * @param mixed $submitted Raw settings value.
	 * @return array<string,array<string,string>>
	 */
	public static function sanitizeRecipeOverrides( $submitted ): array {
		$submitted = is_array( $submitted ) ? $submitted : array();
		$clean     = array();
		foreach ( self::CUSTOMIZABLE_IDS as $preset_id ) {
			if ( ! isset( $submitted[ $preset_id ] ) || ! is_array( $submitted[ $preset_id ] ) || ! empty( $submitted[ $preset_id ]['_reset'] ) ) {
				continue;
			}
			if ( ! self::isValidRecipeInput( $submitted[ $preset_id ] ) ) {
				continue;
			}
			$recipe = self::normalizeRecipe( $preset_id, $submitted[ $preset_id ] );
			if ( $recipe !== self::defaultRecipe( $preset_id ) ) {
				$clean[ $preset_id ] = $recipe;
			}
		}

		return $clean;
	}

	/** @return array<string,array<string,string>> */
	public static function normalizedRecipes( $stored ): array {
		$stored  = is_array( $stored ) ? $stored : array();
		$recipes = array();
		foreach ( self::CUSTOMIZABLE_IDS as $preset_id ) {
			$recipes[ $preset_id ] = self::normalizeRecipe( $preset_id, $stored[ $preset_id ] ?? null );
		}

		return $recipes;
	}

	/** Get the effective recipe for a built-in preset. */
	public static function recipe( string $preset_id ): ?array {
		$preset_id = sanitize_key( $preset_id );
		if ( ! in_array( $preset_id, self::CUSTOMIZABLE_IDS, true ) ) {
			return null;
		}

		$settings = get_option( Schema::settingsOption(), array() );
		$presentation = is_array( $settings ) && isset( $settings['presentation'] ) && is_array( $settings['presentation'] )
			? $settings['presentation']
			: array();
		$has_overrides = array_key_exists( 'preset_customizations', $presentation );
		$raw = $has_overrides && self::hasSupportedRecipeVersion( $presentation )
			? $presentation['preset_customizations']
			: array();
		$all      = self::normalizedRecipes( $raw );

		return $all[ $preset_id ];
	}

	/** Return the safe visible separator selected for an inline subtitle. */
	public static function separatorText( array $recipe ): string {
		$style = sanitize_key( (string) ( $recipe['separator_style'] ?? 'colon' ) );
		if ( ! in_array( $style, array( 'colon', 'dash', 'pipe', 'custom' ), true ) ) {
			$style = 'colon';
		}
		if ( 'custom' === $style ) {
			$custom = self::sanitizeSeparator( (string) ( $recipe['custom_separator'] ?? '' ) );
			return '' !== $custom ? $custom : self::localeSeparators()['colon'];
		}

		return self::localeSeparators()[ $style ];
	}

	/**
	 * Punctuation is presentation, not stored content. The saved recipe keeps a
	 * semantic style key so switching the WordPress locale can use its customary
	 * glyphs without rewriting settings.
	 *
	 * @return array<string,string>
	 */
	public static function localeSeparators(): array {
		return array(
			'colon' => _x( ': ', 'inline title and subtitle separator: colon', 'wp-title-layer' ),
			'dash'  => _x( ' — ', 'inline title and subtitle separator: dash', 'wp-title-layer' ),
			'pipe'  => _x( ' | ', 'inline title and subtitle separator: vertical bar', 'wp-title-layer' ),
		);
	}

	/** Strip markup and limit a custom separator to eight Unicode characters. */
	private static function sanitizeSeparator( string $value ): string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return '';
		}

		$matched = preg_match_all( '/./us', $value, $characters );
		if ( false === $matched || ! isset( $characters[0] ) ) {
			return '';
		}

		return implode( '', array_slice( $characters[0], 0, self::CUSTOM_SEPARATOR_LENGTH ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $presets Raw definitions.
	 * @return array<string,array<string,mixed>>
	 */
	private static function normalize( array $presets ): array {
		$normalized = array();

		foreach ( $presets as $id => $preset ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $preset ) || empty( $preset['template'] ) ) {
				continue;
			}

			$normalized[ $id ] = array(
				'id'          => $id,
				'label'       => sanitize_text_field( (string) ( $preset['label'] ?? $id ) ),
				'description' => sanitize_text_field( (string) ( $preset['description'] ?? '' ) ),
				'template'    => (string) $preset['template'],
			);
		}

		return $normalized;
	}
}
