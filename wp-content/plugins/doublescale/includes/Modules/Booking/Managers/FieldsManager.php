<?php
/**
 * Class FieldsManager
 *
 * @since 1.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Managers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\FieldType;
use DoubleScale\Modules\Booking\Fields\CheckboxField;
use DoubleScale\Modules\Booking\Fields\TextField;
use DoubleScale\Modules\Booking\Fields\EmailField;
use DoubleScale\Modules\Booking\Fields\NumberField;
use DoubleScale\Modules\Booking\Fields\RadioField;
use DoubleScale\Modules\Booking\Fields\TextareaField;
use DoubleScale\Modules\Booking\Fields\SelectField;
use DoubleScale\Modules\Booking\Fields\MultipleSelectField;
use DoubleScale\Modules\Booking\Fields\PhoneField;
use DoubleScale\Modules\Booking\Fields\TermsField;
use DoubleScale\Modules\Booking\Fields\TimeField;
use DoubleScale\Modules\Booking\Fields\UrlField;
use DoubleScale\Modules\Booking\Traits\Singleton;

class FieldsManager extends \DoubleScale\Modules\Booking\Abstracts\Manager {

	use \DoubleScale\Modules\Booking\Traits\Singleton;

	public function __construct() {
		$this->register_fields();
	}

	public function register_fields() {
		$fields = array(
			CheckboxField::class,
			TextField::class,
			EmailField::class,
			NumberField::class,
			RadioField::class,
			TextareaField::class,
			SelectField::class,
			PhoneField::class,
			UrlField::class,
			TimeField::class,
			TermsField::class,
			MultipleSelectField::class,
		);

		foreach ( $fields as $field ) {
			$this->register(
				new $field(),
				FieldType::class,
				'slug',
				array(
					'name'        => 'name',
					'has_options' => 'has_options',
					'multiple'    => 'multiple',
				)
			);
		}
	}
}
