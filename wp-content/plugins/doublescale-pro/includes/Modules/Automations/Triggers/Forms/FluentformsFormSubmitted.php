<?php
/**
 * Fluent Forms — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class FluentformsFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Fluent Forms';

	public $slug = 'fluentforms';

	public $description = 'Runs when a Fluent Forms form is submitted.';

	public $attributes = array();

	public $group = 'fluentforms';
}
