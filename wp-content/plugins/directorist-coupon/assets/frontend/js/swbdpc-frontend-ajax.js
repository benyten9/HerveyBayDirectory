/**
 * @package  Directorist - Coupon
 */

jQuery(document).ready(function($) {
        /** ************   START Coupon code generator   *************** */
        $('#directorist-coupon-redeem__btn').on('click', function(e) {
            e.preventDefault();

            const $button = $(this);
            const userCoupCode = $('#directorist-coupon-redeem__code').val();
            const $form = $('#atbdp-checkout-form');

            if (!$form.length) {
                return false;
            }

            const data = {};
            $.each($form.serializeArray(), function(index, field) {
                data[field.name] = field.value;
            });
            data.directorist_order_coupon = userCoupCode;

            $button.prop('disabled', true);

            $.ajax({
                url: `${swbdpc_frontend_ajax_object.rest_url}/coupons/validate`,
                method: 'POST',
                data: data,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', swbdpc_frontend_ajax_object.rest_nonce);
                },
            }).done(function(response) {
                applyCouponToCheckout(response);
                showCouponMessage(response.message, 'success');
            }).fail(function(xhr) {
                const response = xhr.responseJSON || {};
                const message = response.message || 'Invalid coupon code!';
                clearCouponFromCheckout();
                showCouponMessage(message, 'error');
            }).always(function() {
                $button.prop('disabled', false);
            });
        });

        function applyCouponToCheckout(response) {
            const discount = parseFloat(response.discount_amount) || 0;
            const subtotal = getCheckoutSubtotal();
            const discountedSubtotal = Math.max(0, subtotal - discount);
            const tax = discountedSubtotal > 0 ? getTaxAmount(discountedSubtotal) : 0;
            const total = discountedSubtotal + tax;

            $('input[name="directorist_order_coupon"]').remove();
            $('.directorist-row--order-discount').remove();

            $('#atbdp-checkout-form').append(
                $('<input>', {
                    type: 'hidden',
                    name: 'directorist_order_coupon',
                    value: response.coupon_code,
                })
            );

            insertDiscountRow(
                '<tr class="directorist-checkout-discount directorist-row--order-discount" data-order-discount-amount="' + escapeAttr(discount) + '">' +
                    '<td colspan="2">' +
                        '<span class="directorist-summery-label directorist-row-label--order-discount">' + escapeHtml(response.discount_label) + '</span>' +
                    '</td>' +
                    '<td class="directorist-text-right">' +
                        '<div class="directorist-summery-amount directorist-row-value--order-discount">-' + response.discount_amount_display + '</div>' +
                    '</td>' +
                '</tr>'
            );

            $('#atbdp_checkout_total_amount_hidden').val(total.toFixed(2));
            updateTaxRow(tax);
            updateTotalAmount(total);
            updateZeroDueCheckoutState(total < 1 && !isRecurringOrder() && !isTrialOrder());
        }

        function clearCouponFromCheckout() {
            const subtotal = getCheckoutSubtotal();
            const tax = getTaxAmount(subtotal);
            const total = Math.max(0, subtotal + tax);

            $('input[name="directorist_order_coupon"]').remove();
            $('.directorist-row--order-discount').remove();
            $('#atbdp_checkout_total_amount_hidden').val(total.toFixed(2));
            updateTaxRow(tax);
            updateTotalAmount(total);
            updateZeroDueCheckoutState(false);
        }

        function getCheckoutSubtotal() {
            const $subtotalRow = $('.directorist-row--order-sub-total').first();
            const subtotal = parseFloat($subtotalRow.attr('data-order-sub-total') || $subtotalRow.attr('data-subtotal'));

            if (!Number.isNaN(subtotal) && subtotal > 0) {
                return subtotal;
            }

            const $totalRow = $('.directorist-row--order-total').first();
            const total = parseFloat($totalRow.attr('data-order-total'));

            if (!Number.isNaN(total) && total > 0) {
                return total;
            }

            return parsePriceText($('#atbdp_checkout_total_amount, .directorist-row-value--order-total').first().text());
        }

        function insertDiscountRow(discountRowHtml) {
            const $subtotalRow = $('.directorist-row--order-sub-total').first();

            if ($subtotalRow.length) {
                $subtotalRow.after(discountRowHtml);
                return;
            }

            const $totalRow = $('.directorist-row--order-total').first();

            if ($totalRow.length) {
                $totalRow.before(discountRowHtml);
            }
        }

        function getTaxAmount(amount) {
            const $taxRow = $('.directorist-row--order-tax');

            if (!$taxRow.length) {
                return 0;
            }

            const taxType = $taxRow.attr('data-order-tax-type') || '';
            const taxRate = parseFloat($taxRow.attr('data-order-tax-rate')) || 0;

            if (!taxType || taxRate <= 0 || amount <= 0) {
                return 0;
            }

            if (taxType === 'flat' || taxType === 'fixed') {
                return roundAmount(taxRate);
            }

            return roundAmount((amount * taxRate) / 100);
        }

        function updateTaxRow(tax) {
            const $taxRow = $('.directorist-row--order-tax');

            if (!$taxRow.length) {
                return;
            }

            $taxRow.attr('data-order-tax-amount', tax.toFixed(2));
            $taxRow.attr('data-order-tax-rate', $taxRow.attr('data-order-tax-rate') || '');
            $taxRow.attr('data-order-tax-type', $taxRow.attr('data-order-tax-type') || '');
            updateAmountHtml($taxRow.find('.directorist-row-value--order-tax-amount').first(), tax);
        }

        function updateTotalAmount(total) {
            const $total = $('#atbdp_checkout_total_amount, .directorist-row-value--order-total').first();
            $('.directorist-row--order-total').attr('data-order-total', total.toFixed(2));
            updateAmountHtml($total, total);
        }

        function updateZeroDueCheckoutState(isZeroDueCheckout) {
            updateSubmitButtonLabel(isZeroDueCheckout);
            updatePaymentGatewaysVisibility(isZeroDueCheckout);
            updatePaymentGatewayInput(isZeroDueCheckout);
        }

        function updateSubmitButtonLabel(shouldCompleteSubmission) {
            const $buttonText = $('#atbdp-checkout-form button[type="submit"] .directorist-btn-text').first();

            if (!$buttonText.length) {
                return;
            }

            if (!$buttonText.data('swbdpcOriginalLabel')) {
                $buttonText.data('swbdpcOriginalLabel', $buttonText.html());
            }

            if (shouldCompleteSubmission) {
                $buttonText.html('Complete Submission');
                return;
            }

            $buttonText.html($buttonText.data('swbdpcOriginalLabel'));
        }

        function updatePaymentGatewaysVisibility(shouldHideGateways) {
            $('.directorist-payment-gateways').toggle(!shouldHideGateways);
        }

        function updatePaymentGatewayInput(shouldClearGateway) {
            if (!shouldClearGateway) {
                return;
            }

            $('input[type="radio"][name="payment_gateway"]').prop('checked', false);
        }

        function isRecurringOrder() {
            return hasInputValue('is_recurring', '1');
        }

        function isTrialOrder() {
            return hasInputValue('is_trial', '1');
        }

        function hasInputValue(name, value) {
            return $('input[name="' + name + '"]').filter(function() {
                return $(this).val() === value;
            }).length > 0;
        }

        function updateAmountHtml($element, amount) {
            const formattedTotal = new Intl.NumberFormat(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(amount);

            if (!$element.length) {
                return;
            }

            const html = $element.html();
            const replaced = html.replace(/([0-9][0-9,.]*)/, formattedTotal);

            $element.html(replaced === html ? formattedTotal : replaced);
        }

        function roundAmount(amount) {
            return Math.round((amount + Number.EPSILON) * 100) / 100;
        }

        function parsePriceText(value) {
            const normalized = (value || '').replace(/[^0-9.,-]/g, '').replace(/,/g, '');
            const amount = parseFloat(normalized);

            return Number.isNaN(amount) ? 0 : amount;
        }

        function showCouponMessage(message, type) {
            const $message = $('span#directorist-coupon-redeem__message');

            $message
                .removeClass('directorist-coupon-redeem__message__success directorist-coupon-redeem__message__error')
                .addClass('directorist-coupon-redeem__message__' + type)
                .text(message);

            setTimeout(function() {
                $message
                    .text('')
                    .removeClass('directorist-coupon-redeem__message__success directorist-coupon-redeem__message__error');
            }, 6000);
        }

        function escapeHtml(value) {
            return $('<div>').text(value || '').html();
        }

        function escapeAttr(value) {
            return $('<div>').text(value || '').html();
        }
    
        // Hide the copy notice on initial load
        function initializeCouponCodeCopy() {
            const couponInputs = document.querySelectorAll('.directorist-coupon__code__text');
            const copyButtons = document.querySelectorAll('.directorist-coupon__code__copy-btn');
        
            if (couponInputs.length > 0 && copyButtons.length > 0) {
                couponInputs.forEach((input) => {
                    input.setAttribute('readonly', true);
                });
    
                copyButtons.forEach((button) => {
                    button.addEventListener('click', function() {
                        const copyText = this.closest('.directorist-coupon__code-input').querySelector('.directorist-coupon__code__text');
                        copyText.select();
                        document.execCommand('copy');
            
                        const notice = this.closest('.directorist-coupon__code').querySelector('.directorist-coupon__code__copy-notice');
                        notice.style.display = 'inline';
            
                        setTimeout(() => {
                            notice.style.display = 'none';
                        }, 2000);
                    });
                });
            }
        }
    
        initializeCouponCodeCopy();
    
        /* coupon countdown */
        const countDownWrapper = [];
        $(countDownWrapper).each(function(i, e) {
            const dateInput = $(e)
                .siblings('.directorist-coupon__countdown__data')
                .text();
            const countDownDate = new Date(dateInput).getTime();
            var x = setInterval(function() {
                const now = new Date().getTime();
                const distance = countDownDate - now;
    
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
    
                $(e).html(`
                    <ul class="directorist-coupon__timer">
                        <li>${days} <span>D</span></li>
                        <li>${hours} <span>H</span></li>
                        <li>${minutes} <span>M</span></li>
                        <li>${seconds} <span>S</span></li>
                    </ul>`);
    
                if (distance < 0) {
                    clearInterval(x);
                    $(e).html('EXPIRED');
                }
            }, 1000);
        });
    }); // End of document.ready
