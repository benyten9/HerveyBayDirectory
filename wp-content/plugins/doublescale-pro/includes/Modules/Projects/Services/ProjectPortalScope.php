<?php
/**
 * Which projects a portal client sees: their own, plus projects set to a
 * company they belong to (via Free's PortalScope). Against an older Free
 * without company sharing, only their own.
 *
 * @package DoubleScale\Pro\Modules\Projects\Services
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Portal\Services\PortalScope;

/**
 * ProjectPortalScope class
 */
class ProjectPortalScope {

	/**
	 * @param mixed $query      Project query.
	 * @param int   $contact_id Contact ID.
	 * @return mixed
	 */
	public static function apply( $query, int $contact_id ) {
		if ( class_exists( PortalScope::class ) ) {
			return PortalScope::apply( $query, $contact_id, 'projects' );
		}
		return $query->where( 'contact_id', $contact_id );
	}

	/**
	 * @param object|null $project    Project.
	 * @param int         $contact_id Contact ID.
	 */
	public static function owns( $project, int $contact_id ): bool {
		if ( class_exists( PortalScope::class ) ) {
			return PortalScope::owns( $project, $contact_id, 'projects' );
		}
		return $project && (int) $project->contact_id === $contact_id;
	}
}
