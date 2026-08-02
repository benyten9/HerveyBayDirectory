<?php
/**
 * Typed event dispatcher backed by WordPress hooks.
 *
 * Provides a thin, type-safe layer on top of `do_action()` / `add_action()`
 * so modules can dispatch and subscribe to domain events using concrete
 * classes instead of magic hook-name strings.
 *
 * Usage:
 *
 *   // Dispatching (from any module):
 *   EventDispatcher::dispatch( new ContactCreated( $contact ) );
 *
 *   // Subscribing (in a module's boot()):
 *   EventDispatcher::listen( ContactCreated::class, function ( ContactCreated $e ) {
 *       // handle
 *   } );
 *
 * Under the hood every event is mapped to a WordPress action whose name
 * is the FQCN (backslashes replaced with dots), so third-party code can
 * still use `add_action()` / `do_action()` directly if needed.
 *
 * @since 1.0.0
 * @package DoubleScale\Core\Events
 */

namespace DoubleScale\Core\Events;

defined( 'ABSPATH' ) || exit;

class EventDispatcher {

	/**
	 * Dispatch an event, invoking all registered listeners.
	 *
	 * @param Event $event Typed event instance.
	 * @return Event The (potentially modified) event.
	 */
	public static function dispatch( Event $event ): Event {
		/**
		 * Fires when a typed domain event is dispatched.
		 *
		 * The dynamic portion of the hook name is derived from the
		 * event's FQCN (e.g. `DoubleScale.Pro.Core.Events.ContactCreated`).
		 *
		 * @param Event $event The event instance.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name derives from event FQCN (e.g. `DoubleScale.Pro.Core.Events.ContactCreated`), always prefixed.
		do_action( $event->hook_name(), $event );

		return $event;
	}

	/**
	 * Subscribe a listener to a typed event.
	 *
	 * @param class-string<Event> $event_class FQCN of the event to listen for.
	 * @param callable            $listener    Receives the event instance as its only argument.
	 * @param int                 $priority    WordPress hook priority (default 10).
	 */
	public static function listen( string $event_class, callable $listener, int $priority = 10 ): void {
		$hook = str_replace( '\\', '.', $event_class );

		add_action(
			$hook,
			static function ( Event $event ) use ( $listener ) {
				if ( $event->is_propagation_stopped() ) {
					return;
				}
				$listener( $event );
			},
			$priority
		);
	}

	/**
	 * Dispatch and allow listeners to filter a return value.
	 *
	 * This is the `apply_filters` counterpart. The event must carry
	 * a mutable value that listeners can modify.
	 *
	 * @param Event $event Typed event carrying the filterable value.
	 * @return Event The (potentially modified) event.
	 */
	public static function filter( Event $event ): Event {
		/** @var Event $result */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name derives from event FQCN, always prefixed.
		$result = apply_filters( $event->hook_name(), $event );
		return $result instanceof Event ? $result : $event;
	}
}
