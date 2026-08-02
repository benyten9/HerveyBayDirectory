<?php
/**
 * Fired when a new contact is created.
 *
 * @since 1.0.0
 * @package DoubleScale\Core\Events
 */

namespace DoubleScale\Core\Events;

defined( 'ABSPATH' ) || exit;

class ContactCreated extends Event {

	/** @var int */
	public int $contact_id;

	/** @var array<string, mixed> */
	public array $data;

	public function __construct( int $contact_id, array $data = array() ) {
		$this->contact_id = $contact_id;
		$this->data       = $data;
	}
}
