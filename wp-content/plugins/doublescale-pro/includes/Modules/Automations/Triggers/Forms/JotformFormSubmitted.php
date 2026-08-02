<?php
/**
 * Jotform — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class JotformFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Jotform';

	public $slug = 'jotform';

	public $description = 'Runs when a Jotform submission is received.';

	public $is_pro = true;

	public $attributes = array();

	public $group = 'jotform';
}
