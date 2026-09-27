/**
 * Social Walls for HivePress: front-end behaviour.
 *
 * Vanilla JS, no dependencies beyond the REST address and nonce HivePress already puts on the page
 * (hivepressCoreData, localised on the hivepress-core script). Everything a member typed reaches the
 * page as server-rendered, escaped markup; this file only ever writes text with textContent.
 *
 * Handlers are delegated from the document, so walls a theme or another plugin inserts later work
 * without being initialised again.
 */
( function () {
	'use strict';

	var data = window.hpswFrontendData || {};

	/**
	 * Sends a request to one of this plugin's HivePress REST routes.
	 *
	 * The API root comes from HivePress, which already accounts for plain permalinks (where it is a
	 * `?rest_route=` address rather than a path). DELETE travels as a POST with core's own method
	 * override header, exactly as core's form script sends it (assets/js/common.js), because some
	 * hosts refuse the DELETE verb outright.
	 *
	 * @param {string} path   Route path, starting with a slash.
	 * @param {string} method HTTP method.
	 * @return {Promise<Response>}
	 */
	function apiFetch( path, method ) {
		var core = window.hivepressCoreData || {},
			base = core.apiURL ? String( core.apiURL ).replace( /\/$/, '' ) : '';

		if ( ! base ) {
			return Promise.reject( new Error( 'no api url' ) );
		}

		var headers = { 'X-WP-Nonce': core.apiNonce || '' };

		if ( 'POST' !== method ) {
			headers[ 'X-HTTP-Method-Override' ] = method;
		}

		return window.fetch( base + path, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
		} );
	}

	/**
	 * Formats a count the way the page does.
	 *
	 * @param {number} value Count.
	 * @return {string}
	 */
	function formatCount( value ) {
		try {
			return Number( value ).toLocaleString( document.documentElement.lang || undefined );
		} catch ( e ) {
			return String( value );
		}
	}

	/* ------------------------------------------------------------------
	 * Likes.
	 * ------------------------------------------------------------------ */
	function toggleLike( button ) {
		if ( button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}

		var id = button.getAttribute( 'data-hpsw-like' );

		button.setAttribute( 'aria-busy', 'true' );

		apiFetch( '/hpsw-posts/' + encodeURIComponent( id ) + '/like', 'POST' )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.body || ! result.body.data ) {
					throw new Error( 'like failed' );
				}

				var liked = !! result.body.data.liked;

				// Every copy of this post's heart on the page, not only the one pressed: a post can
				// appear on a wall and in a sidebar at once.
				document.querySelectorAll( '[data-hpsw-like="' + id + '"]' ).forEach( function ( heart ) {
					var count = heart.querySelector( '[data-hpsw-like-count]' );

					heart.classList.toggle( 'is-liked', liked );
					heart.setAttribute( 'aria-pressed', liked ? 'true' : 'false' );

					if ( count ) {
						count.textContent = formatCount( result.body.data.count || 0 );
					}
				} );
			} )
			.catch( function () {
				window.alert( data.likeFailed || 'Your like could not be saved.' );
			} )
			.then( function () {
				button.removeAttribute( 'aria-busy' );
			} );
	}

	/* ------------------------------------------------------------------
	 * Coupon codes.
	 *
	 * The copying itself is core's `copy` component on the code element; this only confirms it. The
	 * Copy button's label reads "Copied", with a tick for its icon, for two seconds, and the row's status region (a
	 * screen-reader-only role="status" span) says the same, because a changed button label is not
	 * announced on its own. The button clicks the code, so both routes copy the same way.
	 * ------------------------------------------------------------------ */
	function showCopied( coupon ) {
		if ( ! coupon ) {
			return;
		}

		var label = coupon.querySelector( '[data-hpsw-copy-label]' ),
			status = coupon.querySelector( '[data-hpsw-copy-status]' ),
			text = data.copied || 'Copied';

		if ( status ) {
			// Emptied first so a second copy is announced again.
			status.textContent = '';

			window.setTimeout( function () {
				status.textContent = text;
			}, 50 );
		}

		if ( ! label ) {
			return;
		}

		if ( ! label.hasAttribute( 'data-hpsw-label' ) ) {
			label.setAttribute( 'data-hpsw-label', label.textContent );
		}

		// The button's copy icon turns into a tick for as long as the label reads "Copied".
		var button = coupon.querySelector( '[data-hpsw-copy-button]' );

		label.textContent = text;

		if ( button ) {
			button.classList.add( 'is-copied' );
		}

		window.clearTimeout( label.hpswTimer );

		label.hpswTimer = window.setTimeout( function () {
			label.textContent = label.getAttribute( 'data-hpsw-label' );

			if ( button ) {
				button.classList.remove( 'is-copied' );
			}

			if ( status ) {
				status.textContent = '';
			}
		}, 2000 );
	}

	/* ------------------------------------------------------------------
	 * Comment replies.
	 * ------------------------------------------------------------------ */
	function startReply( button ) {
		var section = button.closest( '[data-hpsw-comments]' ),
			form = section ? section.querySelector( 'form' ) : null;

		if ( ! form ) {
			return;
		}

		var parent = form.querySelector( '[data-hpsw-parent]' ),
			cancel = form.querySelector( '[data-hpsw-reply-cancel]' ),
			text = form.querySelector( 'textarea' );

		if ( parent ) {
			parent.value = button.getAttribute( 'data-hpsw-reply' ) || '';
		}

		if ( cancel ) {
			var label = cancel.querySelector( 'span' );

			if ( label ) {
				label.textContent = ( button.getAttribute( 'data-hpsw-reply-name' ) || '' ) ? '@' + button.getAttribute( 'data-hpsw-reply-name' ) : '';
			}

			cancel.hidden = false;
		}

		if ( text ) {
			text.focus();
		}

		form.scrollIntoView( { block: 'center', behavior: 'smooth' } );
	}

	function cancelReply( button ) {
		var form = button.closest( 'form' ),
			parent = form ? form.querySelector( '[data-hpsw-parent]' ) : null;

		if ( parent ) {
			parent.value = '';
		}

		button.hidden = true;
	}

	/* ------------------------------------------------------------------
	 * Comment deletion.
	 * ------------------------------------------------------------------ */
	function deleteComment( button ) {
		if ( ! window.confirm( data.deleteConfirm || 'Delete this comment?' ) ) {
			return;
		}

		button.disabled = true;

		apiFetch( '/hpsw-comments/' + encodeURIComponent( button.getAttribute( 'data-hpsw-comment-delete' ) ), 'DELETE' )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'delete failed' );
				}

				window.location.reload();
			} )
			.catch( function () {
				button.disabled = false;

				window.alert( data.deleteFailed || 'The comment could not be deleted.' );
			} );
	}

	/* ------------------------------------------------------------------
	 * The post form: Deal-only fields follow the post type.
	 * ------------------------------------------------------------------ */
	function syncDealFields( form ) {
		var checked = form.querySelector( 'input[name="type"]:checked' ),
			isDeal = checked && 'deal' === checked.value;

		form.querySelectorAll( '[data-hpsw-deal]' ).forEach( function ( input ) {
			var field = input.closest( '.hp-form__field' );

			if ( field ) {
				field.hidden = ! isDeal;
			}
		} );
	}

	function initForms() {
		document.querySelectorAll( 'form.hp-form--hpsw-post-update' ).forEach( function ( form ) {
			syncDealFields( form );

			form.addEventListener( 'change', function ( event ) {
				if ( event.target && 'type' === event.target.name ) {
					syncDealFields( form );
				}
			} );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var like = target.closest( 'button[data-hpsw-like]' );

		if ( like ) {
			event.preventDefault();
			toggleLike( like );

			return;
		}

		var copyButton = target.closest( '[data-hpsw-copy-button]' );

		if ( copyButton ) {
			var coupon = copyButton.closest( '.hpsw-post__coupon' ),
				code = coupon ? coupon.querySelector( '[data-hpsw-copy]' ) : null;

			if ( code ) {
				code.click();

				// Core's copy selects a temporary input and removes it (hivepress/assets/js/common.js,
				// `copy`), which leaves keyboard focus on the page body. Put it back on the button.
				copyButton.focus();
			}

			return;
		}

		var copyCode = target.closest( '[data-hpsw-copy]' );

		if ( copyCode ) {
			showCopied( copyCode.closest( '.hpsw-post__coupon' ) );

			return;
		}

		var reply = target.closest( '[data-hpsw-reply]' );

		if ( reply ) {
			event.preventDefault();
			startReply( reply );

			return;
		}

		var cancel = target.closest( '[data-hpsw-reply-cancel]' );

		if ( cancel ) {
			event.preventDefault();
			cancelReply( cancel );

			return;
		}

		var remove = target.closest( '[data-hpsw-comment-delete]' );

		if ( remove ) {
			event.preventDefault();
			deleteComment( remove );
		}
	} );

	/* ------------------------------------------------------------------
	 * The wall filter's location box when the Geolocation scripts are missing.
	 *
	 * The box is the HivePress Geolocation extension's own Location field, and its suggestions and
	 * "Locate Me" link are driven by that extension's script (and Geolocation Plus's). A site can
	 * dequeue them, for example by dequeuing the Mapbox library (hivepress-geolocation depends on
	 * mapbox-language, and WordPress never prints a script whose dependency is gone); "Locate Me",
	 * a bare <a href="#">, then only added "#" to the address. The box still works as a plain text
	 * search, and the browser's own geolocation can still locate the visitor. So, only when
	 * `hivepress.initGeolocation` is absent once the page has loaded, the link fills the form's
	 * coordinate fields itself, and typing clears them so a typed place is searched as text.
	 * ------------------------------------------------------------------ */
	function initLocationFallback() {
		if ( window.hivepress && 'function' === typeof window.hivepress.initGeolocation ) {
			return;
		}

		var fields = document.querySelectorAll( '.hpsw-filter [data-component="location"]' );

		if ( ! fields.length ) {
			return;
		}

		if ( window.console && window.console.warn ) {
			window.console.warn( 'Social Walls: the HivePress Geolocation scripts are not on this page, so the wall location filter offers no suggestions. Check for a plugin or snippet that dequeues them.' );
		}

		fields.forEach( function ( field ) {
			var form = field.closest( 'form' ),
				input = field.querySelector( 'input[type="text"]' ),
				button = field.querySelector( 'a' );

			if ( ! form || ! input ) {
				return;
			}

			var clear = function () {
				form.querySelectorAll( 'input[data-coordinate], input[data-region]' ).forEach( function ( coordinate ) {
					coordinate.value = '';
				} );
			};

			input.addEventListener( 'input', clear );

			if ( ! button ) {
				return;
			}

			if ( ! navigator.geolocation ) {
				button.style.display = 'none';

				return;
			}

			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				navigator.geolocation.getCurrentPosition( function ( position ) {
					var lat = form.querySelector( 'input[data-coordinate="lat"]' ),
						lng = form.querySelector( 'input[data-coordinate="lng"]' );

					if ( ! lat || ! lng ) {
						return;
					}

					clear();

					lat.value = position.coords.latitude;
					lng.value = position.coords.longitude;
					input.value = data.myLocation || 'My location';
				} );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initForms );
	} else {
		initForms();
	}

	// After every footer script has run, so the Geolocation script has had its chance to register.
	if ( 'complete' === document.readyState ) {
		initLocationFallback();
	} else {
		window.addEventListener( 'load', initLocationFallback );
	}
}() );
