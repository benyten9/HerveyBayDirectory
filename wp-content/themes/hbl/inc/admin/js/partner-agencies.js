/**
 * HBL Partner Agencies JavaScript
 */

(function($) {
	'use strict';

	var HBLPartnerAgencies = {
		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			var self = this;

			$('#hbl-add-partner-form').on('submit', function(e) {
				e.preventDefault();
				self.createPartner($(this));
			});
		},

		createPartner: function($form) {
			var self = this;
			var $submitBtn = $form.find('button[type="submit"]');
			var $result = $('#hbl-partner-result');
			var originalText = $submitBtn.html();

			// Validate required fields
			var username = $form.find('#partner_username').val().trim();
			var email = $form.find('#partner_email').val().trim();
			var agencyName = $form.find('#partner_agency_name').val().trim();

			if (!username || !email || !agencyName) {
				$result.removeClass('success').addClass('error')
					.html(hblPartnerAgencies.strings.fillRequired).show();
				return;
			}

			// Disable button and show loading state
			$submitBtn.prop('disabled', true).html(
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="hbl-spin">' +
				'<path d="M12 2V6M12 18V22M4.93 4.93L7.76 7.76M16.24 16.24L19.07 19.07M2 12H6M18 12H22M4.93 19.07L7.76 16.24M16.24 7.76L19.07 4.93" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>' +
				'</svg> ' + hblPartnerAgencies.strings.creating
			);
			$result.hide();

			// Collect form data
			var formData = {
				action: 'hbl_create_partner_user',
				nonce: hblPartnerAgencies.nonce,
				username: username,
				email: email,
				first_name: $form.find('#partner_first_name').val().trim(),
				last_name: $form.find('#partner_last_name').val().trim(),
				agency_name: agencyName,
				agency_website: $form.find('#partner_agency_website').val().trim(),
				role: $form.find('#partner_role').val(),
				discount_rate: $form.find('#partner_discount').val(),
				notes: $form.find('#partner_notes').val().trim()
			};

			$.ajax({
				url: hblPartnerAgencies.ajaxUrl,
				type: 'POST',
				data: formData,
				success: function(response) {
					if (response.success) {
						$result.removeClass('error').addClass('success')
							.html(response.data.message).show();
						
						// Reset form
						$form[0].reset();

						// Reload page after 2 seconds to show new partner
						setTimeout(function() {
							window.location.reload();
						}, 2000);
					} else {
						$result.removeClass('success').addClass('error')
							.html(response.data.message || hblPartnerAgencies.strings.error).show();
						$submitBtn.prop('disabled', false).html(originalText);
					}
				},
				error: function() {
					$result.removeClass('success').addClass('error')
						.html(hblPartnerAgencies.strings.error).show();
					$submitBtn.prop('disabled', false).html(originalText);
				}
			});
		}
	};

	// Spinner animation
	$('<style>.hbl-spin { animation: hbl-spin 1s linear infinite; } @keyframes hbl-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>').appendTo('head');

	$(document).ready(function() {
		if ($('.hbl-partner-agencies-wrap').length) {
			HBLPartnerAgencies.init();
		}
	});

})(jQuery);

