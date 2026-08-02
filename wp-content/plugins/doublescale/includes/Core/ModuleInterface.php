<?php
/**
 * Contract every module must implement.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

interface ModuleInterface {

	public function slug(): string;

	public function label(): string;

	public function description(): string;

	public function is_toggleable(): bool;

	/**
	 * Semver-style version for this module (used by MigrationRunner).
	 */
	public function version(): string;

	/**
	 * @return string[]
	 */
	public function dependencies(): array;

	public function is_enabled(): bool;

	/**
	 * Canonical "on" check; same as {@see is_enabled()} since 1.13.x.
	 */
	public function isActive(): bool;

	public function register( Container $container ): void;

	public function boot( Container $container ): void;

	/**
	 * @return string[]
	 */
	public function migrations(): array;

	/**
	 * @return class-string[]
	 */
	public function restControllers(): array;

	/**
	 * Runs once when the module transitions from inactive to active (user toggle or option add).
	 *
	 * @since 1.0.0
	 */
	public function onActivate(): void;

	/**
	 * Runs once when the module transitions from active to inactive.
	 *
	 * @since 1.0.0
	 */
	public function onDeactivate(): void;

	/**
	 * Action Scheduler group + hook pairs to unschedule when the module turns off.
	 *
	 * @since 1.0.0
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function scheduledHooks(): array;
}
