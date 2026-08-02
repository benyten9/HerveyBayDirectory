/**
 * SEOPress Redirections — Real-time validation.
 *
 * Validates origin and destination URL fields on the seopress_404 CPT editor.
 *
 * @since 9.8
 */
(function () {
	'use strict';

	var titleField = document.getElementById( 'title' );
	if ( ! titleField ) {
		return;
	}

	var ajaxUrl = typeof seopressRedirectionsValidation !== 'undefined' ? seopressRedirectionsValidation.ajaxUrl : '';
	var nonce   = typeof seopressRedirectionsValidation !== 'undefined' ? seopressRedirectionsValidation.nonce : '';
	var i18n    = typeof seopressRedirectionsValidation !== 'undefined' ? seopressRedirectionsValidation.i18n : {};
	var postId  = typeof seopressRedirectionsValidation !== 'undefined' ? seopressRedirectionsValidation.postId : 0;

	var noticeContainer = null;

	/**
	 * Create or get the notice container.
	 */
	function getNoticeContainer() {
		if ( noticeContainer ) {
			return noticeContainer;
		}

		noticeContainer = document.createElement( 'div' );
		noticeContainer.id = 'seopress-redirections-notices';
		noticeContainer.style.marginTop = '8px';

		var titleWrap = document.getElementById( 'titlewrap' );
		if ( titleWrap ) {
			titleWrap.parentNode.insertBefore( noticeContainer, titleWrap.nextSibling );
		}

		return noticeContainer;
	}

	/**
	 * Show a notice.
	 *
	 * @param {string} key     Unique key for the notice.
	 * @param {string} message The message.
	 * @param {string} type    'warning' or 'error'.
	 */
	function showNotice( key, message, type ) {
		var container = getNoticeContainer();
		var existing  = document.getElementById( 'seopress-redir-notice-' + key );

		if ( existing ) {
			existing.innerHTML = '<p>' + message + '</p>';
			return;
		}

		var notice       = document.createElement( 'div' );
		notice.id        = 'seopress-redir-notice-' + key;
		notice.className = 'notice notice-' + type + ' inline';
		notice.style.margin = '4px 0';
		notice.innerHTML = '<p>' + message + '</p>';

		container.appendChild( notice );
	}

	/**
	 * Remove a notice by key.
	 *
	 * @param {string} key Unique key.
	 */
	function removeNotice( key ) {
		var existing = document.getElementById( 'seopress-redir-notice-' + key );
		if ( existing ) {
			existing.remove();
		}
	}

	/**
	 * Validate the origin URL (title field).
	 *
	 * @param {string} value The current value.
	 */
	function validateOrigin( value ) {
		if ( ! value ) {
			removeNotice( 'full-url' );
			removeNotice( 'spaces' );
			removeNotice( 'anchor' );
			return;
		}

		// Full URL detected.
		if ( /^https?:\/\//i.test( value ) ) {
			showNotice( 'full-url', i18n.fullUrl || 'Enter a relative path (e.g. <code>/old-page</code>), not a full URL. The domain will be stripped automatically.', 'warning' );
		} else {
			removeNotice( 'full-url' );
		}

		// Spaces.
		if ( /\s/.test( value ) ) {
			showNotice( 'spaces', i18n.spaces || 'URLs should not contain spaces. Use <code>%20</code> or <code>-</code> instead.', 'warning' );
		} else {
			removeNotice( 'spaces' );
		}

		// Anchor.
		if ( /#/.test( value ) ) {
			showNotice( 'anchor', i18n.anchor || 'Anchors (<code>#</code>) are not sent by browsers and will be ignored.', 'warning' );
		} else {
			removeNotice( 'anchor' );
		}
	}

	var siteHost = typeof seopressRedirectionsValidation !== 'undefined' ? seopressRedirectionsValidation.siteHost : '';

	/**
	 * Extract path from a URL or relative path, stripping the site domain if present.
	 *
	 * @param {string} value The URL or path.
	 * @return {string|null} The cleaned path, or null if external domain.
	 */
	function extractLocalPath( value ) {
		if ( ! value ) {
			return null;
		}

		var match = value.match( /^https?:\/\/([^/]+)(\/.*)?$/i );
		if ( match ) {
			// Full URL — check if it's the same domain.
			var host = match[1].toLowerCase().replace( /:\d+$/, '' );
			var localHost = siteHost.toLowerCase().replace( /:\d+$/, '' );

			if ( host !== localHost ) {
				return null; // External domain — no loop possible.
			}

			return ( match[2] || '/' ).replace( /^\/+/, '' ).replace( /\/+$/, '' );
		}

		// Relative path.
		return value.replace( /^\/+/, '' ).replace( /\/+$/, '' );
	}

	/**
	 * Validate origin vs destination (loop detection).
	 */
	function validateLoop() {
		var origin = extractLocalPath( titleField.value );
		var destField = document.querySelector( 'input[name="seopress_redirections_value"], input[id*="seopress_redirections_value"]' );

		if ( ! destField || ! destField.value ) {
			removeNotice( 'loop' );
			return;
		}

		var dest = extractLocalPath( destField.value );

		if ( null === dest ) {
			// External destination — no loop possible.
			removeNotice( 'loop' );
			return;
		}

		if ( origin && dest && origin === dest ) {
			showNotice( 'loop', i18n.loop || 'Origin and destination are identical. This will cause a redirect loop.', 'error' );
		} else {
			removeNotice( 'loop' );
		}
	}

	/**
	 * Check for duplicate origin URL via AJAX.
	 *
	 * @param {string} value The origin URL.
	 */
	function checkDuplicate( value ) {
		if ( ! value || ! ajaxUrl || ! nonce ) {
			removeNotice( 'duplicate' );
			removeNotice( 'chain' );
			return;
		}

		// Clean origin: strip domain, leading slash.
		var cleanOrigin = value.replace( /^https?:\/\/[^/]+/i, '' ).replace( /^\/+/, '' );

		if ( ! cleanOrigin ) {
			removeNotice( 'duplicate' );
			removeNotice( 'chain' );
			return;
		}

		var formData = new FormData();
		formData.append( 'action', 'seopress_check_redirection' );
		formData.append( '_ajax_nonce', nonce );
		formData.append( 'origin', cleanOrigin );
		formData.append( 'post_id', postId );

		fetch( ajaxUrl, { method: 'POST', body: formData } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( result ) {
				if ( result.success && result.data ) {
					// Duplicate check.
					if ( result.data.duplicate ) {
						showNotice(
							'duplicate',
							( i18n.duplicate || 'A redirection already exists for this URL.' ) +
							' <a href="' + result.data.duplicate.edit_url + '">' +
							( i18n.duplicateEdit || 'Edit existing' ) + '</a>',
							'error'
						);
					} else {
						removeNotice( 'duplicate' );
					}

					// Chain check.
					if ( result.data.chain ) {
						showNotice(
							'chain',
							( i18n.chain || 'Warning: this destination is already redirected.' ) +
							' ' + result.data.chain.origin + ' → <strong>' + result.data.chain.destination + '</strong>. ' +
							( i18n.chainAdvice || 'Consider redirecting directly to the final destination.' ),
							'warning'
						);
					} else {
						removeNotice( 'chain' );
					}
				} else {
					removeNotice( 'duplicate' );
					removeNotice( 'chain' );
				}
			} )
			.catch( function () {
				removeNotice( 'duplicate' );
				removeNotice( 'chain' );
			} );
	}

	var debounceTimer = null;

	// Origin field: validate on input, check duplicate on blur.
	titleField.addEventListener( 'input', function () {
		validateOrigin( this.value );
		validateLoop();
	} );

	titleField.addEventListener( 'blur', function () {
		clearTimeout( debounceTimer );
		debounceTimer = setTimeout( function () {
			checkDuplicate( titleField.value );
		}, 300 );
	} );

	// Destination field: validate loop on change.
	document.addEventListener( 'change', function ( e ) {
		if ( e.target && ( e.target.name === 'seopress_redirections_value' || ( e.target.id && e.target.id.indexOf( 'seopress_redirections_value' ) !== -1 ) ) ) {
			validateLoop();
		}
	} );

	// Also check destination input events.
	document.addEventListener( 'input', function ( e ) {
		if ( e.target && ( e.target.name === 'seopress_redirections_value' || ( e.target.id && e.target.id.indexOf( 'seopress_redirections_value' ) !== -1 ) ) ) {
			validateLoop();
		}
	} );

	// Initial validation on page load.
	if ( titleField.value ) {
		validateOrigin( titleField.value );
	}
})();
