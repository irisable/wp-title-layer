const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const php = fs.readFileSync( path.join( __dirname, '..', 'includes', 'Admin', 'ControlCenter.php' ), 'utf8' );
const css = fs.readFileSync( path.join( __dirname, '..', 'assets', 'admin.css' ), 'utf8' );
const plugin = fs.readFileSync( path.join( __dirname, '..', 'includes', 'Plugin.php' ), 'utf8' );

test( 'control center is an independently registered manage-options page', () => {
	assert.match( php, /public static function register\( bool \$legacy_active = false \): void/ );
	assert.match( php, /add_menu_page\(/ );
	assert.match( php, /'manage_options'/ );
	assert.match( php, /wp-title-layer-home/ );
	assert.match( php, /data:image\/svg\+xml;base64,/ );
	assert.match( php, /<path fill="#a7aaad"/ );
	assert.doesNotMatch( php, /dashicons-editor-textcolor/ );
	assert.match( plugin, /ControlCenter::register\( \$legacy_active \);/ );
	assert.ok( plugin.indexOf( 'ControlCenter::register( $legacy_active );' ) < plugin.indexOf( 'if ( $legacy_active )' ), 'control center must register before migration-only mode returns' );
} );

test( 'control center is read-only and links to existing workflows', () => {
	assert.doesNotMatch( php, /\b(?:add|update|delete)_option\s*\(/ );
	assert.doesNotMatch( php, /\b(?:add|update|delete)_post_meta\s*\(/ );
	assert.doesNotMatch( php, /\bwp_set_object_terms\s*\(/ );
	assert.doesNotMatch( php, /<form\b/i );
	assert.match( php, /options-general\.php/ );
	assert.match( php, /tools\.php/ );
	assert.match( php, /edit-tags\.php/ );
	assert.match( php, /Schema.*taxonomy/s );
} );

test( 'all four product states and the first-run guide are present', () => {
	for ( const label of [ 'Content migration', 'Title display', 'Series', 'Reading experience', 'Start here' ] ) {
		assert.match( php, new RegExp( label ) );
	}
	assert.match( php, /self::\$legacy_active/ );
	assert.match( php, /class_exists\(/ );
	assert.match( php, /aria-disabled/ );
} );

test( 'admin layout collapses cleanly for narrow screens', () => {
	assert.match( css, /grid-template-columns:\s*repeat\(2/ );
	assert.match( css, /@media \(max-width: 782px\)/ );
	assert.match( css, /grid-template-columns:\s*minmax\(0, 1fr\)/ );
	assert.match( css, /focus-visible/ );
} );
