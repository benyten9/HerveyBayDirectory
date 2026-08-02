<div class="akef-checkout-preview-widget-container">
	<style>.akef-checkout-preview-alert{padding:15px;margin-bottom:30px;border-left:5px solid transparent;position:relative;text-align:center}.akef-checkout-preview-alert .akef-checkout-preview-alert-title{display:block;font-weight:700}.akef-checkout-preview-alert .akef-checkout-preview-alert-description{font-size:13px}.akef-checkout-preview-alert button.akef-checkout-preview-alert-dismiss{position:absolute;right:var(--dismiss-icon-horizontal-position,10px);top:var(--dismiss-icon-vertical-position,10px);padding:3px;font-size:var(--dismiss-icon-size,20px);line-height:1;background:transparent;color:var(--dismiss-icon-normal-color,inherit);border:none;cursor:pointer;transition-duration:var(--dismiss-icon-hover-transition-duration,.3s)}.akef-checkout-preview-alert button.akef-checkout-preview-alert-dismiss:hover{color:var(--dismiss-icon-hover-color,inherit)}.akef-checkout-preview-alert button.akef-checkout-preview-alert-dismiss svg{width:var(--dismiss-icon-size,20px);height:var(--dismiss-icon-size,20px);fill:var(--dismiss-icon-normal-color,currentColor);transition-duration:var(--dismiss-icon-hover-transition-duration,.3s)}.akef-checkout-preview-alert button.akef-checkout-preview-alert-dismiss svg:hover{fill:var(--dismiss-icon-hover-color,currentColor)}.akef-checkout-preview-alert.akef-checkout-preview-alert-info{color:#31708f;background-color:#d9edf7;border-color:#bcdff1}.akef-checkout-preview-alert.akef-checkout-preview-alert-success{color:#3c763d;background-color:#dff0d8;border-color:#cae6be}.akef-checkout-preview-alert.akef-checkout-preview-alert-warning{color:#8a6d3b;background-color:#fcf8e3;border-color:#f9f0c3}.akef-checkout-preview-alert.akef-checkout-preview-alert-danger{color:#a94442;background-color:#f2dede;border-color:#e8c4c4}@media (max-width:767px){.akef-checkout-preview-alert{padding:10px}.akef-checkout-preview-alert button.akef-checkout-preview-alert-dismiss{right:7px;top:7px}}</style>
    <div class="akef-checkout-preview-alert akef-checkout-preview-alert-info" role="alert">
	    <span class="akef-checkout-preview-alert-title">It's a dummy order for the preview.</span>
	</div>
</div>

<div class="directorist-col-md-6 directorist-offset-md-3">
    <form id="atbdp-checkout-form" class="form-vertical clearfix" method="post" action="#" role="form">
        <div class="directorist-checkout-text directorist-text-center directorist-mb-40">
            Your order details are given below. Please review it and click on Proceed to Payment to complete this order.
        </div>
        <div class="directorist-card directorist-checkout-card">
            <div class="directorist-card__header">
                <h4 class="directorist-card__header--title">Order Summary</h4>
            </div>
            <div class="directorist-card__body">
                <div class="directorist-table-responsive">
                    <table id="directorist-checkout-table" class="directorist-table">
                        <tbody>
                            <tr>
                                <td colspan="2" class="">
                                    <input type="hidden" id="feature_1" name="feature" class="atbdp-checkout-price-item atbdp_checkout_item_field" value="19.99" data-price-type="addition" checked="'checked'">
                                    <label for="feature_1"></label>
                                    <span class="directorist-summery-label"> Featured </span>
                                    <p class="directorist-summery-label-description">(Top of the search result and listings pages for a number days and it requires an additional payment.)</p>
                                </td>
                                <td class="directorist-text-right">
                                    <span class="directorist-summery-amount">
                                        $19.99
                                    </span>
                                </td>
                            </tr>
                            <tr class="atbdp_ch_subtotal">
                                <td colspan="2" class="">
                                    <span class="directorist-summery-label">Subtotal</span>
                                </td>
                                <td class="directorist-text-right">
                                    <div id="atbdp_checkout_subtotal_amount" class="directorist-summery-amount">
                                        $19.99
                                    </div>
                                </td>
                            </tr>
                            <tr class="directorist-summery-total">
                                <td colspan="2" class="">
                                    <span class="directorist-summery-label">Total amount [USD]</span>
                                </td>
                                <td class="directorist-text-right">
                                    <div id="atbdp_checkout_total_amount" class="directorist-summery-amount">19.99</div>
                                </td>
                            </tr>
                        </tbody>
                    </table> <!-- ends table -->
                </div>
            </div>
        </div>

        <div class="directorist-card directorist-mt-30 directorist-payment-gateways directorist-mb-15 directorist-checkout-card directorist-checkout-payment" id="directorist_payment_gateways">
            <div class="directorist-card__header">
                <h4 class="directorist-card__header--title">Choose a payment method</h4>
            </div>
            <div class="directorist-card__body">
                <ul>
                    <li class="list-group-item">
                        <div class="gateway_list directorist-radio directorist-radio-circle">
                            <label for="bank_transfer" class="directorist-radio__label">
                                Bank Transfer
                            </label>
                        </div>
                        <p class="directorist-payment-text">You can make your payment directly to our bank account using this gateway. Please use your ORDER ID as a reference when making the payment. We will complete your order as soon as your deposit is cleared in our bank.</p>
                    </li>
                </ul>
            </div>
        </div>

        <p id="atbdp_checkout_errors" class="text-danger"></p>

        <div class="directorist-payment-action directorist-flex directorist-justify-content-between" id="atbdp_pay_notpay_btn">
            <a href="#" class="directorist-btn directorist-btn-lg directorist-btn-light atbdp_not_now_button">Not Now</a>
            <button type="submit" id="atbdp_checkout_submit_btn" class="directorist-btn directorist-btn-lg directorist-btn-primary directorist-btn-payment-submit" value="Pay Now">Pay Now</button>
        </div>
    </form>
</div>