<?php
/**
 * Contact-email attachment adapter — delegates to the core attachment service.
 *
 * Lives in Free because the `email_received` activity and the contact Emails
 * timeline are Free-owned. Only Pro's inbound poller calls
 * store_email_attachment(), but the service must load in a Free-standalone
 * install so get_messages() can map attachments for display.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService as CoreAttachmentService;
use WP_Error;

/**
 * EmailAttachmentService class.
 */
final class EmailAttachmentService {

	/**
	 * Polymorphic owner type for contact (individual) email attachments.
	 *
	 * @var string
	 */
	public const ATTACHABLE_TYPE = 'contact_email';

	/**
	 * Extra MIME types accepted for INBOUND email only (scoped via a one-shot
	 * filter so Support uploads / global limits are untouched). svg/html are
	 * deliberately excluded — images are served `inline`, so an SVG could carry
	 * script; everything here is either non-rendered or a safe raster/text type.
	 *
	 * @var string[]
	 */
	private const INBOUND_EXTRA_MIMES = array(
		'text/csv',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation', // pptx.
		'application/vnd.ms-powerpoint',
		'image/heic',
		'image/heif',
		'image/bmp',
		'image/tiff',
		'application/rtf',
		'application/x-zip-compressed',
	);

	/**
	 * Core attachment service.
	 *
	 * @var CoreAttachmentService
	 */
	private $core;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->core = new CoreAttachmentService();
	}

	/**
	 * Store one inbound email attachment, linked to its conversation activity.
	 *
	 * @param array<string, mixed> $file        {filename, mime, content, content_id} from ImapClient.
	 * @param int                  $contact_id  Owner contact id (attachable_id).
	 * @param int                  $activity_id The email_received activity id.
	 * @return AttachmentModel|WP_Error
	 */
	public function store_email_attachment( array $file, int $contact_id, int $activity_id ) {
		$subdir = 'doublescale-contacts/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );

		$widen = static function ( $mimes ) {
			return array_values( array_unique( array_merge( (array) $mimes, self::INBOUND_EXTRA_MIMES ) ) );
		};

		add_filter( 'doublescale_attachment_accepted_mimes', $widen );
		try {
			$result = $this->core->store_raw(
				$file,
				self::ATTACHABLE_TYPE,
				$contact_id,
				array( 'contact_id' => $contact_id ),
				array(
					'activity_id'        => $activity_id,
					'storage_subdir'     => $subdir,
					'protected_base_dir' => 'doublescale-contacts',
				)
			);
		} finally {
			remove_filter( 'doublescale_attachment_accepted_mimes', $widen );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return AttachmentModel::find( (int) $result->id );
	}

	/**
	 * Map active attachments for a set of email activities to shaped API rows.
	 *
	 * Mirrors Support\Services\AttachmentService::map_for_activities(). Each row
	 * is shaped with the non-legacy signed URL (`?ds_file=&ds_sign=`) served by
	 * the global Core\Renderer\AttachmentServeHandler.
	 *
	 * @param int[] $activity_ids Email activity ids.
	 * @return array<int, array<int, array<string, mixed>>> activity_id => [shaped, …].
	 */
	public function map_for_activities( array $activity_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $activity_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$rows = AttachmentModel::query()
			->where( 'attachable_type', self::ATTACHABLE_TYPE )
			->whereIn( 'activity_id', $ids )
			->where( 'status', 'active' )
			->get();

		$map = array();
		foreach ( $rows as $attachment ) {
			$aid = (int) $attachment->activity_id;
			if ( ! isset( $map[ $aid ] ) ) {
				$map[ $aid ] = array();
			}

			// Mirror Support's tighter API surface: drop the internal id / file_hash /
			// created_at keys. Download is gated by the time-based HMAC `sign`, not the
			// hash, so omitting them costs nothing and keeps the response lean.
			$shaped = $this->core->shape_for_api( $attachment, false, false );
			unset( $shaped['id'], $shaped['file_hash'], $shaped['created_at'] );

			$map[ $aid ][] = $shaped;
		}

		return $map;
	}
}
