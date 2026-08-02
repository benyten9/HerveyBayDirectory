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
 * TicketAgentAssigned trigger stub.
 */
class TicketAgentAssigned extends TriggerPro {

	public $name = 'Ticket agent assigned';

	public $slug = 'ticket_agent_assigned';

	public $description = 'Fires when a support ticket is assigned to an agent.';

	public $attributes = array();

	public $source = 'support';

	public $group = 'support';
}

TriggersManager::instance()->register( new TicketAgentAssigned() );
