<?php
/**
 * Free automation trigger: Quill Forms.
 *
 * Ships in the free plugin — not locked behind Pro.
 * The actual submission hook is handled by {@see \DoubleScale\Modules\Forms\Quillforms\Form}.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class QuillformsFormSubmitted extends Trigger {

	public $source = 'forms';

	public $name = 'Quill Forms';

	public $slug = 'quillforms';

	public $description = 'Runs when a Quill Forms form is submitted.';

	public $attributes = array();

	public $group = 'quillforms';

	public function load_hooks() {}

	public function get_fields() {
		return array();
	}
}

TriggersManager::instance()->register( new QuillformsFormSubmitted() );
