<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Support;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * TicketClosed trigger stub.
 */
class TicketClosed extends TriggerPro {

	public $name = 'Ticket closed';

	public $slug = 'ticket_closed';

	public $description = 'Fires when a support ticket is closed.';

	public $attributes = array();

	public $source = 'support';

	public $group = 'support';
}

TriggersManager::instance()->register( new TicketClosed() );
