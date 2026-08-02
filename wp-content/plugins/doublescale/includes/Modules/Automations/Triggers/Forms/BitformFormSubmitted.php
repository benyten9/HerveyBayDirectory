<?php
/**
 * Pro automation trigger (free plugin): Bit Form — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class BitformFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'Bit Form';

	public $slug = 'bitform';

	public $description = 'Runs when a Bit Form form is submitted.';

	public $attributes = array();

	public $group = 'bitform';
}

TriggersManager::instance()->register( new BitformFormSubmitted() );
