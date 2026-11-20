<?php
/**
 * Shared rules for the "Projects" contact filters (campaign audiences, segments).
 *
 * A project rule picks the contacts who are the main contact (client) of a
 * project that meets it: "CMS is WordPress" sends to the client of every
 * WordPress project. A company project keeps a main contact too, so it is
 * the one reached.
 *
 * @package DoubleScale\Pro\Modules\Projects\Filters
 */

namespace DoubleScale\Pro\Modules\Projects\Filters;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder;

/**
 * Turns a rule about projects into a rule about their clients.
 */
final class ProjectAudience {

	/**
	 * Group key in the contact filter pickers.
	 */
	const GROUP = 'projects';

	/**
	 * Operators that read "none of the client's projects …".
	 *
	 * "CMS is not WordPress" means the client has projects and none of them is
	 * a WordPress one. Matching "a project that is not WordPress" instead would
	 * also send to a client with one WordPress and one Shopify project.
	 */
	const NEGATIVE = array(
		'is_not'           => 'is',
		'does_not_contain' => 'contains',
	);

	/**
	 * Whether project data can be read on this request.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		if ( function_exists( 'doublescale_module_data_ready' ) ) {
			return doublescale_module_data_ready( 'projects' );
		}

		return function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'projects' );
	}

	/**
	 * Limit a contact query to the clients of projects matching a rule.
	 *
	 * @param Builder  $contacts Contact query.
	 * @param string   $operator Rule operator.
	 * @param callable $narrow   fn( Builder $projects, string $operator ): void, narrows a project query by the rule.
	 * @return Builder
	 */
	public static function apply( Builder $contacts, string $operator, callable $narrow ): Builder {
		$id = $contacts->getModel()->getTable() . '.id';

		// Projects switched off: nobody is the client of a matching project. A rule
		// that silently did nothing would send the campaign to every contact.
		if ( ! self::ready() ) {
			return $contacts->whereRaw( '1 = 0' );
		}

		if ( isset( self::NEGATIVE[ $operator ] ) ) {
			$matching = self::clients();
			$narrow( $matching, self::NEGATIVE[ $operator ] );

			return $contacts->whereIn( $id, self::clients() )->whereNotIn( $id, $matching );
		}

		$matching = self::clients();
		$narrow( $matching, $operator );

		return $contacts->whereIn( $id, $matching );
	}

	/**
	 * Main contact ids of projects; never NULL, or NOT IN would match nobody.
	 *
	 * @return Builder
	 */
	public static function clients(): Builder {
		return ProjectModel::query()->select( 'contact_id' )->whereNotNull( 'contact_id' );
	}
}
