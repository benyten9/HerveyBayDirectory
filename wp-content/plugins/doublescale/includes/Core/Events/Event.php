<?php
/**
 * Base event class for the typed event system.
 *
 * All domain events should extend this class. Typed events improve
 * IDE discoverability and type-safety compared to raw WordPress hooks
 * with magic string identifiers.
 *
 * @since 1.0.0
 * @package DoubleScale\Core\Events
 */

namespace DoubleScale\Core\Events;

defined( 'ABSPATH' ) || exit;

abstract class Event {

	/**
	 * WordPress hook name used under the hood.
	 *
	 * Defaults to the FQCN with backslashes replaced by dots, which
	 * guarantees uniqueness across events.
	 *
	 * @return string
	 */
	public function hook_name(): string {
		return str_replace( '\\', '.', static::class );
	}

	/**
	 * Whether propagation has been stopped.
	 *
	 * @var bool
	 */
	private bool $propagation_stopped = false;

	public function stop_propagation(): void {
		$this->propagation_stopped = true;
	}

	public function is_propagation_stopped(): bool {
		return $this->propagation_stopped;
	}
}
