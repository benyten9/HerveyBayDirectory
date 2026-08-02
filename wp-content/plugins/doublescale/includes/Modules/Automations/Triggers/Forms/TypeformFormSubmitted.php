<?php
/**
 * Pro automation trigger (free plugin): Typeform — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class TypeformFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'Typeform';

	public $slug = 'typeform';

	public $description = 'Runs when a Typeform response is submitted.';

	public $attributes = array();

	public $group = 'typeform';
}

TriggersManager::instance()->register( new TypeformFormSubmitted() );
