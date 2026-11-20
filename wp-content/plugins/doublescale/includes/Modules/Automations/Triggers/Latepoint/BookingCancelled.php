<?php
/**
 * LatePoint "Booking Cancelled" trigger (free plugin): definition only. The runtime
 * ships in DoubleScale Pro.
 *
 * @package DoubleScale\Modules\Automations
 */

namespace DoubleScale\Modules\Automations\Triggers\Latepoint;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * BookingCancelled trigger stub.
 */
class BookingCancelled extends TriggerPro {

	/**
	 * Pro class that runs this trigger.
	 */
	public const PRO_CLASS = '\\DoubleScale\\Pro\\Modules\\Automations\\Triggers\\Latepoint\\BookingCancelled';

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Booking Cancelled';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'latepoint_booking_cancelled';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a LatePoint booking is cancelled.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'appointments';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'latepoint';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		// Pro is on but older than the LatePoint triggers: keep the trigger
		// locked rather than offer one that would never fire.
		if ( ! $this->is_pro && ! class_exists( self::PRO_CLASS ) ) {
			$this->is_pro = true;
		}
	}
}

TriggersManager::instance()->register( new BookingCancelled() );
