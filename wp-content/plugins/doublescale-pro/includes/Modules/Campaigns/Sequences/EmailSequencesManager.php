<?php

/**
 * Class Email Sequences Manager
 * Handles email sequence lifecycle: enrollment, scheduling, sending,
 * exit conditions, and completion tracking with rate limiting.
 *
 * All timestamps are stored and compared using WordPress local time
 * (current_time('mysql') / current_time('timestamp')) to stay consistent
 * with the rest of the Plugin campaign pipeline. The execute_at column,
 * enrollment times, and delay calculations all operate in this timezone.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Campaigns\Sequences;

use Exception;
use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Campaigns\Campaign\EmailProcessing;
use DoubleScale\Modules\Campaigns\Services\CampaignRateLimiter;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Campaigns\Services\CampaignStatusManager;

/**
 * Email Sequences Manager class
 */
final class EmailSequencesManager {

	/**
	 * @var EmailSequencesManager
	 */
	private static $instance;

	/**
	 * @var EmailProcessing
	 */
	private $email_processor;

	/**
	 * @var CampaignRateLimiter
	 */
	private $rate_limiter;

	/**
	 * @var array
	 */
	private $settings;

	/**
	 * @var float
	 */
	private $start_time;

	/**
	 * @var int
	 */
	private $max_execution_time;

	/**
	 * Tracks which parent IDs have already had exit conditions processed
	 * in the current cron run, so we don't repeat the work per child step.
	 *
	 * @var array<int, bool>
	 */
	private $exit_processed_parents = array();

	const DEFAULT_MAX_PER_DAY    = 10000;
	const DEFAULT_MAX_PER_SECOND = 15;
	const INTER_EMAIL_DELAY_US   = 100000;

	const EXIT_UNSUBSCRIBED = 'unsubscribed';
	const EXIT_BOUNCED      = 'bounced';
	const EXIT_COMPLETED    = 'completed';
	const EXIT_MANUAL       = 'manual';
	const EXIT_GOAL_MET     = 'goal_met';

	const MAX_SEND_ATTEMPTS            = 3;
	const MAX_SEND_ATTEMPTS_PERMANENT  = 999;

	const FAILURE_PERMANENT = 'permanent';
	const FAILURE_TRANSIENT = 'transient';

	/**
	 * Settings keys that REST Api clients are allowed to modify.
	 * Anything NOT in this list is stripped from incoming requests.
	 * This is a whitelist -- safer than a blacklist because new
	 * internal keys are protected by default.
	 */
	const EDITABLE_SETTINGS_KEYS = array(
		'subject',
		'from_name',
		'from_email',
		'reply_to',
		'pre_header',
		'preview_text',
		'delay',
		'days',
		'enable_specific_days',
		'sending_time_range',
		'template_ids',
		'templates',
		'ab_test',
	);

	/**
	 * @return EmailSequencesManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->email_processor    = EmailProcessing::instance();
		$this->rate_limiter       = CampaignRateLimiter::instance();
		$this->settings           = Settings::get( 'email', array() );
		$this->max_execution_time = Utils::get_max_execution_time();
		$this->add_hooks();
	}

	private function add_hooks() {
		add_action(
			'init',
			function () {
				PluginKernel::instance()->campaigns_tasks->register_callback(
					'doublescale_email_sequences',
					array( $this, 'process_pending_sequences' )
				);
			}
		);
	}

	// ──────────────────────────────────────────────────────────────
	// Enrollment
	// ──────────────────────────────────────────────────────────────

	/**
	 * Start an email sequence for a contact.
	 *
	 * Uses a database-level atomic check to prevent race conditions
	 * when two concurrent requests enroll the same contact.
	 *
	 * @param int  $sequence_id Parent sequence ID.
	 * @param int  $contact_id  Contact ID.
	 * @param bool $force       Re-enroll even if already active.
	 * @return bool|array True on success, array with status on skip, false on error.
	 */
	public function start_sequence_for_contact( $sequence_id, $contact_id, $force = false ) {
		try {
			$sequence_id = (int) $sequence_id;
			$contact_id  = (int) $contact_id;

			if ( $sequence_id <= 0 || $contact_id <= 0 ) {
				return false;
			}

			$contact = ContactModel::find( $contact_id );
			if ( ! $contact ) {
				return false;
			}

			if ( empty( $contact->email ) || ! filter_var( $contact->email, FILTER_VALIDATE_EMAIL ) ) {
				doublescale_get_logger()->info(
					'Sequence enrollment skipped - invalid email',
					array(
						'sequence_id' => $sequence_id,
						'contact_id'  => $contact_id,
					)
				);
				return array(
					'status'  => 'skipped',
					'message' => 'Contact has no valid email address',
				);
			}

			if ( $contact->email_status !== 'subscribed' ) {
				doublescale_get_logger()->info(
					'Sequence enrollment skipped - not subscribed',
					array(
						'sequence_id' => $sequence_id,
						'contact_id'  => $contact_id,
						'status'      => $contact->email_status,
					)
				);
				return array(
					'status'  => 'skipped',
					'message' => "Contact is not subscribed (status: {$contact->email_status})",
				);
			}

			return $this->do_enroll( $sequence_id, $contact_id, $force );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				'Start sequence for contact error',
				array(
					'sequence_id' => $sequence_id,
					'contact_id'  => $contact_id,
					'error'       => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Atomic enrollment: locks the parent row to prevent concurrent
	 * duplicate enrollments from webhooks/automations firing in parallel.
	 *
	 * @param int  $sequence_id Parent sequence ID.
	 * @param int  $contact_id  Contact ID.
	 * @param bool $force       Allow re-enrollment.
	 * @return bool|array
	 */
	private function do_enroll( $sequence_id, $contact_id, $force ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			$table = ( new CampaignModel() )->getTable();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $sequence_id ) );

			if ( ! $row ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			$parent_sequence = CampaignModel::find( $sequence_id );
			if ( ! $parent_sequence ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			$settings    = $parent_sequence->settings;
			$contact_ids = array_map( 'intval', (array) ( $settings['contact_ids'] ?? array() ) );

			if ( ! $force && in_array( $contact_id, $contact_ids, true ) ) {
				$wpdb->query( 'COMMIT' );
				doublescale_get_logger()->info(
					'Sequence enrollment skipped - already enrolled',
					array(
						'sequence_id' => $sequence_id,
						'contact_id'  => $contact_id,
					)
				);
				return array(
					'status'  => 'skipped',
					'message' => 'Contact is already enrolled in this sequence',
				);
			}

			if ( ! in_array( $contact_id, $contact_ids, true ) ) {
				$contact_ids[] = $contact_id;
			}

			$settings['contact_ids']                         = $contact_ids;
			$settings['contact_enrollments'][ $contact_id ]  = current_time( 'mysql' );
			$settings['contact_exit_reasons'][ $contact_id ] = null;

			$parent_sequence->settings = $settings;
			$parent_sequence->save();

			$wpdb->query( 'COMMIT' );
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		foreach ( $parent_sequence->sequences_mail as $sequence_step ) {
			if ( $sequence_step->status !== CampaignStatusManager::ACTIVE ) {
				$this->reactivate_sequence_step( $sequence_step );
			}
		}

		doublescale_get_logger()->info(
			'Contact enrolled in email sequence',
			array(
				'sequence_id' => $sequence_id,
				'contact_id'  => $contact_id,
			)
		);

		return true;
	}

	/**
	 * Remove a contact from an email sequence.
	 *
	 * @param int    $sequence_id Sequence ID (parent or child).
	 * @param int    $contact_id  Contact ID.
	 * @param string $reason      Exit reason constant.
	 * @return bool
	 */
	public function remove_contact_from_sequence( $sequence_id, $contact_id, $reason = self::EXIT_MANUAL ) {
		try {
			$sequence_id = (int) $sequence_id;
			$contact_id  = (int) $contact_id;

			if ( $sequence_id <= 0 || $contact_id <= 0 ) {
				return false;
			}

			$parent_sequence = $this->resolve_parent_sequence( $sequence_id );
			if ( ! $parent_sequence ) {
				return false;
			}

			$settings    = $parent_sequence->settings;
			$contact_ids = array_map( 'intval', (array) ( $settings['contact_ids'] ?? array() ) );

			$settings['contact_ids'] = array_values(
				array_filter( $contact_ids, fn( $id ) => (int) $id !== $contact_id )
			);

			if ( ! empty( $settings['contact_enrollments'] ) && is_array( $settings['contact_enrollments'] ) ) {
				unset( $settings['contact_enrollments'][ $contact_id ] );
				unset( $settings['contact_enrollments'][ (string) $contact_id ] );
			}

			$settings['contact_exit_reasons'][ $contact_id ] = array(
				'reason' => $reason,
				'at'     => current_time( 'mysql' ),
			);

			$parent_sequence->settings = $settings;
			$parent_sequence->save();

			doublescale_get_logger()->info(
				'Contact removed from email sequence',
				array(
					'sequence_id' => $parent_sequence->id,
					'contact_id'  => $contact_id,
					'reason'      => $reason,
				)
			);

			return true;
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				'Remove contact from sequence error',
				array(
					'sequence_id' => $sequence_id,
					'contact_id'  => $contact_id,
					'error'       => $e->getMessage(),
				)
			);

			return false;
		}
	}

	/**
	 * Check if a contact is currently enrolled in a sequence.
	 *
	 * @param int $sequence_id Parent sequence ID.
	 * @param int $contact_id  Contact ID.
	 * @return bool
	 */
	public function is_contact_enrolled( $sequence_id, $contact_id ) {
		$parent_sequence = CampaignModel::find( (int) $sequence_id );
		if ( ! $parent_sequence ) {
			return false;
		}

		$contact_ids = array_map( 'intval', (array) ( $parent_sequence->settings['contact_ids'] ?? array() ) );

		return in_array( (int) $contact_id, $contact_ids, true );
	}

	// ──────────────────────────────────────────────────────────────
	// Processing (cron entry point)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Process pending email sequences with rate limiting.
	 * Called by cron to check and send sequences that are ready.
	 */
	public function process_pending_sequences() {
		$max_per_day = $this->settings['max_in_day'] ?? self::DEFAULT_MAX_PER_DAY;

		if ( $this->rate_limiter->is_daily_limit_reached( 'email', $max_per_day ) ) {
			$this->rate_limiter->log_daily_limit_reached(
				'email',
				$this->rate_limiter->get_daily_count( 'email' ),
				$max_per_day
			);
			return;
		}

		$this->start_time             = microtime( true );
		$this->exit_processed_parents = array();

		if ( Utils::is_memory_limit_reached() ) {
			return;
		}

		try {
			$ready_sequences = $this->get_ready_sequences();

			foreach ( $ready_sequences as $sequence ) {
				if ( $this->should_stop_processing() ) {
					break;
				}

				$this->process_single_sequence( $sequence );
			}

			if ( ! PluginKernel::instance()->campaigns_tasks->update_heartbeat( 'doublescale_email_sequences' ) ) {
				doublescale_get_logger()->info(
					'Failed to update heartbeat for email sequences',
					array( 'context' => 'email_sequence_processing' )
				);
			}
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				'Email sequence processing error',
				array(
					'code'  => 'email_sequence_processing_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'trace'   => $e->getTraceAsString(),
					),
				)
			);
		} finally {
			$this->exit_processed_parents = array();
		}
	}

	// ──────────────────────────────────────────────────────────────
	// Sequence readiness
	// ──────────────────────────────────────────────────────────────

	/**
	 * @return \Illuminate\Support\Collection
	 */
	private function get_ready_sequences() {
		$sequences = CampaignModel::query()
			->where( 'type', CampaignChannel::CHANNEL_SEQUENCE_MAIL )
			->where( 'status', CampaignStatusManager::ACTIVE )
			->where( 'execute_at', '<=', current_time( 'mysql' ) )
			->get();

		if ( $sequences->isEmpty() ) {
			return collect();
		}

		return $sequences
			->filter( fn( $seq ) => $this->is_sequence_ready_to_send( $seq ) )
			->sortBy( fn( $seq ) => $this->get_delay_in_minutes( $seq->settings ) )
			->values();
	}

	private function is_sequence_ready_to_send( CampaignModel $sequence ) {
		$settings = $sequence->settings;

		if ( ! empty( $settings['enable_specific_days'] ) && ! $this->is_allowed_day( $settings ) ) {
			return false;
		}

		if ( ! empty( $settings['sending_time_range'] ) && ! $this->is_within_time_range( $settings['sending_time_range'] ) ) {
			return false;
		}

		return true;
	}

	private function is_allowed_day( $settings ) {
		if ( empty( $settings['days'] ) ) {
			return true;
		}

		$current_day = strtolower( current_time( 'l' ) );

		return ! empty( $settings['days'][ $current_day ] );
	}

	private function is_within_time_range( $time_range ) {
		if ( empty( $time_range['from'] ) || empty( $time_range['to'] ) ) {
			return true;
		}

		$now  = current_time( 'H:i' );
		$from = $time_range['from'];
		$to   = $time_range['to'];

		if ( $from <= $to ) {
			return $now >= $from && $now <= $to;
		}

		return $now >= $from || $now <= $to;
	}

	// ──────────────────────────────────────────────────────────────
	// Single sequence processing
	// ──────────────────────────────────────────────────────────────

	/**
	 * Process a single child sequence-mail step.
	 *
	 * Exit conditions are evaluated once per parent per cron run
	 * (not per child step) to avoid redundant DB queries. After exit
	 * conditions, the parent is always freshly loaded from DB so
	 * downstream methods never operate on stale state.
	 *
	 * @param CampaignModel $sequence Child sequence-mail step.
	 */
	private function process_single_sequence( CampaignModel $sequence ) {
		try {
			$parent_id = (int) $sequence->parent_id;

			if ( ! isset( $this->exit_processed_parents[ $parent_id ] ) ) {
				$this->process_exit_conditions_for_parent( $parent_id );
				$this->exit_processed_parents[ $parent_id ] = true;
			}

			$parent = CampaignModel::find( $parent_id );
			if ( ! $parent ) {
				return;
			}

			$contacts = $this->get_eligible_contacts( $sequence, $parent );

			if ( $contacts->isEmpty() ) {
				if ( $this->are_all_contacts_successfully_sent( $sequence, $parent ) ) {
					$this->transition_to_completed( $sequence );
					$this->check_sequence_completion( $parent );
				}
				return;
			}

			$this->send_batch( $sequence, $contacts );
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				'Single email sequence processing error',
				array(
					'sequence_id' => $sequence->id,
					'error'       => $e->getMessage(),
					'trace'       => $e->getTraceAsString(),
				)
			);
		}
	}

	/**
	 * Check contacts for exit conditions (unsubscribed, bounced, invalid email)
	 * and remove them from the parent sequence. Operates directly on
	 * the parent ID, loads its own fresh data, and saves atomically.
	 *
	 * @param int $parent_id Parent sequence ID.
	 */
	private function process_exit_conditions_for_parent( $parent_id ) {
		$parent = CampaignModel::find( $parent_id );
		if ( ! $parent ) {
			return;
		}

		$settings    = $parent->settings;
		$contact_ids = array_map( 'intval', (array) ( $settings['contact_ids'] ?? array() ) );

		if ( empty( $contact_ids ) ) {
			return;
		}

		$contacts_to_exit = ContactModel::whereIn( 'id', $contact_ids )
			->where(
				function ( $query ) {
					$query->where( 'email_status', '!=', 'subscribed' )
					->orWhereNull( 'email' )
					->orWhere( 'email', '' );
				}
			)
			->get( array( 'id', 'email_status' ) );

		if ( $contacts_to_exit->isEmpty() ) {
			return;
		}

		foreach ( $contacts_to_exit as $contact ) {
			$reason = ( $contact->email_status === 'bounced' ) ? self::EXIT_BOUNCED : self::EXIT_UNSUBSCRIBED;
			$this->remove_contact_from_sequence( $parent_id, $contact->id, $reason );
		}
	}

	/**
	 * Transition a sequence step to COMPLETED via the correct
	 * state machine path: active -> processing -> completed.
	 *
	 * @param CampaignModel $sequence
	 */
	private function transition_to_completed( CampaignModel $sequence ) {
		if ( $sequence->status === CampaignStatusManager::ACTIVE ) {
			$sequence->status = CampaignStatusManager::PROCESSING;
			$sequence->save();
		}

		if ( $sequence->status === CampaignStatusManager::PROCESSING ) {
			$sequence->status = CampaignStatusManager::COMPLETED;
			$sequence->save();
		}
	}

	/**
	 * Re-activate a sequence step from any reachable state.
	 *
	 * @param CampaignModel $step
	 */
	private function reactivate_sequence_step( CampaignModel $step ) {
		$manager = CampaignStatusManager::instance();

		if ( $manager->is_valid_transition( $step->status, CampaignStatusManager::ACTIVE ) ) {
			$step->status = CampaignStatusManager::ACTIVE;
			$step->save();
		}
	}

	/**
	 * Mark the parent sequence completed when every child step is done.
	 *
	 * @param CampaignModel $parent Parent sequence.
	 */
	private function check_sequence_completion( CampaignModel $parent ) {
		$parent->load( 'sequences_mail' );

		if ( $parent->sequences_mail->isEmpty() ) {
			return;
		}

		$all_completed = $parent->sequences_mail->every(
			fn( $step ) => $step->status === CampaignStatusManager::COMPLETED
		);

		if ( $all_completed ) {
			$this->transition_to_completed( $parent );

			doublescale_get_logger()->info(
				'Email sequence fully completed - all steps done',
				array( 'sequence_id' => $parent->id )
			);
		}
	}

	// ──────────────────────────────────────────────────────────────
	// Contact eligibility
	// ──────────────────────────────────────────────────────────────

	/**
	 * Get contacts eligible to receive a specific sequence step.
	 *
	 * A contact is eligible when:
	 * - They are in the parent's contact_ids list
	 * - They are subscribed with a valid email
	 * - They have NOT already been successfully sent this step
	 * - Their enrollment time + step delay has elapsed
	 * - They have NOT exhausted their retry limit (MAX_SEND_ATTEMPTS)
	 *
	 * The "already sent" subquery only excludes contacts with a SENT
	 * tracking record, not FAILED. This means failed sends are retried
	 * on the next cron run -- up to MAX_SEND_ATTEMPTS times.
	 *
	 * @param CampaignModel $sequence Child step.
	 * @param CampaignModel $parent   Parent sequence.
	 * @return \Illuminate\Support\Collection
	 */
	private function get_eligible_contacts( CampaignModel $sequence, CampaignModel $parent ) {
		$settings    = $parent->settings;
		$contact_ids = array_map( 'intval', (array) ( $settings['contact_ids'] ?? array() ) );

		if ( empty( $contact_ids ) ) {
			return collect();
		}

		$tracking_table = ( new CommunicationTrackingModel() )->getTable();

		$contacts = ContactModel::whereIn( 'id', $contact_ids )
			->where( 'email_status', 'subscribed' )
			->whereNotNull( 'email' )
			->where( 'email', '!=', '' )
			->whereNotIn(
				'id',
				function ( $sub ) use ( $sequence, $tracking_table ) {
					$sub->select( 'contact_id' )
						->from( $tracking_table )
						->where( 'source_id', $sequence->id )
						->where( 'source_type', MessageSourceTypes::CAMPAIGN )
						->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
						->where( 'status', TrackingStatus::SENT );
				}
			)
			->get();

		$enrollments = $settings['contact_enrollments'] ?? array();
		$delay       = $sequence->settings['delay'] ?? array(
			'value' => 0,
			'unit'  => 'minutes',
		);

		return $contacts->filter(
			function ( $contact ) use ( $enrollments, $delay, $sequence ) {
				$enrollment_time = $enrollments[ $contact->id ] ?? $enrollments[ (string) $contact->id ] ?? null;
				if ( ! $enrollment_time ) {
					return false;
				}

				$due_at = $this->calculate_execution_time_from_base( $delay, $enrollment_time );
				if ( strtotime( $due_at ) > current_time( 'timestamp' ) ) {
					return false;
				}

				if ( $this->get_send_attempt_count( $sequence->id, $contact->id ) >= self::MAX_SEND_ATTEMPTS ) {
					return false;
				}

				return true;
			}
		);
	}

	/**
	 * Check if all enrolled contacts are resolved for this step.
	 *
	 * A contact is "resolved" if they either:
	 *   1. Have a SENT tracking record (success), OR
	 *   2. Have exhausted MAX_SEND_ATTEMPTS (permanently failed)
	 *
	 * This ensures the sequence step can complete even when some contacts
	 * are unreachable, while still giving each contact a fair number of
	 * retry attempts before giving up.
	 *
	 * @param CampaignModel $sequence Child step.
	 * @param CampaignModel $parent   Parent sequence.
	 * @return bool
	 */
	private function are_all_contacts_successfully_sent( CampaignModel $sequence, CampaignModel $parent ) {
		$contact_ids = array_map( 'intval', (array) ( $parent->settings['contact_ids'] ?? array() ) );
		$total       = count( $contact_ids );

		if ( $total === 0 ) {
			return false;
		}

		$sent_count = CommunicationTrackingModel::where( 'source_id', $sequence->id )
			->where( 'source_type', MessageSourceTypes::CAMPAIGN )
			->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
			->where( 'status', TrackingStatus::SENT )
			->whereIn( 'contact_id', $contact_ids )
			->count();

		$permanently_failed = 0;
		foreach ( $contact_ids as $cid ) {
			if ( $this->get_send_attempt_count( $sequence->id, $cid ) >= self::MAX_SEND_ATTEMPTS ) {
				$permanently_failed++;
			}
		}

		return ( $sent_count + $permanently_failed ) >= $total;
	}

	// ──────────────────────────────────────────────────────────────
	// Batch sending with rate limiting
	// ──────────────────────────────────────────────────────────────

	/**
	 * @param CampaignModel                 $sequence
	 * @param \Illuminate\Support\Collection $contacts
	 */
	private function send_batch( CampaignModel $sequence, $contacts ) {
		$max_per_second   = $this->settings['max_in_second'] ?? self::DEFAULT_MAX_PER_SECOND;
		$max_per_day      = $this->settings['max_in_day'] ?? self::DEFAULT_MAX_PER_DAY;
		$batch_count      = 0;
		$total_sent       = 0;
		$total_failed     = 0;
		$batch_start_time = microtime( true );

		foreach ( $contacts as $contact ) {
			if ( $this->should_stop_processing() ) {
				break;
			}

			if ( $this->rate_limiter->is_daily_limit_reached( 'email', $max_per_day ) ) {
				doublescale_get_logger()->info(
					'Daily email limit reached during sequence processing',
					array(
						'sequence_id' => $sequence->id,
						'processed'   => $total_sent,
						'daily_count' => $this->rate_limiter->get_daily_count( 'email' ),
						'max_per_day' => $max_per_day,
					)
				);
				break;
			}

			$send_result = $this->send_sequence_email( $sequence, $contact );

			if ( $send_result === true ) {
				$total_sent++;
				$batch_count++;
			} elseif ( $send_result === false ) {
				$total_failed++;
			}

			if ( $batch_count >= $max_per_second ) {
				$elapsed = microtime( true ) - $batch_start_time;
				if ( $elapsed < 1.0 ) {
					usleep( (int) ( ( 1.0 - $elapsed ) * 1000000 ) );
				}
				$batch_count      = 0;
				$batch_start_time = microtime( true );
			} else {
				usleep( self::INTER_EMAIL_DELAY_US );
			}
		}

		doublescale_get_logger()->info(
			'Email sequence batch processing completed',
			array(
				'sequence_id'    => $sequence->id,
				'contacts_total' => $contacts->count(),
				'total_sent'     => $total_sent,
				'total_failed'   => $total_failed,
				'execution_time' => $this->get_current_execution_time(),
			)
		);
	}

	/**
	 * Send a sequence email to a single contact.
	 *
	 * Uses an atomic claim pattern to guarantee idempotency even under
	 * concurrent workers:
	 *   1. Acquire a DB row lock via SELECT ... FOR UPDATE
	 *   2. Inside that lock, check for existing PENDING/SENT records
	 *   3. If none, INSERT the tracking record while still holding the lock
	 *   4. COMMIT the lock, then send the email
	 *
	 * This makes duplicate sends impossible by design -- the DB enforces
	 * the invariant, not application-level check-then-act.
	 *
	 * Retry-limited: permanent failures (invalid email, hard bounce) burn
	 * all retries immediately. Transient failures (SMTP down, timeout)
	 * count normally up to MAX_SEND_ATTEMPTS.
	 *
	 * @param CampaignModel $sequence Child step.
	 * @param ContactModel  $contact
	 * @return bool|null True = sent, false = failed, null = skipped.
	 */
	private function send_sequence_email( CampaignModel $sequence, ContactModel $contact ) {
		try {
			if ( empty( $contact->email ) || ! filter_var( $contact->email, FILTER_VALIDATE_EMAIL ) ) {
				doublescale_get_logger()->info(
					'Sequence email skipped - invalid email at send time',
					array(
						'sequence_id' => $sequence->id,
						'contact_id'  => $contact->id,
					)
				);
				return null;
			}

			$attempt_count = $this->get_send_attempt_count( $sequence->id, $contact->id );
			if ( $attempt_count >= self::MAX_SEND_ATTEMPTS ) {
				doublescale_get_logger()->warning(
					'Sequence email skipped - max retry attempts reached',
					array(
						'sequence_id' => $sequence->id,
						'contact_id'  => $contact->id,
						'attempts'    => $attempt_count,
						'max'         => self::MAX_SEND_ATTEMPTS,
					)
				);
				return null;
			}

			$template_id = $this->email_processor->get_template_for_contact( $sequence, $contact ) ?? null;
			if ( ! $template_id ) {
				doublescale_get_logger()->info(
					'Sequence email skipped - no template found',
					array(
						'sequence_id' => $sequence->id,
						'contact_id'  => $contact->id,
					)
				);
				return null;
			}

			$campaign_message = $this->claim_send_slot( $sequence, $contact, $template_id );
			if ( ! $campaign_message ) {
				return null;
			}

			$this->email_processor->process_campaign_message( $sequence, $contact, $campaign_message );

			$this->rate_limiter->record_send_complete( 'email' );

			$campaign_message->refresh();
			$success = ( (int) $campaign_message->status === TrackingStatus::SENT );

			doublescale_get_logger()->info(
				$success ? 'Email sequence sent successfully' : 'Email sequence send completed with failure status',
				array(
					'sequence_id' => $sequence->id,
					'contact_id'  => $contact->id,
					'message_id'  => $campaign_message->id,
					'status'      => $campaign_message->status,
					'attempt'     => $attempt_count + 1,
					'daily_count' => $this->rate_limiter->get_daily_count( 'email' ),
				)
			);

			return $success;
		} catch ( Exception $e ) {
			$this->rate_limiter->record_send_complete( 'email' );

			doublescale_get_logger()->error(
				'Email sequence send error',
				array(
					'sequence_id' => $sequence->id,
					'contact_id'  => $contact->id,
					'error'       => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Atomically claim a send slot for (step + contact).
	 *
	 * Uses SELECT ... FOR UPDATE on the contact row to serialize
	 * concurrent workers. Inside the lock:
	 *   - If a PENDING or SENT record exists → return null (already claimed)
	 *   - Otherwise → INSERT the tracking record, COMMIT, return it
	 *
	 * The lock scope is the contact row (not a shared table lock),
	 * so different contacts are processed in parallel.
	 *
	 * @param CampaignModel $sequence    Child step.
	 * @param ContactModel  $contact
	 * @param int            $template_id
	 * @return CommunicationTrackingModel|null Claimed record or null.
	 */
	private function claim_send_slot( CampaignModel $sequence, ContactModel $contact, $template_id ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			$contact_table = ( new ContactModel() )->getTable();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$contact_table} WHERE id = %d FOR UPDATE",
				$contact->id
			) );

			$existing = CommunicationTrackingModel::where( 'source_id', $sequence->id )
				->where( 'source_type', MessageSourceTypes::CAMPAIGN )
				->where( 'contact_id', $contact->id )
				->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
				->whereIn( 'status', array( TrackingStatus::PENDING, TrackingStatus::SENT ) )
				->first();

			if ( $existing ) {
				$wpdb->query( 'COMMIT' );
				doublescale_get_logger()->info(
					'Sequence email skipped - send slot already claimed',
					array(
						'sequence_id' => $sequence->id,
						'contact_id'  => $contact->id,
						'tracking_id' => $existing->id,
						'status'      => $existing->status,
					)
				);
				return null;
			}

			$campaign_message = CommunicationTrackingModel::create(
				array(
					'contact_id'  => $contact->id,
					'template_id' => $template_id,
					'mode'        => CommunicationTrackingModel::MODE_EMAIL,
					'source_type' => MessageSourceTypes::CAMPAIGN,
					'source_id'   => $sequence->id,
					'recipient'   => $contact->email,
					'status'      => TrackingStatus::PENDING,
					'hash_key'    => Utils::generate_hash_key(),
				)
			);

			$wpdb->query( 'COMMIT' );

			return $campaign_message;
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Count how many times we have already attempted (and failed) to send
	 * a specific step to a specific contact. Uses FAILED tracking records
	 * as the source of truth -- no separate counter needed.
	 *
	 * @param int $step_id    Child sequence step ID.
	 * @param int $contact_id Contact ID.
	 * @return int
	 */
	private function get_send_attempt_count( $step_id, $contact_id ) {
		return (int) CommunicationTrackingModel::where( 'source_id', $step_id )
			->where( 'source_type', MessageSourceTypes::CAMPAIGN )
			->where( 'contact_id', $contact_id )
			->where( 'mode', CommunicationTrackingModel::MODE_EMAIL )
			->where( 'status', TrackingStatus::FAILED )
			->count();
	}

	/**
	 * Classify a send failure as permanent or transient.
	 *
	 * Permanent failures (invalid address, hard bounce) should not be
	 * retried -- they burn all remaining attempts immediately.
	 * Transient failures (SMTP timeout, rate limit, temporary error)
	 * are retried up to MAX_SEND_ATTEMPTS.
	 *
	 * @param ContactModel $contact
	 * @param string|null   $error_message Exception message if available.
	 * @return string FAILURE_PERMANENT or FAILURE_TRANSIENT.
	 */
	private function classify_failure( ContactModel $contact, $error_message = null ) {
		if ( empty( $contact->email ) || ! filter_var( $contact->email, FILTER_VALIDATE_EMAIL ) ) {
			return self::FAILURE_PERMANENT;
		}

		if ( in_array( $contact->email_status, array( 'bounced', 'complained', 'invalid' ), true ) ) {
			return self::FAILURE_PERMANENT;
		}

		if ( $error_message ) {
			$permanent_patterns = array(
				'mailbox not found',
				'user unknown',
				'address rejected',
				'invalid recipient',
				'does not exist',
				'no such user',
				'bad destination',
				'permanently rejected',
				'550 ',
				'551 ',
				'552 ',
				'553 ',
				'554 ',
			);
			$lower = strtolower( $error_message );
			foreach ( $permanent_patterns as $pattern ) {
				if ( strpos( $lower, $pattern ) !== false ) {
					return self::FAILURE_PERMANENT;
				}
			}
		}

		return self::FAILURE_TRANSIENT;
	}

	// ──────────────────────────────────────────────────────────────
	// Delay / timing helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Convert delay settings to minutes for sorting.
	 *
	 * @param array|string $settings
	 * @return int
	 */
	public function get_delay_in_minutes( $settings ) {
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true );
		}

		$delay = $settings['delay'] ?? array(
			'value' => 0,
			'unit'  => 'minutes',
		);
		$value = intval( $delay['value'] ?? 0 );
		$unit  = strtolower( $delay['unit'] ?? 'minutes' );

		switch ( $unit ) {
			case 'hours':
				return $value * 60;
			case 'days':
				return $value * 1440;
			default:
				return $value;
		}
	}

	/**
	 * Calculate execution time by adding a delay to a base timestamp.
	 *
	 * Uses date() (not gmdate()) because the entire sequence pipeline
	 * stores and compares times in WordPress local timezone via
	 * current_time(). This keeps enrollment_time, due_at, and the
	 * comparison target (current_time('timestamp')) in the same zone.
	 *
	 * @param array    $delay     Delay config with value and unit keys.
	 * @param int|null $base_time Unix timestamp base (defaults to now).
	 * @return string  MySQL datetime string in WP local timezone.
	 */
	public function calculate_execution_time( $delay, $base_time = null ) {
		$value = intval( $delay['value'] ?? 0 );
		$unit  = strtolower( $delay['unit'] ?? 'minutes' );
		$time  = $base_time ?? time();

		$time = strtotime( "+{$value} {$unit}", $time ) ?: $time;

		return date( 'Y-m-d H:i:s', $time );
	}

	/**
	 * @param array  $delay
	 * @param string $base_time MySQL datetime string.
	 * @return string
	 */
	public function calculate_execution_time_from_base( $delay, $base_time ) {
		return $this->calculate_execution_time( $delay, strtotime( $base_time ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Filter incoming settings to only the keys clients are allowed to modify.
	 *
	 * Whitelist approach: only explicitly allowed keys pass through.
	 * Any new internal key added in the future is protected by default
	 * without needing to remember to add it to a blacklist.
	 *
	 * @param array $settings Incoming settings from the client.
	 * @return array Sanitized settings containing only editable keys.
	 */
	public static function sanitize_incoming_settings( array $settings ) {
		return array_intersect_key( $settings, array_flip( self::EDITABLE_SETTINGS_KEYS ) );
	}

	/**
	 * Resolve to the parent sequence whether given a parent or child ID.
	 *
	 * @param int $sequence_id
	 * @return CampaignModel|null
	 */
	private function resolve_parent_sequence( $sequence_id ) {
		$sequence = CampaignModel::find( $sequence_id );
		if ( ! $sequence ) {
			return null;
		}

		if ( (int) $sequence->parent_id > 0 ) {
			return CampaignModel::find( (int) $sequence->parent_id );
		}

		return $sequence;
	}

	// ──────────────────────────────────────────────────────────────
	// Execution guards
	// ──────────────────────────────────────────────────────────────

	private function should_stop_processing() {
		if ( $this->get_current_execution_time() >= $this->max_execution_time ) {
			doublescale_get_logger()->info(
				'Email sequence processing stopped - execution time limit reached',
				array(
					'execution_time' => $this->get_current_execution_time(),
					'max_time'       => $this->max_execution_time,
				)
			);
			return true;
		}

		if ( Utils::is_memory_limit_reached() ) {
			doublescale_get_logger()->info(
				'Email sequence processing stopped - memory limit reached'
			);
			return true;
		}

		return false;
	}

	private function get_current_execution_time() {
		return microtime( true ) - $this->start_time;
	}
}
