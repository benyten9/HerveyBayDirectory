<?php
/**
 * Forminator — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class ForminatorFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Forminator';

	public $slug = 'forminator';

	public $description = 'Runs when a Forminator form is submitted.';

	public $attributes = array();

	public $group = 'forminator';
}
