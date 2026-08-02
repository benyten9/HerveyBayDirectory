<?php
/**
 * eForm — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class EformFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'eForm';

	public $slug = 'eform';

	public $description = 'Runs when an eForm form is submitted.';

	public $attributes = array();

	public $group = 'eform';
}
