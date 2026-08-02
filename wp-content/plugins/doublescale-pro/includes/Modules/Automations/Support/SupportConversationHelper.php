<?php
/**
 * Shared helpers for support ticket conversation data in automations.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Support;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Support\Models\TicketModel;

defined( 'ABSPATH' ) || exit;

final class SupportConversationHelper {

	/**
	 * Extract HTML/plain content from an activity row.
	 *
	 * @param ActivityModel|null $activity Activity model.
	 * @return string
	 */
	public static function get_activity_content( ?ActivityModel $activity ): string {
		if ( ! $activity ) {
			return '';
		}
		$data = is_array( $activity->data ) ? $activity->data : array();
		return isset( $data['content'] ) ? (string) $data['content'] : '';
	}

	/**
	 * Strip tags for text-rule comparisons.
	 *
	 * @param string $content HTML or plain text.
	 * @return string
	 */
	public static function plain_text( string $content ): string {
		return trim( wp_strip_all_tags( $content ) );
	}

	/**
	 * Read activity_id from automation enrollment data.
	 *
	 * @param object $automation_contact Automation contact model.
	 * @return int
	 */
	public static function get_enrollment_activity_id( $automation_contact ): int {
		if ( ! is_object( $automation_contact ) || ! isset( $automation_contact->data['activity_id'] ) ) {
			return 0;
		}
		return (int) $automation_contact->data['activity_id'];
	}

	/**
	 * Resolve the activity referenced by the current enrollment, if any.
	 *
	 * @param object $automation_contact Automation contact model.
	 * @return ActivityModel|null
	 */
	public static function resolve_enrollment_activity( $automation_contact ): ?ActivityModel {
		$activity_id = self::get_enrollment_activity_id( $automation_contact );
		if ( $activity_id <= 0 ) {
			return null;
		}
		$activity = ActivityModel::find( $activity_id );
		return $activity instanceof ActivityModel ? $activity : null;
	}

	/**
	 * First customer-visible reply on the ticket (the opening message).
	 *
	 * @param TicketModel $ticket Ticket model.
	 * @return ActivityModel|null
	 */
	public static function get_opening_activity( TicketModel $ticket ): ?ActivityModel {
		$activity = ActivityModel::forTicket( $ticket->id )
			->where( 'activity_type', ActivityTypes::SUPPORT_REPLY )
			->first();

		return $activity instanceof ActivityModel ? $activity : null;
	}

	/**
	 * Opening message body for a ticket.
	 *
	 * @param TicketModel $ticket Ticket model.
	 * @return string
	 */
	public static function get_opening_content( TicketModel $ticket ): string {
		return self::get_activity_content( self::get_opening_activity( $ticket ) );
	}

	/**
	 * Opening channel (`web` or `email`) from the first reply activity.
	 *
	 * @param TicketModel $ticket Ticket model.
	 * @return string
	 */
	public static function get_opening_source( TicketModel $ticket ): string {
		$activity = self::get_opening_activity( $ticket );
		if ( ! $activity ) {
			return 'web';
		}
		$data   = is_array( $activity->data ) ? $activity->data : array();
		$source = isset( $data['source'] ) ? strtolower( (string) $data['source'] ) : 'web';

		return in_array( $source, array( 'web', 'email' ), true ) ? $source : 'web';
	}

	/**
	 * Content for the activity that triggered the automation, when it matches $type.
	 *
	 * @param object      $automation_contact Automation contact model.
	 * @param string|null $type               {@see ActivityTypes} value, or null for any type.
	 * @return string
	 */
	public static function get_trigger_activity_content( $automation_contact, ?string $type = null ): string {
		$activity = self::resolve_enrollment_activity( $automation_contact );
		if ( ! $activity ) {
			return '';
		}
		if ( null !== $type && (string) $activity->activity_type !== $type ) {
			return '';
		}
		return self::get_activity_content( $activity );
	}
}
