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
 * TicketStatusChanged trigger stub.
 */
class TicketStatusChanged extends TriggerPro {

	public $name = 'Ticket status changed';

	public $slug = 'ticket_status_changed';

	public $description = 'Fires when a support ticket changes status.';

	public $attributes = array();

	public $source = 'support';

	public $group = 'support';
}

TriggersManager::instance()->register( new TicketStatusChanged() );
