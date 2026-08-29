const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

function read( relativePath ) {
	return fs.readFileSync( path.join( __dirname, '..', relativePath ), 'utf8' );
}

const schema = read( 'includes/Core/Schema.php' );
const meta = read( 'includes/Core/Meta.php' );
const series = read( 'includes/Core/Series.php' );
const navigation = read( 'includes/Reader/Navigation.php' );
const template = read( 'templates/series-navigation.php' );
const settings = read( 'includes/Reader/AdminSettings.php' );

test( 'navigation scope is a typed per-Series setting with a compatible default', () => {
	assert.match( schema, /TERM_META_NAVIGATION_SCOPE\s*=\s*'wptl_navigation_scope'/ );
	assert.match( schema, /NAVIGATION_SCOPE_SERIES\s*=\s*'series'/ );
	assert.match( schema, /NAVIGATION_SCOPE_SEASON\s*=\s*'season'/ );
	assert.match( meta, /TERM_META_NAVIGATION_SCOPE[\s\S]*?sanitize_navigation_scope[\s\S]*?NAVIGATION_SCOPE_SERIES/ );
	assert.match( series, /Reading navigation scope/ );
	assert.match( series, /Existing Series default to Entire Series/ );
	assert.match( series, /TERM_META_NAVIGATION_SCOPE\s*=>\s*\[ Meta::class, 'sanitize_navigation_scope' \]/ );
} );

test( 'current-season navigation derives every control from one public collection', () => {
	assert.match( navigation, /public function scopedPostIds/ );
	assert.match( navigation, /\$post_ids\s*=\s*\$this->scopedPostIds\( \$term, \$season_key \)/ );
	assert.match( navigation, /\$previous\s*=\s*0 < \$index/ );
	assert.match( navigation, /\$next\s*=\s*\$index \+ 1 < \$total/ );
	assert.match( navigation, /\$first\s*=\s*\$total \? \$this->postLink\( \$post_ids\[0\] \)/ );
	assert.match( navigation, /'position'\s*=>\s*\$index \+ 1/ );
	assert.match( navigation, /'total'\s*=>\s*\$total/ );
	assert.match( navigation, /NAVIGATION_SCOPE_SEASON !== Series::navigation_scope/ );
	assert.match( navigation, /return array\(\);[\s\S]*?seasonDefinition/ );
} );

test( 'the navigation card identifies and links the active scoped season', () => {
	assert.match( template, /wptl-series-navigation__scope/ );
	assert.match( template, /\$wptl_season\['url'\]/ );
	assert.match( template, /Read this season from the beginning/ );
	assert.match( settings, /choose Entire Series or Current Season on that Series’ edit screen/ );
} );

test( 'full-Series ordering remains the canonical compatibility path', () => {
	assert.match( navigation, /public function orderedPostIds/ );
	assert.match( navigation, /Existing and flat Series always retain the full-Series collection/ );
	assert.match( series, /NAVIGATION_SCOPE_SEASON === \$scope && self::is_seasoned/ );
	assert.match( series, /:\s*Schema::NAVIGATION_SCOPE_SERIES;/ );
} );
