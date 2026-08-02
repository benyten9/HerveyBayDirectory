<?php
/**
 * Class Integration
 *
 * This class is responsible for handling the integration
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Abstracts;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Integration\Accounts;
use DoubleScale\Modules\Booking\Managers\IntegrationsManager;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Models\CalendarModel;

/**
 * Integration class
 */
abstract class Integration {

	/**
	 * Integration Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name;

	/**
	 * Integration Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug;

	/**
	 * Integration Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description;

	/**
	 * Option name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $option_name;

	/**
	 * Remote Data
	 *
	 * @var object|null
	 */
	public $remote_data;

	/**
	 * API
	 *
	 * @var object|null
	 */
	public $api;

	/**
	 * Accounts
	 *
	 * @var Accounts
	 */
	public $accounts;

	/**
	 * Meta key
	 *
	 * @var string
	 */
	public $meta_key;

	/**
	 * Host
	 *
	 * @var CalendarModel
	 */
	public $host;

	/**
	 * Is calendar integration
	 *
	 * @var bool
	 */
	public $is_calendar = true;

	/**
	 * Has accounts
	 *
	 * @var bool
	 */
	public $has_accounts = true;

	/**
	 * Is global integration
	 *
	 * @var bool
	 */
	public $is_global = false;

	/**
	 * Auth type
	 *
	 * @var string
	 */
	public $auth_type = 'oauth';

	/**
	 * Subclasses instances.
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	private static $instances = array();

	/**
	 * Integration Instances.
	 *
	 * Instantiates or reuses an instances of Integration.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return static - Single instance
	 */
	public static function instance() {
		if ( ! isset( self::$instances[ static::class ] ) ) {
			$instance = new static();
			$instance->register();
			self::$instances[ static::class ] = $instance;
		}
		return self::$instances[ static::class ];
	}

	/**
	 * Class names
	 *
	 * @var array
	 */
	protected static $classes = array(
		// + classes from parent.
		// 'remote_data'   => Integration_Remote_Data::class,
		// 'rest_api'      => REST_API::class,
	);

	/**
	 * Constructor
	 */
	protected function __construct() {
		$this->init();
	}

	/**
	 * Init
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init() {
		$this->accounts    = new Accounts( $this );
		$this->option_name = 'doublescale_booking_' . $this->slug . '_settings';
		$this->meta_key    = 'doublescale_booking_' . $this->slug . '_accounts';

		// A third-party integration's optional sub-class constructor must not
		// break the booking-lifecycle subscription below; a missing REST
		// controller degrades the integration's admin surface but the
		// booking-create flow must still complete.
		if ( ! empty( static::$classes['rest_api'] ) ) {
			try {
				new static::$classes['rest_api']( $this );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Surfaced via IntegrationsManager logging.
			}
		}

		if ( ! empty( static::$classes['remote_data'] ) ) {
			try {
				$this->remote_data = new static::$classes['remote_data']( $this );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Surfaced via IntegrationsManager logging.
			}
		}
	}

	/**
	 * Register
	 *
	 * @return bool
	 */
	private function register() {
		try {
			IntegrationsManager::instance()->register_integration( $this );
		} catch ( \Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Set host
	 *
	 * @param int|CalendarModel $host Host.
	 *
	 * @return void
	 */
	public function set_host( $host ) {
		if ( $host instanceof CalendarModel ) {
			$this->host = $host;
			return;
		}

		$this->host = CalendarModel::find( $host );
	}

	/**
	 * Resolve a calendar-handler argument to a {@see BookingModel}.
	 *
	 * Accepts:
	 * - an existing {@see BookingModel} instance (returned unchanged);
	 * - a positive integer id;
	 * - a whole-number float id (e.g. JSON numeric);
	 * - a trimmed string of ASCII digits only (e.g. REST body id).
	 *
	 * Rejects null, false, empty strings, non-positive ids, non-digit strings, arrays, objects
	 * other than {@see BookingModel}, and unknown ids (returns null when `find` misses).
	 *
	 * @param mixed $booking_or_id Value passed from lifecycle hooks, AJAX, or cross-integration calls.
	 * @return BookingModel|null
	 */
	protected function resolve_booking( $booking_or_id ): ?BookingModel {
		if ( $booking_or_id instanceof BookingModel ) {
			return $booking_or_id;
		}

		$id = null;

		if ( is_int( $booking_or_id ) ) {
			$id = $booking_or_id;
		} elseif ( is_float( $booking_or_id ) && $booking_or_id > 0 && floor( $booking_or_id ) === $booking_or_id ) {
			$id = (int) $booking_or_id;
		} elseif ( is_string( $booking_or_id ) ) {
			$trimmed = trim( $booking_or_id );
			if ( '' !== $trimmed && ctype_digit( $trimmed ) ) {
				$id = (int) $trimmed;
			}
		}

		if ( null === $id || $id < 1 ) {
			return null;
		}

		return BookingModel::find( $id ) ?: null;
	}

	/**
	 * Subscribe to {@see \DoubleScale\Modules\Booking\Services\BookingEvents} hooks
	 * and forward `(int $booking_id, array $context)` to an instance method that expects
	 * a {@see BookingModel} (same pattern for every calendar integration).
	 *
	 * @param array<string,string> $hook_to_method WordPress hook => public method name on this class.
	 */
	protected function register_booking_lifecycle_handlers( array $hook_to_method ): void {
		foreach ( $hook_to_method as $hook => $method ) {
			if ( ! is_string( $hook ) || ! is_string( $method ) ) {
				continue;
			}
			add_action(
				$hook,
				function ( $booking_id, $_context = array() ) use ( $method ) {
					$this->dispatch_booking_lifecycle_handler( $method, $booking_id, $_context );
				},
				10,
				2
			);
		}
	}

	/**
	 * Resolve booking from lifecycle hook args and invoke the named handler.
	 *
	 * @param string               $method     Instance method; first argument must accept {@see BookingModel}.
	 * @param int|string           $booking_id Emitted by {@see \DoubleScale\Modules\Booking\Services\BookingEvents::emit()}.
	 * @param array<string, mixed> $_context   Lifecycle context bag (available for overrides; unused in default dispatch).
	 */
	protected function dispatch_booking_lifecycle_handler( string $method, $booking_id, $_context = array() ): void {
		if ( ! is_callable( array( $this, $method ) ) ) {
			return;
		}
		$booking = $this->resolve_booking( $booking_id );
		if ( ! $booking ) {
			return;
		}
		$this->{$method}( $booking );
	}

	/**
	 * Resolve default calendar for outbound booking sync from stored account meta.
	 *
	 * When `config.default_calendar` is present: `null` or incomplete structure means this account
	 * does not sync; a full object is returned as-is. When the key is absent, a single enabled
	 * calendar is used if exactly one is listed.
	 *
	 * @param string|int $account_id Account key in host meta.
	 * @param array      $data       Account row (name, tokens, config, ...).
	 * @return array<string, string>|null `calendar_id` and `account_id`, or null.
	 */
	protected function resolve_account_default_calendar( $account_id, array $data ): ?array {
		$config     = Arr::get( $data, 'config', array() );
		$has_dc_key = is_array( $config ) && array_key_exists( 'default_calendar', $config );

		if ( $has_dc_key ) {
			$default_calendar = $config['default_calendar'];
			if ( null === $default_calendar ) {
				return null;
			}
			if ( is_array( $default_calendar ) ) {
				if ( isset( $default_calendar['calendar_id'], $default_calendar['account_id'] ) ) {
					return $default_calendar;
				}
				return null;
			}
			return null;
		}

		$enabled = Arr::get( $data, 'config.calendars', array() );
		if ( is_array( $enabled ) && 1 === count( $enabled ) ) {
			$only_id = reset( $enabled );
			if ( is_string( $only_id ) || is_int( $only_id ) ) {
				return array(
					'calendar_id' => (string) $only_id,
					'account_id'  => (string) $account_id,
				);
			}
		}

		return null;
	}

	/**
	 * Pick the account row used for outbound sync.
	 *
	 * Prefers an account with an explicit `config.default_calendar`; otherwise
	 * returns the first account that resolves to a single enabled calendar.
	 *
	 * @param array<string, mixed> $integration_meta Host meta map account_id => account payload.
	 * @return array{id: string|int, data: array, default_calendar: array}|null
	 */
	protected function pick_host_account_for_default_calendar_sync( array $integration_meta ): ?array {
		$fallback = null;
		foreach ( $integration_meta as $account_id => $data ) {
			if ( empty( $data ) || empty( $data['config'] ) || ! is_array( $data['config'] ) ) {
				continue;
			}
			$resolved = $this->resolve_account_default_calendar( $account_id, $data );
			if ( ! $resolved ) {
				continue;
			}
			$dc = Arr::get( $data, 'config.default_calendar' );
			if ( is_array( $dc ) && ! empty( $dc['calendar_id'] ) && isset( $dc['account_id'] ) ) {
				return array(
					'id'               => $account_id,
					'data'             => $data,
					'default_calendar' => $resolved,
				);
			}
			if ( null === $fallback ) {
				$fallback = array(
					'id'               => $account_id,
					'data'             => $data,
					'default_calendar' => $resolved,
				);
			}
		}
		return $fallback;
	}

	/**
	 * Host calendar row whose integration accounts apply for this booking.
	 *
	 * For team calendars with round-robin events, the booking has been assigned
	 * to a specific host via booking_hosts — integration writes (Google/Outlook/
	 * Apple) must target that host's third-party account so the meeting lands on
	 * the actual attendee's remote calendar. Collective and other multi-host
	 * team modes still route to the team owner because there is no single
	 * "selected" host. Personal calendars are a trivial passthrough.
	 *
	 * @param BookingModel $booking Booking model.
	 * @return CalendarModel|null
	 */
	protected function get_integration_host_calendar_for_booking( BookingModel $booking ): ?CalendarModel {
		$booking_calendar = $booking->calendar;
		if ( ! $booking_calendar ) {
			return null;
		}
		if ( 'host' === $booking_calendar->type ) {
			return $booking_calendar;
		}
		if ( 'team' === $booking_calendar->type ) {
			$event = $booking->event;
			if ( $event && 'round-robin' === $event->type ) {
				$hosts = $booking->hosts;
				if ( $hosts && count( $hosts ) ) {
					foreach ( $hosts as $user ) {
						$host_cal = CalendarModel::where( 'user_id', $user->ID )->where( 'type', 'host' )->first();
						if ( $host_cal ) {
							return $host_cal;
						}
					}
				}
			}
			$owner_id = (int) $booking_calendar->user_id;
			return CalendarModel::where( 'user_id', $owner_id )->where( 'type', 'host' )->first();
		}
		$hosts = $booking->hosts;
		if ( $hosts && count( $hosts ) ) {
			foreach ( $hosts as $user ) {
				$host_cal = CalendarModel::where( 'user_id', $user->ID )->where( 'type', 'host' )->first();
				if ( $host_cal ) {
					return $host_cal;
				}
			}
		}
		return null;
	}

	/**
	 * Connect the integration
	 *
	 * @since 1.0.0
	 *
	 * @param int $host_id Host ID.
	 * @param int $account_id Account ID.
	 *
	 * @return bool|object
	 */
	public function connect( $host_id, $account_id ) {
		$this->set_host( $host_id );
		if ( ! $this->host ) {
			return new \WP_Error( 'host_not_found', __( 'Host not found.', 'doublescale' ) );
		}
		// Implement this method in the child class.
	}

	/**
	 * Get the settings
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_settings() {
		return get_option( $this->option_name, array() );
	}

	/**
	 * Get the setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();
		return Arr::get( $settings, $key, $default );
	}

	/**
	 * Update the settings
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings
	 *
	 * @return void
	 */
	public function update_settings( $settings ) {
		update_option( $this->option_name, $settings );
	}

	/**
	 * Update the setting
	 *
	 * @since 1.0.0
	 *
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return void
	 */
	public function update_setting( $key, $value ) {
		$settings         = $this->get_settings();
		$settings[ $key ] = $value;
		$this->update_settings( $settings );
	}

	/**
	 * validate the integration
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function validate( $settings ) {
		return true;
	}

	/**
	 * Is connected
	 *
	 * @since 1.0.0
	 *
	 * @param int $host_id Host ID.
	 * @param int $account_id Account ID.
	 *
	 * @return bool
	 */
	public function is_connected( $host_id, $account_id ) {
		$api = $this->connect( $host_id, $account_id );
		if ( $api && ! is_wp_error( $api ) ) {
			return true;
		}

		/* translators: %s: integration name */
		return new \WP_Error( 'integration_not_connected', sprintf( __( 'Integration %s is not connected.', 'doublescale' ), $this->name ) );
	}

	/**
	 * Get icon
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_icon() {
		// Booking integration icons (Apple/Google/Outlook/Zoom) are Pro-tier assets.
		// Free-only installs don't have the Pro plugin URL constant — return empty so
		// the JS bootstrap falls back gracefully rather than fataling on an undefined constant.
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ) {
			return '';
		}
		return DOUBLESCALE_PRO_PLUGIN_URL . 'assets/booking-icons/' . $this->slug . '/icon.svg';
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array();
	}

	/**
	 * Auth fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_auth_fields() {
		return array();
	}
}
