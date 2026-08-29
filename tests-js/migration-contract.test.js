const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const source = fs.readFileSync( path.join( __dirname, '..', 'assets', 'migration.js' ), 'utf8' );

test( 'automatic migration submits one nonce-protected batch at a time', () => {
	assert.match( source, /wptl_migration_process_batch/ );
	assert.match( source, /body\.set\( 'nonce'/ );
	assert.match( source, /window\.setTimeout\( next/ );
	assert.doesNotMatch( source, /Promise\.all/ );
} );

test( 'browser navigation may stop safely without storing migration ownership', () => {
	assert.doesNotMatch( source, /localStorage|sessionStorage/ );
	assert.match( source, /completed batches are already saved|config\.text\.running/ );
} );

test( 'conflict export becomes available only for a stable terminal run', () => {
	assert.match( source, /state\.counts\.conflict > 0/ );
	assert.match( source, /state\.status === 'complete'/ );
	assert.match( source, /state\.status === 'rolled_back'/ );
	assert.match( source, /exportForm\.hidden = !/ );
} );
