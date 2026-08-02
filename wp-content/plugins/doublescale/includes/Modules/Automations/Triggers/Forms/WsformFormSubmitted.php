<?php
/**
 * Pro automation trigger (free plugin): WS Form — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class WsformFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'WS Form';

	public $slug = 'wsform';

	public $description = 'Runs when a WS Form form is submitted.';

	public $attributes = array();

	public $group = 'wsform';
}

TriggersManager::instance()->register( new WsformFormSubmitted() );
