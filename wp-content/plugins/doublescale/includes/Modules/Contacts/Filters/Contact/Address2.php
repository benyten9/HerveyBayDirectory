<?php
/**
 * Class Address2
 *
 * This class is responsible for handling the contact address 2 rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\Filters\Contact;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Filters\FiltersManager;

/**
 * Address2 class
 */
class Address2 extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Address 2';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'contact_address_2';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'contact';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'text';
}

FiltersManager::instance()->register( new Address2() );
