<?php
/**
 * Integrations facade: delegates to the Pro implementation when the Pro plugin is active.
 *
 * CRM vendor integrations live in DoubleScale Pro; the free plugin keeps this FQCN so
 * automations and admin config can reference it without fatals when Pro is not installed.
 *
 * @package DoubleScale\Managers
 */

namespace DoubleScale\Core\Managers;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point for integration registry access.
 */
final class IntegrationsManager {

	/**
	 * @var self|null
	 */
	private static $instance;

	/**
	 * @return self|\DoubleScale\Pro\Modules\Integrations\Services\IntegrationsManager
	 */
	public static function instance() {
		if ( class_exists( \DoubleScale\Pro\Modules\Integrations\Services\IntegrationsManager::class, true ) ) {
			return \DoubleScale\Pro\Modules\Integrations\Services\IntegrationsManager::instance();
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public function is_active( $slug ) {
		return false;
	}

	/**
	 * @param string $slug Integration slug.
	 * @return null
	 */
	public function get_integration( $slug ) {
		unset( $slug );
		return null;
	}

	/**
	 * Placeholder catalog when Pro is not active (locked Pro cards).
	 *
	 * @return array
	 */
	public function get_options() {
		$base = defined( 'DOUBLESCALE_PLUGIN_URL' ) ? trailingslashit( DOUBLESCALE_PLUGIN_URL ) : '';

		$placeholders = array(
			'twilio'        => array(
				'label'             => __( 'Twilio', 'doublescale' ),
				'description'       => __( 'Send SMS messages to your contacts via Twilio.', 'doublescale' ),
				'icon'              => 'assets/images/twilio/twilio.png',
				'catalog_category'  => 'messaging',
			),
			'stripe'        => array(
				'label'             => __( 'Stripe', 'doublescale' ),
				'description'       => __( 'Accept payments and manage subscriptions through Stripe.', 'doublescale' ),
				'icon'              => 'assets/images/stripe/stripe.png',
				'catalog_category'  => 'payment',
			),
			'slack'         => array(
				'label'             => __( 'Slack', 'doublescale' ),
				'description'       => __( 'Send notifications and updates to your Slack channels.', 'doublescale' ),
				'icon'              => 'assets/images/slack/slack.png',
				'catalog_category'  => 'messaging',
			),
			'meta-whatsapp' => array(
				'label'             => __( 'Meta WhatsApp', 'doublescale' ),
				'description'       => __( 'Send WhatsApp messages to your contacts via Meta WhatsApp Business.', 'doublescale' ),
				'icon'              => 'assets/images/meta-whatsapp/meta-whatsapp.svg',
				'catalog_category'  => 'messaging',
			),
			'paypal'        => array(
				'label'             => __( 'PayPal', 'doublescale' ),
				'description'       => __( 'Accept PayPal payments for invoices. Configure sandbox or live REST app credentials.', 'doublescale' ),
				'icon'              => 'assets/images/paypal/paypal.png',
				'catalog_category'  => 'payment',
			),
			'square'        => array(
				'label'             => __( 'Square', 'doublescale' ),
				'description'       => __( 'Accept Square payments for invoices. Configure sandbox or production access token and location.', 'doublescale' ),
				'icon'              => 'assets/images/square/square.svg',
				'catalog_category'  => 'payment',
			),
			'mollie'        => array(
				'label'             => __( 'Mollie', 'doublescale' ),
				'description'       => __( 'Accept iDEAL, Bancontact, SEPA and card payments for invoices via Mollie.', 'doublescale' ),
				'icon'              => 'assets/images/mollie/mollie.svg',
				'catalog_category'  => 'payment',
			),
			'razorpay'      => array(
				'label'             => __( 'Razorpay', 'doublescale' ),
				'description'       => __( 'Accept UPI, cards, netbanking and wallet payments for invoices via Razorpay.', 'doublescale' ),
				'icon'              => 'assets/images/razorpay/razorpay.svg',
				'catalog_category'  => 'payment',
			),
			'authorize_net' => array(
				'label'             => __( 'Authorize.Net', 'doublescale' ),
				'description'       => __( 'Accept card payments for invoices via Authorize.Net Accept Hosted.', 'doublescale' ),
				'icon'              => 'assets/images/authorize-net/authorize-net.svg',
				'catalog_category'  => 'payment',
			),
			'typeform'      => array(
				'label'             => __( 'Typeform', 'doublescale' ),
				'description'       => __( 'Connect your Typeform account with a personal access token.', 'doublescale' ),
				'icon'              => 'assets/images/typeform/typeform.svg',
				'catalog_category'  => 'forms',
			),
			'jotform'       => array(
				'label'             => __( 'Jotform', 'doublescale' ),
				'description'       => __( 'Connect your Jotform account with an API key.', 'doublescale' ),
				'icon'              => 'assets/images/jotform/jotform.png',
				'catalog_category'  => 'forms',
			),
		);

		$options = array();
		foreach ( $placeholders as $slug => $row ) {
			$options[ $slug ] = array(
				'label'             => $row['label'],
				'description'       => $row['description'],
				'fields'            => array(),
				'is_connected'      => false,
				'settings'          => array(),
				'is_pro'            => true,
				'show_in_catalog'   => true,
				'catalog_category'  => $row['catalog_category'],
				'icon_url'          => $base ? $base . $row['icon'] : '',
			);
		}

		return $options;
	}
}
