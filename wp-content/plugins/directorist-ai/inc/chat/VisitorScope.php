<?php
/**
 * Public directory scope for the global visitor assistant.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Directory identities always come from current native listings and settings. */
final class VisitorScope {
	/**
	 * A null directory is an explicit global turn, never unrestricted data access.
	 *
	 * @param object $scope Server-owned turn scope.
	 */
	public static function global( object $scope ): bool {
		return true === ( $scope->global_search ?? false );
	}

	/**
	 * Read only the current owned worker's immutable turn scope.
	 *
	 * @param Context  $context Verified visitor worker.
	 * @param Database $db Owned runtime database.
	 * @return object|null Current server scope, or no authority.
	 */
	public static function current( Context $context, Database $db ): ?object {
		if ( 'visitor' !== $context->profile() || null === $context->conversation_id() || null === $context->run_id() ) {
			return null;
		}
		$rows  = $db->rows( 'SELECT r.checkpoint_json FROM ' . $db->table( 'runs' ) . ' r JOIN ' . $db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.public_id=%s AND c.erasure_requested=0', array( $context->run_id(), $context->actor()->owner()->reference(), $context->conversation_id() ) );
		$scope = $rows ? ( json_decode( $rows[0]['checkpoint_json'] )->target_context ?? null ) : null;
		return $scope instanceof \stdClass ? $scope : null;
	}

	/**
	 * Validate each selected public listing against its own current directory.
	 *
	 * @param Context $context Verified visitor context.
	 * @param array   $ids Selected native listing IDs.
	 * @throws ContractException When a selection is invalid or unavailable.
	 */
	public static function selection( Context $context, array $ids ): object {
		if ( count( $ids ) > 4 || array_values( $ids ) !== $ids || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) ) {
			throw new ContractException( 'invalid_input', 'Choose up to four current listings.' );
		}
		$groups = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 || ! function_exists( 'directorist_get_listing_directory' ) ) {
				throw new ContractException( 'invalid_input', 'Choose a current listing.' );
			}
			$directory              = ListingActions::directory( (string) directorist_get_listing_directory( $id ) );
			$groups[ $directory ][] = $id;
		}
		$listings = array();
		foreach ( $groups as $directory => $selected ) {
			$facts = ListingActions::selection( $context, $directory, $selected );
			foreach ( $facts->listings as $listing ) {
				$listing->directory_id = $directory;
				$listings[]            = $listing;
			}
		}
		return (object) array( 'listings' => $listings );
	}
}
