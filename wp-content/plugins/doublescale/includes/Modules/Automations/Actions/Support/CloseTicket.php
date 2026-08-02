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
 * CloseTicket action stub.
 */
class CloseTicket extends ProAutomationStubAction {

	public $name = 'Close a ticket';

	public $slug = 'close_ticket';

	public $description = 'This action will close a support ticket for the contact.';

	public $source = 'support';

	public $group = 'support';
}

CloseTicket::instance();
