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
 * TicketReplyAdded trigger stub.
 */
class TicketReplyAdded extends TriggerPro {

	public $name = 'Ticket reply added';

	public $slug = 'ticket_reply_added';

	public $description = 'Fires when a reply is posted to a support ticket.';

	public $attributes = array();

	public $source = 'support';

	public $group = 'support';
}

TriggersManager::instance()->register( new TicketReplyAdded() );
