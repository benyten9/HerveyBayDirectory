<?php
/**
 * Formidable — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class FormidableFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Formidable';

	public $slug = 'formidable';

	public $description = 'Runs when a Formidable form is submitted.';

	public $attributes = array();

	public $group = 'formidable';
}
