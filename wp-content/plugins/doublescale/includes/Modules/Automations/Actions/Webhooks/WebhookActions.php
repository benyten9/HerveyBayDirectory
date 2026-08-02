<?php

/**
 * Webhook Actions Trait
 *
 * Contains common functionality for webhook-based actions
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Actions\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * WebhookActions trait
 */
trait WebhookActions {


	/**
	 * Safely decode JSON data fields, handling WordPress escaping
	 *
	 * @param mixed $data_fields The data fields to decode
	 * @return array The decoded data fields
	 */
	protected function decode_data_fields( $data_fields ) {
		// If already an array, return as-is
		if ( \is_array( $data_fields ) ) {
			return $data_fields;
		}

		// If not a string, return empty array
		if ( ! \is_string( $data_fields ) ) {
			return array();
		}

		// Try to decode as-is first
		$decoded_data = \json_decode( $data_fields, true );

		// If first attempt failed, try with stripslashes (in case WordPress added slashes)
		if ( \json_last_error() !== JSON_ERROR_NONE ) {
			$stripped_data = \stripslashes( $data_fields );
			$decoded_data  = \json_decode( $stripped_data, true );
		}

		// If still failed, try double stripslashes (in case of double escaping)
		if ( \json_last_error() !== JSON_ERROR_NONE ) {
			$double_stripped = \stripslashes( \stripslashes( $data_fields ) );
			$decoded_data    = \json_decode( $double_stripped, true );
		}

		// Return decoded data if successful, otherwise empty array
		$result = ( \json_last_error() === JSON_ERROR_NONE && $decoded_data !== null ) ? $decoded_data : array();
		return $result;
	}

	/**
	 * Validate webhook URL
	 *
	 * @param string $webhook_url The webhook URL to validate
	 * @param object $automation Automation Model
	 * @param object $step Automation Step Model
	 * @param string $action_name The name of the action (for logging)
	 * @return bool True if valid, false otherwise
	 */
	protected function validate_webhook_url( $webhook_url, $automation, $step, $action_name ) {
		if ( empty( $webhook_url ) ) {
			doublescale_get_logger()->error(
				/* translators: %s: webhook action name */
				\sprintf( \__( '%s action is missing webhook URL.', 'doublescale' ), $action_name ),
				array(
					'code' => \strtolower( \str_replace( ' ', '_', $action_name ) ) . '_missing_url',
					'data' => array(
						'automation' => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'       => array(
							'id' => $step->id,
						),
					),
				)
			);
			return false;
		}

		if ( ! \filter_var( $webhook_url, \FILTER_VALIDATE_URL ) ) {
			doublescale_get_logger()->error(
				/* translators: %s: webhook action name */
				\sprintf( \__( '%s action has invalid webhook URL.', 'doublescale' ), $action_name ),
				array(
					'code' => \strtolower( \str_replace( ' ', '_', $action_name ) ) . '_invalid_url',
					'data' => array(
						'automation'  => array(
							'id'   => $automation->id,
							'name' => $automation->name,
						),
						'step'        => array(
							'id' => $step->id,
						),
						'webhook_url' => $webhook_url,
					),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Process data fields with merge tags
	 *
	 * @param array  $data_fields Array of key-value pairs
	 * @param object $automation_contact Contact Model
	 * @return array Processed data
	 */
	protected function process_data_fields( $data_fields, $automation_contact ) {
		$processed_data = array();

		if ( ! empty( $data_fields ) && is_array( $data_fields ) ) {
			if ( isset( $data_fields[0] ) && is_array( $data_fields[0] ) && isset( $data_fields[0]['key'] ) ) {
				foreach ( $data_fields as $pair ) {
					$key = isset( $pair['key'] ) ? (string) $pair['key'] : '';
					if ( '' === $key ) {
						continue;
					}
					// Do not use empty( $value ): PHP treats "0" as empty and would drop valid payloads / merge output.
					$value_raw              = array_key_exists( 'value', $pair ) ? (string) $pair['value'] : '';
					$processed_value        = $this->merge_tags_manager->process_merge_tags( $value_raw, $automation_contact );
					$processed_data[ $key ] = $processed_value;
				}
			}
		}

		return $processed_data;
	}

	/**
	 * Process header fields with merge tags
	 *
	 * @param array  $headers_fields Array of key-value pairs
	 * @param object $automation_contact Contact Model
	 * @return array Processed headers
	 */
	protected function process_header_fields( $headers_fields, $automation_contact ) {
		$custom_headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'DoubleScale/' . DOUBLESCALE_VERSION,
		);

		if ( ! empty( $headers_fields ) && is_array( $headers_fields ) ) {
			if ( isset( $headers_fields[0] ) && is_array( $headers_fields[0] ) && isset( $headers_fields[0]['key'] ) ) {
				foreach ( $headers_fields as $pair ) {
					$key = isset( $pair['key'] ) ? (string) $pair['key'] : '';
					if ( '' === $key ) {
						continue;
					}
					$value_raw              = array_key_exists( 'value', $pair ) ? (string) $pair['value'] : '';
					$processed_value        = $this->merge_tags_manager->process_merge_tags( $value_raw, $automation_contact );
					$custom_headers[ $key ] = $processed_value;
				}
			}
		}

		return $custom_headers;
	}

	/**
	 * Log webhook error
	 *
	 * @param string $message Error message
	 * @param string $code Error code
	 * @param object $automation Automation Model
	 * @param object $step Automation Step Model
	 * @param string $webhook_url Webhook URL
	 * @param array  $additional_data Additional data to log
	 */
	protected function log_webhook_error( $message, $code, $automation, $step, $webhook_url, $additional_data = array() ) {
		$log_data = array(
			'code' => $code,
			'data' => array_merge(
				array(
					'automation'  => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'step'        => array(
						'id' => $step->id,
					),
					'webhook_url' => $webhook_url,
				),
				$additional_data
			),
		);

		doublescale_get_logger()->error( $message, $log_data );
	}

	/**
	 * Log webhook success
	 *
	 * @param string $message Success message
	 * @param string $code Success code
	 * @param object $automation Automation Model
	 * @param object $step Automation Step Model
	 * @param string $webhook_url Webhook URL
	 * @param array  $additional_data Additional data to log
	 */
	protected function log_webhook_success( $message, $code, $automation, $step, $webhook_url, $additional_data = array() ) {
		$log_data = array(
			'code' => $code,
			'data' => array_merge(
				array(
					'automation'  => array(
						'id'   => $automation->id,
						'name' => $automation->name,
					),
					'step'        => array(
						'id' => $step->id,
					),
					'webhook_url' => $webhook_url,
				),
				$additional_data
			),
		);

		doublescale_get_logger()->info( $message, $log_data );
	}

	/**
	 * Prepare test data from fields (removes merge tags)
	 *
	 * @param array $data_fields Array of key-value pairs
	 * @return array Test data
	 */
	protected function prepare_test_data( $data_fields ) {
		$test_data = array();

		if ( ! empty( $data_fields ) && \is_array( $data_fields ) ) {
			foreach ( $data_fields as $pair ) {
				if ( ! empty( $pair['key'] ) && ! empty( $pair['value'] ) ) {
					// Remove merge tags for testing
					$pair['value'] = preg_replace( '/\{\{.*?\}\}/', '', $pair['value'] );
					// Normalize whitespace
					$pair['value'] = trim( preg_replace( '/\s+/', ' ', $pair['value'] ) );

					$test_data[ \sanitize_text_field( $pair['key'] ) ] = \sanitize_text_field( $pair['value'] );
				}
			}
		}

		return $test_data;
	}

	/**
	 * Validate webhook test response
	 *
	 * @param mixed  $response The response from wp_remote_request
	 * @param string $action_name The name of the action (for error messages)
	 * @return array Result array with success and data
	 */
	protected function validate_webhook_response( $response, $action_name ) {
		if ( \is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'data'    => array(
					'message' => \sprintf(
						/* translators: %s: HTTP error message returned by wp_remote_request */
						\__( 'Failed to send test data: %s', 'doublescale' ),
						$response->get_error_message()
					),
				),
			);
		}

		$response_code = \wp_remote_retrieve_response_code( $response );
		$response_body = \wp_remote_retrieve_body( $response );

		if ( $response_code < 200 || $response_code >= 300 ) {
			return array(
				'success' => false,
				'data'    => array(
					'message' => \sprintf(
						/* translators: 1: HTTP response code, 2: response body */
						\__( 'Webhook returned error status %1$d: %2$s', 'doublescale' ),
						$response_code,
						$response_body
					),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'message' => \__( 'Test data sent successfully!', 'doublescale' ),
			),
		);
	}
}
