<?php

/**
 * Class Contact WhatsApp Merge Tag
 *
 * Merge tag for contact whatsapp
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\MergeTags\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Contact WhatsApp Merge Tag
 */
class ContactWhatsapp extends MergeTag {


	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name = 'Contact WhatsApp';

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug = 'whatsapp';

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description = 'Contact WhatsApp';

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * Is automation merge tag
	 *
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel $contact Contact Model.
	 * @param string                 $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( is_null( $contact ) ) {
			return '';
		}

		return $contact->whatsapp_phone;
	}
}

MergeTagsManager::instance()->register( new ContactWhatsapp() );
