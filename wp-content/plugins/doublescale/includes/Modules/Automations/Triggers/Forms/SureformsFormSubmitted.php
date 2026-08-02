<?php
/**
 * Pro automation trigger (free plugin): SureForms — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class SureformsFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'SureForms';

	public $slug = 'sureforms';

	public $description = 'Runs when a SureForms form is submitted.';

	public $attributes = array();

	public $group = 'sureforms';
}

TriggersManager::instance()->register( new SureformsFormSubmitted() );
