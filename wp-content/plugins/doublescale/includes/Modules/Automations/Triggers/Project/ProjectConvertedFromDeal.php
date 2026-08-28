<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Project;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * ProjectConvertedFromDeal trigger stub.
 */
class ProjectConvertedFromDeal extends TriggerPro {

	public $name = 'Project converted from deal';

	public $slug = 'project_converted_from_deal';

	public $description = 'Fires when a project is created by converting a deal. Projects without a client contact cannot enroll.';

	public $attributes = array();

	public $source = 'projects';

	public $group = 'project';
}

TriggersManager::instance()->register( new ProjectConvertedFromDeal() );
