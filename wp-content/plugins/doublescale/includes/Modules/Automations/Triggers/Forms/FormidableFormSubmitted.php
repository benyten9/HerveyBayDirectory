<?php
/**
 * Pro automation trigger (free plugin): Formidable — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class FormidableFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'Formidable';

	public $slug = 'formidable';

	public $description = 'Runs when a Formidable form is submitted.';

	public $attributes = array();

	public $group = 'formidable';
}

TriggersManager::instance()->register( new FormidableFormSubmitted() );
