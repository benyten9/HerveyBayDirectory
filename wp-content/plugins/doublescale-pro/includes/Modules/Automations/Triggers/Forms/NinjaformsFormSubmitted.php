<?php
/**
 * Ninja Forms — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class NinjaformsFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'NinjaForms';

	public $slug = 'ninjaforms';

	public $description = 'Runs when a Ninja Forms form is submitted.';

	public $attributes = array();

	public $group = 'ninjaforms';
}
