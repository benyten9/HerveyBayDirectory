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
            var data = {
                'action': 'dcl_plan_allowances',
                'author_id': $('#directorist__plan-allowances').data('author_id'),
                'plan_id': $(this).val()
            };
            $.post(dcl_main.ajaxurl, data, function (response) {
                if (response.html){
                    $('#directorist__plan-allowances').show();
                    $('#directorist__plan-allowances').html(response.html);
                    $('.dcl_pricing_plan_name').removeClass('dcl-loading');
                }else{
                $('#directorist__plan-allowances').html(' ');
                $('.dcl_pricing_plan_name').removeClass('dcl-loading');
            }

            });
        });
        
        $('#directorist-claimer__form').on('submit', function (e) {
            
            e.preventDefault();

            $(this).find('.directorist-modal__footer .directorist-btn').addClass('directorist-loader');

            var listing_type = $( 'input[type="radio"][name=listing_type]:checked').val();
            var plan_id = $('#directorist-claimer_plan').find(":selected").val();

			var formData = new FormData();
			
			formData.append('action', 'dcl_submit_claim');

			if( $('#directorist__post-id').val() ) {
				formData.append('post_id', $('#directorist__post-id').val() );
			}
			
			if( $('#directorist-claimer__name').val() ) {
				formData.append('claimer_name', $('#directorist-claimer__name').val() );
			}
			
			if( $('#directorist-claimer__phone').val() ) {
				formData.append('claimer_phone', $('#directorist-claimer__phone').val() );
			}
			
			if( $('#directorist-claimer__details').val() ) {
				formData.append('claimer_details', $('#directorist-claimer__details').val() );
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
                    $('#directorist-claimer__form .directorist-modal__footer .directorist-btn').removeClass('directorist-loader');
                    if ( response.take_payment ) {
                        window.location.href = response.checkout_url;
                    } else {
                        // Clear form fields
                        $('.directorist-claimer__form .directorist-form-group').hide('');
                        
                        // Show success message
                        $('#directorist-claimer__submit-notification').addClass('text-success').html(response.message);
                        
                        // Auto close modal after 2 seconds
                        setTimeout(() => {
                            // Close the modal
                            $('.directorist-claim-listing-modal').removeClass('directorist-show');
                            $('body').removeClass('directorist-modal-open');
                        }, 2000);
                    }
                    if ( response.error_msg ){
                        $('#directorist-claimer__warning-notification').addClass('text-warning').html(response.error_msg);
                    }
                },
                    
                error( error ) {
                    $('#directorist-claimer__form .directorist-modal__footer .directorist-btn').removeClass('directorist-loader');
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

