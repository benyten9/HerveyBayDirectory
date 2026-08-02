/**
 * @package  Directorist - Coupon
 */

jQuery(document).ready(function($) {

        $('#coupon-activated input[name="coupon-activated"]').on('change', function (event) {
                event.preventDefault();
                var form_data = new FormData();
                var coupon_license = $('#coupon_license input[name="coupon_license"]').val();
                form_data.append('action', 'atbdp_coupon_license_activation');
                form_data.append('coupon_license', coupon_license);
                $.ajax({
                    method: 'POST',
                    processData: false,
                    contentType: false,
                    url: swbdpc_data.ajaxurl,
                    data: form_data,
                    success: function (response) {
                        if (response.status === true) {
                            $('#success_msg').remove();
                            $('#coupon-activated').after('<p id="success_msg">' + response.msg + '</p>');
                            location.reload();
                        } else {
                            $('#error_msg').remove();
                            $('#coupon-activated').after('<p id="error_msg">' + response.msg + '</p>');
                        }
                    },
                    error: function (error) {
                        // console.log(error);
                    }
                });
            });
            // deactivate license
            $('#coupon-deactivated input[name="coupon-deactivated"]').on('change', function (event) {
                event.preventDefault();
                var form_data = new FormData();
                var coupon_license = $('#coupon_license input[name="coupon_license"]').val();
                form_data.append('action', 'atbdp_coupon_license_deactivation');
                form_data.append('coupon_license', coupon_license);
                $.ajax({
                    method: 'POST',
                    processData: false,
                    contentType: false,
                    url: swbdpc_data.ajaxurl,
                    data: form_data,
                    success: function (response) {
                        if (response.status === true) {
                            $('#success_msg').remove();
                            $('#coupon-deactivated').after('<p id="success_msg">' + response.msg + '</p>');
                            location.reload();
                        } else {
                            $('#error_msg').remove();
                            $('#coupon-deactivated').after('<p id="error_msg">' + response.msg + '</p>');
                        }
                    },
                    error: function (error) {
                        // console.log(error);
                    }
                });
            });


        /**************   START Coupon code generator   ***************/
        $('#swbdpc__coup_code_generator').on('click', function(event) {
                event.preventDefault();

                function makeid() {
                        let text = '';
                        // Coupon code generate by these letters
                        const possible = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

                        for (let i = 0; i < 8; i++)
                                text += possible.charAt(Math.floor(Math.random() * possible.length));

                        return text;
                }

                // Coupon code display in coupon code input field
                $('#swbdpc__coupon_code').val(makeid());
        });
        /**************   END Coupon code generator   **************/

        /**************   START Change placeholder depend on coupon type   *************/
        // This is previously saved coupon amount placeholder
        var couponType = $('select#swbdpc__coupon_type')
                .children('option:selected')
                .val();

        if (couponType == 'percentage_dis') {
                $('#swbdpc__coupon_amount').attr('placeholder', 'e.g. 5%');
        } else {
                $('#swbdpc__coupon_amount').attr('placeholder', 'e.g. 10');
        }

        // After click change coupon amount placeholder
        $('select#swbdpc__coupon_type').change(function() {
                const couponType = $(this)
                        .children('option:selected')
                        .val();

                if (couponType == 'percentage_dis') {
                        $('#swbdpc__coupon_amount').attr('placeholder', 'e.g. 5%');
                } else {
                        $('#swbdpc__coupon_amount').attr('placeholder', 'e.g. 10');
                }
        });
        /**************   END Change placeholder depend on coupon type   ****************/

        /*********   START Show and hide the product select row for Fixed Product Discount option  ***********/
        // If previously selected Fixed Product Discount option then shwo products select row otherwise hide
        var couponType = $('select#swbdpc__coupon_type')
                .children('option:selected')
                .val();

        if (couponType == 'fixed_product_dis') {
                $('#swbdpc__fixed_product_row').show();
        } else {
                $('#swbdpc__fixed_product_row').hide();
        }

        // When select Fixed Product Discount then shwo products select row
        $('select#swbdpc__coupon_type').change(function(event) {
                event.preventDefault();
                const couponType = $(this)
                        .children('option:selected')
                        .val();

                if (couponType == 'fixed_product_dis') {
                        $('#swbdpc__fixed_product_row').show();
                } else {
                        $('#swbdpc__fixed_product_row').hide();
                }
        });
        /************   END Show and hide the product select row Fixed Product Discount option  ***********/

        const cCode = document.querySelectorAll('.column-swbdpc_coupon_code');
        function copy(that) {
                const inp = document.createElement('input');
                document.body.appendChild(inp);
                inp.value = that.textContent;
                inp.select();
                document.execCommand('copy', false);
                inp.remove();
        }
        const ccMessage = document.createElement('span');
        ccMessage.textContent = 'Code Copied!';
        cCode.forEach(function(i, e) {
                i.addEventListener('click', function() {
                        copy(this);
                        this.appendChild(ccMessage);
                        setTimeout(() => {
                                ccMessage.remove();
                        }, 1000);
                });
        });
        
}); // End of document.ready
// coupon code copy on click
