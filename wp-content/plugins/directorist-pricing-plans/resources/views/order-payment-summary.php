<?php

defined( "ABSPATH" ) || exit;

/**
 * @var \DirectoristPricingPlan\App\DTO\Plan\DTO $plan
 * @var ?array $expiry
 */
?>
<input type="hidden" name="plan_id" value="<?php echo esc_attr( $plan->get_id() ); ?>">

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

<?php if ( $expiry ): ?>
<tr>
    <td colspan="2">
        <span class="directorist-summery-label">
            <?php echo esc_html( $expiry['label'] ); ?>
        </span>
    </td>
    <td class="directorist-text-right">
        <div class="directorist-summery-amount">
            <?php echo esc_html( $expiry['value'] ); ?>
        </div>
    </td>
</tr>
<?php endif; ?>

