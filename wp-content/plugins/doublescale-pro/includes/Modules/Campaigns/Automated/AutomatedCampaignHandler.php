<?php
/**
 * Automated Campaign Handler (Pro)
 * Manages both event-based and schedule-based automated campaigns
 *
 * Event architecture:
 *   Post Publish → match campaigns → INSERT into campaign_events table
 *   → schedule one drain worker per campaign → worker processes events FIFO
 *   → each event carries its own post_id (no global state)
 *
 * @since 1.3.0
 * @package DoubleScale\Pro\Modules\Campaigns\Automated
 */

namespace DoubleScale\Pro\Modules\Campaigns\Automated;


defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Campaigns\Campaign\EmailProcessing;
use DoubleScale\Modules\Campaigns\Campaign\WhatsappProcessing;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * AutomatedCampaignHandler class
 */
class AutomatedCampaignHandler {

	/**
	 * Singleton instance
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cron hook name for scheduled campaigns
	 */
	const CRON_HOOK = 'doublescale_automated_campaign_execute';

	/**
	 * Hook name for the event queue drain worker
	 */
	const EVENT_DRAIN_HOOK = 'doublescale_drain_campaign_events';

	/**
	 * Cron hook for recovering stuck campaigns
	 */
	const STUCK_RECOVERY_HOOK = 'doublescale_recover_stuck_campaigns';

	/**
	 * Max minutes a campaign can stay in "processing" before considered stuck
	 */
	const STUCK_THRESHOLD_MINUTES = 15;

	/**
	 * Cron hook for cleaning up old completed/failed events
	 */
	const EVENT_CLEANUP_HOOK = 'doublescale_cleanup_campaign_events';

	/**
	 * Days to keep completed/failed events before deletion
	 */
	const EVENT_RETENTION_DAYS = 7;

	/**
	 * Max minutes an event row can stay in "processing" before considered stuck
	 */
	const EVENT_STUCK_THRESHOLD_MINUTES = 30;

	/**
	 * Get singleton instance
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - register hooks
	 */
	private function __construct() {
		$this->register_event_hooks();
		$this->register_schedule_hooks();
		$this->register_stuck_recovery();
		$this->register_event_drain_handler();
		$this->register_completion_handler();
		$this->register_event_cleanup();
	}

	/**
	 * Register event-based hooks
	 */
	private function register_event_hooks() {
		add_action( 'transition_post_status', array( $this, 'handle_post_status_change' ), 10, 3 );
	}

	/**
	 * Register schedule-based hooks
	 */
	private function register_schedule_hooks() {
		add_action( self::CRON_HOOK, array( $this, 'execute_scheduled_campaign' ), 10, 1 );
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );
	}

	/**
	 * Register stuck job recovery via WP-Cron (runs twice daily)
	 */
	private function register_stuck_recovery() {
		add_action( self::STUCK_RECOVERY_HOOK, array( $this, 'recover_stuck_campaigns' ) );

		if ( ! wp_next_scheduled( self::STUCK_RECOVERY_HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', self::STUCK_RECOVERY_HOOK );
		}
	}

	/**
	 * Recover campaigns stuck in "processing" status.
	 */
	public function recover_stuck_campaigns() {
		global $wpdb;

		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::STUCK_THRESHOLD_MINUTES * MINUTE_IN_SECONDS ) );
		$table     = esc_sql( $wpdb->prefix . 'doublescale_campaigns' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stuck_campaigns = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, type FROM {$table}
				WHERE status = 'processing'
				AND processing_started_at IS NOT NULL
				AND processing_started_at < %s
				AND JSON_EXTRACT(settings, '$.automated') = true",
				$threshold
			)
		);

		if ( empty( $stuck_campaigns ) ) {
			return;
		}

		foreach ( $stuck_campaigns as $row ) {
			$channel_string = CampaignChannel::to_string( (int) $row->type );
			$offset_key     = "doublescale_{$channel_string}_campaigns_last_contact_offset_{$row->id}";
			$offset         = (int) get_option( $offset_key, 0 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'                => 'active',
					'processing_started_at' => null,
				),
				array( 'id' => $row->id )
			);

			$this->maybe_schedule_drain( (int) $row->id );

			doublescale_get_logger()->warning(
				__( 'Recovered stuck automated campaign.', 'doublescale' ),
				array(
					'campaign_id'     => $row->id,
					'threshold_mins'  => self::STUCK_THRESHOLD_MINUTES,
					'offset_at_crash' => $offset,
					'will_resume'     => $offset > 0,
					'context'         => 'stuck_job_recovery',
				)
			);

			/**
			 * Fires when a stuck automated campaign is recovered.
			 *
			 * @since 1.4.0
			 *
			 * @param int $campaign_id     Campaign ID.
			 * @param int $offset_at_crash The offset the processor reached before crash.
			 */
			do_action( 'doublescale_stuck_campaign_recovered', (int) $row->id, $offset );
		}

		$this->recover_stuck_events();
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function add_custom_cron_schedules( $schedules ) {
		$schedules['doublescale_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'doublescale' ),
		);

		return $schedules;
	}

	/**
	 * Register the drain worker for event queue processing.
	 */
	private function register_event_drain_handler() {
		add_action( self::EVENT_DRAIN_HOOK, array( $this, 'drain_campaign_events' ), 10, 1 );
	}

	/**
	 * When an automated campaign finishes processing (all contacts sent),
	 * reset it to "active" and drain any queued events that arrived while
	 * it was busy.
	 */
	private function register_completion_handler() {
		add_action( 'doublescale_campaign_complete', array( $this, 'handle_automated_campaign_completed' ), 10, 3 );
		add_action( 'doublescale_campaign_failure', array( $this, 'handle_automated_campaign_completed' ), 10, 3 );
	}

	/**
	 * Reset an automated campaign to "active" after it completes or fails,
	 * clean up per-event context, and re-trigger the drain worker.
	 *
	 * @param CampaignModel $campaign        Campaign model.
	 * @param mixed          $recipients_or_error Recipients count or error message.
	 * @param string         $channel         Channel string.
	 */
	public function handle_automated_campaign_completed( $campaign, $recipients_or_error = null, $channel = '' ) {
		$settings = $campaign->settings;
		if ( empty( $settings['automated'] ) ) {
			return;
		}

		$channel_string = $channel ?: CampaignChannel::to_string( $campaign->get_type() );

		$campaign->status                = 'active';
		$campaign->processing_started_at = null;
		$campaign->save();

		if ( $channel_string ) {
			$offset_key = "doublescale_{$channel_string}_campaigns_last_contact_offset_{$campaign->id}";
			delete_option( $offset_key );
		}

		MergeTagsManager::instance()->set_current_post_id( null );

		$trigger = $this->get_trigger_config( $campaign );
		if ( $trigger && isset( $trigger['schedule']['frequency'] ) && 'monthly' === $trigger['schedule']['frequency'] ) {
			$this->schedule_campaign_cron( $campaign );
		}

		$this->maybe_schedule_drain( $campaign->id );
	}

	/**
	 * Register daily cleanup cron for old completed/failed events.
	 */
	private function register_event_cleanup() {
		add_action( self::EVENT_CLEANUP_HOOK, array( $this, 'cleanup_old_events' ) );

		if ( ! wp_next_scheduled( self::EVENT_CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::EVENT_CLEANUP_HOOK );
		}
	}

	/**
	 * Delete completed/failed event rows older than retention period.
	 */
	public function cleanup_old_events() {
		global $wpdb;

		$events_table  = esc_sql( $wpdb->prefix . 'doublescale_campaign_events' );
		$retention     = self::EVENT_RETENTION_DAYS;
		$batch_size    = 5000;
		$total_deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"DELETE FROM {$events_table}
					 WHERE status IN ('completed','failed')
					   AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)
					 LIMIT %d",
					$retention,
					$batch_size
				)
			);

			$total_deleted += $deleted;

		} while ( $deleted === $batch_size && $total_deleted < 50000 );
	}

	/**
	 * Recover stuck event rows that have been in "processing" too long.
	 */
	public function recover_stuck_events() {
		global $wpdb;

		$events_table = esc_sql( $wpdb->prefix . 'doublescale_campaign_events' );
		$threshold    = self::EVENT_STUCK_THRESHOLD_MINUTES;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reset_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$events_table}
				 WHERE status = 'processing'
				   AND created_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
				 LIMIT 500",
				$threshold
			)
		);

		if ( empty( $reset_ids ) ) {
			return;
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $reset_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$events_table}
				 SET status = 'pending', processed_at = NULL
				 WHERE id IN ({$id_placeholders})",
				...$reset_ids
			)
		);

		$campaign_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT campaign_id FROM {$events_table}
				 WHERE id IN ({$id_placeholders})",
				...$reset_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		foreach ( $campaign_ids as $cid ) {
			$this->maybe_schedule_drain( (int) $cid );
		}
	}

	// ─── Event Queue (post publish → queue → drain) ────────────────────

	/**
	 * Handle post status changes (first publish only).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function handle_post_status_change( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		$campaigns = $this->get_active_automated_campaigns( 'event', 'post_published' );

		if ( empty( $campaigns ) ) {
			return;
		}

		$taxonomy   = 'product' === $post->post_type ? 'product_cat' : 'category';
		$post_terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $post_terms ) ) {
			$post_terms = array();
		}

		$matched_campaign_ids = array();
		foreach ( $campaigns as $campaign ) {
			if ( $this->campaign_matches_post( $campaign, $post, $post_terms ) ) {
				$matched_campaign_ids[] = (int) $campaign->id;
			}
		}

		if ( empty( $matched_campaign_ids ) ) {
			return;
		}

		global $wpdb;

		// Batch dedup check — single query instead of N get_transient calls.
		$dedup_names = array();
		foreach ( $matched_campaign_ids as $cid ) {
			$dedup_names[] = "_transient_doublescale_event_dedup_{$cid}_{$post->ID}";
		}

		$options_table  = esc_sql( $wpdb->options );
		$placeholders   = implode( ',', array_fill( 0, count( $dedup_names ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$existing_dedup = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT option_name FROM {$options_table} WHERE option_name IN ({$placeholders})",
				...$dedup_names
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$already_seen = array();
		foreach ( $existing_dedup as $name ) {
			if ( preg_match( '/dedup_(\d+)_/', $name, $m ) ) {
				$already_seen[ (int) $m[1] ] = true;
			}
		}

		$new_campaign_ids = array_filter(
			$matched_campaign_ids,
			function ( $cid ) use ( $already_seen ) {
				return ! isset( $already_seen[ $cid ] );
			}
		);

		if ( empty( $new_campaign_ids ) ) {
			return;
		}

		$new_campaign_ids = array_values( $new_campaign_ids );

		// Batch INSERT events — one multi-row statement.
		$events_table = esc_sql( $wpdb->prefix . 'doublescale_campaign_events' );
		$now          = current_time( 'mysql', true );
		$value_rows   = array();
		$value_args   = array();

		foreach ( $new_campaign_ids as $cid ) {
			$value_rows[] = '(%d, %d, %s, %s)';
			$value_args[] = $cid;
			$value_args[] = $post->ID;
			$value_args[] = 'pending';
			$value_args[] = $now;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$events_table} (campaign_id, post_id, status, created_at) VALUES " . implode( ', ', $value_rows ),
				...$value_args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Batch-set dedup transients — one multi-row INSERT IGNORE.
		$expiry         = time() + ( MINUTE_IN_SECONDS * 5 );
		$transient_args = array();

		foreach ( $new_campaign_ids as $cid ) {
			$transient_args[] = "_transient_doublescale_event_dedup_{$cid}_{$post->ID}";
			$transient_args[] = '1';
			$transient_args[] = 'no';

			$transient_args[] = "_transient_timeout_doublescale_event_dedup_{$cid}_{$post->ID}";
			$transient_args[] = $expiry;
			$transient_args[] = 'no';
		}

		$values_clause = implode( ', ', array_fill( 0, count( $new_campaign_ids ) * 2, '(%s, %s, %s)' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$options_table} (option_name, option_value, autoload) VALUES {$values_clause}",
				...$transient_args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Schedule drain — one call per unique campaign.
		foreach ( array_unique( $new_campaign_ids ) as $cid ) {
			$this->maybe_schedule_drain( $cid );
		}
	}

	/**
	 * Schedule a drain worker for a campaign if one isn't already pending.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	private function maybe_schedule_drain( $campaign_id ) {
		$campaign_id = (int) $campaign_id;

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::EVENT_DRAIN_HOOK, array( 'campaign_id' => $campaign_id ), 'doublescale' ) ) {
			return;
		}

		as_enqueue_async_action(
			self::EVENT_DRAIN_HOOK,
			array( 'campaign_id' => $campaign_id ),
			'doublescale'
		);
	}

	/**
	 * Drain pending events for a single campaign (Action Scheduler worker).
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public function drain_campaign_events( $campaign_id ) {
		$campaign_id = $this->normalize_as_campaign_id_arg( $campaign_id );
		if ( $campaign_id < 1 ) {
			return;
		}

		global $wpdb;

		$events_table  = esc_sql( $wpdb->prefix . 'doublescale_campaign_events' );
		$drain_start   = microtime( true );
		$drain_max_time = min( 10, \DoubleScale\Core\Utils\Utils::get_max_execution_time() );

		while ( true ) {
			if ( ( microtime( true ) - $drain_start ) >= $drain_max_time ) {
				break;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$event = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$events_table} WHERE campaign_id = %d AND status = 'pending' ORDER BY created_at ASC LIMIT 1",
					$campaign_id
				)
			);

			if ( ! $event ) {
				break;
			}

			$campaign = CampaignModel::find( $campaign_id );

			if ( ! $campaign || empty( $campaign->settings['automated'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					$events_table,
					array(
						'status'       => 'failed',
						'processed_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $event->id )
				);
				continue;
			}

			if ( 'active' !== $campaign->status ) {
				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$claimed = $wpdb->update(
				$events_table,
				array( 'status' => 'processing' ),
				array(
					'id'     => $event->id,
					'status' => 'pending',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);

			if ( ! $claimed ) {
				continue;
			}

			$event_status = 'completed';
			try {
				$this->execute_campaign_for_event( $campaign, (int) $event->post_id );
			} catch ( \Throwable $e ) {
				$event_status = 'failed';

				doublescale_get_logger()->error(
					__( 'Campaign event execution failed.', 'doublescale' ),
					array(
						'campaign_id' => $campaign_id,
						'event_id'    => $event->id,
						'post_id'     => $event->post_id,
						'error'       => $e->getMessage(),
						'context'     => 'drain_worker',
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$events_table,
				array(
					'status'       => $event_status,
					'processed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $event->id )
			);

			if ( 'failed' === $event_status ) {
				continue;
			}

			$campaign->refresh();
			if ( 'active' !== $campaign->status ) {
				break;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$events_table} WHERE campaign_id = %d AND status = 'pending'",
				$campaign_id
			)
		);

		if ( $remaining > 0 ) {
			$this->maybe_schedule_drain( $campaign_id );
		}
	}

	/**
	 * Check whether a campaign's trigger matches a given post.
	 *
	 * @param CampaignModel $campaign   Campaign model.
	 * @param \WP_Post       $post       Post object.
	 * @param int[]          $post_terms Pre-fetched term IDs for the post.
	 * @return bool
	 */
	private function campaign_matches_post( $campaign, $post, $post_terms ) {
		$trigger = $this->get_trigger_config( $campaign );
		if ( ! $trigger || ! isset( $trigger['event'] ) ) {
			return false;
		}

		$event_config = $trigger['event'];

		if ( ! empty( $event_config['post_type'] ) && $post->post_type !== $event_config['post_type'] ) {
			return false;
		}

		if ( ! empty( $event_config['categories'] ) && is_array( $event_config['categories'] ) ) {
			$matched_ids = array_intersect( array_map( 'intval', $event_config['categories'] ), $post_terms );
			if ( empty( $matched_ids ) ) {
				return false;
			}
		}

		return true;
	}

	// ─── Campaign Execution ────────────────────────────────────────────

	/**
	 * Execute a campaign for a single event (post_id).
	 *
	 * @param CampaignModel $campaign Campaign model.
	 * @param int            $post_id  Post that triggered the event.
	 */
	private function execute_campaign_for_event( $campaign, $post_id ) {
		$context = array( 'post_id' => $post_id );

		$this->execute_campaign( $campaign, $context );
	}

	/**
	 * Execute a scheduled campaign (via WP-Cron)
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public function execute_scheduled_campaign( $campaign_id ) {
		$campaign_id = $this->normalize_as_campaign_id_arg( $campaign_id );
		if ( $campaign_id < 1 ) {
			return;
		}

		$campaign = CampaignModel::find( $campaign_id );

		if ( ! $campaign ) {
			return;
		}

		$settings = $campaign->settings;
		if ( empty( $settings['automated'] ) || 'active' !== $campaign->status ) {
			return;
		}

		$this->execute_campaign( $campaign );
	}

	/**
	 * Execute an automated campaign.
	 *
	 * @param CampaignModel $campaign Campaign model.
	 * @param array          $context  Additional context data.
	 */
	private function execute_campaign( $campaign, $context = array() ) {
		$processing_type = $campaign->get_type();

		$channel_string = CampaignChannel::to_string( $processing_type );
		if ( ! $channel_string ) {
			return;
		}

		global $wpdb;
		$locked = $wpdb->update(
			$wpdb->prefix . 'doublescale_campaigns',
			array(
				'status'                => 'processing',
				'processing_started_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $campaign->id,
				'status' => 'active',
			)
		);

		if ( ! $locked ) {
			return;
		}

		$campaign->refresh();

		/**
		 * Fires before an automated campaign is executed.
		 *
		 * @since 1.3.0
		 *
		 * @param CampaignModel $campaign Campaign model.
		 * @param array          $context  Trigger context data.
		 */
		do_action( 'doublescale_before_automated_campaign_execute', $campaign, $context );

		if ( ! empty( $context['post_id'] ) ) {
			MergeTagsManager::instance()->set_current_post_id( (int) $context['post_id'] );
		}

		$processors = array(
			CampaignChannel::STR_EMAIL    => EmailProcessing::class,
			CampaignChannel::STR_WHATSAPP => WhatsappProcessing::class,
		);
		if ( class_exists( \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::class ) ) {
			$processors[ CampaignChannel::STR_SMS ] = \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::class;
		}

		if ( isset( $processors[ $channel_string ] ) ) {
			$processor = $processors[ $channel_string ]::instance();
			$processor->process_campaign( $campaign );
		}

		/**
		 * Fires after an automated campaign is executed.
		 *
		 * @since 1.3.0
		 *
		 * @param CampaignModel $campaign Campaign model.
		 * @param array          $context  Trigger context data.
		 */
		do_action( 'doublescale_after_automated_campaign_execute', $campaign, $context );
	}

	/**
	 * Get automated campaigns eligible for event queuing.
	 *
	 * @param string $trigger_type 'event' or 'schedule'.
	 * @param string $event_type   Specific event type (for event triggers).
	 * @return array
	 */
	private function get_active_automated_campaigns( $trigger_type, $event_type = '' ) {
		$query = CampaignModel::whereIn( 'status', array( 'active', 'processing' ) )
			->whereRaw( "JSON_EXTRACT(settings, '$.automated') = true" )
			->whereRaw( "JSON_EXTRACT(settings, '$.trigger.trigger_type') = ?", array( $trigger_type ) );

		if ( 'event' === $trigger_type && ! empty( $event_type ) ) {
			$query->whereRaw( "JSON_EXTRACT(settings, '$.trigger.event.event_type') = ?", array( $event_type ) );
		}

		return $query->get()->all();
	}

	/**
	 * Get trigger configuration from campaign
	 *
	 * @param CampaignModel $campaign Campaign model.
	 * @return array|null
	 */
	private function get_trigger_config( $campaign ) {
		$settings = $campaign->settings;
		return isset( $settings['trigger'] ) ? $settings['trigger'] : null;
	}

	/**
	 * Schedule a cron event for a campaign.
	 *
	 * @param CampaignModel $campaign Campaign model.
	 */
	public function schedule_campaign_cron( $campaign ) {
		$trigger = $this->get_trigger_config( $campaign );

		if ( ! $trigger || 'schedule' !== $trigger['trigger_type'] || ! isset( $trigger['schedule'] ) ) {
			return;
		}

		$schedule_config = $trigger['schedule'];
		$hook_args       = array( 'campaign_id' => (int) $campaign->id );

		$this->unschedule_campaign_cron( $campaign->id );

		$first_run  = $this->calculate_first_run( $schedule_config );
		$recurrence = $this->get_cron_recurrence( $schedule_config );

		if ( $recurrence ) {
			$interval = $this->get_recurrence_interval( $recurrence );
			as_schedule_recurring_action( $first_run, $interval, self::CRON_HOOK, $hook_args, 'doublescale' );
		} else {
			as_schedule_single_action( $first_run, self::CRON_HOOK, $hook_args, 'doublescale' );
		}
	}

	/**
	 * Unschedule a campaign's recurring action.
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	public function unschedule_campaign_cron( $campaign_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, array( 'campaign_id' => (int) $campaign_id ), 'doublescale' );
		}
	}

	/**
	 * Convert a WP-Cron recurrence slug to seconds.
	 *
	 * @param string $recurrence WP-Cron recurrence slug.
	 * @return int Interval in seconds.
	 */
	private function get_recurrence_interval( $recurrence ) {
		switch ( $recurrence ) {
			case 'daily':
				return DAY_IN_SECONDS;
			case 'doublescale_weekly':
				return WEEK_IN_SECONDS;
			default:
				return DAY_IN_SECONDS;
		}
	}

	/**
	 * Calculate first run timestamp from schedule config
	 *
	 * @param array $schedule_config Schedule configuration.
	 * @return int Unix timestamp.
	 */
	private function calculate_first_run( $schedule_config ) {
		$time      = isset( $schedule_config['time'] ) ? $schedule_config['time'] : '09:00';
		$frequency = isset( $schedule_config['frequency'] ) ? $schedule_config['frequency'] : 'daily';
		$timezone  = wp_timezone();
		$now       = new \DateTime( 'now', $timezone );

		$run_time = new \DateTime( 'today ' . $time, $timezone );

		switch ( $frequency ) {
			case 'weekly':
				$day_of_week = isset( $schedule_config['day_of_week'] ) ? $schedule_config['day_of_week'] : 'monday';

				if ( strtolower( $now->format( 'l' ) ) === strtolower( $day_of_week ) ) {
					$run_time = new \DateTime( 'today ' . $time, $timezone );
					if ( $run_time <= $now ) {
						$run_time->modify( '+1 week' );
					}
				} else {
					$run_time = new \DateTime( "next {$day_of_week} {$time}", $timezone );
				}
				break;

			case 'monthly':
				$day_of_month = isset( $schedule_config['day_of_month'] ) ? (int) $schedule_config['day_of_month'] : 1;
				$run_time     = $this->build_monthly_datetime( $now, $day_of_month, $time, $timezone );
				if ( $run_time <= $now ) {
					$next_month = ( clone $now )->modify( 'first day of next month' );
					$run_time   = $this->build_monthly_datetime( $next_month, $day_of_month, $time, $timezone );
				}
				break;
			default: // daily
				if ( $run_time <= $now ) {
					$run_time->modify( '+1 day' );
				}
				break;
		}

		return $run_time->getTimestamp();
	}

	/**
	 * Build a DateTime for a specific day-of-month, clamped to the last day
	 * of that month (e.g. Day-31 in February becomes Day-28/29).
	 *
	 * @param \DateTime     $reference     A DateTime in the target month.
	 * @param int           $day_of_month  Desired day (1-31).
	 * @param string        $time          Time string (HH:MM).
	 * @param \DateTimeZone $timezone      Timezone.
	 * @return \DateTime
	 */
	private function build_monthly_datetime( $reference, $day_of_month, $time, $timezone ) {
		$last_day    = (int) $reference->format( 't' );
		$clamped_day = min( $day_of_month, $last_day );
		$date_string = $reference->format( 'Y-m-' ) . sprintf( '%02d', $clamped_day ) . ' ' . $time;

		return new \DateTime( $date_string, $timezone );
	}

	/**
	 * Get WP-Cron recurrence string from schedule config
	 *
	 * @param array $schedule_config Schedule configuration.
	 * @return string|null WP-Cron schedule slug or null for single event.
	 */
	private function get_cron_recurrence( $schedule_config ) {
		$frequency = isset( $schedule_config['frequency'] ) ? $schedule_config['frequency'] : 'daily';

		switch ( $frequency ) {
			case 'daily':
				return 'daily';
			case 'weekly':
				return 'doublescale_weekly';
			case 'monthly':
				return null;
			default:
				return 'daily';
		}
	}

	/**
	 * Activate an automated campaign
	 *
	 * @param CampaignModel $campaign Campaign model.
	 * @return bool
	 */
	public function activate_campaign( $campaign ) {
		$settings = $campaign->settings;
		if ( empty( $settings['automated'] ) ) {
			return false;
		}

		$campaign->status = 'active';
		$campaign->save();

		$trigger = $this->get_trigger_config( $campaign );
		if ( $trigger && 'schedule' === $trigger['trigger_type'] ) {
			$this->schedule_campaign_cron( $campaign );
		}

		return true;
	}

	/**
	 * Deactivate an automated campaign
	 *
	 * @param CampaignModel $campaign Campaign model.
	 * @return bool
	 */
	public function deactivate_campaign( $campaign ) {
		$settings = $campaign->settings;
		if ( empty( $settings['automated'] ) ) {
			return false;
		}

		$campaign->status = 'draft';
		$campaign->save();

		$this->unschedule_campaign_cron( $campaign->id );

		return true;
	}

	/**
	 * Normalise Action Scheduler callback args to a campaign id.
	 *
	 * @param mixed $arg First callback argument from the job runner.
	 * @return int
	 */
	private function normalize_as_campaign_id_arg( $arg ) {
		if ( is_array( $arg ) ) {
			if ( isset( $arg['campaign_id'] ) ) {
				return (int) $arg['campaign_id'];
			}
			$first = reset( $arg );
			return is_numeric( $first ) ? (int) $first : 0;
		}
		return (int) $arg;
	}
}
