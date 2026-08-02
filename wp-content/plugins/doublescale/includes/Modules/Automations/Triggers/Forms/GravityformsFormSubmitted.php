<?php
/**
 * Pro automation trigger (free plugin): Gravity Forms — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class GravityformsFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'GravityForms';

	public $slug = 'gravityforms';

	public $description = 'Runs when a Gravity Forms form is submitted.';

	public $attributes = array();

	public $group = 'gravityforms';
}

TriggersManager::instance()->register( new GravityformsFormSubmitted() );
