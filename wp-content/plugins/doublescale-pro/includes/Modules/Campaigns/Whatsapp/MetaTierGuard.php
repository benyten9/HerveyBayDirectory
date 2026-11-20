<?php
/**
 * Keeps WhatsApp campaigns inside the account's Meta messaging tier.
 *
 * Meta limits how many UNIQUE recipients a number may start business-initiated
 * conversations with in a rolling 24 hours (MetaAccountLimits reads the tier).
 * A recipient already contacted inside the window uses no new quota; messages
 * Meta never accepted count for nothing. Usage is counted across every
 * WhatsApp source — campaigns, automations, one-off sends — because Meta does.
 *
 * Before a campaign sends to a new recipient past the limit (less a safety
 * margin), the campaign pauses and queues its own resume for the moment the
 * oldest counted recipient leaves the window.
 *
 * Lives in Pro since the Meta integration does; free fires the hook it plugs
 * into (`doublescale_whatsapp_campaign_hold_recipient`) and owns pausing.
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Whatsapp
 */

namespace DoubleScale\Pro\Modules\Campaigns\Whatsapp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Campaigns\Services\CampaignContactFilter;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * MetaTierGuard class
 */
final class MetaTierGuard {

	/** Pause reason code recorded on the campaign. */
	const PAUSE_CODE = 'meta_tier_limit';

	/** Pause reason when Meta rates the number RED. Never resumed automatically. */
	const QUALITY_CODE = 'meta_quality_red';

	/** Task hook (under the campaigns group) that resumes a tier-paused campaign. */
	const RESUME_HOOK = 'resume_after_meta_tier';

	/** Share of the tier the plugin allows itself; the rest is headroom. */
	const SAFETY_RATIO = 0.9;

	/** Free's own copy of this guard, shipped on main before it moved here. */
	const FREE_COPY = '\\DoubleScale\\Modules\\Campaigns\\Services\\MetaTierGuard';

	/**
	 * Whether this guard runs. It needs the free release that fires its hook
	 * and owns pausing (WhatsappAccountGuard); a free that still ships its own
	 * copy keeps running that one, so the two never both act.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return WhatsappEngine::free_fires_hold_hook() && null !== WhatsappEngine::account_guard() && ! class_exists( self::FREE_COPY );
	}

	/**
	 * Hook the guard into free's WhatsApp send path and register its resume task.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'doublescale_whatsapp_campaign_hold_recipient', array( self::class, 'hold_recipient' ), 10, 3 );

		// Queued with a single campaign id, so the callback opts in to having
		// Tasks unwrap the queued argument (see Tasks::register_callback()).
		PluginKernel::instance()->campaigns_tasks->register_callback(
			self::RESUME_HOOK,
			array( self::class, 'resume_after_window' ),
			true
		);
	}

	/**
	 * Free's hold hook: stop the campaign before a recipient past the tier,
	 * or on a RED quality rating, after pausing it.
	 *
	 * @param bool          $hold      Whether an earlier guard already holds.
	 * @param string        $recipient Recipient number as tracked.
	 * @param CampaignModel $campaign  Campaign being sent.
	 * @return bool
	 */
	public static function hold_recipient( $hold, $recipient, $campaign ) {
		if ( true === $hold || ! is_object( $campaign ) ) {
			return (bool) $hold;
		}

		$check = self::can_contact( (string) $recipient );
		if ( $check['allowed'] ) {
			return false;
		}

		self::pause_campaign( (int) $campaign->id, $check );

		return true;
	}

	/**
	 * Recipients the plugin may reach in the window, after the safety margin.
	 *
	 * @param int|null $tier_limit Meta's tier; null for unlimited.
	 * @return int|null Null for no limit.
	 */
	public static function effective_limit( $tier_limit ) {
		if ( null === $tier_limit ) {
			return null;
		}

		/**
		 * Filters the share of the Meta tier campaigns may use.
		 *
		 * @param float $ratio Between 0 and 1.
		 */
		$ratio = (float) apply_filters( 'doublescale_meta_tier_safety_ratio', self::SAFETY_RATIO );
		$ratio = min( 1.0, max( 0.1, $ratio ) );

		return max( 1, (int) floor( (int) $tier_limit * $ratio ) );
	}

	/**
	 * Unique recipients Meta accepted in the last 24 hours, and when the first
	 * of them leaves the window.
	 *
	 * @return array{used:int,frees_at:string|null}
	 */
	public static function usage() {
		global $wpdb;

		$table    = ( new CommunicationTrackingModel() )->getTable();
		$accepted = implode( ',', array( TrackingStatus::SENT, TrackingStatus::DELIVERED, TrackingStatus::READ ) );
		$since    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// A recipient counts while ANY accepted message to them is in the
		// window, so each one leaves it 24h after their latest message.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS used, MIN(last_at) AS first_last
				 FROM (
					SELECT recipient, MAX(sent_at) AS last_at
					FROM {$table}
					WHERE mode = %d AND status IN ({$accepted}) AND sent_at >= %s AND recipient <> ''
					GROUP BY recipient
				 ) AS window_recipients",
				CommunicationTrackingModel::MODE_WHATSAPP,
				$since
			),
			ARRAY_A
		);

		$first_last = $row['first_last'] ?? null;

		return array(
			'used'     => (int) ( $row['used'] ?? 0 ),
			'frees_at' => $first_last ? gmdate( 'Y-m-d H:i:s', strtotime( $first_last . ' UTC' ) + DAY_IN_SECONDS ) : null,
		);
	}

	/**
	 * Whether a message to this recipient stays inside the tier.
	 *
	 * @param string $recipient Recipient number as tracked.
	 * @return array{allowed:bool,used:int,limit:int|null,resumes_at:string|null,reason?:string}
	 */
	public static function can_contact( $recipient ) {
		$limits = MetaAccountLimits::get();

		// RED is Meta's last warning before restricting the number: send nothing.
		if ( is_array( $limits ) && 'RED' === ( $limits['quality'] ?? '' ) ) {
			return array( 'allowed' => false, 'used' => 0, 'limit' => null, 'resumes_at' => null, 'reason' => self::QUALITY_CODE );
		}

		$limit = is_array( $limits ) ? self::effective_limit( $limits['tier_limit'] ) : null;

		if ( null === $limit ) {
			return array( 'allowed' => true, 'used' => 0, 'limit' => null, 'resumes_at' => null );
		}

		$usage = self::usage();

		if ( $usage['used'] < $limit || self::in_window( (string) $recipient ) ) {
			return array( 'allowed' => true, 'used' => $usage['used'], 'limit' => $limit, 'resumes_at' => null );
		}

		return array( 'allowed' => false, 'used' => $usage['used'], 'limit' => $limit, 'resumes_at' => $usage['frees_at'] );
	}

	/**
	 * Pause a campaign at the tier and queue its resume.
	 *
	 * @param int   $campaign_id Campaign id.
	 * @param array $check       Result of can_contact().
	 * @return void
	 */
	public static function pause_campaign( $campaign_id, array $check ) {
		if ( self::QUALITY_CODE === ( $check['reason'] ?? '' ) ) {
			// No automatic resume: the admin decides once the rating recovers.
			$guard = WhatsappEngine::account_guard();
			$guard::pause(
				$campaign_id,
				self::QUALITY_CODE,
				__( 'Meta rates this number\'s quality RED.', 'doublescale' )
			);
			return;
		}

		$guard  = WhatsappEngine::account_guard();
		$paused = $guard::pause(
			$campaign_id,
			self::PAUSE_CODE,
			sprintf(
				/* translators: 1: recipients used, 2: limit. */
				__( 'Reached %1$d of %2$d unique recipients allowed in 24 hours.', 'doublescale' ),
				(int) $check['used'],
				(int) $check['limit']
			),
			array(
				'resumes_at' => (string) ( $check['resumes_at'] ?? '' ),
				'used'       => (int) $check['used'],
				'limit'      => (int) $check['limit'],
			)
		);

		// Queue the resume only when this call did the pausing — one resume
		// per pause, per campaign.
		if ( $paused ) {
			$at = $check['resumes_at'] ? strtotime( $check['resumes_at'] . ' UTC' ) : time() + HOUR_IN_SECONDS;
			PluginKernel::instance()->campaigns_tasks->schedule_single( $at + MINUTE_IN_SECONDS, self::RESUME_HOOK, (int) $campaign_id );
		}
	}

	/**
	 * Queued resume: continue a campaign the tier paused.
	 *
	 * Only a tier pause is lifted here — a campaign paused because Meta
	 * restricted the number stays paused for the admin to decide.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return void
	 */
	public static function resume_after_window( $campaign_id ) {
		$guard  = WhatsappEngine::account_guard();
		$reason = $guard::get_pause_reason( (int) $campaign_id );
		if ( self::PAUSE_CODE !== ( $reason['code'] ?? '' ) ) {
			return;
		}

		$guard::resume( (int) $campaign_id );
	}

	/**
	 * The speed the plugin sends at, or null when this free cannot say
	 * (effective_max_per_second() is newer than some free releases).
	 *
	 * @return int|null
	 */
	private static function plugin_max_per_second() {
		$processing = WhatsappEngine::processor();

		return ( $processing && method_exists( $processing, 'effective_max_per_second' ) ) ? (int) $processing->effective_max_per_second() : null;
	}

	/**
	 * Everything the settings card shows, from the same sources the guard uses.
	 *
	 * @param bool $refresh Ask Meta now instead of using the cached answer.
	 * @return array
	 */
	public static function status( $refresh = false ) {
		$limits = MetaAccountLimits::get( (bool) $refresh );

		if ( ! is_array( $limits ) ) {
			return array( 'applies' => false );
		}

		$usage     = self::usage();
		$effective = self::effective_limit( $limits['tier_limit'] );

		return array(
			'applies'               => true,
			// 'meta' = Meta just told us; 'last_known' = Meta unreachable, its
			// last answer; 'default' = never known, smallest account assumed.
			'source'                => $limits['source'],
			'fetched_at'            => $limits['fetched_at'],
			'tier'                  => $limits['tier'],
			// False when Meta answered without a tier: nothing is capped and
			// the card must not present an assumption as Meta's word.
			'tier_known'            => (bool) ( $limits['tier_known'] ?? ( '' !== (string) $limits['tier'] ) ),
			'tier_limit'            => $limits['tier_limit'],
			// The number the limits belong to, so an admin comparing with
			// WhatsApp Manager can see which of their numbers this is.
			'phone_number'          => (string) ( $limits['phone_number'] ?? '' ),
			'effective_limit'       => $effective,
			'safety_ratio'          => null === $effective ? null : (float) apply_filters( 'doublescale_meta_tier_safety_ratio', self::SAFETY_RATIO ),
			'used'                  => $usage['used'],
			'remaining'             => null === $effective ? null : max( 0, $effective - $usage['used'] ),
			'frees_at'              => $usage['frees_at'],
			'throughput_level'      => $limits['throughput_level'],
			'meta_max_per_second'   => $limits['max_per_second'],
			'plugin_max_per_second' => self::plugin_max_per_second(),
			'quality'               => $limits['quality'],
		);
	}

	/**
	 * How much of a campaign's audience can go out now.
	 *
	 * Exact, not estimated: the audience is the one the send path uses, and
	 * each recipient already reached inside the window costs no new quota.
	 *
	 * @param array $filters Campaign recipient filters.
	 * @return array{recipients:int,already_in_window:int,need_new:int,remaining:int|null,can_send_now:int,waiting:int,frees_at:string|null,blocked_by_quality:bool}
	 */
	public static function campaign_quota( array $filters, $owner = null ) {
		global $wpdb;

		$filter  = new CampaignContactFilter();
		$numbers = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $filter->get_filtered_contacts( 'whatsapp', $filters, $owner )->pluck( 'whatsapp_phone' )->all() )
				)
			)
		);

		$status = self::status();
		$total  = count( $numbers );

		$in_window = 0;
		if ( $total ) {
			$table    = ( new CommunicationTrackingModel() )->getTable();
			$accepted = implode( ',', array( TrackingStatus::SENT, TrackingStatus::DELIVERED, TrackingStatus::READ ) );
			$since    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

			foreach ( array_chunk( $numbers, 500 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$in_window   += (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT recipient) FROM {$table}
						 WHERE mode = %d AND status IN ({$accepted}) AND sent_at >= %s AND recipient IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						array_merge( array( CommunicationTrackingModel::MODE_WHATSAPP, $since ), $chunk )
					)
				);
			}
		}

		$need_new  = $total - $in_window;
		$remaining = empty( $status['applies'] ) ? null : $status['remaining'];
		$red       = ! empty( $status['applies'] ) && 'RED' === $status['quality'];

		$can_send_now = $red ? 0 : ( null === $remaining ? $total : $in_window + min( $need_new, $remaining ) );

		return array(
			'recipients'         => $total,
			'already_in_window'  => $in_window,
			'need_new'           => $need_new,
			'remaining'          => $remaining,
			'can_send_now'       => $can_send_now,
			'waiting'            => $total - $can_send_now,
			'frees_at'           => $status['frees_at'] ?? null,
			'blocked_by_quality' => $red,
		);
	}

	/**
	 * Whether this recipient was already reached inside the window.
	 *
	 * @param string $recipient Recipient number.
	 * @return bool
	 */
	private static function in_window( $recipient ) {
		global $wpdb;

		if ( '' === $recipient ) {
			return false;
		}

		$table    = ( new CommunicationTrackingModel() )->getTable();
		$accepted = implode( ',', array( TrackingStatus::SENT, TrackingStatus::DELIVERED, TrackingStatus::READ ) );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table}
				 WHERE mode = %d AND status IN ({$accepted}) AND sent_at >= %s AND recipient = %s
				 LIMIT 1",
				CommunicationTrackingModel::MODE_WHATSAPP,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				$recipient
			)
		);
	}
}
