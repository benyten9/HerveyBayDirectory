<?php
namespace DirectoristAnalytics\App\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy Service
 *
 * @package DirectoristAnalytics
 * @since 1.0.0
 */
class PrivacyService {

	const SETTINGS_KEY = 'directorist_analytics_settings';

	/**
	 * Get settings
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_settings() {
		$defaults = array(
			'enable_data_protection'   => false,
			'respect_do_not_track'     => false,
			'include_admin_visits'     => true,
			'anonymize_ips'            => false,
			'auto_delete_days'         => 30,
		);

		$saved = get_option( self::SETTINGS_KEY, array() );
		return array_merge( $defaults, $saved );
	}

	/**
	 * Should track this request?
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function should_track() {
		$settings = $this->get_settings();

		// Check DNT header
		if ( $this->should_respect_dnt( $settings ) && $this->has_dnt_header() ) {
			return false;
		}

		// Check admin visits
		if ( ! $this->should_include_admin( $settings ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Should respect DNT?
	 *
	 * @since 1.0.0
	 * @param array $settings Settings array.
	 * @return bool
	 */
	private function should_respect_dnt( $settings ) {
		// Always respect DNT if data protection enabled
		if ( ! empty( $settings['enable_data_protection'] ) ) {
			return true;
		}

		// Otherwise check individual setting
		return ! empty( $settings['respect_do_not_track'] );
	}

	/**
	 * Should include admin visits?
	 *
	 * @since 1.0.0
	 * @param array $settings Settings array.
	 * @return bool
	 */
	private function should_include_admin( $settings ) {
		// Always exclude admin if data protection enabled
		if ( ! empty( $settings['enable_data_protection'] ) ) {
			return false;
		}

		// Otherwise check individual setting
		return ! empty( $settings['include_admin_visits'] );
	}

	/**
	 * Should anonymize IP?
	 *
	 * @since 1.0.0
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public function should_anonymize_ip( $settings = null ) {
		if ( ! $settings ) {
			$settings = $this->get_settings();
		}

		// Always anonymize if data protection enabled
		if ( ! empty( $settings['enable_data_protection'] ) ) {
			return true;
		}

		// Otherwise check individual setting
		return ! empty( $settings['anonymize_ips'] );
	}

	/**
	 * Check if DNT header is set
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	private function has_dnt_header() {
		return isset( $_SERVER['HTTP_DNT'] ) && '1' === $_SERVER['HTTP_DNT'];
	}

	/**
	 * Strip query parameters from URL (privacy mode)
	 *
	 * @since 1.0.0
	 * @param string $url URL to clean.
	 * @return string
	 */
	public function strip_query_params( $url ) {
		$parsed = wp_parse_url( $url );
		
		if ( ! $parsed ) {
			return $url;
		}

		$clean_url = '';
		
		if ( isset( $parsed['scheme'] ) ) {
			$clean_url .= $parsed['scheme'] . '://';
		}
		
		if ( isset( $parsed['host'] ) ) {
			$clean_url .= $parsed['host'];
		}
		
		if ( isset( $parsed['path'] ) ) {
			$clean_url .= $parsed['path'];
		}

		return $clean_url;
	}

	/**
	 * Round timestamp to nearest hour (privacy mode)
	 *
	 * @since 1.0.0
	 * @param string $timestamp MySQL timestamp.
	 * @return string
	 */
	public function round_to_hour( $timestamp ) {
		$dt = new \DateTime( $timestamp );
		$dt->setTime( (int) $dt->format( 'H' ), 0, 0 );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Get retention days
	 *
	 * @since 1.0.0
	 * @return int
	 */
	public function get_retention_days() {
		$settings = $this->get_settings();

		// Always 30 days if data protection enabled
		if ( ! empty( $settings['enable_data_protection'] ) ) {
			return 30;
		}

		return absint( $settings['auto_delete_days'] ?: 30 );
	}
}