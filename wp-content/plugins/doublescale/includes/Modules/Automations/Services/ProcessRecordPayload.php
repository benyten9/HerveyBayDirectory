<?php
/**
 * Build a process-row payload and recover when `message` is missing.
 *
 * The column is optional. A site that has not received the ALTER — or a
 * developer who dropped it — must still record that a step failed, otherwise
 * View Journey hides every step and only the trigger remains.
 *
 * @package DoubleScale\Modules\Automations
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ProcessRecordPayload helper.
 */
class ProcessRecordPayload {

	/**
	 * @param int    $automation_contact_id Automation contact id.
	 * @param int    $contact_id            Contact id.
	 * @param int    $step_id               Step id.
	 * @param string $status                Process status.
	 * @param string $message               User-visible reason. Omitted when empty.
	 * @return array<string, mixed>
	 */
	public static function attributes( $automation_contact_id, $contact_id, $step_id, $status, $message = '' ): array {
		$row = array(
			'automation_contact_id' => $automation_contact_id,
			'contact_id'            => $contact_id,
			'step_id'               => $step_id,
			'status'                => $status,
		);

		$trimmed = trim( (string) $message );
		if ( '' !== $trimmed ) {
			$row['message'] = $trimmed;
		}

		return $row;
	}

	/**
	 * Strip the optional message so the INSERT can succeed without that column.
	 *
	 * @param array<string, mixed> $row Process attributes.
	 * @return array<string, mixed>
	 */
	public static function without_message( array $row ): array {
		$copy = $row;
		unset( $copy['message'] );
		return $copy;
	}

	/**
	 * Whether the error is MySQL rejecting the optional `message` column.
	 *
	 * @param mixed $error Exception or error string.
	 */
	public static function is_unknown_message_column( $error ): bool {
		$text = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
		if ( '' === $text ) {
			return false;
		}

		if ( false === stripos( $text, 'Unknown column' ) ) {
			return false;
		}

		return (bool) preg_match( "/Unknown column ['`]message['`]/i", $text );
	}
}
