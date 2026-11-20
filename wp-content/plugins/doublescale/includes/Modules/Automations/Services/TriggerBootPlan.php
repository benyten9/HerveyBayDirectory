<?php
/**
 * Which triggers need their hooks on this request, and how to build them
 * without loading the whole trigger catalog.
 *
 * A trigger only does something when an active automation uses it: its
 * hooks call Trigger::process(), which looks up the active automations for
 * its slug. So a request needs the hooks of the triggers that active
 * automations use, not all ~300 trigger classes. The full catalog (labels,
 * fields, sources) is built later, on first read.
 *
 * Two small autoloaded options:
 * - the recipe map: trigger slug => class and file, learned from a full
 *   catalog build and checked when learned (a fresh instance of the class
 *   must have the same slug; per-form triggers that need constructor
 *   arguments are marked "not rebuildable"). Rebuilt when plugin versions,
 *   active plugins or active DoubleScale modules change.
 * - the active trigger slugs: forgotten whenever an automation is saved or
 *   deleted, and re-read from the database at least every 10 minutes.
 *
 * When any active slug cannot be rebuilt (or the map is missing/stale),
 * the request loads the full catalog as before.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;

final class TriggerBootPlan {

	/** Autoloaded: array{sig: string, recipes: array<string, array{0: string, 1: string}|false>}. */
	const MAP_OPTION = 'doublescale_trigger_recipes';

	/** Autoloaded: array{sig: string, slugs: string[], checked: int}. */
	const ACTIVE_OPTION = 'doublescale_active_trigger_slugs';

	/** Re-read the active slugs from the database at least this often. */
	const ACTIVE_TTL = 600;

	/** @var string[] Module slugs booted this request. */
	private static $modules = array();

	/**
	 * @param string[] $booted Booted module slugs (doublescale_modules_booted).
	 */
	public static function note_modules( $booted ): void {
		self::$modules = is_array( $booted ) ? array_values( $booted ) : array();
	}

	/**
	 * Recipes for the triggers active automations use, or null when this
	 * request has to load the full catalog.
	 *
	 * @return array<string, array{0: string, 1: string}>|null
	 */
	public static function plan(): ?array {
		$map = get_option( self::MAP_OPTION );
		if ( ! is_array( $map ) || ( $map['sig'] ?? '' ) !== self::signature() || ! is_array( $map['recipes'] ?? null ) ) {
			return null;
		}
		$slugs = self::active_slugs();
		if ( null === $slugs ) {
			return null;
		}
		$plan = array();
		foreach ( $slugs as $slug ) {
			$recipe = $map['recipes'][ $slug ] ?? null;
			if ( ! is_array( $recipe ) ) {
				return null; // Unknown or not rebuildable (e.g. a per-form trigger): full catalog.
			}
			$plan[ $slug ] = $recipe;
		}
		return $plan;
	}

	/**
	 * Learn the recipes after a full catalog build (only when missing or stale).
	 *
	 * @param array<string, Trigger> $triggers Every registered trigger.
	 */
	public static function learn( array $triggers ): void {
		$sig = self::signature();
		$map = get_option( self::MAP_OPTION );
		if ( is_array( $map ) && ( $map['sig'] ?? '' ) === $sig ) {
			return;
		}
		$recipes = array();
		foreach ( $triggers as $slug => $trigger ) {
			$recipes[ (string) $slug ] = self::recipe_for( (string) $slug, $trigger );
		}
		update_option(
			self::MAP_OPTION,
			array(
				'sig'     => $sig,
				'recipes' => $recipes,
				// Registration order of a full build: a lazy build restores it so
				// lists (builder, catalog) show triggers in the same order.
				'order'   => array_map( 'strval', array_keys( $triggers ) ),
			),
			true
		);
	}

	/**
	 * Trigger slugs in the order a full build registers them (empty when unknown).
	 *
	 * @return string[]
	 */
	public static function order(): array {
		$map = get_option( self::MAP_OPTION );
		return is_array( $map ) && is_array( $map['order'] ?? null ) ? $map['order'] : array();
	}

	/**
	 * Forget the active slugs (an automation was saved or deleted).
	 */
	public static function forget_active(): void {
		delete_option( self::ACTIVE_OPTION );
	}

	/**
	 * @param string $slug    Trigger slug.
	 * @param mixed  $trigger Registered trigger.
	 * @return array{0: string, 1: string}|false Class and file, or false when it cannot be rebuilt from its class.
	 */
	private static function recipe_for( string $slug, $trigger ) {
		if ( ! $trigger instanceof Trigger ) {
			return false;
		}
		try {
			$ref  = new \ReflectionClass( $trigger );
			$ctor = $ref->getConstructor();
			if ( $ref->isAbstract() || ( $ctor && $ctor->getNumberOfRequiredParameters() > 0 ) ) {
				return false;
			}
			$fresh = $ref->newInstance();
			if ( ! $fresh instanceof Trigger || $fresh->slug !== $slug || (bool) $fresh->is_pro !== (bool) $trigger->is_pro ) {
				return false;
			}
			return array( $ref->getName(), (string) $ref->getFileName() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Slugs of the triggers active automations use (cached; null when it cannot be read).
	 *
	 * @return string[]|null
	 */
	private static function active_slugs(): ?array {
		$sig    = self::signature();
		$cached = get_option( self::ACTIVE_OPTION );
		if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $sig && is_array( $cached['slugs'] ?? null )
			&& time() - (int) ( $cached['checked'] ?? 0 ) < self::ACTIVE_TTL ) {
			return $cached['slugs'];
		}
		try {
			$slugs = AutomationModel::query()
				->where( 'status', 'active' )
				->distinct()
				->pluck( 'trigger' )
				->filter()
				->map( static function ( $slug ) {
					return (string) $slug;
				} )
				->values()
				->all();
		} catch ( \Throwable $e ) {
			return null;
		}
		update_option(
			self::ACTIVE_OPTION,
			array(
				'sig'     => $sig,
				'slugs'   => $slugs,
				'checked' => time(),
			),
			true
		);
		return $slugs;
	}

	/**
	 * Code and setup the trigger set depends on.
	 */
	private static function signature(): string {
		return md5(
			wp_json_encode(
				array(
					defined( 'DOUBLESCALE_VERSION' ) ? DOUBLESCALE_VERSION : '',
					defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : '',
					(array) get_option( 'active_plugins', array() ),
					is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array(),
					self::$modules,
				)
			)
		);
	}
}
