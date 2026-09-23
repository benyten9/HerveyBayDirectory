<?php
/**
 * Interpret the value an automation action returns from process_action().
 *
 * Actions historically returned true/false. Messaging actions also return an
 * array: `{ success: false, message }` on failure, `{ status: skipped }` when
 * the step does not apply. PHP treats a non-empty array as truthy, so
 * ProcessAutomation must not use `if ( ! $result )` for those returns — that
 * stored Failed WhatsApp sends as Completed and hid the reason from Reports.
 *
 * @package DoubleScale\Modules\Automations
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ActionResult helper.
 */
class ActionResult {

	/**
	 * @param mixed $result Raw process_action() return value.
	 * @return array{outcome: string, message: string} outcome is completed|failed|skipped.
	 */
	public static function interpret( $result ): array {
		if ( true === $result ) {
			return array(
				'outcome' => 'completed',
				'message' => '',
			);
		}

		if ( false === $result || null === $result ) {
			return array(
				'outcome' => 'failed',
				'message' => '',
			);
		}

		if ( ! is_array( $result ) ) {
			return array(
				'outcome' => $result ? 'completed' : 'failed',
				'message' => '',
			);
		}

		$status  = isset( $result['status'] ) ? (string) $result['status'] : '';
		$message = isset( $result['message'] ) ? trim( (string) $result['message'] ) : '';

		if ( 'skipped' === $status ) {
			return array(
				'outcome' => 'skipped',
				'message' => $message,
			);
		}

		if ( array_key_exists( 'success', $result ) && false === $result['success'] ) {
			return array(
				'outcome' => 'failed',
				'message' => $message,
			);
		}

		return array(
			'outcome' => 'completed',
			'message' => $message,
		);
	}
}
