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
	 * @return array
	 */
	public function get_options() {
		return array(
			'twilio'        => array(
				'label'        => __( 'Twilio', 'doublescale' ),
				'description'  => __( 'Send SMS messages to your contacts via Twilio.', 'doublescale' ),
				'fields'       => array(),
				'is_connected' => false,
				'settings'     => array(),
				'is_pro'       => true,
			),
			'stripe'        => array(
				'label'        => __( 'Stripe', 'doublescale' ),
				'description'  => __( 'Accept payments and manage subscriptions through Stripe.', 'doublescale' ),
				'fields'       => array(),
				'is_connected' => false,
				'settings'     => array(),
				'is_pro'       => true,
			),
			'slack'         => array(
				'label'        => __( 'Slack', 'doublescale' ),
				'description'  => __( 'Send notifications and updates to your Slack channels.', 'doublescale' ),
				'fields'       => array(),
				'is_connected' => false,
				'settings'     => array(),
				'is_pro'       => true,
			),
			'meta-whatsapp' => array(
				'label'        => __( 'Meta WhatsApp', 'doublescale' ),
				'description'  => __( 'Send WhatsApp messages to your contacts via Meta WhatsApp Business.', 'doublescale' ),
				'fields'       => array(),
				'is_connected' => false,
				'settings'     => array(),
				'is_pro'       => true,
			),
			'paypal'        => array(
				'label'        => __( 'PayPal', 'doublescale' ),
				'description'  => __( 'Accept PayPal payments for invoices. Configure sandbox or live REST app credentials.', 'doublescale' ),
				'fields'       => array(),
				'is_connected' => false,
				'settings'     => array(),
				'is_pro'       => true,
			),
			'typeform'      => array(
				'label'        => __( 'Typeform', 'doublescale' ),
				'description'  => __( 'Connect your Typeform account with a personal access token.', 'doublescale' ),
				'fields'       => array(),
				'is_connected' => false,
				'settings'     => array(),
				'is_pro'       => true,
			),
			'jotform'       => array(
				'label'        => __( 'Jotform', 'doublescale' ),
				'description'  => __( 'Connect your Jotform account with an API key.', 'doublescale' ),
				'fields'       => array(),
				'is_connected' => false,
				'settings'     => array(),
				'is_pro'       => true,
			),
		);
	}
}
