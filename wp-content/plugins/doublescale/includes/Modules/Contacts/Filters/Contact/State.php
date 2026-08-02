<?php
/**
 * Class State
 *
 * This class is responsible for handling the contact state rule
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
 * State class
 */
class State extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'State';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'contact_state';

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

FiltersManager::instance()->register( new State() );
