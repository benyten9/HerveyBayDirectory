<?php
/**
 * The workflow send queue: schedule, claim and settle queued messages.
 *
 * Send steps queue their message during bulk runs or while the channel is
 * held back (backlog, daily cap, WhatsApp tier). The Dispatcher sends queued
 * rows in pages within the shared limits and continues each contact's
 * workflow afterwards.
 *
 * @package DoubleScale\Pro\Modules\SendQueue
 */

namespace DoubleScale\Pro\Modules\SendQueue;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Engine\SendContext;
use DoubleScale\Modules\Automations\ProcessAutomation;
use DoubleScale\Modules\Campaigns\Services\CampaignRateLimiter;
use DoubleScale\Pro\Modules\SendQueue\Migrations\SendQueueTable;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- queue rows are claimed and settled on every dispatch; the table name is our own.

/**
 * SendQueue repository.
 */
final class SendQueue {

	const QUEUED  = 'queued';
	const SENDING = 'sending';
	const DONE    = 'done';
	const FAILED  = 'failed';

	/** Workflow messages go before campaigns on shared limits. */
	const PRIORITY_WORKFLOW = 10;

	/** Seconds a dispatcher owns claimed rows before another may take them. */
	const LEASE_SECONDS = 300;

	/** Done/failed rows kept for this many days. */
	const RETENTION_DAYS = 7;

	/** Option set once the table exists. */
	const READY_OPTION = 'doublescale_send_queue_ready';

	/** @var bool */
	private static $ready = false;

	/**
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_send_queue';
	}

	/**
	 * Create the table if the install step has not yet.
	 *
	 * @return void
	 */
	public static function ensure_ready(): void {
		if ( self::$ready ) {
			return;
		}
		self::$ready = true;

		if ( get_option( self::READY_OPTION ) ) {
			return;
		}
		require_once __DIR__ . '/Migrations/SendQueueTable.php';
		( new SendQueueTable() )->run();
		update_option( self::READY_OPTION, 1, false );
	}

	/**
	 * Whether DoubleScale (free) can pause a workflow step on the queue.
	 * Older releases cannot; their send steps keep sending inline.
	 *
	 * @return bool
	 */
	public static function engine_supported(): bool {
		return class_exists( SendContext::class )
			&& method_exists( ProcessAutomation::class, 'finish_waiting_step' )
			&& class_exists( CampaignRateLimiter::class )
			&& method_exists( CampaignRateLimiter::class, 'is_daily_cap_reached' );
	}

	/**
	 * Should this message wait in the queue instead of going out now?
	 *
	 * @param string $channel   email|sms|whatsapp.
	 * @param string $recipient Recipient as tracked.
	 * @return string '' to send now, otherwise why it waits.
	 */
	public static function hold_reason( string $channel, string $recipient = '' ): string {
		if ( ! self::engine_supported() || SendContext::is_test() ) {
			return '';
		}
		if ( SendContext::is_bulk() ) {
			return 'bulk';
		}
		if ( self::has_due( $channel ) ) {
			return 'backlog';
		}
		if ( CampaignRateLimiter::instance()->is_daily_cap_reached( $channel ) ) {
			return 'daily_limit';
		}
		if ( 'whatsapp' === $channel && '' !== $recipient ) {
			$check = Limits::whatsapp_check( $recipient );
			if ( ! $check['allowed'] ) {
				return 'whatsapp_tier';
			}
		}
		return '';
	}

	/**
	 * Queue a prepared message and wake the channel's dispatcher.
	 *
	 * @param string $channel               Channel.
	 * @param int    $tracking_id           Tracking row (status PENDING).
	 * @param int    $automation_id         Workflow.
	 * @param int    $step_id               Send step.
	 * @param int    $automation_contact_id Contact's journey.
	 * @param int    $contact_id            Contact.
	 * @return bool
	 */
	public static function push( string $channel, int $tracking_id, int $automation_id, int $step_id, int $automation_contact_id, int $contact_id ): bool {
		global $wpdb;
		self::ensure_ready();

		$ok = false !== $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (channel, priority, tracking_id, automation_id, step_id, automation_contact_id, contact_id, status, available_at) VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
				$channel,
				self::PRIORITY_WORKFLOW,
				$tracking_id,
				$automation_id,
				$step_id,
				$automation_contact_id,
				$contact_id,
				self::QUEUED,
				current_time( 'mysql' )
			)
		);

		if ( $ok ) {
			Dispatcher::kick( $channel );
		}
		return $ok;
	}

	/**
	 * Any row of the channel ready to send now (or a dispatcher's lease ran out)?
	 *
	 * @param string $channel Channel.
	 * @return bool
	 */
	public static function has_due( string $channel ): bool {
		global $wpdb;
		self::ensure_ready();
		$now = current_time( 'mysql' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . self::table() . ' WHERE channel = %s AND available_at <= %s AND ( status = %s OR ( status = %s AND lease_until < %s ) ) LIMIT 1',
				$channel,
				$now,
				self::QUEUED,
				self::SENDING,
				$now
			)
		);
	}

	/**
	 * When the next waiting row of the channel becomes due (local time), or null.
	 *
	 * @param string $channel Channel.
	 * @return string|null
	 */
	public static function next_available_at( string $channel ): ?string {
		global $wpdb;
		self::ensure_ready();

		$at = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MIN(available_at) FROM ' . self::table() . ' WHERE channel = %s AND status = %s',
				$channel,
				self::QUEUED
			)
		);
		return $at ? (string) $at : null;
	}

	/**
	 * Claim up to $limit due rows for this dispatcher: workflow priority first,
	 * then oldest. Rows whose lease expired (a crashed run) are claimed again.
	 *
	 * @param string $channel Channel.
	 * @param int    $limit   Page size.
	 * @return array<int, object> Claimed rows.
	 */
	public static function claim( string $channel, int $limit = 100 ): array {
		global $wpdb;
		self::ensure_ready();

		$token = wp_generate_uuid4();
		$now   = current_time( 'mysql' );
		$until = wp_date( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, lease_token = %s, lease_until = %s
				WHERE channel = %s AND available_at <= %s AND ( status = %s OR ( status = %s AND lease_until < %s ) )
				ORDER BY priority DESC, id ASC
				LIMIT %d',
				self::SENDING,
				$token,
				$until,
				$channel,
				$now,
				self::QUEUED,
				self::SENDING,
				$now,
				max( 1, $limit )
			)
		);

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE lease_token = %s ORDER BY priority DESC, id ASC', $token )
		);
	}

	/**
	 * Put rows back to wait until $until (local datetime).
	 *
	 * @param int[]  $ids    Row ids.
	 * @param string $until  Local datetime.
	 * @param string $reason Why they wait.
	 * @param bool   $count_attempt Count this as a failed attempt.
	 * @return void
	 */
	public static function defer( array $ids, string $until, string $reason, bool $count_attempt = false ): void {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', $ids );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = %s, available_at = %s, lease_token = NULL, lease_until = NULL, last_error = %s, attempts = attempts + %d WHERE id IN ({$in})",
				self::QUEUED,
				$until,
				$reason,
				$count_attempt ? 1 : 0
			)
		);
	}

	/**
	 * Settle a row as sent or failed.
	 *
	 * @param int    $id     Row id.
	 * @param bool   $ok     Sent.
	 * @param string $reason Failure reason.
	 * @return void
	 */
	public static function settle( int $id, bool $ok, string $reason = '' ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, lease_token = NULL, lease_until = NULL, last_error = %s, attempts = attempts + 1 WHERE id = %d',
				$ok ? self::DONE : self::FAILED,
				$ok ? null : mb_substr( $reason, 0, 1000 ),
				$id
			)
		);
	}

	/**
	 * Counts per channel for the status endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function status(): array {
		global $wpdb;
		self::ensure_ready();
		$now = current_time( 'mysql' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT channel,
					SUM( status IN (%s, %s) AND available_at <= %s ) AS due,
					SUM( status = %s AND available_at > %s ) AS waiting,
					MIN( CASE WHEN status = %s AND available_at > %s THEN available_at END ) AS next_at,
					SUM( status = %s ) AS failed
				FROM ' . self::table() . ' GROUP BY channel',
				self::QUEUED,
				self::SENDING,
				$now,
				self::QUEUED,
				$now,
				self::QUEUED,
				$now,
				self::FAILED
			)
		);

		$limiter = CampaignRateLimiter::instance();
		$out     = array();
		foreach ( array( 'email', 'sms', 'whatsapp' ) as $channel ) {
			$out[ $channel ] = array(
				'due'         => 0,
				'waiting'     => 0,
				'next_at'     => null,
				'failed'      => 0,
				'sent_today'  => $limiter->get_daily_count( $channel ),
				'daily_limit' => method_exists( $limiter, 'get_daily_limit' ) ? $limiter->get_daily_limit( $channel ) : null,
			);
		}
		foreach ( $rows as $row ) {
			if ( isset( $out[ $row->channel ] ) ) {
				$out[ $row->channel ]['due']     = (int) $row->due;
				$out[ $row->channel ]['waiting'] = (int) $row->waiting;
				$out[ $row->channel ]['next_at'] = $row->next_at;
				$out[ $row->channel ]['failed']  = (int) $row->failed;
			}
		}
		return $out;
	}

	/**
	 * Drop settled rows past retention, and rows of deleted contacts.
	 *
	 * @return void
	 */
	public static function purge(): void {
		global $wpdb;
		self::ensure_ready();

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE status IN (%s, %s) AND updated_at < NOW() - INTERVAL %d DAY',
				self::DONE,
				self::FAILED,
				self::RETENTION_DAYS
			)
		);
	}

	/**
	 * Forget a deleted contact's queued messages.
	 *
	 * @param object $contact Contact being deleted.
	 * @return void
	 */
	public static function forget_contact( $contact ): void {
		global $wpdb;
		$contact_id = (int) ( $contact->id ?? 0 );
		if ( $contact_id <= 0 ) {
			return;
		}
		self::ensure_ready();
		$wpdb->delete( self::table(), array( 'contact_id' => $contact_id ), array( '%d' ) );
	}
}
