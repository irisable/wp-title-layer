const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

function read( relativePath ) {
	return fs.readFileSync( path.join( __dirname, '..', relativePath ), 'utf8' );
}

const editor = read( 'assets/editor.js' );
const seriesAdmin = read( 'assets/series-admin.js' );
const seriesAdminCss = read( 'assets/series-admin.css' );
const classicEditor = read( 'assets/classic-editor.js' );
const classicEditorPhp = read( 'includes/Admin/ClassicEditor.php' );
const frontendCss = read( 'assets/frontend.css' );
const readerCss = read( 'assets/reader.css' );
const presets = read( 'includes/Presentation/Presets.php' );
const readerSettings = read( 'includes/Reader/Settings.php' );
const readerAdmin = read( 'includes/Reader/AdminSettings.php' );
const themeArchive = read( 'includes/Reader/ThemeArchiveIntegration.php' );
const readerArchive = read( 'includes/Reader/Archive.php' );
const seriesHealth = read( 'includes/Admin/SeriesHealth.php' );
const channelHealth = read( 'includes/Admin/ChannelHealth.php' );
const archiveTemplate = read( 'templates/series-archive.php' );
const navigationTemplate = read( 'templates/series-navigation.php' );
const rankMath = read( 'includes/Integrations/RankMath.php' );
const plugin = read( 'includes/Plugin.php' );
const coreSchema = read( 'includes/Core/Schema.php' );
const coreSeries = read( 'includes/Core/Series.php' );
const postColumns = read( 'includes/Admin/PostListColumns.php' );

test( 'Series archive links inherit the theme until hover or keyboard focus', () => {
	assert.match( frontendCss, /\.wptl-series-link,[\s\S]*?\.wptl-season-link,[\s\S]*?\.wptl-group-link\s*\{[\s\S]*?color:\s*inherit;[\s\S]*?text-decoration:\s*none;/ );
	assert.match( frontendCss, /\.wptl-series-link:hover,[\s\S]*?var\(--global-palette-highlight/ );
	assert.match( frontendCss, /\.wptl-series-link:hover,[\s\S]*?text-decoration-line:\s*underline/ );
	assert.match( frontendCss, /\.wptl-series-link:focus-visible[\s\S]*?outline:/ );
} );

test( 'season labels link to a real filtered archive view', () => {
	assert.match( coreSeries, /public_season_archive_url/ );
	assert.match( coreSeries, /QUERY_VAR_SEASON/ );
	assert.match( coreSeries, /BINARY wptl_season_filter_value\.meta_value = BINARY %s/ );
	assert.match( frontendCss, /\.wptl-season-link:hover/ );
} );

test( 'the four templates describe distinct publishing situations', () => {
	assert.match( presets, /Neutral and theme-led for most articles/ );
	assert.match( presets, /For long-form and Series features/ );
	assert.match( presets, /For short titles and compact Series entries/ );
	assert.match( presets, /if \( 'inline' === \$preset_id \) \{\s*\$defaults\['subtitle_position'\]\s*=\s*'inline';\s*\}/ );
	assert.match( presets, /For ordinary posts or strict theme compatibility/ );
	assert.match( frontendCss, /\.wptl-title-layer--editorial\s*\{[\s\S]*?border-inline-start/ );
} );

test( 'the sidebar subtitle remains multiline without consuming excessive height', () => {
	assert.match( editor, /TextareaControl,[\s\S]*?key:\s*'subtitle',[\s\S]*?rows:\s*2/ );
} );

test( 'Series archives expose theme and structured modes with a subtitle policy', () => {
	assert.match( readerAdmin, /Use the active theme’s archive layout/ );
	assert.match( readerAdmin, /Use WP Title Layer’s structured Series layout/ );
	assert.match( readerSettings, /ARCHIVE_SHOW_SUBTITLES\s*=\s*'archive_show_subtitles'/ );
	assert.match( archiveTemplate, /id="wptl-series-archive"/ );
	assert.doesNotMatch( archiveTemplate, /id="primary"/ );
	assert.match( archiveTemplate, /\$wptl_view\['show_subtitles'\]/ );
	assert.match( archiveTemplate, /\$wptl_view\['show_featured_images'\]/ );
	assert.match( archiveTemplate, /wptl-series-list__media/ );
	assert.match( readerCss, /\.wptl-series-archive\s*\{[\s\S]*?width:\s*min\(calc\(100% - 2rem\), 72rem\);[\s\S]*?margin:\s*0 auto;/ );
	assert.match( readerCss, /\.wptl-series-archive__header--has-cover\s*\{[\s\S]*?display:\s*grid/ );
	assert.match( readerCss, /font-size:\s*clamp\(2rem, 3\.25vw, 3\.25rem\)/ );
	assert.match( readerCss, /\.wptl-series-navigation a\s*\{[\s\S]*?text-decoration-line:\s*none/ );
	assert.match( readerCss, /\.wptl-series-navigation a:hover,[\s\S]*?text-decoration-line:\s*underline/ );
} );

test( 'structured archive excerpts are opt-in and independently overridable per Series', () => {
	assert.match( readerSettings, /ARCHIVE_SHOW_EXCERPTS\s*=\s*'archive_show_excerpts'/ );
	assert.match( readerSettings, /archiveExcerptsEnabled/ );
	assert.match( readerAdmin, /Structured archive excerpts/ );
	assert.match( coreSchema, /TERM_META_ARCHIVE_EXCERPTS/ );
	assert.match( coreSeries, /Article excerpts on this archive/ );
	assert.match( readerArchive, /'show_excerpts'/ );
	assert.match( readerArchive, /'excerpt'\s*=>/ );
	assert.match( archiveTemplate, /wptl-series-list__excerpt/ );
	assert.match( readerCss, /\.wptl-series-list__excerpt\s*\{/ );
	assert.doesNotMatch( themeArchive, /archiveExcerptsEnabled|wptl-series-list__excerpt/ );
} );

test( 'each Series may inherit or override its archive presentation without changing article taxonomy', () => {
	for ( const key of [
		'TERM_META_PARENT_CATEGORY_ID',
		'TERM_META_ARCHIVE_LAYOUT',
		'TERM_META_ARCHIVE_SUBTITLES',
		'TERM_META_ARCHIVE_FEATURED_IMAGES'
	] ) {
		assert.match( coreSchema, new RegExp( key ) );
		assert.match( coreSeries, new RegExp( key ) );
	}
	assert.match( readerSettings, /archiveTemplateEnabled\( \?\\WP_Term \$term = null \)/ );
	assert.match( readerSettings, /archiveFeaturedImagesEnabled/ );
	assert.match( readerAdmin, /Off by default to preserve existing layouts/ );
	assert.match( archiveTemplate, /wptl-series-archive__parent-category/ );
	assert.match( readerCss, /\.wptl-series-list__item--has-image/ );
	assert.doesNotMatch( coreSeries, /wp_set_post_categories/ );
} );

test( 'Series and Category are checked as compatible branches without blocking article saves', () => {
	assert.match( editor, /Series \/ Category mismatch/ );
	assert.match( editor, /categoryBranchIds/ );
	assert.match( editor, /categoryConsistent/ );
	assert.match( seriesHealth, /Category branch/ );
} );

test( 'Series creation can add season rows without leaving the form', () => {
	assert.match( coreSeries, /max\( 4, count\( \$seasons \) \+ 3 \)/ );
	assert.match( coreSeries, /wptl-add-season-row/ );
	assert.match( seriesAdmin, /cloneNode\( true \)/ );
	assert.match( seriesAdmin, /data-next-index/ );
} );

test( 'Series covers progressively enhance to the native media library', () => {
	assert.match( coreSeries, /data-wptl-cover-control/ );
	assert.match( coreSeries, /wp_enqueue_media\(\)/ );
	assert.match( seriesAdmin, /wp\.media/ );
	assert.match( seriesAdmin, /library:\s*\{ type: 'image' \}/ );
	assert.match( seriesAdmin, /input\.type = 'hidden'/ );
	assert.match( seriesAdmin, /input\.value = String\( attachment\.id \)/ );
	assert.match( seriesAdminCss, /\.wptl-cover-preview__image/ );
} );

test( 'Classic Editor gets a fail-safe canonical Title Layer meta box', () => {
	assert.match( plugin, /ClassicEditor::register\(\)/ );
	assert.match( classicEditorPhp, /use_block_editor_for_post\( \$post \)/ );
	assert.match( classicEditorPhp, /wptl_classic_editor_owns_series_control/ );
	assert.match( classicEditorPhp, /update_post_meta\( \$post_id, Schema::META_SUBTITLE/ );
	assert.match( classicEditorPhp, /wp_set_object_terms/ );
	assert.doesNotMatch( classicEditorPhp, /wp_insert_term|Add New Series/ );
	assert.match( classicEditor, /data-wptl-series-condition/ );
} );

test( 'theme archive subtitles are opt-in and confined to a verified Kadence slot', () => {
	assert.match( readerSettings, /THEME_ARCHIVE_SHOW_SUBTITLES\s*=\s*'theme_archive_show_subtitles'/ );
	assert.match( themeArchive, /kadence_loop_entry_header/ );
	assert.match( themeArchive, /renderKadenceSubtitle' \), 25/ );
	assert.match( themeArchive, /is_tax\( \$taxonomy \)/ );
	assert.match( themeArchive, /TemplateLoader::usesPluginArchiveTemplate\(\)/ );
	assert.match( themeArchive, /hasVerifiedKadenceArchiveTemplates/ );
	assert.doesNotMatch( themeArchive, /add_filter\(\s*'the_title'/ );
	assert.match( readerCss, /\.wptl-theme-archive-subtitle\s*\{[\s\S]*?font-size:\s*clamp\(1\.0625rem, 1rem \+ 0\.25vw, 1\.1875rem\)/ );
	assert.doesNotMatch( readerCss, /\.wptl-theme-archive-subtitle\s*\{[\s\S]*?font-size:\s*0\.92em/ );
} );

test( 'page navigation is distinct from previous and next articles', () => {
	for ( const paginationOwner of [ seriesHealth, channelHealth ] ) {
		assert.match( paginationOwner, /'prev_text'\s*=>\s*__\( 'Previous page', 'wp-title-layer' \)/ );
		assert.match( paginationOwner, /'next_text'\s*=>\s*__\( 'Next page', 'wp-title-layer' \)/ );
	}
	assert.match( readerArchive, /'prev_text'\s*=>[\s\S]*?&lsaquo;[\s\S]*?esc_html__\( 'Previous page', 'wp-title-layer' \)/ );
	assert.match( readerArchive, /'next_text'\s*=>[\s\S]*?&rsaquo;[\s\S]*?esc_html__\( 'Next page', 'wp-title-layer' \)/ );
	assert.match( readerCss, /\.wptl-series-archive__pagination span\.current\s*\{[\s\S]*?background:\s*var\(--wptl-reader-accent\)/ );
	assert.match( readerCss, /\.wptl-series-archive__pagination \.screen-reader-text\s*\{/ );
	assert.match( readerCss, /text-decoration:\s*none !important/ );
	assert.match( navigationTemplate, /esc_html_e\( 'Previous', 'wp-title-layer' \)/ );
	assert.match( navigationTemplate, /esc_html_e\( 'Next', 'wp-title-layer' \)/ );
} );

test( 'Series icons are decorative, opt-in, and outside the article heading', () => {
	const renderer = read( 'includes/Presentation/Renderer.php' );
	const presentationSettings = read( 'includes/Presentation/SettingsPage.php' );
	assert.match( coreSchema, /TERM_META_ICON_ID/ );
	assert.match( coreSchema, /TERM_META_TITLE_ICON/ );
	assert.match( coreSeries, /Series icon/ );
	assert.match( presentationSettings, /series_icons_enabled/ );
	assert.match( presentationSettings, /seriesIconEnabled/ );
	assert.match( renderer, /class="wptl-series-icon" aria-hidden="true"/ );
	assert.match( renderer, /<img class="wptl-series-icon__image"[\s\S]*?alt=""/ );
	assert.match( renderer, /esc_url_raw\( \(string\) \$image\[0\], array\( 'http', 'https' \) \)/ );
	assert.match( frontendCss, /\.wptl-series-icon\s*\{/ );
	assert.match( renderer, /private static function classicTitleHtml\(\): array[\s\S]*?private static function classicContextHtml/ );
	const headingAllowlist = renderer.match( /private static function classicTitleHtml\(\): array([\s\S]*?)private static function classicContextHtml/ );
	assert.ok( headingAllowlist );
	assert.doesNotMatch( headingAllowlist[ 1 ], /'img'/ );
	assert.doesNotMatch( rankMath, /wptl_icon_id|seriesIcon|series_icon/ );
} );

test( 'Series lifecycle status is explicit, descriptive, and visible on structured archives', () => {
	for ( const status of [ 'planning', 'ongoing', 'paused', 'completed' ] ) {
		assert.match( coreSchema, new RegExp( "STATUS_" + status.toUpperCase() + "\\s*=\\s*'" + status + "'" ) );
	}
	assert.match( coreSeries, /TERM_META_SERIES_STATUS/ );
	assert.match( coreSeries, /Series status/ );
	assert.match( coreSeries, /Descriptive only\. It does not publish, hide, lock, or reorder articles\./ );
	assert.match( coreSeries, /manage_edit-\{\$taxonomy\}_columns/ );
	assert.match( archiveTemplate, /wptl-series-archive__status/ );
	assert.match( readerCss, /\.wptl-series-archive__status\s*\{/ );
} );

test( 'the post list exposes a screen-option compatible Subtitle column', () => {
	assert.match( plugin, /PostListColumns::register\(\)/ );
	assert.match( postColumns, /manage_\{\$post_type\}_posts_columns/ );
	assert.match( postColumns, /wptl_subtitle/ );
} );

test( 'Rank Math receives opt-in variables without title or social overrides', () => {
	assert.match( plugin, /Integrations_Bootstrap::register\(\)/ );
	assert.match( rankMath, /rank_math\/vars\/register_extra_replacements/ );
	for ( const variable of [ 'wptl_subtitle', 'wptl_series', 'wptl_title_with_subtitle' ] ) {
		assert.match( rankMath, new RegExp( "'" + variable + "'" ) );
	}
	assert.equal( ( rankMath.match( /'nocache'\s*=>\s*true/g ) || [] ).length, 3 );
	assert.match( rankMath, /never changes Rank Math metadata automatically/ );
	assert.doesNotMatch( rankMath, /rank_math\/frontend\/title|rank_math\/opengraph\/[^']+\/og_title/ );
} );
