const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

function read( relativePath ) {
	return fs.readFileSync( path.join( __dirname, '..', relativePath ), 'utf8' );
}

const report = read( 'includes/Admin/ChannelHealthReport.php' );
const page = read( 'includes/Admin/ChannelHealth.php' );
const plugin = read( 'includes/Plugin.php' );
const control = read( 'includes/Admin/ControlCenter.php' );

test( 'landing and channel governance is normal-mode only and centrally discoverable', () => {
	assert.match( plugin, /ChannelHealth::register\(\);/ );
	assert.ok( plugin.indexOf( 'if ( $legacy_active )' ) < plugin.indexOf( 'ChannelHealth::register();' ) );
	assert.match( control, /channel_health/ );
	assert.match( control, /Landing pages and channels/ );
	assert.match( control, /Check landing pages and title channels/ );
} );

test( 'the report is capability-gated and read-only', () => {
	assert.match( page, /wptl_channel_health_capability/ );
	assert.match( page, /current_user_can\( self::capability\(\) \)/ );
	assert.doesNotMatch( page, /<form|wp_nonce_field|admin_post_|wp_ajax_/ );
	assert.doesNotMatch( report, /update_(?:option|post_meta|term_meta)|delete_(?:option|post_meta|term_meta)|wp_set_object_terms/ );
	assert.match( page, /Nothing on this page changes SEO metadata, taxonomy relationships, robots, canonical URLs, or sitemap settings/ );
} );

test( 'provider detection ignores stale options and distinguishes ownership states', () => {
	for ( const state of [ 'none', 'rank_math', 'other_provider', 'multiple_providers' ] ) {
		assert.match( report, new RegExp( "'" + state + "'" ) );
	}
	assert.match( report, /RANK_MATH_VERSION/ );
	assert.match( report, /wptl_channel_health_providers/ );
	assert.doesNotMatch( report, /get_option\( self::RANK_MATH_TITLES_OPTION[\s\S]{0,100}provider_state/ );
} );

test( 'Rank Math taxonomy, canonical, robots, sitemap and title policies are inspected', () => {
	for ( const key of [
		'tax_',
		'rank_math_title',
		'rank_math_description',
		'rank_math_robots',
		'rank_math_canonical_url',
		'rank_math_facebook_title',
		'rank_math_twitter_title',
		'exclude_terms',
		'wptl_title_with_subtitle',
	] ) {
		assert.match( report, new RegExp( key ) );
	}
	assert.match( report, /provider_self/ );
	assert.match( report, /http', 'https/ );
} );

test( 'duplicate entry points use exact public sets and bounded pagination', () => {
	assert.match( report, /COUNT\(DISTINCT p\.ID\)/ );
	assert.match( report, /p\.post_status = 'publish'/ );
	assert.match( report, /p\.post_password = ''/ );
	assert.match( report, /'exact'/ );
	assert.match( report, /'partial'/ );
	assert.match( report, /min\( 100, max\( 1, \$per_page \) \)/ );
	assert.match( report, /LIMIT %d OFFSET %d/ );
} );
