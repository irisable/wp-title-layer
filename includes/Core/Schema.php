<?php
/**
 * Canonical content-model identifiers for WP Title Layer.
 *
 * @package WPTitleLayer
 */

namespace WPTitleLayer\Core;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const TAXONOMY_SERIES = 'wptl_series';
	public const SERIES_TAXONOMY = self::TAXONOMY_SERIES;

	public const META_SUBTITLE          = 'wptl_subtitle';
	public const META_SEASON_KEY        = 'wptl_season_key';
	public const META_SEQUENCE_POSITION = 'wptl_sequence_position';
	public const META_SEQUENCE_RANK     = 'wptl_sequence_rank';
	public const META_SEQUENCE_SOURCE_POSITION = 'wptl_sequence_source_position';
	public const META_SEQUENCE_LABEL    = 'wptl_sequence_label';
	public const META_SERIES_GROUP      = 'wptl_series_group';
	public const META_GROUP_ID          = '_wptl_group_id';
	public const META_SERIES_ROLE       = 'wptl_series_role';
	public const META_SERIES_SCOPE      = 'wptl_series_scope';
	public const META_TEMPLATE_OVERRIDE = 'wptl_template_override';
	public const META_KICKER_OVERRIDE   = 'wptl_kicker_override';

	public const TERM_META_SERIES_MODE      = 'wptl_series_mode';
	public const TERM_META_SERIES_STATUS    = 'wptl_series_status';
	public const TERM_META_SERIES_STRUCTURE = 'wptl_series_structure';
	public const TERM_META_NAVIGATION_SCOPE = 'wptl_navigation_scope';
	public const TERM_META_ARCHIVE_SORT     = 'wptl_archive_sort';
	public const TERM_META_SEASONS          = 'wptl_seasons';
	public const TERM_META_DEFAULT_TEMPLATE = 'wptl_default_template';
	public const TERM_META_SHORT_LABEL      = 'wptl_short_label';
	public const TERM_META_COVER_ID         = 'wptl_cover_id';
	public const TERM_META_ICON_ID          = 'wptl_icon_id';
	public const TERM_META_PARENT_CATEGORY_ID       = 'wptl_parent_category_id';
	public const TERM_META_ARCHIVE_LAYOUT           = 'wptl_archive_layout';
	public const TERM_META_ARCHIVE_SUBTITLES        = 'wptl_archive_subtitles';
	public const TERM_META_ARCHIVE_EXCERPTS         = 'wptl_archive_excerpts';
	public const TERM_META_ARCHIVE_FEATURED_IMAGES  = 'wptl_archive_featured_images';
	public const TERM_META_TITLE_ICON                = 'wptl_title_icon';
	public const TERM_META_SEQUENCE_SCHEMA_VERSION   = 'wptl_sequence_schema_version';
	public const TERM_META_SEQUENCE_REVISIONS        = 'wptl_sequence_revisions';
	public const TERM_META_SEASON_ID_HIGHWATER       = 'wptl_season_public_id_highwater';
	public const TERM_META_BOOK_STRUCTURE_VERSION    = 'wptl_book_structure_version';

	// Short aliases retained for consumers that name constants after the field.
	public const TERM_META_MODE       = self::TERM_META_SERIES_MODE;
	public const TERM_META_STRUCTURE  = self::TERM_META_SERIES_STRUCTURE;
	public const TERM_META_SORT       = self::TERM_META_ARCHIVE_SORT;

	public const OPTION_SETTINGS = 'wptl_settings';
	public const QUERY_VAR_SEASON = 'wptl_season';
	public const QUERY_VAR_RESOLVED_SEASON = 'wptl_resolved_season';

	public const SEQUENCE_SCHEMA_VERSION = 1;

	public const MODE_ORDERED   = 'ordered';
	public const MODE_UNORDERED = 'unordered';

	public const STATUS_PLANNING  = 'planning';
	public const STATUS_ONGOING   = 'ongoing';
	public const STATUS_PAUSED    = 'paused';
	public const STATUS_COMPLETED = 'completed';

	public const STRUCTURE_FLAT     = 'flat';
	public const STRUCTURE_SEASONED = 'seasoned';

	public const NAVIGATION_SCOPE_SERIES = 'series';
	public const NAVIGATION_SCOPE_SEASON = 'season';

	public const ARCHIVE_SORT_DATE_DESC = 'date_desc';
	public const ARCHIVE_SORT_DATE_ASC  = 'date_asc';
	public const ARCHIVE_SORT_TITLE     = 'title';

	public const ARCHIVE_LAYOUT_INHERIT    = 'inherit';
	public const ARCHIVE_LAYOUT_THEME      = 'theme';
	public const ARCHIVE_LAYOUT_STRUCTURED = 'structured';

	public const ARCHIVE_VISIBILITY_INHERIT = 'inherit';
	public const ARCHIVE_VISIBILITY_SHOW    = 'show';
	public const ARCHIVE_VISIBILITY_HIDE    = 'hide';

	public const ROLE_INTRO    = 'intro';
	public const ROLE_ARTICLE  = 'article';
	public const ROLE_EPILOGUE = 'epilogue';
	public const ROLE_APPENDIX = 'appendix';

	public const SCOPE_SERIES = 'series';
	public const SCOPE_SEASON = 'season';

	/**
	 * Return the active taxonomy key. The filter permits a deliberate takeover
	 * of an existing Series taxonomy without changing stored relationships.
	 */
	public static function taxonomy(): string {
		$taxonomy = (string) apply_filters( 'wptl_series_taxonomy_key', self::TAXONOMY_SERIES );
		$taxonomy = sanitize_key( $taxonomy );

		return '' !== $taxonomy && 32 >= strlen( $taxonomy ) ? $taxonomy : self::TAXONOMY_SERIES;
	}

	/** @return string[] */
	public static function modes(): array {
		return [ self::MODE_ORDERED, self::MODE_UNORDERED ];
	}

	/** @return string[] */
	public static function statuses(): array {
		return [ self::STATUS_PLANNING, self::STATUS_ONGOING, self::STATUS_PAUSED, self::STATUS_COMPLETED ];
	}

	/** @return string[] */
	public static function structures(): array {
		return [ self::STRUCTURE_FLAT, self::STRUCTURE_SEASONED ];
	}

	/** @return string[] */
	public static function navigation_scopes(): array {
		return [ self::NAVIGATION_SCOPE_SERIES, self::NAVIGATION_SCOPE_SEASON ];
	}

	/** @return string[] */
	public static function archive_sorts(): array {
		return [ self::ARCHIVE_SORT_DATE_DESC, self::ARCHIVE_SORT_DATE_ASC, self::ARCHIVE_SORT_TITLE ];
	}

	/** @return string[] */
	public static function archive_layouts(): array {
		return [ self::ARCHIVE_LAYOUT_INHERIT, self::ARCHIVE_LAYOUT_THEME, self::ARCHIVE_LAYOUT_STRUCTURED ];
	}

	/** @return string[] */
	public static function archive_visibility_overrides(): array {
		return [ self::ARCHIVE_VISIBILITY_INHERIT, self::ARCHIVE_VISIBILITY_SHOW, self::ARCHIVE_VISIBILITY_HIDE ];
	}

	/** @return string[] */
	public static function roles(): array {
		return [ self::ROLE_INTRO, self::ROLE_ARTICLE, self::ROLE_EPILOGUE, self::ROLE_APPENDIX ];
	}

	/** @return string[] */
	public static function content_scopes(): array {
		return [ self::SCOPE_SERIES, self::SCOPE_SEASON ];
	}

	/** @return array<string,string> */
	public static function role_labels(): array {
		return [
			''                  => __( 'Unspecified role (treated as a main article)', 'wp-title-layer' ),
			self::ROLE_INTRO    => __( 'Introduction / preface', 'wp-title-layer' ),
			self::ROLE_ARTICLE  => __( 'Main article (explicit)', 'wp-title-layer' ),
			self::ROLE_EPILOGUE => __( 'Epilogue / afterword', 'wp-title-layer' ),
			self::ROLE_APPENDIX => __( 'Appendix', 'wp-title-layer' ),
		];
	}

	/** @return array<string,string> */
	public static function scope_labels(): array {
		return [
			''                 => __( 'Choose scope (legacy value is unresolved)', 'wp-title-layer' ),
			self::SCOPE_SERIES => __( 'Entire Series', 'wp-title-layer' ),
			self::SCOPE_SEASON => __( 'One season', 'wp-title-layer' ),
		];
	}
}
