<?php
/**
 * Tasks about a record of a custom type (entity type 5).
 *
 * Pro knows nothing of custom types: the Custom Objects add-on answers who
 * may open a record, what it is called, who owns it and who may take a
 * task on it through the filters below. Without the add-on every answer is
 * "no", so such tasks cannot be made.
 *
 * The number is kept here as well as in Free's TaskEntityType::RECORD so
 * this file loads with a Free that predates it.
 *
 * @package DoubleScale\Pro\Modules\Tasks
 */

namespace DoubleScale\Pro\Modules\Tasks\Services;

defined( 'ABSPATH' ) || exit;

use WP_Error;

final class TaskRecordEntity {

	const TYPE = 5;

	/**
	 * Whether tasks can be about records on this site.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return (bool) apply_filters( 'doublescale_task_record_entity_available', false );
	}

	/**
	 * Whether the user may open the record (and so attach a task to it).
	 *
	 * @param int $record_id Record.
	 * @param int $user_id   User.
	 * @return bool
	 */
	public static function readable( int $record_id, int $user_id ): bool {
		return self::available() && (bool) apply_filters( 'doublescale_task_record_readable', false, $record_id, $user_id );
	}

	/**
	 * The record for task screens: id, name, type label and admin path;
	 * null when the viewer may not open it.
	 *
	 * @param int $record_id Record.
	 * @param int $viewer    User.
	 * @return array<string, mixed>|null
	 */
	public static function summary( int $record_id, int $viewer ): ?array {
		if ( ! self::available() ) {
			return null;
		}
		$summary = apply_filters( 'doublescale_task_record_summary', null, $record_id, $viewer );
		return is_array( $summary ) ? $summary : null;
	}

	/**
	 * The record as an object with a `title` (merge tags), or null.
	 *
	 * @param int $record_id Record.
	 * @return object|null
	 */
	public static function model( int $record_id ) {
		$model = self::available() ? apply_filters( 'doublescale_task_record_model', null, $record_id ) : null;
		return is_object( $model ) ? $model : null;
	}

	/**
	 * Who owns the record (0 for nobody).
	 *
	 * @param int $record_id Record.
	 * @return int
	 */
	public static function owner( int $record_id ): int {
		return self::available() ? (int) apply_filters( 'doublescale_task_record_owner', 0, $record_id ) : 0;
	}

	/**
	 * Why this person cannot take a task on the record, or null.
	 *
	 * @param int      $record_id Record.
	 * @param \WP_User $user      Wanted assignee.
	 * @return WP_Error|null
	 */
	public static function assignee_error( int $record_id, $user ): ?WP_Error {
		if ( ! self::available() ) {
			return new WP_Error( 'invalid_record', __( 'Invalid record ID.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$error = apply_filters( 'doublescale_task_record_assignee_error', null, $record_id, $user );
		return $error instanceof WP_Error ? $error : null;
	}
}
