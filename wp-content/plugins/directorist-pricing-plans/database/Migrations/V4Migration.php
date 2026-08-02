<?php

namespace DirectoristPricingPlan\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Migration;
use DirectoristPricingPlan\Database\Setup;

class V4Migration implements Migration {
    public function more_than_version() {
        return '3.9.9';
    }

    public function execute(): bool {
        $setup = new Setup();
        $setup->execute();
        $setup->mark_default_plans_as_initialized();

        return true;
    }
}
