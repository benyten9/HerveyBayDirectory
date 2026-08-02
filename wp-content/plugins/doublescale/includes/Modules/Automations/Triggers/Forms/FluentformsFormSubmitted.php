<?php
/**
 * Free automation trigger: Fluent Forms.
 *
 * Ships in the free plugin — not locked behind Pro.
 * The actual submission hook is handled by {@see \DoubleScale\Modules\Forms\Fluentforms\Form}.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class FluentformsFormSubmitted extends Trigger {

	public $source = 'forms';

	public $name = 'Fluent Forms';

	public $slug = 'fluentforms';

	public $description = 'Runs when a Fluent Forms form is submitted.';

	public $attributes = array();

	public $group = 'fluentforms';

	public function load_hooks() {}

	public function get_fields() {
		return array();
	}
}

TriggersManager::instance()->register( new FluentformsFormSubmitted() );
