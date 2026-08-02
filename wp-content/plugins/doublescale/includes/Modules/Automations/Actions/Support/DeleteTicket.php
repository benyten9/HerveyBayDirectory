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
 * DeleteTicket action stub.
 */
class DeleteTicket extends ProAutomationStubAction {

	public $name = 'Delete a ticket';

	public $slug = 'delete_ticket';

	public $description = 'This action will permanently delete a support ticket for the contact.';

	public $source = 'support';

	public $group = 'support';
}

DeleteTicket::instance();
