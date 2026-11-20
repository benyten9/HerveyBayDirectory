<?php
/**
 * Amelia "Event Booked" trigger (free plugin): definition only. The runtime
 * ships in DoubleScale Pro.
 *
 * @package DoubleScale\Modules\Automations
 */

namespace DoubleScale\Modules\Automations\Triggers\Amelia;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * EventBooked trigger stub.
 */
class EventBooked extends TriggerPro {

	/**
	 * Pro class that runs this trigger.
	 */
	public const PRO_CLASS = '\\DoubleScale\\Pro\\Modules\\Automations\\Triggers\\Amelia\\EventBooked';

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Event Booked';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'amelia_event_booked';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a customer books a place in an Amelia event.';

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
	public $group = 'amelia';

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		// Pro is on but older than the Amelia triggers: keep the trigger
		// locked rather than offer one that would never fire.
		if ( ! $this->is_pro && ! class_exists( self::PRO_CLASS ) ) {
			$this->is_pro = true;
		}
	}
}

TriggersManager::instance()->register( new EventBooked() );
