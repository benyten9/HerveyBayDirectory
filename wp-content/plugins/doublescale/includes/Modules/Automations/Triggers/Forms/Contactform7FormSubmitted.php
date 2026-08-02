<?php
/**
 * Free automation trigger: Contact Form 7.
 *
 * Ships in the free plugin — not locked behind Pro.
 * The actual submission hook is handled by {@see \DoubleScale\Modules\Forms\Contactform7\Form}.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class Contactform7FormSubmitted extends Trigger {

	public $source = 'forms';

	public $name = 'Contact Form 7';

	public $slug = 'contactform7';

	public $description = 'Runs when a Contact Form 7 form is submitted.';

	public $attributes = array();

	public $group = 'contactform7';

	public function load_hooks() {}

	public function get_fields() {
		return array();
	}
}

TriggersManager::instance()->register( new Contactform7FormSubmitted() );
