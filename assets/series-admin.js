( function () {
	'use strict';

	function initializeCoverControl( control ) {
		var input = control.querySelector( '.wptl-cover-id-input' );
		var choose = control.querySelector( '.wptl-select-cover' );
		var remove = control.querySelector( '.wptl-remove-cover' );
		var preview = control.querySelector( '[data-wptl-cover-preview]' );
		if ( ! input || ! choose || ! remove || ! preview || ! window.wp || ! wp.media ) {
			return;
		}

		// Progressive enhancement: keep a numeric fallback when the media frame
		// is unavailable, but hide it once WordPress media is ready.
		input.type = 'hidden';
		control.classList.add( 'is-media-ready' );

		choose.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			var frame = wp.media( {
				title: choose.textContent,
				button: { text: choose.textContent },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first();
				attachment = attachment && attachment.toJSON ? attachment.toJSON() : null;
				if ( ! attachment || ! attachment.id || ! attachment.url ) {
					return;
				}

				var source = attachment.sizes && attachment.sizes.thumbnail
					? attachment.sizes.thumbnail.url
					: attachment.url;
				var image = document.createElement( 'img' );
				image.className = 'wptl-cover-preview__image';
				image.src = source;
				image.alt = attachment.alt || '';
				preview.replaceChildren( image );
				input.value = String( attachment.id );
				remove.hidden = false;
			} );

			frame.open();
		} );

		remove.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			input.value = '0';
			preview.replaceChildren();
			remove.hidden = true;
		} );
	}

	Array.prototype.forEach.call(
		document.querySelectorAll( '[data-wptl-cover-control]' ),
		initializeCoverControl
	);

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.wptl-add-season-row' ) : null;
		if ( ! button ) {
			return;
		}

		var wrapper = button.closest( '.form-field' );
		var table = wrapper ? wrapper.querySelector( '.wptl-season-table' ) : null;
		var body = table ? table.querySelector( 'tbody' ) : null;
		var rows = body ? body.querySelectorAll( '.wptl-season-row' ) : [];
		if ( ! body || ! rows.length ) {
			return;
		}

		event.preventDefault();
		var index = parseInt( table.getAttribute( 'data-next-index' ), 10 );
		if ( ! Number.isFinite( index ) || index < 0 ) {
			index = rows.length;
		}

		var row = rows[ rows.length - 1 ].cloneNode( true );
		Array.prototype.forEach.call( row.querySelectorAll( 'input' ), function ( input ) {
			input.name = input.name.replace( /\[\d+\](?=\[(?:key|label|sort|public_id)\]$)/, '[' + index + ']' );
			if ( /\[sort\]$/.test( input.name ) ) {
				input.value = String( ( index + 1 ) * 10 );
			} else {
				input.value = '';
			}
		} );
		var publicId = row.querySelector( '.wptl-season-public-id' );
		if ( publicId ) {
			publicId.textContent = '—';
		}

		body.appendChild( row );
		table.setAttribute( 'data-next-index', String( index + 1 ) );
		var label = row.querySelector( 'input[type="text"]' );
		if ( label ) {
			label.focus();
		}
	} );
}() );
