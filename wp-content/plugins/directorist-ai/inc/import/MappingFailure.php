<?php
/** Safe recovery guidance for an owned import mapping request.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

use DirectoristAIPro\AI\FailureNotice;

// phpcs:disable Squiz.Commenting.FunctionComment -- Closed mapping poll presentation contract.
// phpcs:disable WordPress.WP.CapitalPDangit -- Internal source identifiers are not display text.

/** Raw checkpoint and provider data never become public error text. */
final class MappingFailure {
	public static function describe( $code, ?string $source, $native = null ): array {
		$label = 'managed' === $source ? 'Directorist AI' : ( 'wordpress' === $source ? 'The selected WordPress connector' : 'The selected AI source' );
		if ( 'provider_unavailable' === $code && 'wordpress' === $source ) {
			$code = self::native_code( $native );
		}
		$notices = array(
			'provider_unavailable'           => array( 'check_connection', $label . ' could not complete the mapping suggestion. Check AI connections and the Administrative assistance provider/model before starting a new suggestion. You can edit the mapping manually.' ),
			'provider_network_error'         => array( 'check_connection', 'WordPress could not reach the selected connector in time. Check the site’s connection and provider availability before starting a new suggestion. You can edit the mapping manually.' ),
			'provider_authentication_failed' => array( 'check_connection', 'The selected WordPress connector rejected its credentials or access. Check its connection settings before starting a new suggestion. You can edit the mapping manually.' ),
			'provider_rate_limited'          => array( 'check_connection', 'The selected WordPress connector limited this request. Check the provider’s quota and wait before starting a new suggestion. You can edit the mapping manually.' ),
			'provider_request_rejected'      => array( 'check_connection', 'The selected WordPress connector rejected the mapping request. Check the selected model and its tool/function calling support before starting a new suggestion. You can edit the mapping manually.' ),
			'provider_configuration_changed' => array( 'check_connection', 'The selected connector configuration changed after this suggestion started. Check AI connections and start a new suggestion with the current configuration, or edit the mapping manually.' ),
			'budget_exhausted'               => array( 'review_usage', 'This mapping suggestion reached the site’s AI usage limit. Check Credits & usage and the site limits before starting a new suggestion. You can edit the mapping manually.' ),
			'usage_bound_exceeded'           => array( 'review_usage', 'The provider reported usage beyond this suggestion’s reserved limit. Check Credits & usage and the provider configuration before starting a new suggestion. You can edit the mapping manually.' ),
			'output_truncated'               => array( 'check_connection', 'The mapping response reached its configured token limit. Check the selected model and simplify the source sample before starting a new suggestion. You can edit the mapping manually.' ),
			'invalid_output'                 => array( 'retry_suggestion', 'The AI response did not contain a valid mapping for this import. Edit the mapping manually or start a new suggestion.' ),
			'mapping_stale'                  => array( 'reload_import', 'The import source, mapping, or directory changed after this suggestion started. Reload the private import and review its current mapping before starting a new suggestion.' ),
			'mapping_failed'                 => array( 'retry_suggestion', 'The mapping suggestion could not be completed. Edit the mapping manually or start a new suggestion.' ),
			'mapping_cancelled'              => array( 'reload_import', 'This mapping suggestion was cancelled. Reload the private import to review its current mapping.' ),
			'storage_unavailable'            => array( 'reload_import', 'The mapping result could not be confirmed from site storage. Reload the private import and check its current mapping before starting another suggestion.' ),
			'context_unavailable'            => array( 'reload_import', 'The mapping request’s private import context is unavailable. Reload the private import before starting another suggestion.' ),
		);
		if ( in_array( $code, array( 'stale_version', 'schema_changed', 'stale_evidence', 'scope_conflict', 'mapping_applied' ), true ) ) {
			$code = 'mapping_stale';
		}
		if ( in_array( $code, array( 'invalid_input', 'mapping_invalid', 'grounded_response_required' ), true ) ) {
			$code = 'invalid_output';
		}
		if ( in_array( $code, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain' ), true ) ) {
			return self::notice( $code, 'wait_for_receipt', FailureNotice::message( $code, 'managed' ) . ' You can edit the mapping manually.' );
		}
		if ( in_array( $code, array( 'managed_failed', 'managed_request_rejected' ), true ) ) {
			return self::notice( $code, 'wait_for_receipt', FailureNotice::message( $code, 'managed' ) . ' You can edit the mapping manually.' );
		}
		if ( in_array( $code, array( 'managed_unavailable', 'invalid_token', 'insufficient_balance', 'unsupported_operation', 'unsupported_capability', 'unsupported_image', 'unsupported_provider_capability', 'provider_capability_unknown' ), true ) ) {
			return self::notice( $code, 'insufficient_balance' === $code ? 'review_usage' : 'check_connection', ( FailureNotice::message( $code, $source ?? '', 'text' ) ?? $notices['provider_unavailable'][1] ) . ' You can edit the mapping manually.' );
		}
		if ( ! is_string( $code ) || ! isset( $notices[ $code ] ) ) {
			$code = 'mapping_failed';
		}
		return self::notice( $code, $notices[ $code ][0], $notices[ $code ][1] );
	}

	private static function native_code( $native ): string {
		$native = $native instanceof \stdClass ? get_object_vars( $native ) : $native;
		if ( ! is_array( $native ) ) {
			return 'provider_unavailable';
		}
		$code   = $native['code'] ?? null;
		$status = $native['http_status'] ?? null;
		if ( in_array( $code, array( 'network_error', 'request_timeout', 'http_request_failed' ), true ) || ( 'prompt_network_error' === $code && 503 === $status ) ) {
			return 'provider_network_error';
		}
		if ( 'prompt_client_error' === $code && is_int( $status ) ) {
			if ( in_array( $status, array( 401, 403 ), true ) ) {
				return 'provider_authentication_failed';
			}
			if ( 429 === $status ) {
				return 'provider_rate_limited';
			}
			if ( $status >= 400 && $status <= 499 ) {
				return 'provider_request_rejected';
			}
		}
		return 'provider_unavailable';
	}

	private static function notice( string $code, string $recovery, string $message ): array {
		return array(
			'failure_code' => $code,
			'recovery'     => $recovery,
			'error'        => $message,
		);
	}
}
