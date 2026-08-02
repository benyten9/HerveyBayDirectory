<?php
/**
 * Form integration automation trigger — definition only in the free plugin.
 *
 * DoubleScale Pro replaces these with full {@see \DoubleScale\Modules\Automations\Abstracts\Trigger}
 * implementations via {@see ProAutomationCatalog} (same pattern as Deal / Messaging stubs).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;

defined( 'ABSPATH' ) || exit;

abstract class AbstractFormSubmittedTrigger extends TriggerPro {

	public $source = 'forms';

	/**
	 * Slugs registered by {@see \DoubleScale\Pro\Modules\Forms\Module::register_forms()}.
	 * Used so {@see \DoubleScale\Modules\Automations\Services\TriggersManager::set_forms_sources()}
	 * does not duplicate the same rows.
	 *
	 * @return string[]
	 */
	public static function integration_slugs(): array {
		return array(
			'bitform',
			'contactform7',
			'elementor',
			'fluentforms',
			'formidable',
			'forminator',
			'gravityforms',
			'metform',
			'ninjaforms',
			'quillforms',
			'sureforms',
			'wpforms',
			'wsform',
			'eform',
			'jetformbuilder',
			'typeform',
			'jotform',
		);
	}
}
