const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( path.join( __dirname, '..', 'assets', 'editor.js' ), 'utf8' );

function bootPanelGuard( options = {} ) {
	let currentPostType = '';
	let removed = false;
	let removeCalls = 0;
	let editPostCalls = 0;
	let subscriber = null;
	let removedPanel = '';
	let removingStore = '';
	const events = [];

	const coreEditorSelect = {
		getCurrentPostType: () => currentPostType,
	};
	const coreEditorDispatch = {
		editPost: () => { editPostCalls += 1; },
	};
	const editPostSelect = {
		isEditorPanelRemoved: () => removed,
	};
	const editPostDispatch = {
		removeEditorPanel: ( panelName ) => {
			removedPanel = panelName;
			removed = true;
			removeCalls += 1;
			removingStore = 'core/edit-post';
		},
	};
	if ( options.canonical !== false ) {
		coreEditorSelect.isEditorPanelRemoved = () => removed;
		coreEditorDispatch.removeEditorPanel = ( panelName ) => {
			removedPanel = panelName;
			removed = true;
			removeCalls += 1;
			removingStore = 'core/editor';
		};
	}

	const wp = {
		blockEditor: { useBlockProps: () => ( {} ), InspectorControls: function () {} },
		blocks: { registerBlockType: () => {} },
		components: options.combobox === false ? {} : { ComboboxControl: function () {} },
		data: {
			dispatch: ( store ) => store === 'core/editor' ? coreEditorDispatch : ( store === 'core/edit-post' ? editPostDispatch : {} ),
			select: ( store ) => store === 'core/editor' ? coreEditorSelect : ( store === 'core/edit-post' ? editPostSelect : {} ),
			subscribe: ( callback ) => {
				events.push( 'subscribe' );
				subscriber = callback;
			},
			useDispatch: () => coreEditorDispatch,
			useSelect: () => ( {} ),
		},
		editPost: { PluginDocumentSettingPanel: function () {} },
		element: {
			createElement: () => ( {} ),
			Fragment: function () {},
			useState: ( initial ) => [ initial, () => {} ],
		},
		i18n: { __: ( value ) => value },
		plugins: {
			registerPlugin: () => { events.push( 'register' ); },
		},
		serverSideRender: null,
	};
	vm.runInNewContext( source, {
		window: {
			wp,
			WPTitleLayerEditor: {
				nativeSeriesPanel: options.panelName === undefined ? 'taxonomy-panel-wptl_series' : options.panelName,
				schema: { taxonomy: 'wptl_series' },
				seriesAttribute: 'wptl_series',
				seriesPostTypes: [ 'post' ],
				supportedPostTypes: [ 'post' ],
			},
		},
	} );

	return {
		events,
		get editPostCalls() { return editPostCalls; },
		get removeCalls() { return removeCalls; },
		get removedPanel() { return removedPanel; },
		get removingStore() { return removingStore; },
		get subscriber() { return subscriber; },
		resetRemoved: () => { removed = false; },
		setPostType: ( value ) => { currentPostType = value; },
	};
}

test( 'editor panel is limited to supported post types', () => {
	assert.match( source, /config\.supportedPostTypes/ );
	assert.match( source, /return null;/ );
} );

test( 'the editor panel and optional block use the WP Title Layer mark', () => {
	assert.match( source, /function titleLayerIcon\( className \)/ );
	assert.match( source, /M3 3h14v2h-6v5H9V5H3V3z/ );
	assert.match( source, /registerPlugin\( 'wp-title-layer', \{ render: TitleLayerPanel, icon: titleLayerIcon\(\) \} \)/ );
	assert.match( source, /registerBlockType\( 'wp-title-layer\/title-layer',[\s\S]*?icon: titleLayerIcon\(\)/ );
	assert.doesNotMatch( source, /editor-textcolor/ );
} );

test( 'Series controls are limited to taxonomy object types', () => {
	assert.match( source, /config\.seriesPostTypes/ );
	assert.match( source, /if \( editor\.seriesEnabled \)/ );
} );

test( 'the native Series panel is removed through editor UI state only', () => {
	assert.match( source, /config\.nativeSeriesPanel/ );
	assert.match( source, /select\( 'core\/editor' \)/ );
	assert.match( source, /dispatch\( 'core\/editor' \)/ );
	assert.match( source, /select\( 'core\/edit-post' \)/ );
	assert.match( source, /dispatch\( 'core\/edit-post' \)/ );
	assert.match( source, /removeEditorPanel\( panelName \)/ );
	assert.match( source, /isEditorPanelRemoved\( panelName \)/ );
	assert.match( source, /wp\.data\.subscribe\( removePanelWhenReady \)/ );
} );

test( 'the native panel guard survives delayed setup without editing the post', () => {
	const runtime = bootPanelGuard();
	assert.equal( runtime.removeCalls, 0, 'the guard ran before the editor post type was ready' );
	assert.equal( typeof runtime.subscriber, 'function' );
	assert.deepEqual( runtime.events.slice( 0, 2 ), [ 'register', 'subscribe' ], 'the native panel was guarded before WPTL registered its own panel' );

	runtime.setPostType( 'post' );
	runtime.subscriber();
	assert.equal( runtime.removeCalls, 1 );
	assert.equal( runtime.removedPanel, 'taxonomy-panel-wptl_series' );
	assert.equal( runtime.removingStore, 'core/editor' );
	assert.equal( runtime.editPostCalls, 0, 'hiding the panel dirtied the post' );

	runtime.resetRemoved();
	runtime.subscriber();
	assert.equal( runtime.removeCalls, 2, 'the guard did not recover after editor UI state reset' );
	runtime.subscriber();
	assert.equal( runtime.removeCalls, 2, 'an already removed panel was dispatched again' );
	assert.equal( runtime.editPostCalls, 0 );
} );

test( 'the panel guard falls back safely and fails open without its replacement control', () => {
	const fallback = bootPanelGuard( { canonical: false } );
	fallback.setPostType( 'post' );
	fallback.subscriber();
	assert.equal( fallback.removeCalls, 1 );
	assert.equal( fallback.removingStore, 'core/edit-post' );

	const noReplacement = bootPanelGuard( { combobox: false } );
	assert.equal( noReplacement.subscriber, null );
	assert.equal( noReplacement.removeCalls, 0 );
} );

test( 'Series relationships use the REST post attribute and searchable existing terms', () => {
	assert.match( source, /config\.seriesAttribute \|\| schema\.taxonomy/ );
	assert.match( source, /getEditedPostAttribute\( seriesAttribute \)/ );
	assert.match( source, /change\[ seriesAttribute \]/ );
	assert.match( source, /ComboboxControl/ );
	assert.match( source, /seriesQuery\.search = seriesSearch/ );
	assert.match( source, /availableSeriesTerms\.unshift\( editor\.selectedTerm \)/ );
	assert.match( source, /isLoading: ! Array\.isArray\( editor\.seriesTerms \)/ );
	assert.doesNotMatch( source, /per_page:\s*100/ );
} );

test( 'the rendered Series control writes the configured REST relationship and resets dependent scope', () => {
	const edits = [];
	const readAttributes = [];
	let panelRender = null;
	let seriesControl = null;
	const selectedTerm = { id: 7, name: 'Existing Series', meta: {} };
	const ComboboxControl = function () {};
	const wp = {
		blockEditor: { useBlockProps: () => ( {} ), InspectorControls: function () {} },
		blocks: { registerBlockType: () => {} },
		components: { ComboboxControl },
		data: {
			useDispatch: () => ( { editPost: ( change ) => { edits.push( change ); } } ),
			useSelect: ( mapSelect ) => mapSelect( ( store ) => {
				if ( store === 'core/editor' ) {
					return {
						getCurrentPostType: () => 'post',
						getEditedPostAttribute: ( attribute ) => {
							readAttributes.push( attribute );
							if ( attribute === 'meta' ) {
								return {};
							}
							if ( attribute === 'wptl-series-rest' ) {
								return [ 7 ];
							}
							return [];
						},
					};
				}
				return {
					getEntityRecord: () => selectedTerm,
					getEntityRecords: () => [ selectedTerm ],
				};
			} ),
		},
		editPost: { PluginDocumentSettingPanel: function () {} },
		element: {
			Fragment: function () {},
			createElement: ( type, props ) => {
				if ( type === ComboboxControl ) {
					seriesControl = props;
				}
				return {};
			},
			useState: ( initial ) => [ initial, () => {} ],
		},
		i18n: { __: ( value ) => value },
		plugins: {
			registerPlugin: ( name, settings ) => {
				assert.equal( name, 'wp-title-layer' );
				panelRender = settings.render;
			},
		},
		serverSideRender: null,
	};
	vm.runInNewContext( source, {
		window: {
			wp,
			WPTitleLayerEditor: {
				categoryRules: [],
				defaultTemplate: 'standard',
				nativeSeriesPanel: '',
				presets: [],
				roles: [],
				schema: {
					taxonomy: 'wptl_series',
					seasonKey: 'wptl_season_key',
					seriesScope: 'wptl_series_scope',
					seriesRole: 'wptl_series_role',
				},
				seriesAttribute: 'wptl-series-rest',
				seriesPostTypes: [ 'post' ],
				supportedPostTypes: [ 'post' ],
			},
		},
	} );

	assert.equal( typeof panelRender, 'function' );
	panelRender();
	assert.ok( readAttributes.includes( 'wptl-series-rest' ) );
	assert.ok( ! readAttributes.includes( 'wptl_series' ) );
	assert.equal( seriesControl.value, '7' );
	seriesControl.onChange( '9' );
	assert.equal( edits.length, 1 );
	assert.deepEqual( Object.keys( edits[ 0 ] ), [ 'wptl-series-rest', 'meta' ] );
	assert.deepEqual( Array.from( edits[ 0 ]['wptl-series-rest'] ), [ 9 ] );
	assert.equal( edits[ 0 ].meta.wptl_season_key, '' );
	assert.equal( edits[ 0 ].meta.wptl_series_scope, '' );
	assert.ok( ! Object.hasOwn( edits[ 0 ].meta, 'wptl_series_role' ) );
	seriesControl.onChange( '' );
	assert.deepEqual( Array.from( edits[ 1 ]['wptl-series-rest'] ), [] );
	assert.equal( edits[ 1 ].meta.wptl_series_role, '' );
} );

test( 'clearing an integer sequence position sends null', () => {
	assert.match( source, /value === '' \? null : parseInt/ );
} );

test( 'all supported Series roles remain selectable', () => {
	assert.match( source, /config\.roles/ );
} );

test( 'seasoned Series use a defined-season selector', () => {
	assert.match( source, /termMeta\[ schema\.seasons \]/ );
	assert.match( source, /Select a season/ );
} );

test( 'assign-only authors can load Series terms without edit-term context', () => {
	assert.match( source, /context: 'view'/ );
	assert.match( source, /hide_empty: false/ );
	assert.doesNotMatch( source, /context: 'edit'/ );
} );
