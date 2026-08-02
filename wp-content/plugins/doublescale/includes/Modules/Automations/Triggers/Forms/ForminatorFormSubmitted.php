<?php
/**
 * Pro automation trigger (free plugin): Forminator — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class ForminatorFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'Forminator';

	public $slug = 'forminator';

	public $description = 'Runs when a Forminator form is submitted.';

	public $attributes = array();

	public $group = 'forminator';
}

TriggersManager::instance()->register( new ForminatorFormSubmitted() );
