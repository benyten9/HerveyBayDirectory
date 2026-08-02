<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Link;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * Link trigger clicked stub.
 */
class LinkTriggerClicked extends TriggerPro {

	public $name = 'Link Trigger Clicked';

	public $slug = 'link_trigger_clicked';

	public $description = 'This trigger will be fired when a link trigger is clicked.';

	public $attributes = array();

	public $source = 'crm';

	public $group = 'link_triggers';
}

TriggersManager::instance()->register( new LinkTriggerClicked() );
