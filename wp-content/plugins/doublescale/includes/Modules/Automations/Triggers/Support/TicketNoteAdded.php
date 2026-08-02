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
 * TicketNoteAdded trigger stub.
 */
class TicketNoteAdded extends TriggerPro {

	public $name = 'Ticket note added';

	public $slug = 'ticket_note_added';

	public $description = 'Fires when an internal note is added to a support ticket.';

	public $attributes = array();

	public $source = 'support';

	public $group = 'support';
}

TriggersManager::instance()->register( new TicketNoteAdded() );
