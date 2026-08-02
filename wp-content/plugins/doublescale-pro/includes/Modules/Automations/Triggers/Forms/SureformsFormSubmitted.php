<?php
/**
 * SureForms — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class SureformsFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'SureForms';

	public $slug = 'sureforms';

	public $description = 'Runs when a SureForms form is submitted.';

	public $attributes = array();

	public $group = 'sureforms';
}
