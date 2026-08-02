<?php

namespace DirectoristPricingPlan\App\Providers\EmailService;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\App\Mail\PackageMailer;
use DirectoristPricingPlan\App\DTO\UserPackage\DTO as UserPackageDTO;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;

class PackageNotificationProvider implements Provider {
    /**
     * Boot the service provider.
     */
    public function boot() {
        add_action( 'directorist_package_created', [ $this, 'handle_package_created' ], 10, 1 );
        add_action( 'directorist_package_updated', [ $this, 'handle_package_updated' ], 10, 2 );
        add_action( 'after_schedule_package_expiration', [ $this, 'handle_schedule_expiration' ], 10, 1 );
        add_action( 'directorist_package_expiry_warning', [ $this, 'send_expiry_warning' ], 10, 1 );
        add_action( 'directorist_fallback_plan_activated', [ $this, 'handle_fallback_activation' ], 10, 2 );
    }

    /**
     * Handle package created event.
     *
     * @param UserPackageDTO $package_dto Package DTO
     */
    public function handle_package_created( UserPackageDTO $package_dto ) {
        if ( $package_dto->is_is_legacy() ) {
            return;
        }

        // Only send activation email for active packages
        if ( $package_dto->get_status() !== UserPackageStatus::ACTIVE ) {
            return;
        }

        // Check if package is trial or regular activation
        if ( $package_dto->is_is_trial() ) {
            $this->send_email( $package_dto->get_id(), 'trial_started' );
        } else {
            $this->send_email( $package_dto->get_id(), 'package_activated' );
        }
    }

    /**
     * Handle package updated event.
     *
     * @param UserPackageDTO $new_package_dto New package DTO
     * @param UserPackageDTO $old_package_dto Old package DTO
     */
    public function handle_package_updated( UserPackageDTO $new_package_dto, UserPackageDTO $old_package_dto ) {
        $new_status = $new_package_dto->get_status();
        $old_status = $old_package_dto->get_status();

        // Send notification when package is cancelled
        if ( $new_status === UserPackageStatus::CANCELLED && $old_status !== UserPackageStatus::CANCELLED ) {
            $this->send_email( $new_package_dto->get_id(), 'package_cancelled' );
            $this->clear_expiry_warning_cron( $new_package_dto->get_id() );
            return;
        }

        // Send notification when package is expired
        if ( $new_status === UserPackageStatus::EXPIRED && $old_status !== UserPackageStatus::EXPIRED ) {
            $this->send_email( $new_package_dto->get_id(), 'package_expired' );
            $this->clear_expiry_warning_cron( $new_package_dto->get_id() );
        }
    }

    /**
     * Handle schedule expiration event.
     *
     * @param UserPackageDTO $package_dto Package DTO
     */
    public function handle_schedule_expiration( UserPackageDTO $package_dto ) {
        // Don't schedule for cancelled packages
        if ( $package_dto->get_status() === UserPackageStatus::CANCELLED ) {
            return;
        }

        $expiry_date = $package_dto->get_current_period_end();

        if ( ! $expiry_date ) {
            return;
        }

        $expiry_timestamp  = $expiry_date->getTimestamp();
        $three_days_before = $expiry_timestamp - ( 3 * DAY_IN_SECONDS );
        $now               = time();

        // Clear any existing scheduled warning
        $this->clear_expiry_warning_cron( $package_dto->get_id() );

        // If expiry is less than 3 days away, send immediately
        if ( $three_days_before <= $now ) {
            $this->send_email( $package_dto->get_id(), 'package_expiring_soon' );
        } else {
            // Schedule for 3 days before expiry
            wp_schedule_single_event( 
                $three_days_before, 
                'directorist_package_expiry_warning', 
                [ [ 'package_id' => $package_dto->get_id() ] ] 
            );
        }
    }

    /**
     * Send expiry warning email (scheduled by cron).
     *
     * @param array $package_data Package data array
     */
    public function send_expiry_warning( array $package_data ) {
        $package_id = $package_data['package_id'] ?? null;

        if ( ! $package_id ) {
            return;
        }

        // Verify package still exists and is active
        $user_package_repository = directorist_user_package_repository();
        $package                 = $user_package_repository->get_by_id( $package_id );

        if ( ! $package || $package->status !== UserPackageStatus::ACTIVE ) {
            return;
        }

        $this->send_email( $package_id, 'package_expiring_soon' );
    }

    /**
     * Handle fallback plan activation.
     *
     * @param int $package_id New package ID
     * @param int $previous_plan_id Previous plan ID
     */
    public function handle_fallback_activation( int $package_id, int $previous_plan_id ) {
        $this->send_email( $package_id, 'fallback_plan_activated', $previous_plan_id );
    }

    /**
     * Clear expiry warning cron job.
     *
     * @param int $package_id Package ID
     */
    private function clear_expiry_warning_cron( int $package_id ) {
        wp_clear_scheduled_hook( 
            'directorist_package_expiry_warning', 
            [ [ 'package_id' => $package_id ] ] 
        );
    }

    /**
     * Send email notification.
     *
     * @param int $package_id Package ID
     * @param string $notification_type Type of notification
     * @param int|null $previous_plan_id Previous plan ID (for fallback notification)
     */
    private function send_email( int $package_id, string $notification_type, ?int $previous_plan_id = null ) {
        try {
            $user_package_repository = directorist_user_package_repository();
            $package                 = $user_package_repository->get_by_id( $package_id );

            if ( ! $package ) {
                return;
            }

            $user = get_userdata( $package->user_id );
            $plan = directorist_get_pricing_plan_by_id( $package->plan_id );

            if ( ! $user || ! $plan ) {
                return;
            }

            /**
             * @var PackageMailer $mailer
             */
            $mailer = directorist_pricing_plans_singleton( PackageMailer::class );

            switch ( $notification_type ) {
                case 'trial_started':
                    $mailer->send_trial_started( $package, $user, $plan );
                    break;
                case 'package_activated':
                    $mailer->send_package_activated( $package, $user, $plan );
                    break;
                case 'package_cancelled':
                    $mailer->send_package_cancelled( $package, $user, $plan );
                    break;
                case 'package_expired':
                    $mailer->send_package_expired( $package, $user, $plan );
                    break;
                case 'package_expiring_soon':
                    $mailer->send_package_expiring_soon( $package, $user, $plan );
                    break;
                case 'fallback_plan_activated':
                    if ( $previous_plan_id ) {
                        $previous_plan = directorist_get_pricing_plan_by_id( $previous_plan_id );
                        if ( $previous_plan ) {
                            $mailer->send_fallback_plan_activated( $package, $user, $plan, $previous_plan );
                        }
                    }
                    break;
            }
        } catch ( \Exception $e ) {
            error_log(
                sprintf( 
                    'Directorist Pricing Plans: Failed to send %s email for package #%d - %s', 
                    $notification_type, 
                    $package_id, 
                    $e->getMessage() 
                ) 
            );
        }
    }
}

