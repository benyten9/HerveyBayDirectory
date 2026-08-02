( function () {
	'use strict';

	var data = window.doublescaleBookingCancel || {};
	var button = document.getElementById( 'cancel_booking_button' );
	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function ( event ) {
		event.preventDefault();

		if ( ! data.canCancel ) {
			return;
		}

		var textarea = document.getElementById( 'cancellation_reason' );
		var validation = document.getElementById( 'validation_message' );
		var buttonText = document.getElementById( 'button_text' );
		var loadingSpinner = document.getElementById( 'loading_spinner' );

		if ( validation ) {
			validation.textContent = '';
		}

		if (
			textarea &&
			! textarea.value.trim() &&
			data.reasonRequired &&
			data.reasonEnabled
		) {
			validation.textContent = data.i18n.required;
			textarea.classList.add( 'error' );
			return;
		}

		button.disabled = true;
		buttonText.style.display = 'none';
		loadingSpinner.style.display = 'inline-flex';
		loadingSpinner.style.alignItems = 'center';

		var formData = new FormData();
		formData.append( 'action', 'doublescale_booking_cancel_booking' );
		formData.append( 'nonce', data.nonce );
		formData.append( 'id', data.hashId );
		if ( textarea ) {
			formData.append( 'cancellation_reason', textarea.value );
		}

		fetch( data.ajaxUrl, {
			method: 'POST',
			body: formData,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				if ( response.success ) {
					document.querySelector(
						'.cancellation-container'
					).hidden = true;

					var successDiv =
						document.getElementById( 'success_message' );
					successDiv.textContent = data.i18n.success;
					successDiv.hidden = false;
				} else {
					button.disabled = false;
					buttonText.style.display = 'inline';
					loadingSpinner.style.display = 'none';

					validation.textContent =
						response.message || data.i18n.genericError;
					textarea.classList.add( 'error' );
				}
			} )
			.catch( function () {
				button.disabled = false;
				buttonText.style.display = 'inline';
				loadingSpinner.style.display = 'none';

				validation.textContent = data.i18n.networkError;
				textarea.classList.add( 'error' );
			} );
	} );
} )();
