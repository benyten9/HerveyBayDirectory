<?php

namespace DirectoristPricingPlan\App\Contracts;

defined( "ABSPATH" ) || exit;

use stdClass;

interface PackageUsageInterface {
    public function get_plan_by_id( int $plan_id );
    public function has_plan_remaining_quota( stdClass $plan, bool $is_featured_listing ): bool;
    public function get_uses_by_plan( int $listing_owner_id, stdClass $plan ): array;
    public function get_listings_uses( int $listing_owner_id, stdClass $plan, bool $is_featured_listing ): array;
    public function get_regular_uses( int $listing_owner_id, stdClass $plan ): array;
    public function get_featured_uses( int $listing_owner_id, stdClass $plan ): array;
    public function get_regular_listings_count( int $listing_owner_id, int $directory_type_id ): int;
    public function get_featured_listings_count( int $listing_owner_id, int $directory_type_id ): int;
    public function get_plan_allowed_listings( stdClass $plan );
    public function get_plan_allowed_featured_listings( stdClass $plan );
    public function get_remaining_listings( int $allowed, int $used ): int;
}