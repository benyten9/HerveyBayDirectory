<?php
/**
 * WcCustomers Importer
 *
 * This class is responsible for handling the WcCustomers importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Validators\PhoneValidator;
use DoubleScale\Core\Settings\PhoneAsWhatsappSetting;
use DoubleScale\Modules\Contacts\Abstracts\Importer;

/**
 * WcCustomers Importer class
 */
class WcCustomers extends Importer {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'WooCommerce Customers';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_customers';

	/**
	 * Is Integration
	 *
	 * @var bool
	 */
	protected $is_integration = false;

	/**
	 * Is Active
	 *
	 * @var bool
	 */
	public function is_active() {
		return doublescale_is_plugin_active( 'woocommerce/woocommerce.php' );
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return PhoneAsWhatsappSetting::get_fields_entry();
	}

	/**
	 * Run importer
	 */
	public function run() {
		global $wpdb;

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from WooCommerce plugin.

		$table_name = $wpdb->prefix . 'wc_customer_lookup';
		$total      = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		$mapping    = array(
			'first_name'      => 'first_name',
			'last_name'       => 'last_name',
			'email'           => 'email',
			'phone'           => 'phone',
			'city'            => 'city',
			'state'           => 'state',
			'zip'             => 'postcode',
			'country'         => 'country',
		);

		if ( PhoneAsWhatsappSetting::is_enabled( $this->phone_is_whatsapp ) ) {
			$mapping['whatsapp_phone'] = 'whatsapp_phone';
		}

		$result = $this->import_with_offset(
			$total,
			$this->offset,
			function ( $offset ) use ( $wpdb, $table_name ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name LIMIT %d, 20", $offset ) );

				foreach ( $rows as $row ) {
					$row->phone = $this->get_customer_phone( $row );

					if ( PhoneAsWhatsappSetting::is_enabled( $this->phone_is_whatsapp ) ) {
						$country_hint        = isset( $row->country ) ? (string) $row->country : '';
						$whatsapp            = '' !== $row->phone ? PhoneValidator::to_e164( $row->phone, $country_hint ) : null;
						$row->whatsapp_phone = null !== $whatsapp ? $whatsapp : '';
					}
				}

				return $rows;
			},
			$mapping
		);

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $result;
	}

	/**
	 * Resolve a customer's phone number.
	 *
	 * The wc_customer_lookup analytics table has no phone column — WooCommerce
	 * keeps phone in billing/shipping data, not the lookup row — so the generic
	 * column mapping imports nothing for phone. Resolve it from WooCommerce's
	 * actual storage instead, mirroring how Wpusers hydrates name from usermeta:
	 *
	 *  - Registered customers (user_id > 0): the WC_Customer object, which reads
	 *    the billing_phone / shipping_phone usermeta regardless of HPOS state.
	 *  - Guest customers (user_id = 0): the phone on their most recent order,
	 *    looked up by the lookup row's email. WC_Order::get_billing_phone() is
	 *    HPOS-aware, so this works against both the orders table and legacy meta.
	 *
	 * Returns '' when no phone is found or WooCommerce's API is unavailable; the
	 * empty value is simply written to an empty contact->phone, same as before.
	 *
	 * @param object $row A wc_customer_lookup row (has ->user_id and ->email).
	 *
	 * @return string Phone number, or '' if none.
	 */
	protected function get_customer_phone( $row ) {
		$user_id = isset( $row->user_id ) ? (int) $row->user_id : 0;

		// Registered customer: read from the WC_Customer (usermeta-backed).
		if ( $user_id > 0 && class_exists( 'WC_Customer' ) ) {
			try {
				$customer = new \WC_Customer( $user_id );
				$phone    = $customer->get_billing_phone();
				if ( empty( $phone ) ) {
					$phone = $customer->get_shipping_phone();
				}
				if ( ! empty( $phone ) ) {
					return $this->sanitize_phone( $phone );
				}
			} catch ( \Exception $e ) {
				// Fall through to the order lookup below.
				$phone = '';
			}
		}

		// Guest customer (or registered customer with no profile phone):
		// fall back to the phone on their most recent order, by email.
		$email = isset( $row->email ) ? (string) $row->email : '';
		if ( ! empty( $email ) && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'billing_email' => $email,
					'limit'         => 1,
					'orderby'       => 'date',
					'order'         => 'DESC',
					'return'        => 'objects',
				)
			);

			if ( ! empty( $orders ) ) {
				$order = $orders[0];
				$phone = $order->get_billing_phone();
				if ( empty( $phone ) && method_exists( $order, 'get_shipping_phone' ) ) {
					$phone = $order->get_shipping_phone();
				}
				if ( ! empty( $phone ) ) {
					return $this->sanitize_phone( $phone );
				}
			}
		}

		return '';
	}

	/**
	 * Normalize a phone number for storage.
	 *
	 * Strips formatting (spaces, dashes, parentheses) but keeps a leading + for
	 * international numbers — the same cleanup the Gohighlevel importer applies.
	 *
	 * @param string $phone Raw phone number.
	 *
	 * @return string
	 */
	protected function sanitize_phone( $phone ) {
		return preg_replace( '/[^\d+]/', '', (string) $phone );
	}
}
