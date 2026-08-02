<?php
/**
 * Support attachment adapter — delegates to the core attachment service.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\AttachmentService as CoreAttachmentService;
use DoubleScale\Modules\Support\Models\AttachmentModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use WP_Error;

/**
 * AttachmentService class.
 */
class AttachmentService {

	/**
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
	 * Accepted MIME types for ticket uploads.
	 *
	 * @return string[]
	 */
	public static function accepted_mimes(): array {
		$mimes = apply_filters( 'doublescale_support_accepted_ticket_mimes', CoreAttachmentService::accepted_mimes() );
		return is_array( $mimes ) ? array_values( array_filter( array_map( 'strval', $mimes ) ) ) : CoreAttachmentService::accepted_mimes();
	}

	/**
	 * @param int $already_staged Count of files already attached to this draft.
	 * @return WP_Error|null
	 */
	public function guard_file_count( int $already_staged ): ?WP_Error {
		$max = AttachmentSettings::max_file_count();
		if ( $already_staged >= $max ) {
			return new WP_Error(
				'too_many_files',
				sprintf(
					/* translators: %d: maximum number of files allowed per message */
					_n(
						'You can attach at most %d file.',
						'You can attach at most %d files.',
						$max,
						'doublescale'
					),
					$max
				),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $file      PHP upload array.
	 * @param int                  $ticket_id Parent ticket id, or 0 for ticketless temp upload.
	 * @param array<string, mixed> $uploader  user_id and/or contact_id.
	 * @return AttachmentModel|WP_Error
	 */
	public function store_upload( array $file, int $ticket_id, array $uploader ) {
		if ( $ticket_id > 0 && ! TicketModel::find( $ticket_id ) ) {
			return new WP_Error( 'ticket_not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$subdir = 'doublescale-support/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );

		$result = $this->core->store_upload(
			$file,
			AttachmentModel::ATTACHABLE_TYPE,
			$ticket_id > 0 ? $ticket_id : null,
			$uploader,
			array(
				'status'             => 'temp',
				'storage_subdir'     => $subdir,
				'protected_base_dir' => 'doublescale-support',
				'max_size_bytes'     => AttachmentSettings::max_file_size_bytes(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return AttachmentModel::find( (int) $result->id );
	}

	/**
	 * @param int      $activity_id Activity row id.
	 * @param int      $ticket_id   Parent ticket id.
	 * @param string[] $file_hashes Temp attachment hashes from the client.
	 * @return void
	 */
	public function link_to_activity( int $activity_id, int $ticket_id, array $file_hashes ): void {
		$hashes = array_values( array_filter( array_map( 'strval', $file_hashes ) ) );
		if ( empty( $hashes ) ) {
			return;
		}

		$rows = AttachmentModel::query()
			->forType( AttachmentModel::ATTACHABLE_TYPE )
			->where( 'status', 'temp' )
			->whereIn( 'file_hash', $hashes )
			->where(
				function ( $query ) use ( $ticket_id ) {
					$query->where( 'attachable_id', $ticket_id )->orWhereNull( 'attachable_id' );
				}
			)
			->get();

		foreach ( $rows as $attachment ) {
			$attachment->attachable_id = $ticket_id;
			$attachment->activity_id   = $activity_id;
			$attachment->status        = 'active';
			$attachment->save();
		}
	}

	/**
	 * @param string $file_hash File hash query arg.
	 * @param string $sign      HMAC signature query arg.
	 * @return void
	 */
	public function serve( string $file_hash, string $sign ): void {
		$this->core->serve( $file_hash, $sign );
	}

	/**
	 * @param AttachmentModel $attachment Attachment model.
	 * @return string
	 */
	public function signed_url( AttachmentModel $attachment ): string {
		return $this->core->signed_url( $attachment, true );
	}

	/**
	 * @param AttachmentModel $attachment Attachment model.
	 * @param bool            $is_inline  Whether the attachment is rendered inline in the body.
	 * @return array{file_name: string, file_size: int, file_type: string, url: string, is_inline: bool}
	 */
	public function shape_for_api( AttachmentModel $attachment, bool $is_inline = false ): array {
		$shaped = $this->core->shape_for_api( $attachment, $is_inline, true );
		unset( $shaped['id'], $shaped['file_hash'], $shaped['created_at'] );
		return $shaped;
	}

	/**
	 * @param int[] $activity_ids Activity ids.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public function map_for_activities( array $activity_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $activity_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$rows = AttachmentModel::query()
			->forType( AttachmentModel::ATTACHABLE_TYPE )
			->whereIn( 'activity_id', $ids )
			->where( 'status', 'active' )
			->get();

		$bodies = $this->activity_bodies_for_inline( $rows, $ids );

		$map = array();
		foreach ( $rows as $attachment ) {
			$aid = (int) $attachment->activity_id;
			if ( ! isset( $map[ $aid ] ) ) {
				$map[ $aid ] = array();
			}

			$cid       = trim( (string) $attachment->content_id, " <>\t\r\n" );
			$is_inline = '' !== $cid
				&& isset( $bodies[ $aid ] )
				&& $this->body_references_inline_image( $bodies[ $aid ], $attachment, $cid );

			$map[ $aid ][] = $this->shape_for_api( $attachment, $is_inline );
		}
		return $map;
	}

	/**
	 * @param iterable $attachments  Active attachment models.
	 * @param int[]    $activity_ids Activity ids.
	 * @return array<int, string>
	 */
	private function activity_bodies_for_inline( $attachments, array $activity_ids ): array {
		$inline_activity_ids = array();
		foreach ( $attachments as $attachment ) {
			if ( '' !== trim( (string) $attachment->content_id, " <>\t\r\n" ) ) {
				$inline_activity_ids[ (int) $attachment->activity_id ] = true;
			}
		}
		if ( empty( $inline_activity_ids ) ) {
			return array();
		}

		$wanted     = array_values( array_intersect( $activity_ids, array_keys( $inline_activity_ids ) ) );
		$bodies     = array();
		$activities = \DoubleScale\Modules\Activities\Models\ActivityModel::query()
			->whereIn( 'id', $wanted )
			->get();

		foreach ( $activities as $activity ) {
			$data                          = is_array( $activity->data ) ? $activity->data : array();
			$bodies[ (int) $activity->id ] = isset( $data['content'] ) ? (string) $data['content'] : '';
		}
		return $bodies;
	}

	/**
	 * @param string          $body       Rendered activity body HTML.
	 * @param AttachmentModel $attachment The attachment under test.
	 * @param string          $cid        Trimmed Content-ID.
	 * @return bool
	 */
	private function body_references_inline_image( string $body, AttachmentModel $attachment, string $cid ): bool {
		if ( '' === $body ) {
			return false;
		}

		$file_hash = (string) $attachment->file_hash;
		if ( '' !== $file_hash ) {
			if ( false !== stripos( $body, 'ds_support_file=' . $file_hash ) ) {
				return true;
			}
			if ( false !== stripos( $body, 'ds_file=' . $file_hash ) ) {
				return true;
			}
		}

		return false !== stripos( $body, 'cid:' . $cid )
			|| false !== stripos( $body, '"' . $cid . '"' )
			|| false !== stripos( $body, "'" . $cid . "'" );
	}

	/**
	 * @return int
	 */
	public function cleanup_stale_temp(): int {
		return $this->core->cleanup_stale_temp( AttachmentModel::ATTACHABLE_TYPE );
	}

	/**
	 * @param int $activity_id Activity id.
	 * @return string[]
	 */
	public function absolute_paths_for_activity( int $activity_id ): array {
		$rows = AttachmentModel::query()
			->forType( AttachmentModel::ATTACHABLE_TYPE )
			->where( 'activity_id', $activity_id )
			->where( 'status', 'active' )
			->get();

		$paths = array();
		foreach ( $rows as $attachment ) {
			$absolute = AttachmentModel::resolve_absolute_path( (string) $attachment->file_path );
			if ( '' !== $absolute && is_file( $absolute ) ) {
				$paths[] = $absolute;
			}
		}
		return $paths;
	}

	/**
	 * @param array<string, mixed> $file        Email attachment payload.
	 * @param int                  $ticket_id   Parent ticket id.
	 * @param int                  $activity_id Conversation activity id.
	 * @param array<string, mixed> $uploader    Optional uploader ids.
	 * @return AttachmentModel|WP_Error
	 */
	public function store_email_attachment( array $file, int $ticket_id, int $activity_id, array $uploader = array() ) {
		$subdir = 'doublescale-support/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );

		$result = $this->core->store_raw(
			$file,
			AttachmentModel::ATTACHABLE_TYPE,
			$ticket_id,
			$uploader,
			array(
				'activity_id'        => $activity_id,
				'storage_subdir'     => $subdir,
				'protected_base_dir' => 'doublescale-support',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return AttachmentModel::find( (int) $result->id );
	}

	/**
	 * @param string $body        Email body HTML.
	 * @param int    $activity_id Conversation activity id.
	 * @return string
	 */
	public function rewrite_inline_image_srcs( string $body, int $activity_id ): string {
		if ( '' === $body || $activity_id <= 0 ) {
			return $body;
		}

		$rows = AttachmentModel::query()
			->forType( AttachmentModel::ATTACHABLE_TYPE )
			->where( 'activity_id', $activity_id )
			->where( 'status', 'active' )
			->whereNotNull( 'content_id' )
			->get();

		if ( $rows->isEmpty() ) {
			return $body;
		}

		$map = array();
		foreach ( $rows as $attachment ) {
			$cid = trim( (string) $attachment->content_id, " <>\t\r\n" );
			if ( '' !== $cid ) {
				$map[ $cid ] = $this->signed_url( $attachment );
			}
		}
		if ( empty( $map ) ) {
			return $body;
		}

		return (string) preg_replace_callback(
			'/(<img\b[^>]*?\bsrc=)(["\'])(?:cid:)?([^"\']+)\2/i',
			static function ( $matches ) use ( $map ) {
				$ref = trim( $matches[3], " <>\t\r\n" );
				if ( isset( $map[ $ref ] ) ) {
					return $matches[1] . $matches[2] . esc_url( $map[ $ref ] ) . $matches[2];
				}
				return $matches[0];
			},
			$body
		);
	}
}
