<?php
/**
 * Accounts class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\PostMark;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\Accounts as Abstract_Accounts;

/**
 * Accounts class.
 *
 * @since 1.0.0
 */
class Accounts extends Abstract_Accounts {

	/**
	 * Initialize new account api
	 *
	 * @param string $account_id Account id.
	 * @param array  $account_data Account data.
	 * @return Account_API
	 */
	protected function init_account_api( $account_id, $account_data ) {
		return new Account_API( $account_data['credentials']['api_key'], $account_data['credentials']['message_stream_id'] ?? '' );
	}
}
