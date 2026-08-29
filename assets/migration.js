( function ( config ) {
	'use strict';

	if ( ! config || ! config.ajaxUrl || ! config.nonce ) {
		return;
	}

	function setText( row, field, value ) {
		var target = row.querySelector( '[data-wptl-field="' + field + '"]' );
		if ( target ) {
			target.textContent = String( value );
		}
	}

	function updateRow( row, state ) {
		setText( row, 'status', state.status );
		setText( row, 'processed', state.counts.processed );
		setText( row, 'migrated', state.counts.migrated );
		setText( row, 'conflict', state.counts.conflict );
		setText( row, 'batch', state.counts.batches );
		var exportForm = row.querySelector( '.wptl-export-conflicts' );
		if ( exportForm ) {
			exportForm.hidden = ! (
				state.counts.conflict > 0 &&
				( state.status === 'complete' || state.status === 'rolled_back' )
			);
		}
	}

	function requestBatch( runId ) {
		var body = new window.URLSearchParams();
		body.set( 'action', 'wptl_migration_process_batch' );
		body.set( 'nonce', config.nonce );
		body.set( 'run_id', runId );

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			if ( ! payload.success ) {
				var details = payload.data || {};
				throw new Error( details.message || details.code || config.text.failed );
			}
			return payload.data;
		} );
	}

	function runAll( button ) {
		if ( button.dataset.running === '1' ) {
			return;
		}

		var runId = button.dataset.runId || '';
		var row = document.querySelector( '[data-wptl-run-id="' + runId + '"]' );
		if ( ! row ) {
			return;
		}

		var status = row.querySelector( '[data-wptl-live-status]' );
		button.dataset.running = '1';
		button.disabled = true;
		button.textContent = config.text.running;
		if ( status ) {
			status.textContent = config.text.running;
		}

		function next() {
			requestBatch( runId ).then( function ( state ) {
				updateRow( row, state );
				if ( state.status === 'complete' ) {
					button.hidden = true;
					if ( status ) {
						status.textContent = config.text.complete;
					}
					return;
				}
				window.setTimeout( next, 75 );
			} ).catch( function ( error ) {
				button.dataset.running = '0';
				button.disabled = false;
				button.textContent = config.text.continue;
				if ( status ) {
					status.textContent = error.message || config.text.failed;
				}
			} );
		}

		next();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var buttons = document.querySelectorAll( '.wptl-run-all' );
		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () { runAll( button ); } );
		} );

		if ( config.autoRunId ) {
			var automatic = document.querySelector( '.wptl-run-all[data-run-id="' + config.autoRunId + '"]' );
			if ( automatic ) {
				runAll( automatic );
			}
		}
	} );
} )( window.WPTitleLayerMigration );
