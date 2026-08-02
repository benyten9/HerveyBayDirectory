<?php
/**
 * Entity Properties
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Entity Properties
 */
trait EntityProperties {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Description
	 *
	 * @var string
	 */
	public $description;
}
