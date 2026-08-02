<?php
/**
 * Pro alias for the shared {@see \DoubleScale\Core\Communication\EmailIdentityResolver}.
 *
 * The resolver itself lives in the Free plugin so the Booking module can use it
 * without depending on Pro. This subclass exists only so Pro callers that still
 * import the old Pro namespace keep working unchanged.
 *
 * @tier free
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Core\Communication;

use DoubleScale\Core\Communication\EmailIdentityResolver as FreeEmailIdentityResolver;

defined( 'ABSPATH' ) || exit;

final class EmailIdentityResolver {

	/**
	 * @see FreeEmailIdentityResolver::resolve()
	 *
	 * @param int|null $host_user_id
	 * @return array{from_address:string, from_name:string, reply_to:string}
	 */
	public static function resolve( ?int $host_user_id = null ): array {
		return FreeEmailIdentityResolver::resolve( $host_user_id );
	}
}
