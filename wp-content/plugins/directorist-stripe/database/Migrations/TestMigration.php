<?php

namespace DirectoristStripe\Database\Migrations;

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\Contracts\Migration;

class TestMigration implements Migration {
    public function more_than_version() {
        return '1.0.0';
    }

    public function execute(): bool {
        return true;
    }
}