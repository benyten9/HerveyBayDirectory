<?php

defined( 'ABSPATH' ) || exit;

$template_context = ! empty( $GLOBALS['directorist_pricing_plans_listing_type_template_context'] ) && is_array( $GLOBALS['directorist_pricing_plans_listing_type_template_context'] )
    ? $GLOBALS['directorist_pricing_plans_listing_type_template_context']
    : [];
unset( $GLOBALS['directorist_pricing_plans_listing_type_template_context'] );

$field_data             = $field_data ?? ( ! empty( $template_context['field_data'] ) && is_array( $template_context['field_data'] ) ? $template_context['field_data'] : ( $data ?? [] ) );
$current_plan           = $current_plan ?? ( $template_context['current_plan'] ?? null );
$is_current_plan_active = $is_current_plan_active ?? (bool) ( $template_context['is_current_plan_active'] ?? false );
$is_legacy              = $is_legacy ?? (bool) ( $template_context['is_legacy'] ?? false );

if ( ! function_exists( 'directorist_get_remaining_featured_listings' ) ) {
    function directorist_get_remaining_featured_listings( $current_plan, $is_current_plan_active, bool $is_legacy = false ) {
        if ( ! $current_plan ) {
            return null;
        }

        if ( ! $is_current_plan_active ) {
            return $current_plan->allowed_featured_listings . ' ' . __( 'Remaining', 'directorist-pricing-plans' );
        }

        if ( $current_plan->is_allowed_unlimited_featured_listings ) {
            return __( 'Unlimited', 'directorist-pricing-plans' );
        }
    
        $package_usage = directorist_package_usage( $is_legacy );
        $uses          = $package_usage->get_featured_uses( get_current_user_id(), $current_plan );

        if ( $uses['remaining'] === -1 ) {
            return __( 'Unlimited', 'directorist-pricing-plans' );
        }

        if ( $uses['remaining'] < 1 ) {
            return __( 'No Remaining', 'directorist-pricing-plans' );
        }

        return $uses['remaining'] . ' ' . __( 'Remaining', 'directorist-pricing-plans' );
    }
}

if ( ! empty( $current_plan ) && $current_plan->type === 'pay_per_listing' ) {
    if ( absint( $current_plan->is_featured ) === 1 ) {
        echo '<input id="featured" type="hidden" name="listing_type" value="featured">';
    }
    return;
}

if ( ! empty( $current_plan ) && ( absint( $current_plan->is_allowed_unlimited_featured_listings ) !== 1 && absint( $current_plan->allowed_featured_listings ) === 0 ) ) {
    return;
}

$remaining = directorist_get_remaining_featured_listings( $current_plan, $is_current_plan_active, $is_legacy ?? false );

?>
<div class="directorist-form-group directorist-listing-type">
    <div class="directorist-listing-type_list">
        <div class="directorist-checkbox directorist-input-group --atbdp_inline">
            <input id="featured" type="checkbox" class="atbdp_radio_input" name="listing_type" value="featured">
            <label for="featured" class="featured_listing_type_select directorist-checkbox__label">
                <?php echo esc_attr( $field_data['featured_label'] ); ?>

                <?php if ( ! empty( $remaining ) ) : ?>
                <span class="atbdp_make_str_green">
                    <?php echo sprintf( esc_html__( ' (%s)', 'directorist-pricing-plans' ), esc_html( $remaining ) ); ?>
                </span>
                <?php endif; ?>
            </label>
        </div>
    </div>
</div>