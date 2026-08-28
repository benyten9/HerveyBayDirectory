<?php
/**
 * Sync WooCommerce order customers into CRM contacts (MVP, live orders only).
 *
 * @package DoubleScale\Pro\Modules\Integrations\WooCommerce
 */

namespace DoubleScale\Pro\Modules\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Models\TagModel;

/**
 * OrderContactSync class.
 */
final class OrderContactSync {

	private const TAG_NAME = 'WooCommerce';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// `woocommerce_new_order` fires before billing details are committed to the
		// order, so the billing email is still empty there. Hook the checkout hooks
		// that run after the order is saved instead — classic checkout, Store API
		// (blocks), and admin/programmatic status transitions.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'handle_order_object' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'handle_order_object' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 20, 4 );
	}

	/**
	 * @param \WC_Order|int $order Order object (or id, defensively).
	 * @return void
	 */
	public function handle_order_object( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->sync_order_object( $order );
			return;
		}

		$this->sync_order( (int) $order );
	}

	/**
	 * @param int      $order_id   Order id.
	 * @param string   $from       Previous status.
	 * @param string   $to         New status.
	 * @param \WC_Order $order     Order object.
	 * @return void
	 */
	public function handle_order_status_changed( $order_id, $from, $to, $order ): void {
		unset( $from, $to );
		if ( ! $order instanceof \WC_Order ) {
			$this->sync_order( (int) $order_id );
			return;
		}
		$this->sync_order_object( $order );
	}

	/**
	 * @param int $order_id Order id.
	 * @return void
	 */
	private function sync_order( int $order_id ): void {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->sync_order_object( $order );
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function sync_order_object( $order ): void {
		if ( ! Settings::instance()->is_sync_enabled() ) {
			return;
		}

		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$payload = $this->contact_payload_from_order( $order, $email );

		// The contacts table enforces a UNIQUE index on `phone`, so a shared billing
		// number (household, company switchboard) makes the write fail. Rather than
		// dropping the customer, retry without the phone so the contact still lands.
		$contact = $this->persist_contact( $order, $email, $payload );
		if ( null === $contact ) {
			$contact = $this->persist_contact( $order, $email, $this->without_phone( $payload ), true );
		}

		if ( null === $contact ) {
			return;
		}

		try {
			$this->apply_woocommerce_tag( $contact );
		} catch ( \Throwable $e ) {
			$this->log_failure( $order, $email, $e, 'woocommerce_contact_sync_tag_failed' );
		}
	}

	/**
	 * Create or update the contact, swallowing failures so nothing escapes into
	 * WooCommerce's order hooks.
	 *
	 * @param \WC_Order            $order    Order.
	 * @param string               $email    Billing email.
	 * @param array<string, mixed> $payload  Contact data.
	 * @param bool                 $is_retry Whether this is the phone-less retry.
	 * @return ContactModel|null Contact on success, null when the write failed.
	 */
	private function persist_contact( $order, string $email, array $payload, bool $is_retry = false ) {
		$contact = ContactModel::get_by_email( $email );

		try {
			if ( $contact ) {
				$contact->fill( $this->merge_contact_fields( $contact, $payload ) );
				$contact->save();

				return $contact;
			}

			return ContactModel::create( $payload );
		} catch ( \Throwable $e ) {
			// On the create path a concurrent order may have inserted the contact
			// between the lookup and the write; reuse it instead of failing. On the
			// update path the contact already existed, so re-reading it would mask
			// the failed write and skip the phone-less retry.
			if ( ! $contact ) {
				$existing = ContactModel::get_by_email( $email );
				if ( $existing ) {
					return $existing;
				}
			}

			if ( $is_retry ) {
				$this->log_failure( $order, $email, $e, 'woocommerce_contact_sync_failed' );
			}

			return null;
		}
	}

	/**
	 * Drop the phone from a payload so a conflicting number cannot block the write.
	 *
	 * @param array<string, mixed> $payload Contact data.
	 * @return array<string, mixed>
	 */
	private function without_phone( array $payload ): array {
		$payload['phone']          = null;
		$payload['whatsapp_phone'] = null;

		return $payload;
	}

	/**
	 * Record a sync failure without interrupting the order.
	 *
	 * @param \WC_Order  $order Order.
	 * @param string     $email Billing email.
	 * @param \Throwable $e     Failure.
	 * @param string     $code  Log code.
	 * @return void
	 */
	private function log_failure( $order, string $email, \Throwable $e, string $code ): void {
		doublescale_get_logger()->error(
			'WooCommerce contact sync failed',
			array(
				'code'     => $code,
				'order_id' => (int) $order->get_id(),
				'email'    => $email,
				'message'  => $e->getMessage(),
			)
		);
	}

	/**
	 * @param \WC_Order $order Order.
	 * @param string    $email Billing email.
	 * @return array<string, mixed>
	 */
	private function contact_payload_from_order( $order, string $email ): array {
		$billing_phone = (string) $order->get_billing_phone();
		$country       = (string) $order->get_billing_country();
		$phone         = ContactModel::normalize_phone_field( $billing_phone );
		$whatsapp      = ContactModel::normalize_whatsapp_field( $billing_phone, $country );

		return array(
			'email'        => $email,
			'first_name'   => (string) $order->get_billing_first_name(),
			'last_name'    => (string) $order->get_billing_last_name(),
			'company_name' => (string) $order->get_billing_company(),
			'phone'        => '' !== $phone ? $phone : null,
			'whatsapp_phone' => '' !== $whatsapp ? $whatsapp : null,
			'address_1'    => (string) $order->get_billing_address_1(),
			'address_2'    => (string) $order->get_billing_address_2(),
			'city'         => (string) $order->get_billing_city(),
			'state'        => (string) $order->get_billing_state(),
			'country'      => (string) $order->get_billing_country(),
			'zip'          => (string) $order->get_billing_postcode(),
			'source'       => 'woocommerce',
		);
	}

	/**
	 * @param ContactModel          $contact Existing contact.
	 * @param array<string, mixed>  $payload Incoming data.
	 * @return array<string, mixed>
	 */
	private function merge_contact_fields( ContactModel $contact, array $payload ): array {
		$merged = array();

		foreach ( array( 'first_name', 'last_name', 'company_name', 'phone', 'whatsapp_phone', 'address_1', 'address_2', 'city', 'state', 'country', 'zip' ) as $field ) {
			$incoming = isset( $payload[ $field ] ) ? trim( (string) $payload[ $field ] ) : '';
			if ( '' !== $incoming ) {
				$merged[ $field ] = $incoming;
			}
		}

		if ( '' === trim( (string) ( $contact->source ?? '' ) ) ) {
			$merged['source'] = 'woocommerce';
		}

		return $merged;
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	private function apply_woocommerce_tag( ContactModel $contact ): void {
		$tag = TagModel::getOrCreate( self::TAG_NAME );
		if ( ! $tag || empty( $tag->id ) ) {
			return;
		}

		$contact->add_tags( array( (int) $tag->id ) );
	}
}
