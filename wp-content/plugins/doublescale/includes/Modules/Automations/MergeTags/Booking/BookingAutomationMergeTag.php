<?php
/**
 * Bridges booking-module merge tags into automation emails/actions.
 *
 * Resolves {{booking:*}}, {{guest:*}}, and {{host:*}} using the same logic as
 * booking notification templates, keyed by `booking_id` on the automation contact.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\MergeTags\Booking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Booking\Managers\MergeTagsManager as BookingMergeTagsManager;
use DoubleScale\Modules\Booking\Models\BookingModel;

/**
 * Proxy merge tag that delegates to a booking-module merge tag class.
 */
final class BookingAutomationMergeTag extends MergeTag {

	/**
	 * Booking merge tag class (must extend booking MergeTag abstract).
	 *
	 * @var class-string
	 */
	private $booking_tag_class;

	/**
	 * @param array<string, mixed> $config name, slug, group, description?, class.
	 */
	public function __construct( array $config ) {
		$this->name              = (string) ( $config['name'] ?? '' );
		$this->slug              = (string) ( $config['slug'] ?? '' );
		$this->group             = (string) ( $config['group'] ?? 'booking' );
		$this->description       = (string) ( $config['description'] ?? $this->name );
		$this->booking_tag_class = (string) ( $config['class'] ?? '' );
	}

	/**
	 * @param AutomationContactModel|\DoubleScale\Modules\Contacts\Models\ContactModel|null $contact Contact.
	 * @param string                                                                        $merge_tag Full tag tail after group (may include options).
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( ! $contact instanceof AutomationContactModel ) {
			return '';
		}

		$booking_id = isset( $contact->data['booking_id'] ) ? (int) $contact->data['booking_id'] : 0;
		if ( $booking_id <= 0 ) {
			return '';
		}

		$booking = BookingModel::find( $booking_id );
		if ( ! $booking ) {
			return '';
		}

		if ( ! class_exists( $this->booking_tag_class ) ) {
			return '';
		}

		$booking_tag = $this->booking_tag_class::instance();
		$options     = $this->parse_options( $merge_tag );

		return (string) $booking_tag->get_value( $booking, $options );
	}

	/**
	 * Parse optional attributes from the merge tag slug tail (e.g. timezone="host").
	 *
	 * @param string $merge_tag Slug portion from the template.
	 * @return array<string, string>
	 */
	private function parse_options( string $merge_tag ): array {
		$options = array();

		if ( preg_match_all( '/(\w+)="([^"]+)"/', $merge_tag, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$options[ $match[1] ] = $match[2];
			}
		}

		return $options;
	}
}

/**
 * Register all booking merge tags for automations.
 */
function doublescale_register_booking_automation_merge_tags(): void {
	if ( ! class_exists( BookingMergeTagsManager::class ) ) {
		return;
	}

	$definitions = array(
		// Booking group — {{booking:slug}}.
		array(
			'name'  => __( 'Event / Service Name', 'doublescale' ),
			'slug'  => 'event_name',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\EventName::class,
		),
		array(
			'name'  => __( 'Booking Start', 'doublescale' ),
			'slug'  => 'start_time',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\BookingStartDate::class,
		),
		array(
			'name'  => __( 'Booking End', 'doublescale' ),
			'slug'  => 'end_time',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\BookingEndDate::class,
		),
		array(
			'name'  => __( 'Booking Location', 'doublescale' ),
			'slug'  => 'event_location',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\BookingLocation::class,
		),
		array(
			'name'  => __( 'Booking Timezone', 'doublescale' ),
			'slug'  => 'timezone',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\BookingTimezone::class,
		),
		array(
			'name'  => __( 'Booking ID Hash', 'doublescale' ),
			'slug'  => 'hash',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\BookingHash::class,
		),
		array(
			'name'  => __( 'Booking Name', 'doublescale' ),
			'slug'  => 'name',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\BookingName::class,
		),
		array(
			'name'  => __( 'Cancel URL', 'doublescale' ),
			'slug'  => 'cancel_url',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\BookingCancelUrl::class,
		),
		array(
			'name'  => __( 'Reschedule URL', 'doublescale' ),
			'slug'  => 'reschedule_url',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\RescheduleUrl::class,
		),
		array(
			'name'  => __( 'Booking Details URL', 'doublescale' ),
			'slug'  => 'details_url',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\BookingDetailsUrl::class,
		),
		array(
			'name'  => __( 'Confirm URL', 'doublescale' ),
			'slug'  => 'confirm_url',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\ConfirmUrl::class,
		),
		array(
			'name'  => __( 'Reject URL', 'doublescale' ),
			'slug'  => 'reject_url',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\RejectUrl::class,
		),
		array(
			'name'  => __( 'Waiting List Position', 'doublescale' ),
			'slug'  => 'waiting_list_position',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\WaitingListPosition::class,
		),
		array(
			'name'  => __( 'Waiting List Claim URL', 'doublescale' ),
			'slug'  => 'waiting_list_claim_url',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\WaitingListClaimUrl::class,
		),
		array(
			'name'  => __( 'Additional Guests', 'doublescale' ),
			'slug'  => 'additional_guests',
			'group' => 'booking',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Booking\AdditionalGuests::class,
		),
		// Guest group — {{guest:slug}}.
		array(
			'name'  => __( 'Guest Name', 'doublescale' ),
			'slug'  => 'name',
			'group' => 'guest',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Contact\ContactName::class,
		),
		array(
			'name'  => __( 'Guest Email', 'doublescale' ),
			'slug'  => 'email',
			'group' => 'guest',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Contact\ContactEmail::class,
		),
		array(
			'name'  => __( 'Guest Note', 'doublescale' ),
			'slug'  => 'note',
			'group' => 'guest',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Contact\ContactNote::class,
		),
		array(
			'name'  => __( 'Guest Timezone', 'doublescale' ),
			'slug'  => 'timezone',
			'group' => 'guest',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Contact\ContactTimezone::class,
		),
		// Host group — {{host:slug}}.
		array(
			'name'  => __( 'Host Name', 'doublescale' ),
			'slug'  => 'name',
			'group' => 'host',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Host\HostName::class,
		),
		array(
			'name'  => __( 'Host Email', 'doublescale' ),
			'slug'  => 'email',
			'group' => 'host',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Host\HostEmail::class,
		),
		array(
			'name'  => __( 'Host Timezone', 'doublescale' ),
			'slug'  => 'timezone',
			'group' => 'host',
			'class' => \DoubleScale\Modules\Booking\MergeTags\Host\HostTimezone::class,
		),
	);

	$manager = MergeTagsManager::instance();

	foreach ( $definitions as $definition ) {
		$manager->register( new BookingAutomationMergeTag( $definition ) );
	}
}

doublescale_register_booking_automation_merge_tags();
