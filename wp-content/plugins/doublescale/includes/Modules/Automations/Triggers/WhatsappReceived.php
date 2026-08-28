<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

class WhatsappReceived extends TriggerPro {

	public $name = 'Whatsapp Received';

	public $slug = 'whatsapp_received';

	public $description = 'Triggers when a WhatsApp message is received from a contact';

	public $attributes = array();

	public $source = 'messaging';

	public $group = 'messaging';
}

TriggersManager::instance()->register( new WhatsappReceived() );
