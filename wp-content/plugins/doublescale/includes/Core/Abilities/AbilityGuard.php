<?php
/**
 * The three gates every DoubleScale ability passes through.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;

/**
 * Gate 1 (module active), Gate 2 (role/capability), and the execute wrapper.
 *
 * Gate 3 (record ownership) cannot live here — it needs the query, so each
 * execute callback applies it. See DocumentAbilities / SupportAbilities.
 *
 * Kept separate from AbilityRegistrar so the fast test suite can exercise the
 * gates directly with stub modules and no database.
 */
final class AbilityGuard {

	/**
	 * Gate 1, evaluated LIVE rather than snapshotted at registration.
	 *
	 * Toggling a module never re-runs ModuleRegistry::boot(), so an ability
	 * registered while the module was on stays registered for the rest of the
	 * request. Since abilities are also reachable over REST, a stale
	 * registration would be a live exposure path — the permission_callback is
	 * the only thing evaluated per invocation, so the authoritative check
	 * belongs there.
	 *
	 * Always pass the LEAF slug ('documents', never 'sales'): the parent gate
	 * is already folded into the child's flag by
	 * doublescale_child_module_parent_map().
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function module_active( string $slug ): bool {
		if ( ! function_exists( 'doublescale_is_module_active' ) ) {
			return true;
		}
		return doublescale_is_module_active( $slug );
	}

	/**
	 * Clear refusal when a tool belongs to a module that is switched off.
	 *
	 * Agents often still hold a cached tools/list from before the toggle, so
	 * the refusal must name the module and say it is disabled — not look like
	 * an unknown tool or an opaque server error.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $ability_name Full ability name.
	 * @param string      $module_slug  Leaf module slug.
	 * @param string|null $module_label Optional human label (e.g. Helpdesk).
	 * @return WP_Error
	 */
	public static function inactive_module_error( string $ability_name, string $module_slug, ?string $module_label = null ): WP_Error {
		$label = ( null !== $module_label && '' !== $module_label )
			? $module_label
			: self::module_label( $module_slug );

		return new WP_Error(
			'doublescale_module_inactive',
			sprintf(
				/* translators: %s: module label (e.g. Helpdesk) */
				__( 'The "%s" module is switched off on this site. Enable it in DoubleScale settings to use this tool, or call doublescale/get-context for the tools that are currently available.', 'doublescale' ),
				$label
			),
			array(
				'status'  => 403,
				'module'  => $module_slug,
				'ability' => $ability_name,
			)
		);
	}

	/**
	 * Human-readable module label for refusal messages.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Module slug.
	 * @return string
	 */
	public static function module_label( string $slug ): string {
		if ( function_exists( 'doublescale_automation_module_label' ) ) {
			return doublescale_automation_module_label( $slug );
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $slug ) );
	}

	/**
	 * Compose Gate 1 + Gate 2 into the permission_callback WP core will call.
	 *
	 * Order is deliberate: AI access first (broadest denial and it already
	 * returns a well-formed WP_Error), then live module state, then the
	 * module's own capability check.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $name              Full ability name.
	 * @param string        $module_slug       Leaf module slug.
	 * @param callable|null $module_permission Module-specific capability check.
	 * @return callable
	 */
	public static function compose_permission( string $name, string $module_slug, $module_permission ): callable {
		return static function () use ( $name, $module_slug, $module_permission ) {
			// Role only — NOT has_ai_access(), which also demands a configured
			// AI provider. Nothing here calls a provider: an external agent
			// connects in and reads CRM data. Requiring an OpenAI key first
			// published zero tools on sites that never intended to use the
			// in-dashboard assistant, which read as "the connection failed".
			$ai_access = Permissions::has_ai_role_access();
			if ( is_wp_error( $ai_access ) ) {
				return $ai_access;
			}

			if ( ! self::module_active( $module_slug ) ) {
				return self::inactive_module_error( $name, $module_slug );
			}

			if ( is_callable( $module_permission ) && ! call_user_func( $module_permission ) ) {
				return new WP_Error(
					'doublescale_forbidden',
					__( 'You do not have permission to use this tool.', 'doublescale' ),
					array(
						'status'  => 403,
						'ability' => $name,
					)
				);
			}

			return true;
		};
	}

	/**
	 * Wrap an execute callback so no exception escapes to the caller.
	 *
	 * Two distinct problems this solves:
	 *
	 * 1. Without a definite failure signal an agent retries tools that
	 *    silently succeeded — duplicating whatever the tool did.
	 * 2. SQL and runtime messages leak table names, query fragments, and
	 *    filesystem paths. WP_DEBUG is enabled often enough on reachable
	 *    staging sites that gating the detail on that constant is not
	 *    protection, so the caller gets a correlation id and nothing else.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $name     Full ability name.
	 * @param callable $callback Module execute callback.
	 * @return callable
	 */
	public static function wrap_execute( string $name, callable $callback ): callable {
		return static function ( $input = null ) use ( $name, $callback ) {
			try {
				$result = call_user_func( $callback, is_array( $input ) ? $input : array() );

				if ( ! is_wp_error( $result ) && self::is_write( $name ) && self::changed_something( $result ) ) {
					/**
					 * Fires after a mutating ability succeeds.
					 *
					 * There is no undo and no revision history anywhere in the
					 * product, so knowing WHAT an agent changed is the only
					 * accountability available. A listener writes this to the
					 * activity timeline, where agent writes then sit alongside
					 * human ones instead of being indistinguishable after
					 * the fact.
					 *
					 * A batch write puts every id and error on `$result`
					 * (`items`, `errors`, `batch_id`). Listeners that need
					 * per-row accountability should iterate those arrays —
					 * this action fires once for the whole batch, not once
					 * per row. A zero `created`/`updated`/`deleted` count
					 * means nothing changed and this action does not fire.
					 *
					 * @since 1.0.0
					 *
					 * @param string $name    Full ability name.
					 * @param mixed  $input   Tool input parameters.
					 * @param mixed  $result  What the ability returned.
					 * @param int    $user_id User the write ran as.
					 */
					do_action( 'doublescale_ability_write', $name, $input, $result, get_current_user_id() );
				}

				return $result;
			} catch ( \Throwable $e ) {
				$error_id = substr( md5( uniqid( 'ds_ability_', true ) ), 0, 12 );

				/**
				 * Fires on an unhandled ability exception, before the sanitised
				 * WP_Error is returned to the caller.
				 *
				 * @since 1.0.0
				 *
				 * @param \Throwable $e        The exception.
				 * @param string     $name     Full ability name.
				 * @param mixed      $input    Tool input parameters.
				 * @param string     $error_id Correlation id given to the caller.
				 */
				do_action( 'doublescale_ability_exception', $e, $name, $input, $error_id );

				self::log_exception( $e, $name, $error_id );

				return new WP_Error(
					'doublescale_ability_failed',
					__( 'The tool failed with an internal error. Quote the error_id to the site administrator so they can look it up in the logs.', 'doublescale' ),
					array(
						'status'   => 500,
						'ability'  => $name,
						'error_id' => $error_id,
					)
				);
			}
		};
	}

	/**
	 * Whether a write ability actually changed anything.
	 *
	 * A write that finds the record already in the requested state is a
	 * legitimate no-op, and auditing it would fill the timeline with entries
	 * for changes that never happened. Write abilities report this by returning
	 * `created` or `updated` as false.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result What the ability returned.
	 * @return bool
	 */
	private static function changed_something( $result ): bool {
		if ( ! is_array( $result ) ) {
			return true; // Nothing to go on; assume it wrote.
		}

		foreach ( array( 'created', 'updated', 'deleted' ) as $flag ) {
			if ( array_key_exists( $flag, $result ) ) {
				return (bool) $result[ $flag ];
			}
		}

		return true;
	}

	/**
	 * Whether an ability mutates, according to its own registered annotation.
	 *
	 * Read from the registry rather than inferred from the name: the annotation
	 * is the authoritative declaration the agent also sees, so auditing keys off
	 * exactly the same fact.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Full ability name.
	 * @return bool
	 */
	private static function is_write( string $name ): bool {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return false;
		}

		$ability = wp_get_ability( $name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
			return false;
		}

		$meta = (array) $ability->get_meta();

		// Absent annotations mean the registrar's read-only default applied.
		return false === ( $meta['annotations']['readonly'] ?? true );
	}

	/**
	 * Send the full exception record to the DoubleScale log.
	 *
	 * Never allowed to interfere with the response — a broken logger must not
	 * turn a handled tool failure into a fatal.
	 *
	 * @since 1.0.0
	 *
	 * @param \Throwable $e        The exception.
	 * @param string     $name     Full ability name.
	 * @param string     $error_id Correlation id.
	 * @return void
	 */
	private static function log_exception( \Throwable $e, string $name, string $error_id ): void {
		if ( ! function_exists( 'doublescale_get_logger' ) ) {
			return;
		}

		try {
			$logger = doublescale_get_logger();
			if ( ! is_object( $logger ) || ! method_exists( $logger, 'error' ) ) {
				return;
			}

			$logger->error(
				sprintf( 'Ability exception [%s] %s', $error_id, $name ),
				array(
					'error_id'  => $error_id,
					'ability'   => $name,
					'exception' => get_class( $e ),
					'message'   => $e->getMessage(),
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'trace'     => $e->getTraceAsString(),
				)
			);
		} catch ( \Throwable $ignored ) {
			// Logging must never escalate a handled failure.
			unset( $ignored );
		}
	}
}
