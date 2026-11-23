/**
 * HBL admin notifications + safe AJAX requests.
 *
 *   HBLNotify.success( message, opts )
 *   HBLNotify.info( message, opts )
 *   HBLNotify.warning( message, opts )
 *   HBLNotify.error( message, opts )         // stays until dismissed
 *   HBLNotify.fromError( errOrResponse, prefix )
 *   HBLNotify.fromXhr( jqXHR, textStatus, prefix )   // for jQuery $.ajax / $.post .fail() handlers
 *   HBLNotify.xhrMessage( jqXHR, textStatus )         // same reason as plain text
 *   HBLNotify.request( action, data, opts )  // resolves to the parsed WP JSON
 *   HBLNotify.message( response, fallback )  // readable text from a { success:false } reply
 *   HBLNotify.code( response )               // machine-readable reason, e.g. "rate_limited"
 *
 * toast opts: { title, detail, key, duration (ms, 0 = stay), action: { label, onClick } }
 *
 * request() resolves with the parsed { success, data } for ANY reply the server
 * understood (including { success:false }), so callers branch on res.success.
 * It rejects (Error with .code and .detail) only when the exchange itself failed:
 * offline, timeout, HTTP error, expired session, or a reply that is not JSON.
 */
( function ( w, d ) {
	'use strict';

	var MAX_TOASTS = 5;
	var DEFAULT_MS = { success: 5000, info: 6000, warning: 10000, error: 0 };
	var ICONS      = { success: '✓', info: 'i', warning: '!', error: '!' };
	var stack      = null;

	function ensureStack() {
		if ( stack && d.body.contains( stack ) ) { return stack; }
		stack = d.createElement( 'div' );
		stack.className = 'hbl-notify-stack';
		stack.setAttribute( 'aria-live', 'polite' );
		d.body.appendChild( stack );
		return stack;
	}

	function el( tag, cls, text ) {
		var e = d.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( text !== undefined ) { e.textContent = text; }
		return e;
	}

	function toast( type, message, opts ) {
		opts = opts || {};
		var host = ensureStack();

		// Same key → update in place instead of stacking duplicates (e.g. a batch of identical errors).
		if ( opts.key ) {
			var existing = host.querySelector( '[data-key="' + opts.key + '"]' );
			if ( existing ) {
				var n = ( parseInt( existing.getAttribute( 'data-count' ), 10 ) || 1 ) + 1;
				existing.setAttribute( 'data-count', n );
				var badge = existing.querySelector( '.hbl-notify__count' );
				if ( ! badge ) {
					badge = el( 'span', 'hbl-notify__count' );
					existing.querySelector( '.hbl-notify__text' ).appendChild( badge );
				}
				badge.textContent = ' ×' + n;
				return { close: function () { remove( existing ); } };
			}
		}

		// Keep the stack short: drop the oldest non-error toast first.
		var all = host.querySelectorAll( '.hbl-notify' );
		if ( all.length >= MAX_TOASTS ) {
			var victim = host.querySelector( '.hbl-notify:not(.hbl-notify--error)' ) || all[ 0 ];
			remove( victim );
		}

		var box = el( 'div', 'hbl-notify hbl-notify--' + type );
		box.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		if ( opts.key ) { box.setAttribute( 'data-key', opts.key ); }

		box.appendChild( el( 'span', 'hbl-notify__icon', ICONS[ type ] || 'i' ) );

		var body = el( 'div', 'hbl-notify__body' );
		if ( opts.title ) { body.appendChild( el( 'div', 'hbl-notify__title', opts.title ) ); }
		body.appendChild( el( 'div', 'hbl-notify__text', String( message == null ? '' : message ) ) );

		if ( opts.detail ) {
			var det = el( 'details', 'hbl-notify__detail' );
			det.appendChild( el( 'summary', '', 'Technical details' ) );
			det.appendChild( el( 'pre', '', String( opts.detail ) ) );
			body.appendChild( det );
		}
		if ( opts.action && opts.action.label ) {
			var btn = el( 'button', 'hbl-notify__action', opts.action.label );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				if ( opts.action.onClick ) { opts.action.onClick(); }
				remove( box );
			} );
			body.appendChild( btn );
		}
		box.appendChild( body );

		var close = el( 'button', 'hbl-notify__close', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', 'Dismiss' );
		close.addEventListener( 'click', function () { remove( box ); } );
		box.appendChild( close );

		host.appendChild( box );

		var ms = opts.duration !== undefined ? opts.duration : DEFAULT_MS[ type ];
		var timer = null;
		function arm() { if ( ms ) { timer = setTimeout( function () { remove( box ); }, ms ); } }
		box.addEventListener( 'mouseenter', function () { clearTimeout( timer ); } );
		box.addEventListener( 'mouseleave', arm );
		arm();

		return { close: function () { remove( box ); } };
	}

	function remove( box ) {
		if ( ! box || ! box.parentNode ) { return; }
		box.classList.add( 'hbl-notify--leaving' );
		setTimeout( function () { if ( box.parentNode ) { box.parentNode.removeChild( box ); } }, 180 );
	}

	/* ── Errors ──────────────────────────────────────────────────────── */

	function makeError( message, code, detail, status ) {
		var e = new Error( message );
		e.code   = code || 'error';
		e.detail = detail || '';
		e.status = status || 0;
		e.hbl    = true;
		return e;
	}

	/** Readable text from a WP JSON reply ({ data: "text" } or { data: { message } }). */
	function message( res, fallback ) {
		fallback = fallback || 'Something went wrong. Please try again.';
		if ( ! res ) { return fallback; }
		var data = res.data !== undefined ? res.data : res;
		if ( typeof data === 'string' && data ) { return data; }
		if ( data && typeof data.message === 'string' && data.message ) { return data.message; }
		if ( res instanceof Error && res.message ) { return res.message; }
		return fallback;
	}

	function code( res ) {
		if ( ! res ) { return ''; }
		if ( res.code ) { return res.code; }
		return ( res.data && res.data.code ) || '';
	}

	/** Shows an error toast for a rejected request or a { success:false } reply. */
	function fromError( err, prefix, extra ) {
		var c    = code( err );
		var text = message( err );
		var opts = extra || {};
		opts.key = opts.key || ( 'err-' + c + '-' + text.slice( 0, 40 ) );
		if ( err && err.detail ) { opts.detail = err.detail; }
		if ( c === 'bad_nonce' || c === 'session' || c === 'logged_out' ) {
			opts.action = { label: 'Reload page', onClick: function () { w.location.reload(); } };
		}
		return toast( 'error', ( prefix ? prefix + ' ' : '' ) + text, opts );
	}

	/* ── Requests ────────────────────────────────────────────────────── */

	function wait( ms ) { return new Promise( function ( r ) { setTimeout( r, ms ); } ); }

	/**
	 * POSTs to admin-ajax.php.
	 *
	 * @param {string|null} action  wp_ajax action. Pass null when `data` is a FormData that already carries it.
	 * @param {Object|FormData} data
	 * @param {Object} o  { nonce, ajaxUrl, timeout (ms, default 60000), retries (default 0) }
	 */
	function request( action, data, o ) {
		o = o || {};
		var url = o.ajaxUrl || w.ajaxurl;
		if ( ! url ) {
			return Promise.reject( makeError( 'The page is missing its AJAX address. Reload and try again.', 'config' ) );
		}
		if ( w.navigator && w.navigator.onLine === false ) {
			return Promise.reject( makeError( 'You appear to be offline. Check your connection and try again.', 'offline' ) );
		}

		var fd;
		if ( typeof FormData !== 'undefined' && data instanceof FormData ) {
			fd = data;
		} else {
			fd = new FormData();
			Object.keys( data || {} ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
		}
		if ( action ) { fd.set( 'action', action ); }
		if ( o.nonce ) { fd.set( 'nonce', o.nonce ); }

		var timeout  = o.timeout || 60000;
		var attempts = ( o.retries || 0 ) + 1;

		function once( n ) {
			var ctrl  = w.AbortController ? new AbortController() : null;
			var timer = ctrl ? setTimeout( function () { ctrl.abort(); }, timeout ) : null;

			return fetch( url, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined } )
				.then( function ( r ) {
					clearTimeout( timer );
					return r.text().then( function ( t ) { return { r: r, t: t }; } );
				} )
				.then( function ( x ) { return interpret( x.r, x.t ); } )
				.catch( function ( e ) {
					clearTimeout( timer );
					if ( e && e.hbl ) { throw e; }
					if ( e && e.name === 'AbortError' ) {
						throw makeError( 'The request timed out after ' + Math.round( timeout / 1000 ) + ' seconds. The server or the website being checked may be slow. Try again.', 'timeout' );
					}
					if ( n < attempts ) { return wait( 800 * n ).then( function () { return once( n + 1 ); } ); }
					throw makeError( 'Could not reach the server (' + ( ( e && e.message ) || 'connection failed' ) + '). Check your connection and try again.', 'network' );
				} );
		}
		return once( 1 );
	}

	function interpret( r, text ) {
		var json = null;
		try { json = JSON.parse( text ); } catch ( e ) { json = null; }

		// WordPress answers a bad nonce with a bare "-1", a logged-out request with "0".
		if ( r.status === 403 || ( ! json && ( text === '-1' || text === '0' ) ) ) {
			if ( json && json.data && json.data.message ) { return json; }
			throw makeError( 'Your session has expired or the security token is no longer valid. Reload this page and try again.', 'session', '', r.status );
		}
		if ( json && typeof json === 'object' && 'success' in json ) {
			return json; // Includes { success:false, data:{ message, code } } — caller decides.
		}
		var snippet = String( text || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 );
		if ( ! r.ok ) {
			throw makeError( 'The server returned an error (HTTP ' + r.status + '). ' + ( r.status >= 500 ? 'Check the PHP error log.' : 'Try again.' ), 'http_' + r.status, snippet, r.status );
		}
		throw makeError( 'The server sent a reply this page cannot read. This is usually a PHP error or a plugin printing output. Check the PHP error log.', 'bad_response', snippet, r.status );
	}

	/* ── jQuery helpers ($.ajax / $.post failures) ───────────────────── */

	/** Turns a failed jqXHR into the same Error / reply object request() produces. */
	function xhrError( xhr, textStatus ) {
		if ( ! xhr || ! xhr.status ) {
			if ( textStatus === 'timeout' ) {
				return makeError( 'The request timed out. The server may be busy. Try again.', 'timeout' );
			}
			if ( w.navigator && w.navigator.onLine === false ) {
				return makeError( 'You appear to be offline. Check your connection and try again.', 'offline' );
			}
			return makeError( 'Could not reach the server. Check your connection and try again.', 'network' );
		}
		try {
			return interpret( { status: xhr.status, ok: xhr.status >= 200 && xhr.status < 300 }, xhr.responseText || '' );
		} catch ( e ) {
			return e;
		}
	}

	/** Plain-text reason for a failed jqXHR, for showing inside a tool's own result panel. */
	function xhrMessage( xhr, textStatus ) {
		return message( xhrError( xhr, textStatus ) );
	}

	/** Error toast for a failed jqXHR. Use as: .fail( function ( xhr, status ) { HBLNotify.fromXhr( xhr, status, 'Save failed.' ); } ) */
	function fromXhr( xhr, textStatus, prefix, extra ) {
		return fromError( xhrError( xhr, textStatus ), prefix, extra );
	}

	w.HBLNotify = {
		success:   function ( m, o ) { return toast( 'success', m, o ); },
		info:      function ( m, o ) { return toast( 'info', m, o ); },
		warning:   function ( m, o ) { return toast( 'warning', m, o ); },
		error:     function ( m, o ) { return toast( 'error', m, o ); },
		fromError: fromError,
		fromXhr:   fromXhr,
		xhrMessage: xhrMessage,
		request:   request,
		message:   message,
		code:      code,
	};
}( window, document ) );
