<?php

/**
 * Class FormFieldRule
 *
 * This class is responsible for handling form field rules
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Forms;

// Prevent multiple class declarations
if (! class_exists('DoubleScale\\Pro\Modules\Automations\Rules\Forms\FormFieldRuleBackend')) {

	/**
	 * FormFieldRuleBackend class
	 *
	 * Extends FormFieldRule with backend-specific functionality
	 */
	class FormFieldRuleBackend extends FormFieldRule
	{
		/**
		 * Required Triggers
		 *
		 * @var array
		 */
		public $required_triggers = array('ttt');
	}
}
