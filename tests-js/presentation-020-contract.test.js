const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

function read( relativePath ) {
	return fs.readFileSync( path.join( __dirname, '..', relativePath ), 'utf8' );
}

const autoDisplay = read( 'includes/Presentation/AutoDisplay.php' );
const bootstrap = read( 'includes/Presentation/Bootstrap.php' );
const dynamicBlock = read( 'includes/Presentation/DynamicBlock.php' );
const editorAssets = read( 'includes/Presentation/EditorAssets.php' );
const editor = read( 'assets/editor.js' );
const adminCss = read( 'assets/admin.css' );
const frontendCss = read( 'assets/frontend.css' );
const presets = read( 'includes/Presentation/Presets.php' );
const renderer = read( 'includes/Presentation/Renderer.php' );
const settings = read( 'includes/Presentation/SettingsPage.php' );
const settingsTabs = read( 'assets/settings.js' );
const themeTitle = read( 'includes/Presentation/ThemeTitleIntegration.php' );

test( 'automatic placement is opt-in and requires the theme-title confirmation', () => {
	assert.match( autoDisplay, /MODE_MANUAL\s+=\s+'manual'/ );
	assert.match( autoDisplay, /MODE_AUTO_PREPEND\s+=\s+'auto-prepend'/ );
	assert.match( autoDisplay, /auto_theme_title_disabled/ );
	assert.match( settings, /display_mode'.*?AutoDisplay::MODE_MANUAL/s );
	assert.match( settings, /MODE_AUTO_PREPEND === \$display_mode && ! \$confirmed/s );
	assert.match( settings, /\$display_mode = AutoDisplay::MODE_MANUAL/ );
	assert.match( bootstrap, /new AutoDisplay\( \$renderer \)/ );
} );

test( 'theme-title takeover is a separate verified-adapter mode', () => {
	assert.match( themeTitle, /MODE_REPLACE\s+=\s+'replace-theme-title'/ );
	assert.match( themeTitle, /render_block_core\/post-title/ );
	assert.match( themeTitle, /kadence_single_before_entry_title/ );
	assert.match( themeTitle, /kadence_single_after_entry_title/ );
	assert.match( themeTitle, /render_block_data/ );
	assert.match( themeTitle, /QUERY_LOOP_MARKER/ );
	assert.match( themeTitle, /'core\/null' === \$parent_block->name/ );
	assert.match( themeTitle, /'' !== \$query_id && '0' !== \$query_id/ );
	assert.match( themeTitle, /! empty\( \$attrs\['isLink'\] \)/ );
	assert.match( themeTitle, /doing_filter\( 'the_content' \)/ );
	assert.match( themeTitle, /isValidBlockReplacement/ );
	assert.match( themeTitle, /wptl-title-heading/ );
	assert.match( themeTitle, /'' !== \(string\) \$post->post_password/ );
	assert.match( settings, /ThemeTitleIntegration::MODE_REPLACE/ );
} );

test( 'classic takeover cannot leak a complete title layer through the global title filter', () => {
	const method = themeTitle.slice(
		themeTitle.indexOf( 'public function filterKadenceTitle' ),
		themeTitle.indexOf( 'public function finishKadenceTitle' )
	);
	assert.doesNotMatch( method, /renderer->render/ );
	assert.match( method, /kadence_post_id/ );
	assert.match( renderer, /public function themeTitleFragments/ );
	assert.match( renderer, /<span class="wptl-title">/ );
} );

test( 'automatic placement is restricted to a singular main-loop body', () => {
	for ( const guard of [
		/is_feed\(\)/,
		/REST_REQUEST/,
		/wp_is_json_request/,
		/is_embed/,
		/doing_filter\( 'get_the_excerpt' \)/,
		/doing_filter\( 'the_excerpt' \)/,
		/! is_singular\(\)/,
		/! in_the_loop\(\)/,
		/! is_main_query\(\)/
	] ) {
		assert.match( autoDisplay, guard );
	}
	assert.match( autoDisplay, /in_array\( \$post_type, \$settings\['auto_post_types'\], true \)/ );
} );

test( 'automatic placement yields to manual placement and request-local duplicates', () => {
	assert.match( autoDisplay, /has_block\( DynamicBlock::NAME, \$content \)/ );
	assert.match( autoDisplay, /'wp_title_layer'/ );
	assert.match( autoDisplay, /'wptl_subtitle'/ );
	assert.match( autoDisplay, /'secondary_title'/ );
	assert.match( autoDisplay, /wptl-auto-prepend/ );
	assert.match( autoDisplay, /\$this->injected_posts/ );
	assert.match( autoDisplay, /markManualPlacement/ );
	assert.match( autoDisplay, /the_content'.*?20/s );
	assert.match( autoDisplay, /public static function containsManualPlacement/ );
} );

test( 'automatic settings sanitize post types and never create an h1', () => {
	assert.match( settings, /availableAutoPostTypes\(\)/ );
	assert.match( settings, /array_intersect\(/ );
	assert.match( settings, /public static function sanitizeAutoHeadingTag/ );
	assert.match( settings, /array\( 'h2', 'div' \)/ );
	assert.match( settings, /\? \$heading_tag : 'div'/ );
	assert.match( settings, /array_merge\(\s*\$old_presentation/s );
} );

test( 'a post disabled override remains authoritative for every renderer caller', () => {
	const disabledCheck = renderer.indexOf( "if ( ! empty( $resolved['disabled'] ) )" );
	const requestedPreset = renderer.indexOf( "$preset_id = sanitize_key" );
	assert.ok( disabledCheck >= 0 );
	assert.ok( requestedPreset > disabledCheck );
	assert.match( autoDisplay, /'' === trim\( \$title_layer \)/ );
} );

test( 'dynamic block uses a WordPress 6.5-compatible server preview with fallbacks', () => {
	assert.match( editorAssets, /'wp-server-side-render'/ );
	assert.match( editor, /wp\.serverSideRender/ );
	assert.match( editor, /block: 'wp-title-layer\/title-layer'/ );
	assert.match( editor, /urlQueryArgs: \{ post_id: currentPostId \}/ );
	assert.match( editor, /EmptyResponsePlaceholder/ );
	assert.match( editor, /ErrorResponsePlaceholder/ );
	assert.match( editor, /live preview is temporarily unavailable/ );
	assert.match( dynamicBlock, /'uses_context'\s+=>\s+array\( 'postId', 'postType' \)/ );
} );

test( 'four built-in templates expose only bounded settings-page customization', () => {
	assert.match( presets, /CUSTOMIZABLE_IDS\s*=\s*array\( 'standard', 'editorial', 'inline', 'minimal' \)/ );
	for ( const key of [
		'series_position',
		'subtitle_position',
		'separator_style',
		'custom_separator'
	] ) {
		assert.match( presets, new RegExp( "'" + key + "'" ) );
	}
	assert.match( presets, /public static function normalizeRecipe/ );
	assert.match( presets, /return \$defaults;/ );
	assert.match( presets, /CUSTOM_SEPARATOR_LENGTH\s*=\s*8/ );
	assert.match( settings, /renderPresetCustomizationsField/ );
	assert.match( settings, /Restore this template to its original defaults/ );
	assert.match( settings, /They never accept HTML, CSS, or PHP/ );
} );

test( 'the shared settings screen groups every registered section without changing its save path', () => {
	assert.match( settings, /settings_fields\( 'wptl_settings_group' \)/ );
	assert.match( settings, /private static function settingsSections/ );
	assert.match( settings, /private static function renderSettingsSections/ );
	assert.match( settings, /foreach \( \$sections as \$index => \$section \)/ );
	assert.match( settings, /do_settings_fields\( self::PAGE_SLUG, \$section_id \)/ );
	assert.doesNotMatch( settings, /do_settings_sections\( self::PAGE_SLUG \)/ );
	assert.match( settings, /wptl-settings__nav/ );
	assert.match( settings, /wptl-settings-group/ );
	assert.match( adminCss, /\.wptl-settings__nav ol\s*\{[\s\S]*?grid-template-columns:/ );
	assert.match( adminCss, /\.wptl-settings-group\s*\{[\s\S]*?background:\s*var\(--wptl-settings-surface\)/ );
	assert.match( adminCss, /@media \(max-width: 782px\)[\s\S]*?\.wptl-settings__nav ol/ );
} );

test( 'settings groups progressively enhance into accessible tabs without changing the one-form save path', () => {
	assert.match( settings, /data-wptl-settings-tabs/ );
	assert.match( settings, /data-wptl-settings-tablist/ );
	assert.match( settings, /data-wptl-settings-panel/ );
	assert.match( settings, /wp_enqueue_script\( 'wptl-settings-tabs'/ );
	assert.match( settingsTabs, /setAttribute\( 'role', 'tablist' \)/ );
	assert.match( settingsTabs, /setAttribute\( 'role', 'tabpanel' \)/ );
	assert.match( settingsTabs, /aria-selected/ );
	assert.match( settingsTabs, /ArrowRight/ );
	assert.match( settingsTabs, /ArrowLeft/ );
	assert.match( settingsTabs, /Home/ );
	assert.match( settingsTabs, /End/ );
	assert.match( settingsTabs, /hashchange/ );
	assert.match( settingsTabs, /panel\.hidden/ );
} );

test( 'inline subtitle stays outside the single primary heading', () => {
	assert.match( presets, /<div class="wptl-title-row"><h1 class="wptl-title-heading">\{\{title_content\}\}<\/h1>\{\{subtitle_inline\}\}<\/div>/ );
	assert.match( renderer, /'\{\{title_content\}\}'\s*=>\s*\$title_content/ );
	assert.match( renderer, /'\{\{subtitle_inline\}\}'\s*=>\s*\$subtitle_inline/ );
	assert.match( renderer, /wptl-theme-title-inline/ );
	assert.match( frontendCss, /wptl-subtitle-layout--inline \.wptl-title-row/ );
} );

test( 'full and classic renderers consume the same normalized recipe', () => {
	assert.ok( ( renderer.match( /Presets::recipe\( \$preset_id \)/g ) || [] ).length >= 2 );
	assert.match( renderer, /templateForRecipe/ );
	assert.doesNotMatch( renderer, /sanitize_hex_color|optionClass|custom CSS/i );
} );

test( 'stacked and inline subtitles keep a readable responsive floor', () => {
	assert.match( frontendCss, /\.wptl-subtitle\s*\{[\s\S]*?font-size:\s*clamp\(1\.25rem,\s*1\.05rem \+ 0\.9vw,\s*1\.7rem\)/ );
	assert.match( frontendCss, /\.wptl-theme-title-inline \.wptl-subtitle[\s\S]*?font-size:\s*clamp\(1\.2rem,\s*1\.02rem \+ 0\.65vw,\s*1\.55rem\)/ );
	assert.match( frontendCss, /\.wptl-theme-title-inline \.wptl-title-separator/ );
	assert.doesNotMatch( frontendCss, /\.wptl-title-layer--inline \.wptl-subtitle\s*\{[\s\S]*?display:\s*inline/ );
} );
