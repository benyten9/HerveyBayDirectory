<?php
/**
 * Typeform — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class TypeformFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Typeform';

	public $slug = 'typeform';

	public $description = 'Runs when a Typeform response is submitted.';

	public $is_pro = true;

	public $attributes = array();

	public $group = 'typeform';
}
