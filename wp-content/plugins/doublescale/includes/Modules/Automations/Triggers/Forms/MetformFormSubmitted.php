<?php
/**
 * Pro automation trigger (free plugin): MetForm — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class MetformFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'MetForm';

	public $slug = 'metform';

	public $description = 'Runs when a MetForm form is submitted.';

	public $attributes = array();

	public $group = 'metform';
}

TriggersManager::instance()->register( new MetformFormSubmitted() );
