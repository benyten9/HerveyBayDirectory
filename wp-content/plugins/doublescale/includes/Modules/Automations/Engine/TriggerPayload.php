<?php
/**
 * Shape of the payload a trigger hands to the automations queue.
 *
 * Triggers build `$args` in two styles:
 *  - `[ 'contact' => ContactModel, 'data' => [...] ]` when the contact exists;
 *  - raw contact attributes (`email`, `first_name`, …) plus `data` when it may
 *    not exist yet (guest checkout, LMS enrolment) — the contact is then
 *    created at processing time by ContactEnrollment::maybe_create_contact().
 *
 * `data` is the event snapshot (order_id, tags, changes, deal_id, …). It is
 * copied onto automation_contacts.data at enrolment and read later by
 * condition rules and merge tags, so it must reach the handler untouched.
 *
 * The Eloquent objects themselves are the only thing that does not need to
 * travel: the handler reads just their ids. Serialising a full ContactModel
 * (attributes + loaded relations) and AutomationModel per enqueue was the bulk
 * of the queue's write volume when a trigger fires for thousands of contacts,
 * and it froze contact attributes at trigger time for no reader.
 *
 * pack() replaces those objects with ids; unpack() hydrates them again. Both
 * are idempotent and accept the legacy object form, so payloads already
 * queued before an upgrade keep processing.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Engine;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Trigger payload (de)hydration.
 */
final class TriggerPayload {

	/**
	 * Reduce an automation reference to its id.
	 *
	 * @param AutomationModel|int|string|array $automation Model, id, or ['id' => …].
	 * @return int 0 when no id can be found.
	 */
	public static function automation_id( $automation ) {
		if ( $automation instanceof AutomationModel ) {
			return (int) $automation->id;
		}
		if ( is_numeric( $automation ) ) {
			return (int) $automation;
		}
		if ( is_array( $automation ) && isset( $automation['id'] ) ) {
			return (int) $automation['id'];
		}
		return 0;
	}

	/**
	 * Replace model objects in trigger args with their ids.
	 *
	 * Every other key — raw contact attributes, `data`, `context`, `test_mode` —
	 * is passed through unchanged.
	 *
	 * @param array $args Trigger args.
	 * @return array
	 */
	public static function pack( array $args ) {
		if ( isset( $args['contact'] ) && is_object( $args['contact'] ) ) {
			$contact_id = isset( $args['contact']->id ) ? (int) $args['contact']->id : 0;
			if ( $contact_id > 0 ) {
				$args['contact_id'] = $contact_id;
			}
			unset( $args['contact'] );
		}

		if ( isset( $args['booking'] ) && is_object( $args['booking'] ) ) {
			$booking_id = isset( $args['booking']->id ) ? (int) $args['booking']->id : 0;
			if ( $booking_id > 0 ) {
				$args['booking_id'] = $booking_id;
			}
			unset( $args['booking'] );
		}

		return $args;
	}

	/**
	 * Hydrate ids back into the objects ContactEnrollment expects.
	 *
	 * A payload that already carries the objects (legacy queue rows, sync
	 * callers) is returned as-is apart from the id keys being filled in.
	 *
	 * @param array $args Packed or legacy trigger args.
	 *
	 * @throws \RuntimeException When a referenced contact no longer exists.
	 *
	 * @return array
	 */
	public static function unpack( array $args ) {
		if ( ! isset( $args['contact'] ) && ! empty( $args['contact_id'] ) ) {
			$contact = ContactModel::find( (int) $args['contact_id'] );
			if ( ! $contact ) {
				throw new \RuntimeException(
					sprintf( 'Contact #%d referenced by the trigger no longer exists.', (int) $args['contact_id'] )
				);
			}
			$args['contact'] = $contact;
		} elseif ( isset( $args['contact'] ) && is_object( $args['contact'] ) && empty( $args['contact_id'] ) && isset( $args['contact']->id ) ) {
			$args['contact_id'] = (int) $args['contact']->id;
		}

		if ( isset( $args['booking'] ) && is_object( $args['booking'] ) && empty( $args['booking_id'] ) && isset( $args['booking']->id ) ) {
			$args['booking_id'] = (int) $args['booking']->id;
		}

		return $args;
	}

	/**
	 * Keys that live in the payload but are not contact columns.
	 *
	 * @return string[]
	 */
	public static function internal_keys() {
		return array( 'data', 'contact', 'contact_id', 'booking', 'booking_id', 'context', 'test_mode' );
	}
}
