<?php
/**
 * Bit Form — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class BitformFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Bit Form';

	public $slug = 'bitform';

	public $description = 'Runs when a Bit Form form is submitted.';

	public $attributes = array();

	public $group = 'bitform';
}
