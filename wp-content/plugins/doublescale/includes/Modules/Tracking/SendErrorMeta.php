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

	/**
	 * The same failure, with the provider's numeric code kept.
	 *
	 * from_result() returns only the human sentence, which is all the
	 * `send_error` meta key ever held — and all Pro reads back. That is enough
	 * to *show* one failure but not to *count* them: "4,747 failed" cannot be
	 * broken down into "412 blocked the business, 88 template not approved"
	 * without the code. So this returns both, for the structured `error_info`
	 * key that the UI and the failure-cause grouping read.
	 *
	 * Two shapes carry the code, and both are real:
	 *   - metadata.error_code — what MetaWhatsappProvider::send_message()
	 *     returns via AbstractMessageProvider::error_result().
	 *   - error_code — what the raw Api layer returns before the provider
	 *     wraps it, and what a provider returning its own result may use.
	 *
	 * A transport failure (wp_remote_request returning WP_Error) has no code at
	 * all; the message alone is still worth recording, so an empty code is a
	 * valid result rather than a reason to skip the write.
	 *
	 * @param mixed $result Provider/send result array.
	 * @return array{code: string, message: string}
	 */
	public static function structured_from_result( $result ): array {
		if ( ! is_array( $result ) ) {
			return array(
				'code'    => '',
				'message' => '',
			);
		}

		$code = $result['metadata']['error_code'] ?? ( $result['error_code'] ?? '' );

		// 0 is what a provider uses for "no code known", not a real Meta code.
		if ( '' === $code || null === $code || 0 === $code || '0' === $code ) {
			$code = '';
		}

		return array(
			'code'    => is_scalar( $code ) ? (string) $code : '',
			'message' => self::from_result( $result ),
		);
	}
}
