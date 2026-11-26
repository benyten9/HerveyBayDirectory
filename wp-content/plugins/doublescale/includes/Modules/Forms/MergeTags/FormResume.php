<?php
/**
 * {{forms:resume 12}} — a link back to DoubleScale form 12 with the answers the contact
 * already gave, for "you didn't finish" emails, SMS and WhatsApp (Pro partial entries).
 *
 * The link carries the random resume token of the contact's latest unfinished entry (valid
 * 30 days). With no unfinished entry it falls back to {{forms:link 12}}. With no form id,
 * the form that started the automation is used.
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Native\Partial;

/**
 * Resume link merge tag.
 */
class FormResume extends MergeTag {

	public $name = 'Form resume link';

	public $slug = 'resume';

	public $group = 'forms';

	public $description = 'Link back to an unfinished DoubleScale form with the answers already given. Add the form ID: {{forms:resume 12}}.';

	public $is_automation = false;

	public $plain_text = true;

	/**
	 * Value for a contact or an automation contact.
	 *
	 * @param mixed  $contact_or_automation_contact Contact or automation contact.
	 * @param string $merge_tag                     "resume 12".
	 * @return string
	 */
	public function get_tag_value( $contact_or_automation_contact, $merge_tag = '' ) {
		$form_id = $this->form_id_from( (string) $merge_tag );
		$contact = $contact_or_automation_contact;
		if ( $contact_or_automation_contact instanceof AutomationContactModel ) {
			if ( ! $form_id ) {
				$data    = is_array( $contact_or_automation_contact->data ) ? $contact_or_automation_contact->data : array();
				$form_id = (int) ( $data['form_id'] ?? 0 );
			}
			$contact = $contact_or_automation_contact->contact;
		}
		return $this->link( $form_id, $contact, (string) $merge_tag );
	}

	/**
	 * Value for a plain contact.
	 *
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag "resume 12".
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		return $this->link( $this->form_id_from( (string) $merge_tag ), $contact, (string) $merge_tag );
	}

	/**
	 * Form id (or link slug) after the tag name.
	 *
	 * @param string $merge_tag "resume 12" or "resume contact-us".
	 * @return int
	 */
	private function form_id_from( string $merge_tag ): int {
		$parts = preg_split( '/\s+/', trim( $merge_tag ) );
		$key   = isset( $parts[1] ) ? (string) $parts[1] : '';
		if ( '' === $key ) {
			return 0;
		}
		if ( ctype_digit( $key ) ) {
			return (int) $key;
		}
		if ( ! doublescale_module_data_ready( 'forms' ) ) {
			return 0;
		}
		$form = FormModel::find_native_by_slug( sanitize_title( $key ) );
		return $form ? (int) $form->id : 0;
	}

	/**
	 * The resume link, or the form's prefilled link when nothing is unfinished.
	 *
	 * @param int    $form_id   Form id.
	 * @param mixed  $contact   Contact or null.
	 * @param string $merge_tag Tag text.
	 * @return string
	 */
	private function link( int $form_id, $contact, string $merge_tag ): string {
		if ( $form_id <= 0 || ! doublescale_module_data_ready( 'forms' ) ) {
			return '';
		}
		$form = FormModel::find( $form_id );
		if ( ! $form || ! $form->is_native() || 'active' !== $form->status ) {
			return '';
		}
		$contact_id = is_object( $contact ) ? (int) ( $contact->id ?? 0 ) : 0;
		$resume     = Partial::resume_link( $contact_id, $form_id );
		if ( '' !== $resume ) {
			return $resume;
		}
		return ( new FormLink() )->get_value( $contact, 'link ' . $form_id );
	}
}

// Unfinished entries are off for now: no resume links to offer.
if ( \DoubleScale\Modules\Forms\Native\Partial::available() ) {
	MergeTagsManager::instance()->register( new FormResume() );
}
