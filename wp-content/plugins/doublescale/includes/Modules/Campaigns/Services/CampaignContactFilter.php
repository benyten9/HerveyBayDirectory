<?php
/**
 * Campaign Contact Filter Service
 * Handles contact filtering for all campaign types
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Filters\Process as Contact_Filters_Process;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * CampaignContactFilter class
 */
class CampaignContactFilter {

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var CampaignContactFilter
	 */
	private static $instance;

	/**
	 * CampaignContactFilter Instance.
	 *
	 * Instantiates or reuses an instance of CampaignContactFilter.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return self - Single instance
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get filtered contacts for campaign
	 *
	 * @param string $type Campaign type ('email', 'sms', 'whatsapp')
	 * @param array  $filters Campaign filters
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function get_filtered_contacts( $type, $filters = array() ) {
		// VALIDATE channel type first
		if ( ! in_array( $type, array( 'email', 'sms', 'whatsapp' ), true ) ) {
			// Return empty query for invalid channel
			return ContactModel::whereRaw( '1 = 0' );
		}

		// Determine channel-specific status field
		$channel_status_field = $type . '_status';

		// Start query - only check channel-specific status
		// Only 'subscribed' contacts can receive messages
		$query = ContactModel::where( $channel_status_field, 'subscribed' );

		// Apply type-specific filtering (email/phone availability)
		$query = $this->apply_campaign_type_filter( $query, $type );

		// Apply custom filters if provided
		if ( ! empty( $filters ) ) {
			$contact_filters = new Contact_Filters_Process( $query, $filters );
			$query           = $contact_filters->filter();
		}

		return $query;
	}

	/**
	 * Get contact count for campaign
	 *
	 * @param string $type Campaign type ('email', 'sms', 'whatsapp')
	 * @param array  $filters Campaign filters
	 *
	 * @return int Contact count
	 */
	public function get_contact_count( $type, $filters = array() ) {
		return $this->get_filtered_contacts( $type, $filters )->count();
	}

	/**
	 * Get paginated contacts for processing
	 *
	 * @param string $type Campaign type ('email', 'sms', 'whatsapp')
	 * @param array  $filters Campaign filters
	 * @param int    $offset Starting offset
	 * @param int    $limit Number of contacts to fetch
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function get_contacts_for_processing( $type, $filters = array(), $offset = 0, $limit = 10 ) {
		return $this->get_filtered_contacts( $type, $filters )
					->offset( $offset )
					->limit( $limit )
					->get();
	}

	/**
	 * Apply campaign type filter to query
	 * Filters contacts based on whether they have email or phone
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query Query builder instance
	 * @param string                                $type Campaign type ('email', 'sms', 'whatsapp')
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function apply_campaign_type_filter( $query, $type ) {
		// Ensure type is in integer format (handles both string and int input)
		$channel_int = CampaignChannel::ensure_integer( $type );

		// If invalid channel, return query unchanged
		if ( null === $channel_int ) {
			return $query;
		}

		// Use CampaignChannel to determine recipient field (DRY principle)
		$recipient_field = CampaignChannel::get_recipient_field( $channel_int );

		$query->whereNotNull( $recipient_field )
				->where( $recipient_field, '!=', '' );

		return $query;
	}

	/**
	 * Skip contact with logging
	 *
	 * @param int    $contact_id Contact ID
	 * @param int    $campaign_id Campaign ID
	 * @param string $type Campaign type
	 * @param string $reason Skip reason
	 *
	 * @return void
	 */
	public function log_skipped_contact( $contact_id, $campaign_id, $type, $reason ) {
		doublescale_get_logger()->info(
			/* translators: %s: reason why contact was skipped */
			sprintf( __( 'Contact skipped - %s', 'doublescale' ), $reason ),
			array(
				'contact_id'  => $contact_id,
				'campaign_id' => $campaign_id,
				'type'        => $type,
			)
		);
	}
}
