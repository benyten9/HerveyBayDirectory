<?php
/**
 * {{forms:link 12}} — the link of DoubleScale form 12, for emails, SMS and WhatsApp.
 *
 * The link carries a signed token (dsf_c) for this contact and this form, valid 30 days, so
 * the form opens with their details filled in; no personal data goes into the address. With
 * no form id, the form that started the automation is used.
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\MergeTags;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Native\Renderer;

/**
 * Form link merge tag.
 */
class FormLink extends MergeTag {

	public $name = 'Form link';

	public $slug = 'link';

	public $group = 'forms';

	public $description = 'Link to a DoubleScale form, filled in for the contact. Add the form ID: {{forms:link 12}}.';

	public $is_automation = false;

	public $plain_text = true;

	/**
	 * Get the tag value for a contact or an automation contact.
	 *
	 * Works in campaigns too, so it reads the automation's form when it has one.
	 *
	 * @param mixed  $contact_or_automation_contact Contact or automation contact.
	 * @param string $merge_tag                     "link 12".
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
		return $this->link( $form_id, $contact );
	}

	/**
	 * Value for a plain contact.
	 *
	 * @param mixed  $contact   Contact.
	 * @param string $merge_tag "link 12".
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		return $this->link( $this->form_id_from( (string) $merge_tag ), $contact );
	}

	/**
	 * Form id (or link slug) after the tag name.
	 *
	 * @param string $merge_tag "link 12" or "link contact-us".
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
	 * The form's link, with the contact's hash.
	 *
	 * @param int   $form_id Form id.
	 * @param mixed $contact Contact or null.
	 * @return string
	 */
	private function link( int $form_id, $contact ): string {
		if ( $form_id <= 0 || ! doublescale_module_data_ready( 'forms' ) ) {
			return '';
		}
		$form = FormModel::find( $form_id );
		if ( ! $form || ! $form->is_native() || 'active' !== $form->status ) {
			return '';
		}
		$url        = Renderer::link( $form );
		$contact_id = is_object( $contact ) ? (int) ( $contact->id ?? 0 ) : 0;
		return $contact_id > 0 ? add_query_arg( Renderer::CONTACT_PARAM, \DoubleScale\Modules\Forms\Native\Tokens::prefill( $contact_id, (int) $form->id ), $url ) : $url;
	}
}

MergeTagsManager::instance()->register( new FormLink() );
