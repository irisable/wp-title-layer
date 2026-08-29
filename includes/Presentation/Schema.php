<?php
/**
 * Runtime bridge to the canonical Core schema.
 *
 * Presentation can be loaded in isolation during tests, so every value has a
 * conservative fallback. When Core\Schema is available its constants win.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Presentation;

defined( 'ABSPATH' ) || exit;

final class Schema {
	/** @var string */
	private const CORE_CLASS = '\\WPTitleLayer\\Core\\Schema';

	/**
	 * Resolve the first constant exposed by Core\Schema, or use the fallback.
	 *
	 * Supporting a small alias list keeps this layer compatible while the Core
	 * class evolves without weakening the public database contract.
	 *
	 * @param string[] $constant_names Candidate constant names.
	 * @param string   $fallback       Frozen schema value.
	 * @return string
	 */
	private static function resolve( array $constant_names, string $fallback ): string {
		foreach ( $constant_names as $constant_name ) {
			$qualified = self::CORE_CLASS . '::' . $constant_name;
			if ( defined( $qualified ) ) {
				$value = constant( $qualified );
				if ( is_string( $value ) && '' !== $value ) {
					return $value;
				}
			}
		}

		return $fallback;
	}

	public static function seriesTaxonomy(): string {
		$series_class = '\\WPTitleLayer\\Core\\Series';
		if ( class_exists( $series_class ) && is_callable( array( $series_class, 'claimed_taxonomy' ) ) ) {
			$taxonomy = call_user_func( array( $series_class, 'claimed_taxonomy' ) );
			return is_string( $taxonomy ) ? $taxonomy : '';
		}

		if ( class_exists( self::CORE_CLASS ) && is_callable( array( self::CORE_CLASS, 'taxonomy' ) ) ) {
			$taxonomy = call_user_func( array( self::CORE_CLASS, 'taxonomy' ) );
			if ( is_string( $taxonomy ) && '' !== $taxonomy ) {
				return $taxonomy;
			}
		}

		return self::resolve( array( 'TAXONOMY_SERIES', 'SERIES_TAXONOMY' ), 'wptl_series' );
	}

	public static function subtitleMeta(): string {
		return self::resolve( array( 'META_SUBTITLE', 'SUBTITLE_META' ), 'wptl_subtitle' );
	}

	/** Read legacy subtitle content without exposing an ACF field-key reference. */
	public static function legacySubtitle( int $post_id ): string {
		$value = (string) get_post_meta( $post_id, '_secondary_title', true );
		return 1 === preg_match( '/^field_[A-Za-z0-9_-]+$/', $value ) ? '' : $value;
	}

	public static function seasonKeyMeta(): string {
		return self::resolve( array( 'META_SEASON_KEY', 'SEASON_KEY_META' ), 'wptl_season_key' );
	}

	public static function sequencePositionMeta(): string {
		return self::resolve( array( 'META_SEQUENCE_POSITION', 'SEQUENCE_POSITION_META' ), 'wptl_sequence_position' );
	}

	public static function sequenceSchemaVersionMeta(): string {
		return self::resolve( array( 'TERM_META_SEQUENCE_SCHEMA_VERSION' ), 'wptl_sequence_schema_version' );
	}

	public static function sequenceLabelMeta(): string {
		return self::resolve( array( 'META_SEQUENCE_LABEL', 'SEQUENCE_LABEL_META' ), 'wptl_sequence_label' );
	}

	public static function seriesRoleMeta(): string {
		return self::resolve( array( 'META_SERIES_ROLE', 'SERIES_ROLE_META' ), 'wptl_series_role' );
	}

	public static function seriesScopeMeta(): string {
		return self::resolve( array( 'META_SERIES_SCOPE' ), 'wptl_series_scope' );
	}

	public static function templateOverrideMeta(): string {
		return self::resolve( array( 'META_TEMPLATE_OVERRIDE', 'TEMPLATE_OVERRIDE_META' ), 'wptl_template_override' );
	}

	public static function kickerOverrideMeta(): string {
		return self::resolve( array( 'META_KICKER_OVERRIDE', 'KICKER_OVERRIDE_META' ), 'wptl_kicker_override' );
	}

	public static function seriesModeMeta(): string {
		return self::resolve( array( 'TERM_META_SERIES_MODE', 'META_SERIES_MODE' ), 'wptl_series_mode' );
	}

	public static function seriesStructureMeta(): string {
		return self::resolve( array( 'TERM_META_SERIES_STRUCTURE', 'META_SERIES_STRUCTURE' ), 'wptl_series_structure' );
	}

	public static function archiveSortMeta(): string {
		return self::resolve( array( 'TERM_META_ARCHIVE_SORT', 'META_ARCHIVE_SORT' ), 'wptl_archive_sort' );
	}

	public static function seasonsMeta(): string {
		return self::resolve( array( 'TERM_META_SEASONS', 'META_SEASONS' ), 'wptl_seasons' );
	}

	public static function defaultTemplateMeta(): string {
		return self::resolve( array( 'TERM_META_DEFAULT_TEMPLATE', 'META_DEFAULT_TEMPLATE' ), 'wptl_default_template' );
	}

	public static function shortLabelMeta(): string {
		return self::resolve( array( 'TERM_META_SHORT_LABEL', 'META_SHORT_LABEL' ), 'wptl_short_label' );
	}

	public static function coverIdMeta(): string {
		return self::resolve( array( 'TERM_META_COVER_ID', 'META_COVER_ID' ), 'wptl_cover_id' );
	}

	public static function iconIdMeta(): string {
		return self::resolve( array( 'TERM_META_ICON_ID', 'META_ICON_ID' ), 'wptl_icon_id' );
	}

	public static function titleIconMeta(): string {
		return self::resolve( array( 'TERM_META_TITLE_ICON' ), 'wptl_title_icon' );
	}

	public static function parentCategoryMeta(): string {
		return self::resolve( array( 'TERM_META_PARENT_CATEGORY_ID' ), 'wptl_parent_category_id' );
	}

	public static function settingsOption(): string {
		return self::resolve( array( 'OPTION_SETTINGS', 'SETTINGS_OPTION' ), 'wptl_settings' );
	}

	/**
	 * Export schema values needed by editor JavaScript.
	 *
	 * @return array<string,string>
	 */
	public static function editorMap(): array {
		return array(
			'taxonomy'            => self::seriesTaxonomy(),
			'subtitle'            => self::subtitleMeta(),
			'seasonKey'           => self::seasonKeyMeta(),
			'sequencePosition'    => self::sequencePositionMeta(),
			'sequenceSchemaVersion' => self::sequenceSchemaVersionMeta(),
			'sequenceLabel'       => self::sequenceLabelMeta(),
			'seriesRole'          => self::seriesRoleMeta(),
			'seriesScope'         => self::seriesScopeMeta(),
			'templateOverride'    => self::templateOverrideMeta(),
			'kickerOverride'      => self::kickerOverrideMeta(),
			'seriesMode'          => self::seriesModeMeta(),
			'seriesStructure'     => self::seriesStructureMeta(),
			'seasons'             => self::seasonsMeta(),
			'defaultTemplate'     => self::defaultTemplateMeta(),
			'parentCategory'      => self::parentCategoryMeta(),
		);
	}
}
