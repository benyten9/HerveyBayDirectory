<?php
/**
 * Plan selector partial for claim listing forms.
 *
 * Expects: $listing_id (int)
 *
 * @since 2.6.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_pricing_plans_active() ) {
    return;
}

$claim_charge_by = get_directorist_option( 'claim_charge_by' );
$charged_by      = get_post_meta( $listing_id, '_claim_fee', true );
$charged_by      = ( $charged_by !== '' ) ? $charged_by : $claim_charge_by;

if ( 'pricing_plan' !== $charged_by ) {
    return;
}

$directory_type_id = (int) get_post_meta( $listing_id, '_directory_type', true );

if ( ! $directory_type_id ) {
    return;
}

$plans = directorist_pricing_plan_repository()->get_by_directory_type( $directory_type_id );

if ( empty( $plans ) ) {
    return;
}

$user_id = get_current_user_id();
?>
<label for="select_plans"><?php esc_html_e( 'Select Plan', 'directorist-claim-listing' ); ?></label>
<select name="claimer_plan" id="directorist-claimer_plan">
    <option><?php esc_html_e( 'Select Plan', 'directorist-claim-listing' ); ?></option>
    <?php foreach ( $plans as $plan ) :
        $plan_type = $plan->type ?? \DirectoristPricingPlan\App\Enums\Plan\Type::PACKAGE;
        $is_active = ! empty( $plan->is_active ) && \DirectoristPricingPlan\App\Enums\Plan\Type::PACKAGE === $plan_type;
    ?>
        <option <?php echo $is_active ? 'class="directorist__active-plan"' : ''; ?> value="<?php echo esc_attr( $plan->id ); ?>">
            <?php echo esc_html( $plan->title ); ?>
            <?php if ( $is_active ) : ?>
                <?php echo esc_html__( '- Active', 'directorist-claim-listing' ); ?>
            <?php endif; ?>
        </option>
    <?php endforeach; ?>
</select>
<div id="directorist__plan-allowances" data-author_id="<?php echo esc_attr( $user_id ); ?>"></div>
<?php if ( class_exists( 'ATBDP_Permalink' ) && method_exists( 'ATBDP_Permalink', 'get_fee_plan_page_link' ) ) : ?>
    <a target="_blank" href="<?php echo esc_url( ATBDP_Permalink::get_fee_plan_page_link() ); ?>" class="directorist__plans">
        <?php esc_html_e( 'Show plan details', 'directorist-claim-listing' ); ?>
    </a>
<?php endif; ?>
