<?php
/**
 * Read-only communication tracking abilities.
 *
 * @package DoubleScale\Modules\Tracking
 */

namespace DoubleScale\Modules\Tracking\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * Every email, SMS, and WhatsApp message the CRM sent, and whether it was
 * opened or clicked.
 *
 * This is the record of what already happened, so it is read-only in the
 * strongest sense: there is no write half even conceivable here. Nothing in
 * this class sends a message; it reports on messages already sent.
 *
 * Engagement is the question the other tools cannot answer. A contact record
 * says who someone is; this says whether they are actually listening.
 */
final class TrackingAbilities {

	/**
	 * Channel names, mapped to the model's integer `mode` column.
	 *
	 * @var array<string, int>
	 */
	private const CHANNELS = array(
		'email'    => CommunicationTrackingModel::MODE_EMAIL,
		'sms'      => CommunicationTrackingModel::MODE_SMS,
		'whatsapp' => CommunicationTrackingModel::MODE_WHATSAPP,
	);

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		// Message history spans every contact, so it follows the same gate as
		// reading contacts rather than a narrower one.
		$permission = array( Permissions::class, 'can_read_contacts' );

		return array(
			'doublescale/list-messages'         => array(
				'module_slug'      => 'tracking',
				'label'            => __( 'List sent messages', 'doublescale' ),
				'description'      => __( 'Message history across email, SMS, and WhatsApp, with delivery status and whether each was opened or clicked. Use this to answer whether a contact is engaging. Read-only: this reports on messages already sent and never sends anything.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id'  => array(
							'type'        => 'integer',
							'description' => 'Only messages to or from this contact.',
						),
						'channel'     => array(
							'type'        => 'string',
							'description' => 'Filter by delivery channel.',
							'enum'        => array( 'email', 'sms', 'whatsapp' ),
						),
						'direction'   => array(
							'type'        => 'string',
							'description' => 'Filter by direction.',
							'enum'        => array( 'outbound', 'inbound' ),
						),
						'source'      => array(
							'type'        => 'string',
							'description' => 'What produced the message.',
							'enum'        => array( 'campaign', 'automation', 'individual', 'booking' ),
						),
						'engagement'  => array(
							'type'        => 'string',
							'description' => 'Restrict to messages that were opened, clicked, or neither.',
							'enum'        => array( 'opened', 'clicked', 'unopened' ),
						),
						'from'        => array(
							'type'        => 'string',
							'description' => 'Only messages sent on or after this date (YYYY-MM-DD).',
						),
						'to'          => array(
							'type'        => 'string',
							'description' => 'Only messages sent on or before this date (YYYY-MM-DD).',
						),
						'limit'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'      => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_messages' ),
			),

			'doublescale/get-engagement-summary' => array(
				'module_slug'      => 'tracking',
				'label'            => __( 'Get engagement summary', 'doublescale' ),
				'description'      => __( 'Sent, opened, and clicked counts with open and click rates. Pass contact_id for one contact’s engagement, or omit it for the whole site. Rates are null when nothing was sent, never zero, so an empty period is not mistaken for poor performance.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Limit to one contact. Omit for site-wide totals.',
						),
						'channel'    => array(
							'type'        => 'string',
							'description' => 'Filter by delivery channel.',
							'enum'        => array( 'email', 'sms', 'whatsapp' ),
						),
						'from'       => array(
							'type'        => 'string',
							'description' => 'Count messages sent on or after this date (YYYY-MM-DD).',
						),
						'to'         => array(
							'type'        => 'string',
							'description' => 'Count messages sent on or before this date (YYYY-MM-DD).',
						),
					),
				),
				'execute_callback' => array( self::class, 'get_engagement_summary' ),
			),
		);
	}

	/**
	 * List tracked messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_messages( $input ) {
		$input  = (array) $input;
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = self::filtered_query( $input );

		if ( ! empty( $input['engagement'] ) ) {
			switch ( (string) $input['engagement'] ) {
				case 'opened':
					$query->where( 'opened', 1 );
					break;
				case 'clicked':
					$query->where( 'clicked', 1 );
					break;
				case 'unopened':
					$query->where( 'opened', 0 );
					break;
			}
		}

		$total = (int) $query->count();

		$rows = $query->with( array( 'contact' ) )
			->orderBy( 'sent_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$contact = $row->contact;

			$items[] = array(
				'id'         => (int) $row->id,
				'channel'    => self::channel_name( (int) $row->mode ),
				'direction'  => MessageDirection::INBOUND === (int) $row->direction ? 'inbound' : 'outbound',
				'source'     => self::source_name( (int) $row->source_type ),
				'status'     => self::status_name( (int) $row->status ),
				'recipient'  => (string) $row->recipient,
				'opened'     => (bool) $row->opened,
				'clicked'    => (bool) $row->clicked,
				'sent_at'    => $row->sent_at ? (string) $row->sent_at : null,
				'opened_at'  => $row->opened_at ? (string) $row->opened_at : null,
				'clicked_at' => $row->clicked_at ? (string) $row->clicked_at : null,
				'contact'    => $contact ? array(
					'id'    => (int) $contact->id,
					'email' => (string) $contact->email,
				) : null,
			);
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * Engagement counts and rates.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_engagement_summary( $input ) {
		$input = (array) $input;

		$sent    = (int) self::filtered_query( $input )->count();
		$opened  = (int) self::filtered_query( $input )->where( 'opened', 1 )->count();
		$clicked = (int) self::filtered_query( $input )->where( 'clicked', 1 )->count();

		return array(
			'sent'       => $sent,
			'opened'     => $opened,
			'clicked'    => $clicked,
			// Null rather than 0 when nothing was sent: a rate of zero reads as
			// "nobody opened it", which is a different claim from "nothing went
			// out". An agent repeating that distinction matters.
			'open_rate'  => $sent > 0 ? round( $opened / $sent * 100, 1 ) : null,
			'click_rate' => $sent > 0 ? round( $clicked / $sent * 100, 1 ) : null,
			'contact_id' => isset( $input['contact_id'] ) ? (int) $input['contact_id'] : null,
			'channel'    => isset( $input['channel'] ) ? (string) $input['channel'] : 'all',
		);
	}

	/**
	 * Query with the filters shared by both abilities applied.
	 *
	 * Returns a fresh builder each call so the summary can count several
	 * variations without one filter leaking into the next.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	private static function filtered_query( array $input ) {
		$query = CommunicationTrackingModel::query();

		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		if ( ! empty( $input['channel'] ) && isset( self::CHANNELS[ $input['channel'] ] ) ) {
			$query->where( 'mode', self::CHANNELS[ $input['channel'] ] );
		}

		if ( ! empty( $input['direction'] ) ) {
			$query->where(
				'direction',
				'inbound' === $input['direction'] ? MessageDirection::INBOUND : MessageDirection::OUTBOUND
			);
		}

		if ( ! empty( $input['source'] ) ) {
			$sources = array(
				'campaign'   => MessageSourceTypes::CAMPAIGN,
				'automation' => MessageSourceTypes::AUTOMATION,
				'individual' => MessageSourceTypes::INDIVIDUAL,
				'booking'    => MessageSourceTypes::BOOKING,
			);

			if ( isset( $sources[ $input['source'] ] ) ) {
				$query->where( 'source_type', $sources[ $input['source'] ] );
			}
		}

		if ( ! empty( $input['from'] ) ) {
			$query->where( 'sent_at', '>=', sanitize_text_field( (string) $input['from'] ) . ' 00:00:00' );
		}

		if ( ! empty( $input['to'] ) ) {
			$query->where( 'sent_at', '<=', sanitize_text_field( (string) $input['to'] ) . ' 23:59:59' );
		}

		return $query;
	}

	/**
	 * @since 1.0.0
	 *
	 * @param int $mode Mode column.
	 * @return string
	 */
	private static function channel_name( int $mode ): string {
		$names = array_flip( self::CHANNELS );

		return $names[ $mode ] ?? 'email';
	}

	/**
	 * @since 1.0.0
	 *
	 * @param int $source_type Source type column.
	 * @return string
	 */
	private static function source_name( int $source_type ): string {
		switch ( $source_type ) {
			case MessageSourceTypes::CAMPAIGN:
				return 'campaign';
			case MessageSourceTypes::AUTOMATION:
				return 'automation';
			case MessageSourceTypes::BOOKING:
				return 'booking';
			case MessageSourceTypes::INDIVIDUAL:
				return 'individual';
			default:
				return 'unknown';
		}
	}

	/**
	 * @since 1.0.0
	 *
	 * @param int $status Status column.
	 * @return string
	 */
	private static function status_name( int $status ): string {
		switch ( $status ) {
			case TrackingStatus::PENDING:
				return 'pending';
			case TrackingStatus::SENT:
				return 'sent';
			case TrackingStatus::FAILED:
				return 'failed';
			case TrackingStatus::DELIVERED:
				return 'delivered';
			case TrackingStatus::SCHEDULED:
				return 'scheduled';
			case TrackingStatus::READ:
				return 'read';
			default:
				return 'unknown';
		}
	}
}
