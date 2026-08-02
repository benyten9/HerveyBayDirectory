<?php

/**
 * Subscription Items Merge Tag
 *
 * This class is responsible for handling the subscription items merge tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\MergeTags\Woocommerce\Subscription;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Subscription Items Merge Tag
 */
class SubscriptionItems extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Subscription Items';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'subscription_items';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Subscription Items';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'subscription';

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		$subscription_id = $contact->get_data( 'subscription_id' );
		if ( ! $subscription_id ) {
			return '';
		}

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof \WC_Subscription ) {
			return '';
		}

		$items = $subscription->get_items();
		if ( empty( $items ) ) {
			return '';
		}

		$format     = $this->get_format( $merge_tag );
		$items_list = array();

		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			switch ( $format ) {
				case 'name_only':
					$items_list[] = $item->get_name();
					break;
				case 'with_quantity':
					$items_list[] = sprintf( '%s x %d', $item->get_name(), $item->get_quantity() );
					break;
				case 'with_price':
					$items_list[] = sprintf( '%s - %s', $item->get_name(), wc_price( $item->get_total() ) );
					break;
				case 'detailed':
				default:
					$items_list[] = sprintf(
						'%s x %d - %s',
						$item->get_name(),
						$item->get_quantity(),
						wc_price( $item->get_total() )
					);
					break;
			}
		}

		$separator = $this->get_separator( $merge_tag );
		return implode( $separator, $items_list );
	}

	/**
	 * Get the format from the merge tag
	 *
	 * @param string $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	private function get_format( $merge_tag ) {
		$format  = 'detailed';
		$matches = array();
		preg_match( '/format=\'(.*?)\'/', $merge_tag, $matches );
		if ( ! empty( $matches[1] ) ) {
			$format = $matches[1];
		}

		return $format;
	}

	/**
	 * Get the separator from the merge tag
	 *
	 * @param string $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	private function get_separator( $merge_tag ) {
		$separator = ', ';
		$matches   = array();
		preg_match( '/separator=\'(.*?)\'/', $merge_tag, $matches );
		if ( ! empty( $matches[1] ) ) {
			$separator = $matches[1];
		}

		return $separator;
	}
}

MergeTagsManager::instance()->register( new SubscriptionItems() );
