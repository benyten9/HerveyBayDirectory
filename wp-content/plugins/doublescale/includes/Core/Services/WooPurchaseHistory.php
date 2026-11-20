<?php
/**
 * WooCommerce purchase history of a contact.
 *
 * A contact has no WordPress user id of its own, so an order belongs to a
 * contact when its billing email is the contact's email, or when it was
 * placed on a site account whose login email is the contact's email. Looking
 * at only one of the two misses guest checkouts by account holders, or
 * account orders billed to another address.
 *
 * Shared by the "Last Purchase" contact filter (campaign audiences, contact
 * lists) and the "Last Order" automation condition.
 *
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\OrderStatus;
use DoubleScale\Core\Utils\DateWithin;
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * WooPurchaseHistory class
 */
final class WooPurchaseHistory {

	/**
	 * Keep contacts with (or, negated, without) a purchase since `$cutoff_gmt`.
	 *
	 * Reads whichever order storage WooCommerce uses: the orders table (HPOS)
	 * or the legacy posts table.
	 *
	 * @param Builder $query      Contacts query.
	 * @param string  $cutoff_gmt Y-m-d H:i:s, GMT.
	 * @param bool    $negate     True for "no purchase since" (never bought included).
	 * @return Builder
	 */
	public static function apply_purchased_since( Builder $query, $cutoff_gmt, $negate = false ) {
		global $wpdb;

		$contacts = $wpdb->prefix . 'doublescale_contacts';
		$statuses = OrderStatus::get_purchase_statuses();
		$in       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		if ( self::uses_orders_table() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$sql    = "SELECT 1 FROM {$orders} o
				WHERE o.type = 'shop_order'
				AND o.status IN ({$in})
				AND o.date_created_gmt >= %s
				AND {$contacts}.email <> ''
				AND (
					o.billing_email = {$contacts}.email
					OR o.customer_id IN ( SELECT u.ID FROM {$wpdb->users} u WHERE u.user_email = {$contacts}.email )
				)";
		} else {
			$sql = "SELECT 1 FROM {$wpdb->posts} o
				WHERE o.post_type = 'shop_order'
				AND o.post_status IN ({$in})
				AND o.post_date_gmt >= %s
				AND {$contacts}.email <> ''
				AND (
					EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = o.ID AND m.meta_key = '_billing_email' AND m.meta_value = {$contacts}.email )
					OR EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} m
						INNER JOIN {$wpdb->users} u ON u.ID = m.meta_value
						WHERE m.post_id = o.ID AND m.meta_key = '_customer_user' AND u.user_email = {$contacts}.email
					)
				)";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only; values are placeholders.
		$exists = $wpdb->prepare( $sql, array_merge( $statuses, array( $cutoff_gmt ) ) );

		$query->whereRaw( ( $negate ? 'NOT EXISTS' : 'EXISTS' ) . " ( {$exists} )" );

		return $query;
	}

	/**
	 * Start of the "within / not within" window, as a Unix time.
	 *
	 * The value is a day count (1–3650). A date (Y-m-d, or an ISO string
	 * starting with one) means "since the start of that day" in the site's
	 * timezone: Pro's admin bundle built before request 129 shows a calendar
	 * for "Not within", and ignoring its date would reach every buyer.
	 *
	 * @param mixed $value Condition value.
	 * @return int|null Null when the value is neither.
	 */
	public static function window_start( $value ) {
		$days = DateWithin::parse_days( $value );
		if ( null !== $days ) {
			return time() - $days * DAY_IN_SECONDS;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( $value ), $match ) ) {
			return null;
		}

		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $match[1], wp_timezone() );

		return $day ? $day->getTimestamp() : null;
	}

	/**
	 * Unix time of the contact's latest purchase, or null when there is none.
	 *
	 * @param string $email Contact email.
	 * @return int|null
	 */
	public static function latest_order_timestamp( $email ) {
		if ( ! is_string( $email ) || '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$lookups = array( array( 'billing_email' => $email ) );
		$user    = get_user_by( 'email', $email );
		if ( $user ) {
			$lookups[] = array( 'customer_id' => (int) $user->ID );
		}

		$latest = null;
		foreach ( $lookups as $lookup ) {
			$orders = wc_get_orders(
				array_merge(
					array(
						'limit'   => 1,
						'orderby' => 'date',
						'order'   => 'DESC',
						'status'  => OrderStatus::get_purchase_statuses(),
					),
					$lookup
				)
			);

			if ( empty( $orders ) || ! $orders[0] instanceof \WC_Order || ! $orders[0]->get_date_created() ) {
				continue;
			}

			$time   = $orders[0]->get_date_created()->getTimestamp();
			$latest = null === $latest ? $time : max( $latest, $time );
		}

		return $latest;
	}

	/**
	 * Whether WooCommerce reads orders from its own table (HPOS).
	 *
	 * @return bool
	 */
	private static function uses_orders_table() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
