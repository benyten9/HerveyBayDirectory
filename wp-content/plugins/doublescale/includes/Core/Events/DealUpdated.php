<?php
/**
 * Fired when a deal is updated.
 *
 * @since 1.0.0
 * @package DoubleScale\Core\Events
 */

namespace DoubleScale\Core\Events;

defined( 'ABSPATH' ) || exit;

class DealUpdated extends Event {

	public int $deal_id;

	/** @var array<string, mixed> Changed attributes. */
	public array $changes;

	public function __construct( int $deal_id, array $changes = array() ) {
		$this->deal_id = $deal_id;
		$this->changes = $changes;
	}
}
