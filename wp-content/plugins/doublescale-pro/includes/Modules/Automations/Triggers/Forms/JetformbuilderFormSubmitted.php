<?php
/**
 * JetFormBuilder — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class JetformbuilderFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'JetFormBuilder';

	public $slug = 'jetformbuilder';

	public $description = 'Runs when a JetFormBuilder form is submitted.';

	public $attributes = array();

	public $group = 'jetformbuilder';
}
