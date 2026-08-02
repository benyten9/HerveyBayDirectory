<?php

namespace SEOPressPro\Services\Options;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );


class OptionBot {

	/**
	 * @since 6.5.0
	 *
	 * @return array
	 */
	public function getOption() {
		return get_option( 'seopress_bot_option_name' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return string|null
	 *
	 * @param string $key
	 */
	protected function searchOptionByKey( $key ) {
		$data = $this->getOption();

		if ( empty( $data ) ) {
			return null;
		}

		if ( ! isset( $data[ $key ] ) ) {
			return null;
		}

		return $data[ $key ];
	}

	/**
	 * @since 8.3.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsEmail() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_email' );
	}

	/**
	 * @since 8.3.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsAuditCPT() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_audit_cpt' );
	}

	/**
	 * @since 9.1.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsAuditBatchSize() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_audit_batch_size' );
	}

	/**
	 * @since 8.8.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsAuditNoindex() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_audit_noindex' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsCleaning() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_cleaning' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsType() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_type' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsWhere() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_where' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return string
	 */
	public function getBotScanSettings404() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_404' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsTimeout() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_timeout' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsNumber() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_number' );
	}

	/**
	 * @since 6.5.0
	 *
	 * @return string
	 */
	public function getBotScanSettingsPostTypes() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_post_types' );
	}

	/**
	 * Webhook URL pinged once a site audit completes.
	 *
	 * @since 9.9.0
	 *
	 * @return string|null
	 */
	public function getBotScanSettingsWebhookUrl() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_webhook_url' );
	}

	/**
	 * Optional shared secret used to sign the site-audit webhook payload
	 * (HMAC-SHA256, sent in the X-SEOPress-Signature header).
	 *
	 * @since 9.9.0
	 *
	 * @return string|null
	 */
	public function getBotScanSettingsWebhookSecret() {
		return $this->searchOptionByKey( 'seopress_bot_scan_settings_webhook_secret' );
	}
}
