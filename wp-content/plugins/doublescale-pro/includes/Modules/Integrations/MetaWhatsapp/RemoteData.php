<?php
/**
 * Meta WhatsApp Remote Data
 *
 * Fetches remote data from Meta WhatsApp Api for use in integration settings
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\MetaWhatsapp;

defined( 'ABSPATH' ) || exit;

/**
 * RemoteData class for Meta WhatsApp
 */
class RemoteData {

	/**
	 * Integration instance
	 *
	 * @var Integration
	 */
	private $integration;

	/**
	 * Constructor
	 *
	 * @param Integration $integration Integration instance.
	 */
	public function __construct( $integration ) {
		$this->integration = $integration;
	}

	/**
	 * Get phone numbers from the Business Account
	 *
	 * @return array List of phone numbers with id and display_phone_number.
	 */
	public function get_phone_numbers() {
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}

		$result = $api->get_phone_numbers();
		if ( ! $result['success'] || empty( $result['data']['data'] ) ) {
			return array();
		}

		$phones = array();
		foreach ( $result['data']['data'] as $phone ) {
			$phones[] = array(
				'id'    => $phone['id'],
				'label' => $phone['display_phone_number'] ?? $phone['id'],
			);
		}

		return $phones;
	}

	/**
	 * Get message templates from the Business Account
	 *
	 * @param string $status Template status filter.
	 *
	 * @return array List of templates.
	 */
	public function get_message_templates( $status = 'APPROVED' ) {
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}

		$result = $api->get_message_templates( $status );
		if ( ! $result['success'] || empty( $result['data']['data'] ) ) {
			return array();
		}

		return $result['data']['data'];
	}

	/**
	 * Get business profile information
	 *
	 * @return array|null Business profile data or null.
	 */
	public function get_business_profile() {
		$api = $this->integration->connect();
		if ( ! $api ) {
			return null;
		}

		$result = $api->get_business_profile();
		if ( ! $result['success'] ) {
			return null;
		}

		return $result['data']['data'][0] ?? null;
	}
}





