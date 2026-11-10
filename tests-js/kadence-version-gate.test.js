const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { spawnSync } = require( 'node:child_process' );
const { mkdtempSync, mkdirSync, writeFileSync, rmSync } = require( 'node:fs' );
const { tmpdir } = require( 'node:os' );
const path = require( 'node:path' );

test( 'Kadence versions and template guards fail closed', { skip: spawnSync( 'php', [ '--version' ] ).status !== 0 }, () => {
	const root = mkdtempSync( path.join( tmpdir(), 'wptl-compat-' ) );
	const files = [ 'template-parts/content/entry_title.php', 'template-parts/title/title.php' ];
	const source = `do_action( 'kadence_single_before_entry_title' );\nthe_title( '<h1 class="entry-title">', '</h1>' );\ndo_action( 'kadence_single_after_entry_title' );`;
	const probe = ( version, child ) => {
		const result = spawnSync( 'php', [ path.join( __dirname, '../tests/kadence-support-probe.php' ), version, root, ...( child ? [ child ] : [] ) ], { encoding: 'utf8' } );
		assert.equal( result.status, 0, result.stderr );
		return JSON.parse( result.stdout ).supported;
	};
	try {
		for ( const file of files ) {
			mkdirSync( path.dirname( path.join( root, file ) ), { recursive: true } );
			writeFileSync( path.join( root, file ), source );
		}
		for ( const version of [ '1.5.0', '1.5.2', '1.6.0' ] ) assert.equal( probe( version ), true, version );
		for ( const version of [ '', '1.4.9', '1.6.0-beta.1', '1.6.1', '1.7.0', '2.0.0' ] ) assert.equal( probe( version ), false, version );
		assert.equal( probe( '1.6.0', root ), false, 'Child title overrides must remain unsupported' );
		for ( const file of files ) {
			writeFileSync( path.join( root, file ), source.replace( 'kadence_single_after_entry_title', 'changed_hook' ) );
			assert.equal( probe( '1.6.0' ), false, 'Changed title hooks must fail closed' );
			writeFileSync( path.join( root, file ), source );
		}
	} finally {
		rmSync( root, { recursive: true, force: true } );
	}
} );
