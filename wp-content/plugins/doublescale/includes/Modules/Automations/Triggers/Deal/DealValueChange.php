<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Deal;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * DealValueChange trigger stub.
 */
class DealValueChange extends TriggerPro {

	public $name = 'Deal Value changes';

	public $slug = 'deal_value_change';

	public $description = 'This trigger will be fired when a deal value is changed.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'deal';
}

TriggersManager::instance()->register( new DealValueChange() );
