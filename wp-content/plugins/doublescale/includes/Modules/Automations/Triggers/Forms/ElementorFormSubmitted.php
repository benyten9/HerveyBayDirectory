<?php
/**
 * Pro automation trigger (free plugin): Elementor — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class ElementorFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'Elementor';

	public $slug = 'elementor';

	public $description = 'Runs when an Elementor Pro form is submitted.';

	public $attributes = array();

	public $group = 'elementor';
}

TriggersManager::instance()->register( new ElementorFormSubmitted() );
