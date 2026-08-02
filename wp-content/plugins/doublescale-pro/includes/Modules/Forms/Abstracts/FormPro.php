<?php

/**
 * Class Form
 * This class is responsible for handling the integration of forms
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */


namespace DoubleScale\Pro\Modules\Forms\Abstracts;

use DoubleScale\Modules\Forms\Abstracts\Form;

/**
 * Form class
 */
abstract class FormPro extends Form {


	public function __construct() {
		$this->is_pro = ! (
			function_exists( 'doublescale_is_pro_addon_active' ) && doublescale_is_pro_addon_active()
		);
	}

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @param string $form_id
	 *
	 * @return array
	 */
	public function get_fields( $form_id ) {
		return array();
	}
}
