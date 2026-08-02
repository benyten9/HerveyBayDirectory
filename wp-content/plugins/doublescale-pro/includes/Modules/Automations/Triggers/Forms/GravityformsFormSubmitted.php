<?php
/**
 * Gravity Forms — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class GravityformsFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'GravityForms';

	public $slug = 'gravityforms';

	public $description = 'Runs when a Gravity Forms form is submitted.';

	public $attributes = array();

	public $group = 'gravityforms';
}
