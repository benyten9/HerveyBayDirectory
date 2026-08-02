<?php

namespace DirectoristPricingPlan\App\Utils;

defined( 'ABSPATH' ) || exit;

use stdClass;
use Directorist\Helpers\DateTime;

use DirectoristPricingPlan\App\DTO\Proration\Result as ProrationResult;
use DirectoristPricingPlan\App\Enums\Plan\FeeType;
use DirectoristPricingPlan\App\Enums\Plan\Interval;

class PlanProration {
    /**
     * Calculate the proration result when switching from a current plan to a new one.
     *
     * @param stdClass|null $current_package The user's active package row, or null if none.
     * @param stdClass|null $current_plan    The plan associated with the active package, or null if none.
     * @param stdClass      $new_plan        The plan the user wants to switch to.
     *
     * @return ProrationResult
     */
    public function calculate_result( ?stdClass $current_package, ?stdClass $current_plan, stdClass $new_plan ): ProrationResult {
        // No current package — always allow at full price with no adjustments.
        if ( ! $current_package || ! $current_plan ) {
            return ProrationResult::allow( (float) $new_plan->price, null, 0.0 );
        }

        $current_is_free    = $this->is_free( $current_plan );
        $new_is_free        = $this->is_free( $new_plan );
        $current_is_limited = $this->is_limited( $current_plan );
        $new_is_limited     = $this->is_limited( $new_plan );
        $current_is_expired = $this->is_expired( $current_package );

        // Free (any) → Any — always allowed, no adjustment.
        if ( $current_is_free ) {
            return ProrationResult::allow( (float) $new_plan->price, null, 0.0 );
        }

        // Paid (any) → Free
        if ( $new_is_free ) {
            if ( $current_is_limited && $current_is_expired ) {
                // Paid Limited → Free, expired — allow.
                return ProrationResult::allow( 0.0, null, 0.0 );
            }
            // Paid Limited → Free, not expired.
            // Paid Unlimited → Free.
            return ProrationResult::deny(
                __( 'You must cancel your current plan before switching to a free plan.', 'directorist-pricing-plans' )
            );
        }

        // Both plans are paid from here on.

        // Paid Limited → Paid (any)
        if ( $current_is_limited ) {
            if ( $new_is_limited ) {
                // Paid Limited → Paid Limited
                if ( $current_is_expired ) {
                    // Expired — allow at full price, no adjustment.
                    return ProrationResult::allow( (float) $new_plan->price, null, 0.0 );
                }

                // Not expired — Proration based on price per day.
                return $this->calculate_limited_to_limited_switch( $current_package, $current_plan, $new_plan );
            }

            // Paid Limited → Paid Unlimited
            if ( $current_is_expired ) {
                // Expired limited plan — allow at full price, no credit.
                return ProrationResult::allow( (float) $new_plan->price, null, 0.0 );
            }

            $unused_amount = $this->calculate_unused_amount( $current_package, $current_plan );

            if ( $unused_amount > (float) $new_plan->price ) {
                // Unused credit exceeds new plan price — must cancel first.
                return ProrationResult::deny(
                    __( 'Your unused plan credit exceeds the new plan price. Please cancel your current plan first.', 'directorist-pricing-plans' )
                );
            }

            // (unused == new price, pay 0) or (unused < new price, deduct).
            $adjusted_price = max( 0.0, (float) $new_plan->price - $unused_amount );

            return ProrationResult::allow( $adjusted_price, null, $unused_amount );
        }

        // Paid Unlimited → Paid Limited — must cancel first.
        if ( $new_is_limited ) {
            return ProrationResult::deny(
                __( 'You must cancel your current unlimited plan before switching to a limited plan.', 'directorist-pricing-plans' )
            );
        }

        // Paid Unlimited → Paid Unlimited
        $old_price = (float) $current_plan->price;
        $new_price = (float) $new_plan->price;

        if ( $new_price < $old_price ) {
            // New price is lower — must cancel first.
            return ProrationResult::deny(
                __( 'You must cancel your current plan before switching to a lower-priced plan.', 'directorist-pricing-plans' )
            );
        }

        // (equal prices, pay 0) or (new price higher, deduct old price).
        $adjusted_price = max( 0.0, $new_price - $old_price );

        return ProrationResult::allow( $adjusted_price, null, $old_price );
    }

    /**
     * Determine whether a plan is free.
     */
    private function is_free( stdClass $plan ): bool {
        return isset( $plan->fee_type ) && FeeType::FREE === $plan->fee_type;
    }

    /**
     * Determine whether a plan is limited (i.e. has an expiry date).
     * A lifetime plan is considered unlimited.
     */
    private function is_limited( stdClass $plan ): bool {
        return ! isset( $plan->interval_type ) || Interval::LIFETIME !== $plan->interval_type;
    }

    /**
     * Determine whether the current package has already expired.
     */
    private function is_expired( stdClass $package ): bool {
        if ( empty( $package->current_period_end ) ) {
            return false;
        }

        return new DateTime( $package->current_period_end ) < directorist_now();
    }

    /**
     * Calculate the unused monetary amount remaining in the current plan period.
     *
     * unused_amount = price_per_day * remaining_days
     */
    private function calculate_unused_amount( stdClass $current_package, stdClass $current_plan ): float {
        if ( empty( $current_package->started_at ) || empty( $current_package->current_period_end ) ) {
            return 0.0;
        }

        $started_at = new DateTime( $current_package->started_at );
        $period_end = new DateTime( $current_package->current_period_end );
        $now        = directorist_now();

        $duration_days  = $this->diff_in_days( $started_at, $period_end );
        $remaining_days = $this->diff_in_days( $now, $period_end );

        if ( $duration_days <= 0 || (float) $current_plan->price <= 0 ) {
            return 0.0;
        }

        $price_per_day = (float) $current_plan->price / $duration_days;

        return round( $price_per_day * $remaining_days, 2 );
    }

    /**
     * Calculate the number of days by which to extend the new plan's period,
     * based on the unused credit and the new plan's per-day rate.
     *
     * extending_days = floor( unused_amount / new_price_per_day )
     */
    private function calculate_extending_days( float $unused_amount, stdClass $new_plan ): int {
        if ( $unused_amount <= 0 ) {
            return 0;
        }

        $duration_days = $this->interval_to_days( $new_plan );

        if ( $duration_days <= 0 || (float) $new_plan->price <= 0 ) {
            return 0;
        }

        $new_price_per_day = (float) $new_plan->price / $duration_days;

        return (int) floor( $unused_amount / $new_price_per_day );
    }

    /**
     * Convert a plan's interval type/count to an approximate number of days,
     * used only for proration math (not for actual date arithmetic).
     */
    private function interval_to_days( stdClass $plan ): int {
        $multipliers = [
            Interval::DAY   => 1,
            Interval::WEEK  => 7,
            Interval::MONTH => 30,
            Interval::YEAR  => 365,
        ];

        $multiplier = $multipliers[ $plan->interval_type ] ?? 1;

        return $multiplier * (int) $plan->interval_count;
    }

    /**
     * Proration for Paid Limited → Paid Limited.
     *
     * Compares the price-per-day (PPD) of the current and new plans to classify
     * the switch as an upgrade, downgrade, or cross-grade:
     *
     * - Upgrade   (new_ppd > old_ppd): gap payment = remaining_days × (new_ppd − old_ppd);
     *                                  period end stays at the current expiry date.
     * - Downgrade (new_ppd < old_ppd): no payment; period end extended by converting
     *                                  unused credit into days at the new plan's rate.
     * - Cross-grade (ppd equal):       no payment; period end stays at current expiry.
     */
    private function calculate_limited_to_limited_switch(
        stdClass $current_package,
        stdClass $current_plan,
        stdClass $new_plan
    ): ProrationResult {
        $old_ppd        = $this->get_price_per_day_from_plan( $current_plan );
        $new_ppd        = $this->get_price_per_day_from_plan( $new_plan );
        $remaining_days = $this->get_remaining_days( $current_package );

        if ( $old_ppd > 0 && $new_ppd > $old_ppd ) {
            // Upgrade: charge only the gap for the remaining period at the higher daily rate.
            $gap_payment  = round( $remaining_days * ( $new_ppd - $old_ppd ), 2 );
            $override_end = new DateTime( $current_package->current_period_end );

            return ProrationResult::allow( $gap_payment, $override_end, 0.0 );
        }

        if ( $old_ppd > 0 && $new_ppd < $old_ppd ) {
            // Downgrade: Store extension days for activation instead of precomputing an absolute expiry.
            $unused_amount  = $this->calculate_unused_amount( $current_package, $current_plan );
            $extending_days = $this->calculate_extending_days( $unused_amount, $new_plan );

            return ProrationResult::allow( $new_plan->price, null, $unused_amount, $extending_days );
        }

        // Cross-grade: same price per day — no charge, keep the existing expiry date.
        $override_end = new DateTime( $current_package->current_period_end );

        return ProrationResult::allow( 0.0, $override_end, 0.0 );
    }

    /**
     * Price per day for the new plan, derived from the plan's interval settings
     * using approximate day multipliers (month = 30, year = 365, etc.).
     */
    private function get_price_per_day_from_plan( stdClass $plan ): float {
        $duration_days = $this->interval_to_days( $plan );

        if ( $duration_days <= 0 || (float) $plan->price <= 0 ) {
            return 0.0;
        }

        return (float) $plan->price / $duration_days;
    }

    /**
     * Number of whole days remaining until the current package expires.
     */
    private function get_remaining_days( stdClass $current_package ): int {
        if ( empty( $current_package->current_period_end ) ) {
            return 0;
        }

        $now        = directorist_now();
        $period_end = new DateTime( $current_package->current_period_end );

        return $this->diff_in_days( $now, $period_end );
    }

    /**
     * Return the absolute difference in whole days between two DateTime instances.
     * Clamped to zero if $to is before $from.
     */
    private function diff_in_days( DateTime $from, DateTime $to ): int {
        $diff = $from->diff( $to );

        return max( 0, (int) $diff->days );
    }
}
