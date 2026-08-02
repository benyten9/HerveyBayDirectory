<?php
/**
 * Base automation trigger for a form integration (runtime handled by {@see FormsManager} integrations).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Forms\Services\FormsManager;

defined( 'ABSPATH' ) || exit;

abstract class AbstractFormIntegrationTrigger extends Trigger {

	public $source = 'forms';

	public function load_hooks() {
		// Submission wiring lives on each {@see \DoubleScale\Modules\Forms\Abstracts\Form} subclass.
	}

	public function get_fields() {
		if ( ! class_exists( FormsManager::class ) ) {
			return array();
		}
		$form = FormsManager::instance()->get_form( $this->slug );
		if ( ! $form ) {
			return array();
		}

		return $form->get_form_options();
	}
}
