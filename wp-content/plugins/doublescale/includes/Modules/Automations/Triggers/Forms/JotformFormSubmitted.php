<?php
/**
 * Pro automation trigger (free plugin): Jotform — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class JotformFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'Jotform';

	public $slug = 'jotform';

	public $description = 'Runs when a Jotform submission is received.';

	public $attributes = array();

	public $group = 'jotform';
}

TriggersManager::instance()->register( new JotformFormSubmitted() );
