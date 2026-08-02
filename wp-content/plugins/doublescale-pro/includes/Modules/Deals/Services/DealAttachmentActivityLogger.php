<?php
/**
 * Logs deal file attachment events to the deal activity timeline.
 *
 * @package DoubleScale\Pro\Modules\Deals
 */

namespace DoubleScale\Pro\Modules\Deals\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;

/**
 * DealAttachmentActivityLogger class.
 */
class DealAttachmentActivityLogger {

	/**
	 * Register WP action listeners.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'doublescale_deal_file_attached', array( $this, 'on_file_attached' ), 10, 2 );
		add_action( 'doublescale_deal_file_removed', array( $this, 'on_file_removed' ), 10, 2 );
	}

	/**
	 * @param DealModel       $deal       Parent deal.
	 * @param AttachmentModel $attachment Attached file.
	 */
	public function on_file_attached( $deal, $attachment ): void {
		if ( ! $deal instanceof DealModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_deal_file_event( $deal, $attachment, ActivityTypes::FILE_ATTACHED );
	}

	/**
	 * @param DealModel       $deal       Parent deal.
	 * @param AttachmentModel $attachment Removed file.
	 */
	public function on_file_removed( $deal, $attachment ): void {
		if ( ! $deal instanceof DealModel || ! $attachment instanceof AttachmentModel ) {
			return;
		}

		$this->log_deal_file_event( $deal, $attachment, ActivityTypes::FILE_REMOVED );
	}

	/**
	 * Write a file attachment activity associated with the given deal.
	 *
	 * @param DealModel       $deal       Parent deal.
	 * @param AttachmentModel $attachment Attachment row.
	 * @param string          $event_type Activity type constant.
	 */
	private function log_deal_file_event( DealModel $deal, AttachmentModel $attachment, string $event_type ): void {
		if ( ! class_exists( ActivityAssociationModel::class ) ) {
			return;
		}

		$shaped = ( new AttachmentService() )->shape_for_api( $attachment );

		$activity = ActivityModel::create(
			array(
				'contact_id'    => (int) $deal->contact_id,
				'activity_type' => $event_type,
				'data'          => array(
					'deal_id'       => (int) $deal->id,
					'file_name'     => (string) $attachment->file_name,
					'attachment_id' => (int) $attachment->id,
					'file_size'     => (int) $attachment->file_size,
					'file_type'     => (string) $attachment->file_type,
					'url'           => (string) ( $shaped['url'] ?? '' ),
				),
				'user_id'       => get_current_user_id(),
			)
		);

		if ( ! $activity ) {
			return;
		}

		ActivityAssociationModel::create(
			array(
				'activity_id' => $activity->id,
				'entity_type' => ActivityAssociationModel::ENTITY_TYPE_DEAL,
				'entity_id'   => (int) $deal->id,
			)
		);
	}
}
