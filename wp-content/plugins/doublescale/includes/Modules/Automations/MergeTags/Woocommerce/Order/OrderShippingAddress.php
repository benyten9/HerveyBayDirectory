<?php
/**
 * Class Order Shipping Address
 *
 * This class is responsible for handling the order shipping address merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Order;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Order Shipping Address Merge Tag
 */
class OrderShippingAddress extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Order Shipping Address';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'shipping_address';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Order Shipping Address';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'order';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel automation_contact Contact Model.
	 * @param string                                                  $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$order_id = $contact->get_data( 'order_id' );
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		// Formats available: default, comma, address_1, address_2.
		$format = $this->get_format( $merge_tag );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
		$address          = apply_filters( 'woocommerce_order_formatted_shipping_address', $order->get_address( 'shipping' ), $order );
		$formated_address = '';
		switch ( $format ) {
			case 'default':
				$formated_address = WC()->countries->get_formatted_address( $address, '' );
				break;
			case 'comma':
				$formated_address = WC()->countries->get_formatted_address( $address, ', ' );
				break;
			case 'address_1':
				$formated_address = $address['address_1'];
				break;
			case 'address_2':
				$formated_address = $address['address_2'];
				break;
		}

		return $formated_address ? $formated_address : '';
	}

	/**
	 * Get the format from the merge tag
	 *
	 * @param string $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	private function get_format( $merge_tag ) {
		$format  = 'comma';
		$matches = array();
		preg_match( '/format=\'(.*?)\'/', $merge_tag, $matches );
		if ( ! empty( $matches[1] ) ) {
			$format = $matches[1];
		}

		return $format;
	}
}

MergeTagsManager::instance()->register( new OrderShippingAddress() );
