<?php
/**
 * "File attached" / "File removed" activities, as the team reads them.
 *
 * Two read paths render activities — the unified timeline
 * ({@see ActivityManager::get_unified_timeline()}) and the type-filtered list
 * (`GET /activities`, {@see \DoubleScale\Modules\Activities\Models\ActivityModel}) —
 * and both must tell the same story about a file:
 *
 *   - who did it: a team member, or the contact from the client portal
 *     (`data.actor` = `contact`, no `user_id` on the row);
 *   - a link that opens. Download links are signed for about an hour, so the
 *     URL stored when the file was attached is dead by the time anyone reads
 *     the timeline. It is signed again on every read while the file exists,
 *     and dropped once the file is gone.
 *
 * @package DoubleScale\Modules\Activities
 */

namespace DoubleScale\Modules\Activities\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\Services\AttachmentService;

/**
 * FileActivityPresenter.
 */
final class FileActivityPresenter {

	/**
	 * Whether the activity type is a file event.
	 *
	 * @param string $type Activity type.
	 * @return bool
	 */
	public static function is_file_type( string $type ): bool {
		return ActivityTypes::FILE_ATTACHED === $type || ActivityTypes::FILE_REMOVED === $type;
	}

	/**
	 * Timeline sentence for a file event.
	 *
	 * @param string               $type      FILE_ATTACHED or FILE_REMOVED.
	 * @param string|null          $user_name Team member's display name, when the row has one.
	 * @param array<string, mixed> $data      Activity data.
	 * @return string
	 */
	public static function message( string $type, ?string $user_name, array $data ): string {
		if ( 'contact' === ( $data['actor'] ?? '' ) ) {
			$name = trim( (string) ( $data['actor_name'] ?? '' ) );
			$name = '' !== $name ? $name : __( 'The contact', 'doublescale' );

			$base = ActivityTypes::FILE_REMOVED === $type
				/* translators: %s: contact name */
				? sprintf( __( '%s removed a file from the client portal', 'doublescale' ), $name )
				/* translators: %s: contact name */
				: sprintf( __( '%s uploaded a file from the client portal', 'doublescale' ), $name );
		} else {
			// Null falls back to "Unknown User", as for every other activity.
			$base = ActivityTypes::get_activity_message( $type, '' !== (string) $user_name ? $user_name : null );
		}

		$file_name = isset( $data['file_name'] ) ? (string) $data['file_name'] : '';
		if ( '' === $file_name ) {
			return $base;
		}

		/* translators: 1: activity message, 2: file name */
		return sprintf( __( '%1$s: %2$s', 'doublescale' ), $base, $file_name );
	}

	/**
	 * Replace the stored (expired) download link with a fresh signed one, or
	 * drop it when the file no longer exists.
	 *
	 * @param string               $type Activity type.
	 * @param array<string, mixed> $data Activity data.
	 * @return array<string, mixed>
	 */
	public static function refresh_data( string $type, array $data ): array {
		if ( ! self::is_file_type( $type ) || empty( $data['attachment_id'] ) ) {
			return $data;
		}

		$attachment = AttachmentModel::find( (int) $data['attachment_id'] );
		if ( $attachment instanceof AttachmentModel && 'active' === (string) $attachment->status ) {
			$data['url'] = ( new AttachmentService() )->signed_url( $attachment );
		} else {
			unset( $data['url'] );
		}

		return $data;
	}
}
