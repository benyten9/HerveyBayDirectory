<?php
/**
 * Pro automation trigger (free plugin): JetFormBuilder — definition only.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Services\TriggersManager;

defined( 'ABSPATH' ) || exit;

final class JetformbuilderFormSubmitted extends AbstractFormSubmittedTrigger {

	public $name = 'JetFormBuilder';

	public $slug = 'jetformbuilder';

	public $description = 'Runs when a JetFormBuilder form is submitted.';

	public $attributes = array();

	public $group = 'jetformbuilder';
}

TriggersManager::instance()->register( new JetformbuilderFormSubmitted() );
