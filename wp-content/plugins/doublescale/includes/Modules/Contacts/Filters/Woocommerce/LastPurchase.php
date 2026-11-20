<?php
/**
 * Class LastPurchase
 *
 * "Last Purchase within / not within N days" (client request 129): a month
 * of campaigns can leave out whoever already bought. Campaigns resolve their
 * audience when they send, so the window is measured from each send.
 *
 * Registered by the Contacts module only while WooCommerce is active.
 *
 * @since 1.7.2
 *
 * @package DoubleScale\Modules\Contacts\Filters\Woocommerce
 */

namespace DoubleScale\Modules\Contacts\Filters\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\WooPurchaseHistory;
use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * LastPurchase class
 */
class LastPurchase extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Last Purchase';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'woocommerce_last_purchase';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'woocommerce';

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'date';

	/**
	 * Get operators
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'within'     => __( 'Within', 'doublescale' ),
			'not_within' => __( 'Not within', 'doublescale' ),
		);
	}

	/**
	 * Apply filter
	 *
	 * The value is a day count, or a date meaning "since that day" (see
	 * WooPurchaseHistory::window_start()). Anything else applies no condition,
	 * like every other filter given an empty value.
	 *
	 * @param Builder $query  Query.
	 * @param array   $filter Rule.
	 *
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'within';
		$start    = WooPurchaseHistory::window_start( isset( $filter['value'] ) ? $filter['value'] : '' );

		if ( null === $start || ! in_array( $operator, array( 'within', 'not_within' ), true ) ) {
			return $query;
		}

		return WooPurchaseHistory::apply_purchased_since(
			$query,
			gmdate( 'Y-m-d H:i:s', $start ),
			'not_within' === $operator
		);
	}
}
