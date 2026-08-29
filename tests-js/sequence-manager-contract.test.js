const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const read = ( file ) => fs.readFileSync( path.join( __dirname, '..', file ), 'utf8' );
const schema = read( 'includes/Core/Schema.php' );
const meta = read( 'includes/Core/Meta.php' );
const sequence = read( 'includes/Core/Sequence.php' );
const runtime = read( 'includes/Core/SequenceRuntime.php' );
const series = read( 'includes/Core/Series.php' );
const manager = read( 'includes/Admin/SequenceManager.php' );
const managerJs = read( 'assets/sequence-manager.js' );
const managerCss = read( 'assets/sequence-manager.css' );
const editor = read( 'assets/editor.js' );
const classic = read( 'includes/Admin/ClassicEditor.php' );
const health = read( 'includes/Admin/SeriesHealthReport.php' );
const archive = read( 'includes/Reader/Archive.php' );
const navigation = read( 'includes/Reader/Navigation.php' );
const renderer = read( 'includes/Presentation/Renderer.php' );

function runSeriesChooser( renderMode = 'commit', includeComponents = true ) {
	const classes = new Set();
	let submitCount = 0;
	const wrapper = {
		classList: {
			add: ( name ) => classes.add( name ),
			remove: ( name ) => classes.delete( name ),
			contains: ( name ) => classes.has( name )
		},
		requestSubmit: () => { submitCount++; }
	};
	const host = { getAttribute: () => 'Series' };
	const select = {
		value: '11',
		options: [
			{ textContent: 'Choose a Series', value: '0' },
			{ textContent: 'Finished Series', value: '11' },
			{ textContent: 'Working Series', value: '22' }
		],
		closest: () => wrapper
	};
	const states = [];
	let hookIndex = 0;
	let pendingEffects = [];
	let rootElement = null;
	let comboboxProps = null;
	let cleanup = null;

	const element = {
		createElement: ( type, props ) => ( { type, props } ),
		useState: ( initial ) => {
			const index = hookIndex++;
			if ( typeof states[ index ] === 'undefined' ) {
				states[ index ] = initial;
			}
			return [ states[ index ], ( value ) => { states[ index ] = value; } ];
		},
		useEffect: ( effect ) => pendingEffects.push( effect ),
		createRoot: () => ( {
			render: ( nextElement ) => {
				rootElement = nextElement;
				if ( 'throw' === renderMode ) {
					throw new Error( 'render failed' );
				}
				if ( 'defer' === renderMode ) {
					return;
				}
				hookIndex = 0;
				pendingEffects = [];
				comboboxProps = nextElement.type().props;
				pendingEffects.forEach( ( effect ) => { cleanup = effect(); } );
			}
		} )
	};
	const document = {
		querySelector: ( selector ) => {
			if ( '[data-wptl-series-combobox]' === selector ) {
				return host;
			}
			if ( '[data-wptl-series-select]' === selector ) {
				return select;
			}
			return null;
		},
		querySelectorAll: () => []
	};
	const window = {
		WPTitleLayerSequenceManager: { ajaxUrl: '/wp-admin/admin-ajax.php' },
		wp: {
			element,
			components: includeComponents ? { ComboboxControl: function ComboboxControl() {} } : {}
		},
		setTimeout: () => 0,
		clearTimeout: () => {}
	};
	vm.runInNewContext( managerJs, { document, window } );

	return {
		classes,
		select,
		getSubmitCount: () => submitCount,
		getProps: () => comboboxProps,
		rerender: () => {
			if ( rootElement ) {
				hookIndex = 0;
				pendingEffects = [];
				comboboxProps = rootElement.type().props;
			}
		},
		cleanup: () => { if ( cleanup ) cleanup(); }
	};
}

test( 'managed order is a private versioned schema, not another article number field', () => {
	assert.match( schema, /META_SEQUENCE_RANK\s*=\s*'wptl_sequence_rank'/ );
	assert.match( schema, /TERM_META_SEQUENCE_SCHEMA_VERSION\s*=\s*'wptl_sequence_schema_version'/ );
	assert.match( schema, /SEQUENCE_SCHEMA_VERSION\s*=\s*1/ );
	assert.match( meta, /register_private_post_field\( Schema::META_SEQUENCE_RANK/ );
	assert.match( meta, /register_private_term_field\( \$taxonomy, Schema::TERM_META_SEQUENCE_SCHEMA_VERSION/ );
	assert.match( editor, /managedSeriesIds/ );
	assert.doesNotMatch( editor, /setMeta\( schema\.sequenceRank/ );
	assert.doesNotMatch( classic, /name=[^\n]*sequence_rank/ );
} );

test( 'initialization freezes source values and commits the activation marker last', () => {
	assert.match( sequence, /initialization_preview/ );
	assert.match( sequence, /hash_equals\( \(string\) \$preview\['fingerprint'\]/ );
	assert.match( sequence, /source_status/ );
	assert.match( sequence, /source_date/ );
	assert.match( sequence, /source_position/ );
	assert.match( sequence, /BATCH_SIZE\s*=\s*100/ );
	assert.ok(
		sequence.indexOf( 'update_term_meta( $term_id, Schema::TERM_META_SEQUENCE_SCHEMA_VERSION' )
			> sequence.indexOf( 'foreach ( $entries as $entry )' ),
		'the activation marker must be written only after every frozen entry is verified'
	);
	assert.match( sequence, /rollback_initialization/ );
	assert.match( sequence, /restore_meta/ );
	assert.match( sequence, /rollback_conflicts/ );
	assert.doesNotMatch( sequence, /delete_post_meta\( \$post_id, Schema::META_SEQUENCE_POSITION/ );
} );

test( 'moves use revisions, sparse ranks, value-checked journals, and bounded pages', () => {
	assert.match( sequence, /RANK_STEP\s*=\s*1048576/ );
	assert.match( sequence, /wptl_sequence_revision_conflict/ );
	assert.match( sequence, /rank_between_neighbors/ );
	assert.match( sequence, /rollback_changes/ );
	assert.match( sequence, /undo_last_move/ );
	assert.match( sequence, /'kind'\s*=>\s*\$manager_authorization \? 'move' : 'append'/ );
	assert.match( sequence, /min\( 100, max\( 1, \$per_page \) \)/ );
	assert.match( sequence, /LIMIT|posts_per_page/ );
	assert.match( sequence, /LEFT JOIN \([\s\S]*wptl_manager_rank/ );
	assert.match( sequence, /NOT REGEXP '\^\[1-9\]\[0-9\]\*\$'/ );
	assert.match( sequence, /add_option\( \$name, \$value, '', false \)/ );
} );

test( 'scope changes invalidate old managers before appending to the new scope', () => {
	assert.match( runtime, /set_object_terms/ );
	assert.match( runtime, /update_post_metadata/ );
	assert.match( runtime, /transition_post_status/ );
	assert.match( runtime, /before_delete_post/ );
	assert.match( runtime, /pending_revisions/ );
	assert.ok(
		runtime.indexOf( 'foreach ( self::$pending_revisions' ) < runtime.indexOf( 'foreach ( array_keys( self::$pending )' ),
		'old scopes should be invalidated before a new-scope append is committed'
	);
	assert.match( runtime, /Sequence::touch_revision/ );
	assert.match( runtime, /Sequence::ensure_post_rank/ );
} );

test( 'Season public URLs use stable never-reused numeric identities', () => {
	assert.match( schema, /TERM_META_SEASON_ID_HIGHWATER/ );
	assert.match( sequence, /with_public_ids/ );
	assert.match( sequence, /do \{\s*\+\+\$highwater;/ );
	assert.match( sequence, /resolve_season_token/ );
	assert.match( series, /public_season_archive_url/ );
	assert.match( series, /canonical_season_url/ );
	assert.match( series, /redirect_legacy_season_url/ );
	assert.match( series, /wp_safe_redirect\( \$url, 301, 'WP Title Layer' \)/ );
	assert.match( series, /wptl_rest_seasons_read_only/ );
} );

test( 'every ordered-Series consumer delegates managed order to the canonical service', () => {
	assert.match( navigation, /Sequence::ordered_post_ids/ );
	assert.match( renderer, /Sequence::automatic_ordinal/ );
	assert.match( archive, /Sequence::automatic_ordinal/ );
	assert.match( health, /Sequence::is_managed/ );
	assert.match( health, /META_SEQUENCE_RANK/ );
	assert.match( health, /legacy_position_changed/ );
	assert.match( series, /Sequence::sort_key/ );
	assert.match( series, /Schema::META_SEQUENCE_RANK/ );
} );

test( 'Sequence and Structure Manager keeps ordered-Series moves independent from advanced structure', () => {
	assert.match( manager, /Series Sequence and Structure Manager/ );
	assert.match( manager, /manager_view/ );
	assert.match( manager, /render_managed_scope/ );
	assert.match( manager, /Sequence::scope_page/ );
	assert.match( manager, /Sequence management remains available separately/ );
	assert.match( manager, /Automatic number/ );
	assert.match( manager, /Place before \/ after/ );
	assert.match( manager, /Undo last move/ );
	assert.match( manager, /Up/ );
	assert.match( manager, /Down/ );
	assert.match( manager, /First/ );
	assert.match( manager, /Last/ );
	assert.match( managerJs, /dragstart/ );
	assert.match( managerJs, /wptl_sequence_search/ );
	assert.match( managerJs, /sequence_revision/ );
	assert.match( managerJs, /aria|role/ );
	assert.match( managerCss, /@media \(max-width: 782px\)/ );
	assert.match( managerCss, /\.wptl-sequence-highlight/ );
} );

test( 'the Series chooser enhances to a searchable WordPress combobox with a native fallback', () => {
	assert.match( manager, /data-wptl-series-combobox/ );
	assert.match( manager, /data-wptl-series-select/ );
	assert.match( manager, /data-wptl-series-open/ );
	assert.match( manager, /wp-components/ );
	assert.match( managerJs, /ComboboxControl/ );
	assert.match( managerJs, /onFilterValueChange/ );
	assert.match( managerJs, /Hide the native control only after React has committed successfully/ );
	assert.match( managerJs, /Keep the native select visible as the fail-safe control/ );
	assert.match( managerCss, /is-series-combobox-enhanced/ );
	assert.match( managerCss, /\.wptl-series-selector[\s\S]*display:\s*grid/ );

	const unavailable = runSeriesChooser( 'commit', false );
	assert.equal( unavailable.classes.has( 'is-series-combobox-enhanced' ), false, 'the native select should remain visible without WordPress components' );
	const deferred = runSeriesChooser( 'defer' );
	assert.equal( deferred.classes.has( 'is-series-combobox-enhanced' ), false, 'the native select should remain visible before React commits' );
	const failed = runSeriesChooser( 'throw' );
	assert.equal( failed.classes.has( 'is-series-combobox-enhanced' ), false, 'the native select should remain visible after a render failure' );

	const mounted = runSeriesChooser();
	assert.equal( mounted.classes.has( 'is-series-combobox-enhanced' ), true, 'a committed combobox should replace the native select' );
	mounted.getProps().onChange( '11' );
	assert.equal( mounted.getSubmitCount(), 0, 'reselecting the current Series should not reload the page' );
	mounted.getProps().onChange( null );
	assert.equal( mounted.getSubmitCount(), 0, 'clearing the chooser should not submit an empty Series' );
	mounted.rerender();
	mounted.getProps().onChange( '11' );
	assert.equal( mounted.getSubmitCount(), 0, 'choosing the Series already open on the page should not reload it after filtering' );
	mounted.getProps().onChange( '22' );
	assert.equal( mounted.select.value, '22', 'the searchable chooser should synchronize its selection to the submitted native control' );
	assert.equal( mounted.getSubmitCount(), 1, 'choosing a different Series should open it without a second click' );
	mounted.getProps().onFilterValueChange( 'finished' );
	mounted.rerender();
	assert.deepEqual( Array.from( mounted.getProps().options, ( option ) => option.value ), [ '11' ], 'the chooser should filter a long Series list by label' );
	mounted.cleanup();
	assert.equal( mounted.classes.has( 'is-series-combobox-enhanced' ), false, 'unmounting should restore the native select' );
} );
