<?php
/**
 * The message query behind a campaign's Messages tab.
 *
 * The tab lists one row per message, narrowed by a status filter and — for
 * failures — a failure-cause chip. Bulk-tagging "every recipient matching this
 * filter" has to resolve to exactly the contacts behind those rows, so both
 * the listing and the bulk target build their query here rather than each
 * keeping its own copy of the filter rules.
 *
 * @package DoubleScale\Modules\Campaigns\Services
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * Builds filtered campaign message queries.
 */
final class CampaignMessageQuery {

	/**
	 * Tracking mode per channel slug.
	 *
	 * @var array<string, int>
	 */
	const CHANNEL_MODES = array(
		'email'    => CommunicationTrackingModel::MODE_EMAIL,
		'sms'      => CommunicationTrackingModel::MODE_SMS,
		'whatsapp' => CommunicationTrackingModel::MODE_WHATSAPP,
	);

	/**
	 * Every message a campaign produced.
	 *
	 * @param int         $campaign_id Campaign id.
	 * @param string|null $channel     Channel slug, or null for every channel
	 *                                 (what /campaigns/{id}/messages uses).
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public static function for_campaign( $campaign_id, $channel = null ) {
		$query = CommunicationTrackingModel::query()
			->where( 'source_type', MessageSourceTypes::CAMPAIGN )
			->where( 'source_id', $campaign_id );

		if ( $channel ) {
			$query->where( 'mode', self::CHANNEL_MODES[ $channel ] ?? CommunicationTrackingModel::MODE_EMAIL );
		}

		return $query;
	}

	/**
	 * Messages as the Messages tab lists them for a status and failure cause.
	 *
	 * @param int         $campaign_id Campaign id.
	 * @param string      $status      Status filter; '' or 'all' for every message.
	 * @param string      $error_code  Failure-cause chip; only applies to failures.
	 * @param string|null $channel     Channel slug, or null for every channel.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public static function filtered( $campaign_id, $status = '', $error_code = '', $channel = null ) {
		$query = self::for_campaign( $campaign_id, $channel );

		if ( ! empty( $status ) && 'all' !== $status ) {
			self::apply_status( $query, $status );
		}

		// A chip narrows the failures; it means nothing on any other filter.
		if ( '' !== (string) $error_code && ( empty( $status ) || 'failed' === $status ) ) {
			self::apply_error_code( $query, $error_code );
		}

		return $query;
	}

	/**
	 * Narrow a message query to a status filter.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query  Query.
	 * @param string                                $status Status filter.
	 * @return void
	 */
	public static function apply_status( $query, $status ) {
		switch ( $status ) {
			case 'failed':
				$query->where( 'status', TrackingStatus::FAILED );
				break;
			case 'sent':
				$query->where( 'status', TrackingStatus::SENT );
				break;
			case 'pending':
				$query->where( 'status', TrackingStatus::PENDING );
				break;
			case 'delivered':
				$query->where( 'status', TrackingStatus::DELIVERED );
				break;
			case 'read':
				// WhatsApp reports read receipts via the delivery webhook.
				$query->where( 'status', TrackingStatus::READ );
				break;
			case 'opened':
				// Email-specific: check opened column.
				$query->where( 'opened', 1 );
				break;
			case 'clicked':
				// Email-specific: check clicked column.
				$query->where( 'clicked', 1 );
				break;
			case 'scheduled':
				$query->where( 'status', TrackingStatus::SCHEDULED );
				break;
		}
	}

	/**
	 * Restrict a message query to a structured error_info code.
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query      Query.
	 * @param string                                $error_code Code stored in error_info.
	 * @return void
	 */
	public static function apply_error_code( $query, $error_code ) {
		global $wpdb;

		$meta  = $wpdb->prefix . 'doublescale_communication_tracking_meta';
		$table = ( new CommunicationTrackingModel() )->getTable();

		$query->whereRaw(
			"EXISTS (
				SELECT 1 FROM {$meta} m
				WHERE m.communication_tracking_id = {$table}.id
				  AND m.meta_key = 'error_info'
				  AND JSON_VALID(m.meta_value)
				  AND JSON_UNQUOTE(JSON_EXTRACT(m.meta_value, '$.code')) = ?
			)",
			array( $error_code )
		);
	}
}
