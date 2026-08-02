<?php

/**
 * Class Review Rating Count
 *
 * This class is responsible for handling the review rating count rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\WoocommerceReview;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Review Rating Count class
 */
class ReviewRatingCount extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Review Rating Count';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'review_rating_count';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'woocommerce_review';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'number';



	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is_equal_to'                 => __( 'is equal to', 'doublescale' ),
			'is_not_equal_to'             => __( 'is not equal to', 'doublescale' ),
			'is_greater_than'             => __( 'is greater than', 'doublescale' ),
			'is_less_than'                => __( 'is less than', 'doublescale' ),
			'is_greater_than_or_equal_to' => __( 'is greater than or equal to', 'doublescale' ),
			'is_less_than_or_equal_to'    => __( 'is less than or equal to', 'doublescale' ),
		);
	}



	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		$review_rating = $automation_contact->get_data( 'review_rating' );
		return $review_rating;
	}

	/**
	 * Is met
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @param array                  $rule Rule.
	 *
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = $this->get_value( $automation_contact );
		$operator   = $rule['operator'];
		$rule_value = $rule['value'];

		switch ( $operator ) {
			case 'is_equal_to':
				return $value == $rule_value;
			case 'is_not_equal_to':
				return $value != $rule_value;
			case 'is_greater_than':
				return $value > $rule_value;
			case 'is_less_than':
				return $value < $rule_value;
			case 'is_greater_than_or_equal_to':
				return $value >= $rule_value;
			case 'is_less_than_or_equal_to':
				return $value <= $rule_value;
			default:
				return false;
		}
	}
}


RulesManager::instance()->register( new ReviewRatingCount() );
