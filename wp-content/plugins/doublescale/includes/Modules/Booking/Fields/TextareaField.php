<?php
/**
 * Class Textarea_Field
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Fields;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Fields\TextField;

/**
 * Textarea_Field class
 */
class TextareaField extends TextField {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Textarea Field';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'textarea';
}
