<?php

/**
 * Forms Metadata Merge Tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\MergeTags\Forms;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

// Prevent multiple class declarations
if ( ! class_exists( 'DoubleScale\\Pro\Modules\Forms\MergeTags\Forms\FormsMetadata' ) ) {

	/**
	 * Forms Metadata Merge Tag
	 */
	class FormsMetadata extends MergeTag {




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
		 * Merge Tag Group
		 *
		 * @var string
		 */
		public $group;

		/**
		 * Field name
		 *
		 * @var string
		 */
		protected $metadata_name;

		/**
		 * Constructor
		 *
		 * @param string $metadata_name Field name.
		 * @param string $metadata_label Field label.
		 */
		public function __construct( $metadata_name, $metadata_label, $slug ) {
			$this->metadata_name = $metadata_name;
			$this->name          = $metadata_label;
			$this->slug          = "metadata:{$metadata_name}";
			$this->group         = $slug;
			/* translators: 1: Form type slug, 2: Metadata label */
			$this->description = sprintf( __( '%1$s metadata: %2$s', 'doublescale'), $slug, $metadata_label );
		}

		/**
		 * Get Merge Tag Value
		 *
		 * @param AutomationContactModel $contact Automation Contact Model.
		 * @param string                   $merge_tag Merge Tag.
		 *
		 * @return string
		 */
		public function get_value( $contact, $merge_tag = '' ) {
			if ( is_null( $contact ) || ! $contact->data ) {
				return '';
			}

			// Get the field name from the merge tag
			$field_key = str_replace( 'metadata:', '', $merge_tag );

			// Check if we have form entry data
			if ( isset( $contact->data[ $field_key ] ) ) {
				$field_value = $contact->data[ $field_key ];

				// Handle array values (for checkboxes, multi-select, etc.)
				if ( is_array( $field_value ) ) {
					return implode( ', ', $field_value );
				}

				return (string) $field_value;
			}

			return '';
		}
	}
}
