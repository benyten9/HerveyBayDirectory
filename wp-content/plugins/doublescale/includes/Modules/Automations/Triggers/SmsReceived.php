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

class SmsReceived extends TriggerPro {

	public $name = 'SMS Received';

	public $slug = 'sms_received';

	public $description = 'Triggers when an SMS is received from a contact';

	public $attributes = array();

	public $source = 'messaging';

	public $group = 'messaging';
}

TriggersManager::instance()->register( new SmsReceived() );
