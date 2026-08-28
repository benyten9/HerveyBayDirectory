<?php
/**
 * Record-level ownership scoping for abilities (Gate 3).
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * One implementation of "you only see your own records".
 *
 * Gate 3 cannot live in {@see AbilityGuard} because it needs the query, so each
 * execute callback applies it. That was fine for three modules; across eleven
 * it means eight different owner columns (`owner_id`, `assigned_to`,
 * `assigned_user_id`, `agent_user_id`, `sale_agent_user_id`, `user_id`,
 * `created_by`, `contact_id`) copy-pasted per ability — and a single wrong
 * column silently leaks other people's records.
 *
 * Callers still decide WHETHER they see everything, because that question is
 * module-specific (sales caps, support caps, project caps). This class only
 * enforces the decision consistently.
 */
final class AbilityScope {

	/**
	 * Restrict a list query to the current user's own records.
	 *
	 * Call this LAST, after every caller-supplied filter has been applied, so
	 * no input can widen the scope.
	 *
	 * @since 1.0.0
	 *
	 * @param object $query     Eloquent query builder.
	 * @param string $column    Owner column on the table.
	 * @param bool   $sees_all  Whether the caller may see every record.
	 * @return object The query, for chaining.
	 */
	public static function apply( $query, string $column, bool $sees_all ) {
		if ( $sees_all ) {
			return $query;
		}

		return $query->where( $column, get_current_user_id() );
	}

	/**
	 * Whether the current user owns a record.
	 *
	 * A null/0 owner counts as NOT owned: an unassigned record belongs to the
	 * team, and a scoped user should not reach it just because nobody claimed
	 * it.
	 *
	 * @since 1.0.0
	 *
	 * @param object $record   Model instance.
	 * @param string $column   Owner column.
	 * @param bool   $sees_all Whether the caller may see every record.
	 * @return bool
	 */
	public static function owns( $record, string $column, bool $sees_all ): bool {
		if ( $sees_all ) {
			return true;
		}

		if ( ! is_object( $record ) ) {
			return false;
		}

		$owner = (int) ( $record->{$column} ?? 0 );

		return $owner > 0 && $owner === (int) get_current_user_id();
	}

	/**
	 * Guard a single-record read; returns a 403 when the caller does not own it.
	 *
	 * @since 1.0.0
	 *
	 * @param object $record   Model instance.
	 * @param string $column   Owner column.
	 * @param bool   $sees_all Whether the caller may see every record.
	 * @param string $message  Caller-facing refusal message.
	 * @return WP_Error|null Null when access is allowed.
	 */
	public static function assert_owns( $record, string $column, bool $sees_all, string $message ): ?WP_Error {
		if ( self::owns( $record, $column, $sees_all ) ) {
			return null;
		}

		return AbilityResult::forbidden( $message );
	}

	/**
	 * The scope label reported to the agent alongside results.
	 *
	 * Without it an agent reads a filtered count as a total and tells the user
	 * "you have 4 invoices" when the site has 400.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $sees_all Whether the caller may see every record.
	 * @return string
	 */
	public static function label( bool $sees_all ): string {
		return $sees_all ? 'all' : 'own';
	}
}
