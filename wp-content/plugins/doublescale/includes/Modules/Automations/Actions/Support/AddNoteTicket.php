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
 * AddNoteTicket action stub.
 */
class AddNoteTicket extends ProAutomationStubAction {

	public $name = 'Add a ticket note';

	public $slug = 'add_note_ticket';

	public $description = 'This action will add an internal note to a support ticket.';

	public $source = 'support';

	public $group = 'support';
}

AddNoteTicket::instance();
