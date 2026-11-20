<?php
/**
 * Who may claim a contact that already exists.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Scope\OwnerScope;
use WP_Error;

/**
 * A scoped agent who creates, imports or merges a contact that another
 * agent owns must be told "no" without learning anything about that record:
 * not its name, not its id, not even whether the email matches an existing
 * person beyond the fact that it's taken. Unassigned contacts are the
 * team's: an agent may take them over, and doing so claims them.
 */
final class ContactOwnershipGuard {

	public const OWNED_BY_OTHER = 'doublescale_contact_owned_by_other';

	/**
	 * May this scope take over (update / merge into / import onto) an
	 * existing contact? Its owner may, a manager may, and anyone may claim an
	 * unassigned contact; a contact another agent owns is refused.
	 *
	 * @param object|null     $existing Existing contact (model or row object), or null.
	 * @param OwnerScope|null $scope    Scope (default: current user).
	 * @return WP_Error|null Null when allowed.
	 */
	public static function assert_can_claim( $existing, ?OwnerScope $scope = null ): ?WP_Error {
		if ( ! is_object( $existing ) ) {
			return null;
		}

		$scope = $scope ?? OwnerScope::current();

		if ( $scope->owns_contact( $existing ) || (int) ( $existing->owner_id ?? 0 ) <= 0 ) {
			return null;
		}

		return new WP_Error(
			self::OWNED_BY_OTHER,
			__( 'A contact with these details is already owned by another team member.', 'doublescale' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether saving onto $existing should make the acting agent its owner:
	 * an agent working an unassigned contact claims it.
	 *
	 * @param object|null     $existing Existing contact.
	 * @param OwnerScope|null $scope    Scope (default: current user).
	 * @return bool
	 */
	public static function claims( $existing, ?OwnerScope $scope = null ): bool {
		if ( ! is_object( $existing ) || (int) ( $existing->owner_id ?? 0 ) > 0 ) {
			return false;
		}

		$scope = $scope ?? OwnerScope::current();

		return $scope->user_id() > 0 && \DoubleScale\Core\UserRoles\Permissions::is_agent_tier( $scope->user_id() );
	}
}
