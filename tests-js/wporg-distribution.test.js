const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const read = ( file ) => fs.readFileSync( path.join( __dirname, '..', file ), 'utf8' );

test( 'WordPress.org distribution uses native update eligibility and the owner account', () => {
	assert.doesNotMatch( read( 'wp-title-layer.php' ), /\*\s*Update URI:/ );
	assert.match( read( 'readme.txt' ), /^Contributors: irisable$/m );
	assert.match( read( 'readme.txt' ), /== Privacy and external services ==/ );
	assert.match( read( 'readme.txt' ), /https:\/\/github\.com\/irisable\/wp-title-layer/ );
} );

test( 'Archive output escapes its selected heading and avoids the obsolete taxonomy argument', () => {
	assert.doesNotMatch( read( 'templates/series-archive.php' ), /echo \$wptl_heading;/ );
	assert.match( read( 'templates/series-archive.php' ), /echo esc_html\( \$wptl_heading \);/ );
	assert.match( read( 'includes/Reader/Archive.php' ), /term_description\( \(int\) \$term->term_id \)/ );
} );
