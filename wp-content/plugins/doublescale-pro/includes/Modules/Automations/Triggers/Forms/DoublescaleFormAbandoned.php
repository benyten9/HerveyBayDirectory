<?php
/**
 * "Form abandoned": someone started a DoubleScale form and left without sending it.
 *
 * Fired by {@see \DoubleScale\Modules\Forms\Native\Partial::abandon()} once per unfinished
 * entry, after the form's "abandoned after" time, for the person behind it (an existing
 * contact, or a new one when the form saves unfinished contacts).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Forms;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Form abandoned trigger.
 */
class DoublescaleFormAbandoned extends Trigger {

	public $name = 'Form abandoned';

	public $slug = 'doublescale_form_abandoned';

	public $description = 'Runs when someone starts a DoubleScale form and leaves without sending it.';

	public $attributes = array();

	public $source = 'forms';

	public $group = 'doublescale';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function load_hooks() {
		$this->name        = __( 'Form abandoned', 'doublescale' );
		$this->description = __( 'Runs when someone starts a DoubleScale form and leaves without sending it.', 'doublescale' );
		add_action( 'doublescale_native_form_abandoned', array( $this, 'abandoned' ), 10, 3 );
	}

	/**
	 * Run for an abandoned entry.
	 *
	 * @param mixed $submission Partial entry.
	 * @param mixed $form       Form.
	 * @param mixed $contact    Contact or null.
	 * @return void
	 */
	public function abandoned( $submission, $form, $contact ) {
		if ( ! $contact instanceof ContactModel || ! is_object( $form ) || ! is_object( $submission ) ) {
			return;
		}
		$this->process(
			array(
				'contact' => $contact,
				'data'    => array(
					'form_id'       => (int) $form->id,
					'form_title'    => (string) $form->name,
					'submission_id' => (int) $submission->id,
					'entry_id'      => (int) $submission->id,
				),
			)
		);
	}

	/**
	 * Only for the chosen form (none chosen: every form).
	 *
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Trigger args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$chosen = (int) $automation->get_setting( 'form_id' );
		return $chosen <= 0 || (int) ( $args['data']['form_id'] ?? 0 ) === $chosen;
	}

	/**
	 * Fields.
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
