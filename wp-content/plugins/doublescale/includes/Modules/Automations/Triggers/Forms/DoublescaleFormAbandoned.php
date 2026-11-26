<?php
/**
 * "Form abandoned" (DoubleScale forms, Pro): definition only in the free plugin, so the
 * trigger shows in the picker with a Pro badge. DoubleScale Pro replaces it with the
 * runtime trigger of the same slug.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * Form abandoned stub.
 */
class DoublescaleFormAbandoned extends TriggerPro {

	public $name = 'Form abandoned';

	public $slug = 'doublescale_form_abandoned';

	public $description = 'Runs when someone starts a DoubleScale form and leaves without sending it.';

	public $attributes = array();

	public $source = 'forms';

	public $group = 'doublescale';

	/**
	 * Fields (mirrored from Pro).
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'form_id' => array(
				'label'       => __( 'Form', 'doublescale' ),
				'type'        => 'ajax_select',
				'ajax_action' => 'doublescale_doublescale_get_form_select_options',
				'helperText'  => __( 'Turn on “Save unfinished entries” in the form’s settings. Use {{forms:resume}} in your message to send them back to their answers.', 'doublescale' ),
			),
		);
	}
}

TriggersManager::instance()->register( new DoublescaleFormAbandoned() );
