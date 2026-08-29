#!/usr/bin/env node

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const root = path.resolve( __dirname, '..' );
const poPath = path.join( root, 'languages', 'wp-title-layer-zh_CN.po' );
const editorPath = path.join( root, 'assets', 'editor.js' );
const outputPath = path.join( root, 'languages', 'wp-title-layer-zh_CN-wptl-editor.json' );

function poString( line ) {
	const quote = line.indexOf( '"' );
	return quote < 0 ? '' : JSON.parse( line.slice( quote ) );
}

function parsePo( source ) {
	const entries = new Map();
	let entry = null;
	let field = '';

	function finish() {
		if ( entry && entry.msgid !== undefined ) {
			entries.set( entry.msgid, entry.msgstr || '' );
		}
		entry = null;
		field = '';
	}

	source.split( /\r?\n/ ).forEach( ( line ) => {
		const match = line.match( /^(msgid|msgstr) / );
		if ( match ) {
			if ( match[ 1 ] === 'msgid' && entry && entry.msgid !== undefined ) {
				finish();
			}
			entry = entry || { msgid: undefined, msgstr: '' };
			field = match[ 1 ];
			entry[ field ] = poString( line );
		} else if ( line.startsWith( '"' ) && entry && field ) {
			entry[ field ] += poString( line );
		} else if ( line.trim() === '' ) {
			finish();
		}
	} );
	finish();
	return entries;
}

const po = parsePo( fs.readFileSync( poPath, 'utf8' ) );
const editor = fs.readFileSync( editorPath, 'utf8' );
const revisionMatch = ( po.get( '' ) || '' ).match( /PO-Revision-Date: ([^\n]+)/ );
const revisionDate = revisionMatch ? revisionMatch[ 1 ] : '';
const messages = {
	'': {
		domain: 'messages',
		lang: 'zh_CN',
		'plural-forms': 'nplurals=1; plural=0;',
	},
};
const missing = [];
for ( const match of editor.matchAll( /__\(\s*'([^']+)'\s*,\s*'wp-title-layer'\s*\)/g ) ) {
	const msgid = match[ 1 ];
	if ( Object.prototype.hasOwnProperty.call( messages, msgid ) ) {
		continue;
	}
	const translation = po.get( msgid ) || '';
	if ( ! translation ) {
		missing.push( msgid );
	}
	messages[ msgid ] = [ translation ];
}

if ( missing.length ) {
	throw new Error( `Missing zh_CN editor translations:\n- ${ missing.join( '\n- ' ) }` );
}

const payload = {
	'translation-revision-date': revisionDate,
	generator: 'WP Title Layer translation build',
	source: 'assets/editor.js',
	domain: 'messages',
	locale_data: { messages },
};
fs.writeFileSync( outputPath, `${ JSON.stringify( payload ) }\n`, 'utf8' );
