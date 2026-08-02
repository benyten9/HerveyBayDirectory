//Reset License
jQuery(document).ready(function($) {
	$('#seopress_pro_license_reset').on('click', function() {
		$.ajax({
			method : 'GET',
			url : seopressAjaxResetLicense.seopress_request_reset_license,
			data : {
				action: 'seopress_request_reset_license',
				_ajax_nonce: seopressAjaxResetLicense.seopress_nonce,
			},
			success : function( data ) {
				var url_location = data.data.url;
				if ($(location).attr('href') == url_location) {
					window.location.reload(true);
				} else {
					$(location).attr('href',url_location);
				}
			},
		});
	});
	$('#seopress_pro_license_reset').on('click', function() {
		$(this).attr("disabled", "disabled");
		$( '.spinner2' ).css( "visibility", "visible" );
		$( '.spinner2' ).css( "float", "none" );
	});

	// Activate/Deactivate License button loading state
	$('form.seopress-option').on('submit', function() {
		var $btn = $(this).find('#seopress-edd-license-btn');
		var $spinner = $(this).find('.seopress-license-actions .spinner');

		// Don't prevent default - let form submit naturally
		setTimeout(function() {
			$btn.prop('disabled', true);
			$spinner.css('visibility', 'visible');
		}, 10);
	});

	// Copy to clipboard
	$('.seopress-license-copy-btn').on('click', function() {
		var $btn = $(this);
		var $code = $('#seopress-license-code');

		// Create a temporary textarea to copy from
		var $temp = $('<textarea>');
		$('body').append($temp);
		$temp.val($code.text()).select();
		document.execCommand('copy');
		$temp.remove();

		// Show "Copied!" feedback
		$btn.addClass('copied');
		$btn.find('.copy-text').hide();
		$btn.find('.copied-text').show();

		// Reset after 2 seconds
		setTimeout(function() {
			$btn.removeClass('copied');
			$btn.find('.copy-text').show();
			$btn.find('.copied-text').hide();
		}, 2000);
	});
});