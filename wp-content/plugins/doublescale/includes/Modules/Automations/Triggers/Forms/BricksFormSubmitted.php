<?php
/**
 * Pro automation trigger (free plugin): Bricks — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class BricksFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'Bricks';

	public $slug = 'bricks';

	public $description = 'Runs when a Bricks Builder form is submitted.';

	public $attributes = array();

	public $group = 'bricks';
}

TriggersManager::instance()->register( new BricksFormSubmitted() );
