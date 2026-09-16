( function () {
	'use strict';

	function initialize( root ) {
		var series = root.querySelector( '[data-wptl-series-select]' );
		if ( ! series ) {
			return;
		}

		var season = root.querySelector( '[data-wptl-season-select]' );
		var role = root.querySelector( '[data-wptl-role-select]' );
		var scope = root.querySelector( '[data-wptl-scope-select]' );
		var managerLinks = root.querySelectorAll( '[data-wptl-manager-link]' );
		var managedHeading = root.querySelector( '[data-wptl-managed-heading]' );
		var conditional = root.querySelectorAll( '[data-wptl-series-condition]' );
		var groupInput = root.querySelector( '#wptl-classic-group' );
		var groupList = root.querySelector( '#wptl-classic-groups' );
		var groupStatus = root.querySelector( '[data-wptl-group-status]' );
		var groupRename = root.querySelector( '[data-wptl-group-rename]' );
		var groupForm = root.querySelector( '[data-wptl-group-rename-form]' );
		var groupConfig = window.WPTLGroupEditor || {};
		var groupOptions = [], groupScope = '', renameId = 0;
		function currentGroup() { return groupOptions.find( function ( item ) { return item.label === groupInput.value || ( item.aliases || [] ).indexOf( groupInput.value ) !== -1; } ); }
		function groupActions() { if ( groupRename ) { groupRename.hidden = ! groupConfig.canRename || ! currentGroup(); } }
		function loadGroups( term, seasonKey ) {
			if ( ! groupInput || ! window.wp || ! window.wp.apiFetch ) { return; }
			var nextScope = term + ':' + seasonKey;
			if ( nextScope === groupScope ) { return; }
			groupScope = nextScope; groupOptions = []; groupList.textContent = ''; groupActions(); groupForm.hidden = true;
			window.wp.apiFetch( { path: '/wp-title-layer/v1/groups?series=' + term + '&season=' + encodeURIComponent( seasonKey ) } ).then( function ( items ) {
				if ( groupScope !== nextScope ) { return; }
				groupOptions = items;
				items.forEach( function ( item ) { var option = document.createElement( 'option' ); option.value = item.label; groupList.appendChild( option ); } );
				groupStatus.textContent = ''; groupActions();
			} ).catch( function () { if ( groupScope === nextScope ) { groupStatus.textContent = groupConfig.loadError; } } );
		}
		if ( groupInput ) {
			groupInput.addEventListener( 'input', groupActions );
			root.querySelector( '[data-wptl-group-rename-start]' ).addEventListener( 'click', function () {
				var item = currentGroup(); if ( ! item ) { return; }
				renameId = item.id; root.querySelector( '#wptl-classic-group-new' ).value = item.label; groupForm.hidden = false;
			} );
			root.querySelector( '[data-wptl-group-rename-cancel]' ).addEventListener( 'click', function () { groupForm.hidden = true; } );
			root.querySelector( '[data-wptl-group-rename-save]' ).addEventListener( 'click', function ( event ) {
				var button = event.currentTarget, requestScope = groupScope;
				var label = root.querySelector( '#wptl-classic-group-new' ).value.trim(); if ( ! label ) { return; }
				button.disabled = true; groupInput.readOnly = true;
				window.wp.apiFetch( { path: '/wp-title-layer/v1/groups/' + renameId, method: 'POST', data: { label: label } } ).then( function ( result ) {
					if ( groupScope !== requestScope ) { return; }
					groupInput.value = result.label; groupForm.hidden = true; groupScope = ''; update();
				} ).catch( function ( error ) { if ( groupScope === requestScope ) { groupStatus.textContent = error.message || groupConfig.renameError; } } ).finally( function () { button.disabled = false; groupInput.readOnly = false; } );
			} );
		}

		function structureTrackKey( structure, scopeValue, seasonKey, roleValue ) {
			roleValue = roleValue || 'article';
			seasonKey = seasonKey || '';
			if ( structure === 'seasoned' ) {
				if ( roleValue !== 'article' && scopeValue === 'series' ) {
					return 'series_' + roleValue;
				}
				return seasonKey ? 'season_' + seasonKey + ( roleValue === 'article' ? '' : '_' + roleValue ) : '';
			}
			return roleValue === 'article' ? 'series' : 'series_' + roleValue;
		}

		function update() {
			var selected = series.options[ series.selectedIndex ];
			var termId = selected ? selected.value : '0';
			var mode = selected ? selected.getAttribute( 'data-mode' ) : '';
			var structure = selected ? selected.getAttribute( 'data-structure' ) : '';
			var managed = selected ? selected.getAttribute( 'data-managed' ) === '1' : false;
			var book = selected ? selected.getAttribute( 'data-book' ) === '1' : false;
			var roleValue = role ? role.value : '';
			var mainArticle = ! roleValue || roleValue === 'article';
			var scopeValue = scope ? scope.value : '';

			Array.prototype.forEach.call( conditional, function ( group ) {
				var condition = group.getAttribute( 'data-wptl-series-condition' );
				var visible = condition === mode || condition === structure;
				if ( condition === 'managed-ordered' ) {
					visible = mode === 'ordered' && managed && mainArticle;
				} else if ( condition === 'legacy-ordered' ) {
					visible = mode === 'ordered' && ! managed;
				} else if ( condition === 'book-scope' ) {
					visible = structure === 'seasoned' && ! mainArticle;
				} else if ( condition === 'season-required' ) {
					visible = structure === 'seasoned' && ( mainArticle || scopeValue !== 'series' );
				} else if ( condition === 'book-track' ) {
					visible = book && ! mainArticle;
				} else if ( condition === 'content-group' ) {
					visible = termId !== '0' && mainArticle;
				} else if ( condition === 'inactive-group' ) {
					visible = termId !== '0' && ! mainArticle && !! ( groupInput && groupInput.value );
				}
				group.hidden = ! visible;
				Array.prototype.forEach.call( group.querySelectorAll( 'input, select' ), function ( field ) {
					field.disabled = ! visible;
				} );
			} );

			if ( season ) {
				Array.prototype.forEach.call( season.options, function ( option, index ) {
					if ( index === 0 ) {
						option.disabled = false;
						option.hidden = false;
						return;
					}
					var matches = option.getAttribute( 'data-series' ) === termId;
					option.disabled = ! matches;
					option.hidden = ! matches;
				} );
				if ( season.selectedOptions.length && season.selectedOptions[ 0 ].disabled ) {
					season.value = '';
				}
			}

			loadGroups( termId, structure === 'seasoned' && ( mainArticle || scopeValue !== 'series' ) && season ? season.value : '' );
			Array.prototype.forEach.call( managerLinks, function ( managerLink ) {
				var baseUrl = managerLink.getAttribute( 'data-base-url' ) || '';
				var postId = managerLink.getAttribute( 'data-post-id' ) || '';
				var seasonKey = season ? season.value : '';
				var trackKey = book && ! mainArticle ? structureTrackKey( structure, scopeValue, seasonKey, roleValue ) : '';
				managerLink.href = baseUrl
					+ '&series_id=' + encodeURIComponent( termId )
					+ ( trackKey
						? '&manager_view=structure&track=' + encodeURIComponent( trackKey )
						: '&manager_view=sequence' + ( seasonKey ? '&season_key=' + encodeURIComponent( seasonKey ) : '' ) )
					+ ( postId ? '&highlight=' + encodeURIComponent( postId ) : '' );
			} );

			if ( managedHeading ) {
				var currentTermId = managedHeading.getAttribute( 'data-current-term-id' ) || '0';
				var currentSeason = managedHeading.getAttribute( 'data-current-season-key' ) || '';
				var currentOrdinal = parseInt( managedHeading.getAttribute( 'data-current-ordinal' ), 10 ) || 0;
				var sameScope = currentTermId === termId && currentSeason === ( season ? season.value : '' );
				managedHeading.textContent = sameScope && currentOrdinal > 0
					? ( managedHeading.getAttribute( 'data-automatic-label' ) || '' ) + ' ' + currentOrdinal
					: ( managedHeading.getAttribute( 'data-managed-label' ) || '' );
			}
		}

		series.addEventListener( 'change', update );
		if ( season ) {
			season.addEventListener( 'change', update );
		}
		if ( role ) {
			role.addEventListener( 'change', function () {
				if ( scope && role.value && role.value !== 'article' && ! scope.value ) {
					scope.value = 'season';
				} else if ( scope && ( ! role.value || role.value === 'article' ) ) {
					scope.value = '';
				}
				update();
			} );
		}
		if ( scope ) {
			scope.addEventListener( 'change', function () {
				if ( scope.value === 'series' && season ) {
					season.value = '';
				}
				update();
			} );
		}
		update();
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-wptl-classic-editor]' ), initialize );
}() );
