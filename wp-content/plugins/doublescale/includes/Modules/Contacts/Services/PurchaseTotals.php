<?php
/**
 * What one or more contacts bought from the store: paid WooCommerce, EDD and
 * FluentCart orders and their revenue. Feeds the "Purchases" tile of the contact and
 * company pages when a figure of a module that is off leaves room.
 *
 * Same audience as the contact's purchase history: CRM managers.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\OrderStatus;
use DoubleScale\Core\Services\FluentCartOrders;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * PurchaseTotals class
 */
class PurchaseTotals {

	/**
	 * Whether a store is there and the user may see what contacts bought.
	 */
	public static function available(): bool {
		return ( self::woo() || self::edd() || FluentCartOrders::is_active() ) && Permissions::has_crm_manager_access();
	}

	/**
	 * Paid orders and revenue of the contacts.
	 *
	 * @param int[] $contact_ids Contact ids.
	 * @return array{orders: int, revenue: float|null, currency: string|null}|null Null when not available.
	 */
	public static function for_contacts( array $contact_ids ): ?array {
		if ( ! self::available() ) {
			return null;
		}

		$orders      = 0;
		$by_currency = array();
		$contacts    = $contact_ids ? ContactModel::whereIn( 'id', array_map( 'intval', $contact_ids ) )->get() : array();

		foreach ( $contacts as $contact ) {
			if ( self::woo() && is_email( (string) $contact->email ) ) {
				try {
					$paid = wc_get_orders(
						array(
							'customer' => (string) $contact->email,
							'status'   => OrderStatus::get_revenue_statuses(),
							'limit'    => -1,
						)
					);
					foreach ( $paid as $order ) {
						$currency                 = (string) $order->get_currency();
						$by_currency[ $currency ] = ( $by_currency[ $currency ] ?? 0 ) + (float) $order->get_total();
						++$orders;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( self::edd() ) {
				try {
					$default = function_exists( 'edd_get_option' ) ? (string) edd_get_option( 'currency', 'USD' ) : 'USD';
					foreach ( $contact->edd_revenue_orders_query()->get( array( 'total', 'currency' ) ) as $order ) {
						$currency                 = (string) ( $order->currency ?: $default );
						$by_currency[ $currency ] = ( $by_currency[ $currency ] ?? 0 ) + (float) $order->total;
						++$orders;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( FluentCartOrders::is_active() && is_email( (string) $contact->email ) ) {
				$summary = FluentCartOrders::sale_summary_for_email( (string) $contact->email );
				foreach ( $summary['revenue_by_currency'] as $currency => $amount ) {
					$by_currency[ $currency ] = ( $by_currency[ $currency ] ?? 0 ) + (float) $amount;
				}
				$orders += $summary['count'];
			}
		}

		// One currency: a single figure; several: the order count only.
		return array(
			'orders'   => $orders,
			'revenue'  => count( $by_currency ) <= 1 ? (float) ( reset( $by_currency ) ?: 0 ) : null,
			'currency' => 1 === count( $by_currency ) ? (string) key( $by_currency ) : self::store_currency(),
		);
	}

	/**
	 * Whether WooCommerce is on.
	 */
	private static function woo(): bool {
		return function_exists( 'wc_get_orders' ) && function_exists( 'doublescale_is_plugin_active' ) && doublescale_is_plugin_active( 'woocommerce' );
	}

	/**
	 * Whether Easy Digital Downloads is on.
	 */
	private static function edd(): bool {
		return function_exists( 'edd_get_option' ) && function_exists( 'doublescale_is_plugin_active' ) && doublescale_is_plugin_active( 'easy-digital-downloads' );
	}

	/**
	 * The store's currency, for an empty total.
	 */
	private static function store_currency(): ?string {
		if ( self::woo() && function_exists( 'get_woocommerce_currency' ) ) {
			return (string) get_woocommerce_currency();
		}
		if ( self::edd() ) {
			return (string) edd_get_option( 'currency', 'USD' );
		}
		return FluentCartOrders::is_active() ? FluentCartOrders::store_currency() : null;
	}
}
