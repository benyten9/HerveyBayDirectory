<?php
/**
 * Class Abandoned_Cart_Table
 *
 * This class is responsible for handling the Abandoned_Cart_Table table
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Migrations;



defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;
/**
 * Abandoned_Cart_Table Table class
 */
class AbandonedCartsTable extends Migration {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $table_name = 'abandoned_carts';

	/**
	 * Get query
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_query() {
		/**
		 * Fields:
		 *
		 * id: Primary key
		 * hash_key: Hash key
		 * user_id: User ID
		 * email: Email
		 * items: Items in the cart
		 * coupons: Coupons in the cart
		 * total: Total amount
		 * fees: Fees
		 * taxes: Taxes
		 * shipping: Shipping
		 * currency: Currency
		 * fields: Fields
		 * order_id: Order ID
		 * status: Status of the cart
		 * created_at: Created at timestamp
		 * updated_at: Updated at timestamp
		 */
		$query = 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			hash_key VARCHAR(255) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
			email VARCHAR(255) NOT NULL,
			fields LONGTEXT NOT NULL,
            items LONGTEXT NOT NULL,
            coupons LONGTEXT NOT NULL,
            total DECIMAL(10, 2) NOT NULL,
            fees LONGTEXT NOT NULL,
            taxes LONGTEXT NOT NULL,
            shipping DECIMAL(10, 2) NOT NULL,
            currency VARCHAR(255) NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
			KEY hash_key (hash_key(191)),
            KEY user_id (user_id),
            KEY order_id (order_id)';

		return $query;
	}
}
