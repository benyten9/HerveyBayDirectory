<?php
/**
 * Read-only campaign abilities.
 *
 * @package DoubleScale\Modules\Campaigns
 */

namespace DoubleScale\Modules\Campaigns\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;

/**
 * Reading a campaign sends nothing, so these are safe — but every WRITE here
 * stays out of the ability layer on purpose. Scheduling a campaign starts a
 * real send to real people, and there is no undo anywhere in this product.
 *
 * Sending is the one decision a human keeps.
 */
final class CampaignAbilities {

	/**
	 * Channels a campaign can be filtered or grouped by.
	 *
	 * `sequence_mail` is excluded on purpose: those rows are the individual
	 * emails inside a sequence, not campaigns a user would recognise.
	 *
	 * @var array<int, string>
	 */
	private const CHANNEL_FILTERS = array(
		CampaignChannel::STR_EMAIL,
		CampaignChannel::STR_SMS,
		CampaignChannel::STR_WHATSAPP,
		CampaignChannel::STR_EMAIL_SEQUENCE,
	);

	/**
	 * Campaigns are a marketing-wide view with no per-user owner column, so
	 * access is all-or-nothing at the CRM Manager level — the same gate the
	 * campaign REST controllers use.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'has_crm_manager_access' );

		return array(
			'doublescale/list-campaigns' => array(
				'module_slug'      => 'campaigns',
				'label'            => __( 'List campaigns', 'doublescale' ),
				'description'      => __( 'List email, SMS, and WhatsApp campaigns with status, channel, and recipient count. Read-only: this never sends or schedules anything.', 'doublescale' ),
				'category'         => AbilityCategories::MARKETING,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status'  => array(
							'type'        => 'string',
							'description' => 'Filter by campaign status, e.g. draft, scheduled, sent.',
						),
						'channel' => array(
							'type'        => 'string',
							'description' => 'Filter by delivery channel.',
							'enum'        => self::CHANNEL_FILTERS,
						),
						'search'  => array(
							'type'        => 'string',
							'description' => 'Match on campaign name.',
						),
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'  => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_campaigns' ),
			),

			'doublescale/get-campaign'   => array(
				'module_slug'      => 'campaigns',
				'label'            => __( 'Get campaign', 'doublescale' ),
				'description'      => __( 'One campaign with its channel, status, schedule, and engagement counts (sent, opened, clicked). The message body is omitted unless include_body is true, because campaign bodies are large.', 'doublescale' ),
				'category'         => AbilityCategories::MARKETING,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array(
							'type'        => 'integer',
							'description' => 'Campaign id.',
						),
						// Bodies are long marketing HTML; returning them by
						// default burns an agent's context for no benefit on
						// the usual "how did this campaign do" question.
						'include_body' => array(
							'type'        => 'boolean',
							'description' => 'Include the message body. Off by default to save tokens.',
							'default'     => false,
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_campaign' ),
			),

			'doublescale/get-campaign-summary' => array(
				'module_slug'      => 'campaigns',
				'label'            => __( 'Get campaign summary', 'doublescale' ),
				'description'      => __( 'Campaign counts grouped by status and by channel. For open and click rates use get-engagement-summary, which reads the delivery record rather than the campaign list.', 'doublescale' ),
				'category'         => AbilityCategories::MARKETING,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback' => array( self::class, 'get_campaign_summary' ),
			),
		);
	}

	/**
	 * Campaign counts by status and channel.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_campaign_summary( $input ) {
		unset( $input );

		$by_status = array();
		foreach ( CampaignModel::query()->get() as $campaign ) {
			$status               = (string) $campaign->status;
			$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + 1;
		}

		$by_channel = array();
		foreach ( self::CHANNEL_FILTERS as $channel ) {
			$mode = CampaignChannel::to_integer( $channel );
			if ( null === $mode ) {
				continue;
			}

			$count = (int) CampaignModel::query()->where( 'type', $mode )->count();
			if ( $count > 0 ) {
				$by_channel[ $channel ] = $count;
			}
		}

		return array(
			'total'      => (int) CampaignModel::query()->count(),
			'by_status'  => $by_status,
			'by_channel' => $by_channel,
		);
	}

	/**
	 * List campaigns.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_campaigns( $input ) {
		$input  = (array) $input;
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = CampaignModel::query();

		if ( ! empty( $input['status'] ) ) {
			$query->where( 'status', sanitize_text_field( (string) $input['status'] ) );
		}

		if ( ! empty( $input['channel'] ) ) {
			$channel = CampaignChannel::to_integer( (string) $input['channel'] );
			if ( null !== $channel ) {
				$query->where( 'type', $channel );
			}
		}

		if ( ! empty( $input['search'] ) ) {
			$query->where( 'name', 'LIKE', '%' . sanitize_text_field( (string) $input['search'] ) . '%' );
		}

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape( $row );
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * One campaign with engagement counts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_campaign( $input ) {
		$input    = (array) $input;
		$campaign = CampaignModel::find( (int) ( $input['id'] ?? 0 ) );

		if ( ! $campaign ) {
			return AbilityResult::not_found( __( 'Campaign not found.', 'doublescale' ) );
		}

		$out = self::shape( $campaign );

		// Accessors run aggregate queries, so they are paid for only here and
		// never in the list view.
		$out['stats'] = array(
			'sent'    => (int) $campaign->sent,
			'opened'  => (int) $campaign->opened,
			'clicked' => (int) $campaign->click,
		);

		$out['subject'] = (string) $campaign->subject;

		if ( ! empty( $input['include_body'] ) ) {
			$body                  = AbilityResult::truncate( (string) $campaign->email_body );
			$out['body']           = $body['text'];
			$out['body_truncated'] = $body['truncated'];
		}

		return $out;
	}

	/**
	 * Common campaign fields.
	 *
	 * @since 1.0.0
	 *
	 * @param object $campaign Campaign row.
	 * @return array<string, mixed>
	 */
	private static function shape( $campaign ): array {
		return array(
			'id'          => (int) $campaign->id,
			'name'        => (string) $campaign->name,
			'status'      => (string) $campaign->status,
			'channel'     => CampaignChannel::to_string( (int) $campaign->type ) ?? 'email',
			'recipients'  => (int) $campaign->count,
			'execute_at'  => $campaign->execute_at ? (string) $campaign->execute_at : null,
			'created_at'  => (string) $campaign->created_at,
			'description' => (string) $campaign->description,
		);
	}
}
