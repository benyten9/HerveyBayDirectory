<?php
/**
 * WPForms — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class WpformsFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'WPForms';

	public $slug = 'wpforms';

	public $description = 'Runs when a WPForms form is submitted.';

	public $attributes = array();

	public $group = 'wpforms';
}
