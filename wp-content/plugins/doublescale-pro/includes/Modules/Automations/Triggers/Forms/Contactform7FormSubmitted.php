<?php
/**
 * Contact Form 7 — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class Contactform7FormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Contact Form 7';

	public $slug = 'contactform7';

	public $description = 'Runs when a Contact Form 7 form is submitted.';

	public $attributes = array();

	public $group = 'contactform7';
}
