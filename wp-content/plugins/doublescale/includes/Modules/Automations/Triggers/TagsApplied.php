<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;
use DoubleScale\Modules\Automations\Triggers\TagsAppliedDocs;

/**
 * TagsApplied trigger stub.
 */
class TagsApplied extends TriggerPro {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Tags Applied';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'tags_applied';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Fires when selected tags are applied to a contact. Ideal for chaining automations — see the guide when you select this trigger.';

	/**
	 * @var bool
	 */
	public $is_featured = true;

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * @return array{title: string, intro: string, steps: array<int, string>, tip: string}
	 */
	public function get_documentation() {
		return TagsAppliedDocs::get();
	}

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
}

TriggersManager::instance()->register( new TagsApplied() );
