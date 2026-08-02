<?php

/**
 * Abstract Campaign Processing
 * Single abstract class for all campaign processing types (Email, Sms, WhatsApp)
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Abstracts;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Campaigns\Models\CampaignModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel;
use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\TrackingStatus;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\PluginKernel;
use DoubleScale\Core\Utils\Utils;
use DoubleScale\Modules\Campaigns\Services\CampaignRateLimiter;
use DoubleScale\Modules\Campaigns\Services\CampaignContactFilter;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Campaigns\Pipeline\CampaignContext;
use DoubleScale\Modules\Campaigns\Pipeline\CampaignProcessingPipeline;
use DoubleScale\Modules\Campaigns\Pipeline\Strategies\IndividualDispatchStrategy;
use DoubleScale\Modules\Campaigns\Pipeline\Steps\InitialiseStep;
use DoubleScale\Modules\Campaigns\Pipeline\Steps\CheckCompletionStep;
use DoubleScale\Modules\Campaigns\Pipeline\Steps\ProcessBatchesStep;
use DoubleScale\Modules\Campaigns\Pipeline\Steps\ScheduleContinuationStep;
use DoubleScale\Modules\Campaigns\Services\CampaignLocker;
use DoubleScale\Modules\Campaigns\Services\CampaignContinuationScheduler;
use DoubleScale\Modules\Campaigns\Services\CampaignResender;

/**
 * AbstractCampaignProcessing class
 */
abstract class AbstractCampaignProcessing {

	/**
	 * Communication channel (email, sms, whatsapp)
	 *
	 * @var string
	 */
	protected $channel;

	/**
	 * Cached merge tag keys for current template
	 *
	 * @var array|null
	 */
	private $template_merge_tag_keys = null;

	/**
	 * Temporarily store rendered conditional section IDs by tracking ID
	 * Used to write conditional_sections meta only after successful send
	 *
	 * @var array Array of tracking_id => rendered_section_ids
	 */
	private $pending_conditional_sections = array();

	/**
	 * Start time
	 *
	 * @var int
	 */
	protected $start_time;

	/**
	 * Max execution time
	 *
	 * @var int
	 */
	protected $max_execution_time;

	/**
	 * Settings
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Rate limiter service
	 *
	 * @var CampaignRateLimiter
	 */
	protected $rate_limiter;

	/**
	 * Contact filter service
	 *
	 * @var CampaignContactFilter
	 */
	protected $contact_filter;

	/**
	 * Message provider instance
	 *
	 * @since 1.0.0
	 *
	 * @var \DoubleScale\Pro\Modules\Inbox\MessageProviderInterface|null
	 */
	protected $message_provider;

	/**
	 * Distributed lock service for campaign processing.
	 *
	 * @var CampaignLocker
	 */
	protected $locker;

	/**
	 * Handles AJAX + Action Scheduler continuations.
	 *
	 * @var CampaignContinuationScheduler
	 */
	protected $continuation_scheduler;

	/**
	 * Lazy-loaded resend service (avoids calling abstract get_message_mode() in constructor).
	 *
	 * @var CampaignResender|null
	 */
	private $resender = null;

	/**
	 * Class Instance storage.
	 *
	 * @var array
	 */
	private static $instances = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->settings               = Settings::get( $this->channel, array() );
		$this->max_execution_time     = Utils::get_max_execution_time();
		$this->rate_limiter           = CampaignRateLimiter::instance();
		$this->contact_filter         = CampaignContactFilter::instance();
		$this->locker                 = new CampaignLocker();
		$this->continuation_scheduler = new CampaignContinuationScheduler(
			$this->channel,
			array( $this, 'continue_campaign_processing' )
		);

		add_action( 'doublescale_ready', array( $this, 'add_hooks' ) );
	}

	/**
	 * Get instance
	 *
	 * @return static
	 */
	public static function instance() {
		$class = get_called_class();
		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}
		return self::$instances[ $class ];
	}

	/**
	 * Register campaign processing hooks
	 * Common for all provider-based campaigns (Sms, WhatsApp, Email)
	 * Should be called from add_hooks() in child classes
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function register_campaign_processing_hooks() {
		// Channel is already a string ('email', 'sms', 'whatsapp')
		$type_string = $this->channel;

		PluginKernel::instance()->campaigns_tasks->register_callback( "doublescale_{$type_string}_campaigns", array( $this, 'process_campaigns' ) );
		PluginKernel::instance()->campaigns_tasks->register_callback( "process_campaign_{$type_string}", array( $this, 'process_campaign_message' ) );

		// Register continuation handler (Action Scheduler fallback)
		PluginKernel::instance()->campaigns_tasks->register_callback(
			"continue_{$type_string}_campaign",
			array( $this, 'continue_campaign_processing' )
		);

		// Register AJAX continuation handler (faster, immediate processing)
		$this->continuation_scheduler->register_ajax_hooks();
	}


	/**
	 * Continue processing a campaign (triggered by Action Scheduler)
	 *
	 * @param int $campaign_id Campaign ID to continue processing
	 * @return void
	 */
	public function continue_campaign_processing( $campaign_id ) {
		// === CONCURRENCY LOCK (Database-based, works without Redis/Memcached) ===
		// IMPORTANT: Uses the SAME lock key as process_campaign() to prevent overlap
		$lock_key      = "doublescale_{$this->channel}_campaign_lock_{$campaign_id}";
		$lock_duration = apply_filters(
			'doublescale_campaign_lock_duration',
			300, // 5 minutes default
			$this->channel,
			$campaign_id
		);

		// Try to acquire lock using transients (database-backed)
		if ( ! $this->locker->acquire( $lock_key, $lock_duration ) ) {
			doublescale_get_logger()->debug(
				/* translators: %s: channel name (email, sms, whatsapp) */
				sprintf( __( 'Campaign %s continuation skipped - already being processed', 'doublescale' ), $this->channel ),
				array(
					'code'        => "{$this->channel}_continuation_locked",
					'campaign_id' => $campaign_id,
				)
			);
			return; // Another process is already handling this campaign
		}

		try {
			$campaign = CampaignModel::find( $campaign_id );

			if ( ! $campaign || $campaign->status !== 'processing' ) {
				// Don't release here - finally block will handle it
				return;
			}

			// Check if progress was made since last continuation
			$progress_key    = "doublescale_{$this->channel}_campaign_last_offset_{$campaign_id}";
			$no_progress_key = "doublescale_{$this->channel}_campaign_no_progress_count_{$campaign_id}";
			$last_offset     = get_transient( $progress_key );
			$current_offset  = get_option( "doublescale_{$this->channel}_campaigns_last_contact_offset_{$campaign_id}", 0 );

			// Check if progress was made
			if ( $last_offset !== false && $last_offset == $current_offset ) {
				// No progress made - increment counter
				$no_progress_count = (int) get_transient( $no_progress_key );
				++$no_progress_count;

				// Maximum consecutive "no progress" attempts (configurable, default 5)
				$max_no_progress = apply_filters(
					'doublescale_campaign_max_no_progress_attempts',
					5,
					$this->channel,
					$campaign_id
				);

				if ( $no_progress_count >= $max_no_progress ) {
					// Too many consecutive attempts with no progress - mark as failed
					$error_message = sprintf(
						/* translators: %d: number of consecutive attempts */
						__( 'Campaign failed: no progress after %d consecutive attempts', 'doublescale' ),
						$no_progress_count
					);
					doublescale_get_logger()->error(
						/* translators: %1$s: channel name, %2$d: number of consecutive attempts */
						sprintf( __( 'Campaign %1$s failed: no progress after %2$d consecutive attempts', 'doublescale' ), $this->channel, $no_progress_count ),
						array(
							'code'        => "{$this->channel}_no_progress_limit_exceeded",
							'campaign_id' => $campaign_id,
							'offset'      => $current_offset,
							'attempts'    => $no_progress_count,
						)
					);
					// Calculate duration even for failed campaigns
					$campaign_duration = $this->calculate_campaign_duration( $campaign );

					$campaign->status = 'failed';
					$campaign->save();

					// Clean up the campaign start time
					delete_option( "doublescale_{$this->channel}_campaign_start_time_{$campaign->id}" );

					/**
					 * Fires when a campaign fails.
					 *
					 * @since 1.0.0
					 *
					 * @param CampaignModel $campaign      The failed campaign.
					 * @param string         $error_message Error description.
					 * @param string         $channel       Campaign channel (email, sms, whatsapp).
					 */
					do_action( 'doublescale_campaign_failure', $campaign, $error_message, $this->channel );
					return;
				}

				// Log warning for no progress
				set_transient( $no_progress_key, $no_progress_count, HOUR_IN_SECONDS );
				doublescale_get_logger()->info(
					/* translators: %1$s: channel name, %2$d: current attempt number, %3$d: maximum attempts */
					sprintf( __( 'Campaign %1$s continuation made no progress (attempt %2$d/%3$d)', 'doublescale' ), $this->channel, $no_progress_count, $max_no_progress ),
					array(
						'code'        => "{$this->channel}_no_progress",
						'campaign_id' => $campaign_id,
						'offset'      => $current_offset,
						'attempt'     => $no_progress_count,
					)
				);
			} else {
				// Progress was made - reset no progress counter
				delete_transient( $no_progress_key );
			}

			// Update progress tracker
			set_transient( $progress_key, $current_offset, HOUR_IN_SECONDS );

			// Raise memory limit
			wp_raise_memory_limit( 'admin' );

			// Reset timing
			$this->start_time = microtime( true );

			// Restore execution context (post_id etc.) that was persisted by
			// the original process — this process has a fresh singleton.
			MergeTagsManager::instance()->restore_campaign_context( $campaign_id );

			// IMPORTANT: Call do_process_campaign() directly, NOT process_campaign()
			// process_campaign() would try to acquire the same lock again and fail!
			// We already hold the lock, so skip straight to the processing logic.
			$this->do_process_campaign( $campaign );
		} finally {
			$this->locker->release( $lock_key );
		}
	}


	/**
	 * Send message via provider (polymorphic for Sms and WhatsApp)
	 *
	 * @since 1.0.0
	 *
	 * @param array                      $message_data Prepared message data
	 * @param ContactModel               $contact Contact model
	 * @param CommunicationTrackingModel $campaign_message Campaign tracking record
	 * @return array Result array with 'success' boolean and optional data
	 */
	protected function send_via_provider( $message_data, ContactModel $contact, CommunicationTrackingModel $campaign_message ) {
		try {
			// Get message provider
			$provider = $this->get_message_provider();
			if ( ! $provider ) {
				throw new \Exception( esc_html( sprintf( 'No message provider available for %s', $this->channel ) ) );
			}

			// Validate provider is configured before attempting to send
			if ( ! $provider->is_configured() ) {
				throw new \Exception(
					esc_html(
						sprintf(
							'%s provider (%s) is not configured. Please configure it in Settings > Integrations before sending messages.',
							ucfirst( $this->channel ),
							$provider->get_provider_name()
						)
					)
				);
			}

			// Prepare message data for provider Api
			$api_data = array(
				'Body' => $message_data['body'],
				'To'   => $campaign_message->recipient,
			);

			// Add StatusCallback if provider supports webhooks
			$webhook_url = $provider->get_webhook_url( $this->channel );
			if ( $webhook_url ) {
				$api_data = $this->prepare_status_callback( $webhook_url, $api_data );
			}

			// Send via provider (unified method)
			$result = $provider->send_message( $this->channel, $api_data, $contact );

			// Handle response
			return $this->handle_provider_response( $result, $campaign_message, $contact );
		} catch ( \Exception $e ) {
			return $this->handle_provider_error( $e );
		}
	}

	/**
	 * Call external Api - must be implemented by Twilio-based child classes
	 *
	 * @param array $api_data Api data to send
	 * @return array Result from Api
	 */
	protected function call_external_api( $api_data ) {
		// This method is only used by Twilio-based Sms/Whatsapp processing
		return array(
			'success' => false,
			'error'   => 'call_external_api not implemented',
		);
	}

	/**
	 * Handle provider Api response (common logic)
	 *
	 * @since 1.0.0
	 *
	 * @param array                      $result Api result
	 * @param CommunicationTrackingModel $campaign_message Campaign message record
	 * @param ContactModel               $contact Contact model
	 * @return array Processed result
	 */
	protected function handle_provider_response( $result, CommunicationTrackingModel $campaign_message, ContactModel $contact ) {
		$is_success = is_array( $result ) && ! empty( $result['success'] );

		if ( $is_success ) {
			// Store provider's message ID in tracking record for webhook processing
			// Note: Do NOT save here — handle_send_result() will save with status + sent_at together
			if ( ! empty( $result['message_id'] ) ) {
				$campaign_message->external_id = $result['message_id'];
			} else {
				doublescale_get_logger()->info(
					ucfirst( $this->channel ) . ' provider returned success but no message ID',
					array(
						'tracking_id' => $campaign_message->id,
						'contact_id'  => $contact->id,
						'code'        => "{$this->channel}_message_id_missing",
					)
				);
			}
		}

		return $result ?? array( 'success' => false );
	}

	/**
	 * Handle provider Api error (common logic)
	 *
	 * @since 1.0.0
	 *
	 * @param \Exception $e Exception that occurred
	 * @return array Error result
	 */
	protected function handle_provider_error( \Exception $e ) {
		doublescale_get_logger()->error(
			/* translators: %s: channel name (email, sms, whatsapp) */
			sprintf( __( '%s send error.', 'doublescale' ), ucfirst( $this->channel ) ),
			array(
				'code'  => "{$this->channel}_send_error",
				'error' => $e->getMessage(),
			)
		);
		return array(
			'success' => false,
			'error'   => $e->getMessage(),
		);
	}

	/**
	 * Prepare StatusCallback URL for Twilio requests (common logic)
	 *
	 * IMPORTANT: Twilio validates StatusCallback URLs and rejects the entire
	 * request if the URL is not publicly reachable (e.g., localhost, 127.0.0.1).
	 * This method automatically skips StatusCallback for non-routable URLs.
	 *
	 * For local development webhook testing, use a tunneling service like:
	 * - ngrok (https://ngrok.com)
	 * - Expose (https://expose.dev)
	 * - LocalTunnel (https://localtunnel.github.io)
	 *
	 * To disable webhooks entirely (e.g., for testing), use this filter:
	 * add_filter( 'doublescale_provider_webhooks_enable', '__return_false' );
	 *
	 * @since 1.0.0
	 * @param string $webhook_url The webhook URL to use
	 * @param array  $data The message data array to modify
	 * @return array Modified data array with StatusCallback added
	 */
	protected function prepare_status_callback( $webhook_url, $data = array() ) {
		// Allow filtering webhook behavior
		$webhooks_enabled = apply_filters( 'doublescale_provider_webhooks_enable', true );

		if ( ! empty( $webhook_url ) && $webhooks_enabled && self::is_publicly_reachable_url( $webhook_url ) ) {
			$data['StatusCallback'] = $webhook_url;
		}

		return $data;
	}

	/**
	 * Check if a URL is publicly reachable (not localhost/private)
	 *
	 * Twilio rejects StatusCallback URLs that point to non-routable addresses,
	 * which causes the entire Sms/Whatsapp request to fail.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check
	 * @return bool True if URL appears publicly reachable
	 */
	public static function is_publicly_reachable_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$host = strtolower( $host );

		// Check for localhost variants
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return false;
		}

		// Check for .local / .localhost TLDs
		if ( preg_match( '/\.(local|localhost|test|invalid|example)$/', $host ) ) {
			return false;
		}

		// Check for private IP ranges
		$ip = filter_var( $host, FILTER_VALIDATE_IP );
		if ( $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add hooks - must be implemented by child classes
	 *
	 * @return void
	 */
	abstract public function add_hooks();

	/**
	 * Get campaign message mode - must be implemented by child classes.
	 *
	 * Public so {@see CampaignResender} and other services may receive array callables; protected methods
	 * are not valid for PHP's `callable` type hint with `array( $this, 'method' )`.
	 *
	 * @return int CommunicationTrackingModel mode constant
	 */
	abstract public function get_message_mode();

	/**
	 * Get recipient field from contact - must be implemented by child classes
	 *
	 * @param ContactModel $contact
	 * @return string|null Recipient (email or phone)
	 */
	abstract protected function get_recipient( ContactModel $contact );

	/**
	 * Send message - must be implemented by child classes
	 *
	 * @param array                      $message_data Prepared message data
	 * @param ContactModel               $contact Contact model
	 * @param CommunicationTrackingModel $campaign_message Campaign tracking record
	 * @return array Result array with 'success' boolean and optional data
	 */
	abstract protected function send_message( $message_data, ContactModel $contact, CommunicationTrackingModel $campaign_message );

	/**
	 * Get tracking class - must be implemented by child classes
	 *
	 * @return string Tracking class name
	 */
	abstract protected function get_tracking_class();

	/**
	 * Get source type for messages - can be overridden by child classes
	 *
	 * @return int Source type constant
	 */
	protected function get_source_type() {
		return MessageSourceTypes::CAMPAIGN;
	}

	/**
	 * Get source ID for messages - can be overridden by child classes
	 *
	 * @param CampaignModel $campaign
	 * @return int Source ID
	 */
	protected function get_source_id( CampaignModel $campaign ) {
		return $campaign->id;
	}

	/**
	 * Process campaigns - unified logic for all types
	 *
	 * @return void
	 */
	public function process_campaigns() {
		$this->start_time = microtime( true );

		// Update heartbeat at the start to track that cron is running
		if ( ! PluginKernel::instance()->campaigns_tasks->update_heartbeat( "doublescale_{$this->channel}_campaigns" ) ) {
			doublescale_get_logger()->info(
				/* translators: %s: channel name */
				sprintf( __( 'Failed to update heartbeat for %s campaigns', 'doublescale' ), $this->channel ),
				array(
					'channel' => $this->channel,
					'context' => 'campaign_processing_start',
				)
			);
		}

		// Check if memory limit is reached
		if ( Utils::is_memory_limit_reached() ) {
			return;
		}

		try {
			// Handle resending first if applicable
			if ( $this->get_resender()->handle_resending() ) {
				return;
			}

			// Get first campaign for processing
			$campaign = $this->get_next_campaign();
			if ( ! $campaign ) {
				return;
			}

			if ( 'schedule' === $campaign->status ) {
				$campaign->status = 'processing';
				$campaign->save();
			}

			$this->process_campaign( $campaign );
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				/* translators: %s: channel name */
				sprintf( __( '%s Campaign processing error.', 'doublescale' ), ucfirst( $this->channel ) ),
				array(
					'code'  => "{$this->channel}_campaign_error",
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'data'    => $e->getTrace(),
					),
				)
			);
		}
	}

	/**
	 * Get next campaign for processing
	 *
	 * @return CampaignModel|null
	 */
	protected function get_next_campaign() {
		return CampaignModel::where(
			function ( $query ) {
				$query->where( 'status', 'processing' )
					->orWhere(
						function ( $subQuery ) {
							$subQuery->where( 'status', 'schedule' )
								->where( 'execute_at', '<=', gmdate( 'Y-m-d H:i:s' ) );
						}
					);
			}
		)
			// Convert string to integer for database query (DB boundary)
			->where( 'type', CampaignChannel::to_integer( $this->channel ) )
			->orderBy( 'updated_at', 'asc' )
			->first();
	}

	/**
	 * Process individual campaign
	 *
	 * @param CampaignModel $campaign
	 * @return void
	 */
	public function process_campaign( CampaignModel $campaign ) {
		// Validate campaign type
		if ( $campaign->type !== $this->channel ) {
			doublescale_get_logger()->error(
				__( 'Campaign type mismatch detected.', 'doublescale' ),
				array(
					'code'          => 'campaign_type_mismatch',
					'campaign_id'   => $campaign->id,
					'expected_type' => $this->channel,
					'actual_type'   => $campaign->type,
					'processor'     => get_class( $this ),
				)
			);
			return;
		}

		// === CONCURRENCY LOCK (Database-based, works without Redis/Memcached) ===
		// Prevent multiple cron workers from processing the same campaign simultaneously
		$lock_key      = "doublescale_{$this->channel}_campaign_lock_{$campaign->id}";
		$lock_duration = apply_filters(
			'doublescale_campaign_lock_duration',
			300, // 5 minutes default
			$this->channel,
			$campaign->id
		);

		// Try to acquire lock using transients (database-backed)
		if ( ! $this->locker->acquire( $lock_key, $lock_duration ) ) {
			doublescale_get_logger()->info(
				/* translators: %s: channel name */
				sprintf( __( 'Campaign %s already being processed by another worker, skipping', 'doublescale' ), $this->channel ),
				array(
					'code'        => "{$this->channel}_campaign_locked",
					'campaign_id' => $campaign->id,
				)
			);
			return; // Another cron/worker is already processing this campaign
		}

		// Wrap everything in try/finally to ensure lock is always released
		try {
			$this->do_process_campaign( $campaign );
		} finally {
			// Always release the lock when done (success or failure)
			$this->locker->release( $lock_key );
		}
	}

	/**
	 * Reset per-campaign cached state before starting a new processing run.
	 *
	 * Must be called at the top of do_process_campaign() (and any override)
	 * so that consecutive campaigns in the same PHP process don't bleed
	 * merge-tag keys, conditional sections, or timing data.
	 *
	 * @return void
	 */
	protected function reset_per_campaign_state() {
		wp_raise_memory_limit( 'admin' );
		$this->template_merge_tag_keys      = null;
		$this->pending_conditional_sections = array();
		$this->start_time                   = microtime( true );
	}

	/**
	 * Build a populated CampaignContext from the current processor state.
	 *
	 * @param CampaignModel $campaign
	 * @return CampaignContext
	 */
	protected function build_campaign_context( CampaignModel $campaign ) {
		$lock_key      = "doublescale_{$this->channel}_campaign_lock_{$campaign->id}";
		$lock_duration = apply_filters(
			'doublescale_campaign_lock_duration',
			300,
			$this->channel,
			$campaign->id
		);
		$offset_key    = "doublescale_{$this->channel}_campaigns_last_contact_offset_{$campaign->id}";

		$ctx = new CampaignContext();

		$ctx->campaign           = $campaign;
		$ctx->channel            = $this->channel;
		$ctx->filters            = $campaign->get_setting( 'filters', array() );
		$ctx->settings           = $this->settings;
		$ctx->lock_key           = $lock_key;
		$ctx->lock_duration      = $lock_duration;
		$ctx->offset_key         = $offset_key;
		$ctx->max_execution_time = $this->max_execution_time;
		$ctx->contact_filter     = $this->contact_filter;
		$ctx->rate_limiter       = $this->rate_limiter;
		$ctx->fn_complete        = \Closure::fromCallable( array( $this, 'complete_campaign' ) );
		$ctx->fn_continue        = array( $this->continuation_scheduler, 'queue' );
		$ctx->fn_refresh_lock    = array( $this->locker, 'refresh' );
		$ctx->fn_execution_time  = array( $this, 'get_current_execution_time' );
		$ctx->fn_add_message     = \Closure::fromCallable( array( $this, 'add_message' ) );

		return $ctx;
	}

	/**
	 * Internal campaign processing logic (called by process_campaign after lock acquired).
	 *
	 * Runs the four-step Pipeline with the IndividualDispatchStrategy (one
	 * message per contact).  EmailProcessing overrides this method to inject
	 * the BulkEmail or CurlMulti strategy when the mailer supports it.
	 *
	 * @param CampaignModel $campaign
	 * @return void
	 */
	protected function do_process_campaign( CampaignModel $campaign ) {
		$this->reset_per_campaign_state();

		$ctx      = $this->build_campaign_context( $campaign );
		$strategy = new IndividualDispatchStrategy();

		$pipeline = new CampaignProcessingPipeline(
			array(
				new InitialiseStep(),
				new CheckCompletionStep(),
				new ProcessBatchesStep( $strategy ),
				new ScheduleContinuationStep(),
			)
		);

		$pipeline->run( $ctx );
	}

	/**
	 * Lazy-load and return the CampaignResender service.
	 *
	 * Deferred so that the abstract get_message_mode() is never invoked before
	 * a concrete subclass is fully constructed.
	 *
	 * @return CampaignResender
	 */
	protected function get_resender() {
		if ( null === $this->resender ) {
			$this->resender = new CampaignResender(
				$this->channel,
				array( $this, 'get_message_mode' ),
				array( $this, 'get_current_execution_time' ),
				$this->max_execution_time,
				$this->settings,
				$this->rate_limiter
			);
		}
		return $this->resender;
	}

	/**
	 * Handle AJAX continuation request — delegates to the continuation scheduler.
	 *
	 * Kept as a public method so any existing callers continue to work.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_ajax_continuation() {
		$this->continuation_scheduler->handle_ajax_continuation();
	}

	/**
	 * Add campaign message - unified logic for all types
	 *
	 * @param CampaignModel $campaign
	 * @param ContactModel  $contact
	 * @return array
	 */
	protected function add_message( CampaignModel $campaign, ContactModel $contact ) {
		try {
			$recipient = $this->get_recipient( $contact );

			if ( empty( $recipient ) ) {
				$skip_reason = $this->channel === CampaignChannel::STR_EMAIL
					? 'no email'
					: ( empty( $contact->phone ) ? 'no phone number' : 'invalid phone format' );

				$this->contact_filter->log_skipped_contact(
					$contact->id,
					$campaign->id,
					$this->channel,
					$skip_reason
				);

				$template_id = $this->get_template_for_contact( $campaign, $contact );

				$failed_record = CommunicationTrackingModel::create(
					array(
						'contact_id'  => $contact->id,
						'template_id' => $template_id ?: 0,
						'mode'        => $this->get_message_mode(),
						'direction'   => MessageDirection::OUTBOUND,
						'source_type' => $this->get_source_type(),
						'source_id'   => $this->get_source_id( $campaign ),
						'recipient'   => $this->channel === CampaignChannel::STR_EMAIL
						? ( $contact->email ?: '' )
						: ( $contact->phone ?: '' ),
						'status'      => TrackingStatus::FAILED,
						'hash_key'    => Utils::generate_hash_key(),
					)
				);

				if ( $failed_record && $failed_record->id ) {
					CommunicationTrackingMetaModel::store_error_info(
						$failed_record->id,
						'recipient_invalid',
						sprintf(
							/* translators: %s: skip reason */
							__( 'Contact skipped: %s', 'doublescale' ),
							$skip_reason
						)
					);
				}

				return array(
					'success' => true,
					'skipped' => true,
					'fatal'   => false,
				);
			}

			$template_id = $this->get_template_for_contact( $campaign, $contact );

			if ( ! $template_id ) {
				doublescale_get_logger()->error(
					/* translators: %s: channel name */
					sprintf( __( 'No template found for %s campaign.', 'doublescale' ), $this->channel ),
					array(
						'code'        => "{$this->channel}_no_template",
						'campaign_id' => $campaign->id,
						'contact_id'  => $contact->id,
					)
				);

				global $wpdb;
				$wpdb->update(
					$wpdb->prefix . 'doublescale_campaigns',
					array( 'status' => 'failed' ),
					array( 'id' => $campaign->id ),
					array( '%s' ),
					array( '%d' )
				);
				$campaign->status = 'failed';

				return array(
					'success' => false,
					'skipped' => false,
					'fatal'   => true,
				);
			}

			$campaign_message = CommunicationTrackingModel::create(
				array(
					'contact_id'  => $contact->id,
					'template_id' => $template_id,
					'mode'        => $this->get_message_mode(),
					'direction'   => MessageDirection::OUTBOUND,
					'source_type' => $this->get_source_type(),
					'source_id'   => $this->get_source_id( $campaign ),
					'recipient'   => $recipient,
					'status'      => TrackingStatus::PENDING,
					'hash_key'    => Utils::generate_hash_key(),
				)
			);

			// Don't update offset here - caller handles it after batch

			$channel_string = $this->channel;
			PluginKernel::instance()->campaigns_tasks->enqueue_sync(
				"process_campaign_{$channel_string}",
				$campaign,
				$contact,
				$campaign_message
			);

			doublescale_get_logger()->info(
				/* translators: %s: channel name */
				sprintf( __( 'Campaign %s enqueued.', 'doublescale' ), $this->channel ),
				array(
					'code'             => "add_campaign_{$this->channel}",
					'campaign_message' => array(
						'id'       => $campaign_message->id,
						'hash_key' => $campaign_message->hash_key,
					),
				)
			);

			return array(
				'success' => true,
				'skipped' => false,
				'fatal'   => false,
			);
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				/* translators: %s: channel name */
				sprintf( __( 'Add campaign %s error.', 'doublescale' ), $this->channel ),
				array(
					'code'  => "add_campaign_{$this->channel}",
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
			return array(
				'success' => false,
				'skipped' => false,
				'fatal'   => false,
			);
		}
	}



	/**
	 * Process campaign message - unified logic for all types
	 *
	 * @param CampaignModel                       $campaign
	 * @param ContactModel|AutomationContactModel $contact_or_automation_contact Contact or Automation Contact Model
	 * @param CommunicationTrackingModel          $campaign_message
	 * @return void
	 */
	public function process_campaign_message( CampaignModel $campaign, $contact_or_automation_contact, CommunicationTrackingModel $campaign_message ) {
		// Extract actual contact for operations that need it (validation, sending, etc.)
		// But preserve the original for merge tag processing
		$contact = $contact_or_automation_contact instanceof AutomationContactModel
			? $contact_or_automation_contact->contact
			: $contact_or_automation_contact;

		// Check if memory limit is reached
		if ( Utils::is_memory_limit_reached() ) {
			// Track retry attempts using transients to prevent infinite requeue loop
			$retry_key   = "doublescale_retry_{$this->channel}_{$campaign_message->id}";
			$retry_count = (int) get_transient( $retry_key );

			// Maximum 3 retries for memory issues
			if ( $retry_count >= 3 ) {
				// Give up after 3 retries - mark as failed
				$campaign_message->status = TrackingStatus::FAILED;
				$campaign_message->save();

				// Clean up retry transient
				delete_transient( $retry_key );

				doublescale_get_logger()->error(
					/* translators: %s: channel name */
					sprintf( __( '%s message failed after memory limit retries', 'doublescale' ), ucfirst( $this->channel ) ),
					array(
						'code'         => "{$this->channel}_memory_retry_exceeded",
						'tracking_id'  => $campaign_message->id,
						'contact_id'   => $contact->id,
						'campaign_id'  => $campaign->id,
						'retry_count'  => $retry_count,
						'memory_usage' => memory_get_usage( true ),
						'memory_limit' => Utils::get_memory_limit(),
					)
				);
				return;
			}

			// Increment retry counter (expires after 1 hour to handle stuck tasks)
			set_transient( $retry_key, $retry_count + 1, HOUR_IN_SECONDS );

			// Log retry attempt
			doublescale_get_logger()->info(
				/* translators: %1$s: channel name, %2$d: retry attempt number (1-3) */
				sprintf( __( '%1$s message requeued due to memory limit (attempt %2$d/3)', 'doublescale' ), ucfirst( $this->channel ), $retry_count + 1 ),
				array(
					'code'         => "{$this->channel}_memory_requeue",
					'tracking_id'  => $campaign_message->id,
					'retry_count'  => $retry_count + 1,
					'memory_usage' => memory_get_usage( true ),
					'memory_limit' => Utils::get_memory_limit(),
				)
			);

			// Requeue the task for later processing - pass the original contact model
			$channel_string = $this->channel;
			PluginKernel::instance()->campaigns_tasks->enqueue_async( "process_campaign_{$channel_string}", $campaign, $contact_or_automation_contact, $campaign_message );
			return;
		}

		// Clear retry counter on successful processing attempt (memory OK)
		$retry_key = "doublescale_retry_{$this->channel}_{$campaign_message->id}";
		delete_transient( $retry_key );

		// Get message provider (for Sms/Whatsapp campaigns)
		// Email campaigns skip this check
		if ( $this->channel !== CampaignChannel::STR_EMAIL ) {
			$provider = $this->get_message_provider();
			if ( ! $provider ) {
				$this->log_provider_connection_error( $campaign, $contact, $campaign_message );
				return;
			}
		}

		try {
			// Get template data
			$template = \DoubleScale\Modules\Campaigns\Models\TemplateModel::find( $campaign_message->template_id );
			if ( ! $template ) {
				throw new \Exception(
					esc_html(
						sprintf(
						/* translators: %s: channel name */
							__( 'Template not found for %s campaign', 'doublescale' ),
							$this->channel
						)
					)
				);
			}

			// Validate template content
			$this->validate_template( $template, $this->channel );

			// Prepare message content - pass the original contact model for merge tags
			$message_data = $this->prepare_message_content( $template, $contact_or_automation_contact, $campaign_message );

			// Send the message - use actual contact for sending
			$result = $this->send_message( $message_data, $contact, $campaign_message );

			// Handle result
			$this->handle_send_result( $campaign_message, $result );

			// Log processing result
			$this->log_campaign_processing_result( $campaign, $contact, $campaign_message );

			// Clean up retry counter on successful processing
			$retry_key = "doublescale_retry_{$this->channel}_{$campaign_message->id}";
			delete_transient( $retry_key );
		} catch ( \Exception $e ) {
			// Log processing error
			$this->log_campaign_processing_error( $campaign, $contact, $campaign_message, $e );

			// Clean up retry counter on error (will be handled by error status)
			$retry_key = "doublescale_retry_{$this->channel}_{$campaign_message->id}";
			delete_transient( $retry_key );
		}
	}

	/**
	 * Prepare message content - unified logic for all types
	 *
	 * @param \DoubleScale\Modules\Campaigns\Models\TemplateModel $template
	 * @param ContactModel|AutomationContactModel                 $contact_or_automation_contact Contact or Automation Contact Model for merge tags
	 * @param CommunicationTrackingModel                          $campaign_message
	 * @return array Prepared message data
	 */
	protected function prepare_message_content( TemplateModel $template, $contact_or_automation_contact, CommunicationTrackingModel $campaign_message ) {
		$subject         = $template->subject ?? '';
		$message         = $template->body ?? $this->get_default_campaign_content();
		$add_unsubscribe = $template->get_setting( 'add_unsubscribe', true );

		// Extract actual contact for operations that need ContactModel
		$contact = $contact_or_automation_contact instanceof AutomationContactModel
			? $contact_or_automation_contact->contact
			: $contact_or_automation_contact;

		// STEP 1: Extract merge tag keys if not already cached
		if ( is_null( $this->template_merge_tag_keys ) ) {
			$combined_content              = $subject . ' ' . $message;
			$this->template_merge_tag_keys = MergeTagsManager::instance()->extract_merge_tag_keys( $combined_content );
		}

		// STEP 2: Capture merge tag values for this contact using pre-extracted keys
		if ( ! empty( $this->template_merge_tag_keys ) ) {
			\DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel::capture_merge_tags_from_keys(
				$campaign_message->id,
				$this->template_merge_tag_keys,
				$contact
			);
		}

		// Check if the message is in builder JSON format and render it to HTML
		// Pass the original contact model for merge tags
		$renderer = null;
		if ( $this->channel === CampaignChannel::STR_EMAIL ) {
			$message = $this->render_builder_content_with_tracking( $message, $contact_or_automation_contact, $campaign_message->id, $renderer );
		}

		// Process merge tags - use the original contact model to support automation merge tags
		$processed_message = MergeTagsManager::instance()->process_merge_tags( $message, $contact_or_automation_contact );
		$processed_subject = MergeTagsManager::instance()->process_merge_tags( $subject, $contact_or_automation_contact );

		// Add click tracking to URLs in the message (email only — Sms/Whatsapp don't use HTML click tracking)
		if ( $this->channel === CampaignChannel::STR_EMAIL ) {
			$tracking_class = $this->get_tracking_class();
			if ( method_exists( $tracking_class, 'add_click_tracking' ) ) {
				$tracked_message = $tracking_class::add_click_tracking( $processed_message, $campaign_message->hash_key );
			} else {
				$tracked_message = $processed_message;
			}
		} else {
			$tracked_message = $processed_message;
		}

		// Add unsubscribe link if enabled (EMAIL ONLY - Sms/Whatsapp use STOP keyword instead)
		// Sms/Whatsapp unsubscribe is handled via STOP keyword in incoming message handler
		if ( $add_unsubscribe && $this->channel === CampaignChannel::STR_EMAIL && method_exists( $tracking_class, 'add_unsubscribe_link' ) ) {
			$tracked_message = $tracking_class::add_unsubscribe_link( $tracked_message, $campaign_message->hash_key );
		}

		// Add opt-out footer for Sms campaigns (Whatsapp uses templates which have this baked in)
		if ( $this->channel === CampaignChannel::STR_SMS ) {
			$tracked_message = $this->add_opt_out_footer( $tracked_message );
		}

		return array(
			'subject'   => $processed_subject,
			'body'      => $tracked_message,
			'recipient' => $campaign_message->recipient,
			'hash_key'  => $campaign_message->hash_key,
		);
	}

	/**
	 * Render builder content with conditional section tracking
	 *
	 * @param string                              $content The content to render (could be HTML or JSON)
	 * @param ContactModel|AutomationContactModel $contact_or_automation_contact Contact model for merge tags
	 * @param int|null                            $tracking_id Communication tracking ID (null if not yet created)
	 * @param object|null                         &$renderer Renderer instance (passed by reference to capture rendered section IDs)
	 * @param string                              $footer_html Optional footer HTML to inject before </body> tag
	 * @return string Rendered HTML content
	 */
	protected function render_builder_content_with_tracking( $content, $contact_or_automation_contact, $tracking_id = null, &$renderer = null, $footer_html = '' ) {
		// Try to decode the content to see if it's JSON
		$decoded = json_decode( $content, true );

		// If it's not valid JSON, return as-is (it's already HTML)
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $content;
		}

		// Check if this is a builder format with type='builder' structure
		if ( isset( $decoded['type'] ) && $decoded['type'] === 'builder' && isset( $decoded['value'] ) ) {
			// Use EmailRenderer to convert builder JSON to HTML
			if ( class_exists( '\DoubleScale\Modules\Emails\EmailRenderer' ) ) {
				$renderer     = new \DoubleScale\Modules\Emails\EmailRenderer();
				$builder_data = $decoded['value'];

				// Extract preview text if available
				$preview_text = '';

				$html = $renderer->render_from_builder_data( $builder_data, $contact_or_automation_contact, $preview_text, $footer_html );

				// Capture rendered conditional section IDs for later storage (only after successful send)
				// IMPORTANT: Do NOT write conditional_sections meta here - it should only be written
				// after the email is successfully sent, not during rendering/preparation phase
				$rendered_section_ids = $renderer->get_rendered_section_ids();

				if ( $tracking_id ) {
					// Validate that rendered_section_ids is an array
					if ( ! is_array( $rendered_section_ids ) ) {
						$rendered_section_ids = array();
					}

					// Filter out any invalid values (ensure all items are strings)
					$rendered_section_ids = array_filter(
						$rendered_section_ids,
						function ( $id ) {
							return is_string( $id ) && ! empty( $id );
						}
					);
					// Re-index array after filtering
					$rendered_section_ids = array_values( $rendered_section_ids );

					// Store rendered section IDs temporarily - will be written to meta only after successful send
					// This ensures conditional_sections meta is only recorded for emails that were actually sent
					$this->pending_conditional_sections[ $tracking_id ] = $rendered_section_ids;
				}

				return $html;
			}
		}

		// JSON content that isn't the builder shape — return verbatim.
		return $content;
	}

	/**
	 * Render builder content to HTML if it's in builder JSON format
	 *
	 * @param string                              $content The content to render (could be HTML or JSON)
	 * @param ContactModel|AutomationContactModel $contact_or_automation_contact Contact model for merge tags
	 * @param string                              $footer_html Optional footer HTML to inject before </body> tag
	 * @return string Rendered HTML content
	 */
	protected function render_builder_content( $content, $contact_or_automation_contact, $footer_html = '' ) {
		$renderer = null;
		return $this->render_builder_content_with_tracking( $content, $contact_or_automation_contact, null, $renderer, $footer_html );
	}


	/**
	 * Complete campaign
	 *
	 * @param CampaignModel $campaign
	 * @param int           $recipients_count
	 * @return void
	 */
	protected function complete_campaign( CampaignModel $campaign, $recipients_count ) {
		$campaign->status = 'completed';
		$campaign->save();
		update_option( "doublescale_{$this->channel}_campaigns_last_contact_offset_{$campaign->id}", $recipients_count );

		// Clear progress trackers
		delete_transient( "doublescale_{$this->channel}_campaign_last_offset_{$campaign->id}" );
		delete_transient( "doublescale_{$this->channel}_campaign_no_progress_count_{$campaign->id}" );

		// Calculate and log campaign duration
		$campaign_duration = $this->calculate_campaign_duration( $campaign );

		doublescale_get_logger()->info(
			/* translators: %s: dynamic value */
			sprintf( __( '%s Campaign completed.', 'doublescale' ), ucfirst( $this->channel ) ),
			array(
				'code'       => "{$this->channel}_campaign_completed",
				'campaign'   => array(
					'id'   => $campaign->id,
					'name' => $campaign->name,
				),
				'duration'   => $campaign_duration['formatted'],
				'start_time' => $campaign_duration['start_time'],
				'end_time'   => $campaign_duration['end_time'],
				'recipients' => $recipients_count,
			)
		);

		// Clean up the campaign start time
		delete_option( "doublescale_{$this->channel}_campaign_start_time_{$campaign->id}" );

		/**
		 * Fires when a campaign is completed successfully.
		 *
		 * @since 1.0.0
		 *
		 * @param CampaignModel $campaign         The completed campaign.
		 * @param int            $recipients_count Number of recipients processed.
		 * @param string         $channel          Campaign channel (email, sms, whatsapp).
		 */
		do_action( 'doublescale_campaign_complete', $campaign, $recipients_count, $this->channel );
	}

	/**
	 * Get template for contact (A/B testing support)
	 *
	 * @param CampaignModel $campaign
	 * @param ContactModel  $contact
	 * @return int|null Template ID or null if no template found
	 */
	public function get_template_for_contact( CampaignModel $campaign, ContactModel $contact ) {
		$template_ids = $campaign->get_template_ids();

		if ( empty( $template_ids ) ) {
			return null;
		}

		// Single template - no A/B testing
		if ( count( $template_ids ) === 1 ) {
			return $template_ids[0];
		}

		// A/B testing: distribute templates evenly based on contact ID
		$template_index = $contact->id % count( $template_ids );
		return $template_ids[ $template_index ];
	}

	/**
	 * Handle send result - unified logic for all types
	 *
	 * @param CommunicationTrackingModel $campaign_message
	 * @param array                      $result Send result
	 * @return void
	 */
	protected function handle_send_result( CommunicationTrackingModel $campaign_message, $result ) {
		if ( $result && $result['success'] ) {
			$campaign_message->status  = TrackingStatus::SENT;
			$campaign_message->sent_at = current_time( 'mysql', true );

			// Write conditional_sections meta ONLY after successful send
			// This ensures we only track sections for emails that were actually sent
			$this->store_conditional_sections_meta( $campaign_message->id );
		} else {
			$campaign_message->status = TrackingStatus::FAILED;

			// Clear pending conditional sections if send failed - don't track sections for unsent emails
			if ( isset( $this->pending_conditional_sections[ $campaign_message->id ] ) ) {
				unset( $this->pending_conditional_sections[ $campaign_message->id ] );
			}

			// Log error details if available
			if ( isset( $result['error'] ) ) {
				doublescale_get_logger()->error(
					/* translators: %1$s: channel name, %2$s: error message */
					sprintf( __( '%1$s message failed: %2$s', 'doublescale' ), ucfirst( $this->channel ), $result['error'] ),
					array(
						'campaign_id' => $campaign_message->source_id,
						'contact_id'  => $campaign_message->contact_id,
						'error'       => $result['error'],
					)
				);
			}

			// Log additional debug info if available
			if ( isset( $result['debug'] ) ) {
				doublescale_get_logger()->debug(
					/* translators: %s: channel name */
					sprintf( __( '%s message failed with debug info', 'doublescale' ), ucfirst( $this->channel ) ),
					$result['debug']
				);
			}
		}

		$campaign_message->save();
	}

	/**
	 * Add opt-out footer to message
	 *
	 * Adds "Reply STOP to unsubscribe" footer for Sms messages.
	 * This is required for compliance with TCPA/CTIA guidelines.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message content
	 * @return string Message with opt-out footer
	 */
	protected function add_opt_out_footer( $message ) {
		// Check if message already contains opt-out keywords
		$opt_out_keywords = array( 'STOP', 'UNSUBSCRIBE', 'OPT OUT', 'OPTOUT' );
		$message_upper    = strtoupper( $message );

		foreach ( $opt_out_keywords as $keyword ) {
			if ( strpos( $message_upper, $keyword ) !== false ) {
				// Message already contains opt-out instruction, don't add duplicate
				return $message;
			}
		}

		/**
		 * Filter the opt-out footer text for Sms messages
		 *
		 * @since 1.0.0
		 *
		 * @param string $footer_text The opt-out footer text
		 * @param string $channel     The channel type (sms)
		 */
		$footer_text = apply_filters(
			'doublescale_message_opt_out_footer',
			__( 'Reply STOP to unsubscribe', 'doublescale' ),
			$this->channel
		);

		return $message . "\n\n" . $footer_text;
	}

	/**
	 * Store conditional sections meta for a tracking record
	 * Called only after successful send to ensure meta is only recorded for sent emails
	 *
	 * @param int $tracking_id Communication tracking ID
	 * @return void
	 */
	private function store_conditional_sections_meta( $tracking_id ) {
		// Check if we have pending conditional sections for this tracking ID
		if ( ! isset( $this->pending_conditional_sections[ $tracking_id ] ) ) {
			return; // No conditional sections were rendered for this email
		}

		$rendered_section_ids = $this->pending_conditional_sections[ $tracking_id ];

		// Only save if we have valid section IDs
		if ( ! empty( $rendered_section_ids ) && is_array( $rendered_section_ids ) ) {
			// Check if record already exists
			$existing_meta = \DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel::where( 'communication_tracking_id', $tracking_id )
				->where( 'meta_key', 'conditional_sections' )
				->first();

			if ( $existing_meta ) {
				$existing_meta->meta_value = $rendered_section_ids;
				$existing_meta->save();
			} else {
				\DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel::create(
					array(
						'communication_tracking_id' => $tracking_id,
						'meta_key'                  => 'conditional_sections',
						'meta_value'                => $rendered_section_ids,
					)
				);
			}
		}

		// Clean up temporary storage
		unset( $this->pending_conditional_sections[ $tracking_id ] );
	}


	/**
	 * Get message provider for this channel
	 *
	 * @since 1.0.0
	 *
	 * @return \DoubleScale\Pro\Modules\Inbox\MessageProviderInterface|null
	 */
	protected function get_message_provider() {
		if ( $this->message_provider ) {
			return $this->message_provider;
		}

		if ( $this->channel === CampaignChannel::STR_EMAIL ) {
			return null;
		}

		// Get provider from registry
		$this->message_provider = \DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry::instance()
			->get_provider( $this->channel );

		return $this->message_provider;
	}

	/**
	 * Log provider connection error
	 *
	 * @since 1.0.0
	 *
	 * @param CampaignModel              $campaign
	 * @param ContactModel               $contact
	 * @param CommunicationTrackingModel $campaign_message
	 * @return void
	 */
	protected function log_provider_connection_error( $campaign, $contact, $campaign_message ) {
		$campaign_message->status = TrackingStatus::FAILED;
		$campaign_message->save();

		doublescale_get_logger()->error(
			/* translators: %s: channel name */
			sprintf( __( 'Failed to connect to message provider for %s campaign.', 'doublescale' ), $this->channel ),
			array(
				'code'        => 'provider_connect_failed',
				'campaign_id' => $campaign->id,
				'contact_id'  => $contact->id,
			)
		);
	}

	/**
	 * Log campaign processing result
	 *
	 * @param CampaignModel              $campaign
	 * @param ContactModel               $contact
	 * @param CommunicationTrackingModel $campaign_message
	 * @return void
	 */
	protected function log_campaign_processing_result( $campaign, $contact, $campaign_message ) {
		doublescale_get_logger()->info(
			/* translators: %s: channel name */
			sprintf( __( 'Campaign %s message processed.', 'doublescale' ), ucfirst( $this->channel ) ),
			array(
				'code'        => "campaign_{$this->channel}_processed",
				'status'      => $campaign_message->status,
				'contact_id'  => $contact->id,
				'campaign_id' => $campaign->id,
			)
		);
	}

	/**
	 * Log campaign processing error
	 *
	 * @param CampaignModel              $campaign
	 * @param ContactModel               $contact
	 * @param CommunicationTrackingModel $campaign_message
	 * @param \Exception                 $exception
	 * @return void
	 */
	protected function log_campaign_processing_error( $campaign, $contact, $campaign_message, $exception ) {
		$campaign_message->status = TrackingStatus::FAILED;
		$campaign_message->save();

		doublescale_get_logger()->error(
			/* translators: %s: channel name */
			sprintf( __( 'Campaign %s message processing error.', 'doublescale' ), ucfirst( $this->channel ) ),
			array(
				'code'        => "campaign_{$this->channel}_error",
				'error'       => $exception->getMessage(),
				'contact_id'  => $contact->id,
				'campaign_id' => $campaign->id,
			)
		);
	}

	/**
	 * Get current execution time
	 *
	 * @return int
	 */
	public function get_current_execution_time() {
		return microtime( true ) - $this->start_time;
	}

	/**
	 * Resend a single message — delegates to CampaignResender.
	 *
	 * Kept as a public method so any existing callers continue to work.
	 *
	 * @param CampaignModel              $campaign
	 * @param ContactModel               $contact
	 * @param CommunicationTrackingModel $message
	 * @return void
	 */
	public function resend_single_message( $campaign, $contact, $message ) {
		$this->get_resender()->resend_single_message( $campaign, $contact, $message );
	}

	/**
	 * Get max per day setting for this channel
	 *
	 * Returns the max per day from settings, or the default from rate limiter.
	 * Returns null for channels without daily limits (Sms, WhatsApp).
	 *
	 * @since 1.0.0
	 *
	 * @return int|null Max per day or null if no daily limit for this channel
	 */
	protected function get_max_per_day_setting() {
		// Check if channel has daily limits configured in rate limiter
		$default_limit = $this->rate_limiter->get_default_daily_limit( $this->channel );

		// If no default limit for this channel (returns fallback 10000 only for unknown channels)
		// Sms and WhatsApp are not in the defaults array, so they'll get the fallback
		// We explicitly check if it's email to apply daily limits
		if ( 'email' !== $this->channel ) {
			return null; // No daily limits for Sms/Whatsapp
		}

		return $this->settings['max_in_day'] ?? $default_limit;
	}

	/**
	 * Get default campaign content - must be implemented by child classes
	 *
	 * @return string
	 */
	abstract protected function get_default_campaign_content();

	/**
	 * Validate template content comprehensively
	 *
	 * @param \DoubleScale\Modules\Campaigns\Models\TemplateModel $template Template to validate
	 * @param string                                              $campaign_type Campaign type
	 * @throws \Exception If template validation fails
	 * @return bool True if valid
	 */
	protected function validate_template( $template, $campaign_type ) {
		if ( ! $template ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception(
				esc_html(
					sprintf(
					/* translators: %s: campaign type (email, sms, whatsapp) */
						__( 'Template not found for %s campaign', 'doublescale' ),
						$campaign_type
					)
				)
			);
		}

		// Check for empty body
		if ( empty( trim( $template->body ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( esc_html__( 'Template body cannot be empty', 'doublescale' ) );
		}

		// Check for subject in email templates
		if ( $campaign_type === CampaignChannel::STR_EMAIL && empty( trim( $template->subject ) ) ) {
			doublescale_get_logger()->info(
				__( 'Email template missing subject', 'doublescale' ),
				array( 'template_id' => $template->id )
			);
		}

		// Validate HTML structure for email templates
		if ( $campaign_type === CampaignChannel::STR_EMAIL ) {
			if ( ! $this->is_valid_html( $template->body ) ) {
				doublescale_get_logger()->info(
					__( 'Email template contains potentially invalid HTML', 'doublescale' ),
					array( 'template_id' => $template->id )
				);
			}
		}

		// Check for required unsubscribe link in email templates
		// Note: Validation disabled - footer automatically adds unsubscribe link via default_email_footer()
		// if ( $campaign_type === 'email' && strpos( $template->body, '{{contact:unsubscribe_link}}' ) === false ) {
		// doublescale_get_logger()->info(
		// __( 'Email template missing unsubscribe link', 'doublescale'),
		// array(
		// 'template_id'   => $template->id,
		// 'template_name' => $template->name ?? 'Unknown',
		// )
		// );
		// }

		// Validate content length for Sms/Whatsapp
		if ( in_array( $campaign_type, array( 'sms', 'whatsapp' ) ) ) {
			$plain_text = wp_strip_all_tags( $template->body );
			if ( strlen( $plain_text ) > 1600 ) { // Sms limit is typically 1600 chars
				doublescale_get_logger()->info(
					/* translators: %1$s: campaign type, %2$d: character count */
					sprintf( __( '%1$s template content may be too long (%2$d characters)', 'doublescale' ), ucfirst( $campaign_type ), strlen( $plain_text ) ),
					array(
						'template_id' => $template->id,
						'length'      => strlen( $plain_text ),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Basic HTML validation
	 *
	 * @param string $html HTML content to validate
	 * @return bool True if valid HTML
	 */
	protected function is_valid_html( $html ) {
		if ( empty( $html ) ) {
			return false;
		}

		// Basic validation - check for malformed tags
		$tag_count_open  = substr_count( $html, '<' );
		$tag_count_close = substr_count( $html, '>' );

		if ( $tag_count_open !== $tag_count_close ) {
			return false;
		}

		// Use libxml for more thorough validation if available
		if ( class_exists( 'DOMDocument' ) ) {
			libxml_use_internal_errors( true );
			$doc    = new \DOMDocument();
			$result = $doc->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();
			return $result !== false;
		}

		return true;
	}


	/**
	 * Calculate campaign duration from start to completion
	 *
	 * @param CampaignModel $campaign
	 * @return array Duration breakdown with total_seconds, formatted, and human_readable
	 */
	protected function calculate_campaign_duration( CampaignModel $campaign ) {
		$start_time_key      = "doublescale_{$this->channel}_campaign_start_time_{$campaign->id}";
		$campaign_start_time = get_option( $start_time_key );

		if ( ! $campaign_start_time ) {
			// If no start time found, try to use execute_at from campaign
			if ( $campaign->execute_at ) {
				$campaign_start_time = strtotime( $campaign->execute_at );
			} else {
				// Fallback to created_at
				$campaign_start_time = strtotime( $campaign->created_at );
			}
		}

		$end_time      = microtime( true );
		$total_seconds = $end_time - (float) $campaign_start_time;
		$total_seconds = max( 0, $total_seconds ); // Ensure non-negative

		// Calculate hours, minutes, seconds
		$hours   = floor( $total_seconds / 3600 );
		$minutes = floor( ( $total_seconds % 3600 ) / 60 );
		$seconds = floor( $total_seconds % 60 );

		// Format duration string
		$formatted = '';
		if ( $hours > 0 ) {
			$formatted .= $hours . 'h ';
		}
		if ( $minutes > 0 || $hours > 0 ) {
			$formatted .= $minutes . 'm ';
		}
		$formatted .= $seconds . 's';

		// Human-readable format
		$human_readable = '';
		if ( $hours > 0 ) {
			$human_readable = sprintf(
				/* translators: 1: hours, 2: minutes, 3: seconds */
				__( '%1$d hours, %2$d minutes, %3$d seconds', 'doublescale' ),
				$hours,
				$minutes,
				$seconds
			);
		} elseif ( $minutes > 0 ) {
			$human_readable = sprintf(
				/* translators: 1: minutes, 2: seconds */
				__( '%1$d minutes, %2$d seconds', 'doublescale' ),
				$minutes,
				$seconds
			);
		} else {
			$human_readable = sprintf(
				/* translators: %d: seconds */
				__( '%d seconds', 'doublescale' ),
				$seconds
			);
		}

		return array(
			'total_seconds'  => round( $total_seconds, 2 ),
			'formatted'      => trim( $formatted ),
			'human_readable' => $human_readable,
			'start_time'     => gmdate( 'Y-m-d H:i:s', (int) $campaign_start_time ),
			'end_time'       => gmdate( 'Y-m-d H:i:s', (int) $end_time ),
		);
	}
}
