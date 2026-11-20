<?php
/**
 * Bricks — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class BricksFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Bricks';

	public $slug = 'bricks';

	public $description = 'Runs when a Bricks Builder form is submitted.';

	public $attributes = array();

	public $group = 'bricks';
}
