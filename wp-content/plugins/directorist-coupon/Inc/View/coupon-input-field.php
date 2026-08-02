<?php
/**
 * @package  Directorist - Coupon
 */

$l_have_coup        = __( 'Got a coupon? Redeem it here.', 'directorist-coupon' );
$l_coup_apply_btn   = __( 'Apply Coupon', 'directorist-coupon' );
$ph_enter_coup      = __( 'Enter coupon code', 'directorist-coupon' );

?>
<div class="directorist-coupon-redeem">
    <h4 class="directorist-coupon-redeem__title"><?php echo $l_have_coup; ?></h4>
    <div class="directorist-coupon-redeem__input-wrapper">
        <div class="directorist-coupon-redeem__form">
            <input type="text" id="directorist-coupon-redeem__code" placeholder="<?php echo $ph_enter_coup; ?>">
            <button class="" id="directorist-coupon-redeem__btn"><?php echo $l_coup_apply_btn; ?></button>
        </div>
        <span id="directorist-coupon-redeem__message"></span>
    </div>
</div>
