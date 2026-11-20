<?php
/**
 * Load a registry's definition files only when something reads the registry.
 *
 * Merge tags and automation conditions (rules) are hundreds of small classes,
 * each registering itself when its file is included. Most requests never read
 * them: they matter when a message is rendered or an automation condition is
 * checked, and when the builder lists them. Including them all on every
 * request cost about a quarter of DoubleScale's start-up files.
 *
 * A module now hands its loader to defer(); the registry runs every pending
 * loader, in the order they were deferred, the first time it is read. The
 * result is the same registry as eager loading:
 *
 * - Order: registrations keep "first one wins". Each registration carries the
 *   sequence number of the point where it originally would have happened (a
 *   deferred loader keeps the number it got when deferred), so a later direct
 *   registration of the same key never beats a deferred one that came first.
 * - Hooks: definition files that wait for `init` (or another start-up action)
 *   before registering would miss it when loaded late. Callbacks a loader adds
 *   to an action that has already run are called straight away instead.
 *
 * Kill switch: define DOUBLESCALE_EAGER_REGISTRIES as true, or return false
 * from the `doublescale_defer_registries` filter, to load everything up front
 * as before.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

trait DefersRegistration {

	/** @var array<int, array{0:int, 1:callable}> Loaders not run yet, with their sequence number. */
	private $deferred_loaders = array();

	/** @var int|null Sequence number of the loader running now. */
	private $deferred_loading_seq = null;

	/** @var int */
	private $registration_counter = 0;

	/** @var array<string, int> Registry key => sequence number it was registered at. */
	private $registration_seq = array();

	/**
	 * Run $loader the first time the registry is read (or now, when deferral is off).
	 *
	 * @param callable $loader Includes definition files / registers entries.
	 */
	public function defer( callable $loader ): void {
		$seq = ++$this->registration_counter;
		if ( ! self::deferral_enabled() ) {
			$this->run_loader( $seq, $loader );
			return;
		}
		$this->deferred_loaders[] = array( $seq, $loader );
	}

	/**
	 * Run every pending loader (registry readers call this first).
	 */
	public function load_deferred(): void {
		while ( $this->deferred_loaders ) {
			list( $seq, $loader ) = array_shift( $this->deferred_loaders );
			$this->run_loader( $seq, $loader );
		}
	}

	/**
	 * Whether this registration of $key should be stored (first one wins, by original order).
	 *
	 * @param string $key       Registry key.
	 * @param bool   $is_stored Whether an entry with this key already exists.
	 */
	protected function claim_registration( string $key, bool $is_stored ): bool {
		$seq = $this->deferred_loading_seq ?? ++$this->registration_counter;
		if ( $is_stored && ( $this->registration_seq[ $key ] ?? 0 ) <= $seq ) {
			return false;
		}
		$this->registration_seq[ $key ] = $seq;
		return true;
	}

	/**
	 * @param int      $seq    Sequence number for everything this loader registers.
	 * @param callable $loader Loader.
	 */
	private function run_loader( int $seq, callable $loader ): void {
		$outer                      = $this->deferred_loading_seq;
		$this->deferred_loading_seq = $seq;
		try {
			self::run_with_missed_actions( $loader );
		} finally {
			$this->deferred_loading_seq = $outer;
		}
	}

	/**
	 * Run $loader; call at once any callback it adds to a start-up action that already ran.
	 *
	 * @param callable $loader Loader.
	 */
	private static function run_with_missed_actions( callable $loader ): void {
		global $wp_filter;

		if ( ! function_exists( 'did_action' ) ) {
			$loader();
			return;
		}

		$actions = array( 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'woocommerce_loaded', 'doublescale_ready' );
		$before  = array();
		foreach ( $actions as $action ) {
			if ( did_action( $action ) ) {
				$before[ $action ] = self::callback_ids( $action );
			}
		}

		$loader();

		foreach ( $before as $action => $ids ) {
			$hook = $wp_filter[ $action ] ?? null;
			if ( ! $hook instanceof \WP_Hook ) {
				continue;
			}
			$running_at = doing_action( $action ) ? $hook->current_priority() : null;
			$callbacks  = $hook->callbacks;
			ksort( $callbacks );
			foreach ( $callbacks as $priority => $list ) {
				// While the action is still running, WordPress itself reaches later priorities.
				if ( null !== $running_at && false !== $running_at && $priority > $running_at ) {
					continue;
				}
				foreach ( $list as $id => $callback ) {
					if ( isset( $ids[ $priority . '|' . $id ] ) ) {
						continue;
					}
					remove_action( $action, $callback['function'], $priority );
					call_user_func( $callback['function'] );
				}
			}
		}
	}

	/**
	 * @param string $action Action name.
	 * @return array<string, true> "priority|id" of every callback on the action.
	 */
	private static function callback_ids( string $action ): array {
		global $wp_filter;
		$ids  = array();
		$hook = $wp_filter[ $action ] ?? null;
		if ( $hook instanceof \WP_Hook ) {
			foreach ( $hook->callbacks as $priority => $list ) {
				foreach ( $list as $id => $unused ) {
					$ids[ $priority . '|' . $id ] = true;
				}
			}
		}
		return $ids;
	}

	private static function deferral_enabled(): bool {
		if ( defined( 'DOUBLESCALE_EAGER_REGISTRIES' ) && DOUBLESCALE_EAGER_REGISTRIES ) {
			return false;
		}
		return ! function_exists( 'apply_filters' ) || (bool) apply_filters( 'doublescale_defer_registries', true );
	}
}
