<?php
/**
 * Workflow run model.
 *
 * Tracks execution of a single side-effect triggered by a booking lifecycle
 * event. Provides idempotency (each `(booking_id, event, action)` triple runs
 * exactly once), retry tracking with exponential backoff, and a queryable
 * audit trail for the {@see \DoubleScale\Modules\Booking\Services\EventBus}.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Models;

use WPEloquent\Eloquent\Model;

defined( 'ABSPATH' ) || exit;

class WorkflowRunModel extends Model {

	protected $table = 'doublescale_booking_workflow_runs';

	protected $primary_key = 'id';

	public $timestamps = false;

	protected $fillable = array(
		'booking_id',
		'event_name',
		'action_name',
		'idempotency_key',
		'status',
		'attempts',
		'max_attempts',
		'payload',
		'result',
		'error_message',
		'started_at',
		'completed_at',
		'next_retry_at',
	);

	protected $casts = array(
		'booking_id'   => 'integer',
		'attempts'     => 'integer',
		'max_attempts' => 'integer',
	);

	public function booking() {
		return $this->belongsTo( BookingModel::class, 'booking_id', 'id' );
	}

	/**
	 * Build the idempotency key for a `(booking, event, action)` triple.
	 */
	public static function make_key( $booking_id, $event_name, $action_name ) {
		return sprintf( '%d:%s:%s', $booking_id, $event_name, $action_name );
	}

	/**
	 * Has this exact action already completed for this booking + event?
	 */
	public static function already_completed( $idempotency_key ) {
		return static::where( 'idempotency_key', $idempotency_key )
			->where( 'status', 'completed' )
			->exists();
	}

	/**
	 * Mark this run as started: bump attempts, stamp started_at.
	 */
	public function markStarted() {
		$this->status     = 'running';
		$this->started_at = gmdate( 'Y-m-d H:i:s' );
		$this->attempts   = (int) $this->attempts + 1;
		$this->save();
	}

	/**
	 * Mark this run as completed.
	 *
	 * @param string|null $result Serialized result data.
	 */
	public function markCompleted( $result = null ) {
		$this->status        = 'completed';
		$this->completed_at  = gmdate( 'Y-m-d H:i:s' );
		$this->result        = $result;
		$this->error_message = null;
		$this->save();
	}

	/**
	 * Mark this run as failed.
	 *
	 * If we still have retries left, status drops back to `pending` with an
	 * exponential `next_retry_at`. Otherwise it sticks at `failed` and the
	 * cron sweeper ignores it.
	 */
	public function markFailed( $error ) {
		$this->error_message = $error;

		if ( (int) $this->attempts >= (int) $this->max_attempts ) {
			$this->status = 'failed';
		} else {
			$this->status        = 'pending';
			$backoff_seconds     = pow( 2, (int) $this->attempts ) * 60;
			$this->next_retry_at = gmdate( 'Y-m-d H:i:s', time() + $backoff_seconds );
		}

		$this->save();
	}
}
