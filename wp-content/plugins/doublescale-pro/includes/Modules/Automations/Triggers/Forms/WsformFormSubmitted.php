<?php
/**
 * WS Form — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class WsformFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'WS Form';

	public $slug = 'wsform';

	public $description = 'Runs when a WS Form form is submitted.';

	public $attributes = array();

	public $group = 'wsform';
}
