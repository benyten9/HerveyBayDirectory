<?php
/**
 * Pro automation trigger (free plugin): Ninja Forms — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class NinjaformsFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'NinjaForms';

	public $slug = 'ninjaforms';

	public $description = 'Runs when a Ninja Forms form is submitted.';

	public $attributes = array();

	public $group = 'ninjaforms';
}

TriggersManager::instance()->register( new NinjaformsFormSubmitted() );
