<?php
/**
 * MetForm — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class MetformFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'MetForm';

	public $slug = 'metform';

	public $description = 'Runs when a MetForm form is submitted.';

	public $attributes = array();

	public $group = 'metform';
}
