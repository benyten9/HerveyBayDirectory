<?php

namespace DirectoristPricingPlan\App\Services;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\App\Enums\Plan\Type as PlanType;
use DirectoristPricingPlan\App\Enums\UserPackage\Status as UserPackageStatus;
use DirectoristPricingPlan\App\Repositories\UserPackageRepository;
use DirectoristPricingPlan\WpMVC\Exceptions\Exception;

class ExpiredListingRenewalService {
    private UserPackageRepository $package_repository;

    public function __construct( UserPackageRepository $package_repository ) {
        $this->package_repository = $package_repository;
    }

    /**
     * Get packages with expired listings that can be renewed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_eligible_packages( int $user_id ): array {
        $packages = $this->package_repository->get_query_builder()
            ->select( 'package.id' )
            ->where( 'package.user_id', $user_id )
            ->where_in( 'package.status', [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ] )
            ->order_by_desc( 'package.id' )
            ->get();

        $summaries = [];

        foreach ( $packages as $package ) {
            $summary = $this->get_summary( (int) $package->id, $user_id );

            if ( $summary ) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * Get the renewable-listing summary for a package.
     *
     * @return array<string, mixed>|null
     */
    public function get_summary( int $package_id, ?int $user_id = null ): ?array {
        $context = $this->build_context( $package_id, $user_id, false );

        return $context ? $context['summary'] : null;
    }

    /**
     * Get renewal details for a single expired listing.
     *
     * @return array<string, mixed>|null
     */
    public function get_listing_summary( int $listing_id, ?int $user_id = null ): ?array {
        $context = $this->build_listing_context( $listing_id, $user_id, false );

        return $context ? $context['summary'] : null;
    }

    /**
     * Validate that checkout can renew an expired pay-per-listing assignment.
     *
     * @return array<string, mixed>
     */
    public function validate_pay_per_listing_checkout( int $listing_id, int $plan_id, int $user_id ): array {
        $context = $this->build_listing_context( $listing_id, $user_id, true );
        $plan    = $context['plan'];

        if ( (int) $plan->id !== $plan_id || PlanType::PAY_PER_LISTING !== ( $plan->type ?? PlanType::PACKAGE ) ) {
            throw new Exception( esc_html__( 'This plan cannot renew the assigned listing.', 'directorist-pricing-plans' ), 400 );
        }

        return $context['summary'];
    }

    /**
     * Complete a paid pay-per-listing renewal.
     *
     * @return array<string, mixed>
     */
    public function complete_pay_per_listing_renewal( int $listing_id, int $plan_id, int $user_id ): array {
        $context      = $this->build_listing_context( $listing_id, $user_id, true );
        $plan         = $context['plan'];
        $was_featured = (bool) $context['summary']['was_featured'];

        if ( (int) $plan->id !== $plan_id || PlanType::PAY_PER_LISTING !== ( $plan->type ?? PlanType::PACKAGE ) ) {
            throw new Exception( esc_html__( 'This plan cannot renew the assigned listing.', 'directorist-pricing-plans' ), 400 );
        }

        directorist_set_listing_featured( $listing_id, ! empty( $plan->is_featured ) );

        if ( ! directorist_set_listing_status( $listing_id, 'publish' ) ) {
            directorist_set_listing_featured( $listing_id, $was_featured );
            throw new Exception( esc_html__( 'The listing could not be renewed after payment.', 'directorist-pricing-plans' ), 500 );
        }

        directorist_pricing_plan_apply_plan_listing_expiration( $listing_id, $plan );

        return [
            'renewed'    => true,
            'listing_id' => $listing_id,
            'plan_id'    => (int) $plan->id,
            'featured'   => ! empty( $plan->is_featured ),
        ];
    }

    /**
     * Renew one expired listing from its assigned package plan.
     *
     * @return array<string, mixed>
     */
    public function renew_listing( int $listing_id, ?int $user_id = null ): array {
        $context = $this->build_listing_context( $listing_id, $user_id, true );
        $package = $context['package'];
        $plan    = $context['plan'];

        if ( PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE ) ) {
            throw new Exception( esc_html__( 'Payment is required to renew this listing.', 'directorist-pricing-plans' ), 400 );
        }

        $was_featured  = (bool) $context['summary']['was_featured'];
        $keep_featured = (bool) $context['summary']['will_be_featured'];
        $should_demote = $was_featured && ! $keep_featured;

        if ( $should_demote && ! directorist_set_listing_featured( $listing_id, false ) ) {
            throw new Exception( esc_html__( 'The listing could not be renewed as a regular listing.', 'directorist-pricing-plans' ), 500 );
        }

        if ( ! directorist_set_listing_status( $listing_id, 'publish' ) ) {
            if ( $should_demote ) {
                directorist_set_listing_featured( $listing_id, true );
            }

            throw new Exception( esc_html__( 'The listing could not be renewed.', 'directorist-pricing-plans' ), 500 );
        }

        directorist_pricing_plan_apply_package_listing_expiration(
            $listing_id,
            $package->current_period_end ?: null
        );

        return [
            'renewed'              => true,
            'listing_id'           => $listing_id,
            'plan_id'              => (int) $plan->id,
            'featured'             => $keep_featured,
            'converted_to_regular' => $should_demote,
        ];
    }

    /**
     * Renew expired listings up to the package's available quota.
     *
     * @return array<string, int>
     */
    public function renew( int $package_id, ?int $user_id = null ): array {
        $context = $this->build_context( $package_id, $user_id, true );
        $package = $context['package'];
        $summary = $context['summary'];
        $limit   = (int) $summary['renewable_count'];

        $renewed_count          = 0;
        $featured_count         = 0;
        $regular_count          = 0;
        $converted_to_regular   = 0;
        $featured_remaining     = (int) $summary['featured_remaining'];
        $has_unlimited_featured = -1 === $featured_remaining;

        foreach ( $context['listing_ids'] as $listing_id ) {
            if ( $renewed_count >= $limit ) {
                break;
            }

            if ( 'expired' !== get_post_status( $listing_id )
                || (int) $package->plan_id !== (int) get_post_meta( $listing_id, directorist_plan_key(), true )
            ) {
                continue;
            }

            $was_featured     = directorist_is_listing_featured( $listing_id );
            $keep_featured    = $was_featured && ( $has_unlimited_featured || $featured_remaining > 0 );
            $should_demote    = $was_featured && ! $keep_featured;
            $demotion_success = true;

            if ( $should_demote ) {
                $demotion_success = directorist_set_listing_featured( $listing_id, false );
            }

            if ( ! $demotion_success || ! directorist_set_listing_status( $listing_id, 'publish' ) ) {
                if ( $should_demote && $demotion_success ) {
                    directorist_set_listing_featured( $listing_id, true );
                }
                continue;
            }

            directorist_pricing_plan_apply_package_listing_expiration(
                $listing_id,
                $package->current_period_end ?: null
            );

            $renewed_count++;

            if ( $keep_featured ) {
                $featured_count++;

                if ( ! $has_unlimited_featured ) {
                    $featured_remaining--;
                }
            } else {
                $regular_count++;

                if ( $should_demote ) {
                    $converted_to_regular++;
                }
            }
        }

        return [
            'renewed_count'        => $renewed_count,
            'featured_count'       => $featured_count,
            'regular_count'        => $regular_count,
            'converted_to_regular' => $converted_to_regular,
            'remaining_expired'    => count( $this->get_expired_listing_ids( $package ) ),
        ];
    }

    /**
     * Build and validate a package renewal context.
     *
     * @return array<string, mixed>|null
     */
    private function build_context( int $package_id, ?int $user_id, bool $throw ): ?array {
        $package = $this->package_repository->get_by_id( $package_id );

        if ( ! $package ) {
            return $this->invalid_context( $throw, __( 'Package not found.', 'directorist-pricing-plans' ), 404 );
        }

        if ( null !== $user_id && (int) $package->user_id !== $user_id ) {
            return $this->invalid_context( $throw, __( 'You are not authorized to renew listings for this package.', 'directorist-pricing-plans' ), 403 );
        }

        if ( ! in_array( $package->status, [ UserPackageStatus::ACTIVE, UserPackageStatus::CANCELLED_AT_PERIOD_END ], true ) ) {
            return $this->invalid_context( $throw, __( 'Only active packages can renew expired listings.', 'directorist-pricing-plans' ), 400 );
        }

        if ( ! empty( $package->current_period_end ) && directorist_to_timestamp( $package->current_period_end ) <= current_time( 'timestamp' ) ) {
            return $this->invalid_context( $throw, __( 'This package has expired.', 'directorist-pricing-plans' ), 400 );
        }

        $plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );

        if ( ! $plan || PlanType::PACKAGE !== ( $plan->type ?? PlanType::PACKAGE ) ) {
            return $this->invalid_context( $throw, __( 'This package cannot renew listings in bulk.', 'directorist-pricing-plans' ), 400 );
        }

        $usage              = directorist_package_usage( ! empty( $package->is_legacy ) );
        $listing_usage      = $usage->get_total_uses( (int) $package->user_id, $plan );
        $featured_usage     = $usage->get_featured_uses( (int) $package->user_id, $plan );
        $remaining          = (int) $listing_usage['remaining'];
        $featured_remaining = (int) $featured_usage['remaining'];

        if ( 0 === $remaining ) {
            return $this->invalid_context( $throw, __( 'This package has no remaining listing quota.', 'directorist-pricing-plans' ), 409 );
        }

        $listing_ids = $this->get_expired_listing_ids( $package );

        if ( empty( $listing_ids ) ) {
            return $this->invalid_context( $throw, __( 'This package has no expired listings to renew.', 'directorist-pricing-plans' ), 409 );
        }

        $renewable_count = -1 === $remaining ? count( $listing_ids ) : min( count( $listing_ids ), $remaining );
        $directory       = get_term( (int) $package->directory_type_id, ATBDP_DIRECTORY_TYPE );

        return [
            'package'     => $package,
            'plan'        => $plan,
            'listing_ids' => $listing_ids,
            'summary'     => [
                'package_id'         => (int) $package->id,
                'plan_id'            => (int) $plan->id,
                'plan_title'         => (string) $plan->title,
                'directory_type_id'  => (int) $package->directory_type_id,
                'directory_type'     => $directory && ! is_wp_error( $directory ) ? $directory->name : '',
                'expired_count'      => count( $listing_ids ),
                'renewable_count'    => $renewable_count,
                'remaining_quota'    => $remaining,
                'featured_remaining' => $featured_remaining,
            ],
        ];
    }

    /**
     * Build and validate a single-listing renewal context.
     *
     * @return array<string, mixed>|null
     */
    private function build_listing_context( int $listing_id, ?int $user_id, bool $throw ): ?array {
        $listing = get_post( $listing_id );

        if ( ! $listing || ATBDP_POST_TYPE !== $listing->post_type ) {
            return $this->invalid_context( $throw, __( 'The listing was not found.', 'directorist-pricing-plans' ), 404 );
        }

        if ( null !== $user_id && (int) $listing->post_author !== $user_id ) {
            return $this->invalid_context( $throw, __( 'You are not authorized to renew this listing.', 'directorist-pricing-plans' ), 403 );
        }

        if ( 'expired' !== $listing->post_status ) {
            return $this->invalid_context( $throw, __( 'Only expired listings can be renewed.', 'directorist-pricing-plans' ), 400 );
        }

        $plan_id = (int) get_post_meta( $listing_id, directorist_plan_key(), true );

        if ( ! $plan_id ) {
            return $this->invalid_context( $throw, __( 'This listing does not have an assigned plan.', 'directorist-pricing-plans' ), 400 );
        }

        $package = directorist_get_listing_package( $listing_id );

        if ( ! $package || (int) $package->plan_id !== $plan_id || ! directorist_is_package_active( $package ) ) {
            return $this->invalid_context( $throw, __( 'The assigned plan does not have an active package.', 'directorist-pricing-plans' ), 400 );
        }

        if ( ! empty( $package->current_period_end ) && directorist_to_timestamp( $package->current_period_end ) <= current_time( 'timestamp' ) ) {
            return $this->invalid_context( $throw, __( 'The assigned package has expired.', 'directorist-pricing-plans' ), 400 );
        }

        if ( (int) $package->directory_type_id !== directorist_get_listings_directory_type( $listing_id ) ) {
            return $this->invalid_context( $throw, __( 'The assigned package is not compatible with this listing directory.', 'directorist-pricing-plans' ), 400 );
        }

        $plan = directorist_get_pricing_plan_by_id( $plan_id );

        if ( ! $plan ) {
            return $this->invalid_context( $throw, __( 'The assigned plan is not available anymore.', 'directorist-pricing-plans' ), 404 );
        }

        $was_featured       = directorist_is_listing_featured( $listing_id );
        $will_be_featured   = PlanType::PAY_PER_LISTING === ( $plan->type ?? PlanType::PACKAGE )
            ? ! empty( $plan->is_featured )
            : $was_featured;
        $remaining          = -1;
        $featured_remaining = -1;

        if ( PlanType::PACKAGE === ( $plan->type ?? PlanType::PACKAGE ) ) {
            $usage         = directorist_package_usage( ! empty( $package->is_legacy ) );
            $listing_usage = $usage->get_total_uses( (int) $listing->post_author, $plan );
            $remaining     = (int) $listing_usage['remaining'];

            if ( 0 === $remaining ) {
                return $this->invalid_context( $throw, __( 'The assigned package has no remaining listing quota.', 'directorist-pricing-plans' ), 409 );
            }

            if ( $was_featured ) {
                $featured_usage     = $usage->get_featured_uses( (int) $listing->post_author, $plan );
                $featured_remaining = (int) $featured_usage['remaining'];

                if ( 0 === $featured_remaining ) {
                    $will_be_featured = false;
                }
            }
        }

        return [
            'listing' => $listing,
            'package' => $package,
            'plan'    => $plan,
            'summary' => [
                'listing_id'         => $listing_id,
                'package_id'         => (int) $package->id,
                'plan_id'            => (int) $plan->id,
                'plan_type'          => (string) ( $plan->type ?? PlanType::PACKAGE ),
                'remaining_quota'    => $remaining,
                'featured_remaining' => $featured_remaining,
                'was_featured'       => $was_featured,
                'will_be_featured'   => $will_be_featured,
            ],
        ];
    }

    /**
     * Get expired listings that belong to the package, newest first.
     *
     * @return int[]
     */
    private function get_expired_listing_ids( object $package ): array {
        $meta_query = [
            [
                'key'     => directorist_plan_key(),
                'value'   => (int) $package->plan_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ],
        ];

        $listing_ids = array_map(
            'intval',
            get_posts(
                [
                    'post_type'      => ATBDP_POST_TYPE,
                    'post_status'    => 'expired',
                    'author'         => (int) $package->user_id,
                    'fields'         => 'ids',
                    'posts_per_page' => -1,
                    'orderby'        => [
                        'date' => 'DESC',
                        'ID'   => 'DESC',
                    ],
                    'meta_query'     => $meta_query,
                ]
            )
        );

        return $listing_ids;
    }

    /**
     * Return null for discovery requests or throw for renewal requests.
     */
    private function invalid_context( bool $throw, string $message, int $code ): ?array {
        if ( $throw ) {
            throw new Exception( esc_html( $message ), $code );
        }

        return null;
    }
}
