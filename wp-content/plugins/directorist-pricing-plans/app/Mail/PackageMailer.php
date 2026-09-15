<?php

namespace DirectoristPricingPlan\App\Mail;

defined( 'ABSPATH' ) || exit;

use stdClass;
use WP_User;
use Directorist\Helpers\DateTime;

class PackageMailer extends Mailer {
    /**
     * Send trial started notification.
     *
     * @param stdClass $package User package object
     * @param WP_User $user WordPress user object
     * @param stdClass $plan Plan object
     * @return bool Whether the email was sent successfully
     */
    public function send_trial_started( stdClass $package, WP_User $user, stdClass $plan ): bool {
        $data = array_merge(
            $this->get_common_placeholders(),
            [
                'user_name'      => $user->display_name,
                'user_email'     => $user->user_email,
                'plan_name'      => $plan->title,
                'trial_end_date' => $this->format_date( $package->current_period_end ),
            ]
        );

        $subject = sprintf( 
            __( 'Your %s trial has started', 'directorist-pricing-plans' ), 
            $data['plan_name'] 
        );
        $body    = $this->render_template( 'trial-started', $data );

        if ( empty( $body ) ) {
            return false;
        }

        return $this->send( $user->user_email, $subject, $body );
    }

    /**
     * Send package activated notification.
     *
     * @param stdClass $package User package object
     * @param WP_User $user WordPress user object
     * @param stdClass $plan Plan object
     * @return bool Whether the email was sent successfully
     */
    public function send_package_activated( stdClass $package, WP_User $user, stdClass $plan ): bool {
        $pricing      = $this->get_package_pricing_details( $package, $plan );
        $is_recurring = ! empty( $package->is_recurring );
        $expiry_date  = ( ! $is_recurring && $package->current_period_end ) ? $this->format_date( $package->current_period_end ) : '';

        // Base data for all packages
        $data = array_merge(
            $this->get_common_placeholders(),
            [
                'user_name'      => $user->display_name,
                'user_email'     => $user->user_email,
                'plan_name'      => $plan->title,
                'amount'         => $this->format_currency( $pricing['first_amount'], $pricing['currency'] ),
                'currency'       => $pricing['currency'],
                'activated_date'    => $this->format_date( $package->started_at ?? new DateTime() ),
                'dashboard_url'     => $this->get_dashboard_url(),
                'expiry_date_row'   => $expiry_date ? sprintf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Valid Until:', 'directorist-pricing-plans' ), esc_html( $expiry_date ) ) : '',
                'billing_breakdown' => $this->get_subscription_billing_breakdown( $pricing, $package ),
            ]
        );

        // Add expiry date for non-recurring packages
        if ( ! $is_recurring && $package->current_period_end ) {
            $data['expiry_date'] = $this->format_date( $package->current_period_end );
        }

        // Add next billing date for recurring packages
        if ( $is_recurring && $package->current_period_end ) {
            $data['next_billing_date'] = $this->format_date( $package->current_period_end );
        }

        $subject = sprintf( 
            __( 'Your %s package is now active', 'directorist-pricing-plans' ), 
            $data['plan_name'] 
        );

        // Use different templates based on package type
        $template = $is_recurring ? 'subscription-activated' : 'package-activated';
        $body     = $this->render_template( $template, $data );

        if ( empty( $body ) ) {
            return false;
        }

        return $this->send( $user->user_email, $subject, $body );
    }

    /**
     * Send package cancelled notification.
     *
     * @param stdClass $package User package object
     * @param WP_User $user WordPress user object
     * @param stdClass $plan Plan object
     * @return bool Whether the email was sent successfully
     */
    public function send_package_cancelled( stdClass $package, WP_User $user, stdClass $plan ): bool {
        $end_date = $package->current_period_end ?? $package->cancelled_at;

        $data = array_merge(
            $this->get_common_placeholders(),
            [
                'user_name'      => $user->display_name,
                'user_email'     => $user->user_email,
                'plan_name'      => $plan->title,
                'cancelled_date' => $this->format_date( $package->cancelled_at ?? new DateTime() ),
                'end_date'       => $this->format_date( $end_date ),
            ]
        );

        $subject = sprintf( 
            __( 'Your %s package has been cancelled', 'directorist-pricing-plans' ), 
            $data['plan_name'] 
        );
        $body    = $this->render_template( 'package-cancelled', $data );

        if ( empty( $body ) ) {
            return false;
        }

        return $this->send( $user->user_email, $subject, $body );
    }

    /**
     * Send package expired notification.
     *
     * @param stdClass $package User package object
     * @param WP_User $user WordPress user object
     * @param stdClass $plan Plan object
     * @return bool Whether the email was sent successfully
     */
    public function send_package_expired( stdClass $package, WP_User $user, stdClass $plan ): bool {
        $renew_url = $this->get_dashboard_url();

        $data = array_merge(
            $this->get_common_placeholders(),
            [
                'user_name'    => $user->display_name,
                'user_email'   => $user->user_email,
                'plan_name'    => $plan->title,
                'expired_date' => $this->format_date( $package->current_period_end ),
                'renew_url'    => $renew_url,
            ]
        );

        $subject = sprintf( 
            __( 'Your %s package has expired', 'directorist-pricing-plans' ), 
            $data['plan_name'] 
        );
        $body    = $this->render_template( 'package-expired', $data );

        if ( empty( $body ) ) {
            return false;
        }

        return $this->send( $user->user_email, $subject, $body );
    }

    /**
     * Send package expiring soon notification.
     *
     * @param stdClass $package User package object
     * @param WP_User $user WordPress user object
     * @param stdClass $plan Plan object
     * @return bool Whether the email was sent successfully
     */
    public function send_package_expiring_soon( stdClass $package, WP_User $user, stdClass $plan ): bool {
        if ( ! empty( $package->is_trial ) ) {
            return $this->send_trial_ending( $package, $user, $plan );
        }

        $manage_url = $this->get_dashboard_url();
        $pricing    = $this->get_package_pricing_details( $package, $plan );

        $data = array_merge(
            $this->get_common_placeholders(),
            [
                'user_name'   => $user->display_name,
                'user_email'  => $user->user_email,
                'plan_name'   => $plan->title,
                'expiry_date' => $this->format_date( $package->current_period_end ),
                'manage_url'  => $manage_url,
                'amount'      => $this->format_currency( $pricing['regular_amount'], $pricing['currency'] ),
                'currency'    => $pricing['currency'],
            ]
        );

        $subject = sprintf( 
            __( 'Your %s package is expiring soon', 'directorist-pricing-plans' ), 
            $data['plan_name'] 
        );
        $body    = $this->render_template( 'package-expiring-soon', $data );

        if ( empty( $body ) ) {
            return false;
        }

        return $this->send( $user->user_email, $subject, $body );
    }

    /**
     * Send trial ending notification.
     *
     * @param stdClass $package User package object
     * @param WP_User $user WordPress user object
     * @param stdClass $plan Plan object
     * @return bool Whether the email was sent successfully
     */
    public function send_trial_ending( stdClass $package, WP_User $user, stdClass $plan ): bool {
        $manage_url = $this->get_dashboard_url();
        $pricing    = $this->get_package_pricing_details( $package, $plan );

        $data = array_merge(
            $this->get_common_placeholders(),
            [
                'user_name'      => $user->display_name,
                'user_email'     => $user->user_email,
                'plan_name'      => $plan->title,
                'trial_end_date' => $this->format_date( $package->current_period_end ),
                'renewal_amount' => $this->format_currency( $pricing['regular_amount'], $pricing['currency'] ),
                'manage_url'     => $manage_url,
            ]
        );

        $subject = sprintf( 
            __( 'Your %s trial is ending soon', 'directorist-pricing-plans' ), 
            $data['plan_name'] 
        );
        $body    = $this->render_template( 'trial-ending', $data );

        if ( empty( $body ) ) {
            return false;
        }

        return $this->send( $user->user_email, $subject, $body );
    }

    /**
     * Send fallback plan activated notification.
     *
     * @param stdClass $package User package object
     * @param WP_User $user WordPress user object
     * @param stdClass $plan Plan object
     * @param stdClass $previous_plan Previous plan object
     * @return bool Whether the email was sent successfully
     */
    public function send_fallback_plan_activated( stdClass $package, WP_User $user, stdClass $plan, stdClass $previous_plan ): bool {
        $manage_url = $this->get_dashboard_url();

        $data = array_merge(
            $this->get_common_placeholders(),
            [
                'user_name'      => $user->display_name,
                'user_email'     => $user->user_email,
                'plan_name'      => $plan->title,
                'previous_plan'  => $previous_plan->title,
                'activated_date' => $this->format_date( $package->started_at ?? new DateTime() ),
                'manage_url'     => $manage_url,
            ]
        );

        $subject = sprintf( 
            __( 'Your fallback plan %s has been activated', 'directorist-pricing-plans' ), 
            $data['plan_name'] 
        );
        $body    = $this->render_template( 'fallback-plan-activated', $data );

        if ( empty( $body ) ) {
            return false;
        }

        return $this->send( $user->user_email, $subject, $body );
    }

    /**
     * Get package pricing information based on recurring and trial status.
     *
     * @param stdClass $package User package object
     * @param stdClass $plan Plan object
     * @return array Array with 'amount' and 'currency' keys
     */
    private function get_package_pricing_details( stdClass $package, stdClass $plan ): array {
        $is_recurring = ! empty( $package->is_recurring );
        $is_trial     = ! empty( $package->is_trial );
        $currency     = $package->subscription_currency ?? get_directorist_option( 'g_currency', 'USD' );
        $order        = $this->get_last_order( $package );
        $first_amount = $order ? directorist_order_total_amount( $order ) : (float) ( $plan->price ?? 0 );
        $has_discount = $order && (float) ( $order->coupon_discount ?? 0 ) > 0;

        if ( $order && ! empty( $order->currency ) ) {
            $currency = $order->currency;
        }

        $regular_amount = (float) ( $package->subscription_amount ?? $plan->price ?? 0 );

        if ( ! $is_recurring || $is_trial ) {
            $regular_amount = (float) ( $plan->price ?? $regular_amount );
        }

        $next_amount = $regular_amount;

        if ( $has_discount && function_exists( 'directorist_compute_order_total_amount' ) ) {
            $next_amount = directorist_compute_order_total_amount(
                (float) ( $plan->price ?? 0 ),
                isset( $order->tax_rate ) ? (float) $order->tax_rate : null,
                $order->tax_type ?? null,
                isset( $order->coupon_discount ) ? (float) $order->coupon_discount : null,
                $order->coupon_discount_type ?? null
            );
        }

        return [
            'first_amount'   => $first_amount,
            'next_amount'    => $next_amount,
            'regular_amount' => $regular_amount,
            'currency'       => $currency,
            'has_discount'   => $has_discount,
        ];
    }

    private function get_last_order( stdClass $package ): ?stdClass {
        if ( empty( $package->last_order_id ) || ! function_exists( 'directorist_get_order_by_id' ) ) {
            return null;
        }

        $order = directorist_get_order_by_id( (int) $package->last_order_id );

        return $order ?: null;
    }

    private function get_subscription_billing_breakdown( array $pricing, stdClass $package ): string {
        $is_recurring = ! empty( $package->is_recurring );

        if ( ! $is_recurring ) {
            return '';
        }

        $first_amount   = $this->format_currency( $pricing['first_amount'], $pricing['currency'] );
        $next_amount    = $this->format_currency( $pricing['next_amount'], $pricing['currency'] );
        $regular_amount = $this->format_currency( $pricing['regular_amount'], $pricing['currency'] );
        $rows           = [
            sprintf( '<p><strong>%s</strong> %s</p>', esc_html__( 'First period amount:', 'directorist-pricing-plans' ), esc_html( $first_amount ) ),
        ];

        if ( ! empty( $package->is_trial ) ) {
            $rows[] = sprintf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Trial period amount:', 'directorist-pricing-plans' ), esc_html( $first_amount ) );
            $rows[] = sprintf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Next billing amount:', 'directorist-pricing-plans' ), esc_html( $next_amount ) );

            if ( ! empty( $pricing['has_discount'] ) && $pricing['next_amount'] !== $pricing['regular_amount'] ) {
                $rows[] = sprintf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Regular renewal amount:', 'directorist-pricing-plans' ), esc_html( $regular_amount ) );
            }
        } else if ( ! empty( $pricing['has_discount'] ) && $pricing['first_amount'] !== $pricing['regular_amount'] ) {
            $rows[] = sprintf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Regular renewal amount:', 'directorist-pricing-plans' ), esc_html( $regular_amount ) );
        }

        return implode( '', array_unique( $rows ) );
    }

    /**
     * Format currency amount.
     *
     * @param float $amount Amount
     * @param string $currency Currency code
     * @return string Formatted currency
     */
    private function format_currency( float $amount, string $currency ): string {
        return "{$amount} {$currency}";
    }
}
