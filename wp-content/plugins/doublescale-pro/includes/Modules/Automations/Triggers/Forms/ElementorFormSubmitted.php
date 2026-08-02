<?php
/**
 * Elementor — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class ElementorFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Elementor';

	public $slug = 'elementor';

	public $description = 'Runs when an Elementor Pro form is submitted.';

	public $attributes = array();

	public $group = 'elementor';
}
