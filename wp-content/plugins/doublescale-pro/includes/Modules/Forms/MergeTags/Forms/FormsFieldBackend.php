<?php

/**
 * Forms Field Merge Tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Forms\MergeTags\Forms;

// Load parent class if not already loaded
if ( ! class_exists( 'DoubleScale\\Pro\Modules\Forms\MergeTags\Forms\FormsField' ) ) {
	require_once __DIR__ . '/FormsField.php';
}

// Prevent multiple class declarations
if ( ! class_exists( 'DoubleScale\\Pro\Modules\Forms\MergeTags\Forms\FormsFieldBackend' ) ) {

	/**
	 * Forms Field Backend Merge Tag
	 *
	 * Extends FormsField with backend-specific functionality
	 */
	class FormsFieldBackend extends FormsField {



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
			parent::__construct( '', __( 'Form Field', 'doublescale'), $group );

			// Override slug for backend generic field tag
			$this->slug = 'field:';
		}
	}
}
