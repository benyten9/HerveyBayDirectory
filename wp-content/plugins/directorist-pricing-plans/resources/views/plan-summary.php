<?php

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;

/**
 * @var \DirectoristPricingPlan\App\DTO\Plan\DTO         $plan
 * @var \stdClass                                        $plan_data
 * @var float                                            $subtotal
 * @var float                                            $tax_amount
 * @var \DirectoristPricingPlan\App\DTO\Proration\Result $proration_result
 * @var \WP_REST_Request                                 $request
 */

$is_recurring = $plan->is_is_subscription_enabled() && PlanInterval::LIFETIME !== $plan->get_interval_type() && (int) $plan->get_interval_count() > 0;
$expiry_value = null;
$expiry_label = null;

if ( 
    null !== $proration_result->get_extending_days() ||
    $proration_result->get_override_period_end() || 
    $proration_result->get_adjusted_price() !== (float) $plan->get_price()
) {
    $is_recurring = false;
}

if ( ! empty( $proration_result ) && $proration_result->get_override_period_end() ) {
    // Proration-adjusted expiry date takes priority.
    $expiry_value = $proration_result->get_override_period_end()->format( get_option( 'date_format' ) );
} elseif ( ! empty( $proration_result ) && null !== $proration_result->get_extending_days() ) {
    $_expiry_str = directorist_get_expiry_date( $proration_result->get_extending_days(), 'day' );
    if ( $_expiry_str ) {
        $expiry_value = date_i18n( get_option( 'date_format' ), strtotime( $_expiry_str ) );
    }
} elseif ( 'lifetime' === $plan->get_interval_type() ) {
    $expiry_value = __( 'Never Expires', 'directorist-pricing-plans' );
} else {
    $_expiry_str = directorist_get_expiry_date( $plan->get_interval_count(), $plan->get_interval_type() );
    if ( $_expiry_str ) {
        $expiry_value = date_i18n( get_option( 'date_format' ), strtotime( $_expiry_str ) );
    }
}

if ( $expiry_value ) {
    if ( directorist_plan_has_subscription( $plan_data ) ) {
        $expiry_label = __( 'First renewal', 'directorist-pricing-plans' );
    } else {
        $expiry_label = __( 'Expires on', 'directorist-pricing-plans' );
    }
}

$trial_end_value = null;

if ( isset( $trial_end_at ) ) {
    $trial_end_value = date_i18n( get_option( 'date_format' ), strtotime( $trial_end_at ) );
}
?>
<input type="hidden" name="plan_id" value="<?php echo esc_attr( $plan->get_id() ); ?>">
<input type="hidden" name="is_recurring" value="<?php echo esc_attr( $is_recurring ? 1 : 0 ); ?>">
<tr>
    <td colspan="2">
        <span class="directorist-summery-label">
            <?php echo esc_html( $plan->get_title() ); ?>
        </span>
    </td>
    <td class="directorist-text-right">
        <div class="directorist-summery-amount">
            <?php echo wp_kses_post( directorist_price( $plan->get_price() ) ); ?>
        </div>
    </td>
</tr>

<?php if ( $trial_end_value ): ?>
<?php $trial_duration_label = sprintf( ' ( %d %s )', $plan->get_trial_interval_count(), ( $plan->get_trial_interval_type() . ( $plan->get_trial_interval_count() > 1 ? 's' : '' ) ) ); ?>
<tr>
    <td colspan="2">
        <span class="directorist-summery-label">
            <?php echo esc_html__( 'Trial ends on', 'directorist-pricing-plans' ) . $trial_duration_label; ?>
        </span>
    </td>
    <td class="directorist-text-right">
        <div class="directorist-summery-amount">
            <?php echo esc_html( $trial_end_value ); ?>
        </div>
    </td>
</tr>

<?php elseif ( $expiry_value ) : ?>
<tr>
    <td colspan="2">
        <span class="directorist-summery-label">
            <?php echo esc_html( $expiry_label ); ?>
        </span>
    </td>
    <td class="directorist-text-right">
        <div class="directorist-summery-amount">
            <?php echo esc_html( $expiry_value ); ?>
        </div>
    </td>
</tr>
<?php endif; ?>

<?php directorist_template_render( 'checkout/checkout-order-sub-total', [ 'sub_total' => $subtotal ] ) ?>
<?php directorist_template_render( 'checkout/checkout-order-tax', [ 'rate' => $plan->get_tax_rate(), 'type' => $plan->get_tax_type(), 'amount' => $tax_amount ] ) ?>