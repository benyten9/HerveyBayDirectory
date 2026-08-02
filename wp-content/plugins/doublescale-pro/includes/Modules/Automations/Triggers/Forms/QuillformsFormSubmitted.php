<?php
/**
 * Quill Forms — form submitted automation trigger (Pro).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

final class QuillformsFormSubmitted extends AbstractFormIntegrationTrigger {

	public $name = 'Quill Forms';

	public $slug = 'quillforms';

	public $description = 'Runs when a Quill Forms form is submitted.';

	public $attributes = array();

	public $group = 'quillforms';
}
