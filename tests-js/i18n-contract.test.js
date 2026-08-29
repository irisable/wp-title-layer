const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const root = path.resolve( __dirname, '..' );

function poString( line ) {
	const firstQuote = line.indexOf( '"' );
	return firstQuote < 0 ? '' : JSON.parse( line.slice( firstQuote ) );
}

function parsePo( source ) {
	const entries = new Map();
	let entry = null;
	let field = '';

	function finish() {
		if ( entry && entry.msgid ) {
			const key = ( entry.msgctxt ? entry.msgctxt + '\u0004' : '' ) + entry.msgid;
			entries.set( key, entry );
		}
		entry = null;
		field = '';
	}

	source.split( /\r?\n/ ).forEach( ( line ) => {
		const fieldMatch = line.match( /^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?) / );
		if ( fieldMatch ) {
			if ( line.startsWith( 'msgid ' ) && entry && entry.msgid !== undefined ) {
				finish();
			}
			entry = entry || { msgctxt: '', msgid: undefined, msgstr: '' };
			field = fieldMatch[ 1 ];
			entry[ field ] = poString( line );
			return;
		}
		if ( line.startsWith( '"' ) && entry && field ) {
			entry[ field ] += poString( line );
			return;
		}
		if ( line.trim() === '' ) {
			finish();
		}
	} );
	finish();

	return entries;
}

function placeholders( value ) {
	return ( value.match( /%(?:\d+\$)?[sd]/g ) || [] ).sort();
}

test( 'the complete Simplified Chinese catalog ships with a reusable POT template', () => {
	const potSource = fs.readFileSync( path.join( root, 'languages/wp-title-layer.pot' ), 'utf8' );
	const poSource = fs.readFileSync( path.join( root, 'languages/wp-title-layer-zh_CN.po' ), 'utf8' );
	const pot = parsePo( potSource );
	const po = parsePo( poSource );

	assert.ok( pot.size >= 647, `Expected a complete catalog, found ${ pot.size } messages.` );
	assert.deepEqual( [ ...po.keys() ].sort(), [ ...pot.keys() ].sort() );
	assert.doesNotMatch( poSource, /^#, fuzzy$/m );
	assert.match( poSource, /"Language: zh_CN\\n"/ );

	for ( const [ key, template ] of pot ) {
		const translation = po.get( key );
		assert.equal(
			translation ? translation.msgid_plural || '' : '',
			template.msgid_plural || '',
			`Changed plural source for: ${ template.msgid }`
		);
		const translatedForms = translation
			? Object.keys( translation )
				.filter( ( field ) => field === 'msgstr' || /^msgstr\[\d+\]$/.test( field ) )
				.map( ( field ) => translation[ field ] )
				.filter( Boolean )
			: [];
		assert.ok( translatedForms.length > 0, `Missing zh_CN translation for: ${ template.msgid }` );
		for ( const translatedForm of translatedForms ) {
			assert.deepEqual(
				placeholders( translatedForm ),
				placeholders( template.msgid ),
				`Changed printf placeholders for: ${ template.msgid }`
			);
		}
	}

	const mo = fs.readFileSync( path.join( root, 'languages/wp-title-layer-zh_CN.mo' ) );
	assert.ok( mo.length > 4096 );
	assert.equal( mo.readUInt32LE( 0 ), 0x950412de );
} );

test( 'the block editor receives the matching zh_CN Jed catalog', () => {
	const po = parsePo( fs.readFileSync( path.join( root, 'languages/wp-title-layer-zh_CN.po' ), 'utf8' ) );
	const editor = fs.readFileSync( path.join( root, 'assets/editor.js' ), 'utf8' );
	const json = JSON.parse( fs.readFileSync( path.join( root, 'languages/wp-title-layer-zh_CN-wptl-editor.json' ), 'utf8' ) );
	const messages = json.locale_data.messages;
	const jsMessages = new Set( [ ...editor.matchAll( /__\(\s*'([^']+)'\s*,\s*'wp-title-layer'\s*\)/g ) ].map( ( match ) => match[ 1 ] ) );

	assert.equal( messages[ '' ].lang, 'zh_CN' );
	assert.ok( jsMessages.size >= 30 );
	for ( const msgid of jsMessages ) {
		assert.equal( messages[ msgid ][ 0 ], po.get( msgid ).msgstr, `Editor translation drifted for: ${ msgid }` );
	}

	const assets = fs.readFileSync( path.join( root, 'includes/Presentation/EditorAssets.php' ), 'utf8' );
	assert.match( assets, /wp_set_script_translations\(\s*self::SCRIPT_HANDLE,\s*'wp-title-layer'/s );
} );

test( 'locale punctuation is resolved at render time instead of stored as Chinese glyphs', () => {
	const plugin = fs.readFileSync( path.join( root, 'wp-title-layer.php' ), 'utf8' );
	const presets = fs.readFileSync( path.join( root, 'includes/Presentation/Presets.php' ), 'utf8' );
	const renderer = fs.readFileSync( path.join( root, 'includes/Presentation/Renderer.php' ), 'utf8' );
	const rankMath = fs.readFileSync( path.join( root, 'includes/Integrations/RankMath.php' ), 'utf8' );

	assert.match( plugin, /Text Domain:\s+wp-title-layer/ );
	assert.match( plugin, /Domain Path:\s+\/languages/ );
	assert.match( presets, /_x\( ': ', 'inline title and subtitle separator: colon', 'wp-title-layer' \)/ );
	assert.match( presets, /_x\( ' — ', 'inline title and subtitle separator: dash', 'wp-title-layer' \)/ );
	assert.match( presets, /_x\( ' \| ', 'inline title and subtitle separator: vertical bar', 'wp-title-layer' \)/ );
	assert.match( rankMath, /_x\( ': ', 'SEO title and subtitle separator', 'wp-title-layer' \)/ );
	assert.doesNotMatch( renderer, />：<|>——<|>｜</ );
} );
