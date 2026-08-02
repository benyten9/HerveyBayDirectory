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
 * DealOwnerChange trigger stub.
 */
class DealOwnerChange extends TriggerPro {

	public $name = 'Deal Owner changes';

	public $slug = 'deal_owner_change';

	public $description = 'This trigger will be fired when a deal owner is changed.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'deal';
}

TriggersManager::instance()->register( new DealOwnerChange() );
