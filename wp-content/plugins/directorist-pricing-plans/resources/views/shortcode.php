<?php

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\Plan\FeeType;
use DirectoristPricingPlan\App\Enums\Plan\Interval as PlanInterval;
use DirectoristPricingPlan\App\Repositories\Admin\PlanFeatureRepository;
use DirectoristPricingPlan\App\Repositories\Admin\PlanRepository;
use DirectoristPricingPlan\App\Enums\Plan\TaxType;

/**
 * Fire before pricing plan loaded
 */
do_action( 'atbdp_before_plan_page_loaded' );

$atts           = ! empty( $atts ) ? $atts : [];
$atts           = shortcode_atts( [ 'id' => null, 'columns' => 3 ], $atts );
$is_single_plan = ! empty( $atts['id'] );

$columns           = 12 / ( ! empty( $atts['columns'] ) ? absint( $atts['columns'] ) : 3 );
$plan_repo         = directorist_pricing_plans_singleton( PlanRepository::class );
$plan_feature_repo = directorist_pricing_plans_singleton( PlanFeatureRepository::class );

if ( $is_single_plan ) {
    $plan = $plan_repo->get_by_id( (int) $atts['id'] );

    if ( ! $plan ) {
        return '<p>' . __( 'Plan not found.', 'directorist-pricing-plans' ) . '</p>';
    }

    $plans          = [ $plan ];
    $directory_term = get_term_by( 'id', $plan->directory_type_id, 'atbdp_listing_types' );
} else {
    $directory_type = ! empty( $_GET['directory_type'] ) ? sanitize_text_field( wp_unslash( $_GET['directory_type'] ) ) : default_directory_type(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $directory_term = ! empty( $directory_type ) ? get_term_by( is_numeric( $directory_type ) ? 'id' : 'slug', $directory_type, 'atbdp_listing_types' ) : null;
    $plans          = $directory_term ? $plan_repo->get_by_directory_type( $directory_term->term_id ) : [];
}

$is_user_logged_in      = is_user_logged_in();
$is_user_trial_eligible = ! empty( $directory_term ) && $is_user_logged_in ? directorist_is_user_trial_eligible( $directory_term->term_id ) : true;
$active_packages        = [];
$active_plan_ids        = [];
$plans_limit_reached    = false;

if ( ! empty( $directory_term ) && $is_user_logged_in ) {
    $user_package_repo = directorist_user_package_repository();
    $active_packages   = $user_package_repo->get_active_packages_for_directory( get_current_user_id(), $directory_term->term_id );

    foreach ( $active_packages as $package ) {
        $user_package_repo->attach_plan_usage_data( $package );
        $active_plan_ids[] = (int) $package->plan_id;
    }

    if ( $plans && ! empty( $active_packages ) ) {
        $limit_reached_count = count( array_filter( $active_packages, 'directorist_is_plan_limit_reached' ) );
        $plans_limit_reached = $limit_reached_count === count( $active_packages );
    }
}
?>
<div id="directorist-pricing-plan-container" <?php do_action( 'atbdp_plans_container_div_attribute' ); ?>>
    <div class="directorist-container directorist-mt-30 directorist-mb-30">
        <?php
        if ( ! $is_single_plan ) {
            $types = directory_types();
            if ( directorist_multi_directory() && count( $types ) > 1 ) {
                directorist_pricing_plans_get_template( 'directory_types', [ 'directory_types' => $types ] );
            }
        }
        ?>

        <?php if ( $plans_limit_reached ) { ?>
            <div class="directorist-col-md-12 directorist-mt-20">
                <section class="directorist-alert directorist-alert-warning directorist-single-listing-notice">
                    <div class="directorist-alert__content">
                        <?php esc_html_e( 'Current plan reached your limit, please upgrade to a higher plan to continue', 'directorist-pricing-plans' ); ?>
                    </div>
                </section>
            </div>
        <?php } ?>

        <div class="directorist-row directorist-justify-content-center">
            <?php
            if ( $plans ) {
                $currency   = directorist_get_currency();
                $symbol     = atbdp_currency_symbol( $currency );
                $c_position = directorist_get_currency_position();
                $before     = '';
                $after      = '';

                if ( 'after' == $c_position ) {
                    $after = $symbol;
                } else {
                    $before = $symbol;
                }

                foreach ( $plans as $plan ) {
                    $features           = $plan_feature_repo->get( $plan );
                    $columns_class      = 'directorist-col-md-' . $columns . ' atpp_' . strtolower( $plan->title );
                    $is_active          = in_array( (int) $plan->id, $active_plan_ids, true );
                    $is_free_one_time   = $plan->fee_type === FeeType::FREE && $plan->interval_type !== PlanInterval::LIFETIME;
                    $exceeded_max_usage = ! $is_active && $is_free_one_time && is_user_logged_in() && directorist_user_package_repository()->has_ever_used_plan( get_current_user_id(), (int) $plan->id );
                    $is_pay_per         = $plan->type === PlanType::PAY_PER_LISTING;
                    
                    $plan_type_label = $is_pay_per
                        ? __( 'Pay Per Listing', 'directorist-pricing-plans' )
                        : __( 'Package', 'directorist-pricing-plans' );
                    $plan_type_style = $is_pay_per
                        ? 'background:#fff3e0;color:#e65100;border:1px solid #ffcc80;'
                        : 'background:#e8f0fe;color:#1a56db;border:1px solid #c3d4fc;';
                    $plan_type_icon  = $is_pay_per
                        ? '<svg width="11" height="11" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0;"><path d="M10 2a8 8 0 100 16A8 8 0 0010 2zm0 3a1 1 0 011 1v3.586l2.207 2.207a1 1 0 01-1.414 1.414l-2.5-2.5A1 1 0 019 10V6a1 1 0 011-1z"/></svg>'
                        : '<svg width="11" height="11" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0;"><path d="M4 3a1 1 0 00-1 1v2a1 1 0 001 1h12a1 1 0 001-1V4a1 1 0 00-1-1H4zm0 6a1 1 0 00-1 1v6a1 1 0 001 1h12a1 1 0 001-1v-6a1 1 0 00-1-1H4z"/></svg>';
                    $fm_tax          = null;

                    if ( $plan->is_taxable && $plan->tax_rate > 0 ) {
                        if ( $plan->tax_type === TaxType::PERCENT ) {
                            $fm_tax = $plan->tax_rate . '%';
                        } else {
                            $fm_tax = trim( "{$before} {$plan->tax_rate} {$after}" );
                        }
                    }

                    $featured_listings_text = '';

                    if ( 1 === (int) $plan->is_allowed_unlimited_listings || (int) $plan->allowed_listings > 0 ) {
                        if ( 1 === (int) $plan->is_allowed_unlimited_featured_listings ) {
                            $featured_listings_text = esc_html__( ' ( All can be featured )', 'directorist-pricing-plans' );
                        } else if ( (int) $plan->allowed_featured_listings > 0 ) {
                            if ( (int) $plan->allowed_featured_listings === 1 ) {
                                if ( (int) $plan->allowed_listings === 1 ) {
                                    $featured_listings_text = sprintf( esc_html__( ' ( can be featured )', 'directorist-pricing-plans' ), (int) $plan->allowed_featured_listings );
                                } else {
                                    $featured_listings_text = sprintf( esc_html__( ' ( 1 can be featured )', 'directorist-pricing-plans' ), (int) $plan->allowed_featured_listings );
                                }
                            } else {
                                $featured_listings_text = sprintf( esc_html__( ' ( %s of them can be featured )', 'directorist-pricing-plans' ), (int) $plan->allowed_featured_listings );
                            }
                        }
                    }

                    $tax_placeholder = 'tax';
                    $has_trial       = $is_user_trial_eligible && directorist_is_plan_trial_eligible( $plan );
                    $has_listing_quota = directorist_plan_has_listing_quota( $plan );
                    ?>
                    <div class="<?php echo esc_attr( $columns_class ); ?>">
                        <div class="directorist-pricing directorist-pricing--1 <?php echo $plan->is_marked_as_recommended ? esc_attr( 'directorist-pricing-special' ) : ''; ?>">
                            <?php if ( $plan->is_marked_as_recommended ): ?>
                                <span class="atbd_popular_badge"><?php esc_html_e( 'Recommended', 'directorist-pricing-plans' ); ?></span>
                            <?php endif; ?>

                            <div class="directorist-pricing__title">
                                <h4>
                                    <?php echo esc_html( $plan->title ); ?>
                                    <?php if ( $is_active ) : ?>
                                        <span class="atbd_plan-active">
                                            <?php esc_html_e( 'Active', 'directorist-pricing-plans' ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $exceeded_max_usage ) : ?>
                                        <span class="atbd_plan-used" style="display:inline-block;font-size:12px;font-weight:600;padding:4px 10px;border-radius:20px;background:#f2f4f7;color:#667085;border:1px solid #d0d5dd;margin-left:6px;vertical-align:middle;line-height:1.4;">
                                            <?php esc_html_e( 'Already used', 'directorist-pricing-plans' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </h4>
                                <span class="atbd_plan-type" style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;letter-spacing:0.2px;line-height:1.4;margin-top: 20px;<?php echo esc_attr( $plan_type_style ); ?>">
                                    <?php echo $plan_type_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG ?>
                                    <?php echo esc_html( $plan_type_label ); ?>
                                </span>
                            </div>

                            <div class="directorist-pricing__price">
                                <p class="directorist-pricing__value">
                                    <?php if ( FeeType::PAID === $plan->fee_type ): ?>
                                        <sup><?php echo esc_html( $before ); ?></sup>
                                        <?php echo esc_html( $plan->price ); ?>
                                        <sup><?php echo esc_html( $after ); ?></sup>

                                        <?php if ( $fm_tax ): ?>
                                            <span class="directorist-pricing-info">
                                                <?php directorist_icon( 'fas fa-question-circle' ); ?>    
                                                <span class="directorist-tooltip-pricing directorist-tooltip-top-pricing">
                                                    <?php $fm_tax ? printf( esc_html__( 'Plus %s tax', 'directorist-pricing-plans' ), esc_html( $fm_tax ) ) : ''; ?>
                                                </span>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php echo esc_html__( 'Free', 'directorist-pricing-plans' ); ?>
                                    <?php endif; ?>
                                    <small>/ <?php echo esc_html( directorist_plan_duration_text( $plan ) ); ?></small>

                                    <?php if ( $has_trial ): ?>
                                    <p class="directorist-text-sm directorist-text-muted directorist-pt-10">
                                        <?php echo sprintf( esc_html__( 'After %d %s of trial', 'directorist-pricing-plans' ), $plan->trial_interval_count, $plan->trial_interval_type . ( $plan->trial_interval_count > 1 ? 's' : '' ) ) ?>
                                    </p>
                                    <?php endif; ?>
                                </p>
                                <p class="directorist-pricing__description"><?php echo esc_html( $plan->description ); ?></p>
                            </div>
                            <div class="directorist-pricing__features">
                                <ul>
                                    <?php if ( $plan->type === PlanType::PACKAGE && $plan->interval_type !== PlanInterval::LIFETIME ) : ?>
                                    <li>
                                        <?php directorist_plan_features( directorist_plan_has_subscription( $plan ) ); ?>
                                        <?php esc_html_e( 'Auto Renew', 'directorist-pricing-plans' ); ?>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ( ! $is_pay_per ) : ?>
                                        <li>
                                            <?php directorist_plan_features( true ); ?>
                                            <?php if ( 1 === (int) $plan->is_allowed_unlimited_listings ): ?>
                                                <?php echo esc_html__( 'Unlimited listings', 'directorist-pricing-plans' ) . $featured_listings_text; ?>
                                            <?php else: ?>
                                                <?php echo trim( sprintf( _n( '%d Total listing %s', '%d Total listings %s', (int) $plan->allowed_listings, 'directorist-pricing-plans' ), $plan->allowed_listings, $featured_listings_text ) ); ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php elseif ( 1 === (int) $plan->is_featured ) : ?>
                                        <li>
                                            <?php directorist_plan_features( true ); ?>
                                            <?php esc_html_e( 'Featured listing', 'directorist-pricing-plans' ); ?>
                                        </li>
                                    <?php endif; ?>
                                    <?php foreach ( $features as $feature ): ?>
                                        <?php if ( ! $feature->is_show_in_pricing_table ) { 
                                            continue; 
                                        } ?>
                                        <?php
                                        $feature_data   = ! empty( $feature->data ) && is_array( $feature->data ) ? $feature->data : [];
                                        $has_limit      = isset( $feature_data['limit'] ) && $feature_data['limit'] !== '' && $feature_data['limit'] !== null;
                                        $has_exclude    = ! empty( $feature_data['exclude'] );
                                        $is_unlimited   = ! empty( $feature_data['is_unlimited'] );

                                        $feature_suffix = '';
                                        
                                        if ( $is_unlimited ) {
                                            if ( $has_exclude ) {
                                                $feature_suffix = ' ( ' . __( 'Unlimited | Partial', 'directorist-pricing-plans' ) . ' )';
                                            } else {
                                                $feature_suffix = ' ( ' . __( 'Unlimited', 'directorist-pricing-plans' ) . ' )';
                                            }
                                        } elseif ( $has_limit && $has_exclude ) {
                                            /* translators: %s: maximum limit number */
                                            $feature_suffix = ' ( ' . sprintf( __( 'Up to %s | Partial', 'directorist-pricing-plans' ), esc_html( $feature_data['limit'] ) ) . ' )';
                                        } elseif ( $has_limit ) {
                                            /* translators: %s: maximum limit number */
                                            $feature_suffix = ' ( ' . sprintf( __( 'Up to %s', 'directorist-pricing-plans' ), esc_html( $feature_data['limit'] ) ) . ' )';
                                        } elseif ( $has_exclude ) {
                                            $feature_suffix = ' ( ' . __( 'Partial', 'directorist-pricing-plans' ) . ' )';
                                        }
                                        ?>
                                        <li>
                                            <?php directorist_plan_features( $feature->is_enabled ); ?>
                                            <?php echo esc_html( $feature->name . $feature_suffix ); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="directorist-pricing__action">
                                    <?php
                                    $is_free_plan = FeeType::FREE === $plan->fee_type || $plan->price === 0;
                                    $query_args   = [
                                        'plan_id'        => $plan->id,
                                        'directory_type' => $directory_term->slug,
                                    ];

                                    $url       = add_query_arg( $query_args, directorist_permalink()::get_add_listing_page_link() );
                                    $url       = apply_filters( 'directorist_pricing_plans_continue_url', $url, $query_args, $plan, $directory_term );
                                    $btn_class = '';

                                    if ( directorist_direct_purchase() && ! get_directorist_option( 'guest_listings' ) && ! is_user_logged_in() ) {
                                        $btn_class = 'directorist_required_login';
                                    } elseif ( directorist_direct_purchase() && get_directorist_option( 'guest_listings' ) && ! is_user_logged_in() ) {
                                        $btn_class = 'directorist_required_email';
                                    }
                                    ?>
                                    <?php if ( ! $has_listing_quota ) : ?>
                                        <p class="directorist-alert directorist-alert-warning directorist-mb-10">
                                            <?php echo esc_html( directorist_plan_no_listing_quota_message() ); ?>
                                        </p>
                                        <span class="directorist-btn directorist-btn-lighter directorist-btn-block directorist-pricing__action--btn" style="opacity:0.5;cursor:not-allowed;pointer-events:none;" aria-disabled="true">
                                            <?php esc_html_e( 'Continue', 'directorist-pricing-plans' ) ?>
                                        </span>
                                    <?php elseif ( $exceeded_max_usage ) : ?>
                                        <span class="directorist-btn directorist-btn-lighter directorist-btn-block directorist-pricing__action--btn" style="opacity:0.5;cursor:not-allowed;pointer-events:none;" aria-disabled="true">
                                            <?php esc_html_e( 'Continue', 'directorist-pricing-plans' ) ?>
                                        </span>
                                    <?php else : ?>
                                        <a data-is_free_plan="<?php echo $is_free_plan ? '1' : '0'; ?>" data-plan_id="<?php echo esc_attr( $plan->id ); ?>" class="directorist-btn directorist-btn-lighter directorist-btn-block directorist-pricing__action--btn <?php echo esc_attr( $btn_class ); ?>" href="<?php echo esc_url( $url ); ?>">
                                            <?php esc_html_e( 'Continue', 'directorist-pricing-plans' ) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                }
            } else {
                ?>
                <div class="col-md-12">
                    <div class="atbd_pricing_status">
                        <?php
                        if ( empty( $directory_term ) ) {
                            printf( '<p>%s</p>', esc_html__( 'Please select a directory to see the plans.', 'directorist-pricing-plans' ) );
                        } else {
                            printf( '<p>%s</p>', esc_html__( 'There is no Plan available right now. Please contact with administrator.', 'directorist-pricing-plans' ) );
                        }
                        ?>
                    </div>
                </div>
                <?php
            }
            ?>
        </div>
        <!--ends. row-->
    </div>
</div>
<!--ends. directorist-pricing-plan-container-->
