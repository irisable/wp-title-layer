( function ( config ) {
	'use strict';

	if ( ! config || ! config.ajaxUrl ) {
		return;
	}

	var live = document.querySelector( '[data-wptl-sequence-live]' );

	function announce( message ) {
		if ( live ) {
			live.textContent = '';
			window.setTimeout( function () { live.textContent = message; }, 20 );
		}
	}

	function post( values ) {
		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams( values ).toString()
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( ! response.ok || ! payload.success ) {
					var message = payload && payload.data && payload.data.message ? payload.data.message : config.messages.failed;
					throw new Error( message );
				}
				return payload.data || {};
			} );
		} );
	}

	function initializeSeriesCombobox() {
		var host = document.querySelector( '[data-wptl-series-combobox]' );
		var select = document.querySelector( '[data-wptl-series-select]' );
		var wpElement = window.wp && window.wp.element;
		var ComboboxControl = window.wp && window.wp.components && window.wp.components.ComboboxControl;
		if ( ! host || ! select || ! wpElement || typeof ComboboxControl !== 'function' ) {
			return;
		}

		var allOptions = Array.prototype.map.call( select.options, function ( option ) {
			return { label: option.textContent, value: option.value };
		} );
		var label = host.getAttribute( 'data-label' ) || '';
		var loadedValue = select.value || '0';
		var el = wpElement.createElement;
		var useState = wpElement.useState;
		var useEffect = wpElement.useEffect;
		var wrapper = select.closest( 'form' );
		if ( ! wrapper || typeof useState !== 'function' || typeof useEffect !== 'function' ) {
			return;
		}

		function SeriesPicker() {
			var valueState = useState( loadedValue );
			var filterState = useState( '' );
			var value = valueState[ 0 ];
			var setValue = valueState[ 1 ];
			var filter = filterState[ 0 ].trim().toLocaleLowerCase();
			var options = ! filter ? allOptions : allOptions.filter( function ( option ) {
				return option.label.toLocaleLowerCase().indexOf( filter ) !== -1;
			} );
			useEffect( function () {
				// Hide the native control only after React has committed successfully.
				wrapper.classList.add( 'is-series-combobox-enhanced' );
				return function () {
					wrapper.classList.remove( 'is-series-combobox-enhanced' );
				};
			}, [] );
			return el( ComboboxControl, {
				label: label,
				value: value,
				options: options,
				onChange: function ( nextValue ) {
					nextValue = nextValue || '0';
					var shouldOpen = '0' !== nextValue && nextValue !== loadedValue;
					select.value = nextValue;
					setValue( nextValue );
					if ( shouldOpen ) {
						if ( typeof wrapper.requestSubmit === 'function' ) {
							wrapper.requestSubmit();
						} else if ( typeof wrapper.submit === 'function' ) {
							wrapper.submit();
						}
					}
				},
				onFilterValueChange: filterState[ 1 ]
			} );
		}

		try {
			if ( typeof wpElement.createRoot === 'function' ) {
				wpElement.createRoot( host ).render( el( SeriesPicker ) );
			} else if ( typeof wpElement.render === 'function' ) {
				wpElement.render( el( SeriesPicker ), host );
			} else {
				return;
			}
		} catch ( error ) {
			// Keep the native select visible as the fail-safe control.
			wrapper.classList.remove( 'is-series-combobox-enhanced' );
		}
	}

	function initializeDrag() {
		var body = document.querySelector( '[data-wptl-sequence-rows]' );
		if ( ! body ) {
			return;
		}
		var sourceId = 0;
		var revision = parseInt( body.getAttribute( 'data-revision' ), 10 ) || config.revision || 0;
		Array.prototype.forEach.call( body.querySelectorAll( 'tr[data-post-id]' ), function ( row ) {
			row.addEventListener( 'dragstart', function ( event ) {
				sourceId = parseInt( row.getAttribute( 'data-post-id' ), 10 ) || 0;
				row.classList.add( 'is-dragging' );
				if ( event.dataTransfer ) {
					event.dataTransfer.effectAllowed = 'move';
					event.dataTransfer.setData( 'text/plain', String( sourceId ) );
				}
			} );
			row.addEventListener( 'dragend', function () {
				row.classList.remove( 'is-dragging' );
				Array.prototype.forEach.call( body.querySelectorAll( '.is-drop-target' ), function ( target ) { target.classList.remove( 'is-drop-target' ); } );
			} );
			row.addEventListener( 'dragover', function ( event ) {
				if ( sourceId && sourceId !== parseInt( row.getAttribute( 'data-post-id' ), 10 ) ) {
					event.preventDefault();
					row.classList.add( 'is-drop-target' );
				}
			} );
			row.addEventListener( 'dragleave', function () { row.classList.remove( 'is-drop-target' ); } );
			row.addEventListener( 'drop', function ( event ) {
				event.preventDefault();
				row.classList.remove( 'is-drop-target' );
				var anchorId = parseInt( row.getAttribute( 'data-post-id' ), 10 ) || 0;
				if ( ! sourceId || ! anchorId || sourceId === anchorId ) {
					return;
				}
				announce( config.messages.moving );
				post( {
					action: 'wptl_sequence_move_ajax',
					nonce: config.nonce,
					series_id: config.termId,
					season_key: config.seasonKey || '',
					track: config.trackKey || '',
					post_id: sourceId,
					anchor_id: anchorId,
					placement: 'before',
					sequence_revision: revision
				} ).then( function () {
					announce( config.messages.moved );
					window.location.reload();
				} ).catch( function ( error ) {
					announce( error.message || config.messages.failed );
					window.alert( error.message || config.messages.failed );
				} );
			} );
		} );
	}

	function initializePreciseSearch( form ) {
		var input = form.querySelector( '[data-wptl-anchor-search]' );
		var hidden = form.querySelector( '[data-wptl-anchor-id]' );
		var results = form.querySelector( '[data-wptl-anchor-results]' );
		var submit = form.querySelector( '[data-wptl-precise-submit]' );
		var source = parseInt( form.querySelector( 'input[name="post_id"]' ).value, 10 ) || 0;
		var timer = 0;
		if ( ! input || ! hidden || ! results || ! submit ) {
			return;
		}

		function clearChoice() {
			hidden.value = '';
			submit.disabled = true;
		}

		input.addEventListener( 'input', function () {
			clearChoice();
			window.clearTimeout( timer );
			var query = input.value.trim();
			if ( query.length < 2 ) {
				results.replaceChildren();
				return;
			}
			timer = window.setTimeout( function () {
				results.textContent = config.messages.searching;
				post( {
					action: 'wptl_sequence_search',
					nonce: config.nonce,
					series_id: config.termId,
					season_key: config.seasonKey || '',
					track: config.trackKey || '',
					search: query
				} ).then( function ( data ) {
					var rows = ( data.rows || [] ).filter( function ( row ) { return parseInt( row.id, 10 ) !== source; } );
					results.replaceChildren();
					if ( ! rows.length ) {
						results.textContent = config.messages.noResults;
						return;
					}
					var list = document.createElement( 'div' );
					list.className = 'wptl-sequence-result-list';
					list.setAttribute( 'role', 'listbox' );
					rows.forEach( function ( row ) {
						var button = document.createElement( 'button' );
						button.type = 'button';
						button.className = 'button-link';
						button.textContent = ( row.ordinal ? row.ordinal + '. ' : '' ) + row.title + ' — ' + row.status;
						button.setAttribute( 'role', 'option' );
						button.addEventListener( 'click', function () {
							hidden.value = String( row.id );
							input.value = row.title;
							submit.disabled = false;
							results.textContent = config.messages.targetChosen + ' ' + row.title;
						} );
						list.appendChild( button );
					} );
					results.appendChild( list );
				} ).catch( function ( error ) {
					results.textContent = error.message || config.messages.failed;
				} );
			}, 250 );
		} );

		form.addEventListener( 'submit', function ( event ) {
			if ( ! hidden.value ) {
				event.preventDefault();
				input.focus();
			}
		} );
	}

	initializeSeriesCombobox();
	initializeDrag();
	Array.prototype.forEach.call( document.querySelectorAll( '[data-wptl-precise-form]' ), initializePreciseSearch );
}( window.WPTitleLayerSequenceManager ) );
