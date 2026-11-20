<?php
/**
 * Class Status
 *
 * This class is responsible for handling the contact status rule
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
 * Status class
 */
class Status extends Filter {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Status';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'contact_status';

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
	public $type = 'select';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'is'     => __( 'Is', 'doublescale' ),
			'is_not' => __( 'Is not', 'doublescale' ),
		);
	}

	/**
	 * The email subscription status.
	 *
	 * Contacts lost their single `status` column when subscriptions split per
	 * channel (email / SMS / WhatsApp); querying it was an SQL error for every
	 * value. The automation "Status" rule reads email_status too.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function get_column() {
		return 'email_status';
	}

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		return array(
			'subscribed'   => __( 'Subscribed', 'doublescale' ),
			'unsubscribed' => __( 'Unsubscribed', 'doublescale' ),
			'unverified'   => __( 'Unverified', 'doublescale' ),
			'bounced'      => __( 'Bounced', 'doublescale' ),
			'blocked'      => __( 'Blocked', 'doublescale' ),
		);
	}
}

FiltersManager::instance()->register( new Status() );
