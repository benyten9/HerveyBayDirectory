<?php
/**
 * Fired when a contact is updated.
 *
 * @since 1.0.0
 * @package DoubleScale\Core\Events
 */

namespace DoubleScale\Core\Events;

defined( 'ABSPATH' ) || exit;

class ContactUpdated extends Event {

	public int $contact_id;

	/** @var array<string, mixed> Changed attributes. */
	public array $changes;

	public function __construct( int $contact_id, array $changes = array() ) {
		$this->contact_id = $contact_id;
		$this->changes    = $changes;
	}
}
