<?php
/**
 * Pro automation action (free plugin): definition only. Implementation ships in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Support;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\ProAutomationStubAction;

/**
 * AssignAgentTicket action stub.
 */
class AssignAgentTicket extends ProAutomationStubAction {

	public $name = 'Assign a ticket agent';

	public $slug = 'assign_agent_ticket';

	public $description = 'This action will assign an agent to a support ticket.';

	public $source = 'support';

	public $group = 'support';
}

AssignAgentTicket::instance();
