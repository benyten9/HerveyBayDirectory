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
 * DealStageChange trigger stub.
 */
class DealStageChange extends TriggerPro {

	public $name = 'Deal Stage changes';

	public $slug = 'deal_stage_change';

	public $description = 'This trigger will be fired when a deal stage is changed.';

	public $attributes = array();

	public $source = 'sales';

	public $group = 'deal';
}

TriggersManager::instance()->register( new DealStageChange() );
