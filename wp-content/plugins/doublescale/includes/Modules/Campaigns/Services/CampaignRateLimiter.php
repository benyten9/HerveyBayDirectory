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
		// Matches the documented default of the `whatsapp.max_in_second`
		// setting, which the REST layer validates to 1..10. This is only the
		// fallback for a site that never saved the setting — but until the cap
		// was wired through get_max_per_second(), EVERY WhatsApp send used it,
		// at its previous value of 1/s.
		'whatsapp' => 10,
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
	 * Start an empty one-second window for a channel.
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
			'sent' => array(),
		);
	}

	/**
	 * Wait, if needed, so this send keeps the channel under its per-second cap.
	 *
	 * Sliding one-second window: with $max sends in the last second, wait until
	 * the oldest of them is a second old. No one-second span ever holds more
	 * than $max sends, whatever the pace before. Fires
	 * `doublescale_rate_limit_wait` before pausing.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $channel        Campaign channel
	 * @param int|null $max_per_second Cap (null = channel default).
	 * @return bool Always true.
	 */
	public function check_and_wait_per_second( $channel, $max_per_second = null ) {
		$channel = $this->normalize_channel_type( $channel );

		if ( ! isset( $this->second_trackers[ $channel ]['sent'] ) ) {
			$this->init_second_tracker( $channel );
		}

		if ( null === $max_per_second ) {
			$max_per_second = $this->get_default_per_second_limit( $channel );
		}

		$max_per_second = (int) apply_filters( "doublescale_{$channel}_max_per_second", $max_per_second );
		if ( $max_per_second <= 0 ) {
			return true;
		}

		$this->prune_window( $channel );
		$sent = $this->second_trackers[ $channel ]['sent'];
		if ( count( $sent ) >= $max_per_second ) {
			$oldest_in_window = $sent[ count( $sent ) - $max_per_second ];
			$wait             = ( $oldest_in_window + 1.0 ) - microtime( true );
			if ( $wait > 0 ) {
				$sleep_microseconds = min( (int) ceil( $wait * 1000000 ), 1000000 );
				do_action( 'doublescale_rate_limit_wait', $channel, $sleep_microseconds );
				usleep( $sleep_microseconds );
			}
			$this->prune_window( $channel );
		}

		return true;
	}

	/**
	 * Record a send in the channel's one-second window.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return void
	 */
	public function record_sent( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		if ( ! isset( $this->second_trackers[ $channel ]['sent'] ) ) {
			$this->init_second_tracker( $channel );
		}

		$this->second_trackers[ $channel ]['sent'][] = microtime( true );
		$this->prune_window( $channel );
	}

	/**
	 * Drop send times older than one second.
	 *
	 * @param string $channel Normalized channel.
	 * @return void
	 */
	private function prune_window( $channel ) {
		$cutoff = microtime( true ) - 1.0;
		$sent   = $this->second_trackers[ $channel ]['sent'];
		$total  = count( $sent );
		$drop   = 0;
		while ( $drop < $total && $sent[ $drop ] <= $cutoff ) {
			++$drop;
		}
		if ( $drop > 0 ) {
			$this->second_trackers[ $channel ]['sent'] = array_slice( $sent, $drop );
		}
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
	 * Sends in the channel's last second.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Campaign channel
	 * @return int
	 */
	public function get_current_second_count( $channel ) {
		$channel = $this->normalize_channel_type( $channel );

		if ( ! isset( $this->second_trackers[ $channel ]['sent'] ) ) {
			return 0;
		}

		$this->prune_window( $channel );

		return count( $this->second_trackers[ $channel ]['sent'] );
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
	 * The shared daily cap for a channel: campaigns, sequences and workflows
	 * all count against it. Email reads the Email settings' "max per day";
	 * SMS and WhatsApp have none here (WhatsApp follows the Meta tier).
	 *
	 * @param string|int $channel Channel.
	 * @return int|null Null when the channel has no daily cap.
	 */
	public function get_daily_limit( $channel ) {
		$channel = $this->normalize_channel_type( $channel );
		$limit   = null;

		if ( isset( $this->default_daily_limits[ $channel ] ) ) {
			$settings = \DoubleScale\Core\Settings\Settings::get( $channel, array() );
			$limit    = (int) ( is_array( $settings ) && ! empty( $settings['max_in_day'] ) ? $settings['max_in_day'] : $this->default_daily_limits[ $channel ] );
		}

		/**
		 * Filter a channel's shared daily send cap (null for none).
		 *
		 * @param int|null $limit   Daily cap.
		 * @param string   $channel email|sms|whatsapp.
		 */
		$limit = apply_filters( 'doublescale_daily_send_limit', $limit, $channel );

		return null === $limit ? null : max( 0, (int) $limit );
	}

	/**
	 * Whether today's shared cap for the channel is used up.
	 *
	 * @param string|int $channel Channel.
	 * @return bool
	 */
	public function is_daily_cap_reached( $channel ) {
		$limit = $this->get_daily_limit( $channel );
		return null !== $limit && $this->get_daily_count( $channel ) >= $limit;
	}

	/**
	 * When today's counters start again: next midnight in the site's timezone.
	 *
	 * @return \DateTimeImmutable
	 */
	public function next_reset_at() {
		return new \DateTimeImmutable( 'tomorrow', wp_timezone() );
	}

	/**
	 * Option holding a channel's count for one day (site timezone).
	 *
	 * The date in the name makes the count start again every day without a
	 * reset job; old days are removed by cleanup_old_daily_counts().
	 *
	 * @param string $channel Normalized channel.
	 * @return string
	 */
	private function daily_option_name( $channel ) {
		return "doublescale_daily_{$channel}_count_" . wp_date( 'Ymd' );
	}

	/**
	 * Get today's count for a channel.
	 *
	 * @param string $type Campaign type string
	 * @return int
	 */
	public function get_daily_count( $type ) {
		$type = $this->normalize_channel_type( $type );
		return (int) get_option( $this->daily_option_name( $type ), 0 );
	}

	/**
	 * Increment today's count for a channel.
	 * Uses atomic increment to prevent race conditions
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Campaign type string
	 * @param int    $by   How many sends to add.
	 * @return int New count after increment
	 */
	public function increment_daily_count( $type, $by = 1 ) {
		global $wpdb;

		$by = max( 1, (int) $by );

		$type        = $this->normalize_channel_type( $type );
		$option_name = $this->daily_option_name( $type );

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                VALUES (%s, %d, 'no')
                ON DUPLICATE KEY UPDATE option_value = option_value + %d",
				$option_name,
				$by,
				$by
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
			update_option( $option_name, $current_count + $by, false );
			return $current_count + $by;
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $this->get_daily_count( $type );
	}

	/**
	 * Reset today's count for a channel.
	 *
	 * @param string $type Campaign type string
	 * @return void
	 */
	public function reset_daily_count( $type ) {
		$type = $this->normalize_channel_type( $type );
		update_option( $this->daily_option_name( $type ), 0, false );

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
	 * Remove counters of past days, and the undated counters older releases
	 * kept forever. Runs on the daily cleanup slot.
	 *
	 * @return void
	 */
	public static function cleanup_old_daily_counts() {
		global $wpdb;

		$today = wp_date( 'Ymd' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name REGEXP '^doublescale_daily_(email|sms|whatsapp)_count(_[0-9]{8})?$'
				AND option_name NOT LIKE %s",
				'%\\_' . $today
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
			$max_per_day = $this->get_daily_limit( $channel );
		}

		if ( null !== $max_per_day && $this->is_daily_limit_reached( $channel, $max_per_day ) ) {
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

		$limit = $this->get_daily_limit( $channel );

		return array(
			'channel'              => $channel,
			'daily_count'          => $this->get_daily_count( $channel ),
			'daily_limit'          => $limit,
			'daily_remaining'      => null === $limit ? null : max( 0, $limit - $this->get_daily_count( $channel ) ),
			'per_second_limit'     => $this->get_default_per_second_limit( $channel ),
			'current_second_count' => $this->get_current_second_count( $channel ),
		);
	}
}
