<?php
/**
 * Free automation trigger: WPForms.
 *
 * Ships in the free plugin — not locked behind Pro.
 * The actual submission hook is handled by {@see \DoubleScale\Modules\Forms\Wpforms\Form}.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class WpformsFormSubmitted extends Trigger {

	public $source = 'forms';

	public $name = 'WPForms';

	public $slug = 'wpforms';

	public $description = 'Runs when a WPForms form is submitted.';

	public $attributes = array();

	public $group = 'wpforms';

	public function load_hooks() {}

	public function get_fields() {
		return array();
	}
}

TriggersManager::instance()->register( new WpformsFormSubmitted() );
