<?php
/**
 * Contact Custom Field merge tag.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Modules\Contacts\MergeTags\Contact
 */

namespace DoubleScale\Modules\Contacts\MergeTags\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;

/**
 * Renders {{contact:contact_field:…}} merge tags from custom field definitions.
 */
class ContactCustomField extends MergeTag {

	/**
	 * Merge Tag Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Merge Tag Slug (includes contact_field: prefix for lookup).
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
	 * Constructor
	 *
	 * @param CustomFieldModel $custom_field Custom field definition.
	 */
	public function __construct( CustomFieldModel $custom_field ) {
		$this->name        = $custom_field->name;
		$this->slug        = 'contact_field:' . $custom_field->slug;
		$this->description = $custom_field->name;
	}

	/**
	 * Get Merge Tag Value
	 *
	 * @param ContactModel $contact Contact Model.
	 * @param string       $merge_tag Full slug segment from the template (may include contact_field: prefix).
	 *
	 * @return string
	 */
	public function get_value( $contact, $merge_tag = '' ) {
		if ( is_null( $contact ) ) {
			return '';
		}

		$key             = str_replace( 'contact_field:', '', $merge_tag );
		$custom_field_id = CustomFieldModel::get_id( $key );
		$custom_field    = $contact->get_custom_field( $custom_field_id );

		if ( $custom_field ) {
			return $custom_field;
		}

		return '';
	}
}

if ( class_exists( CustomFieldModel::class ) ) {
	( static function () {
		try {
			foreach ( CustomFieldModel::all() as $custom_field ) {
				MergeTagsManager::instance()->register( new ContactCustomField( $custom_field ) );
			}
		} catch ( \Throwable $e ) {
			// Custom field tables may not exist yet on first load before migrations run.
			if ( function_exists( 'doublescale_get_logger' ) ) {
				$log = doublescale_get_logger();
				if ( is_object( $log ) && method_exists( $log, 'debug' ) ) {
					$log->debug(
						'Contact custom field merge tags skipped',
						array(
							'code'    => 'contact_custom_field_merge_tags_init',
							'message' => $e->getMessage(),
						)
					);
				}
			}
		}
	} )();
}
