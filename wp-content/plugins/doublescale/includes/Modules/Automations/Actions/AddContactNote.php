<?php
/**
 * Add a note to the contact (title and text, with merge tags).
 *
 * Used to keep what another app sent on the contact's timeline, for example
 * a chat transcript or an AI summary from a webhook:
 * "{{webhook:field:summary}}".
 *
 * @package DoubleScale\Modules\Automations\Actions
 */

namespace DoubleScale\Modules\Automations\Actions;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Services\ActivityManager;
use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Add contact note action.
 */
class AddContactNote extends Action {

	/** @var string */
	public $name = 'Add a contact note';

	/** @var string */
	public $slug = 'add_contact_note';

	/** @var string */
	public $source = 'crm';

	/** @var string */
	public $group = 'contact';

	/** @var string */
	public $description = 'This action will add a note to the contact, for example a chat summary sent by another app.';

	/** @var array */
	public $attributes = array();

	/** Longest note kept, in characters (a long transcript is cut here). */
	const MAX_LENGTH = 20000;

	/**
	 * @param AutomationModel        $automation         Workflow.
	 * @param AutomationStepModel    $step               Step.
	 * @param AutomationContactModel $automation_contact Run.
	 * @return bool
	 */
	public function process_action( AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact ) {
		$contact = $automation_contact->contact;
		if ( ! $contact ) {
			return false;
		}

		$title = (string) $step->get_setting( 'title', '' );
		$note  = (string) $step->get_setting( 'note', '' );
		if ( false !== strpos( $title, '{{' ) ) {
			$title = (string) $this->merge_tags_manager->process_merge_tags( $title, $automation_contact );
		}
		if ( false !== strpos( $note, '{{' ) ) {
			$note = (string) $this->merge_tags_manager->process_merge_tags( $note, $automation_contact );
		}

		$title = trim( wp_strip_all_tags( $title ) );
		// Notes are plain text: keep line breaks, drop markup sent by other apps.
		$note = trim( wp_strip_all_tags( $note ) );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $note ) > self::MAX_LENGTH ) {
			$note = mb_substr( $note, 0, self::MAX_LENGTH ) . '…';
		}
		if ( '' === $title && '' === $note ) {
			// Every merge tag was empty: nothing worth a note, and not an error.
			return true;
		}

		$activity = ActivityManager::instance()->add_note(
			array(
				'contact_id' => (int) $contact->id,
				'title'      => $title,
				'content'    => nl2br( esc_html( $note ) ),
			),
			(int) ( $automation->created_by ?? 0 )
		);

		return (bool) $activity;
	}

	/**
	 * @return array
	 */
	public function get_fields() {
		return array(
			'title' => array(
				'label'       => __( 'Note title', 'doublescale' ),
				'type'        => 'text',
				'placeholder' => __( 'e.g. Chat summary', 'doublescale' ),
			),
			'note'  => array(
				'label' => __( 'Note', 'doublescale' ),
				'type'  => 'textarea',
			),
		);
	}

	/**
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title' => array(
					'type' => 'string',
				),
				'note'  => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}

AddContactNote::instance();
