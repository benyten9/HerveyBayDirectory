<?php

/**
 * Forms Metadata Merge Tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\MergeTags\Forms;

// Load parent class if not already loaded
if ( ! class_exists( 'DoubleScale\\Pro\Modules\Forms\MergeTags\Forms\FormsMetadata' ) ) {
	require_once __DIR__ . '/FormsMetadata.php';
}

// Prevent multiple class declarations
if ( ! class_exists( 'DoubleScale\\Pro\Modules\Forms\MergeTags\Forms\FormsMetadataBackEnd' ) ) {

	/**
	 * Forms Metadata Backend Merge Tag
	 *
	 * Extends FormsMetadata with backend-specific functionality
	 */
	class FormsMetadataBackEnd extends FormsMetadata {



		/**
		 * Required Triggers
		 *
		 * @var array
		 */
		public $required_triggers = array( 'ttt' );

		/**
		 * Constructor
		 *
		 * @param string $group Group.
		 */
		public function __construct( $group ) {
			// Call parent constructor with generic parameters for backend usage
			parent::__construct( '', __( 'Form Metadata', 'doublescale'), $group );

			// Override slug for backend generic metadata tag
			$this->slug = 'metadata:';
		}
	}
}
