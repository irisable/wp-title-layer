const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const read = ( file ) => fs.readFileSync( path.join( __dirname, '..', file ), 'utf8' );
const page = read( 'includes/Admin/SeriesHealth.php' );
const report = read( 'includes/Admin/SeriesHealthReport.php' );
const plugin = read( 'includes/Plugin.php' );
const control = read( 'includes/Admin/ControlCenter.php' );
const css = read( 'assets/admin.css' );

test( 'Series health is a normal-mode, capability-gated read-only page', () => {
	assert.match( page, /add_submenu_page\(/ );
	assert.match( page, /'manage_options'/ );
	assert.match( page, /current_user_can\( self::capability\(\) \)/ );
	assert.match( page, /Read-only report/ );
	assert.doesNotMatch( `${ page }\n${ report }`, /\b(?:add|update|delete)_post_meta\s*\(/ );
	assert.doesNotMatch( `${ page }\n${ report }`, /\b(?:add|update|delete)_term_meta\s*\(/ );
	assert.doesNotMatch( `${ page }\n${ report }`, /\bwp_(?:insert|update|delete)_post\s*\(/ );
	assert.doesNotMatch( `${ page }\n${ report }`, /\bwp_set_object_terms\s*\(/ );
	assert.doesNotMatch( page, /<form[^>]*method="post"/i );
	assert.match( page, /method="get"/i );
	assert.match( plugin, /SeriesHealth::register\(\);/ );
	assert.ok( plugin.indexOf( 'SeriesHealth::register();' ) > plugin.indexOf( 'if ( $legacy_active )' ), 'Series health must remain unavailable during migration-only mode' );
} );

test( 'Series health aggregates whole-Series facts and pages only bounded rows', () => {
	assert.match( report, /MIN\(meta_id\)/ );
	assert.match( report, /LIMIT %d OFFSET %d/ );
	assert.match( report, /min\( 100, max\( 1, \$per_page \) \)/ );
	assert.match( report, /post_status = 'future'/ );
	assert.match( report, /post_status = 'private'/ );
	assert.match( report, /post_password <> ''/ );
	assert.match( report, /EXISTS \(/ );
	assert.match( report, /Schema::MODE_ORDERED === \$mode/ );
	assert.match( report, /Schema::STRUCTURE_SEASONED === \$structure/ );
	assert.match( report, /function terms_page\( int \$page = 1, int \$per_page = 20, string \$search = '' \)/ );
	assert.match( report, /\$count_args\['search'\] = \$search/ );
	assert.match( report, /\$term_args\['search'\] = \$search/ );
	assert.match( report, /0 === \$total_pages[\s\S]*\$page = 1/ );
	assert.match( page, /name="series_search"/ );
	assert.match( page, /'series_search' => \$search/ );
} );

test( 'Series health is discoverable and remains usable on narrow screens', () => {
	assert.match( control, /series_health/ );
	assert.match( control, /Check Series health/ );
	assert.match( css, /\.wptl-series-health/ );
	assert.match( css, /\.wptl-health-summary-grid/ );
	assert.match( css, /@media \(max-width: 782px\)[\s\S]*\.wptl-health-table[\s\S]*overflow-x:\s*auto/ );
} );
