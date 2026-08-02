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
 * CreateTicket action stub.
 */
class CreateTicket extends ProAutomationStubAction {

	public $name = 'Create a ticket';

	public $slug = 'create_ticket';

	public $description = 'This action will open a new support ticket for the contact.';

	public $source = 'support';

	public $group = 'support';
}

CreateTicket::instance();
