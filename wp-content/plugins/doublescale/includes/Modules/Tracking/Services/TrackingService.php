<?php
/**
 * Tracking service — centralises open/click/visit recording logic.
 *
 * Business logic that was previously scattered across the Email, Website,
 * LinkTriggers, Sms, and Whatsapp singletons can be progressively
 * extracted here so REST controllers remain thin.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Tracking\Services
 */

namespace DoubleScale\Modules\Tracking\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;

class TrackingService {

	/**
	 * Record a page visit for a contact (requires Website Tracking in Pro).
	 *
	 * @param int    $contact_id Contact ID.
	 * @param string $url        Visited URL.
	 * @param string $referrer   Optional referrer URL.
	 * @param string $user_agent Optional User-Agent header.
	 * @return object|null Page visit model instance or null when not available.
	 */
	public function record_page_visit( int $contact_id, string $url, string $referrer = '', string $user_agent = '' ) {
		if ( ! class_exists( '\DoubleScale\Pro\Modules\WebsiteTracking\Models\PageVisitModel' ) ) {
			return null;
		}
		return \DoubleScale\Pro\Modules\WebsiteTracking\Models\PageVisitModel::create(
			array(
				'contact_id' => $contact_id,
				'url'        => $url,
				'referrer'   => $referrer,
				'user_agent' => $user_agent,
			)
		);
	}

	/**
	 * Record an email open event.
	 *
	 * @param int $tracking_id Communication tracking record ID.
	 * @return bool
	 */
	public function record_open( int $tracking_id ): bool {
		$tracking = CommunicationTrackingModel::find( $tracking_id );
		if ( ! $tracking ) {
			return false;
		}
		$tracking->opened    = true;
		$tracking->opened_at = current_time( 'mysql', true );
		return $tracking->save();
	}

	/**
	 * Record an email click event.
	 *
	 * @param int    $tracking_id Communication tracking record ID.
	 * @param string $url         Clicked URL.
	 * @return bool
	 */
	public function record_click( int $tracking_id, string $url ): bool {
		$tracking = CommunicationTrackingModel::find( $tracking_id );
		if ( ! $tracking ) {
			return false;
		}
		$tracking->clicked    = true;
		$tracking->clicked_at = current_time( 'mysql', true );
		$tracking->save();

		CommunicationTrackingMetaModel::create(
			array(
				'communication_tracking_id' => $tracking_id,
				'meta_key'                  => 'clicked_url',
				'meta_value'                => $url,
			)
		);

		return true;
	}
}
