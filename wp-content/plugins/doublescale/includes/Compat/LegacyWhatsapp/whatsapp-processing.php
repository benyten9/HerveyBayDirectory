<?php
/**
 * Stand-in for free's former WhatsApp campaign engine, served only next to a
 * Pro that predates its own engine. See {@see \DoubleScale\Compat\LegacyWhatsapp}.
 *
 * Nothing is sent. A message handed in is marked failed with the reason, so
 * the automation step or campaign that asked shows why.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Modules\Campaigns\Campaign;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Compat\LegacyWhatsapp;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;

/**
 * WhatsappProcessing.
 */
final class WhatsappProcessing {

	/** @var self|null */
	private static $instance = null;

	/**
	 * Single instance, as the engine was.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * A campaign run: nothing to send with.
	 *
	 * @param mixed $campaign Campaign model.
	 * @return \WP_Error
	 */
	public function process_campaign( $campaign = null ) {
		doublescale_get_logger()->warning(
			LegacyWhatsapp::message(),
			array(
				'campaign_id' => is_object( $campaign ) && isset( $campaign->id ) ? (int) $campaign->id : 0,
				'code'        => 'whatsapp_needs_pro_update',
			)
		);

		return LegacyWhatsapp::error();
	}

	/**
	 * One message (an automation step): record it as failed with the reason.
	 *
	 * @param mixed $campaign Campaign model.
	 * @param mixed $contact  Contact.
	 * @param mixed $tracking Tracking row for the message.
	 * @return \WP_Error
	 */
	public function process_campaign_message( $campaign = null, $contact = null, $tracking = null ) {
		if ( is_object( $tracking ) && ! empty( $tracking->id ) ) {
			$tracking->status = TrackingStatus::FAILED;
			$tracking->save();
			CommunicationTrackingMetaModel::store_error_info( $tracking->id, 'whatsapp_needs_pro_update', LegacyWhatsapp::message() );
		}

		return LegacyWhatsapp::error();
	}

	/**
	 * Any other engine method an older Pro may call.
	 *
	 * @param string $name Method.
	 * @param array  $args Arguments.
	 * @return \WP_Error
	 */
	public function __call( $name, $args ) {
		return LegacyWhatsapp::error();
	}

	/**
	 * Any static engine method an older Pro may call.
	 *
	 * @param string $name Method.
	 * @param array  $args Arguments.
	 * @return \WP_Error
	 */
	public static function __callStatic( $name, $args ) {
		return LegacyWhatsapp::error();
	}
}
