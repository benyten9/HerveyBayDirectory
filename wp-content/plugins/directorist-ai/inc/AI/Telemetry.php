<?php
/**
 * Safe separation of selected SDK identities and provider-reported identities.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

/** Result metadata is a declaration; it is not always the served model. */
final class Telemetry {

	/**
	 * Capture only allowlisted response metadata, never content or credentials.
	 *
	 * @param object $result Native provider result.
	 */
	public static function result( object $result ): array {
		$data = array(
			'reported_model' => null,
			'usage_reported' => false,
		);
		try {
			$extra = $result->getAdditionalData();
			$model = $extra['model'] ?? null;
			if ( is_string( $model ) && preg_match( '/^[A-Za-z0-9._:\\/@+-]{1,191}$/D', $model ) ) {
				$data['reported_model'] = $model;
			}
		} catch ( \Throwable $error ) {
			// Optional serving-model metadata is not supplied by every connector.
		}
		try {
			$usage = $result->getTokenUsage();
			// The bundled SDK substitutes zeroes when the upstream omitted usage.
			// Without positive evidence, zero is displayed as unreported.
			$data['usage_reported'] = $usage->getPromptTokens() > 0 || $usage->getCompletionTokens() > 0 || $usage->getTotalTokens() > 0;
		} catch ( \Throwable $error ) {
			// Missing optional telemetry must not fail otherwise usable generation.
		}
		return $data;
	}
}
