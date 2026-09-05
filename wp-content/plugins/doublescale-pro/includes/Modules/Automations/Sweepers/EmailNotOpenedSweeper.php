<?php
/**
 * Daily sweep: contacts who received email but have not opened any for X days.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Sweepers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Tasks;
use DoubleScale\Core\Utils\DateWithin;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;

/**
 * EmailNotOpenedSweeper class.
 */
class EmailNotOpenedSweeper {

	/**
	 * Option key storing announced event keys => timestamp.
	 */
	const OPTION_KEY = 'doublescale_email_not_opened_announced';

	/**
	 * Default inactivity window when the automation setting is empty.
	 */
	const DEFAULT_DAYS = 30;

	/**
	 * Hook name registered on the automations Action Scheduler group.
	 */
	const SWEEP_HOOK = 'process_email_not_opened_sweep';

	/**
	 * Trigger slug this sweeper feeds.
	 */
	const TRIGGER_SLUG = 'email_not_opened';

	/**
	 * @var self|null
	 */
	private static $instance;

	/**
	 * @var Tasks
	 */
	private $tasks;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->tasks = new Tasks( 'doublescale_automations' );
		$this->init_hooks();
	}

	/**
	 * Register AS callback and schedule.
	 */
	private function init_hooks() {
		$this->tasks->register_callback( self::SWEEP_HOOK, array( $this, 'process_sweep' ) );
		add_action( 'init', array( $this, 'schedule_sweep' ) );
	}

	/**
	 * Schedule the recurring sweep once per day.
	 */
	public function schedule_sweep() {
		if ( false === $this->tasks->get_next_timestamp( self::SWEEP_HOOK ) ) {
			$this->tasks->schedule_recurring( time(), DAY_IN_SECONDS, self::SWEEP_HOOK );
		}
	}

	/**
	 * Announce unengaged contacts once per inactivity window + last-send cycle.
	 */
	public function process_sweep() {
		$day_windows = $this->configured_day_windows();
		if ( empty( $day_windows ) ) {
			$this->prune_announced();
			return;
		}

		foreach ( $day_windows as $days ) {
			$this->process_window( $days );
		}

		$this->prune_announced();
	}

	/**
	 * Unique positive day counts configured on active automations.
	 *
	 * @return int[]
	 */
	private function configured_day_windows() {
		$automations = AutomationModel::get_automations_by_trigger( self::TRIGGER_SLUG );
		$windows     = array();

		foreach ( $automations as $automation ) {
			$days = self::normalize_days( $automation->get_setting( 'days', self::DEFAULT_DAYS ) );
			if ( $days < 1 ) {
				continue;
			}
			$windows[ $days ] = $days;
		}

		return array_values( $windows );
	}

	/**
	 * @param int $days Inactivity window.
	 */
	private function process_window( $days ) {
		$days        = (int) $days;
		$batch_size  = 50;
		$max_batches = 10;
		$batch_count = 0;
		$announced   = $this->get_announced();
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		do {
			$rows = CommunicationTrackingModel::query()
				->emails()
				->outbound()
				->whereNotNull( 'sent_at' )
				->whereHas(
					'contact',
					static function ( $query ) {
						$query->where( 'email_status', 'subscribed' )
							->whereNotNull( 'email' )
							->where( 'email', '!=', '' );
					}
				)
				->select( 'contact_id' )
				->selectRaw( 'MAX(sent_at) as last_sent_at' )
				->selectRaw( 'MAX(opened_at) as last_opened_at' )
				->groupBy( 'contact_id' )
				->havingRaw( 'MAX(sent_at) <= ?', array( $cutoff ) )
				->havingRaw( '(MAX(opened_at) IS NULL OR MAX(opened_at) <= ?)', array( $cutoff ) )
				->orderBy( 'contact_id', 'asc' )
				->offset( $batch_count * $batch_size )
				->limit( $batch_size )
				->get();

			if ( $rows->isEmpty() ) {
				break;
			}

			$contacts = ContactModel::query()
				->whereIn( 'id', $rows->pluck( 'contact_id' )->all() )
				->get()
				->keyBy( 'id' );

			foreach ( $rows as $row ) {
				$contact_id     = (int) $row->contact_id;
				$last_sent_at   = (string) ( $row->last_sent_at ?? '' );
				$last_opened_at = (string) ( $row->last_opened_at ?? '' );

				if ( ! self::contact_qualifies( $last_sent_at, $last_opened_at, $days ) ) {
					continue;
				}

				$contact = $contacts->get( $contact_id );
				if ( ! $contact instanceof ContactModel ) {
					continue;
				}

				$key = $this->event_key( $contact_id, $days, $last_sent_at );
				if ( isset( $announced[ $key ] ) ) {
					continue;
				}

				/**
				 * Fires once when a subscribed contact has not opened email for X days.
				 *
				 * @param ContactModel $contact        Contact.
				 * @param int          $days           Configured inactivity window.
				 * @param string       $last_sent_at   Latest outbound email sent_at.
				 * @param string       $last_opened_at Latest email opened_at, or empty.
				 */
				do_action( 'doublescale_automation_email_not_opened', $contact, $days, $last_sent_at, $last_opened_at );
				$announced[ $key ] = time();
			}

			$batch_count++;
			if ( $batch_count >= $max_batches ) {
				break;
			}
		} while ( $rows->count() === $batch_size );

		$this->save_announced( $announced );
	}

	/**
	 * Coerce a trigger setting to a positive day count.
	 *
	 * Empty / missing values fall back to {@see DEFAULT_DAYS}. Out-of-range
	 * numbers return 0 so the caller can skip the automation.
	 *
	 * @param mixed $value Raw settings value.
	 * @return int
	 */
	public static function normalize_days( $value ) {
		if ( '' === $value || null === $value || false === $value ) {
			return self::DEFAULT_DAYS;
		}

		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		$days = (int) $value;
		if ( $days < 1 || $days > DateWithin::MAX_DAYS ) {
			return 0;
		}

		return $days;
	}

	/**
	 * Whether last send/open timestamps fall outside the inactivity window.
	 *
	 * Contacts who were never sent an email do not qualify. A more recent send
	 * than X days ago also does not qualify (they still have time to open it).
	 *
	 * @param string     $last_sent_at   GMT datetime or empty.
	 * @param string     $last_opened_at GMT datetime or empty.
	 * @param int        $days           Positive day count.
	 * @return bool
	 */
	public static function contact_qualifies( $last_sent_at, $last_opened_at, $days ) {
		$days = (int) $days;
		if ( $days < 1 || $days > DateWithin::MAX_DAYS ) {
			return false;
		}

		$sent_ts = self::to_utc_ts( $last_sent_at );
		if ( $sent_ts <= 0 ) {
			return false;
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		if ( $sent_ts > $cutoff ) {
			return false;
		}

		$opened_ts = self::to_utc_ts( $last_opened_at );
		if ( $opened_ts > 0 && $opened_ts > $cutoff ) {
			return false;
		}

		return true;
	}

	/**
	 * @param int    $contact_id   Contact ID.
	 * @param int    $days         Window.
	 * @param string $last_sent_at Last send datetime.
	 */
	public function event_key( $contact_id, $days, $last_sent_at ) {
		$date = substr( (string) $last_sent_at, 0, 10 );
		return (int) $contact_id . ':' . (int) $days . ':' . $date;
	}

	/**
	 * @param string $datetime GMT datetime.
	 * @return int Unix timestamp or 0.
	 */
	private static function to_utc_ts( $datetime ) {
		$datetime = trim( (string) $datetime );
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return 0;
		}

		$parsed = \DateTime::createFromFormat( 'Y-m-d H:i:s', $datetime, new \DateTimeZone( 'UTC' ) );
		if ( $parsed instanceof \DateTime ) {
			return $parsed->getTimestamp();
		}

		$ts = strtotime( $datetime . ' UTC' );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * @return array<string,int>
	 */
	private function get_announced() {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string,int> $announced Map.
	 */
	private function save_announced( $announced ) {
		update_option( self::OPTION_KEY, $announced, false );
	}

	/**
	 * Drop announcement keys older than two years so a new last-send can fire again.
	 */
	private function prune_announced() {
		$announced = $this->get_announced();
		$cutoff    = time() - ( 730 * DAY_IN_SECONDS );
		$changed   = false;

		foreach ( $announced as $key => $ts ) {
			if ( (int) $ts < $cutoff ) {
				unset( $announced[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			$this->save_announced( $announced );
		}
	}
}
