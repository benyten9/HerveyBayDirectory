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

class EmailReceived extends TriggerPro {

	public $name = 'Email Received';

	public $slug = 'email_received';

	public $description = 'Triggers when an email is received from a contact';

	public $attributes = array();

	public $source = 'crm';

	public $group = 'messaging';
}

TriggersManager::instance()->register( new EmailReceived() );
