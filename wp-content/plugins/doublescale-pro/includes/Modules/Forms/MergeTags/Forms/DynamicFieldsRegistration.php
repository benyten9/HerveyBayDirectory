<?php

/**
 * Dynamic Forms Fields Registration
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\MergeTags\Forms;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Pro\Modules\Forms\MergeTags\Forms\FormsField;
use DoubleScale\Pro\Modules\Forms\MergeTags\Forms\FormsMetadata;

/**
 * Dynamic Forms Fields Registration
 */
class DynamicFieldsRegistration {


	/**
	 * Constructor
	 */
	public function __construct( $fields, $slug ) {
		$this->register_form_metadata_tags( $slug );
		$this->register_fields_from_data( $fields, $slug );
	}

	/**
	 * Register fields from automation data
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields Fields.
	 *
	 * @return void
	 */
	public function register_fields_from_data( $fields, $slug ) {
		if ( ! isset( $fields ) || ! is_array( $fields ) ) {
			return;
		}

		foreach ( $fields as $field_name => $field_label ) {
			$merge_tag = new FormsField( $field_name, $field_label['label'], $slug );
			MergeTagsManager::instance()->register( $merge_tag );
		}
	}

	/**
	 * Register form metadata merge tags (form_title, form_id)
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Form slug
	 *
	 * @return void
	 */
	public function register_form_metadata_tags( $slug ) {

		$title_merge_tag = new FormsMetadata( 'form_title', 'Form Title', $slug );
		MergeTagsManager::instance()->register( $title_merge_tag );

		$id_merge_tag = new FormsMetadata( 'form_id', 'Form ID', $slug );
		MergeTagsManager::instance()->register( $id_merge_tag );
	}
}
