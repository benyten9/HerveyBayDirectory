<?php

/**
 * Class Merge Tag
 *
 * Abstract class for merge tags
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\MergeTags\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Merge Tag class
 */
abstract class MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Merge Tag Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Merge Tag Description
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Required Triggers
	 *
	 * @var array
	 */
	public $required_triggers = array();

	/**
	 * Merge Tag Group
	 *
	 * @var string
	 */
	public $group;

	/**
	 * Is automation merge tag
	 *
	 * @var bool
	 */
	public $is_automation = true;

	/**
	 * Get Merge Tag Value
	 *
	 * @param AutomationContactModel|ContactModel|null $contact Contact Model.
	 * @param string                                   $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	abstract public function get_value( $contact, $merge_tag = '' );

	/**
	 * Is Automation Contact
	 *
	 * @param AutomationContactModel|ContactModel|null $contact Contact Model.
	 *
	 * @return bool
	 */
	public function is_automation_contact( $contact ) {
		return $contact instanceof AutomationContactModel;
	}

	/**
	 * Get tag value
	 *
	 * @param AutomationContactModel|ContactModel|null $contact_or_automation_contact.
	 * @param string                                   $merge_tag Merge Tag.
	 *
	 * @return string
	 */
	public function get_tag_value( $contact_or_automation_contact, $merge_tag = '' ) {
		if ( $this->is_automation && ! $this->is_automation_contact( $contact_or_automation_contact ) ) {
			return '';
		}

		if ( ! $this->is_automation && $this->is_automation_contact( $contact_or_automation_contact ) ) {
			return $this->get_value( $contact_or_automation_contact->contact, $merge_tag );
		}

		return $this->get_value( $contact_or_automation_contact, $merge_tag );
	}
}
