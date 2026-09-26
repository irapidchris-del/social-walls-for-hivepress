/**
 * Share: the Share button, its pop-up and the QR code.
 *
 * SHARED FILE. Social Walls for HivePress and Additional Gallery for HivePress each ship a
 * byte-identical copy at assets/js/share.js. Change one, copy it to the other in the same round,
 * and compare the two (a checksum is enough) before packaging either plugin.
 *
 * Everything this script needs comes from data attributes on the markup the plugin printed, so the
 * copies stay identical and the words stay translatable on the server:
 *
 * - `[data-hp-share="#modal_id"]` on the button, with `data-url` and `data-title`.
 * - `[data-hp-share-copy]` on the Copy link button inside the pop-up, `[data-hp-share-copied]` on
 *   its "Link copied" note.
 * - `[data-hp-share-qr]` on the QR code box, with `data-text` (the address), `data-src` (the
 *   plugin's own bundled QR library), `data-label` (what a screen reader hears) and an optional
 *   `data-logo` (the owner's own image for the middle of the code).
 *
 * Two plugins on one page never run two share systems. The first copy to load claims the page with
 * a `data-hp-share-bound` attribute on <html> and every later copy stands down. The pop-ups carry the
 * shared marker class `hp-share-modal`, which is never styled: if a page somehow holds two, the first
 * is kept and any other, with the buttons that open it, is removed.
 *
 * The QR library is not loaded with the page. It is fetched from the plugin's own folder the first
 * time a pop-up opens, so a visitor who never opens one never downloads it, and nothing is ever
 * requested from anybody else's server.
 */
( function () {
	'use strict';

	var root = document.documentElement;

	if ( root.hasAttribute( 'data-hp-share-bound' ) ) {
		return;
	}

	root.setAttribute( 'data-hp-share-bound', '1' );

	/**
	 * Keeps one share pop-up per page.
	 */
	function claimPage() {
		var modals = document.querySelectorAll( '.hp-share-modal' ),
			i,
			j,
			buttons;

		for ( i = 1; i < modals.length; i++ ) {
			buttons = document.querySelectorAll( '[data-hp-share="#' + modals[ i ].id + '"]' );

			for ( j = 0; j < buttons.length; j++ ) {
				buttons[ j ].parentNode.removeChild( buttons[ j ] );
			}

			modals[ i ].parentNode.removeChild( modals[ i ] );
		}
	}

	/**
	 * Whether the visitor is on a touch device, where the phone's own share sheet is the better
	 * choice. A coarse pointer, not the screen width: a narrow desktop window has no share sheet.
	 *
	 * @return {boolean}
	 */
	function isTouch() {
		return !! ( window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches );
	}

	/**
	 * Loads the bundled QR library once, however many times it is asked for.
	 *
	 * @param {string}   src  Library address.
	 * @param {Function} done Called once the library is ready, with false if it failed.
	 */
	function loadLibrary( src, done ) {
		if ( 'function' === typeof window.qrcode ) {
			done( true );

			return;
		}

		var script = document.querySelector( 'script[data-hp-share-qr-library]' );

		if ( ! script ) {
			script = document.createElement( 'script' );
			script.src = src;
			script.async = true;
			script.setAttribute( 'data-hp-share-qr-library', '1' );
			document.head.appendChild( script );
		}

		script.addEventListener( 'load', function () {
			done( 'function' === typeof window.qrcode );
		} );

		script.addEventListener( 'error', function () {
			done( false );
		} );
	}

	/**
	 * Draws the QR code for one box onto a canvas.
	 *
	 * The white ground, quiet zone included, is painted into the canvas itself rather than left to
	 * CSS, so a dark mode that recolours backgrounds cannot leave dark modules on a dark page, which
	 * phone cameras cannot read. With a logo the code uses the highest error correction (H, about 30%
	 * of the code can be covered), and the logo and its white backing cover under 6% of it.
	 *
	 * @param {Element} box QR code box.
	 */
	function drawCode( box ) {
		var text = box.getAttribute( 'data-text' ) || '',
			logo = box.getAttribute( 'data-logo' ) || '',
			qr,
			count,
			quiet = 4,
			cells,
			ratio = Math.max( 1, Math.min( 3, window.devicePixelRatio || 1 ) ),
			size = 200,
			cell,
			canvas = document.createElement( 'canvas' ),
			context,
			row,
			col;

		try {
			qr = window.qrcode( 0, logo ? 'H' : 'M' );
			qr.addData( text );
			qr.make();
		} catch ( error ) {
			box.setAttribute( 'hidden', '' );

			return;
		}

		count = qr.getModuleCount();
		cells = count + quiet * 2;

		// Whole device pixels per module, and the canvas shown at exactly its own size, so no module
		// is blurred across a pixel boundary by scaling (about 200 CSS pixels, whatever the code's size).
		cell = Math.max( 1, Math.round( ( size * ratio ) / cells ) );

		canvas.width = cell * cells;
		canvas.height = cell * cells;
		canvas.style.width = ( canvas.width / ratio ) + 'px';
		canvas.setAttribute( 'role', 'img' );
		canvas.setAttribute( 'aria-label', box.getAttribute( 'data-label' ) || '' );

		context = canvas.getContext( '2d' );

		if ( ! context ) {
			box.setAttribute( 'hidden', '' );

			return;
		}

		context.fillStyle = '#ffffff';
		context.fillRect( 0, 0, canvas.width, canvas.height );
		context.fillStyle = '#000000';

		for ( row = 0; row < count; row++ ) {
			for ( col = 0; col < count; col++ ) {
				if ( qr.isDark( row, col ) ) {
					context.fillRect( ( col + quiet ) * cell, ( row + quiet ) * cell, cell, cell );
				}
			}
		}

		box.appendChild( canvas );

		if ( logo ) {
			drawLogo( context, canvas.width, count * cell, logo );
		}
	}

	/**
	 * Draws the owner's logo in the middle of the code, on a white square, keeping its proportions.
	 *
	 * @param {CanvasRenderingContext2D} context Canvas context.
	 * @param {number}                   full    Canvas size in pixels.
	 * @param {number}                   code    Size of the code itself, without the quiet zone.
	 * @param {string}                   src     Logo address.
	 */
	function drawLogo( context, full, code, src ) {
		var image = new window.Image();

		image.onload = function () {
			var backing = Math.round( code * 0.24 ),
				pad = Math.round( backing * 0.1 ),
				inner = backing - pad * 2,
				scale = Math.min( inner / image.naturalWidth, inner / image.naturalHeight ),
				width = Math.max( 1, Math.round( image.naturalWidth * scale ) ),
				height = Math.max( 1, Math.round( image.naturalHeight * scale ) ),
				start = Math.round( ( full - backing ) / 2 );

			context.fillStyle = '#ffffff';
			context.fillRect( start, start, backing, backing );
			context.drawImage( image, Math.round( ( full - width ) / 2 ), Math.round( ( full - height ) / 2 ), width, height );
		};

		image.src = src;
	}

	/**
	 * Draws every QR code in a pop-up that has not been drawn yet.
	 *
	 * @param {Element} modal Pop-up.
	 */
	function renderCodes( modal ) {
		var boxes = modal.querySelectorAll( '[data-hp-share-qr]:not([data-hp-share-drawn])' ),
			i;

		if ( ! boxes.length ) {
			return;
		}

		for ( i = 0; i < boxes.length; i++ ) {
			boxes[ i ].setAttribute( 'data-hp-share-drawn', '1' );
		}

		loadLibrary( boxes[ 0 ].getAttribute( 'data-src' ), function ( ready ) {
			for ( i = 0; i < boxes.length; i++ ) {
				if ( ready ) {
					drawCode( boxes[ i ] );
				} else {
					boxes[ i ].setAttribute( 'hidden', '' );
				}
			}
		} );
	}

	/**
	 * Opens a pop-up the way HivePress opens its own (hivepress/assets/js/common.js, Modal).
	 *
	 * @param {Element} modal Pop-up.
	 */
	function openModal( modal ) {
		var $ = window.jQuery;

		if ( $ && $.fancybox ) {
			$.fancybox.close();
			$.fancybox.open( {
				src: '#' + modal.id,
				touch: false,
			} );
		} else {
			modal.style.display = 'block';
		}

		renderCodes( modal );
	}

	/**
	 * Copies text to the clipboard, falling back to the old selection method where the Clipboard
	 * API is missing or refused (it needs a secure connection).
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise<void>}
	 */
	function copyText( text ) {
		function fallback() {
			return new Promise( function ( resolve, reject ) {
				var field = document.createElement( 'textarea' );

				field.value = text;
				field.setAttribute( 'readonly', '' );
				field.style.position = 'fixed';
				field.style.top = '0';
				field.style.left = '0';
				field.style.opacity = '0';
				document.body.appendChild( field );
				field.select();

				try {
					if ( document.execCommand( 'copy' ) ) {
						resolve();
					} else {
						reject( new Error( 'copy refused' ) );
					}
				} catch ( error ) {
					reject( error );
				}

				document.body.removeChild( field );
			} );
		}

		if ( navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( fallback );
		}

		return fallback();
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target && event.target.closest ? event.target : null,
			button = target ? target.closest( '[data-hp-share]' ) : null,
			copy = target ? target.closest( '[data-hp-share-copy]' ) : null,
			modal,
			note;

		if ( button ) {
			modal = document.querySelector( button.getAttribute( 'data-hp-share' ) );

			if ( ! modal ) {
				return;
			}

			event.preventDefault();

			if ( navigator.share && isTouch() ) {
				navigator.share( {
					title: button.getAttribute( 'data-title' ) || document.title,
					url: button.getAttribute( 'data-url' ) || window.location.href,
				} ).catch( function ( error ) {

					// Closing the share sheet is a choice, not a failure: nothing more to do. Any other
					// refusal (no permission, nothing to share to) falls back to the pop-up.
					if ( ! error || 'AbortError' !== error.name ) {
						openModal( modal );
					}
				} );

				return;
			}

			openModal( modal );

			return;
		}

		if ( copy ) {
			event.preventDefault();

			modal = copy.closest( '.hp-share-modal' ) || document;
			note = modal.querySelector( '[data-hp-share-copied]' );

			copyText( copy.getAttribute( 'data-hp-share-copy' ) || '' ).then( function () {
				if ( note ) {
					note.hidden = false;

					window.clearTimeout( note.hpShareTimer );
					note.hpShareTimer = window.setTimeout( function () {
						note.hidden = true;
					}, 4000 );
				}
			} ).catch( function () {} );
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', claimPage );
	} else {
		claimPage();
	}
}() );
