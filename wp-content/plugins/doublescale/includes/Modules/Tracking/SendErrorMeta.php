<?php
/**
 * Persist a provider send error on the tracking row.
 *
 * Campaigns and automations share handle_send_result(). The tracking status
 * becomes FAILED, but Reports had nowhere to read the human-readable reason.
 * Storing it as tracking meta lets the automation action pass that copy back
 * so the run timeline can show it.
 *
 * @package DoubleScale\Modules\Tracking
 */

namespace DoubleScale\Modules\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * SendErrorMeta helper.
 */
class SendErrorMeta {

	public const KEY = 'send_error';

	/**
	 * @param mixed $result Provider/send result array.
	 * @return string Trimmed error, or empty when none.
	 */
	public static function from_result( $result ): string {
		if ( ! is_array( $result ) ) {
			return '';
		}

		$error = $result['error'] ?? '';

		return is_string( $error ) ? trim( $error ) : '';
	}
}
