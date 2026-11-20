<?php
/**
 * Sends queued workflow messages within the shared limits.
 *
 * One Action Scheduler action per channel claims due rows in pages of 100
 * (workflows first, then oldest), sends each through the send step's own
 * deliver() — the exact code an inline send uses — and continues or fails the
 * contact's workflow. At the daily cap it waits for midnight; at the WhatsApp
 * tier it waits for the window; temporary errors retry after 1, 5 and 30
 * minutes. Claims carry a lease, so a crashed run never sends twice.
 *
 * @package DoubleScale\Pro\Modules\SendQueue
 */

namespace DoubleScale\Pro\Modules\SendQueue;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\SchedulerTuning;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\ProcessAutomation;
use DoubleScale\Modules\Automations\Services\ActionResult;
use DoubleScale\Modules\Automations\Services\ActionsManager;
use DoubleScale\Modules\Campaigns\Services\CampaignRateLimiter;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * Dispatcher.
 */
final class Dispatcher {

	const HOOK     = 'doublescale_send_queue_dispatch';
	const WATCHDOG = 'doublescale_send_queue_watchdog';
	const GROUP    = 'doublescale-send-queue';
	const PAGE     = 100;
	const CHANNELS = array( 'email', 'sms', 'whatsapp' );

	/** Row result: stop this run (daily cap reached). */
	const STOP = 'stop';

	/**
	 * Wire the Action Scheduler hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
		add_action( self::WATCHDOG, array( self::class, 'watchdog' ) );
		add_action(
			'init',
			static function () {
				if ( ! function_exists( 'as_next_scheduled_action' ) ) {
					return;
				}
				// Through the shared schedule cache: asking Action Scheduler on
				// every request cost a query per page load.
				$next = class_exists( \DoubleScale\Core\NextRunCache::class )
					? \DoubleScale\Core\NextRunCache::get( self::WATCHDOG, array(), self::GROUP )
					: as_next_scheduled_action( self::WATCHDOG, array(), self::GROUP );
				if ( false === $next ) {
					as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::WATCHDOG, array(), self::GROUP, true );
				}
			},
			20
		);
	}

	/**
	 * Wake the channel's dispatcher unless one is already waiting to run.
	 *
	 * @param string $channel   Channel.
	 * @param int    $timestamp Unix time to run at (0 = as soon as possible).
	 * @return void
	 */
	public static function kick( string $channel, int $timestamp = 0 ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array( $channel ), self::GROUP ) ) {
			return;
		}
		self::schedule( $channel, $timestamp );
	}

	/**
	 * Schedule a dispatcher run without the duplicate check (used by a run
	 * that is about to end, which still counts as scheduled itself).
	 *
	 * @param string $channel   Channel.
	 * @param int    $timestamp Unix time (0 = now).
	 * @return void
	 */
	private static function schedule( string $channel, int $timestamp = 0 ): void {
		if ( $timestamp > time() ) {
			as_schedule_single_action( $timestamp, self::HOOK, array( $channel ), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK, array( $channel ), self::GROUP );
		}
	}

	/**
	 * Safety net every 5 minutes: wake any channel with due rows.
	 *
	 * @return void
	 */
	public static function watchdog(): void {
		foreach ( self::CHANNELS as $channel ) {
			if ( SendQueue::has_due( $channel ) ) {
				self::kick( $channel );
			}
		}
		SendQueue::purge();
	}

	/**
	 * Seconds one run may spend sending.
	 *
	 * @return int
	 */
	public static function budget_seconds(): int {
		$limit = class_exists( SchedulerTuning::class ) ? SchedulerTuning::time_limit( 30 ) : 25;
		return (int) apply_filters( 'doublescale_send_queue_budget_seconds', max( 5, $limit - 5 ) );
	}

	/**
	 * Action Scheduler callback: send due rows of one channel.
	 *
	 * @param string $channel Channel.
	 * @return void
	 */
	public static function run( $channel ): void {
		$channel = (string) $channel;
		if ( ! in_array( $channel, self::CHANNELS, true ) || ! SendQueue::engine_supported() ) {
			return;
		}

		// One sender per channel at a time, so the per-second and daily caps
		// hold exactly. A second run leaves; the lock holder reschedules.
		$budget = self::budget_seconds();
		if ( ! self::acquire_lock( $channel, $budget + MINUTE_IN_SECONDS ) ) {
			return;
		}

		try {
			$deadline = microtime( true ) + $budget;
			$stopped  = false;

			while ( ! $stopped && microtime( true ) < $deadline ) {
				$rows = SendQueue::claim( $channel, self::PAGE );
				if ( ! $rows ) {
					break;
				}

				foreach ( $rows as $index => $row ) {
					if ( microtime( true ) >= $deadline ) {
						// Out of time: hand the rest back for the next run.
						SendQueue::defer( wp_list_pluck( array_slice( $rows, $index ), 'id' ), current_time( 'mysql' ), '' );
						$stopped = true;
						break;
					}

					if ( self::STOP === self::send_row( $row, $channel ) ) {
						self::wait_for_daily_reset( array_slice( $rows, $index ), $channel );
						$stopped = true;
						break;
					}
				}
			}
		} finally {
			self::release_lock( $channel );
		}

		// Keep going while work is due; otherwise wake for the next waiting row.
		if ( SendQueue::has_due( $channel ) && ! CampaignRateLimiter::instance()->is_daily_cap_reached( $channel ) ) {
			self::schedule( $channel );
			return;
		}
		$next = SendQueue::next_available_at( $channel );
		if ( $next ) {
			self::schedule( $channel, ( new \DateTimeImmutable( $next, wp_timezone() ) )->getTimestamp() + 5 );
		}
	}

	/**
	 * Take the channel's send lock; an expired lock (a crashed run) is taken over.
	 *
	 * @param string $channel Channel.
	 * @param int    $seconds Lock lifetime.
	 * @return bool
	 */
	private static function acquire_lock( string $channel, int $seconds ): bool {
		global $wpdb;
		$name  = "doublescale_send_queue_lock_{$channel}";
		$now   = time();
		$until = (string) ( $now + $seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $until ) );
		if ( $wpdb->rows_affected > 0 ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $until, $name, $now ) );
		return $wpdb->rows_affected > 0;
	}

	/**
	 * @param string $channel Channel.
	 * @return void
	 */
	private static function release_lock( string $channel ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", "doublescale_send_queue_lock_{$channel}" ) );
	}

	/**
	 * Send one claimed row.
	 *
	 * @param object $row     Queue row.
	 * @param string $channel Channel.
	 * @return string|null self::STOP when the daily cap is reached.
	 */
	private static function send_row( $row, string $channel ): ?string {
		$tracking           = CommunicationTrackingModel::find( (int) $row->tracking_id );
		$automation         = AutomationModel::find( (int) $row->automation_id );
		$step               = AutomationStepModel::find( (int) $row->step_id );
		$automation_contact = AutomationContactModel::find( (int) $row->automation_contact_id );

		if ( ! $tracking || ! $automation || ! $step || ! $automation_contact || ! $automation_contact->contact ) {
			SendQueue::settle( (int) $row->id, false, 'The workflow, its step or the contact no longer exists.' );
			if ( $tracking ) {
				$tracking->update( array( 'status' => TrackingStatus::FAILED ) );
			}
			return null;
		}

		$action = ActionsManager::instance()->get_action( $step->action );
		if ( ! $action || ! method_exists( $action, 'deliver' ) ) {
			return self::fail( $row, $automation, $step, $tracking, __( 'This step can no longer send messages.', 'doublescale' ) );
		}

		// Unsubscribed (or lost their number/address) while waiting: don't send.
		$blocked = $action->delivery_blocked( $automation_contact->contact, $automation_contact );
		if ( $blocked ) {
			return self::fail( $row, $automation, $step, $tracking, $blocked );
		}

		if ( CampaignRateLimiter::instance()->is_daily_cap_reached( $channel ) ) {
			return self::STOP;
		}

		if ( 'whatsapp' === $channel ) {
			$check = Limits::whatsapp_check( (string) $tracking->recipient );
			if ( ! $check['allowed'] ) {
				$message = 'whatsapp_quality' === $check['reason']
					? __( 'Waiting: Meta rates this WhatsApp number RED, so sending is on hold. Trying again in an hour.', 'doublescale' )
					/* translators: %s: time the WhatsApp window frees up. */
					: sprintf( __( 'Waiting: WhatsApp messaging limit reached. Sends again at %s.', 'doublescale' ), wp_date( 'H:i', strtotime( $check['resumes_at'] ) ) );
				SendQueue::defer( array( (int) $row->id ), (string) $check['resumes_at'], $check['reason'] );
				( new ProcessAutomation( $automation ) )->update_waiting_message( $step->id, $automation_contact->id, $message );
				return null;
			}
		}

		if ( TrackingStatus::PENDING !== (int) $tracking->status ) {
			$tracking->update( array( 'status' => TrackingStatus::PENDING ) );
		}

		try {
			$result = $action->deliver( $automation, $step, $automation_contact, $tracking );
		} catch ( \Throwable $e ) {
			$result = array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		$outcome = ActionResult::interpret( $result );
		if ( 'completed' === $outcome['outcome'] ) {
			SendQueue::settle( (int) $row->id, true );
			( new ProcessAutomation( $automation ) )->finish_waiting_step( $step, $automation_contact->id, true );
			return null;
		}

		$error = '' !== $outcome['message'] ? $outcome['message'] : __( 'The message could not be sent.', 'doublescale' );
		$delay = Limits::is_transient( $error ) ? Limits::retry_delay( (int) $row->attempts + 1 ) : null;
		if ( null !== $delay ) {
			SendQueue::defer( array( (int) $row->id ), wp_date( 'Y-m-d H:i:s', time() + $delay ), $error, true );
			( new ProcessAutomation( $automation ) )->update_waiting_message(
				$step->id,
				$automation_contact->id,
				/* translators: 1: error, 2: retry time. */
				sprintf( __( 'Waiting to retry: %1$s (next try at %2$s).', 'doublescale' ), $error, wp_date( 'H:i', time() + $delay ) )
			);
			return null;
		}

		return self::fail( $row, $automation, $step, $tracking, $error );
	}

	/**
	 * Fail a row and its workflow step with a reason.
	 *
	 * @return null
	 */
	private static function fail( $row, AutomationModel $automation, AutomationStepModel $step, CommunicationTrackingModel $tracking, string $reason ) {
		SendQueue::settle( (int) $row->id, false, $reason );
		if ( TrackingStatus::SENT !== (int) $tracking->status ) {
			$tracking->update( array( 'status' => TrackingStatus::FAILED ) );
		}
		( new ProcessAutomation( $automation ) )->finish_waiting_step( $step, (int) $row->automation_contact_id, false, $reason );
		return null;
	}

	/**
	 * Daily cap reached: the remaining claimed rows wait for midnight.
	 *
	 * @param array  $rows    Rows not yet sent.
	 * @param string $channel Channel.
	 * @return void
	 */
	private static function wait_for_daily_reset( array $rows, string $channel ): void {
		$limiter = CampaignRateLimiter::instance();
		$reset   = $limiter->next_reset_at();
		SendQueue::defer( wp_list_pluck( $rows, 'id' ), $reset->format( 'Y-m-d H:i:s' ), 'daily_limit' );

		$message = sprintf(
			/* translators: 1: channel, 2: daily limit, 3: time sending resumes. */
			__( 'Waiting: daily %1$s limit reached (%2$s). Sends again after %3$s.', 'doublescale' ),
			$channel,
			number_format_i18n( (int) $limiter->get_daily_limit( $channel ) ),
			$reset->format( 'H:i' )
		);
		foreach ( $rows as $row ) {
			$automation = AutomationModel::find( (int) $row->automation_id );
			if ( $automation ) {
				( new ProcessAutomation( $automation ) )->update_waiting_message( (int) $row->step_id, (int) $row->automation_contact_id, $message );
			}
		}
	}
}
