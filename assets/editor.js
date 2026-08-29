( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var PluginDocumentSettingPanel = wp.editPost && wp.editPost.PluginDocumentSettingPanel;
	var ServerSideRender = wp.serverSideRender && ( wp.serverSideRender.default || wp.serverSideRender );
	var schema = config.schema || {};
	var seriesAttribute = config.seriesAttribute || schema.taxonomy;
	var titleLayerIconPath = 'M3 3h14v2h-6v5H9V5H3V3zm1 9h12v2H4v-2zm2 4h8v2H6v-2z';

	function titleLayerIcon( className ) {
		return el( 'svg', {
			className: className || undefined,
			viewBox: '0 0 20 20',
			width: 24,
			height: 24,
			focusable: 'false',
			'aria-hidden': true
		}, el( 'path', { d: titleLayerIconPath, fill: 'currentColor' } ) );
	}

	function supportsSeriesControl( postType ) {
		return !! postType
			&& ( config.supportedPostTypes || [] ).indexOf( postType ) !== -1
			&& ( config.seriesPostTypes || [] ).indexOf( postType ) !== -1;
	}

	/*
	 * The native flat-taxonomy panel permits multiple terms and term creation.
	 * WPTL owns a single-select control instead. removeEditorPanel changes only
	 * editor UI state; subscribing lets the guard survive delayed panel setup or
	 * an editor-store reset without touching post attributes or preferences.
	 */
	function installNativeSeriesPanelGuard() {
		var panelName = typeof config.nativeSeriesPanel === 'string' ? config.nativeSeriesPanel : '';
		if ( ! panelName || ! wp.data || typeof wp.data.select !== 'function' || typeof wp.data.dispatch !== 'function' ) {
			return;
		}

		function removePanelWhenReady() {
			var editorSelect = wp.data.select( 'core/editor' );
			if (
				! editorSelect
				|| typeof editorSelect.getCurrentPostType !== 'function'
				|| ! supportsSeriesControl( editorSelect.getCurrentPostType() )
			) {
				return;
			}

			var panelSelect = editorSelect;
			var panelDispatch = wp.data.dispatch( 'core/editor' );
			if (
				! panelSelect
				|| ! panelDispatch
				|| typeof panelSelect.isEditorPanelRemoved !== 'function'
				|| typeof panelDispatch.removeEditorPanel !== 'function'
			) {
				// WordPress 6.5+ exposes these in core/editor. Keep the
				// deprecated core/edit-post bridge only as a capability fallback.
				panelSelect = wp.data.select( 'core/edit-post' );
				panelDispatch = wp.data.dispatch( 'core/edit-post' );
			}
			if (
				! panelSelect
				|| ! panelDispatch
				|| typeof panelSelect.isEditorPanelRemoved !== 'function'
				|| typeof panelDispatch.removeEditorPanel !== 'function'
				|| panelSelect.isEditorPanelRemoved( panelName )
			) {
				return;
			}

			panelDispatch.removeEditorPanel( panelName );
		}

		removePanelWhenReady();
		if ( typeof wp.data.subscribe === 'function' ) {
			wp.data.subscribe( removePanelWhenReady );
		}
	}

	function optionList( leading ) {
		var choices = ( config.presets || [] ).map( function ( preset ) {
			return { label: preset.label, value: preset.value };
		} );
		return leading ? [ leading ].concat( choices ) : choices;
	}

	function presetLabel( value ) {
		var preset = ( config.presets || [] ).find( function ( item ) {
			return item.value === value;
		} );
		return preset ? preset.label : value;
	}

	function selectedSeriesTermId( editedTerms ) {
		if ( ! Array.isArray( editedTerms ) || ! editedTerms.length ) {
			return 0;
		}
		var first = editedTerms[ 0 ];
		return parseInt( typeof first === 'object' ? first.id : first, 10 ) || 0;
	}

	function structureTrackKey( structure, scope, seasonKey, role ) {
		role = role || 'article';
		seasonKey = seasonKey || '';
		if ( structure === 'seasoned' ) {
			if ( role !== 'article' && scope === 'series' ) {
				return 'series_' + role;
			}
			return seasonKey ? 'season_' + seasonKey + ( role === 'article' ? '' : '_' + role ) : '';
		}
		return role === 'article' ? 'series' : 'series_' + role;
	}

	function TitleLayerPanel() {
		var seriesSearchState = useState( '' );
		var seriesSearch = seriesSearchState[ 0 ];
		var setSeriesSearch = seriesSearchState[ 1 ];
		var editor = useSelect( function ( select ) {
			var coreEditor = select( 'core/editor' );
			var core = select( 'core' );
			var postType = coreEditor.getCurrentPostType();
			var meta = coreEditor.getEditedPostAttribute( 'meta' ) || {};
			var seriesEnabled = supportsSeriesControl( postType );
			var editedTerms = seriesEnabled ? ( coreEditor.getEditedPostAttribute( seriesAttribute ) || [] ) : [];
			var categoryIds = coreEditor.getEditedPostAttribute( 'categories' ) || [];
			var termId = selectedSeriesTermId( editedTerms );
			var seriesQuery = {
				per_page: 50,
				hide_empty: false,
				orderby: 'name',
				order: 'asc',
				context: 'view'
			};
			if ( seriesSearch ) {
				seriesQuery.search = seriesSearch;
			}
			return {
				postId: typeof coreEditor.getCurrentPostId === 'function'
					? coreEditor.getCurrentPostId()
					: parseInt( ( config.currentSequence || {} ).postId, 10 ) || 0,
				postType: postType,
				meta: meta,
				categoryIds: categoryIds,
				seriesEnabled: seriesEnabled,
				seriesTerms: seriesEnabled ? core.getEntityRecords( 'taxonomy', schema.taxonomy, seriesQuery ) : [],
				selectedTermId: termId,
				selectedTerm: termId ? core.getEntityRecord( 'taxonomy', schema.taxonomy, termId, { context: 'view' } ) : null
			};
		}, [ seriesSearch ] );
		var editPost = useDispatch( 'core/editor' ).editPost;

		if ( ( config.supportedPostTypes || [] ).indexOf( editor.postType ) === -1 ) {
			return null;
		}

		function setMeta( key, value ) {
			var next = Object.assign( {}, editor.meta );
			next[ key ] = value;
			editPost( { meta: next } );
		}

		function setSeries( value ) {
			var termId = parseInt( value, 10 ) || 0;
			var change = {};
			change[ seriesAttribute ] = termId ? [ termId ] : [];
			if ( termId !== editor.selectedTermId ) {
				var nextMeta = Object.assign( {}, editor.meta );
				nextMeta[ schema.seasonKey ] = '';
				nextMeta[ schema.seriesScope ] = '';
				if ( ! termId ) {
					nextMeta[ schema.seriesRole ] = '';
				}
				change.meta = nextMeta;
			}
			editPost( change );
			setSeriesSearch( '' );
		}

		var termMeta = editor.selectedTerm && editor.selectedTerm.meta ? editor.selectedTerm.meta : {};
		var mode = termMeta[ schema.seriesMode ] === 'ordered' ? 'ordered' : 'unordered';
		var structure = termMeta[ schema.seriesStructure ] === 'seasoned' ? 'seasoned' : 'flat';
		var sequenceManaged = ( config.managedSeriesIds || [] ).map( function ( id ) { return parseInt( id, 10 ); } ).indexOf( editor.selectedTermId ) !== -1;
		var bookStructureEnabled = ( config.bookStructureSeriesIds || [] ).map( function ( id ) { return parseInt( id, 10 ); } ).indexOf( editor.selectedTermId ) !== -1;
		var seasons = Array.isArray( termMeta[ schema.seasons ] ) ? termMeta[ schema.seasons ] : [];
		var role = editor.meta[ schema.seriesRole ] || '';
		var mainArticle = ! role || role === 'article';
		var contentScope = editor.meta[ schema.seriesScope ] || '';
		var seriesDefault = termMeta[ schema.defaultTemplate ] || '';
		var override = editor.meta[ schema.templateOverride ] || '';
		var parentCategoryId = parseInt( termMeta[ schema.parentCategory ], 10 ) || 0;
		var categoryBranch = parentCategoryId && config.seriesCategoryBranches
			? config.seriesCategoryBranches[ String( parentCategoryId ) ]
			: null;
		var categoryBranchIds = categoryBranch && Array.isArray( categoryBranch.ids )
			? categoryBranch.ids
			: ( parentCategoryId ? [ parentCategoryId ] : [] );
		var categoryConsistent = ! parentCategoryId || editor.categoryIds.some( function ( categoryId ) {
			return categoryBranchIds.indexOf( parseInt( categoryId, 10 ) ) !== -1;
		} );
		var sourceLabel = __( 'Global default', 'wp-title-layer' ) + ': ' + presetLabel( config.defaultTemplate );
		var effective = config.defaultTemplate;

		( config.categoryRules || [] ).some( function ( rule ) {
			if ( editor.categoryIds.indexOf( rule.categoryId ) !== -1 ) {
				effective = rule.preset;
				sourceLabel = __( 'Category rule', 'wp-title-layer' ) + ': ' + rule.categoryName;
				return true;
			}
			return false;
		} );
		if ( editor.selectedTerm && seriesDefault ) {
			effective = seriesDefault;
			sourceLabel = __( 'Series default', 'wp-title-layer' ) + ': ' + editor.selectedTerm.name;
		}
		if ( override ) {
			effective = override;
			sourceLabel = override === 'disabled'
				? __( 'Disabled by post override', 'wp-title-layer' )
				: __( 'Post override', 'wp-title-layer' );
		}

		var availableSeriesTerms = Array.isArray( editor.seriesTerms ) ? editor.seriesTerms.slice() : [];
		if (
			editor.selectedTerm
			&& ! availableSeriesTerms.some( function ( term ) { return term.id === editor.selectedTerm.id; } )
		) {
			availableSeriesTerms.unshift( editor.selectedTerm );
		}
		var termChoices = [ { label: __( 'No Series', 'wp-title-layer' ), value: '0' } ];
		if ( Array.isArray( editor.seriesTerms ) ) {
			termChoices = termChoices.concat( availableSeriesTerms.map( function ( term ) {
				return { label: term.name, value: String( term.id ) };
			} ) );
		}

		var controls = [
			el( wp.components.TextareaControl, {
				key: 'subtitle',
				label: __( 'Subtitle', 'wp-title-layer' ),
				rows: 2,
				value: editor.meta[ schema.subtitle ] || '',
				onChange: function ( value ) { setMeta( schema.subtitle, value ); },
				help: __( 'Explains or extends the main title. It is not a Series label.', 'wp-title-layer' )
			} )
		];

		if ( editor.seriesEnabled ) {
			controls.push( el( wp.components.ComboboxControl, {
				key: 'series',
				label: __( 'Series', 'wp-title-layer' ),
				value: String( editor.selectedTermId ),
				options: termChoices,
				onChange: setSeries,
				onFilterValueChange: setSeriesSearch,
				isLoading: ! Array.isArray( editor.seriesTerms )
			} ) );
		}

		if ( editor.selectedTerm && ! categoryConsistent ) {
			controls.push( el( wp.components.Notice, {
				key: 'series-category-mismatch',
				status: 'warning',
				isDismissible: false
			},
				el( 'strong', null, __( 'Series / Category mismatch', 'wp-title-layer' ) ),
				el( 'br' ),
				__( 'This Series belongs to the Category branch:', 'wp-title-layer' ) + ' ' + ( categoryBranch ? categoryBranch.name : String( parentCategoryId ) ) + '. ',
				__( 'Assign this article to that Category or one of its descendants, or change the Series definition.', 'wp-title-layer' )
			) );
		}

		if ( editor.selectedTerm && structure === 'seasoned' && ! mainArticle ) {
			controls.push( el( wp.components.SelectControl, {
				key: 'series-scope',
				label: __( 'Book structure scope', 'wp-title-layer' ),
				value: contentScope,
				options: config.scopes || [],
				onChange: function ( value ) {
					var next = Object.assign( {}, editor.meta );
					next[ schema.seriesScope ] = value;
					if ( value === 'series' ) {
						next[ schema.seasonKey ] = '';
					}
					editPost( { meta: next } );
				},
				help: contentScope
					? __( 'Series-wide entries appear only on the complete Series archive; season entries appear only inside the chosen season.', 'wp-title-layer' )
					: __( 'This legacy role is not assigned to Series-wide or season scope yet. Choose explicitly before enabling book structure.', 'wp-title-layer' )
			} ) );
		}

		if ( editor.selectedTerm && structure === 'seasoned' && ( mainArticle || contentScope !== 'series' ) ) {
			var seasonChoices = [ { label: __( 'Select a season', 'wp-title-layer' ), value: '' } ].concat(
				seasons.map( function ( season ) {
					return { label: season.label || season.key, value: season.key };
				} )
			);
			controls.push( el( wp.components.SelectControl, {
				key: 'season',
				label: __( 'Season', 'wp-title-layer' ),
				value: editor.meta[ schema.seasonKey ] || '',
				options: seasonChoices,
				onChange: function ( value ) { setMeta( schema.seasonKey, value ); },
				help: seasons.length
					? __( 'Choose from the seasons defined for this Series.', 'wp-title-layer' )
					: __( 'Define seasons on the Series edit screen first.', 'wp-title-layer' )
			} ) );
		}

		if ( editor.selectedTerm && ( mode === 'ordered' || ( bookStructureEnabled && ! mainArticle ) ) ) {
			if ( sequenceManaged || ( bookStructureEnabled && ! mainArticle ) ) {
				var currentSequence = config.currentSequence || {};
				var selectedSeason = structure === 'seasoned' ? ( editor.meta[ schema.seasonKey ] || '' ) : '';
				var selectedTrack = structureTrackKey( structure, contentScope, selectedSeason, role );
				var sameScope = mainArticle && parseInt( currentSequence.termId, 10 ) === editor.selectedTermId
					&& ( currentSequence.seasonKey || '' ) === selectedSeason;
				var managerUrl = ( config.sequenceManagerUrl || '' )
					+ '&series_id=' + encodeURIComponent( String( editor.selectedTermId ) )
					+ ( ! mainArticle && bookStructureEnabled && selectedTrack
						? '&manager_view=structure&track=' + encodeURIComponent( selectedTrack )
						: '&manager_view=sequence' + ( selectedSeason ? '&season_key=' + encodeURIComponent( selectedSeason ) : '' ) )
					+ ( editor.postId ? '&highlight=' + encodeURIComponent( String( editor.postId ) ) : '' );
				controls.push( el( wp.components.Notice, {
					key: 'managed-sequence',
					status: 'info',
					isDismissible: false
				},
					el( 'strong', null, ! mainArticle
						? __( 'Managed structure track', 'wp-title-layer' )
						: ( sameScope && parseInt( currentSequence.ordinal, 10 ) > 0
							? __( 'Automatic article number:', 'wp-title-layer' ) + ' ' + String( currentSequence.ordinal )
							: __( 'Managed sequence', 'wp-title-layer' ) ) ),
					el( 'br' ),
					! mainArticle
						? __( 'This role has its own private order track in Sequence & Structure.', 'wp-title-layer' )
						: ( sameScope
							? __( 'Order is controlled by Sequence Manager; the private rank is not an article field.', 'wp-title-layer' )
							: __( 'After saving, this article will be appended to the selected Series and season.', 'wp-title-layer' ) ),
					managerUrl ? el( Fragment, null, el( 'br' ), el( 'a', { href: managerUrl }, __( 'Move in Sequence & Structure', 'wp-title-layer' ) ) ) : null
				) );
			} else if ( mode === 'ordered' ) {
				controls.push( el( wp.components.TextControl, {
					key: 'position',
					label: __( 'Legacy sequence position', 'wp-title-layer' ),
					type: 'number',
					min: 0,
					step: 1,
					value: editor.meta[ schema.sequencePosition ] === undefined ? '' : editor.meta[ schema.sequencePosition ],
					onChange: function ( value ) { setMeta( schema.sequencePosition, value === '' ? null : parseInt( value, 10 ) ); },
					help: __( 'This Series still uses the 0.9 position field. Initialize it in Sequence Manager to insert without renumbering later articles.', 'wp-title-layer' )
				} ) );
			}
			if ( mode === 'ordered' ) {
				controls.push(
				el( wp.components.TextControl, {
					key: 'sequence-label',
					label: mainArticle ? __( 'Sequence label override', 'wp-title-layer' ) : __( 'Public structure label', 'wp-title-layer' ),
					value: editor.meta[ schema.sequenceLabel ] || '',
					onChange: function ( value ) { setMeta( schema.sequenceLabel, value ); },
					help: ! mainArticle
						? __( 'Optional label such as 0-1, Preface II, or Appendix A.', 'wp-title-layer' )
						: ( sequenceManaged
						? __( 'Optional. Leave blank to show the automatic article number.', 'wp-title-layer' )
						: __( 'Optional. Leave blank to derive a label such as S2.03.', 'wp-title-layer' ) )
				} )
				);
			}
		}

		if ( editor.selectedTerm ) {
			if ( mode !== 'ordered' && ! mainArticle ) {
				controls.push( el( wp.components.TextControl, {
					key: 'sequence-label',
					label: __( 'Public structure label', 'wp-title-layer' ),
					value: editor.meta[ schema.sequenceLabel ] || '',
					onChange: function ( value ) { setMeta( schema.sequenceLabel, value ); },
					help: __( 'Optional label such as 0-1, Preface II, or Appendix A.', 'wp-title-layer' )
				} ) );
			}
			controls.push( el( wp.components.SelectControl, {
				key: 'role',
				label: __( 'Series role', 'wp-title-layer' ),
				value: role,
				options: config.roles || [],
				onChange: function ( value ) {
					var next = Object.assign( {}, editor.meta );
					next[ schema.seriesRole ] = value;
					if ( ! value || value === 'article' ) {
						next[ schema.seriesScope ] = '';
					} else if ( structure === 'seasoned' && ! next[ schema.seriesScope ] ) {
						next[ schema.seriesScope ] = 'season';
					}
					editPost( { meta: next } );
				},
				help: bookStructureEnabled
					? __( 'Introductions, epilogues, and appendices use independent movable tracks and never consume automatic main-article numbers.', 'wp-title-layer' )
					: __( 'Book-like tracks become canonical after this Series passes the Structure compatibility preview.', 'wp-title-layer' )
			} ) );
		}

		controls.push(
			el( wp.components.TextControl, {
				key: 'kicker',
				label: __( 'Kicker override', 'wp-title-layer' ),
				value: editor.meta[ schema.kickerOverride ] || '',
				onChange: function ( value ) { setMeta( schema.kickerOverride, value ); },
				help: __( 'Optional display text. Leave blank to use the Series short label.', 'wp-title-layer' )
			} ),
			el( wp.components.SelectControl, {
				key: 'template',
				label: __( 'Template override', 'wp-title-layer' ),
				value: override,
				options: [
					{ label: __( 'Inherit', 'wp-title-layer' ), value: '' },
					{ label: __( 'Do not display a Title Layer', 'wp-title-layer' ), value: 'disabled' }
				].concat( optionList() ),
				onChange: function ( value ) { setMeta( schema.templateOverride, value ); }
			} ),
			el( wp.components.Notice, {
				key: 'effective',
				status: 'info',
				isDismissible: false,
				className: 'wptl-template-source'
			},
				el( 'strong', null,
					override === 'disabled'
						? __( 'Effective template: disabled', 'wp-title-layer' )
						: __( 'Effective template:', 'wp-title-layer' ) + ' ' + presetLabel( effective )
				),
				el( 'br' ),
				sourceLabel
			)
		);

		return el( PluginDocumentSettingPanel, {
			name: 'wp-title-layer-fields',
			title: __( 'Title Layer', 'wp-title-layer' ),
			className: 'wptl-document-panel'
		}, controls );
	}

	var titleLayerPanelRegistered = false;
	if (
		PluginDocumentSettingPanel
		&& wp.plugins
		&& typeof wp.plugins.registerPlugin === 'function'
		&& wp.components
		&& typeof wp.components.ComboboxControl === 'function'
		&& typeof useState === 'function'
	) {
		wp.plugins.registerPlugin( 'wp-title-layer', { render: TitleLayerPanel, icon: titleLayerIcon() } );
		titleLayerPanelRegistered = true;
	}
	if ( titleLayerPanelRegistered ) {
		installNativeSeriesPanelGuard();
	}

	wp.blocks.registerBlockType( 'wp-title-layer/title-layer', {
		apiVersion: 2,
		title: __( 'Title Layer', 'wp-title-layer' ),
		description: __( 'Display the resolved editorial title hierarchy.', 'wp-title-layer' ),
		icon: titleLayerIcon(),
		category: 'theme',
		attributes: {
			postId: { type: 'integer', default: 0 },
			preset: { type: 'string', default: '' },
			headingTag: { type: 'string', default: 'h1' }
		},
		supports: { html: false, align: [ 'wide', 'full' ], className: true, anchor: true },
		edit: function ( props ) {
			var currentPostId = useSelect( function ( select ) {
				return select( 'core/editor' ).getCurrentPostId();
			}, [] );
			var blockProps = wp.blockEditor.useBlockProps( { className: 'wptl-block-editor-preview' } );
			var inspector = el( wp.blockEditor.InspectorControls, null,
				el( wp.components.PanelBody, { title: __( 'Title Layer settings', 'wp-title-layer' ) },
					el( wp.components.SelectControl, {
						label: __( 'Template', 'wp-title-layer' ),
						value: props.attributes.preset,
						options: optionList( { label: __( 'Use resolved template', 'wp-title-layer' ), value: '' } ),
						onChange: function ( value ) { props.setAttributes( { preset: value } ); }
					} ),
					el( wp.components.SelectControl, {
						label: __( 'Heading level', 'wp-title-layer' ),
						value: props.attributes.headingTag,
						options: [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p' ].map( function ( tag ) {
							return { label: tag.toUpperCase(), value: tag };
						} ),
						onChange: function ( value ) { props.setAttributes( { headingTag: value } ); }
					} )
				)
			);
			var previewAttributes = Object.assign( {}, props.attributes, {
				postId: props.attributes.postId || currentPostId || 0
			} );
			var fallback = el( 'div', { className: 'wptl-block-placeholder' },
				titleLayerIcon( 'wptl-title-layer-mark' ),
				el( 'strong', null, __( 'Dynamic Title Layer', 'wp-title-layer' ) ),
				el( 'p', null, currentPostId
					? __( 'A live preview is temporarily unavailable. The Title Layer will still render on the front end.', 'wp-title-layer' )
					: __( 'Save this article once to load its live Title Layer preview.', 'wp-title-layer' )
				)
			);
			var preview = ServerSideRender && currentPostId
				? el( ServerSideRender, {
					block: 'wp-title-layer/title-layer',
					attributes: previewAttributes,
					urlQueryArgs: { post_id: currentPostId },
					EmptyResponsePlaceholder: function () {
						return el( wp.components.Placeholder, {
							icon: titleLayerIcon(),
							label: __( 'Title Layer is not displayed', 'wp-title-layer' ),
							instructions: __( 'This article is disabled or does not yet have a renderable title layer.', 'wp-title-layer' )
						} );
					},
					ErrorResponsePlaceholder: function () { return fallback; }
				} )
				: fallback;
			return el( Fragment, null,
				inspector,
				el( 'div', blockProps,
					preview,
					el( 'p', { className: 'wptl-preview-note' }, __( 'Preview uses the latest saved article data. Update the article to refresh changed title fields.', 'wp-title-layer' ) )
				)
			);
		},
		save: function () { return null; }
	} );
} )( window.wp, window.WPTitleLayerEditor );
