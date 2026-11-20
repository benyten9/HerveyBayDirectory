/* eslint-disable */
(function ($) {

        var set_plan = $('#pricing_plans');
        var claimed = $('#claimed_by_admin');
        var claim_charge = $('input[name="claim_charge"]');

        claim_charge.hide();
        if($("#clain_with_fee").is(":checked")){
            claim_charge.show();
        }
        $('input[name="claim_fee"]').on("change", function () {
            if($("#clain_with_fee").is(":checked")){
                claim_charge.show();
            }else{
                claim_charge.hide();
            }
        });

        if(claimed.is(":checked")){
            set_plan.hide();
        }
        claimed.on('click', function () {
            if($(this).is(":checked")){
                set_plan.hide();
            }else{
                set_plan.show();
            }
        });

        //show plan allowance
        $('#directorist__plan-allowances').hide();
        $('body').on('change', '#directorist-claimer_plan', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var form = $(this).closest('.directorist-claimer__form');
            var plan_allowances = form.find('#directorist__plan-allowances');
            var data = {
                'action': 'dcl_plan_allowances',
                'author_id': plan_allowances.data('author_id'),
                'plan_id': $(this).val()
            };
            $.post(dcl_main.ajaxurl, data, function (response) {
                if (response.html){
                    plan_allowances.show();
                    plan_allowances.html(response.html);
                    form.find('.dcl_pricing_plan_name').removeClass('dcl-loading');
                }else{
                    plan_allowances.html(' ');
                    form.find('.dcl_pricing_plan_name').removeClass('dcl-loading');
                }

            });
        });
        
        $('.directorist-claimer__form').on('submit', function (e) {
            
            e.preventDefault();

            var form = $(this);
            var modal = form.closest('.directorist-claim-listing-modal');

            form.find('.directorist-modal__footer .directorist-btn').addClass('directorist-loader');

            var listing_id = form.data('listing-id') || $('#directorist__post-id').first().val();
            var listing_type = form.find('input[type="radio"][name=listing_type]:checked').val();
            var plan_id = form.find('#directorist-claimer_plan').find(":selected").val();

			var formData = new FormData();
			
			formData.append('action', 'dcl_submit_claim');

			if( listing_id ) {
				formData.append('post_id', listing_id );
			}
			
			if( form.find('#directorist-claimer__name').val() ) {
				formData.append('claimer_name', form.find('#directorist-claimer__name').val() );
			}
			
			if( form.find('#directorist-claimer__phone').val() ) {
				formData.append('claimer_phone', form.find('#directorist-claimer__phone').val() );
			}
			
			if( form.find('#directorist-claimer__details').val() ) {
				formData.append('claimer_details', form.find('#directorist-claimer__details').val() );
			}
			
			if( plan_id ) {
				formData.append('plan_id', plan_id );
			}
			
			if( dcl_main.nonce ) {
				formData.append('nonce', dcl_main.nonce );
			}
			
			if( listing_type ) {
				formData.append('type', listing_type );
			}
			
			$.ajax({
                method: 'POST',
                processData: false,
                contentType: false,
                url: dcl_main.ajaxurl,
                data: formData,
                success(response) {
                    form.find('.directorist-modal__footer .directorist-btn').removeClass('directorist-loader');
                    if ( response.error_msg ) {
                        form.find('#directorist-claimer__warning-notification').addClass('text-warning').html(response.error_msg);
                        return;
                    }

                    if ( response.take_payment ) {
                        window.location.href = response.checkout_url;
                    } else {
                        // Clear form fields
                        form.find('.directorist-form-group').hide('');
                        
                        // Show success message
                        form.find('#directorist-claimer__submit-notification').addClass('text-success').html(response.message);
                        
                        // Auto close modal after 2 seconds
                        setTimeout(() => {
                            // Close the modal
                            modal.removeClass('directorist-show');
                            $('body').removeClass('directorist-modal-open');
                        }, 2000);
                    }
                },
                    
                error( error ) {
                    form.find('.directorist-modal__footer .directorist-btn').removeClass('directorist-loader');
                },
				
			});
        });

        //calim listng settings panel - set claim fee
        var claim_price = $("#claim_listing_price");
        claim_price.hide();

        $('select[name="claim_charge_by"]').on("change", function () {
            if($(this).val() == "static_fee"){
                claim_price.show();
            }else{
                claim_price.hide();
            }
        });
        if($('select[name="claim_charge_by"] option:selected').val() == "static_fee"){

            claim_price.show();
        }

    var dcln = $('.directorist-claim-listing__login-notice');
    dcln.hide();
    $('.directorist-claim-listing__login-alert ').on('click', function (e) {
        e.preventDefault();
        dcln.slideDown();
    });

})(jQuery);
