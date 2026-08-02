<?php
/**
 * Campaign Rate Limiter Service
 * Handles daily and per-second rate limiting for all campaign types
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\CampaignChannel;

/**
 * CampaignRateLimiter class
 */
class CampaignRateLimiter {

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var CampaignRateLimiter
	 */
	private static $instance;

	/**
	 * Per-second rate limiting trackers (in-memory, per request)
	 *
	 * @var array
	 */
	private $second_trackers = array();

	/**
	 * Default per-second limits by channel
	 *
	 * @var array
	 */
	private $default_per_second_limits = array(
		'email'    => 15,
		'sms'      => 1,
		'whatsapp' => 1,
	);

	/**
	 * Default daily limits by channel
	 *
	 * Note: Sms and WhatsApp do not have daily limits enforced by this plugin.
	 * Their rate limits are handled by the provider (Twilio, Meta, etc.).
	 *
	 * @var array
	 */
	private $default_daily_limits = array(
		'email' => 10000,
	);

	/**
	 * CampaignRateLimiter Instance.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return self - Single instance
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		// Initialize trackers
		$this->second_trackers = array();
	}

	/**
	 * Normalize channel type to string
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $type Campaign type
	 * @return string Normalized channel string
	 */
	private function normalize_channel_type( $type ) {
		if ( is_string( $type ) ) {
			return $type;
		}

		if ( is_int( $type ) ) {
			$channel_string = CampaignChannel::to_string( $type );
			if ( $channel_string ) {
				return $channel_string;
			}
		}

		return (string) $type;
	}

	// =========================================================================
	// PER-SECOND RATE LIMITING
	// =========================================================================

	/**
	 * Initialize per-second tracker for a channel
	 *
	 * Call this at the start of campaign processing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return void
	 */
	public function init_second_tracker( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		$this->second_trackers[ $channel ] = array(
			'count'        => 0,
			'second_start' => microtime( true ),
		);
	}

	/**
	 * Check if per-second limit is reached and wait if necessary
	 *
	 * Returns true if processing can continue, false if should stop.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $channel        Campaign channel
	 * @param int|null $max_per_second Max per second (null = use default)
	 * @return bool True if can continue, false if should stop
	 */
	public function check_and_wait_per_second( $channel, $max_per_second = null ) {
		$channel = $this->normalize_channel_type( $channel );

		// Initialize tracker if not exists
		if ( ! isset( $this->second_trackers[ $channel ] ) ) {
			$this->init_second_tracker( $channel );
		}

		// Get limit
		if ( null === $max_per_second ) {
			$max_per_second = $this->get_default_per_second_limit( $channel );
		}

		$max_per_second = apply_filters( "doublescale_{$channel}_max_per_second", $max_per_second );

		// Check if limit reached
		if ( $this->second_trackers[ $channel ]['count'] >= $max_per_second ) {
			$this->wait_for_next_second( $channel );
		}

		return true;
	}

	/**
	 * Record a message sent (increment per-second counter)
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return void
	 */
	public function record_sent( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		if ( ! isset( $this->second_trackers[ $channel ] ) ) {
			$this->init_second_tracker( $channel );
		}

		++$this->second_trackers[ $channel ]['count'];
	}

	/**
	 * Wait until the current second has elapsed
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return void
	 */
	private function wait_for_next_second( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		if ( ! isset( $this->second_trackers[ $channel ] ) ) {
			return;
		}

		$elapsed = microtime( true ) - $this->second_trackers[ $channel ]['second_start'];

		if ( $elapsed < 1.0 ) {
			$sleep_microseconds = (int) ( ( 1.0 - $elapsed ) * 1000000 );

			// Safety cap: never sleep more than 1 second
			$sleep_microseconds = min( $sleep_microseconds, 1000000 );

			if ( $sleep_microseconds > 0 ) {
				usleep( $sleep_microseconds );
			}
		}

		// Reset tracker for new second
		$this->second_trackers[ $channel ] = array(
			'count'        => 0,
			'second_start' => microtime( true ),
		);
	}

	/**
	 * Get default per-second limit for channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return int Default limit
	 */
	public function get_default_per_second_limit( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		return $this->default_per_second_limits[ $channel ] ?? 10;
	}

	/**
	 * Get current per-second count for channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return int Current count this second
	 */
	public function get_current_second_count( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		if ( ! isset( $this->second_trackers[ $channel ] ) ) {
			return 0;
		}

		return $this->second_trackers[ $channel ]['count'];
	}

	// =========================================================================
	// DAILY RATE LIMITING
	// =========================================================================

	/**
	 * Check if daily limit has been reached
	 *
	 * @param string $type       Campaign type string
	 * @param int    $max_per_day Maximum allowed per day
	 * @return bool True if limit reached
	 */
	public function is_daily_limit_reached( $type, $max_per_day ) {
		$daily_count = $this->get_daily_count( $type );
		return $daily_count >= $max_per_day;
	}

	/**
	 * Get current daily count for campaign type
	 *
	 * @param string $type Campaign type string
	 * @return int Current daily count
	 */
	public function get_daily_count( $type ) {
		$type = $this->normalize_channel_type( $type );
		return (int) get_option( "doublescale_daily_{$type}_count", 0 );
	}

	/**
	 * Increment daily count for campaign type
	 * Uses atomic increment to prevent race conditions
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Campaign type string
	 * @return int New count after increment
	 */
	public function increment_daily_count( $type ) {
		global $wpdb;

		$type        = $this->normalize_channel_type( $type );
		$option_name = "doublescale_daily_{$type}_count";

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                VALUES (%s, 1, 'no')
                ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$option_name
			)
		);

		if ( false === $result ) {
			doublescale_get_logger()->info(
				'Atomic increment failed, falling back to regular update',
				array(
					'code'  => 'rate_limiter_atomic_increment_failed',
					'type'  => $type,
					'error' => $wpdb->last_error,
				)
			);

			$current_count = $this->get_daily_count( $type );
			update_option( $option_name, $current_count + 1, false );
			return $current_count + 1;
		}

		wp_cache_delete( $option_name, 'options' );

		return $this->get_daily_count( $type );
	}

	/**
	 * Reset daily count for campaign type
	 *
	 * @param string $type Campaign type string
	 * @return void
	 */
	public function reset_daily_count( $type ) {
		$type = $this->normalize_channel_type( $type );
		update_option( "doublescale_daily_{$type}_count", 0, false );

		doublescale_get_logger()->info(
			/* translators: %s: channel type (email, sms, whatsapp) */
			sprintf( __( 'Daily %s count reset.', 'doublescale' ), $type ),
			array(
				'code'    => "daily_{$type}_count_reset",
				'channel' => $type,
			)
		);
	}

	/**
	 * Log daily limit reached
	 *
	 * @param string $type        Campaign type string
	 * @param int    $daily_count Current count
	 * @param int    $max_per_day Maximum allowed
	 * @return void
	 */
	public function log_daily_limit_reached( $type, $daily_count, $max_per_day ) {
		$type = $this->normalize_channel_type( $type );

		doublescale_get_logger()->info(
			/* translators: %s: channel type (email, sms, whatsapp) */
			sprintf( __( 'Daily %s limit reached.', 'doublescale' ), $type ),
			array(
				'code'    => "daily_{$type}_limit_reached",
				'channel' => $type,
				'data'    => array(
					'daily_count' => $daily_count,
					'max_per_day' => $max_per_day,
				),
			)
		);
	}

	/**
	 * Get default daily limit for channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return int Default daily limit
	 */
	public function get_default_daily_limit( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		return $this->default_daily_limits[ $channel ] ?? 10000;
	}

	// =========================================================================
	// COMBINED HELPER METHODS
	// =========================================================================

	/**
	 * Check all rate limits before sending
	 *
	 * Convenience method that checks both daily and per-second limits.
	 * Waits if per-second limit is reached (blocking).
	 *
	 * @since 1.0.0
	 *
	 * @param string   $channel        Campaign channel
	 * @param int|null $max_per_second Max per second (null = use settings/default)
	 * @param int|null $max_per_day    Max per day (null = use settings/default)
	 * @return array ['can_send' => bool, 'reason' => string|null]
	 */
	public function check_rate_limits( $channel, $max_per_second = null, $max_per_day = null ) {
		$channel = $this->normalize_channel_type( $channel );

		// Check daily limit first (non-blocking)
		if ( null === $max_per_day ) {
			$max_per_day = $this->get_default_daily_limit( $channel );
		}

		if ( $this->is_daily_limit_reached( $channel, $max_per_day ) ) {
			return array(
				'can_send' => false,
				'reason'   => 'daily_limit_reached',
			);
		}

		// Check and wait for per-second limit (blocking)
		$this->check_and_wait_per_second( $channel, $max_per_second );

		return array(
			'can_send' => true,
			'reason'   => null,
		);
	}

	/**
	 * Record a successful send (updates both per-second and daily counters)
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return void
	 */
	public function record_send_complete( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		// Update per-second counter (in-memory)
		$this->record_sent( $channel );

		// Update daily counter (database)
		$this->increment_daily_count( $channel );
	}

	/**
	 * Get rate limit status for admin display
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return array Status information
	 */
	public function get_rate_limit_status( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		return array(
			'channel'              => $channel,
			'daily_count'          => $this->get_daily_count( $channel ),
			'daily_limit'          => $this->get_default_daily_limit( $channel ),
			'daily_remaining'      => max( 0, $this->get_default_daily_limit( $channel ) - $this->get_daily_count( $channel ) ),
			'per_second_limit'     => $this->get_default_per_second_limit( $channel ),
			'current_second_count' => $this->get_current_second_count( $channel ),
		);
	}
}
