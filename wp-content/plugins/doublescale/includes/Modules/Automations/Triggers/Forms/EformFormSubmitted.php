<?php
/**
 * Pro automation trigger (free plugin): eForm — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class EformFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'eForm';

	public $slug = 'eform';

	public $description = 'Runs when an eForm form is submitted.';

	public $attributes = array();

	public $group = 'eform';
}

TriggersManager::instance()->register( new EformFormSubmitted() );
