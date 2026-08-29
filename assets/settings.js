( function () {
	'use strict';

	function ready() {
		var root = document.querySelector( '[data-wptl-settings-tabs]' );
		if ( ! root ) {
			return;
		}

		var tablist = root.querySelector( '[data-wptl-settings-tablist]' );
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '[data-wptl-settings-tab]' ) );
		var panels = Array.prototype.slice.call( root.querySelectorAll( '[data-wptl-settings-panel]' ) );
		if ( ! tablist || tabs.length < 2 || tabs.length !== panels.length ) {
			return;
		}

		function tabForPanelId( panelId ) {
			return tabs.find( function ( tab ) {
				return tab.getAttribute( 'aria-controls' ) === panelId;
			} );
		}

		function activate( activeTab, updateHash, moveFocus ) {
			if ( ! activeTab ) {
				return;
			}

			tabs.forEach( function ( tab ) {
				var selected = tab === activeTab;
				tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				tab.tabIndex = selected ? 0 : -1;
			} );
			panels.forEach( function ( panel ) {
				panel.hidden = panel.id !== activeTab.getAttribute( 'aria-controls' );
			} );

			if ( updateHash && window.history && typeof window.history.replaceState === 'function' ) {
				window.history.replaceState( null, '', '#' + encodeURIComponent( activeTab.getAttribute( 'aria-controls' ) ) );
			}
			if ( moveFocus ) {
				activeTab.focus();
			}
		}

		tablist.setAttribute( 'role', 'tablist' );
		tabs.forEach( function ( tab ) {
			tab.setAttribute( 'role', 'tab' );
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activate( tab, true, false );
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				var index = tabs.indexOf( tab );
				var next = null;
				if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) {
					next = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) {
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( event.key === 'Home' ) {
					next = tabs[ 0 ];
				} else if ( event.key === 'End' ) {
					next = tabs[ tabs.length - 1 ];
				}
				if ( next ) {
					event.preventDefault();
					activate( next, true, true );
				}
			} );
		} );
		panels.forEach( function ( panel ) {
			panel.setAttribute( 'role', 'tabpanel' );
			panel.setAttribute( 'aria-labelledby', panel.getAttribute( 'data-wptl-settings-tab-label' ) || '' );
		} );

		root.classList.add( 'wptl-settings--tabs-ready' );
		var initialId = '';
		try {
			initialId = decodeURIComponent( window.location.hash.replace( /^#/, '' ) );
		} catch ( error ) {
			initialId = '';
		}
		activate( tabForPanelId( initialId ) || tabs[ 0 ], false, false );

		window.addEventListener( 'hashchange', function () {
			var panelId = '';
			try {
				panelId = decodeURIComponent( window.location.hash.replace( /^#/, '' ) );
			} catch ( error ) {
				panelId = '';
			}
			var tab = tabForPanelId( panelId );
			if ( tab ) {
				activate( tab, false, false );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
}() );
