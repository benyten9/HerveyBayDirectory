<?php
/**
 * SendGrid recipient value object (SDK drop-in for \SendGrid\Mail\To).
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SendGrid;

defined( 'ABSPATH' ) || exit;

/**
 * Recipient.
 *
 * @since 1.0.0
 */
class To {

	/**
	 * Email address.
	 *
	 * @var string
	 */
	protected $email;

	/**
	 * Display name.
	 *
	 * @var string|null
	 */
	protected $name;

	/**
	 * Constructor.
	 *
	 * @param string      $email Email address.
	 * @param string|null $name  Display name.
	 */
	public function __construct( $email, $name = null ) {
		$this->email = (string) $email;
		$this->name  = ( null !== $name && '' !== $name ) ? (string) $name : null;
	}

	/**
	 * Serialise to a `{ email, name? }` entry.
	 *
	 * @return array
	 */
	public function to_array() {
		$entry = array( 'email' => $this->email );
		if ( null !== $this->name ) {
			$entry['name'] = $this->name;
		}
		return $entry;
	}
}
