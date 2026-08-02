<?php

namespace DoubleScale\Modules\Booking\Models;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class BookedSlotModel extends Model {

	protected $table = 'doublescale_booking_booked_slots';

	protected $primary_key = 'id';

	public $timestamps = false;

	protected $fillable = array(
		'calendar_id',
		'event_id',
		'booking_id',
		'slot_start',
		'slot_end',
		'status',
	);

	protected $casts = array(
		'calendar_id' => 'integer',
		'event_id'    => 'integer',
		'booking_id'  => 'integer',
	);

	public function booking() {
		return $this->belongsTo( BookingModel::class, 'booking_id', 'id' );
	}

	public function calendar() {
		return $this->belongsTo( CalendarModel::class, 'calendar_id', 'id' );
	}

	public static function acquire( $calendar_id, $slot_start, $slot_end, $booking_id, $event_id = null ) {
		try {
			return static::create(
				array(
					'calendar_id' => $calendar_id,
					'slot_start'  => $slot_start,
					'slot_end'    => $slot_end,
					'booking_id'  => $booking_id,
					'event_id'    => $event_id,
					'status'      => 'booked',
				)
			);
		} catch ( \Exception $e ) {
			if ( self::is_duplicate_entry_error( $e ) ) {
				throw new \Exception( esc_html__( 'This time slot has just been booked. Please choose another.', 'doublescale' ) );
			}
			throw $e;
		}
	}

	public static function release( $booking_id ) {
		return static::where( 'booking_id', $booking_id )->delete();
	}

	public static function has_overlap( $calendar_id, $start, $end ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'doublescale_booking_booked_slots' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the trusted prefixed table name; all values are bound via prepare().
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE calendar_id = %d
				  AND status = 'booked'
				  AND slot_start < %s
				  AND slot_end > %s
				FOR UPDATE",
				$calendar_id,
				$end,
				$start
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count > 0;
	}

	public static function has_overlap_excluding( $calendar_id, $start, $end, $exclude_booking_id ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'doublescale_booking_booked_slots' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the trusted prefixed table name; all values are bound via prepare().
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE calendar_id = %d
				  AND status = 'booked'
				  AND booking_id <> %d
				  AND slot_start < %s
				  AND slot_end > %s
				FOR UPDATE",
				$calendar_id,
				$exclude_booking_id,
				$end,
				$start
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count > 0;
	}

	public static function count_overlaps( $calendar_id, $start, $end ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'doublescale_booking_booked_slots' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the trusted prefixed table name; all values are bound via prepare().
		$result = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE calendar_id = %d
				  AND status = 'booked'
				  AND slot_start < %s
				  AND slot_end > %s
				FOR UPDATE",
				$calendar_id,
				$end,
				$start
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $result;
	}

	private static function is_duplicate_entry_error( $e ) {
		$message = $e->getMessage();
		return (
			stripos( $message, 'Duplicate entry' ) !== false ||
			stripos( $message, '1062' ) !== false
		);
	}
}
