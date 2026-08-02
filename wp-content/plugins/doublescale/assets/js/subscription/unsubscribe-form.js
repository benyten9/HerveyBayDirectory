( function () {
	'use strict';

	var data = window.doublescaleUnsubscribeForm || {};
	var form = document.getElementById( 'doublescale-unsubscribe-form' );
	if ( ! form ) {
		return;
	}

	var unsubscribeAll = document.getElementById( 'unsubscribe_all' );
	var reasonField = document.getElementById( 'reason-field' );

	function toggleReasonField() {
		if ( ! reasonField || ! unsubscribeAll ) {
			return;
		}

		reasonField.style.display = unsubscribeAll.checked ? 'block' : 'none';
	}

	if ( unsubscribeAll ) {
		unsubscribeAll.addEventListener( 'change', toggleReasonField );
		toggleReasonField();
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var button = form.querySelector( 'button[type="submit"]' );
		var errorDiv = document.getElementById( 'error-message' );
		var formData = new FormData( form );

		formData.delete( 'list_status[]' );

		var listCheckboxes = form.querySelectorAll( '.list-preference-checkbox' );
		listCheckboxes.forEach( function ( checkbox ) {
			var listId = checkbox.getAttribute( 'data-list-id' );
			if ( ! listId ) {
				return;
			}

			formData.append(
				'list_status[' + listId + ']',
				checkbox.checked ? 'subscribed' : 'unsubscribed'
			);
		} );

		if ( unsubscribeAll && ! unsubscribeAll.checked ) {
			formData.delete( 'unsubscribe_all' );
		}

		button.disabled = true;
		button.textContent = data.i18n.processing;
		errorDiv.classList.remove( 'show' );

		fetch( data.ajaxUrl, {
			method: 'POST',
			body: formData,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				if ( response.success && response.data && response.data.html ) {
					document.open();
					document.write( response.data.html );
					document.close();
				} else if ( response.success ) {
					alert( response.data.message );
					window.location.reload();
				} else {
					errorDiv.textContent =
						( response.data && response.data.message ) ||
						data.i18n.genericError;
					errorDiv.classList.add( 'show' );
					button.disabled = false;
					button.textContent = data.i18n.confirm;
				}
			} )
			.catch( function () {
				errorDiv.textContent = data.i18n.genericError;
				errorDiv.classList.add( 'show' );
				button.disabled = false;
				button.textContent = data.i18n.confirm;
			} );
	} );
} )();
