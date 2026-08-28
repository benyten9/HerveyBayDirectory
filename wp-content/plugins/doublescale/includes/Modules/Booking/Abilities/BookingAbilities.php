<?php
/**
 * Read-only booking abilities.
 *
 * @package DoubleScale\Modules\Booking
 */

namespace DoubleScale\Modules\Booking\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Booking\Models\BookingModel;

/**
 * "What is on my calendar" is the question this answers, and answering it
 * moves nothing.
 *
 * Creating or cancelling a booking is deliberately absent: both send a
 * confirmation to the person who booked, which puts them under the same
 * no-sends rule as invoices and campaigns.
 *
 * Ownership is unusual here. A booking has no owner column — hosts live in a
 * separate `booking_hosts` table — so scoping is a whereHas on that relation
 * rather than the AbilityScope::apply() used everywhere else.
 */
final class BookingAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'has_booking_access' );

		return array(
			'doublescale/list-bookings' => array(
				'module_slug'      => 'booking',
				'label'            => __( 'List bookings', 'doublescale' ),
				'description'      => __( 'List bookings with their time, status, event, and the contact who booked. Unless you can manage all bookings you see only bookings you host — check get-context first. Read-only: this never books, reschedules, or cancels anything.', 'doublescale' ),
				'category'         => AbilityCategories::BOOKING,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'     => array(
							'type'        => 'string',
							'description' => 'Filter by booking status.',
							'enum'        => array( 'scheduled', 'completed', 'cancelled', 'no-show', 'pending' ),
						),
						'from'       => array(
							'type'        => 'string',
							'description' => 'Only bookings starting on or after this date (YYYY-MM-DD).',
						),
						'to'         => array(
							'type'        => 'string',
							'description' => 'Only bookings starting on or before this date (YYYY-MM-DD).',
						),
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only bookings made by this contact.',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'     => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_bookings' ),
			),

			'doublescale/get-booking'   => array(
				'module_slug'      => 'booking',
				'label'            => __( 'Get booking', 'doublescale' ),
				'description'      => __( 'One booking with its time, status, event, hosts, and the contact who booked.', 'doublescale' ),
				'category'         => AbilityCategories::BOOKING,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Booking id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_booking' ),
			),

			'doublescale/get-booking-summary' => array(
				'module_slug'      => 'booking',
				'label'            => __( 'Get booking summary', 'doublescale' ),
				'description'      => __( 'Booking counts by status, plus how many are upcoming. Scoped the same way as list-bookings: if your scope is "own", these are the bookings you host only.', 'doublescale' ),
				'category'         => AbilityCategories::BOOKING,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'from' => array(
							'type'        => 'string',
							'description' => 'Count bookings starting on or after this date (YYYY-MM-DD).',
						),
						'to'   => array(
							'type'        => 'string',
							'description' => 'Count bookings starting on or before this date (YYYY-MM-DD).',
						),
					),
				),
				'execute_callback' => array( self::class, 'get_booking_summary' ),
			),
		);
	}

	/**
	 * Booking counts by status, plus upcoming.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_booking_summary( $input ) {
		$input    = (array) $input;
		$sees_all = self::sees_all();

		$by_status = array();
		foreach ( self::scoped_query( $input, $sees_all )->get() as $booking ) {
			$status               = (string) $booking->status;
			$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + 1;
		}

		$upcoming = self::scoped_query( $input, $sees_all )
			->where( 'start_time', '>=', gmdate( 'Y-m-d H:i:s' ) )
			->where( 'status', 'scheduled' )
			->count();

		return array(
			'total'     => (int) self::scoped_query( $input, $sees_all )->count(),
			'upcoming'  => (int) $upcoming,
			'by_status' => $by_status,
			'scope'     => AbilityScope::label( $sees_all ),
		);
	}

	/**
	 * A fresh query with host scoping and date filters applied.
	 *
	 * Fresh each call so the summary can count several variations without one
	 * filter leaking into the next.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input    Ability input.
	 * @param bool                 $sees_all Whether the caller sees every booking.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	private static function scoped_query( array $input, bool $sees_all ) {
		$query = BookingModel::query();

		if ( ! $sees_all ) {
			$user_id = get_current_user_id();
			$query->whereHas(
				'hosts',
				static function ( $host_query ) use ( $user_id ) {
					$host_query->where( 'user_id', $user_id );
				}
			);
		}

		if ( ! empty( $input['from'] ) ) {
			$query->where( 'start_time', '>=', sanitize_text_field( (string) $input['from'] ) . ' 00:00:00' );
		}

		if ( ! empty( $input['to'] ) ) {
			$query->where( 'start_time', '<=', sanitize_text_field( (string) $input['to'] ) . ' 23:59:59' );
		}

		return $query;
	}

	/**
	 * Whether the caller sees every booking or only the ones they host.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all(): bool {
		return Permissions::can_manage_all_bookings() || Permissions::is_crm_manager();
	}

	/**
	 * List bookings, scoped to the caller's own unless they manage all.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_bookings( $input ) {
		$input  = (array) $input;
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = BookingModel::query()->with( array( 'event', 'contact' ) );

		$sees_all = self::sees_all();
		if ( ! $sees_all ) {
			$user_id = get_current_user_id();
			$query->whereHas(
				'hosts',
				static function ( $host_query ) use ( $user_id ) {
					$host_query->where( 'user_id', $user_id );
				}
			);
		}

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', sanitize_text_field( (string) $input['status'] ) );
		}

		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		if ( ! empty( $input['from'] ) ) {
			$query->where( 'start_time', '>=', sanitize_text_field( (string) $input['from'] ) . ' 00:00:00' );
		}

		if ( ! empty( $input['to'] ) ) {
			$query->where( 'start_time', '<=', sanitize_text_field( (string) $input['to'] ) . ' 23:59:59' );
		}

		$total = (int) $query->count();

		$rows = $query->orderBy( 'start_time', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape( $row );
		}

		return AbilityResult::collection(
			$items,
			$total,
			$limit,
			$offset,
			array( 'scope' => AbilityScope::label( $sees_all ) )
		);
	}

	/**
	 * One booking.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_booking( $input ) {
		$input   = (array) $input;
		$booking = BookingModel::with( array( 'event', 'contact', 'hosts' ) )
			->find( (int) ( $input['id'] ?? 0 ) );

		if ( ! $booking ) {
			return AbilityResult::not_found( __( 'Booking not found.', 'doublescale' ) );
		}

		$hosts = array();
		foreach ( $booking->hosts as $host ) {
			$hosts[] = (int) $host->user_id;
		}

		if ( ! self::sees_all() && ! in_array( get_current_user_id(), $hosts, true ) ) {
			return AbilityResult::forbidden( __( 'You can only view bookings you host.', 'doublescale' ) );
		}

		$out          = self::shape( $booking );
		$out['hosts'] = $hosts;

		return $out;
	}

	/**
	 * Common booking fields.
	 *
	 * @since 1.0.0
	 *
	 * @param object $booking Booking row.
	 * @return array<string, mixed>
	 */
	private static function shape( $booking ): array {
		$event   = $booking->event;
		$contact = $booking->contact;

		return array(
			'id'         => (int) $booking->id,
			'status'     => (string) $booking->status,
			'start_time' => (string) $booking->start_time,
			'end_time'   => (string) $booking->end_time,
			'duration'   => (int) $booking->slot_time,
			'source'     => (string) $booking->source,
			'event'      => $event ? array(
				'id'   => (int) $event->id,
				'name' => (string) $event->name,
			) : null,
			'contact'    => $contact ? array(
				'id'   => (int) $contact->id,
				'name' => $booking->getContactDisplayName(),
			) : null,
			'created_at' => (string) $booking->created_at,
		);
	}
}
