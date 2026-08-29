<?php
/**
 * Reader settings backed by WP Title Layer's shared option.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Reader;

use WPTitleLayer\Core\Schema;
use WPTitleLayer\Core\Series;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public const SUBTREE                  = 'reader';
	public const ARCHIVE_TEMPLATE_ENABLED = 'archive_template_enabled';
	public const ARCHIVE_SHOW_SUBTITLES   = 'archive_show_subtitles';
	public const ARCHIVE_SHOW_EXCERPTS    = 'archive_show_excerpts';
	public const THEME_ARCHIVE_SHOW_SUBTITLES = 'theme_archive_show_subtitles';
	public const ARCHIVE_SHOW_FEATURED_IMAGES = 'archive_show_featured_images';
	public const AFTER_CONTENT_ENABLED     = 'after_content_enabled';
	public const SHORTCODE_ENABLED         = 'shortcode_enabled';

	/**
	 * Return normalized Reader settings.
	 *
	 * Reader preserves a deliberately small contract. A settings UI may write
	 * this subtree later without making this module depend on that UI.
	 *
	 * @return array{archive_template_enabled:bool,archive_show_subtitles:bool,archive_show_excerpts:bool,theme_archive_show_subtitles:bool,archive_show_featured_images:bool,after_content_enabled:bool,shortcode_enabled:bool}
	 */
	public static function all(): array {
		$all    = get_option( Schema::OPTION_SETTINGS, array() );
		$reader = is_array( $all ) && isset( $all[ self::SUBTREE ] ) && is_array( $all[ self::SUBTREE ] )
			? $all[ self::SUBTREE ]
			: array();

		$settings = array(
			self::ARCHIVE_TEMPLATE_ENABLED => self::toBool( $reader[ self::ARCHIVE_TEMPLATE_ENABLED ] ?? false, false ),
			self::ARCHIVE_SHOW_SUBTITLES   => self::toBool( $reader[ self::ARCHIVE_SHOW_SUBTITLES ] ?? true, true ),
			self::ARCHIVE_SHOW_EXCERPTS    => self::toBool( $reader[ self::ARCHIVE_SHOW_EXCERPTS ] ?? false, false ),
			self::THEME_ARCHIVE_SHOW_SUBTITLES => self::toBool( $reader[ self::THEME_ARCHIVE_SHOW_SUBTITLES ] ?? false, false ),
			self::ARCHIVE_SHOW_FEATURED_IMAGES => self::toBool( $reader[ self::ARCHIVE_SHOW_FEATURED_IMAGES ] ?? false, false ),
			self::AFTER_CONTENT_ENABLED     => self::toBool( $reader[ self::AFTER_CONTENT_ENABLED ] ?? false, false ),
			self::SHORTCODE_ENABLED         => self::toBool( $reader[ self::SHORTCODE_ENABLED ] ?? true, true ),
		);

		/** @param array<string,bool> $settings Normalized Reader settings. */
		$filtered = apply_filters( 'wptl_reader_settings', $settings );

		if ( is_array( $filtered ) ) {
			foreach ( $settings as $key => $default ) {
				if ( array_key_exists( $key, $filtered ) ) {
					$settings[ $key ] = self::toBool( $filtered[ $key ], $default );
				}
			}
		}

		return $settings;
	}

	public static function archiveTemplateEnabled( ?\WP_Term $term = null ): bool {
		$enabled = self::all()[ self::ARCHIVE_TEMPLATE_ENABLED ];
		if ( $term instanceof \WP_Term ) {
			$override = Series::archive_layout( $term );
			if ( Schema::ARCHIVE_LAYOUT_STRUCTURED === $override ) {
				$enabled = true;
			} elseif ( Schema::ARCHIVE_LAYOUT_THEME === $override ) {
				$enabled = false;
			}
		}

		/**
		 * @param bool          $enabled Whether the plugin Series archive template is enabled.
		 * @param \WP_Term|null $term    Current Series, when resolving a term override.
		 */
		return (bool) apply_filters( 'wptl_reader_archive_template_enabled', $enabled, $term );
	}

	public static function archiveSubtitlesEnabled( ?\WP_Term $term = null ): bool {
		$enabled = self::all()[ self::ARCHIVE_SHOW_SUBTITLES ];
		$enabled = self::visibilityOverride( $enabled, $term, Schema::TERM_META_ARCHIVE_SUBTITLES );

		/**
		 * @param bool          $enabled Whether article subtitles appear in the plugin-owned Series archive.
		 * @param \WP_Term|null $term    Current Series, when resolving a term override.
		 */
		return (bool) apply_filters( 'wptl_reader_archive_subtitles_enabled', $enabled, $term );
	}

	public static function archiveExcerptsEnabled( ?\WP_Term $term = null ): bool {
		$enabled = self::all()[ self::ARCHIVE_SHOW_EXCERPTS ];
		$enabled = self::visibilityOverride( $enabled, $term, Schema::TERM_META_ARCHIVE_EXCERPTS );

		/**
		 * @param bool          $enabled Whether article excerpts appear in the plugin-owned Series archive.
		 * @param \WP_Term|null $term    Current Series, when resolving a term override.
		 */
		return (bool) apply_filters( 'wptl_reader_archive_excerpts_enabled', $enabled, $term );
	}

	public static function themeArchiveSubtitlesEnabled( ?\WP_Term $term = null ): bool {
		$enabled = self::all()[ self::THEME_ARCHIVE_SHOW_SUBTITLES ];
		$enabled = self::visibilityOverride( $enabled, $term, Schema::TERM_META_ARCHIVE_SUBTITLES );

		/**
		 * @param bool          $enabled Whether a verified theme adapter may add archive-card Subtitles.
		 * @param \WP_Term|null $term    Current Series, when resolving a term override.
		 */
		return (bool) apply_filters( 'wptl_reader_theme_archive_subtitles_enabled', $enabled, $term );
	}

	public static function archiveFeaturedImagesEnabled( ?\WP_Term $term = null ): bool {
		$enabled = self::all()[ self::ARCHIVE_SHOW_FEATURED_IMAGES ];
		$enabled = self::visibilityOverride( $enabled, $term, Schema::TERM_META_ARCHIVE_FEATURED_IMAGES );

		/**
		 * @param bool          $enabled Whether structured archive entries show featured images.
		 * @param \WP_Term|null $term    Current Series, when resolving a term override.
		 */
		return (bool) apply_filters( 'wptl_reader_archive_featured_images_enabled', $enabled, $term );
	}

	public static function afterContentEnabled( int $post_id = 0 ): bool {
		$enabled = self::all()[ self::AFTER_CONTENT_ENABLED ];

		/**
		 * @param bool $enabled Whether automatic after-content navigation is enabled.
		 * @param int  $post_id Current post ID.
		 */
		return (bool) apply_filters( 'wptl_reader_after_content_enabled', $enabled, $post_id );
	}

	public static function shortcodeEnabled(): bool {
		$enabled = self::all()[ self::SHORTCODE_ENABLED ];

		/** @param bool $enabled Whether [wptl_series_navigation] is registered. */
		return (bool) apply_filters( 'wptl_reader_shortcode_enabled', $enabled );
	}

	private static function visibilityOverride( bool $enabled, ?\WP_Term $term, string $meta_key ): bool {
		if ( ! $term instanceof \WP_Term ) {
			return $enabled;
		}

		if ( Schema::TERM_META_ARCHIVE_FEATURED_IMAGES === $meta_key ) {
			$override = Series::archive_featured_images( $term );
		} elseif ( Schema::TERM_META_ARCHIVE_EXCERPTS === $meta_key ) {
			$override = Series::archive_excerpts( $term );
		} else {
			$override = Series::archive_subtitles( $term );
		}
		if ( Schema::ARCHIVE_VISIBILITY_SHOW === $override ) {
			return true;
		}
		if ( Schema::ARCHIVE_VISIBILITY_HIDE === $override ) {
			return false;
		}

		return $enabled;
	}

	/** @param mixed $value */
	private static function toBool( $value, bool $default ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 1 === (int) $value;
		}
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( '0', 'false', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}

		return $default;
	}
}
