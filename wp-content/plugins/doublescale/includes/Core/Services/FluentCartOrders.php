<?php
/**
 * FluentCart orders, read straight from FluentCart's tables.
 *
 * One place for everything DoubleScale needs from a FluentCart store: the
 * contact's purchase history, the "FluentCart Current Order" automation rules,
 * the order merge tags and the Pro triggers/goal. Reading the tables (not
 * FluentCart's ORM) keeps this independent of FluentCart's internal API, which
 * changes between releases.
 *
 * FluentCart keeps every amount in cents, whatever the currency.
 *
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

/**
 * FluentCartOrders class
 */
final class FluentCartOrders {

	/**
	 * Payment statuses whose orders count as sales (purchase history, totals).
	 *
	 * The same list FluentCart counts a customer's purchases with
	 * (Status::getOrderPaymentSuccessStatuses()). A partly refunded order is
	 * still a sale; its refunded part is taken off the revenue.
	 */
	public const REVENUE_PAYMENT_STATUSES = array( 'paid', 'partially_refunded', 'partially_paid' );

	/**
	 * Net paid amount of an order row, in cents: paid less refunded, never
	 * below zero. FluentCart's own formula for a customer's lifetime value
	 * (Customer::recountStat()), so DoubleScale shows the store's figure.
	 */
	private const NET_PAID_SQL = 'CASE WHEN COALESCE(o.total_paid, 0) > COALESCE(o.total_refund, 0) THEN COALESCE(o.total_paid, 0) - COALESCE(o.total_refund, 0) ELSE 0 END';

	/**
	 * Whether FluentCart is active on this site.
	 */
	public static function is_active(): bool {
		// The active-plugins list as well as the constant: DoubleScale loads
		// before FluentCart, so the constant is not defined yet early on.
		return function_exists( 'doublescale_is_plugin_active' )
			? doublescale_is_plugin_active( 'fluent-cart' )
			: defined( 'FLUENTCART_VERSION' );
	}

	/**
	 * Cents to a decimal amount.
	 *
	 * @param mixed $cents Amount in cents.
	 */
	public static function to_amount( $cents ): float {
		return round( ( (float) $cents ) / 100, 2 );
	}

	/**
	 * One order with its customer, billing details and products.
	 *
	 * @param int $order_id FluentCart order id.
	 * @return array<string, mixed>|null Null when FluentCart is off or the order is gone.
	 */
	public static function find_order( int $order_id ): ?array {
		global $wpdb;

		if ( $order_id <= 0 || ! self::is_active() ) {
			return null;
		}

		$orders    = $wpdb->prefix . 'fct_orders';
		$customers = $wpdb->prefix . 'fct_customers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FluentCart's own tables; names come from $wpdb->prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.*, c.email AS customer_email, c.first_name AS customer_first_name, c.last_name AS customer_last_name, c.user_id AS customer_user_id
				FROM {$orders} o
				LEFT JOIN {$customers} c ON c.id = o.customer_id
				WHERE o.id = %d",
				$order_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$items    = self::order_items( $order_id );
		$billing  = self::billing_address( $order_id );
		$currency = strtoupper( (string) $row['currency'] );

		return array(
			'id'                   => (int) $row['id'],
			'receipt_number'       => (string) ( $row['receipt_number'] ?? '' ),
			'invoice_no'           => (string) ( $row['invoice_no'] ?? '' ),
			'reference'            => self::reference( $row ),
			'type'                 => (string) $row['type'],
			'status'               => (string) $row['status'],
			'payment_status'       => (string) $row['payment_status'],
			'shipping_status'      => (string) ( $row['shipping_status'] ?? '' ),
			'payment_method_title' => (string) ( $row['payment_method_title'] ?? '' ),
			'currency'             => $currency,
			'total'                => self::to_amount( $row['total_amount'] ),
			'total_paid'           => self::to_amount( $row['total_paid'] ),
			'total_refund'         => self::to_amount( $row['total_refund'] ),
			'created_at'           => (string) $row['created_at'],
			'customer_id'          => (int) $row['customer_id'],
			'email'                => (string) ( $row['customer_email'] ?? '' ),
			'first_name'           => (string) ( $row['customer_first_name'] ?? '' ),
			'last_name'            => (string) ( $row['customer_last_name'] ?? '' ),
			'user_id'              => (int) ( $row['customer_user_id'] ?? 0 ),
			'phone'                => $billing['phone'],
			'country'              => $billing['country'],
			'address_1'            => $billing['address_1'],
			'city'                 => $billing['city'],
			'state'                => $billing['state'],
			'zip'                  => $billing['zip'],
			'product_ids'          => array_values( array_unique( array_map( 'intval', wp_list_pluck( $items, 'post_id' ) ) ) ),
			'product_names'        => array_values( array_filter( wp_list_pluck( $items, 'title' ) ) ),
			'admin_url'            => admin_url( 'admin.php?page=fluent-cart#/orders/' . (int) $row['id'] . '/view' ),
		);
	}

	/**
	 * The order id inside a FluentCart hook payload.
	 *
	 * FluentCart passes `array( 'order' => Order, ... )`; the order is a model
	 * object, an array once serialised by a queue.
	 *
	 * @param mixed $payload Hook argument.
	 */
	public static function order_id_from_payload( $payload ): int {
		$order = is_array( $payload ) ? ( $payload['order'] ?? null ) : null;

		if ( is_object( $order ) && isset( $order->id ) ) {
			return (int) $order->id;
		}
		if ( is_array( $order ) && isset( $order['id'] ) ) {
			return (int) $order['id'];
		}

		return 0;
	}

	/**
	 * Arguments for an automation trigger: who the contact is, and the order in `data`.
	 *
	 * @param array<string, mixed> $order Order from {@see self::find_order()}.
	 * @param array<string, mixed> $extra Extra keys for `data`.
	 * @return array<string, mixed>
	 */
	public static function trigger_args( array $order, array $extra = array() ): array {
		$args = array(
			'email'      => $order['email'],
			'first_name' => $order['first_name'],
			'last_name'  => $order['last_name'],
			'data'       => array_merge(
				array(
					'order_id'    => $order['id'],
					'product_ids' => $order['product_ids'],
					'total'       => $order['total'],
					'currency'    => $order['currency'],
				),
				$extra
			),
		);

		// The billing details the buyer typed at checkout; empty ones are left
		// out so they never blank a value the contact already has.
		foreach ( array( 'phone', 'country', 'address_1', 'city', 'state', 'zip' ) as $field ) {
			if ( '' !== $order[ $field ] ) {
				$args[ $field ] = $order[ $field ];
			}
		}

		return $args;
	}

	/**
	 * Sale orders of the customers with this email, newest first.
	 *
	 * @param string $email    Contact email.
	 * @param int    $limit    Page size.
	 * @param int    $offset   Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sale_orders_for_email( string $email, int $limit, int $offset ): array {
		global $wpdb;

		if ( ! self::is_active() || ! is_email( $email ) ) {
			return array();
		}

		list( $where, $params ) = self::sale_orders_where( $email );
		$orders                 = $wpdb->prefix . 'fct_orders';
		$customers              = $wpdb->prefix . 'fct_customers';
		$params[]               = max( 1, $limit );
		$params[]               = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- FluentCart's own tables; $where holds only placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id, o.receipt_number, o.invoice_no, o.type, o.status, o.payment_status, o.currency, o.total_amount, o.total_paid, o.total_refund, o.created_at
				FROM {$orders} o
				INNER JOIN {$customers} c ON c.id = o.customer_id
				WHERE {$where}
				ORDER BY o.created_at DESC, o.id DESC
				LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'             => (int) $row['id'],
					'number'         => self::reference( $row ),
					'type'           => (string) $row['type'],
					'status'         => (string) $row['status'],
					'payment_status' => (string) $row['payment_status'],
					'total_amount'   => self::to_amount( $row['total_amount'] ),
					'currency'       => strtoupper( (string) $row['currency'] ),
					'date'           => (string) $row['created_at'],
					'url'            => admin_url( 'admin.php?page=fluent-cart#/orders/' . (int) $row['id'] . '/view' ),
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Count, net revenue per currency and last order date of the sale orders.
	 *
	 * Net revenue is what was paid less what was refunded.
	 *
	 * @param string $email Contact email.
	 * @return array{count: int, count_by_currency: array<string, int>, revenue_by_currency: array<string, float>, last_order: string|null}
	 */
	public static function sale_summary_for_email( string $email ): array {
		global $wpdb;

		$summary = array(
			'count'               => 0,
			'count_by_currency'   => array(),
			'revenue_by_currency' => array(),
			'last_order'          => null,
		);

		if ( ! self::is_active() || ! is_email( $email ) ) {
			return $summary;
		}

		list( $where, $params ) = self::sale_orders_where( $email );
		$orders                 = $wpdb->prefix . 'fct_orders';
		$customers              = $wpdb->prefix . 'fct_customers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- FluentCart's own tables; $where holds only placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT UPPER(o.currency) AS currency, COUNT(*) AS orders, SUM(' . self::NET_PAID_SQL . ") AS net, MAX(o.created_at) AS last_order
				FROM {$orders} o
				INNER JOIN {$customers} c ON c.id = o.customer_id
				WHERE {$where}
				GROUP BY UPPER(o.currency)",
				$params
			),
			ARRAY_A
		);

		foreach ( $rows ?: array() as $row ) {
			$currency                                   = (string) $row['currency'];
			$summary['count']                          += (int) $row['orders'];
			$summary['count_by_currency'][ $currency ]   = (int) $row['orders'];
			$summary['revenue_by_currency'][ $currency ] = self::to_amount( $row['net'] );
			if ( null === $summary['last_order'] || $row['last_order'] > $summary['last_order'] ) {
				$summary['last_order'] = (string) $row['last_order'];
			}
		}

		return $summary;
	}

	/**
	 * The store's currency.
	 */
	public static function store_currency(): string {
		$currency = '';
		if ( self::is_active() && class_exists( '\FluentCart\Api\CurrencySettings' ) ) {
			try {
				$currency = (string) \FluentCart\Api\CurrencySettings::get( 'currency' );
			} catch ( \Throwable $e ) {
				$currency = '';
			}
		}
		if ( '' === $currency ) {
			$settings = get_option( 'fluent_cart_store_settings', array() );
			$currency = is_array( $settings ) ? (string) ( $settings['currency'] ?? '' ) : '';
		}

		return '' !== $currency ? strtoupper( $currency ) : 'USD';
	}

	/**
	 * Published products, id => title, for rule and trigger pickers.
	 *
	 * @return array<int, string>
	 */
	public static function product_options(): array {
		if ( ! self::is_active() ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => 'fluent-products',
				'post_status'      => 'publish',
				'numberposts'      => 500,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$options = array();
		foreach ( $posts as $post ) {
			$options[ (int) $post->ID ] = $post->post_title;
		}

		return $options;
	}

	/**
	 * FluentCart's order statuses.
	 *
	 * @return array<string, string>
	 */
	public static function order_statuses(): array {
		return array(
			'processing' => __( 'Processing', 'doublescale' ),
			'completed'  => __( 'Completed', 'doublescale' ),
			'on-hold'    => __( 'On Hold', 'doublescale' ),
			'canceled'   => __( 'Canceled', 'doublescale' ),
			'failed'     => __( 'Failed', 'doublescale' ),
		);
	}

	/**
	 * The order number FluentCart shows the customer in its emails: the
	 * invoice number (INV-12), or the order id when there is none
	 * (OrderParser::getOrderRef()).
	 *
	 * @param array<string, mixed> $row Order row.
	 */
	private static function reference( array $row ): string {
		$invoice = trim( (string) ( $row['invoice_no'] ?? '' ) );

		return '' !== $invoice ? $invoice : (string) $row['id'];
	}

	/**
	 * WHERE clause and parameters for the sale orders of an email.
	 *
	 * @param string $email Contact email.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function sale_orders_where( string $email ): array {
		$statuses     = self::REVENUE_PAYMENT_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		return array(
			"c.email = %s AND o.payment_status IN ({$placeholders})",
			array_merge( array( $email ), $statuses ),
		);
	}

	/**
	 * Products of an order.
	 *
	 * @param int $order_id Order id.
	 * @return array<int, array{post_id: int, title: string}>
	 */
	private static function order_items( int $order_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fct_order_items';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FluentCart's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, post_title, title FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$title = trim( (string) $row['post_title'] );
				$name  = trim( (string) $row['title'] );
				if ( '' !== $name && $name !== $title && '' !== $title ) {
					$title .= ' - ' . $name;
				} elseif ( '' === $title ) {
					$title = $name;
				}

				return array(
					'post_id' => (int) $row['post_id'],
					'title'   => $title,
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Phone and country of the order's billing address.
	 *
	 * @param int $order_id Order id.
	 * @return array{phone: string, country: string, address_1: string, city: string, state: string, zip: string}
	 */
	private static function billing_address( int $order_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fct_order_addresses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FluentCart's own table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT address_1, city, state, postcode, country, meta FROM {$table} WHERE order_id = %d AND type = 'billing' ORDER BY id DESC LIMIT 1", $order_id ),
			ARRAY_A
		);

		$meta = $row && ! empty( $row['meta'] ) ? json_decode( (string) $row['meta'], true ) : array();

		return array(
			'phone'     => is_array( $meta ) ? trim( (string) ( $meta['other_data']['phone'] ?? '' ) ) : '',
			'country'   => $row ? strtoupper( trim( (string) $row['country'] ) ) : '',
			'address_1' => $row ? trim( (string) $row['address_1'] ) : '',
			'city'      => $row ? trim( (string) $row['city'] ) : '',
			'state'     => $row ? trim( (string) $row['state'] ) : '',
			'zip'       => $row ? trim( (string) $row['postcode'] ) : '',
		);
	}
}
